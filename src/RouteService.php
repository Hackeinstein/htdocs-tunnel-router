<?php
declare(strict_types=1);

/**
 * Route lifecycle used by the web API: attach, detach, enable/disable, expiry.
 * Every method either completes or rolls back; failures throw RouteException.
 */
class RouteService
{
    public const MIN_EXPIRY = 300;            // 5 minutes from now
    public const MAX_EXPIRY = 370 * 86400;    // ~12 months, plus a little slack for "12 months" presets

    private bool $deferReload = false;
    private bool $reloadPending = false;

    public function __construct(
        private State $state,
        private ActivityLog $log,
        private VhostManager $vm,
        private ApacheManager $ap,
        private ?CloudflareClient $cf,
    ) {}

    // ---- Attach --------------------------------------------------------------

    /** @return array{hostname:string, dns_record_id:string} */
    public function attach(string $project, string $zoneId, string $subdomain, bool $www, string $label, bool $forceApex, ?int $expiresAt, bool $portal = false): array
    {
        $cf = $this->needCf();
        if (!sanitizeProjectName($project)) throw new RouteException('Invalid project name');
        if ($zoneId === '') throw new RouteException('zone_id required');

        $docRoot = rtrim(Config::load()['paths']['htdocs'], '/\\') . '/' . $project;
        if (!is_dir($docRoot)) throw new RouteException("Project folder not found: $docRoot");

        [$hostname, $zoneName] = resolveHostname($cf, $zoneId, $subdomain);
        if (!sanitizeHostname($hostname)) throw new RouteException("Invalid hostname: $hostname");

        if ($subdomain === '') {
            $conflict = $cf->checkApexConflict($zoneId, $zoneName);
            if ($conflict && !$forceApex) {
                throw new RouteException("Apex record exists ({$conflict['type']} → {$conflict['content']}). Confirm to replace it.", 409, ['apex_conflict' => $conflict]);
            }
        }

        // Idempotent DNS: update if present, create if not
        $existing  = $cf->getDnsRecord($zoneId, $hostname);
        $dnsRecord = $existing ? $cf->updateCname($zoneId, $existing['id'], $hostname) : $cf->createCname($zoneId, $hostname);

        $vhostWritten = false;
        try {
            if (!$this->vm->hasBlock($hostname)) {
                $this->vm->addBlock($hostname, $docRoot, $www);
                $vhostWritten = true;
            }
        } catch (Throwable $e) {                                  // e.g. vhosts file not writable: undo the DNS we just made
            if (!$existing) { try { $cf->deleteDnsRecord($zoneId, $dnsRecord['id']); } catch (Throwable) {} }
            $this->log->append('attach', $hostname, false, 'vhost write failed: ' . $e->getMessage());
            throw new RouteException('Could not write the vhosts file — DNS change rolled back. ' . $e->getMessage(), 500, ['step' => 'vhost_write']);
        }

        $ap = $this->validateAndReload();
        if (!$ap['ok']) {
            if (!$existing) { try { $cf->deleteDnsRecord($zoneId, $dnsRecord['id']); } catch (Throwable) {} }
            $this->log->append('attach', $hostname, false, 'Apache validate failed: ' . ($ap['output'] ?? ''));
            throw new RouteException($ap['error'] ?? 'Apache config validation failed — changes rolled back.', 500,
                ['step' => 'apache_validate', 'output' => $ap['output'] ?? '']);
        }

        $this->state->upsertRoute([
            'hostname' => $hostname, 'type' => $subdomain === '' ? 'apex' : 'subdomain', 'zone_id' => $zoneId,
            'project' => $project, 'document_root' => $docRoot, 'www_alias' => $www,
            'dns_record_id' => $dnsRecord['id'], 'enabled' => true, 'label' => $label ?: $project,
            'expires_at' => $expiresAt, 'portal_enabled' => $portal,
        ]);
        self::dnsChanged();
        $this->log->append('attach', $hostname, true, "DNS {$dnsRecord['id']} created/updated; vhost " . ($vhostWritten ? 'written' : 'already present')
            . '; Apache reloaded' . ($expiresAt ? '; expires ' . gmdate('Y-m-d H:i', $expiresAt) . ' UTC' : ''));
        return ['hostname' => $hostname, 'dns_record_id' => $dnsRecord['id']];
    }

    // ---- Detach --------------------------------------------------------------

