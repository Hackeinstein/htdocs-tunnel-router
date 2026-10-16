<?php
declare(strict_types=1);

class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';
    private const CACHE_TTL = 300; // 5 minutes

    private string $token;
    private string $accountId;
    private string $tunnelId;
    private string $cacheFile;

    public function __construct(string $apiToken, string $accountId, string $tunnelId, string $stateDir)
    {
        $this->token     = $apiToken;
        $this->accountId = $accountId;
        $this->tunnelId  = $tunnelId;
        $this->cacheFile = rtrim(normalizePath($stateDir), '/') . '/zones_cache.json';
    }

    /** List all zones accessible by the token. Cached for 5 min. */
    public function listZones(): array
    {
        if ($cached = $this->readCache()) return $cached;

        $data   = [];
        $page   = 1;
        do {
            $res  = $this->request('GET', '/zones', [], ['page' => $page, 'per_page' => 50]);
            $data = array_merge($data, $res['result'] ?? []);
            $info = $res['result_info'] ?? [];
            $page++;
        } while (isset($info['page'], $info['total_pages']) && $info['page'] < $info['total_pages']);

        $zones = array_map(fn($z) => ['id' => $z['id'], 'name' => $z['name']], $data);
        $this->writeCache($zones);
        return $zones;
    }

    /** Resolve a zone name to its zone object, or null. */
    public function getZone(string $zoneName): ?array
    {
        $res = $this->request('GET', '/zones', [], ['name' => $zoneName]);
        return $res['result'][0] ?? null;
    }

    /** List DNS records for a zone, optionally filtered by name. */
    public function listDnsRecords(string $zoneId, ?string $name = null): array
    {
        $params = ['per_page' => 100];
        if ($name !== null) $params['name'] = $name;
        $res = $this->request('GET', "/zones/{$zoneId}/dns_records", [], $params);
        return $res['result'] ?? [];
    }

    /** Find a single DNS record by hostname (exact name match). */
    public function getDnsRecord(string $zoneId, string $hostname): ?array
    {
        $records = $this->listDnsRecords($zoneId, $hostname);
        return $records[0] ?? null;
    }

    /**
     * Create a proxied CNAME pointing to the tunnel.
     * Returns the created record array.
     */
    public function createCname(string $zoneId, string $hostname): array
    {
        $res = $this->request('POST', "/zones/{$zoneId}/dns_records", [
            'type'    => 'CNAME',
            'name'    => $hostname,
            'content' => $this->tunnelId . '.cfargotunnel.com',
            'proxied' => true,
            'ttl'     => 1,
        ]);
        if (empty($res['result']['id'])) {
            throw new RuntimeException('CF createCname: no record id in response — ' . $this->cfErrors($res));
        }
        return $res['result'];
    }

    /** Update an existing CNAME record. */
    public function updateCname(string $zoneId, string $recordId, string $hostname): array
    {
        $res = $this->request('PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", [
            'type'    => 'CNAME',
            'name'    => $hostname,
            'content' => $this->tunnelId . '.cfargotunnel.com',
            'proxied' => true,
            'ttl'     => 1,
        ]);
        if (empty($res['result']['id'])) {
            throw new RuntimeException('CF updateCname: ' . $this->cfErrors($res));
        }
        return $res['result'];
    }

    /** Delete a DNS record by ID. Returns true on success. */
    public function deleteDnsRecord(string $zoneId, string $recordId): bool
    {
        $res = $this->request('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
        return !empty($res['result']['id']) || ($res['success'] ?? false);
    }

    /**
     * Check if an apex (zone root) A or CNAME record already exists.
     * Returns the existing record, or null.
     */
    public function checkApexConflict(string $zoneId, string $zoneName): ?array
    {
        $records = $this->listDnsRecords($zoneId, $zoneName);
        foreach ($records as $r) {
            if (in_array($r['type'], ['A', 'CNAME'], true)) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Find and delete a DNS record by hostname across all accessible zones.
     * Returns true if a record was found and deleted.
     */
    public function findAndDeleteDnsByName(string $hostname): bool
    {
        $zones = $this->listZones();
        foreach ($zones as $zone) {
            try {
                $rec = $this->getDnsRecord($zone['id'], $hostname);
                if ($rec) {
                    return $this->deleteDnsRecord($zone['id'], $rec['id']);
                }
            } catch (Throwable) {
                continue;
            }
        }
        return false;
    }

    /** Clear the zones cache (call when token changes). */
    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) @unlink($this->cacheFile);
    }

    // -------------------------------------------------------------------------

    /** One reusable handle per client: later calls in the same request skip DNS + TCP + TLS (that was ~0.5 s each). */
    private ?\CurlHandle $ch = null;
    private ?\CurlMultiHandle $mh = null;

    /** @return array<int,mixed> curl options shared by single and parallel requests */
    private function opts(string $method, string $path, array $body, array $query): array
    {
        $url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $o = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'Accept-Encoding: gzip'],
            CURLOPT_ENCODING       => '',                  // accept gzip: smaller analytics/DNS payloads
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,   // avoids the multi-second IPv6-then-IPv4 fallback stall common on Windows
        ];
        if ($ca = caBundle()) $o[CURLOPT_CAINFO] = $ca;
        // (HTTP version is left to libcurl: it negotiates HTTP/2 on its own, and forcing it broke POST bodies on some builds.)
        if ($method === 'GET') { $o[CURLOPT_HTTPGET] = true; }
        elseif ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
        else { $o[CURLOPT_CUSTOMREQUEST] = $method; if ($method === 'PATCH' || $method === 'PUT') $o[CURLOPT_POSTFIELDS] = json_encode($body); }
        return $o;
    }

    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        if ($this->ch === null) $this->ch = curl_init(); else curl_reset($this->ch);   // curl_reset keeps the live connection
        curl_setopt_array($this->ch, $this->opts($method, $path, $body, $query));
        $raw  = curl_exec($this->ch);
        return $this->decode($raw, (int)curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), curl_error($this->ch), $path);
    }

    /**
     * Several independent calls at once (over one HTTP/2 connection when available): total time is the slowest call,
     * not the sum. $specs = [key => [method, path, body?, query?]].
     * @return array<string, array{ok: bool, data: ?array, error: ?string}> never throws — each panel handles its own failure
     */
    public function requestMany(array $specs): array
    {
        if ($this->mh === null) {
            $this->mh = curl_multi_init();
            if (defined('CURLMOPT_PIPELINING') && defined('CURLPIPE_MULTIPLEX')) @curl_multi_setopt($this->mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
        $handles = [];
        foreach ($specs as $key => $sp) {
            $ch = curl_init();
            curl_setopt_array($ch, $this->opts($sp[0], $sp[1], $sp[2] ?? [], $sp[3] ?? []));
            curl_multi_add_handle($this->mh, $ch);
            $handles[$key] = [$ch, $sp[1]];
        }
        do {
            curl_multi_exec($this->mh, $running);
            if ($running && curl_multi_select($this->mh, 1.0) === -1) usleep(10000);
        } while ($running);

        $out = [];
        foreach ($handles as $key => [$ch, $path]) {
            try { $out[$key] = ['ok' => true, 'data' => $this->decode(curl_multi_getcontent($ch), (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_error($ch), $path), 'error' => null]; }
            catch (Throwable $e) { $out[$key] = ['ok' => false, 'data' => null, 'error' => $e->getMessage()]; }
            curl_multi_remove_handle($this->mh, $ch); curl_close($ch);
        }
        return $out;
    }

    /** Turn a raw Cloudflare response into its decoded body, or throw a readable error. */
    private function decode(string|bool|null $raw, int $code, string $err, string $path): array
    {
        if ($raw === false || $raw === null || ($raw === '' && $err !== '')) throw new RuntimeException("CF API curl error: $err");
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new RuntimeException("CF API non-JSON response (HTTP $code): " . substr((string)$raw, 0, 200));
        if ($path === '/graphql') {
            if (!empty($decoded['errors'])) throw new RuntimeException('CF GraphQL: ' . implode('; ', array_column($decoded['errors'], 'message')));
            return $decoded['data'] ?? [];
        }
        if (!($decoded['success'] ?? false)) throw new RuntimeException('CF API error (HTTP ' . $code . '): ' . $this->cfErrors($decoded));
        return $decoded;
    }

    private function cfErrors(array $res): string
    {
        $errs = $res['errors'] ?? [];
        if (!$errs) return 'unknown error';
        return implode('; ', array_map(fn($e) => "[{$e['code']}] {$e['message']}", $errs));
    }

    private function readCache(): ?array
    {
        if (!file_exists($this->cacheFile)) return null;
        if (filemtime($this->cacheFile) < time() - self::CACHE_TTL) return null;
        $raw = json_decode(file_get_contents($this->cacheFile), true);
        return is_array($raw) ? $raw : null;
    }

    private function writeCache(array $zones): void
    {
        atomicWrite($this->cacheFile, jsonPretty($zones));
    }
}
