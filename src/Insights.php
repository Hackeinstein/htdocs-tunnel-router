<?php
declare(strict_types=1);

/**
 * Read-side Cloudflare data for the Domains and Tunnels pages:
 * zones, zone settings, GraphQL analytics, tunnels, and live health probes.
 */
class Insights
{
    /** Zone settings the UI may change, with their allowed values. */
    public const SETTINGS = [
        'ssl'                      => ['off', 'flexible', 'full', 'strict'],
        'always_use_https'         => ['on', 'off'],
        'automatic_https_rewrites' => ['on', 'off'],
        'min_tls_version'          => ['1.0', '1.1', '1.2', '1.3'],
        'security_level'           => ['off', 'essentially_off', 'low', 'medium', 'high', 'under_attack'],
        'development_mode'         => ['on', 'off'],
    ];

    private string $dir;
    private Cache $cache;

    public function __construct(private CloudflareClient $cf, private string $accountId, string $stateDir)
    {
        $this->dir   = rtrim(normalizePath($stateDir), '/');
        $this->cache = new Cache($stateDir);
    }

    public function cache(): Cache { return $this->cache; }

    // ---- Zones ---------------------------------------------------------------

    public function zones(): array
    {
        return $this->cached('zones_full', 300, function () {
            $out = [];
            foreach ($this->paged('/zones') as $z) {
                $out[] = [
                    'id'     => $z['id'],
                    'name'   => $z['name'],
                    'status' => $z['status'] ?? '',
                    'paused' => (bool)($z['paused'] ?? false),
                    'plan'   => $z['plan']['name'] ?? '',
                ];
            }
            return $out;
        });
    }

    /** Current values of the whitelisted settings: [name => value]. */
    public function zoneSettings(string $zoneId): array
    {
        $res = $this->cf->request('GET', "/zones/{$zoneId}/settings");
        $out = [];
        foreach ($res['result'] ?? [] as $s) {
            if (isset(self::SETTINGS[$s['id']])) $out[$s['id']] = $s['value'];
        }
        return $out;
    }

    public function securityLevel(string $zoneId): string
    {
        return (string)($this->cf->request('GET', "/zones/{$zoneId}/settings/security_level")['result']['value'] ?? 'medium');
    }

    public function setZoneSetting(string $zoneId, string $name, string $value): void
    {
        if (!isset(self::SETTINGS[$name]) || !in_array($value, self::SETTINGS[$name], true)) {
            throw new InvalidArgumentException("Setting not allowed: $name=$value");
        }
        $this->cf->request('PATCH', "/zones/{$zoneId}/settings/{$name}", ['value' => $value]);
        $this->cache->forget("overview_$zoneId");
    }

    /** Purge everything, or only the given hostnames. */
    public function purgeCache(string $zoneId, array $hosts = []): void
    {
        $this->cf->request('POST', "/zones/{$zoneId}/purge_cache", $hosts ? ['hosts' => $hosts] : ['purge_everything' => true]);
    }

    // ---- Analytics -----------------------------------------------------------

    /** Cloudflare's GraphQL API rejects more than this many zones in one query ("too many zones requested"). */
    private const GRAPHQL_MAX_ZONES = 10;

    /** Totals over the last $days days for many zones: [zoneId => {requests, bytes, cached, threats}]. Chunks run in parallel. */
    public function zoneTotals(array $zoneIds, int $days = 7): array
    {
        if (!$zoneIds) return [];
        return $this->cached('totals_' . md5(implode(',', $zoneIds)) . "_$days", 120, function () use ($zoneIds, $days) {
            $specs = [];
            foreach (array_chunk(array_values($zoneIds), self::GRAPHQL_MAX_ZONES) as $i => $chunk) {
                $specs["c$i"] = ['POST', '/graphql', ['query' => '
                  query($tags:[string!],$since:Date!){viewer{zones(filter:{zoneTag_in:$tags}){zoneTag
                    httpRequests1dGroups(limit:15,filter:{date_geq:$since}){sum{requests bytes cachedRequests threats}}}}}',
                  'variables' => ['tags' => $chunk, 'since' => date('Y-m-d', time() - $days * 86400)]]];
            }
            $out = [];
            foreach ($this->cf->requestMany($specs) as $res) {
                if (!$res['ok']) throw new RuntimeException($res['error']);
                foreach ($res['data']['viewer']['zones'] ?? [] as $z) {
                    $t = ['requests' => 0, 'bytes' => 0, 'cached' => 0, 'threats' => 0];
                    foreach ($z['httpRequests1dGroups'] as $g) {
                        $t['requests'] += $g['sum']['requests']; $t['bytes'] += $g['sum']['bytes'];
                        $t['cached']   += $g['sum']['cachedRequests']; $t['threats'] += $g['sum']['threats'];
                    }
                    $out[$z['zoneTag']] = $t;
                }
            }
            return $out;
        });
    }

