<?php
declare(strict_types=1);

class ApacheManager
{
    private string $apacheBin;

    public function __construct(string $apacheBin)
    {
        $this->apacheBin = normalizePath($apacheBin);
    }

    /**
     * Run `httpd.exe -t` to validate config syntax.
     * Returns ['ok' => bool, 'output' => string, 'skipped' => bool].
     */
    /** The binary path ends up inside a shell command line — refuse anything that could break out of the quotes. */
    private function unsafePath(): bool
    {
        return (bool)preg_match('/[\x00-\x1f"&|<>^%`$;]/', $this->apacheBin);
    }

    public function validate(): array
    {
        if ($this->unsafePath()) return ['ok' => false, 'output' => 'Apache binary path contains characters that are not allowed.', 'skipped' => false];
        if (!file_exists($this->apacheBin)) {
            return [
                'ok'      => true,
                'output'  => '[validation skipped — apache binary not found at ' . $this->apacheBin . ']',
                'skipped' => true,
            ];
        }

        $win = DIRECTORY_SEPARATOR === '\\';
        $bin = $win ? str_replace('/', '\\', $this->apacheBin) : $this->apacheBin;

        // Pass httpd.conf explicitly so httpd.exe locates its config regardless of CWD.
        // Without -f, running from PHP's CWD can cause "could not open config file" failures.
        $apacheRoot = dirname(dirname($this->apacheBin)); // .../apache/bin/httpd.exe → .../apache
        $confFile   = $apacheRoot . '/conf/httpd.conf';
        $confFlag   = file_exists($confFile)
            ? ' -f "' . ($win ? str_replace('/', '\\', $confFile) : $confFile) . '"'
            : '';

        $cmd = '"' . $bin . '"' . $confFlag . ' -t 2>&1';
        exec($cmd, $out, $code);
        $output = implode("\n", $out);

        if ($code === 0) {
            return ['ok' => true, 'output' => $output, 'skipped' => false];
        }

        // XAMPP's mpm_winnt prints one harmless line (AH02965, "Unable to retrieve my generation from the parent")
        // when httpd -t runs outside the Control Panel's process tree. Ignore exactly that line and nothing else:
        // a real error that merely mentions "parent" must still fail validation.
        $realErrors = trim(implode("\n", array_filter(
            preg_split('/\r?\n/', $output) ?: [],
            fn($line) => trim($line) !== '' && !preg_match('/AH02965|Unable to retrieve my generation from the parent/i', $line)
        )));

        if ($realErrors === '') {
            return ['ok' => true, 'output' => $output, 'skipped' => false];
        }

        return ['ok' => false, 'output' => $output, 'skipped' => false];
    }

    /**
     * Ask for an Apache restart. Returns at once: a separate process (reload_worker.php) does the restart, tries
     * stronger methods if one fails, and records a VERIFIED result in state/reload.json (see ReloadWorker).
     * ['ok' => bool, 'pending' => bool, 'id' => string]
     */
    public function reload(): array
    {
        if ($this->unsafePath()) return ['ok' => false, 'output' => 'Apache binary path contains characters that are not allowed.'];
        $stateDir = Config::stateDir();
        if (!file_exists($this->apacheBin)) {
            atomicWrite(ReloadWorker::statusFile($stateDir), jsonPretty(['id' => '-', 'state' => 'skipped', 'requested_at' => time(), 'finished_at' => time(),
                'detail' => 'Apache binary not found at ' . $this->apacheBin . ' — check the path in Settings.', 'attempts' => []]));
            return ['ok' => true, 'output' => '[reload skipped — binary not found]'];
        }
        $d = Config::load()['defaults'];
        $id = bin2hex(random_bytes(4));
        $log = dirname($this->apacheBin, 2) . '/logs/error.log';
        atomicWrite(ReloadWorker::requestFile($stateDir), jsonPretty(['id' => $id, 'requested_at' => time(), 'bin' => $this->apacheBin, 'log' => is_file($log) ? $log : null,
            'elevate' => (bool)($d['reload_elevate'] ?? true), 'hard' => (bool)($d['reload_hard'] ?? false)]));
        atomicWrite(ReloadWorker::statusFile($stateDir), jsonPretty(['id' => $id, 'state' => 'pending', 'requested_at' => time(), 'attempts' => []]));
        if (!$this->spawnWorker($stateDir)) {
            atomicWrite(ReloadWorker::statusFile($stateDir), jsonPretty(['id' => $id, 'state' => 'failed', 'requested_at' => time(), 'finished_at' => time(), 'attempts' => [],
                'detail' => 'Could not start the reload helper process. Restart Apache from the XAMPP Control Panel.']));
            return ['ok' => true, 'pending' => false, 'id' => $id];
        }
        return ['ok' => true, 'pending' => true, 'id' => $id];
    }

    /** Start reload_worker.php detached from this request (a restart can end the request that asked for it). */
    protected function spawnWorker(string $stateDir): bool
    {
        $worker = dirname(__DIR__) . '/reload_worker.php';
        if (DIRECTORY_SEPARATOR === '\\') {
            $php = dirname($this->apacheBin, 3) . '/php/php.exe';                  // C:/xampp/apache/bin/httpd.exe → C:/xampp/php/php.exe
            if (!is_file($php)) $php = 'php.exe';
            $cmd = 'start "" /B "' . str_replace('/', '\\', $php) . '" -f "' . str_replace('/', '\\', $worker) . '" "' . str_replace('/', '\\', $stateDir) . '"';
            $h = @popen($cmd, 'r');
            if ($h === false) return false;
            pclose($h);
            return true;
        }
        $php = is_file(PHP_BINARY) && str_starts_with(basename(PHP_BINARY), 'php') ? PHP_BINARY : 'php';
        @exec('nohup ' . escapeshellarg($php) . ' -f ' . escapeshellarg($worker) . ' ' . escapeshellarg($stateDir) . ' >/dev/null 2>&1 &', $o, $rc);
        return $rc === 0;
    }

    /**
     * Validate then reload (pass $reload=false to validate only, e.g. mid-batch). On failure: restore
     * backup, return error with full Apache output.
     */
    public function validateAndReload(VhostManager $vm, bool $reload = true): array
    {
        $result = $this->validate();
        if (!$result['ok']) {
            $restored = $vm->restoreLatestBackup();
            return [
                'ok'     => false,
                'error'  => 'Apache config validation failed — '
                          . ($restored ? 'backup restored.' : 'no backup available; check vhosts file manually.'),
                'output' => $result['output'],
            ];
        }
        if ($reload && !($result['skipped'] ?? false)) {
            $this->reload();
        }
        return ['ok' => true, 'output' => $result['output']];
    }
}
