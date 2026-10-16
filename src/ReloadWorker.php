<?php
declare(strict_types=1);

/**
 * Applies a pending Apache config change by restarting it, and PROVES it worked.
 *
 * Runs in its own process (reload_worker.php) because a restart can interrupt the web request that asked
 * for it. It tries strategies in order and, after each, watches Apache's error log for the line Apache
 * prints when it comes back ("... resuming normal operations"). A strategy only counts if that line appears.
 * Progress and the final result go to state/reload.json, which the UI shows.
 *
 *  1. graceful   httpd -k restart                         (no UAC; works when Apache and this tool run as the same user)
 *  2. elevated   same, via PowerShell RunAs               (UAC prompt unless the XAMPP Control Panel already runs as admin)
 *  3. hard       taskkill + start httpd.exe               (opt-in: the XAMPP Control Panel may need a click to resync)
 */
class ReloadWorker
{
    public const CONFIRM_RE = '/resuming normal operations/i';

    public function __construct(private string $stateDir) {}

    public static function requestFile(string $stateDir): string { return rtrim(normalizePath($stateDir), '/') . '/reload_request.json'; }
    public static function statusFile(string $stateDir): string  { return rtrim(normalizePath($stateDir), '/') . '/reload.json'; }

    public static function status(string $stateDir): ?array
    {
        $f = self::statusFile($stateDir);
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) ? $j : null;
    }

    /**
     * @param array|null $strategies [['name' => string, 'run' => callable(): array{code:int, output:string}]], default: for this OS
     * @return array the final status
     */
    public function run(?array $strategies = null, int $confirmSecs = 12, int $settleMs = 1000): array
    {
        $req = json_decode((string)@file_get_contents(self::requestFile($this->stateDir)), true);
        if (!is_array($req)) return $this->finish(['id' => '?', 'state' => 'failed', 'detail' => 'No reload request found.', 'attempts' => []]);
        $st = ['id' => $req['id'], 'state' => 'running', 'requested_at' => $req['requested_at'], 'started_at' => time(), 'attempts' => []];

        $lock = @fopen($this->stateDir . '/reload.lock', 'c');                   // one restart at a time; later requests wait their turn
        if ($lock) { $t = time(); while (!flock($lock, LOCK_EX | LOCK_NB) && time() - $t < 90) usleep(200000); }
        try {
            $this->write($st);
            usleep($settleMs * 1000);                                           // let the HTTP response that triggered us leave first
            $log = $req['log'] ?? null;
            foreach ($strategies ?? $this->defaultStrategies($req) as $s) {
                $offset = $log && is_file($log) ? (int)filesize($log) : 0;
                $t0 = microtime(true);
                $res = $s['run']();
                $confirmed = $this->confirm($log, $offset, $res['code'] === 0, $confirmSecs);
                $st['attempts'][] = ['method' => $s['name'], 'exit' => $res['code'], 'output' => mb_substr(trim($res['output']), 0, 400),
                                     'confirmed' => $confirmed, 'verified_by' => $log && is_file($log) ? 'apache error log' : 'exit code only', 'secs' => round(microtime(true) - $t0, 1)];
                $this->write($st);
                if ($confirmed) return $this->finish(array_merge($st, ['state' => 'ok', 'detail' => "Apache reloaded ({$s['name']})."]));
            }
            return $this->finish(array_merge($st, ['state' => 'failed', 'detail' => $this->hint($st['attempts'], $req)]));
        } finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
    }

    /** Did Apache come back? Looks for its "resuming normal operations" line after $offset; without a readable log, trusts a zero exit code. */
    private function confirm(?string $log, int $offset, bool $exitOk, int $secs): bool
    {
        if (!$log || !is_file($log) || !is_readable($log)) return $exitOk;
        $deadline = microtime(true) + $secs;
        do {
            clearstatcache(true, $log);
            $size = (int)filesize($log);
            if ($size < $offset) $offset = 0;                                   // log rotated
            if ($size > $offset) {
                $fh = fopen($log, 'rb'); fseek($fh, $offset); $new = (string)stream_get_contents($fh); fclose($fh);
                if (preg_match(self::CONFIRM_RE, $new)) return true;
            }
            usleep(400000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function defaultStrategies(array $req): array
    {
        $bin = (string)$req['bin']; $win = DIRECTORY_SEPARATOR === '\\';
        $native = $win ? str_replace('/', '\\', $bin) : $bin;
        $out = [['name' => 'graceful restart', 'run' => fn() => $this->exec([$native, '-k', $win ? 'restart' : 'graceful'])]];
        if ($win && !empty($req['elevate'])) {
            $ps = "Start-Process -FilePath '" . str_replace("'", "''", $native) . "' -ArgumentList '-k','restart' -Verb RunAs -WindowStyle Hidden -Wait";
            $out[] = ['name' => 'elevated restart (UAC)', 'run' => fn() => $this->exec(['powershell', '-NoProfile', '-NonInteractive', '-Command', $ps])];
        }
        if ($win && !empty($req['hard'])) {
            $out[] = ['name' => 'hard restart', 'run' => function () use ($native) {
                $a = $this->exec(['taskkill', '/F', '/IM', basename($native)]);
                sleep(2);
                $h = popen('start "" /D "' . dirname($native, 2) . '" "' . $native . '"', 'r'); if ($h) pclose($h);   // detached, like the Control Panel's start script
                return ['code' => 0, 'output' => $a['output']];
            }];
        }
        return $out;
    }

    /** Run a command without a shell (no quoting pitfalls), capture its output, never hang forever. */
    protected function exec(array $argv, int $timeoutSecs = 60): array
    {
        $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return ['code' => 127, 'output' => 'could not start ' . $argv[0]];
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $end = time() + $timeoutSecs;
        while (true) {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $s = proc_get_status($p);
            if (!$s['running']) break;
            if (time() > $end) { proc_terminate($p); return ['code' => 124, 'output' => $out . ' [timed out]']; }
            usleep(100000);
        }
        $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = $s['exitcode'] >= 0 ? $s['exitcode'] : proc_close($p);
        return ['code' => (int)$code, 'output' => $out];
    }

    private function hint(array $attempts, array $req): string
    {
        $tried = implode(', ', array_column($attempts, 'method'));
        $h = "Apache did not confirm a restart (tried: $tried). The config was written and checked, so it will apply the next time Apache restarts — restart it from the XAMPP Control Panel now.";
        if (empty($req['elevate'])) $h .= ' Tip: enable "Allow UAC prompt" in Settings → Apache reload.';
        elseif (empty($req['hard'])) $h .= ' Tip: if the UAC prompt kept being dismissed, start the XAMPP Control Panel "as administrator" so reloads need no prompt; or enable "Hard restart" in Settings.';
        return $h;
    }

    private function write(array $st): void { atomicWrite(self::statusFile($this->stateDir), jsonPretty($st)); }

    private function finish(array $st): array { $st['finished_at'] = time(); $this->write($st); return $st; }
}
