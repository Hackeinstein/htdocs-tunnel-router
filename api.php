<?php
declare(strict_types=1);

// Prevent PHP notices/warnings from corrupting the JSON response body.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

define('APP_ROOT',   __DIR__);
define('STATE_DIR',  APP_ROOT . '/state');
define('BACKUP_DIR', STATE_DIR . '/backups');

require_once APP_ROOT . '/src/helpers.php';

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

header('Content-Type: application/json');
ob_clean(); // discard any stray output before we write JSON
Config::init(STATE_DIR);

// ---- Route dispatcher -------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
// Strip base path if tool is not at web root
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$path   = '/' . ltrim(substr($uri, strlen($base)), '/');

// Support ?route= override for servers without mod_rewrite
if (isset($_GET['route'])) $path = '/' . ltrim($_GET['route'], '/');

try {
    route($method, $path);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ---- Router -----------------------------------------------------------------

function route(string $method, string $path): void
{
    $cfg     = Config::load();
    $state   = new State(STATE_DIR);
    $log     = new ActivityLog(STATE_DIR);

    // Cloudflare client (only if configured)
    $cf = Config::isConfigured()
        ? new CloudflareClient(
            $cfg['cloudflare']['api_token'],
            $cfg['cloudflare']['account_id'],
            $cfg['cloudflare']['tunnel_id'],
            STATE_DIR
          )
        : null;

    $vm = new VhostManager($cfg['paths']['vhosts_file'], BACKUP_DIR);
    $ap = new ApacheManager($cfg['paths']['apache_bin']);

    // GET /api/projects
    if ($method === 'GET' && $path === '/api/projects') {
        $scanner = new ProjectScanner($cfg['paths']['htdocs']);
        $projects = $scanner->scan();
        $routes   = $state->loadRoutes();
        $routeMap = [];
        foreach ($routes as $r) $routeMap[$r['project']] = $r;

        $out = [];
        foreach ($projects as $p) {
            $r = $routeMap[$p['name']] ?? null;
            $status = 'none';
            if ($r) {
                $status = $r['enabled'] ? 'active' : 'disabled';
            }
            $out[] = [
                'name'        => $p['name'],
                'path'        => $p['path'],
                'route_status'=> $status,
                'hostname'    => $r['hostname'] ?? null,
            ];
        }
        echo json_encode(['ok' => true, 'projects' => $out]);
        return;
    }

    // GET /api/zones
    if ($method === 'GET' && $path === '/api/zones') {
        requireCf($cf);
        $zones = $cf->listZones();
        echo json_encode(['ok' => true, 'zones' => $zones]);
        return;
    }

    // GET /api/routes
    if ($method === 'GET' && $path === '/api/routes') {
        echo json_encode(['ok' => true, 'routes' => $state->loadRoutes()]);
        return;
    }

    // GET /api/preview
    if ($method === 'GET' && $path === '/api/preview') {
        requireCf($cf);
        $project   = $_GET['project']   ?? '';
        $zoneId    = $_GET['zone_id']   ?? '';
        $subdomain = $_GET['subdomain'] ?? '';
        $wwwAlias  = !empty($_GET['www_alias']);
        $cfg2      = Config::load();

        [$hostname, $zoneName] = resolveHostname($cf, $zoneId, $subdomain);

        $docRoot = rtrim($cfg2['paths']['htdocs'], '/\\') . '/' . $project;
        $vhostBlock = $vm->buildBlockText($hostname, $docRoot, $wwwAlias);
        $dnsPayload  = [
            'type'    => 'CNAME',
            'name'    => $hostname,
            'content' => $cfg2['cloudflare']['tunnel_id'] . '.cfargotunnel.com',
            'proxied' => true,
        ];
        $apexConflict = null;
        if ($subdomain === '') {
            $apexConflict = $cf->checkApexConflict($zoneId, $zoneName);
        }
        echo json_encode([
            'ok'           => true,
            'hostname'     => $hostname,
            'vhost_block'  => $vhostBlock,
            'dns_payload'  => $dnsPayload,
            'apex_conflict'=> $apexConflict,
        ]);
        return;
    }

    // POST /api/routes
    if ($method === 'POST' && $path === '/api/routes') {
        requireCf($cf);
        $body      = jsonBody();
        $project   = trim($body['project']   ?? '');
        $zoneId    = trim($body['zone_id']   ?? '');
        $subdomain = trim($body['subdomain'] ?? '');
        $wwwAlias  = (bool)($body['www_alias'] ?? false);
        $label     = trim($body['label']     ?? $project);
        $forceApex = (bool)($body['force_apex'] ?? false);

        if (!sanitizeProjectName($project)) apiError('Invalid project name');
        if (!$zoneId) apiError('zone_id required');

        $cfg2    = Config::load();
        $docRoot = rtrim($cfg2['paths']['htdocs'], '/\\') . '/' . $project;

        // Validate project folder actually exists
        if (!is_dir($docRoot)) apiError("Project folder not found: $docRoot");

        [$hostname, $zoneName] = resolveHostname($cf, $zoneId, $subdomain);
        if (!sanitizeHostname($hostname)) apiError("Invalid hostname: $hostname");

        // Apex conflict check
        if ($subdomain === '') {
            $conflict = $cf->checkApexConflict($zoneId, $zoneName);
            if ($conflict && !$forceApex) {
                echo json_encode([
                    'ok'           => false,
                    'apex_conflict'=> $conflict,
                    'error'        => "Apex record exists ({$conflict['type']} → {$conflict['content']}). Send force_apex=true to replace.",
                ]);
                return;
            }
        }

        // Idempotent DNS: update if same tunnel, create if not present
        $existing = $cf->getDnsRecord($zoneId, $hostname);
        $dnsRecord = null;
        if ($existing) {
            $dnsRecord = $cf->updateCname($zoneId, $existing['id'], $hostname);
        } else {
            $dnsRecord = $cf->createCname($zoneId, $hostname);
        }

        // Write vhost block
        $vhostWritten = false;
        if (!$vm->hasBlock($hostname)) {
            $vm->addBlock($hostname, $docRoot, $wwwAlias);
            $vhostWritten = true;
        }

        // Validate + reload Apache
        $apResult = $ap->validateAndReload($vm);
        if (!$apResult['ok']) {
            // Rollback: delete DNS record just created (not if it already existed)
            if (!$existing && $dnsRecord) {
                try { $cf->deleteDnsRecord($zoneId, $dnsRecord['id']); } catch (Throwable) {}
            }
            $log->append('attach', $hostname, false, 'Apache validate failed: ' . ($apResult['output'] ?? ''));
            http_response_code(500);
            echo json_encode([
                'ok'     => false,
                'step'   => 'apache_validate',
                'error'  => $apResult['error'] ?? 'Apache config validation failed — changes rolled back.',
                'output' => $apResult['output'] ?? '',
            ]);
            exit;
        }

        // Persist to routes.json
        $state->upsertRoute([
            'hostname'     => $hostname,
            'type'         => $subdomain === '' ? 'apex' : 'subdomain',
            'zone_id'      => $zoneId,
            'project'      => $project,
            'document_root'=> $docRoot,
            'www_alias'    => $wwwAlias,
            'dns_record_id'=> $dnsRecord['id'],
            'enabled'      => true,
            'label'        => $label,
        ]);

        $log->append('attach', $hostname, true, "DNS {$dnsRecord['id']} created/updated; vhost written=" . ($vhostWritten?'yes':'skipped') . '; Apache reloaded');
        echo json_encode(['ok' => true, 'hostname' => $hostname, 'dns_record_id' => $dnsRecord['id']]);
        return;
    }

    // DELETE /api/routes/{hostname}
    if ($method === 'DELETE' && preg_match('#^/api/routes/(.+)$#', $path, $m)) {
        requireCf($cf);
        if (($_GET['confirm'] ?? '') !== '1') apiError('confirm=1 required', 400);

        $hostname = urldecode($m[1]);
        $route    = $state->findRoute($hostname);
        if (!$route) apiError("Route not found: $hostname", 404);

        // Remove vhost block first, validate
        $vm->removeBlock($hostname);
        $apResult = $ap->validateAndReload($vm);
        if (!$apResult['ok']) {
            $log->append('detach', $hostname, false, 'Apache validate failed after vhost removal — backup restored');
            apiError('Apache invalid after vhost removal — backup restored. ' . ($apResult['error'] ?? ''), 500);
        }

        // Delete DNS record
        $zoneId   = $route['zone_id'];
        $recordId = $route['dns_record_id'];
        $dnsOk    = false;
        if ($zoneId && $recordId) {
            try { $dnsOk = $cf->deleteDnsRecord($zoneId, $recordId); } catch (Throwable $e) {
                $log->append('detach', $hostname, false, 'DNS delete failed: ' . $e->getMessage());
            }
        }

        $state->removeRoute($hostname);
        $log->append('detach', $hostname, true, 'vhost removed; DNS deleted=' . ($dnsOk?'yes':'failed'));
        echo json_encode(['ok' => true]);
        return;
    }

    // POST /api/routes/{hostname}/toggle
    if ($method === 'POST' && preg_match('#^/api/routes/(.+)/toggle$#', $path, $m)) {
        $hostname = urldecode($m[1]);
        $route    = $state->findRoute($hostname);
        if (!$route) apiError("Route not found: $hostname", 404);

        $enable = !($route['enabled'] ?? true);
        if ($enable) {
            $vm->enableBlock($hostname);
        } else {
            $vm->disableBlock($hostname);
        }

        $apResult = $ap->validateAndReload($vm);
        if (!$apResult['ok']) {
            // Undo toggle
            $enable ? $vm->disableBlock($hostname) : $vm->enableBlock($hostname);
            apiError('Apache invalid after toggle — reverted. ' . ($apResult['error'] ?? ''), 500);
        }

        $state->upsertRoute(array_merge($route, ['enabled' => $enable]));
        $log->append('toggle', $hostname, true, $enable ? 'enabled' : 'disabled');
        echo json_encode(['ok' => true, 'enabled' => $enable]);
        return;
    }

    // GET /api/reconcile
    if ($method === 'GET' && $path === '/api/reconcile') {
        requireCf($cf);
        $reconciler = new Reconciler($cf, $vm, $state);
        $results    = $reconciler->reconcile();
        echo json_encode(['ok' => true, 'routes' => $results]);
        return;
    }

    // POST /api/reconcile/fix
    if ($method === 'POST' && $path === '/api/reconcile/fix') {
        requireCf($cf);
        $body     = jsonBody();
        $hostname = $body['hostname'] ?? '';
        $action   = $body['action']   ?? '';

        if (!$hostname || !$action) apiError('hostname and action required');

        $route = $state->findRoute($hostname);
        $cfg2  = Config::load();

        switch ($action) {
            case 'create_dns':
                if (!$route) apiError("No routes.json entry for $hostname — import first");
                $rec = $cf->createCname($route['zone_id'], $hostname);
                $state->upsertRoute(array_merge($route, ['dns_record_id' => $rec['id']]));
                $log->append('reconcile_fix', $hostname, true, 'DNS record created');
                break;

            case 'create_vhost':
                if (!$route) apiError("No routes.json entry for $hostname — import first");
                $vm->addBlock($hostname, $route['document_root'], $route['www_alias'] ?? false);
                $apResult = $ap->validateAndReload($vm);
                if (!$apResult['ok']) { apiError('Apache invalid after vhost creation — backup restored'); }
                $log->append('reconcile_fix', $hostname, true, 'vhost block created');
                break;

            case 'delete_dns':
                if ($route && $route['zone_id'] && ($route['dns_record_id'] ?? '')) {
                    $cf->deleteDnsRecord($route['zone_id'], $route['dns_record_id']);
                } else {
                    // Orphan DNS: search across known zones and delete
                    $found = $cf->findAndDeleteDnsByName($hostname);
                    if (!$found) apiError("Cannot locate DNS record for $hostname in any zone");
                }
                $log->append('reconcile_fix', $hostname, true, 'DNS record deleted');
                break;

            case 'delete_vhost':
                $vm->removeBlock($hostname);
                $apResult = $ap->validateAndReload($vm);
                if (!$apResult['ok']) { apiError('Apache invalid — backup restored'); }
                $log->append('reconcile_fix', $hostname, true, 'vhost block removed');
                break;

            case 'delete_both':
                // Delete DNS then vhost
                if ($route && $route['zone_id'] && ($route['dns_record_id'] ?? '')) {
                    try { $cf->deleteDnsRecord($route['zone_id'], $route['dns_record_id']); } catch (Throwable) {}
                }
                if ($vm->hasBlock($hostname)) {
                    $vm->removeBlock($hostname);
                    $apResult = $ap->validateAndReload($vm);
                    if (!$apResult['ok']) { apiError('Apache invalid after vhost removal — backup restored'); }
                }
                $state->removeRoute($hostname);
                $log->append('reconcile_fix', $hostname, true, 'both DNS and vhost cleaned up');
                break;

            case 'delete_route_entry':
                $state->removeRoute($hostname);
                $log->append('reconcile_fix', $hostname, true, 'stale route entry removed');
                break;

            default:
                apiError("Unknown action: $action");
        }

        echo json_encode(['ok' => true]);
        return;
    }

    // GET /api/activity
    if ($method === 'GET' && $path === '/api/activity') {
        $limit  = max(1, min((int)($_GET['limit'] ?? 100), 1000));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        echo json_encode(['ok' => true, 'entries' => $log->read($limit, $offset)]);
        return;
    }

    // POST /api/config
    if ($method === 'POST' && $path === '/api/config') {
        $body = jsonBody();
        // Never overwrite token with empty string (write-only UX)
        $existing = Config::load();
        if (empty($body['cloudflare']['api_token'])) {
            $body['cloudflare']['api_token'] = $existing['cloudflare']['api_token'];
        }
        Config::save($body);
        // Clear CF cache on token change
        if (isset($body['cloudflare']['api_token'])) {
            $newCf = new CloudflareClient(
                $body['cloudflare']['api_token'],
                $body['cloudflare']['account_id'] ?? '',
                $body['cloudflare']['tunnel_id'] ?? '',
                STATE_DIR
            );
            $newCf->clearCache();
        }
        echo json_encode(['ok' => true]);
        return;
    }

    // GET /api/debug  — system diagnostics (no sensitive values)
    if ($method === 'GET' && $path === '/api/debug') {
        $apacheBin  = $cfg['paths']['apache_bin'] ?? '';
        $apacheRoot = $apacheBin ? dirname(dirname(normalizePath($apacheBin))) : '';
        $confFile   = $apacheRoot ? $apacheRoot . '/conf/httpd.conf' : '';
        $vhosts     = $cfg['paths']['vhosts_file'] ?? '';
        $tunnelId   = $cfg['cloudflare']['tunnel_id'] ?? '';

        $execAvail = false;
        if (function_exists('exec')) {
            exec('echo ok', $o); $execAvail = ($o[0] ?? '') === 'ok';
        }

        $cfZones = null;
        $cfError = null;
        if ($cf) {
            try { $cfZones = count($cf->listZones()); } catch (Throwable $e) { $cfError = $e->getMessage(); }
        }

        echo json_encode([
            'ok'                    => true,
            'php_version'           => PHP_VERSION,
            'curl_loaded'           => extension_loaded('curl'),
            'exec_available'        => $execAvail,
            'apache_bin'            => $apacheBin,
            'apache_bin_exists'     => $apacheBin ? file_exists($apacheBin) : false,
            'httpd_conf_path'       => $confFile,
            'httpd_conf_exists'     => $confFile ? file_exists($confFile) : false,
            'vhosts_file'           => $vhosts,
            'vhosts_exists'         => $vhosts ? file_exists($vhosts) : false,
            'state_dir_writable'    => is_writable(STATE_DIR),
            'tunnel_id_set'         => $tunnelId !== '',
            'tunnel_id_len'         => strlen($tunnelId),
            'cf_configured'         => $cf !== null,
            'cf_zones_reachable'    => $cfZones !== null,
            'cf_zones_count'        => $cfZones,
            'cf_error'              => $cfError,
        ]);
        return;
    }

    // 404
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "No API route: $method $path"]);
}

// ---- Helpers ----------------------------------------------------------------

function requireCf(?CloudflareClient $cf): void
{
    if (!$cf) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Cloudflare not configured — visit Settings first']);
        exit;
    }
}

function apiError(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Resolve zone_id + subdomain → [hostname, zoneName].
 * If subdomain is empty, hostname = zone name (apex).
 */
function resolveHostname(CloudflareClient $cf, string $zoneId, string $subdomain): array
{
    $zones    = $cf->listZones();
    $zoneName = '';
    foreach ($zones as $z) {
        if ($z['id'] === $zoneId) { $zoneName = $z['name']; break; }
    }
    if (!$zoneName) throw new RuntimeException("Zone not found: $zoneId");

    $hostname = $subdomain !== ''
        ? rtrim($subdomain, '.') . '.' . $zoneName
        : $zoneName;

    return [$hostname, $zoneName];
}
