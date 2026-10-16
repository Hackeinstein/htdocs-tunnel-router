<?php
declare(strict_types=1);

class Reconciler
{
    private CloudflareClient $cf;
    private VhostManager     $vm;
    private State            $state;

    public function __construct(CloudflareClient $cf, VhostManager $vm, State $state)
    {
        $this->cf    = $cf;
        $this->vm    = $vm;
        $this->state = $state;
    }

    /**
     * Diff routes.json against live vhosts file and live CF DNS.
     *
     * Status values:
     *   ok           — vhost + DNS both present
     *   dns_missing  — vhost present, no DNS record
     *   vhost_missing— DNS record present, no vhost block
     *   both_missing — neither found (stale routes.json entry)
     *   drifted      — vhost block exists but not in routes.json (hand-edited)
     *   dns_only     — CF DNS record points to our tunnel but not in routes.json
     *
     * Returns array of result objects.
     */
    public function reconcile(): array
    {
        $routes    = $this->state->loadRoutes();
        $vhostMap  = $this->vm->parseBlocks();          // hostname => blockText
        $dnsMap    = $this->buildDnsMap($routes);       // hostname => record

        $results   = [];
        $seen      = [];

        foreach ($routes as $r) {
            $h = $r['hostname'];
            $seen[$h] = true;
            $hasVhost = array_key_exists($h, $vhostMap);
            $hasDns   = array_key_exists($h, $dnsMap);

            $status = match (true) {
                $hasVhost && $hasDns   => 'ok',
                $hasVhost && !$hasDns  => 'dns_missing',
                !$hasVhost && $hasDns  => 'vhost_missing',
                default                => 'both_missing',
            };

            $results[] = [
                'hostname'     => $h,
                'status'       => $status,
                'route_entry'  => $r,
                'has_vhost'    => $hasVhost,
                'has_dns'      => $hasDns,
                'dns_record_id'=> $dnsMap[$h]['id'] ?? null,
                'issues'       => $status !== 'ok' ? [$status] : [],
            ];
        }

        // Fenced vhosts not in routes.json → drifted
        $portal = Config::portalHost();
        if ($portal !== '') $seen[$portal] = true;                 // the file portal's own address is neither drift nor an orphan
        foreach ($vhostMap as $h => $block) {
            if (!isset($seen[$h])) {
                $results[] = [
                    'hostname'     => $h,
                    'status'       => 'drifted',
                    'route_entry'  => null,
                    'has_vhost'    => true,
                    'has_dns'      => array_key_exists($h, $dnsMap),
                    'dns_record_id'=> $dnsMap[$h]['id'] ?? null,
                    'issues'       => ['drifted'],
                ];
                $seen[$h] = true;
            }
        }

        // CF DNS records pointing to our tunnel, not in routes.json
        foreach ($dnsMap as $h => $rec) {
            if (!isset($seen[$h])) {
                $results[] = [
                    'hostname'     => $h,
                    'status'       => 'dns_only',
                    'route_entry'  => null,
                    'has_vhost'    => false,
                    'has_dns'      => true,
                    'dns_record_id'=> $rec['id'],
                    'issues'       => ['dns_only'],
                ];
            }
        }

        return $results;
    }

    /**
     * hostname → CF DNS record for the zones referenced in routes.json, only records aimed at a tunnel.
     * All zones are fetched in parallel (it used to be one after another) and cached for 20 s; anything that changes
     * a route drops the cache (see RouteService::dnsChanged()).
     */
    private function buildDnsMap(array $routes): array
    {
        $cache = new Cache(Config::stateDir());
        $map = []; $specs = [];
        foreach (array_unique(array_filter(array_column($routes, 'zone_id'))) as $zoneId) {
            $hit = $cache->get("dnsmap_$zoneId", 20);
            if ($hit !== null) { $map += $hit; continue; }
            $specs[$zoneId] = ['GET', "/zones/$zoneId/dns_records", [], ['type' => 'CNAME', 'per_page' => 100]];
        }
        foreach ($specs ? $this->cf->requestMany($specs) : [] as $zoneId => $r) {
            if (!$r['ok']) continue;                      // CF API down for this zone — degrade gracefully, no DNS info for it
            $zone = [];
            foreach ($r['data']['result'] ?? [] as $rec) if (str_contains($rec['content'] ?? '', 'cfargotunnel.com')) $zone[$rec['name']] = $rec;
            $cache->put("dnsmap_$zoneId", $zone);
            $map += $zone;
        }
        return $map;
    }
}