    private function detailSpec(string $zoneId, int $days): array
    {
        return ['POST', '/graphql', ['query' => '
          query($tag:string!,$since:Date!){viewer{zones(filter:{zoneTag:$tag}){
            httpRequests1dGroups(limit:15,orderBy:[date_ASC],filter:{date_geq:$since}){
              dimensions{date} uniq{uniques}
              sum{requests bytes cachedRequests threats responseStatusMap{edgeResponseStatus requests} countryMap{clientCountryName requests}}}}}}',
          'variables' => ['tag' => $zoneId, 'since' => date('Y-m-d', time() - $days * 86400)]]];
    }

    private function parseDetail(array $d): array
    {
        $series = []; $status = []; $country = [];
        $tot = ['requests' => 0, 'bytes' => 0, 'cached' => 0, 'threats' => 0, 'uniques' => 0];
        foreach ($d['viewer']['zones'][0]['httpRequests1dGroups'] ?? [] as $g) {
            $s = $g['sum'];
            $series[] = ['date' => $g['dimensions']['date'], 'requests' => $s['requests'], 'bytes' => $s['bytes']];
            $tot['requests'] += $s['requests']; $tot['bytes'] += $s['bytes']; $tot['cached'] += $s['cachedRequests'];
            $tot['threats'] += $s['threats'];   $tot['uniques'] += $g['uniq']['uniques'];
            foreach ($s['responseStatusMap'] as $m) { $k = (string)intdiv((int)$m['edgeResponseStatus'], 100) . 'xx'; $status[$k] = ($status[$k] ?? 0) + $m['requests']; }
            foreach ($s['countryMap'] as $m) $country[$m['clientCountryName']] = ($country[$m['clientCountryName']] ?? 0) + $m['requests'];
        }
        arsort($country); ksort($status);
        return ['totals' => $tot, 'series' => $series, 'status' => $status, 'countries' => array_slice($country, 0, 8, true)];
    }

    /** One zone: daily series + status-code and country breakdowns for the last $days days. */
    public function zoneDetail(string $zoneId, int $days = 7): array
    {
        return $this->cached("detail_{$zoneId}_$days", 120, fn() => $this->parseDetail($this->cf->request(...$this->detailSpec($zoneId, $days))));
    }