    public function detach(string $hostname): void
    {
        $cf    = $this->needCf();
        $route = $this->mustFind($hostname);

        $this->vm->removeBlock($hostname);
        $ap = $this->validateAndReload();
        if (!$ap['ok']) {
            $this->log->append('detach', $hostname, false, 'Apache validate failed after vhost removal — backup restored');
            throw new RouteException('Apache invalid after vhost removal — backup restored. ' . ($ap['error'] ?? ''), 500);
        }

        $dnsOk = false;
        if (!empty($route['zone_id']) && !empty($route['dns_record_id'])) {
            try { $dnsOk = $cf->deleteDnsRecord($route['zone_id'], $route['dns_record_id']); }
            catch (Throwable $e) { $this->log->append('detach', $hostname, false, 'DNS delete failed: ' . $e->getMessage()); }
        }
        $this->state->removeRoute($hostname);
        try { (new UserStore(Config::stateDir()))->revokeHost($hostname); } catch (Throwable) {}   // nobody keeps access to a route that no longer exists
        self::dnsChanged();
        $this->log->append('detach', $hostname, true, 'vhost removed; DNS deleted=' . ($dnsOk ? 'yes' : 'failed'));
    }

    // ---- Enable / disable ----------------------------------------------------

    public function setEnabled(string $hostname, bool $enable): void
    {
        $route = $this->mustFind($hostname);
        $enable ? $this->vm->enableBlock($hostname) : $this->vm->disableBlock($hostname);

        $ap = $this->validateAndReload();
        if (!$ap['ok']) {
            $enable ? $this->vm->disableBlock($hostname) : $this->vm->enableBlock($hostname);
            throw new RouteException('Apache invalid after toggle — reverted. ' . ($ap['error'] ?? ''), 500);
        }
        $this->state->upsertRoute(array_merge($route, ['enabled' => $enable]));
        $this->log->append('toggle', $hostname, true, $enable ? 'enabled' : 'disabled');
    }

    // ---- Expiry --------------------------------------------------------------

    /**
     * Turn a request body into an absolute unix expiry, or null for "never".
     * Accepts: never=true | in=<seconds from now> | extend=<seconds added to current expiry, or to now> | at=<unix>.
     * Returns false when the body asks for nothing.
     */
    public static function resolveExpiry(array $b, ?int $current = null): int|null|false
    {
        $now = time();
        if (!empty($b['never'])) return null;
        $at = match (true) {
            isset($b['at'])     => (int)$b['at'],
            isset($b['in'])     => $now + (int)$b['in'],
            isset($b['extend']) => max($current ?? 0, $now) + (int)$b['extend'],
            default             => false,
        };
        if ($at === false) return false;
        if ($at < $now + self::MIN_EXPIRY) throw new RouteException('Expiry must be at least 5 minutes from now');
        if ($at > $now + self::MAX_EXPIRY) throw new RouteException('Expiry cannot be more than 12 months from now');
        return $at;
    }

    public function setExpiry(string $hostname, ?int $at): void
    {
        $this->mustFind($hostname);
        $this->state->upsertRoute(['hostname' => $hostname, 'expires_at' => $at]);
        $this->log->append('expiry', $hostname, true, $at ? 'set to ' . gmdate('Y-m-d H:i', $at) . ' UTC' : 'removed');
    }

    /** Disable or detach every route whose expiry has passed. Returns [{hostname, ok, detail}]. */
    public function sweep(): array
    {
        $action = Config::load()['defaults']['expiry_action'] ?? 'disable';
        $now = time(); $done = [];
        foreach ($this->state->loadRoutes() as $r) {
            $at = $r['expires_at'] ?? null;
            if (!$at || $at > $now) continue;
            $h = $r['hostname'];
            try {
                if ($action === 'detach') {
                    $this->detach($h);
                } else {
                    if ($r['enabled'] ?? true) $this->setEnabled($h, false);
                    // Clear the expiry so a disabled route isn't swept again; keep a record of when it lapsed.
                    $this->state->upsertRoute(['hostname' => $h, 'expires_at' => null, 'expired_at' => $now]);
                }
                $this->log->append('expire', $h, true, $action === 'detach' ? 'expired — detached' : 'expired — disabled');
                $done[] = ['hostname' => $h, 'ok' => true, 'detail' => $action];
            } catch (Throwable $e) {
                $this->log->append('expire', $h, false, $e->getMessage());
                $done[] = ['hostname' => $h, 'ok' => false, 'detail' => $e->getMessage()];
            }
        }
        return $done;
    }

