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
header('Cache-Control: no-store');   // API answers are live data; the browser must never reuse them
ob_clean(); // discard any stray output before we write JSON
Config::init(STATE_DIR);
Guard::enforce((string)(Config::load()['security']['allowed_hosts'] ?? ''), true);   // private to this computer: see Guard
if ($err = Config::ensureState()) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $err]); exit; }

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
} catch (RouteException $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()] + $e->data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ---- Router -----------------------------------------------------------------

function route(string $method, string $path): void
{
    if ($method !== 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'tunnel-router') {
        apiError('Missing X-Requested-With header (blocked to prevent cross-site requests)', 403);
    }
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
    $svc = new RouteService($state, $log, $vm, $ap, $cf);

    // GET /api/tick — called every minute (and on load) by any open page: expiry, Under Attack revert, health monitor
    if ($method === 'GET' && $path === '/api/tick') {
        $alerts = new Alerts(STATE_DIR);
        $out = ['ok' => true, 'expired' => [], 'reverted' => [], 'new_alerts' => []];
        if ($cf) {
            $in = new Insights($cf, $cfg['cloudflare']['account_id'], STATE_DIR);
            $out['expired'] = $svc->sweepIfDue(STATE_DIR);
            if (is_file(STATE_DIR . '/attack.json')) {
                try { $out['reverted'] = (new AttackMode($in, STATE_DIR, $log))->sweep(); } catch (Throwable) {}
            }
            try { $out['new_alerts'] = (new Monitor($state, $in, $alerts, STATE_DIR, $cfg['cloudflare']['tunnel_id']))->runIfDue(); } catch (Throwable) {}
        }
        $out['unread'] = $alerts->unread();
        $out['reload'] = reloadStatus();
        echo json_encode($out);
        return;
    }

    // GET /api/reload/status  ·  POST /api/reload/test — restart Apache now and report whether it was confirmed
    if ($method === 'GET' && $path === '/api/reload/status') { echo json_encode(['ok' => true, 'reload' => reloadStatus()]); return; }
    if ($method === 'POST' && $path === '/api/reload/test') {
        $r = $ap->reload();
        if (!($r['ok'] ?? false)) apiError($r['output'] ?? 'Reload could not be started', 400);
        echo json_encode(['ok' => true, 'reload' => reloadStatus()]);
        return;
    }

    // GET /api/health — per-route uptime/latency + alert inbox;  POST /api/health/check — probe now
    if (in_array($path, ['/api/health', '/api/health/check'], true)) {
        requireCf($cf);
        $alerts = new Alerts(STATE_DIR);
        $mon = new Monitor($state, new Insights($cf, $cfg['cloudflare']['account_id'], STATE_DIR), $alerts, STATE_DIR, $cfg['cloudflare']['tunnel_id']);
        if ($method === 'POST') $mon->run(time());
        echo json_encode(['ok' => true, 'routes' => $mon->summary(), 'alerts' => array_slice($alerts->all(), 0, 100), 'unread' => $alerts->unread()]);
        return;
    }

    // POST /api/alerts/read
    if ($method === 'POST' && $path === '/api/alerts/read') {
        $a = new Alerts(STATE_DIR); $a->markAllRead();
        echo json_encode(['ok' => true]);
        return;
    }

    // GET /api/projects
    if ($method === 'GET' && $path === '/api/projects') {
        if ($cf) { try { $svc->sweepIfDue(STATE_DIR); } catch (Throwable) {} }
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
                'expires_at'  => $r['expires_at'] ?? null,
                'expired_at'  => $r['expired_at'] ?? null,
            ];
        }
        echo json_encode(['ok' => true, 'projects' => $out]);
        return;
    }

    // GET /api/project?name=… — everything for one project's page (works whether or not it is routed)
    if ($method === 'GET' && $path === '/api/project') {
        $name = (string)($_GET['name'] ?? '');
        $htdocs = rtrim($cfg['paths']['htdocs'], '/\\');
        if (!sanitizeProjectName($name) || !is_dir("$htdocs/$name")) apiError('Project folder not found: ' . $name, 404);
        $routes = array_values(array_filter($state->loadRoutes(), fn($r) => ($r['project'] ?? '') === $name));
        echo json_encode(['ok' => true, 'project' => (new ProjectScanner($htdocs))->inspect($name), 'routes' => $routes]);
        return;
    }

    // GET /api/zones
    if ($method === 'GET' && $path === '/api/zones') {
        requireCf($cf);
        $count = [];                                   // routes tracked per zone, for "example.com (2)" in dropdowns
        foreach ($state->loadRoutes() as $r) $count[$r['zone_id'] ?? ''] = ($count[$r['zone_id'] ?? ''] ?? 0) + 1;
        $zones = array_map(fn($z) => $z + ['routes' => $count[$z['id']] ?? 0], $cf->listZones());
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
        if (!sanitizeProjectName($project)) apiError('Invalid project name');

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
        $b = jsonBody();
        $exp = isset($b['expires_in']) ? RouteService::resolveExpiry(['in' => (int)$b['expires_in']])
             : (isset($b['expires_at']) ? RouteService::resolveExpiry(['at' => (int)$b['expires_at']]) : null);
        $r = $svc->attach(trim($b['project'] ?? ''), trim($b['zone_id'] ?? ''), trim($b['subdomain'] ?? ''),
                          (bool)($b['www_alias'] ?? false), trim($b['label'] ?? ''), (bool)($b['force_apex'] ?? false), $exp, !empty($b['portal_enabled']));
        echo json_encode(['ok' => true] + $r + ['expires_at' => $exp]);
        return;
    }

    // POST /api/share  {project, zone_id, in}  — temporary route on a random subdomain
    if ($method === 'POST' && $path === '/api/share') {
        requireCf($cf);
        $b   = jsonBody();
        $exp = RouteService::resolveExpiry(['in' => (int)($b['in'] ?? 7200)]);
        $sub = 'share-' . bin2hex(random_bytes(3));
        $r   = $svc->attach(trim($b['project'] ?? ''), trim($b['zone_id'] ?? ''), $sub, false, 'Share link', false, $exp);
        echo json_encode(['ok' => true, 'url' => 'https://' . $r['hostname'], 'expires_at' => $exp] + $r);
        return;
    }

    // ---- Backup: /api/backup/{export|plan|apply|snapshots|snapshot} ----
    if (str_starts_with($path, '/api/backup/')) {
        $bundle = new Bundle($state, $vm, $ap, $log, $cf, BACKUP_DIR);
        $sub = substr($path, strlen('/api/backup/'));
        if ($method === 'GET' && $sub === 'export') {
            $data = $bundle->export(($_GET['token'] ?? '') === '1');
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="tunnel-router-backup-' . date('Ymd-His') . '.json"');
            echo jsonPretty($data);
            return;
        }
        if ($method === 'GET' && $sub === 'snapshots') { echo json_encode(['ok' => true, 'snapshots' => $bundle->snapshots()]); return; }
        if ($method === 'POST' && $sub === 'snapshot')  { echo json_encode(['ok' => true, 'name' => $bundle->snapshot('manual')]); return; }
        if ($method === 'POST' && in_array($sub, ['plan', 'apply'], true)) {
            $b = jsonBody();
            $data = isset($b['snapshot']) ? $bundle->loadSnapshot((string)$b['snapshot']) : (array)($b['bundle'] ?? []);
            $useConfig = !empty($b['config']);
            if ($sub === 'plan') { echo json_encode(['ok' => true, 'plan' => $bundle->plan($data, $useConfig)]); return; }
            requireCf($cf);
            echo json_encode(['ok' => true] + $bundle->apply($data, $useConfig, !isset($b['routes']) || !empty($b['routes'])));
            return;
        }
    }

    // POST /api/bulk  {action, ...}  → per-item results
    if ($method === 'POST' && $path === '/api/bulk') {
        requireCf($cf);
        $b = jsonBody();
        try { (new Bundle($state, $vm, $ap, $log, null, BACKUP_DIR))->snapshot('before bulk ' . ($b['action'] ?? '')); } catch (Throwable) {}
        echo json_encode(['ok' => true, 'results' => $svc->bulk((string)($b['action'] ?? ''), $b)]);
        return;
    }

    // DELETE /api/routes/{hostname}
    if ($method === 'DELETE' && preg_match('#^/api/routes/(.+)$#', $path, $m)) {
        requireCf($cf);
        if (($_GET['confirm'] ?? '') !== '1') apiError('confirm=1 required', 400);
        $svc->detach(urldecode($m[1]));
        echo json_encode(['ok' => true]);
        return;
    }

    // POST /api/routes/{hostname}/toggle
    if ($method === 'POST' && preg_match('#^/api/routes/(.+)/toggle$#', $path, $m)) {
        $hostname = urldecode($m[1]);
        $route    = $state->findRoute($hostname);
        if (!$route) apiError("Route not found: $hostname", 404);
        $enable = !($route['enabled'] ?? true);
        $svc->setEnabled($hostname, $enable);
        echo json_encode(['ok' => true, 'enabled' => $enable]);
        return;
    }

    // POST /api/routes/{hostname}/expiry  {never | in | extend | at}
    if ($method === 'POST' && preg_match('#^/api/routes/(.+)/expiry$#', $path, $m)) {
        $hostname = urldecode($m[1]);
        $route    = $state->findRoute($hostname);
        if (!$route) apiError("Route not found: $hostname", 404);
        $at = RouteService::resolveExpiry(jsonBody(), $route['expires_at'] ?? null);
        if ($at === false) apiError('Send never, in, extend or at');
        $svc->setExpiry($hostname, $at);
        echo json_encode(['ok' => true, 'expires_at' => $at]);
        return;
    }

    // ---- Rebuild routes.json: /api/import/scan · /api/import/apply · /api/import/routes-file (read-only for Apache and Cloudflare) ----
    if (str_starts_with($path, '/api/import/')) {
        $imp = new RouteImporter($state, $vm, $log, $cf, $cfg['cloudflare']['tunnel_id'], rtrim($cfg['paths']['htdocs'], '/\\'));
        $b = $method === 'POST' ? jsonBody() : [];
        if ($method === 'GET' && $path === '/api/import/scan') { requireCf($cf); echo json_encode(['ok' => true] + $imp->scan() + ['tracked' => count($state->loadRoutes())]); return; }
        if ($method === 'POST' && $path === '/api/import/apply') {
            requireCf($cf);
            (new Bundle($state, $vm, $ap, $log, null, BACKUP_DIR))->snapshot('before import');
            echo json_encode(['ok' => true, 'results' => $imp->import((array)($b['hostnames'] ?? []))]); return;
        }
        if ($method === 'POST' && $path === '/api/import/routes-file') {
            $dry = !empty($b['dry_run']);
            if (!$dry) (new Bundle($state, $vm, $ap, $log, null, BACKUP_DIR))->snapshot('before routes.json import');
            echo json_encode(['ok' => true, 'results' => $imp->fromRoutesFile((array)($b['routes'] ?? []), $dry)]); return;
        }
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
        if (!sanitizeHostname((string)$hostname)) apiError('Invalid hostname');

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

        RouteService::dnsChanged();
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
        $clean = Config::clean(jsonBody());
        Config::save($clean);
        // Cloudflare caches are tied to the token/account — drop them
        (new CloudflareClient($clean['cloudflare']['api_token'], $clean['cloudflare']['account_id'], $clean['cloudflare']['tunnel_id'], STATE_DIR))->clearCache();
        @unlink(STATE_DIR . '/metrics_cache.json');
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
            'php_ok'                => PHP_VERSION_ID >= 80100,
            'extensions'            => ['zip' => class_exists('ZipArchive'), 'fileinfo' => class_exists('finfo'), 'mbstring' => function_exists('mb_check_encoding'), 'curl' => function_exists('curl_init')],
            'functions_blocked'     => array_values(array_filter(['exec', 'popen', 'proc_open'], fn($f) => !function_exists($f))),
            'php_exe_for_reload'    => $apacheBin ? is_file(dirname(normalizePath($apacheBin), 3) . '/php/php.exe') || DIRECTORY_SEPARATOR !== '\\' : false,
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

    if (insightsRoute($method, $path, $cf, $cfg, $state, $log)) return;
    if (routePageRoute($method, $path, $cf, $cfg, $state, $log, $vm)) return;
    if (usersRoute($method, $path, $cf, $cfg, $state, $log, $vm, $ap)) return;
    if (filesRoute($method, $path, $cfg, $log, $state)) return;

    // 404
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "No API route: $method $path"]);
}

