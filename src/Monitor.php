<?php
declare(strict_types=1);

/**
 * Health monitor, driven by the once-a-minute page tick (no background process, so it only samples
 * while a Tunnel Router page is open). Probes routes, watches the tunnel, and raises alerts on changes.
 */
class Monitor
{
    public const PROBE_EVERY  = 120;   // seconds between probe rounds
    public const TUNNEL_EVERY = 300;
    public const FAILS_BEFORE_ALERT = 2; // consecutive failed probes before "down" (filters one-off blips)
    public const EXPIRY_WARN  = 3600;

    private string $dir;

    public function __construct(private State $state, private Insights $in, private Alerts $alerts, string $stateDir, private string $tunnelId)
    {
        $this->dir = rtrim(normalizePath($stateDir), '/');
    }

    /** run() at most every PROBE_EVERY seconds across all open tabs. Returns alerts raised. */
    public function runIfDue(): array
    {
        $stamp = $this->dir . '/monitor.stamp';
        if (is_file($stamp) && time() - filemtime($stamp) < self::PROBE_EVERY - 5) return [];
        $fh = fopen($stamp, 'c');
        if (!$fh) return [];
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) return [];
            $new = $this->run(time());
            ftruncate($fh, 0); fwrite($fh, (string)time());
            return $new;
        } finally { fclose($fh); }
    }

    public function run(int $now): array
    {
        $st  = $this->load();
        $new = [];
        $raise = function (string $type, string $sev, string $host, string $msg) use (&$new, $now) { $new[] = $this->alerts->add($type, $sev, $host, $msg, $now); };

        // --- routes
        $routes = array_filter($this->state->loadRoutes(), fn($r) => $r['enabled'] ?? true);
        $hosts  = array_column($routes, 'hostname');
        $st['hosts'] = array_intersect_key($st['hosts'] ?? [], array_flip($hosts));
        if ($hosts) {
            foreach ($this->in->probe($hosts) as $h => $p) {
                $s = $st['hosts'][$h] ?? ['fails' => 0, 'down' => false];
                if ($p['ok']) {
                    if ($s['down']) $raise('route_up', 'info', $h, "$h is back up ({$p['ms']} ms)");
                    $s = ['fails' => 0, 'down' => false];
                } else {
                    $s['fails']++;
                    if ($s['fails'] >= self::FAILS_BEFORE_ALERT && !$s['down']) {
                        $why = ['tunnel_down' => 'the tunnel is not answering (HTTP ' . $p['code'] . ')', 'error' => 'HTTP ' . $p['code'], 'unreachable' => 'no response'][$p['state']] ?? $p['state'];
                        $raise('route_down', 'error', $h, "$h is down: $why");
                        $s['down'] = true;
                    }
                }
                $st['hosts'][$h] = $s;
            }
        }

        // --- expiring soon (once per expiry value)
        $exp = [];
        foreach ($routes as $r) {
            $at = $r['expires_at'] ?? null;
            if (!$at || $at <= $now) continue;
            $exp[$r['hostname']] = $at;
            if ($at - $now <= self::EXPIRY_WARN && ($st['expiring'][$r['hostname']] ?? null) !== $at) {
                $raise('expiring', 'warn', $r['hostname'], $r['hostname'] . ' expires in ' . max(1, (int)round(($at - $now) / 60)) . ' min');
                $exp[$r['hostname']] = $at; $st['expiring'][$r['hostname']] = $at;
            }
        }
        $st['expiring'] = array_intersect_key($st['expiring'] ?? [], $exp);

        // --- tunnel
        if ($this->tunnelId !== '' && $now - ($st['tunnel_at'] ?? 0) >= self::TUNNEL_EVERY) {
            $st['tunnel_at'] = $now;
            try {
                $status = (string)($this->in->tunnel($this->tunnelId)['status'] ?? 'unknown');
                $prev = $st['tunnel'] ?? null;
                if ($status !== 'healthy' && $prev !== $status) $raise('tunnel_down', 'error', $this->tunnelId, "Tunnel status is $status");
                if ($status === 'healthy' && $prev !== null && $prev !== 'healthy') $raise('tunnel_up', 'info', $this->tunnelId, 'Tunnel is healthy again');
                $st['tunnel'] = $status;
                if (!empty($st['cf_error'])) { $st['cf_error'] = false; $raise('cf_ok', 'info', '', 'Cloudflare API is reachable again'); }
            } catch (Throwable $e) {
                if (empty($st['cf_error'])) { $st['cf_error'] = true; $raise('cf_error', 'error', '', 'Cloudflare API check failed: ' . $e->getMessage()); }
            }
        }

        atomicWrite($this->dir . '/monitor.json', jsonPretty($st));
        return $new;
    }

    // ---- reading history ------------------------------------------------------

    /** Per-route summary from health.json samples [ts, ok, ms]. */
    public function summary(?int $now = null): array
    {
        $now ??= time();
        $file = $this->dir . '/health.json';
        $hist = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        $out  = [];
        foreach ($this->state->loadRoutes() as $r) {
            $h = $r['hostname'];
            $s = $hist[$h] ?? [];
            $win = fn(int $secs) => array_values(array_filter($s, fn($x) => $x[0] >= $now - $secs));
            $d1 = $win(86400); $d7 = $win(7 * 86400);
            $pct = fn(array $a) => $a ? round(array_sum(array_column($a, 1)) / count($a) * 100, 1) : null;
            $ms = array_column(array_filter($d1, fn($x) => $x[1]), 2);
            $series = $d1; $step = max(1, (int)ceil(count($series) / 120));
            $out[] = ['hostname' => $h, 'enabled' => $r['enabled'] ?? true, 'samples' => count($s), 'since' => $s[0][0] ?? null,
                      'last' => $s ? end($s) : null, 'uptime24' => $pct($d1), 'uptime7' => $pct($d7),
                      'avg_ms' => $ms ? (int)round(array_sum($ms) / count($ms)) : null,
                      'series' => array_values(array_filter($series, fn($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH))];
        }
        return $out;
    }

    /** summary() for a single hostname (or null if it is not a tracked route). */
    public function summaryFor(string $host): ?array
    {
        foreach ($this->summary() as $r) if ($r['hostname'] === $host) return $r;
        return null;
    }

    private function load(): array
    {
        $f = $this->dir . '/monitor.json';
        $raw = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($raw) ? $raw : [];
    }
}