    /** sweep() at most once a minute, safe to call from every request; a lock stops overlapping runs. */
    public function sweepIfDue(string $stateDir): array
    {
        $stamp = rtrim(normalizePath($stateDir), '/') . '/sweep.stamp';
        if (is_file($stamp) && time() - filemtime($stamp) < 60) return [];
        $fh = fopen($stamp, 'c');
        if (!$fh) return [];
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) return [];
            $res = $this->sweep();
            ftruncate($fh, 0); fwrite($fh, (string)time());
            return $res;
        } finally { fclose($fh); }
    }

    // ---- Bulk ----------------------------------------------------------------

    /**
     * Run one action over many routes. Each item succeeds or fails on its own; Apache is validated per
     * item but reloaded once at the end. Returns [{id, ok, error?}].
     * Actions: attach (projects, zone_id, pattern, www_alias, expires_*), detach|enable|disable (hostnames),
     * expiry (hostnames + never|in|extend|at).
     */
    public function bulk(string $action, array $b): array
    {
        $jobs = match ($action) {
            'attach' => $this->attachJobs($b),
            'detach' => $this->hostJobs($b, fn($h) => $this->detach($h)),
            'enable', 'disable' => $this->hostJobs($b, function ($h) use ($action) {
                if (($this->mustFind($h)['enabled'] ?? true) !== ($action === 'enable')) $this->setEnabled($h, $action === 'enable');
            }),
            'expiry' => $this->hostJobs($b, function ($h) use ($b) {
                $at = self::resolveExpiry($b, $this->mustFind($h)['expires_at'] ?? null);
                if ($at === false) throw new RouteException('Send never, in, extend or at');
                $this->setExpiry($h, $at);
            }),
            default => throw new RouteException("Unknown bulk action: $action"),
        };

        $this->deferReload = true;
        $out = [];
        try {
            foreach ($jobs as $id => $job) {
                try { $job(); $out[] = ['id' => $id, 'ok' => true]; }
                catch (Throwable $e) { $out[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()]; }
            }
        } finally {
            $this->deferReload = false;
            if ($this->reloadPending) { $this->reloadPending = false; $this->ap->reload(); }
        }
        return $out;
    }

    /** DNS-safe label from a folder name: "My_Site 2" → "my-site-2". */
    public static function slug(string $name): string
    {
        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 63);
    }

    private function hostJobs(array $b, callable $fn): array
    {
        $hosts = array_values(array_unique(array_filter((array)($b['hostnames'] ?? []), 'is_string')));
        if (!$hosts) throw new RouteException('No hostnames given');
        $jobs = [];
        foreach ($hosts as $h) $jobs[$h] = fn() => $fn($h);
        return $jobs;
    }

    private function attachJobs(array $b): array
    {
        $projects = array_values(array_unique(array_filter((array)($b['projects'] ?? []), 'is_string')));
        $pattern  = trim((string)($b['pattern'] ?? '{project}'));
        if (!$projects) throw new RouteException('No projects given');
        if (!str_contains($pattern, '{project}')) throw new RouteException('Pattern must contain {project}');
        $exp = isset($b['expires_in']) ? self::resolveExpiry(['in' => (int)$b['expires_in']])
             : (isset($b['expires_at']) ? self::resolveExpiry(['at' => (int)$b['expires_at']]) : null);

        $jobs = []; $seen = [];
        foreach ($projects as $p) {
            $sub = trim(str_replace('{project}', self::slug($p), $pattern), '.');
            $jobs[$p] = function () use ($p, $sub, $b, $exp, &$seen) {
                if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $sub)) throw new RouteException("Cannot build a valid hostname from \"$p\"");
                if (isset($seen[$sub])) throw new RouteException("Same hostname as {$seen[$sub]}: $sub");
                $seen[$sub] = $p;
                $this->attach($p, (string)($b['zone_id'] ?? ''), $sub, (bool)($b['www_alias'] ?? false), '', false, $exp, !empty($b['portal_enabled']));
            };
        }
        return $jobs;
    }

    // ---- internals -----------------------------------------------------------

    /** Something changed in DNS or the route list: drop cached DNS maps so the next page load is truthful. */
    public static function dnsChanged(): void
    {
        try { (new Cache(Config::stateDir()))->forget('dns'); } catch (Throwable) {}   // dnsmap_*, dnslist_*
    }

    /** validate (and reload now, unless a bulk run is deferring the reload to its end). */
    private function validateAndReload(): array
    {
        $r = $this->ap->validateAndReload($this->vm, !$this->deferReload);
        if ($r['ok'] && $this->deferReload) $this->reloadPending = true;
        return $r;
    }

    private function needCf(): CloudflareClient
    {
        return $this->cf ?? throw new RouteException('Cloudflare not configured — visit Settings first', 503);
    }

    private function mustFind(string $hostname): array
    {
        return $this->state->findRoute($hostname) ?? throw new RouteException("Route not found: $hostname", 404);
    }
}