// ---- Helpers ----------------------------------------------------------------

/** Last Apache reload result. A helper that never reported back for 3 minutes is shown as failed, not pending forever. */
function reloadStatus(): ?array
{
    $st = ReloadWorker::status(STATE_DIR);
    if ($st && in_array($st['state'] ?? '', ['pending', 'running'], true) && time() - (int)($st['requested_at'] ?? 0) > 180) {
        $st['state'] = 'failed';
        $st['detail'] = 'The reload helper never reported back. Restart Apache from the XAMPP Control Panel; if this keeps happening check that php.exe can run from the tool (Settings → Diagnostics).';
    }
    return $st;
}

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
 * Domains + tunnels metrics/settings endpoints. Returns false if the path isn't ours.
 * Each Cloudflare sub-call is wrapped so one missing token permission degrades a panel, not the page.
 */
function insightsRoute(string $method, string $path, ?CloudflareClient $cf, array $cfg, State $state, ActivityLog $log): bool
{
    if (!preg_match('#^/api/(domains|tunnels|dns)#', $path)) return false;
    requireCf($cf);

    $in      = new Insights($cf, $cfg['cloudflare']['account_id'], STATE_DIR);
    $attack  = new AttackMode($in, STATE_DIR, $log);
    $routes  = $state->loadRoutes();
    $active  = $cfg['cloudflare']['tunnel_id'];
    $try     = function (callable $fn, ?string &$err) {
        try { return $fn(); } catch (Throwable $e) { $err = $e->getMessage(); return null; }
    };

    // GET /api/domains
    if ($method === 'GET' && $path === '/api/domains') {
        $zones = $in->zones();
        $totals = $try(fn() => $in->zoneTotals(array_column($zones, 'id')), $err) ?? [];
        foreach ($zones as &$z) {
            $z['routes'] = count(array_filter($routes, fn($r) => $r['zone_id'] === $z['id']));
            $z['stats']  = $totals[$z['id']] ?? null;
        }
        echo json_encode(['ok' => true, 'zones' => $zones, 'stats_error' => $err ?? null, 'attack' => (object)$attack->active()]);
        return true;
    }

    // GET /api/domains/{zoneId}[/analytics|/health] — split so the page paints the fast part at once and fills in the slow parts
    if ($method === 'GET' && preg_match('#^/api/domains/([a-f0-9]{32})(?:/(analytics|health))?$#', $path, $m)) {
        $zone = null;
        foreach ($in->zones() as $z) if ($z['id'] === $m[1]) $zone = $z;
        if (!$zone) apiError('Zone not found', 404);
        $zr    = array_values(array_filter($routes, fn($r) => $r['zone_id'] === $zone['id']));
        $hosts = array_column($zr, 'hostname');

        if (($m[2] ?? '') === 'analytics') {               // GraphQL: zone breakdown + per-hostname traffic, in parallel
            $a = $in->domainAnalytics($zone['id'], $hosts);
            echo json_encode(['ok' => true, 'analytics' => $a['analytics'], 'hosts' => (object)$a['hosts'], 'errors' => $a['errors']]);
            return true;
        }
        if (($m[2] ?? '') === 'health') {                  // live probes through the tunnel: the slowest part, so it comes last
            echo json_encode(['ok' => true, 'probes' => (object)$in->probe(array_column(array_filter($zr, fn($r) => $r['enabled'] ?? true), 'hostname'))]);
            return true;
        }
        $ov = $in->domainOverview($zone['id']);            // settings + tunnel DNS, one parallel round trip (cached)
        echo json_encode([
            'ok' => true, 'zone' => $zone, 'settings' => $ov['settings'], 'options' => Insights::SETTINGS, 'routes' => $zr, 'dns' => $ov['dns'],
            'tunnel_id' => $active, 'attack' => $attack->active()[$zone['id']] ?? null, 'errors' => $ov['errors'], 'stale' => $ov['stale'] ?? null,
        ]);
        return true;
    }

    // POST /api/domains/{zoneId}/attack   {on: bool, revert_in?: seconds}
    if ($method === 'POST' && preg_match('#^/api/domains/([a-f0-9]{32})/attack$#', $path, $m)) {
        $b = jsonBody();
        if (!empty($b['on'])) $attack->enable($m[1], isset($b['revert_in']) && $b['revert_in'] ? (int)$b['revert_in'] : null);
        else $attack->disable($m[1]);
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST /api/domains/{zoneId}/setting   {name, value}
    if ($method === 'POST' && preg_match('#^/api/domains/([a-f0-9]{32})/setting$#', $path, $m)) {
        $b = jsonBody();
        try { $in->setZoneSetting($m[1], (string)($b['name'] ?? ''), (string)($b['value'] ?? '')); }
        catch (InvalidArgumentException $e) { apiError($e->getMessage()); }
        $log->append('zone_setting', $m[1], true, ($b['name'] ?? '') . '=' . ($b['value'] ?? ''));
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST /api/domains/{zoneId}/purge   {hostname?}
    if ($method === 'POST' && preg_match('#^/api/domains/([a-f0-9]{32})/purge$#', $path, $m)) {
        $host = trim((string)(jsonBody()['hostname'] ?? ''));
        if ($host !== '' && !sanitizeHostname($host)) apiError('Invalid hostname');
        $in->purgeCache($m[1], $host !== '' ? [$host] : []);
        $log->append('purge_cache', $host ?: $m[1], true, $host ? 'host' : 'everything');
        echo json_encode(['ok' => true]);
        return true;
    }

    // ---- DNS browser: /api/dns/{zoneId}[/{recordId}|/export|/import] ----
    if (preg_match('#^/api/dns/([a-f0-9]{32})(?:/([a-f0-9]{32}|export|import))?$#', $path, $m)) {
        $zoneId = $m[1]; $sub = $m[2] ?? '';
        $zoneName = '';
        foreach ($in->zones() as $z) if ($z['id'] === $zoneId) $zoneName = $z['name'];
        if (!$zoneName) apiError('Zone not found', 404);
        $dns = new DnsManager($cf, $state, $log, $in->cache());

        if ($method === 'GET' && $sub === '')       { echo json_encode(['ok' => true, 'zone' => $zoneName, 'records' => $dns->list($zoneId, (string)($_GET['q'] ?? ''), strtoupper((string)($_GET['type'] ?? '')))]); return true; }
        if ($method === 'GET' && $sub === 'export') {
            $fmt = ($_GET['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
            header('Content-Type: ' . ($fmt === 'csv' ? 'text/csv' : 'application/json'));
            header("Content-Disposition: attachment; filename=\"$zoneName-dns.$fmt\"");
            echo $dns->export($zoneId, $fmt);
            return true;
        }
        if ($method === 'POST' && $sub === 'import') { $b = jsonBody(); echo json_encode(['ok' => true, 'results' => $dns->import($zoneId, $zoneName, (array)($b['records'] ?? []), !empty($b['dry_run']))]); return true; }
        if ($method === 'POST' && $sub === '')       { echo json_encode(['ok' => true, 'record' => $dns->create($zoneId, $zoneName, jsonBody())]); return true; }
        if (in_array($method, ['PUT', 'PATCH'], true) && $sub !== '') { $b = jsonBody(); echo json_encode(['ok' => true, 'record' => $dns->update($zoneId, $zoneName, $sub, $b, !empty($b['force']))]); return true; }
        if ($method === 'DELETE' && $sub !== '')     { $dns->delete($zoneId, $sub, ($_GET['force'] ?? '') === '1'); echo json_encode(['ok' => true]); return true; }
    }

    if (!$cfg['cloudflare']['account_id'] && str_starts_with($path, '/api/tunnels')) {
        apiError('Account ID required — set it in Settings', 400);
    }

    // GET /api/tunnels
    if ($method === 'GET' && $path === '/api/tunnels') {
        $out = [];
        foreach ($in->tunnels() as $t) {
            $conns = 0;
            foreach ($t['connections'] ?? [] as $c) $conns++;
            $out[] = [
                'id' => $t['id'], 'name' => $t['name'], 'status' => $t['status'] ?? '',
                'created_at' => $t['created_at'] ?? null, 'connections' => $conns,
                'remote' => ($t['config_src'] ?? '') === 'cloudflare',
                'active' => $t['id'] === $active,
                'routes' => $t['id'] === $active ? count($routes) : 0,
            ];
        }
        echo json_encode(['ok' => true, 'tunnels' => $out, 'active' => $active]);
        return true;
    }

    $uuid = '([a-f0-9-]{36})';

    // GET /api/tunnels/{id}[/live] — overview first (3 Cloudflare calls in parallel), traffic + health after
    if ($method === 'GET' && preg_match("#^/api/tunnels/$uuid(?:/(live))?$#", $path, $m)) {
        $id = $m[1]; $isActive = $id === $active; $mine = $isActive ? $routes : [];
        if (($m[2] ?? '') === 'live') {
            $byZone = []; foreach ($mine as $r) $byZone[$r['zone_id']][] = $r['hostname'];
            $hs = $in->hostStatsMany($byZone);
            $traffic = ['requests' => 0, 'bytes' => 0];
            foreach ($hs['stats'] as $st) foreach ($st as $v) { $traffic['requests'] += $v['requests']; $traffic['bytes'] += $v['bytes']; }
            echo json_encode(['ok' => true, 'traffic' => $isActive ? $traffic : null, 'errors' => $hs['errors'] ? ['traffic' => implode('; ', $hs['errors'])] : [],
                              'probes' => (object)($mine ? $in->probe(array_column(array_filter($mine, fn($r) => $r['enabled'] ?? true), 'hostname')) : [])]);
            return true;
        }
        $ov = $in->tunnelOverview($id);
        echo json_encode(['ok' => true, 'tunnel' => $ov['tunnel'], 'active' => $isActive, 'connectors' => $ov['connectors'], 'ingress' => $ov['ingress'],
                          'routes' => $mine, 'errors' => $ov['errors'], 'stale' => $ov['stale'] ?? null]);
        return true;
    }

    // POST /api/tunnels/{id}/rename   {name}
    if ($method === 'POST' && preg_match("#^/api/tunnels/$uuid/rename$#", $path, $m)) {
        $name = trim((string)(jsonBody()['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) apiError('Name: 1-64 chars, letters, digits, . _ -');
        $in->renameTunnel($m[1], $name);
        if ($m[1] === $active) { $c = Config::load(); $c['cloudflare']['tunnel_name'] = $name; Config::save($c); }
        $log->append('tunnel_rename', $m[1], true, $name);
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST /api/tunnels/{id}/cleanup  — drop stale connections
    if ($method === 'POST' && preg_match("#^/api/tunnels/$uuid/cleanup$#", $path, $m)) {
        $in->cleanupConnections($m[1]);
        $log->append('tunnel_cleanup', $m[1], true, 'stale connections cleared');
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST /api/tunnels/{id}/activate — use this tunnel for NEW routes
    if ($method === 'POST' && preg_match("#^/api/tunnels/$uuid/activate$#", $path, $m)) {
        $t = $in->tunnel($m[1]);
        $c = Config::load();
        $c['cloudflare']['tunnel_id']   = $m[1];
        $c['cloudflare']['tunnel_name'] = $t['name'] ?? '';
        Config::save($c);
        $in->cache()->forget('tunnel');
        $log->append('tunnel_activate', $m[1], true, $t['name'] ?? '');
        echo json_encode(['ok' => true]);
        return true;
    }

    return false;
}

/** File manager + logs endpoints. Paths are always relative to htdocs; Files does all the sandboxing. */
function filesRoute(string $method, string $path, array $cfg, ActivityLog $log, State $state): bool
{
    if (!preg_match('#^/api/(fs|logs)#', $path)) return false;

    if (str_starts_with($path, '/api/logs')) {
        $logs = new Logs($cfg['paths']['apache_bin']);
        if ($path === '/api/logs/sources') { echo json_encode(['ok' => true, 'sources' => $logs->sources()]); return true; }
        $off = isset($_GET['offset']) && $_GET['offset'] !== '' ? (int)$_GET['offset'] : null;
        echo json_encode(['ok' => true] + $logs->tail((string)($_GET['name'] ?? ''), (int)($_GET['lines'] ?? 300), $off));
        return true;
    }

    // Scope: ?site=<hostname> or ?project=<folder> roots the sandbox at that one site's folder; none = all of htdocs.
    $htdocs = rtrim($cfg['paths']['htdocs'], '/\\');
    $site = trim((string)($_GET['site'] ?? '')); $proj = trim((string)($_GET['project'] ?? ''));
    $routeEntry = $site !== '' ? ($state->findRoute($site) ?? apiError("Site not found: $site", 404)) : null;
    if ($routeEntry) $proj = (string)$routeEntry['project'];
    if ($proj !== '' && (!sanitizeProjectName($proj) || !is_dir("$htdocs/$proj"))) apiError("Project folder not found: $proj", 404);
    $fs = new Files($proj !== '' ? "$htdocs/$proj" : $htdocs, APP_ROOT, STATE_DIR, $log);

    if ($method === 'GET' && $path === '/api/fs/sites') {
        $seen = []; $out = [];
        foreach ($state->loadRoutes() as $r) { $seen[$r['project']] = true; $out[] = ['hostname' => $r['hostname'], 'project' => $r['project'], 'enabled' => $r['enabled'] ?? true, 'expires_at' => $r['expires_at'] ?? null]; }
        foreach ((new ProjectScanner($cfg['paths']['htdocs']))->scan() as $p) if (empty($seen[$p['name']])) $out[] = ['hostname' => null, 'project' => $p['name'], 'enabled' => null, 'expires_at' => null];
        echo json_encode(['ok' => true, 'sites' => $out]);
        return true;
    }
    if ($method === 'GET' && $path === '/api/fs/info') {
        echo json_encode(['ok' => true, 'label' => $proj !== '' ? $proj : 'htdocs', 'project' => $proj ?: null, 'hostname' => $routeEntry['hostname'] ?? null, 'enabled' => $routeEntry['enabled'] ?? null,
                          'expires_at' => $routeEntry['expires_at'] ?? null, 'expired_at' => $routeEntry['expired_at'] ?? null]);
        return true;
    }
    $b  = $method === 'GET' ? [] : (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') ? $_POST : jsonBody());
    $p  = (string)($_GET['path'] ?? $b['path'] ?? '');

    switch ($method . ' ' . $path) {
        case 'GET /api/fs/list':     echo json_encode(['ok' => true, 'path' => trim($p, '/'), 'entries' => $fs->list($p)]); return true;
        case 'GET /api/fs/read':     echo json_encode(['ok' => true] + $fs->read($p)); return true;
        case 'POST /api/fs/write':   echo json_encode(['ok' => true] + $fs->write($p, (string)($b['content'] ?? ''), isset($b['mtime']) ? (int)$b['mtime'] : null)); return true;
        case 'POST /api/fs/chmod':   echo json_encode(['ok' => true, 'mode' => $fs->chmod($p, (string)($b['mode'] ?? ''))]); return true;
        case 'POST /api/fs/compress': echo json_encode(['ok' => true, 'path' => $fs->compress((string)($b['dir'] ?? ''), (array)($b['paths'] ?? []), isset($b['name']) ? (string)$b['name'] : null)]); return true;
        case 'POST /api/fs/create':  $fs->create($p); echo json_encode(['ok' => true]); return true;
        case 'POST /api/fs/mkdir':   $fs->mkdir($p); echo json_encode(['ok' => true]); return true;
        case 'POST /api/fs/rename':  $fs->rename((string)($b['from'] ?? ''), (string)($b['to'] ?? '')); echo json_encode(['ok' => true]); return true;
        case 'POST /api/fs/copy':    $fs->copy((string)($b['from'] ?? ''), (string)($b['to'] ?? '')); echo json_encode(['ok' => true]); return true;
        case 'POST /api/fs/delete':  echo json_encode(['ok' => true, 'ids' => $fs->delete((array)($b['paths'] ?? []))]); return true;
        case 'POST /api/fs/unzip':   echo json_encode(['ok' => true, 'path' => $fs->unzip($p)]); return true;
        case 'GET /api/fs/versions': echo json_encode(['ok' => true, 'versions' => $fs->versions($p)]); return true;
        case 'GET /api/fs/version':  echo json_encode(['ok' => true, 'content' => $fs->versionContent($p, (string)($_GET['id'] ?? ''))]); return true;
        case 'GET /api/fs/trash':    echo json_encode(['ok' => true, 'items' => $fs->trashList()]); return true;
        case 'POST /api/fs/restore': echo json_encode(['ok' => true, 'path' => $fs->trashRestore((string)($b['id'] ?? ''))]); return true;
        case 'POST /api/fs/trash-empty': echo json_encode(['ok' => true, 'removed' => $fs->trashEmpty()]); return true;
        case 'POST /api/fs/upload':
            if (empty($_FILES['files'])) throw new RouteException('No files arrived — the upload is probably larger than post_max_size (' . ini_get('post_max_size') . ') or upload_max_filesize (' . ini_get('upload_max_filesize') . ') in php.ini.', 413);
            echo json_encode(['ok' => true, 'results' => $fs->upload((string)($b['dir'] ?? ''), $_FILES['files'] ?? [], !empty($b['overwrite']))]);
            return true;
        case 'GET /api/fs/raw':      // inline preview for images/text/pdf; everything else downloads
        case 'GET /api/fs/download':
            $abs = $fs->resolve($p);
            $isZip = is_dir($abs);
            $paths = $isZip ? [$p] : [];
            $file = $isZip ? $fs->zip($paths) : $abs;
            $name = basename($abs) . ($isZip ? '.zip' : '');
            $mime = $isZip ? 'application/zip' : ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream');
            $inline = $path === '/api/fs/raw' && !$isZip && preg_match('#^(image/(png|jpe?g|gif|webp|bmp)|text/plain|application/pdf)$#', $mime);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            if ($isZip) @unlink($file);
            exit;
        case 'POST /api/fs/zip':     // zip several items and download
            $tmp = $fs->zip((array)($b['paths'] ?? []));
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="archive.zip"'); header('Content-Length: ' . filesize($tmp));
            readfile($tmp); @unlink($tmp); exit;
    }
    return false;
}

/**
 * Per-route page endpoints: /api/routes/{host}/(detail|analytics|probe|label). Cloudflare problems degrade a panel
 * instead of failing the page — everything the tool itself knows comes straight from its own files.
 */
function routePageRoute(string $method, string $path, ?CloudflareClient $cf, array $cfg, State $state, ActivityLog $log, VhostManager $vm): bool
{
    if (!preg_match('#^/api/routes/(.+)/(detail|analytics|probe|label)$#', $path, $m)) return false;
    $host = urldecode($m[1]);
    $route = $state->findRoute($host) ?? apiError("Route not found: $host", 404);
    $in = $cf ? new Insights($cf, $cfg['cloudflare']['account_id'], STATE_DIR) : null;
    $mon = $in ? new Monitor($state, $in, new Alerts(STATE_DIR), STATE_DIR, $cfg['cloudflare']['tunnel_id']) : null;

    if ($method === 'POST' && $m[2] === 'label') {
        $label = trim((string)(jsonBody()['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 100 || preg_match('/[<>\x00-\x1f]/', $label)) apiError('Label: 1-100 characters, no < > or control characters');
        $state->upsertRoute(['hostname' => $host, 'label' => $label]);
        $log->append('route_label', $host, true, $label);
        echo json_encode(['ok' => true, 'label' => $label]);
        return true;
    }
    if ($method !== 'GET') return false;

    if ($m[2] === 'analytics') {                                  // GraphQL, 24 h, this hostname only
        requireCf($cf);
        try { echo json_encode(['ok' => true] + $in->routeAnalytics((string)$route['zone_id'], $host)); }
        catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        return true;
    }
    if ($m[2] === 'probe') {                                      // a fresh check through the tunnel, right now
        requireCf($cf);
        echo json_encode(['ok' => true, 'probe' => ($in->probe([$host])[$host] ?? null)]);
        return true;
    }

    // detail: what this tool knows (instant) + the live DNS record (one cached Cloudflare call, optional)
    $dns = null; $dnsError = null;
    if ($in && !empty($route['zone_id'])) { try { $dns = $in->dnsRecord((string)$route['zone_id'], $host); } catch (Throwable $e) { $dnsError = $e->getMessage(); } }
    $block = null; foreach ($vm->parseBlocks() as $bh => $text) if (strtolower($bh) === strtolower($host)) $block = RouteImporter::parseBlock($text);
    $zoneName = ''; if ($in && !empty($route['zone_id'])) { try { foreach ($in->zones() as $z) if ($z['id'] === $route['zone_id']) $zoneName = $z['name']; } catch (Throwable) {} }
    $alerts = array_values(array_filter((new Alerts(STATE_DIR))->all(), fn($a) => ($a['host'] ?? '') === $host));
    $acts   = array_values(array_filter($log->read(1000), fn($e) => ($e['hostname'] ?? '') === $host));
    echo json_encode(['ok' => true, 'route' => $route, 'zone_name' => $zoneName, 'dns' => $dns, 'dns_error' => $dnsError, 'vhost' => $block,
                      'health' => $mon ? $mon->summaryFor($host) : null, 'alerts' => array_slice($alerts, 0, 10), 'activity' => array_slice($acts, 0, 15)]);
    return true;
}

/**
 * Portal users and the portal's address (admin only — this file is never reachable through the tunnel).
 * /api/users…  ·  /api/portal…  ·  /api/routes/{host}/portal
 */
function usersRoute(string $method, string $path, ?CloudflareClient $cf, array $cfg, State $state, ActivityLog $log, VhostManager $vm, ApacheManager $ap): bool
{
    if (!preg_match('#^/api/(users|portal)|^/api/routes/.+/portal$#', $path)) return false;
    $store = new UserStore(STATE_DIR);
    $b = $method === 'GET' ? [] : jsonBody();
    $inviteUrl = fn(string $token) => Config::portalHost() !== '' ? 'https://' . Config::portalHost() . '/?invite=' . $token : null;

    // POST /api/routes/{host}/portal {enabled}
    if ($method === 'POST' && preg_match('#^/api/routes/(.+)/portal$#', $path, $m)) {
        $host = urldecode($m[1]);
        $state->findRoute($host) ?? apiError("Route not found: $host", 404);
        $on = !empty($b['enabled']);
        $state->upsertRoute(['hostname' => $host, 'portal_enabled' => $on]);
        $log->append('route_portal', $host, true, $on ? 'file access for users on' : 'file access for users off');
        echo json_encode(['ok' => true, 'enabled' => $on]);
        return true;
    }

    // GET /api/users — everything the Users page and the route cards need
    if ($method === 'GET' && $path === '/api/users') {
        $users = array_map(fn($u) => $u + ['access' => $store->access($u['id'])], $store->all());
        $routes = array_map(fn($r) => ['hostname' => $r['hostname'], 'label' => $r['label'] ?? $r['project'], 'portal_enabled' => !empty($r['portal_enabled']), 'enabled' => $r['enabled'] ?? true], $state->loadRoutes());
        echo json_encode(['ok' => true, 'users' => $users, 'routes' => $routes, 'max' => UserStore::MAX_USERS,
                          'portal' => ['hostname' => Config::portalHost(), 'upload_mb' => $cfg['portal']['upload_mb'], 'site_mb' => $cfg['portal']['site_mb']]]);
        return true;
    }
    if ($method === 'GET' && $path === '/api/users/activity') {
        echo json_encode(['ok' => true, 'entries' => (new PortalLog(STATE_DIR))->read(max(1, min((int)($_GET['limit'] ?? 100), 500)), ($_GET['user'] ?? '') ?: null, ($_GET['host'] ?? '') ?: null)]);
        return true;
    }
    // POST /api/users {username, display, code_files}
    if ($method === 'POST' && $path === '/api/users') {
        $r = $store->create((string)($b['username'] ?? ''), (string)($b['display'] ?? ''), !empty($b['code_files']));
        $log->append('user_create', $r['user']['username'], true, !empty($b['code_files']) ? 'with code-file permission' : '');
        echo json_encode(['ok' => true, 'user' => $r['user'], 'token' => $r['token'], 'invite_url' => $inviteUrl($r['token'])]);
        return true;
    }
    if (preg_match('#^/api/users/(u_[0-9a-f]{8})(?:/(update|invite|signout|grant))?$#', $path, $m)) {
        $id = $m[1]; $sub = $m[2] ?? '';
        $u = $store->findPublic($id) ?? apiError('User not found', 404);
        if ($method === 'POST' && $sub === 'update') {
            $patch = array_intersect_key($b, array_flip(['display', 'code_files', 'disabled']));
            $out = $store->update($id, $patch);
            $log->append('user_update', $u['username'], true, json_encode(array_map(fn($v) => is_bool($v) ? ($v ? 'on' : 'off') : $v, $patch)));
            echo json_encode(['ok' => true, 'user' => $out]); return true;
        }
        if ($method === 'POST' && $sub === 'invite') {
            $r = $store->resetInvite($id);
            $log->append('user_reset', $u['username'], true, 'password cleared, new invitation');
            echo json_encode(['ok' => true, 'user' => $r['user'], 'token' => $r['token'], 'invite_url' => $inviteUrl($r['token'])]); return true;
        }
        if ($method === 'POST' && $sub === 'signout') { $store->signOutEverywhere($id); $log->append('user_signout', $u['username'], true, ''); echo json_encode(['ok' => true]); return true; }
        if ($method === 'DELETE' && $sub === '') {
            if (($_GET['confirm'] ?? '') !== '1') apiError('confirm=1 required', 400);
            $store->delete($id); $log->append('user_delete', $u['username'], true, ''); echo json_encode(['ok' => true]); return true;
        }
        if ($method === 'POST' && $sub === 'grant') {
            $host = strtolower(trim((string)($b['host'] ?? '')));
            $state->findRoute($host) ?? apiError("Route not found: $host", 404);
            $until = isset($b['until']) && $b['until'] !== null && $b['until'] !== '' ? (int)$b['until'] : (isset($b['in']) && (int)$b['in'] > 0 ? time() + (int)$b['in'] : null);
            $store->grant($id, $host, !empty($b['upload']), !empty($b['edit']), !empty($b['delete']), $until);
            $log->append('user_grant', $u['username'], true, "$host: " . implode('+', array_keys(array_filter(['view' => true, 'upload' => !empty($b['upload']), 'edit' => !empty($b['edit']), 'delete' => !empty($b['delete'])]))) . ($until ? ' until ' . gmdate('Y-m-d H:i', $until) . ' UTC' : ''));
            echo json_encode(['ok' => true]); return true;
        }
        if ($method === 'DELETE' && $sub === 'grant') {
            $host = strtolower(trim((string)($_GET['host'] ?? '')));
            $store->revoke($id, $host); $log->append('user_revoke', $u['username'], true, $host); echo json_encode(['ok' => true]); return true;
        }
    }

    // ---- the portal's public address
    if ($method === 'GET' && $path === '/api/portal') { echo json_encode(['ok' => true, 'portal' => ['hostname' => Config::portalHost(), 'upload_mb' => $cfg['portal']['upload_mb'], 'site_mb' => $cfg['portal']['site_mb']]]); return true; }
    if ($method === 'POST' && $path === '/api/portal/enable') {
        requireCf($cf);
        $r = (new PortalSetup($cf, $vm, $ap, $state, $log, APP_ROOT))->enable((string)($b['zone_id'] ?? ''), (string)($b['subdomain'] ?? ''));
        echo json_encode(['ok' => true] + $r); return true;
    }
    if ($method === 'POST' && $path === '/api/portal/disable') {
        $warn = (new PortalSetup($cf, $vm, $ap, $state, $log, APP_ROOT))->disable();      // works without Cloudflare: turning it off must never get stuck
        echo json_encode(['ok' => true, 'warnings' => $warn]); return true;
    }
    return false;
}
