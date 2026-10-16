<?php
declare(strict_types=1);

/**
 * Rebuilds routes.json from what is really live — the fenced vhost blocks in httpd-vhosts.conf and the
 * CNAME records that point at this tunnel — or from an old routes.json file. It only ever WRITES routes.json:
 * Apache and Cloudflare are read, never changed, and existing entries are never overwritten.
 */
class RouteImporter
{
    public function __construct(private State $state, private VhostManager $vm, private ActivityLog $log, private ?CloudflareClient $cf, private string $tunnelId, private string $htdocs) {}

    /**
     * Everything discoverable, flagged by what can be imported.
     * status: importable | tracked (already in routes.json) | dns_only (no vhost: attach it from Attach Route) | project_missing
     * @return array{items: array, zones_failed: array}
     */
    public function scan(): array
    {
        $tracked = array_column($this->state->loadRoutes(), null, 'hostname');
        $zones = []; $failed = [];
        $dns = [];                                                   // hostname => [zone_id, record]
        if ($this->cf) {
            try { $zones = $this->cf->listZones(); } catch (Throwable $e) { $failed['*'] = $e->getMessage(); }
            $specs = [];                                            // one filtered query per zone, all at once (was one after another: ~0.4 s × zones)
            foreach ($zones as $z) $specs[$z['id']] = ['GET', "/zones/{$z['id']}/dns_records", [], ['type' => 'CNAME', 'content' => $this->tunnelId . '.cfargotunnel.com', 'per_page' => 100]];
            $names = array_column($zones, 'name', 'id');
            foreach ($specs ? $this->cf->requestMany($specs) : [] as $zid => $r) {
                if (!$r['ok']) { $failed[$names[$zid]] = $r['error']; continue; }
                foreach ($r['data']['result'] ?? [] as $rec) $dns[strtolower($rec['name'])] = [$zid, $rec];
            }
        }

        $blocks = $this->vm->parseBlocks();
        $portal = Config::portalHost();
        $hosts = array_values(array_filter(array_unique(array_merge(array_map('strtolower', array_keys($blocks)), array_keys($dns))), fn($h) => $portal === '' || $h !== $portal));
        sort($hosts);
        $items = [];
        foreach ($hosts as $h) {
            $block = null; foreach ($blocks as $bh => $text) if (strtolower($bh) === $h) $block = $text;
            $info  = $block !== null ? self::parseBlock($block) : null;
            $zone  = isset($dns[$h]) ? $this->zoneById($zones, $dns[$h][0]) : $this->zoneFor($zones, $h);
            $project = $info ? $this->projectFor($info['document_root']) : null;
            $status = isset($tracked[$h]) ? 'tracked' : (!$info ? 'dns_only' : ($project === null ? 'project_missing' : 'importable'));
            $items[] = ['hostname' => $h, 'status' => $status, 'has_vhost' => $info !== null, 'has_dns' => isset($dns[$h]),
                        'enabled' => $info['enabled'] ?? true, 'www_alias' => $info['www_alias'] ?? false, 'document_root' => $info['document_root'] ?? null,
                        'project' => $project, 'zone_id' => $zone['id'] ?? '', 'zone_name' => $zone['name'] ?? '',
                        'dns_record_id' => $dns[$h][1]['id'] ?? '', 'type' => ($zone && $h === strtolower($zone['name'])) ? 'apex' : 'subdomain'];
        }
        return ['items' => $items, 'zones_failed' => $failed];
    }

    /** Import the chosen hostnames (from scan()). Returns [{id, ok, error?|detail?}]. Existing entries are skipped. */
    public function import(array $hostnames): array
    {
        $by = array_column($this->scan()['items'], null, 'hostname');
        $out = [];
        foreach (array_values(array_unique(array_map('strtolower', array_filter($hostnames, 'is_string')))) as $h) {
            $i = $by[$h] ?? null;
            if (!$i) { $out[] = ['id' => $h, 'ok' => false, 'error' => 'Not found in the live setup']; continue; }
            if ($i['status'] === 'tracked') { $out[] = ['id' => $h, 'ok' => true, 'detail' => 'already tracked — left as is']; continue; }
            if ($i['status'] !== 'importable') { $out[] = ['id' => $h, 'ok' => false, 'error' => $i['status'] === 'dns_only' ? 'No vhost block for it — use Attach Route to connect it to a project' : 'Its folder was not found in htdocs']; continue; }
            $this->state->upsertRoute($this->entry($i));
            RouteService::dnsChanged();
            $this->log->append('import', $h, true, 'rebuilt from the live setup' . ($i['has_dns'] ? '' : ' (no DNS record found)'));
            $out[] = ['id' => $h, 'ok' => true, 'detail' => 'imported' . ($i['has_dns'] ? '' : ' — but no DNS record exists for it') . ($i['enabled'] ? '' : ' (disabled)')];
        }
        return $out;
    }

