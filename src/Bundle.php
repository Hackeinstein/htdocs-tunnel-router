<?php
declare(strict_types=1);

/**
 * Backup bundle: config (token optional), routes (with expiries), vhost blocks and each route's DNS record.
 * export() builds one, plan() previews a restore, apply() performs it. snapshot() is the cheap local
 * safety copy taken before bulk runs and restores (no Cloudflare calls).
 */
class Bundle
{
    private const FORMAT = 'tunnel-router-backup';
    private const KEEP_SNAPSHOTS = 20;

    public function __construct(
        private State $state, private VhostManager $vm, private ApacheManager $ap, private ActivityLog $log,
        private ?CloudflareClient $cf, private string $backupDir,
    ) {}

    public function export(bool $includeToken): array
    {
        $b = $this->base($includeToken);
        $b['dns'] = [];
        if ($this->cf) {
            try {                                     // the DNS section is a bonus: never lose the local backup because Cloudflare is down
                $zones = [];
                foreach ($this->cf->listZones() as $z) $zones[$z['id']] = $z['name'];
                foreach ($b['routes'] as $r) {
                    if (empty($r['zone_id'])) continue;
                    try { $rec = $this->cf->getDnsRecord($r['zone_id'], $r['hostname']); } catch (Throwable) { $b['dns_incomplete'] = true; continue; }
                    if ($rec) $b['dns'][] = ['zone_id' => $r['zone_id'], 'zone_name' => $zones[$r['zone_id']] ?? '', 'type' => $rec['type'], 'name' => $rec['name'],
                                             'content' => $rec['content'], 'proxied' => (bool)($rec['proxied'] ?? false), 'ttl' => $rec['ttl'] ?? 1];
                }
            } catch (Throwable $e) {
                $b['dns_error'] = 'DNS records not included: ' . $e->getMessage();
            }
        }
        return $b;
    }

    /** Local-only copy of config (no token), routes and vhost blocks. Returns the file name. */
    public function snapshot(string $why): string
    {
        $b = $this->base(false); $b['reason'] = $why;
        $dir = rtrim(normalizePath($this->backupDir), '/');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $name = 'snapshot_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 4) . '.json';
        atomicWrite("$dir/$name", jsonPretty($b));
        $all = $this->snapshots();
        foreach (array_slice($all, self::KEEP_SNAPSHOTS) as $old) @unlink("$dir/{$old['name']}");
        return $name;
    }

