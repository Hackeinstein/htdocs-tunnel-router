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

    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $url = self::BASE . $path;
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        // Use XAMPP's bundled CA bundle on Windows; fall back to common macOS/Linux paths
        $caCandidates = [
            'C:/xampp/php/extras/ssl/cacert.pem',
            '/Applications/XAMPP/xamppfiles/share/curl/cacert.pem',
            '/etc/ssl/cert.pem',
            '/usr/local/etc/openssl/cert.pem',
        ];
        foreach ($caCandidates as $caBundle) {
            if (file_exists($caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
                break;
            }
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("CF API curl error: $err");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("CF API non-JSON response (HTTP $code): " . substr($raw, 0, 200));
        }
        if (!($decoded['success'] ?? false)) {
            throw new RuntimeException('CF API error (HTTP ' . $code . '): ' . $this->cfErrors($decoded));
        }

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
