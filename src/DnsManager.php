<?php
declare(strict_types=1);

/** Browse and edit a zone's DNS records. Records that belong to a tunnel route are protected unless forced. */
class DnsManager
{
    /** Types the UI may create/edit. Others (SRV, CAA, …) are listed read-only. */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS'];

    public function __construct(private CloudflareClient $cf, private State $state, private ActivityLog $log, private ?Cache $cache = null) {}

    /** All records, newest shape for the UI, optionally filtered by text and type. */
    public function list(string $zoneId, string $q = '', string $type = ''): array
    {
        $owned = [];
        foreach ($this->state->loadRoutes() as $r) if (!empty($r['dns_record_id'])) $owned[$r['dns_record_id']] = $r['hostname'];

        $fetch = function () use ($zoneId, $owned) {
            $out = []; $page = 1;
            do {
                $res = $this->cf->request('GET', "/zones/$zoneId/dns_records", [], ['page' => $page, 'per_page' => 100]);
                foreach ($res['result'] ?? [] as $r) {
                    $content = (string)($r['content'] ?? '');
                    $out[] = [
                        'id' => $r['id'], 'type' => $r['type'], 'name' => $r['name'], 'content' => $content,
                        'ttl' => $r['ttl'] ?? 1, 'proxied' => (bool)($r['proxied'] ?? false), 'priority' => $r['priority'] ?? null,
                        'comment' => $r['comment'] ?? '', 'editable' => in_array($r['type'], self::TYPES, true),
                        'tunnel' => str_ends_with($content, '.cfargotunnel.com'), 'route' => null,
                    ];
                }
                $info = $res['result_info'] ?? []; $page++;
            } while (isset($info['page'], $info['total_pages']) && $info['page'] < $info['total_pages'] && $page <= 50);
            return $out;
        };
        $out = $this->cache ? $this->cache->remember("dnslist_$zoneId", 20, $fetch) : $fetch();   // 20 s; edits drop it
        foreach ($out as &$rec) $rec['route'] = $owned[$rec['id']] ?? null;                         // ownership is always current
        unset($rec);

        $q = strtolower(trim($q));
        return array_values(array_filter($out, fn($r) => ($type === '' || $r['type'] === $type)
            && ($q === '' || str_contains(strtolower($r['name'] . ' ' . $r['content'] . ' ' . $r['comment']), $q))));
    }

    public function create(string $zoneId, string $zoneName, array $in): array
    {
        $rec = $this->clean($zoneName, $in);
        $res = $this->cf->request('POST', "/zones/$zoneId/dns_records", $rec);
        RouteService::dnsChanged();
        $this->log->append('dns_create', $rec['name'], true, "{$rec['type']} {$rec['content']}");
        return $res['result'] ?? [];
    }

    public function update(string $zoneId, string $zoneName, string $id, array $in, bool $force): array
    {
        $this->guard($zoneId, $id, $force);
        $rec = $this->clean($zoneName, $in);
        $res = $this->cf->request('PATCH', "/zones/$zoneId/dns_records/$id", $rec);
        RouteService::dnsChanged();
        $this->log->append('dns_update', $rec['name'], true, "{$rec['type']} {$rec['content']}");
        return $res['result'] ?? [];
    }

    public function delete(string $zoneId, string $id, bool $force): void
    {
        $this->guard($zoneId, $id, $force);
        $this->cf->request('DELETE', "/zones/$zoneId/dns_records/$id");
        RouteService::dnsChanged();
        $this->log->append('dns_delete', $id, true, 'record deleted');
    }

    /** Add records that don't already exist (same type+name+content). Dry run reports what would happen. */
    public function import(string $zoneId, string $zoneName, array $records, bool $dryRun): array
    {
        $have = [];
        foreach ($this->list($zoneId) as $r) $have[strtolower("{$r['type']}|{$r['name']}|{$r['content']}")] = true;
        $out = [];
        foreach ($records as $i => $in) {
            $id = ($in['type'] ?? '?') . ' ' . ($in['name'] ?? '?');
            try {
                $rec = $this->clean($zoneName, is_array($in) ? $in : []);
                $id  = "{$rec['type']} {$rec['name']}";
                if (isset($have[strtolower("{$rec['type']}|{$rec['name']}|{$rec['content']}")])) { $out[] = ['id' => $id, 'ok' => true, 'detail' => 'already exists — skipped']; continue; }
                if (!$dryRun) { $this->cf->request('POST', "/zones/$zoneId/dns_records", $rec); $this->log->append('dns_import', $rec['name'], true, "{$rec['type']} {$rec['content']}"); }
                $out[] = ['id' => $id, 'ok' => true, 'detail' => $dryRun ? 'would create' : 'created'];
            } catch (Throwable $e) { $out[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()]; }
        }
        return $out;
    }

    public function export(string $zoneId, string $format): string
    {
        $rows = array_map(fn($r) => array_intersect_key($r, array_flip(['type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'])), $this->list($zoneId));
        if ($format === 'json') return jsonPretty($rows);
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment'], ',', '"', '\\');
        foreach ($rows as $r) fputcsv($fh, [$r['type'], $r['name'], $r['content'], $r['ttl'], $r['proxied'] ? 'true' : 'false', $r['priority'] ?? '', $r['comment']], ',', '"', '\\');
        rewind($fh); return (string)stream_get_contents($fh);
    }

    /** Validate and normalise a record from user input. Throws RouteException with a readable reason. */
    public function clean(string $zoneName, array $in): array
    {
        $type = strtoupper(trim((string)($in['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) throw new RouteException('Type must be one of ' . implode(', ', self::TYPES));

        $name = strtolower(trim((string)($in['name'] ?? '')));
        if ($name === '' || $name === '@') $name = $zoneName;
        elseif ($name !== $zoneName && !str_ends_with($name, '.' . $zoneName)) $name .= '.' . $zoneName;
        if (!preg_match('/^(\*\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) || strlen($name) > 253) throw new RouteException("Invalid name: $name");

        $content = trim((string)($in['content'] ?? ''));
        if ($content === '') throw new RouteException('Content is required');
        if ($type === 'A' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RouteException('A record needs an IPv4 address');
        if ($type === 'AAAA' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RouteException('AAAA record needs an IPv6 address');
        if (in_array($type, ['CNAME', 'MX', 'NS'], true) && !preg_match('/^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9-]{2,}\.?$/i', $content)) throw new RouteException("$type content must be a hostname");
        if ($type === 'TXT' && strlen($content) > 2048) throw new RouteException('TXT content too long');

        $ttl = (int)($in['ttl'] ?? 1);
        if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) throw new RouteException('TTL: 1 (auto) or 60–86400 seconds');
        $rec = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl, 'comment' => mb_substr(trim((string)($in['comment'] ?? '')), 0, 100)];
        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) $rec['proxied'] = filter_var($in['proxied'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($type === 'MX') {
            $pr = (int)($in['priority'] ?? 10);
            if ($pr < 0 || $pr > 65535) throw new RouteException('MX priority: 0–65535');
            $rec['priority'] = $pr;
        }
        return $rec;
    }

    private function guard(string $zoneId, string $id, bool $force): void
    {
        foreach ($this->state->loadRoutes() as $r) {
            if (($r['dns_record_id'] ?? '') === $id && !$force) {
                throw new RouteException("This record belongs to the route {$r['hostname']}. Change or detach the route instead, or confirm to override.", 409, ['route' => $r['hostname']]);
            }
        }
    }
}