    /**
     * Merge an old routes.json (a JSON list of route objects). Each entry is validated; the document root is rebuilt from
     * THIS machine's htdocs (old paths may differ); entries already tracked are left alone.
     * @return array [{id, ok, detail|error}]
     */
    public function fromRoutesFile(array $routes, bool $dryRun): array
    {
        $tracked = array_column($this->state->loadRoutes(), null, 'hostname');
        $out = [];
        foreach ($routes as $r) {
            $h = strtolower(trim((string)($r['hostname'] ?? ''))); $p = (string)($r['project'] ?? '');
            if (!is_array($r) || !sanitizeHostname($h)) { $out[] = ['id' => $h ?: '?', 'ok' => false, 'error' => 'invalid hostname']; continue; }
            if (isset($tracked[$h])) { $out[] = ['id' => $h, 'ok' => true, 'detail' => 'already tracked — left as is']; continue; }
            if (!sanitizeProjectName($p) || !is_dir($this->htdocs . '/' . $p)) { $out[] = ['id' => $h, 'ok' => false, 'error' => "project folder '$p' not found in htdocs"]; continue; }
            $zone = (string)($r['zone_id'] ?? '');
            if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/i', $zone)) { $out[] = ['id' => $h, 'ok' => false, 'error' => 'invalid zone id']; continue; }
            if (!$dryRun) {
                $exp = $r['expires_at'] ?? null;
                $this->state->upsertRoute(['hostname' => $h, 'type' => ($r['type'] ?? '') === 'apex' ? 'apex' : 'subdomain', 'zone_id' => $zone, 'project' => $p, 'document_root' => $this->htdocs . '/' . $p,
                    'www_alias' => (bool)($r['www_alias'] ?? false), 'dns_record_id' => preg_match('/^[a-f0-9]{32}$/i', (string)($r['dns_record_id'] ?? '')) ? (string)$r['dns_record_id'] : '',
                    'enabled' => ($r['enabled'] ?? true) !== false, 'label' => mb_substr((string)($r['label'] ?? $p), 0, 100), 'expires_at' => is_int($exp) && $exp > time() ? $exp : null]);
                $this->log->append('import', $h, true, 'from an old routes.json');
            }
            $out[] = ['id' => $h, 'ok' => true, 'detail' => $dryRun ? 'would import' : 'imported'];
        }
        return $out;
    }

    // ---- parsing & matching -------------------------------------------------

    /** @return array{document_root:string, enabled:bool, www_alias:bool}|null */
    public static function parseBlock(string $block): ?array
    {
        if (!preg_match('/^\s*#?\s*DocumentRoot\s+"?([^"\r\n]+?)"?\s*$/mi', $block, $m)) return null;
        return ['document_root' => normalizePath(trim($m[1])), 'enabled' => !preg_match('/^\s*#\s*<VirtualHost/mi', $block), 'www_alias' => (bool)preg_match('/ServerAlias\s+www\./i', $block)];
    }

    /** The htdocs sub-folder a DocumentRoot points at, or null if it is not in htdocs here. A foreign path falls back to its folder name. */
    private function projectFor(string $docRoot): ?string
    {
        $docRoot = rtrim(normalizePath($docRoot), '/'); $root = rtrim(normalizePath($this->htdocs), '/');
        $name = (strncasecmp($docRoot, $root . '/', strlen($root) + 1) === 0) ? explode('/', substr($docRoot, strlen($root) + 1))[0] : basename($docRoot);
        return $name !== '' && sanitizeProjectName($name) && is_dir($root . '/' . $name) ? $name : null;
    }

    private function zoneById(array $zones, string $id): ?array { foreach ($zones as $z) if ($z['id'] === $id) return $z; return null; }

    /** Longest zone whose name the hostname ends with (or equals). */
    private function zoneFor(array $zones, string $host): ?array
    {
        $best = null;
        foreach ($zones as $z) {
            $n = strtolower($z['name']);
            if (($host === $n || str_ends_with($host, '.' . $n)) && (!$best || strlen($n) > strlen($best['name']))) $best = $z;
        }
        return $best;
    }

    private function entry(array $i): array
    {
        return ['hostname' => $i['hostname'], 'type' => $i['type'], 'zone_id' => $i['zone_id'], 'project' => $i['project'], 'document_root' => rtrim($this->htdocs, '/') . '/' . $i['project'],
                'www_alias' => $i['www_alias'], 'dns_record_id' => $i['dns_record_id'], 'enabled' => $i['enabled'], 'label' => $i['project'], 'expires_at' => null];
    }
}
