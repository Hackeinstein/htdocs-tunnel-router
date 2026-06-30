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
     * Build a map of hostname → CF DNS record for all zones referenced in routes.json.
     * Only includes records whose content matches our tunnel ID.
     */
    private function buildDnsMap(array $routes): array
    {
        $zoneIds  = array_unique(array_column($routes, 'zone_id'));
        $dnsMap   = [];
        foreach ($zoneIds as $zoneId) {
            if (!$zoneId) continue;
            try {
                $records = $this->cf->listDnsRecords($zoneId);
                foreach ($records as $rec) {
                    if ($rec['type'] !== 'CNAME') continue;
                    // Only track records pointing to our tunnel
                    if (!str_contains($rec['content'] ?? '', 'cfargotunnel.com')) continue;
                    $dnsMap[$rec['name']] = $rec;
                }
            } catch (Throwable $e) {
                // CF API down — degrade gracefully, flag no DNS info for this zone
            }
        }
        return $dnsMap;
    }
}
