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
    public function validate(): array
    {
        if (!file_exists($this->apacheBin)) {
            return [
                'ok'      => true,
                'output'  => '[validation skipped — apache binary not found at ' . $this->apacheBin . ']',
                'skipped' => true,
            ];
        }

        $bin = str_replace('/', '\\', $this->apacheBin);

        // Pass httpd.conf explicitly so httpd.exe locates its config regardless of CWD.
        // Without -f, running from PHP's CWD can cause "could not open config file" failures.
        $apacheRoot = dirname(dirname($this->apacheBin)); // .../apache/bin/httpd.exe → .../apache
        $confFile   = $apacheRoot . '/conf/httpd.conf';
        $confFlag   = file_exists($confFile)
            ? ' -f "' . str_replace('/', '\\', $confFile) . '"'
            : '';

        $cmd = '"' . $bin . '"' . $confFlag . ' -t 2>&1';
        exec($cmd, $out, $code);
        $output = implode("\n", $out);

        if ($code === 0) {
            return ['ok' => true, 'output' => $output, 'skipped' => false];
        }

        // Filter the XAMPP mpm_winnt false positive (AH02965 — child generation counter)
        // which fires when httpd -t runs outside the Control Panel's process tree.
        $filtered   = preg_replace('/^.*AH02965.*\r?\n?/m', '', $output);
        $filtered   = trim($filtered);
        $realErrors = implode("\n", array_filter(
            explode("\n", $filtered),
            fn($line) => $line !== ''
                && !str_contains($line, 'parent')
                && !str_contains($line, 'generation')
        ));

        if ($realErrors === '' || str_contains($realErrors, 'Syntax OK')) {
            return ['ok' => true, 'output' => $output, 'skipped' => false];
        }

        return ['ok' => false, 'output' => $output, 'skipped' => false];
    }

    /**
     * Fire a detached, UAC-elevated `httpd -k restart`.
     * PHP flushes its response before Apache cycles (500 ms delay).
     *
     * Uses single quotes for -FilePath to avoid double-quote nesting
     * in the cmd.exe → PowerShell command boundary.
     */
    public function reload(): array
    {
        if (!file_exists($this->apacheBin)) {
            return ['ok' => true, 'output' => '[reload skipped — binary not found]'];
        }

        $bin = str_replace('/', '\\', $this->apacheBin);

        $ps = "Start-Sleep -Milliseconds 500; "
            . "Start-Process -Verb RunAs -FilePath '" . $bin . "' "
            . "-ArgumentList '-k','restart'";

        $handle = popen(
            'start /B powershell -NoProfile -WindowStyle Hidden -Command "' . $ps . '"',
            'r'
        );
        if ($handle !== false) pclose($handle);

        return ['ok' => true];
    }

    /**
     * Validate then reload. On failure: restore backup, return error with full Apache output.
     * This is the only method callers should use.
     */
    public function validateAndReload(VhostManager $vm): array
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
        if (!($result['skipped'] ?? false)) {
            $this->reload();
        }
        return ['ok' => true, 'output' => $result['output']];
    }
}