    /** Newest first: [{name, at, reason, routes}] */
    public function snapshots(): array
    {
        $out = [];
        foreach (glob(rtrim(normalizePath($this->backupDir), '/') . '/snapshot_*.json') ?: [] as $f) {
            $j = json_decode((string)file_get_contents($f), true) ?: [];
            $out[] = ['name' => basename($f), 'at' => $j['exported_at'] ?? '', 'reason' => $j['reason'] ?? '', 'routes' => count($j['routes'] ?? [])];
        }
        usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    public function loadSnapshot(string $name): array
    {
        if (!preg_match('/^snapshot_[0-9_a-f]+\.json$/', $name)) throw new RouteException('Invalid snapshot name');
        $f = rtrim(normalizePath($this->backupDir), '/') . '/' . $name;
        if (!is_file($f)) throw new RouteException('Snapshot not found', 404);
        return json_decode((string)file_get_contents($f), true) ?: throw new RouteException('Snapshot is unreadable');
    }

    /** Preview: what restoring this bundle would do, per route. Changes nothing. */
    public function plan(array $b, bool $useConfig): array
    {
        $this->check($b);
        $htdocs = rtrim((string)(($useConfig ? ($b['config']['paths']['htdocs'] ?? '') : '') ?: Config::load()['paths']['htdocs']), '/\\');
        $blocks = $this->vm->parseBlocks();
        $dnsBy  = [];
        foreach ($b['dns'] ?? [] as $d) $dnsBy[$d['name'] ?? ''] = $d;

        $routes = [];
        foreach ($b['routes'] as $r) {
            $h = (string)($r['hostname'] ?? ''); $project = (string)($r['project'] ?? '');
            $acts = []; $problems = [];
            if (!sanitizeHostname($h)) $problems[] = 'invalid hostname';
            if (!empty($r['zone_id']) && !preg_match('/^[a-f0-9]{32}$/i', (string)$r['zone_id'])) $problems[] = 'invalid zone id';
            if (isset($dnsBy[$h]) && !preg_match('/^[a-f0-9]{32}$/i', (string)($dnsBy[$h]['zone_id'] ?? ''))) $problems[] = 'invalid zone id in the DNS section';
            if (!sanitizeProjectName($project) || !is_dir("$htdocs/$project")) $problems[] = "project folder not found: $htdocs/$project";

            $acts['route'] = $this->state->findRoute($h) ? 'update' : 'add';
            $acts['vhost'] = isset($blocks[$h]) ? 'keep' : 'add';
            $d = $dnsBy[$h] ?? null;
            if (!$d) $acts['dns'] = 'none in backup';
            elseif (!$this->cf) $acts['dns'] = 'unknown (Cloudflare not configured)';
            else {
                try { $acts['dns'] = $this->cf->getDnsRecord($d['zone_id'], $h) ? 'keep' : 'create'; }
                catch (Throwable $e) { $acts['dns'] = 'unknown'; $problems[] = 'zone not reachable: ' . $e->getMessage(); }
            }
            $routes[] = ['hostname' => $h, 'project' => $project, 'actions' => $acts, 'problems' => $problems];
        }
        $cfgChanges = [];
        if ($useConfig) {
            $cur = Config::load();
            foreach (['paths', 'defaults'] as $sec) foreach ($b['config'][$sec] ?? [] as $k => $v) if (($cur[$sec][$k] ?? null) !== $v) $cfgChanges[] = "$sec.$k";
            foreach (['account_id', 'tunnel_id', 'tunnel_name'] as $k) if (isset($b['config']['cloudflare'][$k]) && ($cur['cloudflare'][$k] ?? '') !== $b['config']['cloudflare'][$k]) $cfgChanges[] = "cloudflare.$k";
            if (!empty($b['config']['cloudflare']['api_token'])) $cfgChanges[] = 'cloudflare.api_token';
        }
        return ['exported_at' => $b['exported_at'] ?? '', 'routes' => $routes, 'config' => $cfgChanges];
    }

    /** Restore. Takes a snapshot first; any vhost/Apache failure puts the vhosts file back. */
    public function apply(array $b, bool $useConfig, bool $useRoutes): array
    {
        $plan = $this->plan($b, $useConfig);
        $snap = $this->snapshot('before restore');
        $results = [];

        if ($useConfig && !empty($b['config'])) {
            // The file may have been edited or come from elsewhere: it passes the same validation as the Settings form.
            $merged = array_replace_recursive(Config::load(), array_intersect_key($b['config'], array_flip(['cloudflare', 'paths', 'defaults'])));
            if (empty($b['config']['cloudflare']['api_token'])) $merged['cloudflare']['api_token'] = Config::load()['cloudflare']['api_token'];
            Config::save(Config::clean($merged));
            $results[] = ['id' => 'config', 'ok' => true, 'detail' => count($plan['config']) . ' setting(s) restored'];
        }
        if (!$useRoutes) return ['snapshot' => $snap, 'results' => $results];

        $cfg = Config::load();
        $vhostsFile = normalizePath($cfg['paths']['vhosts_file']);
        $before = is_file($vhostsFile) ? (string)file_get_contents($vhostsFile) : '';
        $dnsBy = []; foreach ($b['dns'] ?? [] as $d) $dnsBy[$d['name'] ?? ''] = $d;
        $routesBefore = $this->state->loadRoutes();
        $createdDns = []; $touchedVhost = false; $ok = [];

        foreach ($b['routes'] as $i => $r) {
            $p = $plan['routes'][$i]; $h = $p['hostname'];
            if ($p['problems']) { $results[] = ['id' => $h, 'ok' => false, 'error' => implode('; ', $p['problems'])]; continue; }
            try {
                $docRoot = rtrim($cfg['paths']['htdocs'], '/\\') . '/' . $r['project'];
                if ($p['actions']['dns'] === 'create') {
                    $d = $dnsBy[$h];
                    $res = $this->cf->request('POST', "/zones/{$d['zone_id']}/dns_records", ['type' => $d['type'], 'name' => $h, 'content' => $d['content'], 'proxied' => $d['proxied'], 'ttl' => $d['ttl']]);
                    $r['dns_record_id'] = $res['result']['id'] ?? ($r['dns_record_id'] ?? '');
                    $createdDns[$h] = [$d['zone_id'], $r['dns_record_id']];
                } elseif (isset($dnsBy[$h]) && $this->cf && $p['actions']['dns'] === 'keep') {
                    $existing = $this->cf->getDnsRecord($dnsBy[$h]['zone_id'], $h);
                    if ($existing) $r['dns_record_id'] = $existing['id'];
                }
                if ($p['actions']['vhost'] === 'add') {
                    $this->vm->addBlock($h, $docRoot, (bool)($r['www_alias'] ?? false));
                    if (($r['enabled'] ?? true) === false) $this->vm->disableBlock($h);
                    $touchedVhost = true;
                }
                $r['document_root'] = $docRoot;
                $this->state->upsertRoute($this->cleanRoute($r));
                $ok[$h] = true;
                $results[] = ['id' => $h, 'ok' => true, 'detail' => 'restored'];
            } catch (Throwable $e) { $results[] = ['id' => $h, 'ok' => false, 'error' => $e->getMessage()]; }
        }

        if ($touchedVhost) {
            $ap = $this->ap->validateAndReload($this->vm);
            if (!$ap['ok']) {                                   // roll the whole vhost file back, and the DNS we created
                atomicWrite($vhostsFile, $before);
                foreach ($createdDns as $h => [$z, $id]) { try { if ($id) $this->cf->deleteDnsRecord($z, $id); } catch (Throwable) {} }
                $this->state->saveRoutes($routesBefore);
                $this->log->append('restore', 'bundle', false, 'Apache validation failed — rolled back');
                throw new RouteException('Apache rejected the restored vhosts — everything was rolled back. ' . ($ap['error'] ?? ''), 500, ['output' => $ap['output'] ?? '', 'snapshot' => $snap]);
            }
        }
        $this->log->append('restore', 'bundle', true, count($ok) . ' route(s) restored; snapshot ' . $snap);
        return ['snapshot' => $snap, 'results' => $results];
    }

    // ---- internals -----------------------------------------------------------

    private function base(bool $includeToken): array
    {
        $cfg = Config::load();
        if (!$includeToken) $cfg['cloudflare']['api_token'] = '';
        return ['format' => self::FORMAT, 'version' => 1, 'exported_at' => nowUtc(), 'config' => $cfg,
                'routes' => $this->state->loadRoutes(), 'vhosts' => $this->vm->parseBlocks()];
    }

    /** Only known fields, correctly typed, from a route entry that came out of a file. */
    private function cleanRoute(array $r): array
    {
        $exp = $r['expires_at'] ?? null;
        return ['hostname' => (string)$r['hostname'], 'type' => ($r['type'] ?? '') === 'apex' ? 'apex' : 'subdomain', 'zone_id' => (string)($r['zone_id'] ?? ''),
                'project' => (string)$r['project'], 'document_root' => (string)$r['document_root'], 'www_alias' => (bool)($r['www_alias'] ?? false),
                'dns_record_id' => preg_match('/^[a-f0-9]{32}$/i', (string)($r['dns_record_id'] ?? '')) ? (string)$r['dns_record_id'] : '',
                'enabled' => ($r['enabled'] ?? true) !== false, 'label' => mb_substr((string)($r['label'] ?? $r['project']), 0, 100),
                'expires_at' => is_int($exp) && $exp > 0 ? $exp : null];
    }

    private function check(array $b): void
    {
        if (($b['format'] ?? '') !== self::FORMAT) throw new RouteException('Not a Tunnel Router backup file');
        if (($b['version'] ?? 0) > 1) throw new RouteException('Backup was made by a newer version');
        if (!is_array($b['routes'] ?? null)) throw new RouteException('Backup has no routes list');
    }
}
