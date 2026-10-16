<?php
declare(strict_types=1);

class ProjectScanner
{
    private string $htdocs;

    /** Folders to skip even if they exist in htdocs. */
    private static array $skip = [
        'tunnel-router',
        'phpmyadmin',
        'dashboard',
        'xampp',
        'webalizer',
    ];

    public function __construct(string $htdocsPath)
    {
        $this->htdocs = rtrim(normalizePath($htdocsPath), '/');
    }

    /**
     * Return all htdocs subfolders (not files, not dot-dirs, not skipped).
     * Each entry: ['name' => string, 'path' => string]
     */
    public function scan(): array
    {
        if (!is_dir($this->htdocs)) return [];

        $projects = [];
        $entries  = scandir($this->htdocs);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (str_starts_with($entry, '.')) continue;
            if (in_array(strtolower($entry), self::$skip, true)) continue;
            $fullPath = $this->htdocs . '/' . $entry;
            if (!is_dir($fullPath)) continue;
            $projects[] = ['name' => $entry, 'path' => $fullPath];
        }

        usort($projects, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $projects;
    }

    /**
     * Facts about one project folder for its page. The walk is capped (entries and seconds) so a huge folder such as one
     * holding node_modules cannot stall the page; `truncated` says when the numbers are a lower bound.
     * @return array{name:string, path:string, files:int, folders:int, bytes:int, newest:int, truncated:bool, entry:?string, has_htaccess:bool, top:array}
     */
    public function inspect(string $name, int $maxEntries = 20000, float $maxSecs = 1.5): array
    {
        $root = $this->htdocs . '/' . $name;
        $out = ['name' => $name, 'path' => $root, 'files' => 0, 'folders' => 0, 'bytes' => 0, 'newest' => 0, 'truncated' => false, 'entry' => null, 'has_htaccess' => is_file("$root/.htaccess"), 'top' => []];
        foreach (['index.php', 'index.html', 'index.htm', 'default.php'] as $f) if (is_file("$root/$f")) { $out['entry'] = $f; break; }
        foreach (scandir($root) ?: [] as $e) if ($e !== '.' && $e !== '..' && count($out['top']) < 12) $out['top'][] = ['name' => $e, 'dir' => is_dir("$root/$e")];

        $t0 = microtime(true); $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $it->setMaxDepth(12);
        foreach ($it as $path => $info) {
            if (++$n > $maxEntries || microtime(true) - $t0 > $maxSecs) { $out['truncated'] = true; break; }
            if ($info->isLink()) continue;                                            // never follow links out of the folder
            if ($info->isDir()) { $out['folders']++; continue; }
            $out['files']++; $out['bytes'] += (int)$info->getSize(); $out['newest'] = max($out['newest'], (int)$info->getMTime());
        }
        return $out;
    }
}