    private function hostSpec(string $zoneId, array $hosts): array
    {
        return ['POST', '/graphql', ['query' => '
          query($tag:string!,$from:Time!,$to:Time!,$hosts:[string!]){viewer{zones(filter:{zoneTag:$tag}){
            httpRequestsAdaptiveGroups(limit:100,filter:{datetime_geq:$from,datetime_leq:$to,clientRequestHTTPHost_in:$hosts}){
              count dimensions{clientRequestHTTPHost} sum{edgeResponseBytes}}}}}',
          'variables' => ['tag' => $zoneId, 'from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400), 'to' => gmdate('Y-m-d\TH:i:s\Z'), 'hosts' => array_values($hosts)]]];
    }

    private function parseHostStats(array $d): array
    {
        $out = [];
        foreach ($d['viewer']['zones'][0]['httpRequestsAdaptiveGroups'] ?? [] as $g) {
            $h = $g['dimensions']['clientRequestHTTPHost'];
            $out[$h]['requests'] = ($out[$h]['requests'] ?? 0) + $g['count'];
            $out[$h]['bytes']    = ($out[$h]['bytes'] ?? 0) + $g['sum']['edgeResponseBytes'];
        }
        return $out;
    }

    /** Last-24h requests/bytes for specific hostnames in one zone: [host => {requests, bytes}]. */
    public function hostStats(string $zoneId, array $hosts): array
    {
        if (!$hosts) return [];
        return $this->cached('hosts_' . md5($zoneId . implode(',', $hosts)), 120, fn() => $this->parseHostStats($this->cf->request(...$this->hostSpec($zoneId, $hosts))));
    }

    /**
     * The domain page's analytics in ONE round trip: zone breakdown + per-hostname traffic fetched in parallel.
     * @return array{analytics: ?array, hosts: array, errors: array}  a failing half is reported, the other still shown
     */
    public function domainAnalytics(string $zoneId, array $hosts, int $days = 7): array
    {
        $kd = "detail_{$zoneId}_$days"; $kh = 'hosts_' . md5($zoneId . implode(',', $hosts));
        $analytics = $this->cache->get($kd, 120); $hostStats = $hosts ? $this->cache->get($kh, 120) : [];
        $specs = [];
        if ($analytics === null) $specs['detail'] = $this->detailSpec($zoneId, $days);
        if ($hostStats === null) $specs['hosts'] = $this->hostSpec($zoneId, $hosts);
        $errors = [];
        foreach ($specs ? $this->cf->requestMany($specs) : [] as $k => $r) {
            if (!$r['ok']) { $errors[$k === 'detail' ? 'analytics' : 'hosts'] = $r['error']; continue; }
            if ($k === 'detail') { $analytics = $this->parseDetail($r['data']); $this->cache->put($kd, $analytics); }
            else { $hostStats = $this->parseHostStats($r['data']); $this->cache->put($kh, $hostStats); }
        }
        return ['analytics' => $analytics ?? $this->cache->peek($kd), 'hosts' => $hostStats ?? $this->cache->peek($kh) ?? [], 'errors' => $errors];
    }

    /**
     * Last-24h traffic for routes spread over several zones, all zones in parallel: [zoneId => [host => {requests, bytes}]].
     * @return array{stats: array, errors: array}
     */
    public function hostStatsMany(array $byZone): array
    {
        $stats = []; $specs = []; $errors = [];
        foreach ($byZone as $zid => $hosts) {
            $key = 'hosts_' . md5($zid . implode(',', $hosts));
            $hit = $this->cache->get($key, 120);
            if ($hit !== null) $stats[$zid] = $hit; else $specs[$zid] = $this->hostSpec($zid, $hosts);
        }
        foreach ($specs ? $this->cf->requestMany($specs) : [] as $zid => $r) {
            if (!$r['ok']) { $errors[$zid] = $r['error']; continue; }
            $stats[$zid] = $this->parseHostStats($r['data']);
            $this->cache->put('hosts_' . md5($zid . implode(',', $byZone[$zid])), $stats[$zid]);
        }
        return ['stats' => $stats, 'errors' => $errors];
    }

    /**
     * One hostname, last 24 h: hourly series, response-code classes and top countries — three aliased queries in a single
     * GraphQL request, cached 2 minutes.
     * @return array{totals: array, series: array, status: array, countries: array}
     */
    public function routeAnalytics(string $zoneId, string $host): array
    {
        return $this->cached('route_' . md5($zoneId . $host), 120, function () use ($zoneId, $host) {
            $f = 'filter:{datetime_geq:$from,datetime_leq:$to,clientRequestHTTPHost:$host}';
            $d = $this->cf->request('POST', '/graphql', ['query' => '
              query($tag:string!,$from:Time!,$to:Time!,$host:string!){viewer{zones(filter:{zoneTag:$tag}){
                series:httpRequestsAdaptiveGroups(limit:100,orderBy:[datetimeHour_ASC],' . $f . '){count dimensions{datetimeHour} sum{edgeResponseBytes}}
                status:httpRequestsAdaptiveGroups(limit:100,' . $f . '){count dimensions{edgeResponseStatus}}
                country:httpRequestsAdaptiveGroups(limit:100,orderBy:[count_DESC],' . $f . '){count dimensions{clientCountryName}}}}}',
              'variables' => ['tag' => $zoneId, 'from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400), 'to' => gmdate('Y-m-d\TH:i:s\Z'), 'host' => $host]]);
            $z = $d['viewer']['zones'][0] ?? [];
            $series = []; $tot = ['requests' => 0, 'bytes' => 0];
            foreach ($z['series'] ?? [] as $g) {
                $series[] = ['t' => $g['dimensions']['datetimeHour'], 'requests' => $g['count'], 'bytes' => $g['sum']['edgeResponseBytes']];
                $tot['requests'] += $g['count']; $tot['bytes'] += $g['sum']['edgeResponseBytes'];
            }
            $status = [];
            foreach ($z['status'] ?? [] as $g) { $k = intdiv((int)$g['dimensions']['edgeResponseStatus'], 100) . 'xx'; $status[$k] = ($status[$k] ?? 0) + $g['count']; }
            ksort($status);
            $countries = [];
            foreach ($z['country'] ?? [] as $g) $countries[$g['dimensions']['clientCountryName']] = ($countries[$g['dimensions']['clientCountryName']] ?? 0) + $g['count'];
            arsort($countries);
            return ['totals' => $tot, 'series' => $series, 'status' => $status, 'countries' => array_slice($countries, 0, 8, true)];
        });
    }

    /** The tunnel DNS record for one hostname (type, target, proxied), or null. Cached 20 s with the other DNS data. */
    public function dnsRecord(string $zoneId, string $host): ?array
    {
        $recs = $this->cached('dnsrec_' . md5($zoneId . $host), 20, fn() => $this->cf->request('GET', "/zones/$zoneId/dns_records", [], ['name' => $host, 'per_page' => 5])['result'] ?? []);
        foreach ($recs as $r) if (in_array($r['type'], ['CNAME', 'A', 'AAAA'], true)) return ['id' => $r['id'], 'type' => $r['type'], 'content' => $r['content'], 'proxied' => (bool)($r['proxied'] ?? false), 'ttl' => $r['ttl'] ?? 1,
            'tunnel' => str_ends_with((string)$r['content'], '.cfargotunnel.com')];
        return null;
    }

    // ---- DNS -----------------------------------------------------------------

    /** Records in the zone that point at a *.cfargotunnel.com target. */
    public function tunnelDns(string $zoneId): array
    {
        return $this->parseTunnelDns($this->cf->request('GET', "/zones/$zoneId/dns_records", [], ['type' => 'CNAME', 'per_page' => 100])['result'] ?? []);
    }

    private function parseTunnelDns(array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            if (str_ends_with((string)($r['content'] ?? ''), '.cfargotunnel.com')) $out[] = ['name' => $r['name'], 'tunnel_id' => substr($r['content'], 0, -17), 'proxied' => $r['proxied'] ?? false];
        }
        return $out;
    }

    /**
     * The domain page's settings + tunnel DNS records, fetched together (45 s cache, dropped when a setting changes).
     * @return array{settings: ?array, dns: ?array, errors: array}
     */
    public function domainOverview(string $zoneId): array
    {
        $hit = $this->cache->get("overview_$zoneId", 45);
        if ($hit !== null) return $hit;
        $r = $this->cf->requestMany(['settings' => ['GET', "/zones/$zoneId/settings"], 'dns' => ['GET', "/zones/$zoneId/dns_records", [], ['type' => 'CNAME', 'per_page' => 100]]]);
        $out = ['settings' => null, 'dns' => null, 'errors' => []];
        if ($r['settings']['ok']) { $out['settings'] = []; foreach ($r['settings']['data']['result'] ?? [] as $x) if (isset(self::SETTINGS[$x['id']])) $out['settings'][$x['id']] = $x['value']; }
        else $out['errors']['settings'] = $r['settings']['error'];
        if ($r['dns']['ok']) $out['dns'] = $this->parseTunnelDns($r['dns']['data']['result'] ?? []);
        else $out['errors']['dns'] = $r['dns']['error'];
        if (!$out['errors']) { $this->cache->put("overview_$zoneId", $out); return $out; }
        $stale = $this->cache->peek("overview_$zoneId") ?: [];            // a failed half falls back to the last known value
        foreach (['settings', 'dns'] as $k) if ($out[$k] === null && isset($stale[$k])) { $out[$k] = $stale[$k]; $out['stale'][$k] = true; }
        return $out;
    }

    // ---- Tunnels -------------------------------------------------------------

    public function tunnels(): array
    {
        return $this->cached('tunnels_list', 30, fn() => $this->cf->request('GET', "/accounts/{$this->accountId}/cfd_tunnel", [], ['is_deleted' => 'false', 'per_page' => 100])['result'] ?? []);
    }

    public function tunnel(string $id): array
    {
        return $this->cf->request('GET', "/accounts/{$this->accountId}/cfd_tunnel/$id")['result'] ?? [];
    }

    /** Connectors (cloudflared instances), each with its edge connections. */
    public function tunnelConnectors(string $id): array
    {
        return $this->cf->request('GET', "/accounts/{$this->accountId}/cfd_tunnel/$id/connections")['result'] ?? [];
    }

    /** Remote ingress rules, or null for locally-managed tunnels. */
    public function tunnelIngress(string $id): ?array
    {
        $res = $this->cf->request('GET', "/accounts/{$this->accountId}/cfd_tunnel/$id/configurations");
        return $res['result']['config']['ingress'] ?? null;
    }

    /**
     * Tunnel details + connections + ingress in ONE round trip (three calls in parallel, 30 s cache).
     * @return array{tunnel: array, connectors: array, ingress: ?array, errors: array}  only the tunnel itself is required
     */
    public function tunnelOverview(string $id): array
    {
        $hit = $this->cache->get("tunnel_$id", 30);
        if ($hit !== null) return $hit;
        $base = "/accounts/{$this->accountId}/cfd_tunnel/$id";
        $r = $this->cf->requestMany(['t' => ['GET', $base], 'c' => ['GET', "$base/connections"], 'i' => ['GET', "$base/configurations"]]);
        if (!$r['t']['ok']) { $stale = $this->cache->peek("tunnel_$id"); if ($stale) return $stale + ['stale' => true]; throw new RuntimeException($r['t']['error']); }
        $out = ['tunnel' => $r['t']['data']['result'] ?? [], 'connectors' => $r['c']['ok'] ? ($r['c']['data']['result'] ?? []) : [],
                'ingress' => $r['i']['ok'] ? ($r['i']['data']['result']['config']['ingress'] ?? null) : null, 'errors' => []];
        if (!$r['c']['ok']) $out['errors']['connectors'] = $r['c']['error'];
        if (!$r['i']['ok']) $out['errors']['ingress'] = $r['i']['error'];
        if (!$out['errors']) $this->cache->put("tunnel_$id", $out);
        return $out;
    }

    public function renameTunnel(string $id, string $name): void
    {
        $this->cf->request('PATCH', "/accounts/{$this->accountId}/cfd_tunnel/$id", ['name' => $name]);
        $this->cache->forget('tunnel');
    }

    public function cleanupConnections(string $id): void
    {
        $this->cf->request('DELETE', "/accounts/{$this->accountId}/cfd_tunnel/$id/connections");
        $this->cache->forget('tunnel');
    }

    // ---- Health probes -------------------------------------------------------

    /**
     * HEAD each hostname through the public edge (so it tests Cloudflare -> tunnel -> Apache),
     * record the sample, and return [host => {code, ms, ok, state, uptime, samples, since}].
     * Uptime is computed from samples taken while this tool is in use; there is no background monitor.
     */
    public function probe(array $hosts): array
    {
        $mh = curl_multi_init(); $handles = [];
        foreach ($hosts as $h) {
            $ch = curl_init("https://$h/");
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
            if ($ca = caBundle()) curl_setopt($ch, CURLOPT_CAINFO, $ca);       // XAMPP on Windows ships no default CA bundle in php.ini
            curl_multi_add_handle($mh, $ch);
            $handles[$h] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 1.0) === -1) usleep(20000);   // select() can return -1 at once on Windows: don't spin
        } while ($running);

        $file = $this->dir . '/health.json';
        $results = []; $now = time();
        foreach ($handles as $h => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $results[$h] = [$code, $ms, $code > 0 && $code < 500];
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        $out = [];
        $update = function () use ($file, $results, $now, &$out) {                // short critical section: read, append, write
            $hist = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
            foreach ($results as $h => [$code, $ms, $ok]) {
                $samples = $hist[$h] ?? [];
                if (!$samples || $now - end($samples)[0] >= 30) $samples[] = [$now, $ok ? 1 : 0, $ms];
                $samples = array_slice($samples, -2500);
                $hist[$h] = $samples;
                $up = array_sum(array_column($samples, 1));
                $out[$h] = [
                    'code' => $code, 'ms' => $ms, 'ok' => $ok,
                    'state' => $ok ? 'up' : ($code === 530 || $code === 502 ? 'tunnel_down' : ($code ? 'error' : 'unreachable')),
                    'uptime' => round($up / count($samples) * 100, 1), 'samples' => count($samples), 'since' => $samples[0][0],
                ];
            }
            if ($results) atomicWrite($file, json_encode($hist));
        };
        withLock($file, $update);
        curl_multi_close($mh);
        return $out;
    }

    // ---- internals -----------------------------------------------------------

    private function paged(string $path): array
    {
        $all = []; $page = 1;
        do {
            $res  = $this->cf->request('GET', $path, [], ['page' => $page, 'per_page' => 50]);
            $all  = array_merge($all, $res['result'] ?? []);
            $info = $res['result_info'] ?? [];
            $page++;
        } while (isset($info['page'], $info['total_pages']) && $info['page'] < $info['total_pages']);
        return $all;
    }

    private function cached(string $key, int $ttl, callable $fn): mixed
    {
        return $this->cache->remember($key, $ttl, $fn);
    }
}
