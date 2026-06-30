<?php
declare(strict_types=1);

class VhostManager
{
    private const OPEN  = '# >>> tunnel-router: ';
    private const CLOSE = '# <<< tunnel-router: ';

    private string $vhostsFile;
    private string $backupDir;

    public function __construct(string $vhostsFile, string $backupDir)
    {
        $this->vhostsFile = normalizePath($vhostsFile);
        $this->backupDir  = normalizePath($backupDir);
    }

    /**
     * Parse all fenced blocks in the vhosts file.
     * Returns [hostname => fullBlockText].
     */
    public function parseBlocks(): array
    {
        if (!file_exists($this->vhostsFile)) return [];
        $content = file_get_contents($this->vhostsFile);
        $blocks  = [];

        $open  = preg_quote(self::OPEN, '/');
        $close = preg_quote(self::CLOSE, '/');
        $pattern = '/' . $open . '(.+?)\r?\n(.*?)' . $close . '\1/s';

        if (preg_match_all($pattern, $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $blocks[$match[1]] = $match[0];
            }
        }
        return $blocks;
    }

    public function hasBlock(string $hostname): bool
    {
        return array_key_exists($hostname, $this->parseBlocks());
    }

    /** Append a new fenced vhost block. Backup first. */
    public function addBlock(string $hostname, string $docRoot, bool $wwwAlias): void
    {
        if ($this->hasBlock($hostname)) {
            throw new RuntimeException("VhostManager: block for $hostname already exists");
        }
        $this->backup();
        $existing = file_exists($this->vhostsFile)
            ? rtrim(file_get_contents($this->vhostsFile))
            : '';
        $block   = $this->buildBlockText($hostname, $docRoot, $wwwAlias);
        $content = $existing . "\n\n" . $block . "\n";
        atomicWrite($this->vhostsFile, $content);
    }

    /** Remove the fenced block for $hostname. Backup first. */
    public function removeBlock(string $hostname): void
    {
        $this->backup();
        $content = file_exists($this->vhostsFile)
            ? file_get_contents($this->vhostsFile)
            : '';
        $escaped = preg_quote($hostname, '/');
        $open    = preg_quote(self::OPEN, '/');
        $close   = preg_quote(self::CLOSE, '/');
        $pattern = '/' . $open . $escaped . '\r?\n.*?' . $close . $escaped . '\r?\n?/s';
        $new     = preg_replace($pattern, '', $content);
        if ($new === null) {
            throw new RuntimeException("VhostManager: regex error while removing block for $hostname");
        }
        atomicWrite($this->vhostsFile, $new);
    }

    /**
     * Comment out the <VirtualHost> section inside the fence (disable without removing fence).
     * Backup first.
     */
    public function disableBlock(string $hostname): void
    {
        $this->toggleBlock($hostname, disable: true);
    }

    /** Uncomment a disabled block. Backup first. */
    public function enableBlock(string $hostname): void
    {
        $this->toggleBlock($hostname, disable: false);
    }

    /** Build the full fenced block string (does NOT write to disk). */
    public function buildBlockText(string $hostname, string $docRoot, bool $wwwAlias): string
    {
        $alias = $wwwAlias ? "\n    ServerAlias www.{$hostname}" : '';
        return self::OPEN . $hostname . "\n"
            . "<VirtualHost 127.0.0.1:80>\n"
            . "    ServerName {$hostname}{$alias}\n"
            . "    DocumentRoot \"{$docRoot}\"\n"
            . "    <Directory \"{$docRoot}\">\n"
            . "        Require all granted\n"
            . "        AllowOverride All\n"
            . "    </Directory>\n"
            . "</VirtualHost>\n"
            . self::CLOSE . $hostname;
    }

    /** Restore the most recent timestamped backup. Returns true on success. */
    public function restoreLatestBackup(): bool
    {
        // Prefix must match what backupFile() saves: pathinfo(filename, PATHINFO_FILENAME)
        $prefix = pathinfo($this->vhostsFile, PATHINFO_FILENAME);
        $backup = latestBackup($this->backupDir, $prefix);
        if (!$backup) return false;
        return copy($backup, $this->vhostsFile);
    }

    // -------------------------------------------------------------------------

    private function backup(): void
    {
        if (file_exists($this->vhostsFile)) {
            backupFile($this->vhostsFile, $this->backupDir);
        }
    }

    private function toggleBlock(string $hostname, bool $disable): void
    {
        $this->backup();
        $content  = file_exists($this->vhostsFile) ? file_get_contents($this->vhostsFile) : '';
        $escaped  = preg_quote($hostname, '/');
        $open     = preg_quote(self::OPEN, '/');
        $close    = preg_quote(self::CLOSE, '/');
        $pattern  = '/(' . $open . $escaped . '\r?\n)(.*?)(' . $close . $escaped . ')/s';

        $new = preg_replace_callback($pattern, function ($m) use ($disable) {
            $inner = $m[2];
            if ($disable) {
                // Comment every non-blank line that isn't already commented
                $inner = preg_replace('/^(?!#)(.+)$/m', '# $1', $inner);
            } else {
                // Uncomment lines that were commented by this tool
                $inner = preg_replace('/^# (.+)$/m', '$1', $inner);
            }
            return $m[1] . $inner . $m[3];
        }, $content);

        if ($new === null) {
            throw new RuntimeException("VhostManager: toggle regex error for $hostname");
        }
        atomicWrite($this->vhostsFile, $new);
    }
}
