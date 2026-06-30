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
}
