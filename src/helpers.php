<?php
declare(strict_types=1);

/**
 * Atomic file write: write to .tmp then rename over target.
 * Prevents partial writes from corrupting the destination.
 */
function atomicWrite(string $path, string $content): void
{
    // Folder not writable (e.g. root-owned Apache conf dir) but the file itself is: write in place.
    // Not atomic, but it is the only option without write access to the folder.
    if (is_file($path) && is_writable($path) && !is_writable(dirname($path))) {
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException("Cannot write $path");
        return;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        $dir = dirname($path);
        throw new RuntimeException(!is_dir($dir) ? "Folder does not exist: $dir"
            : "Cannot write to $dir — give the Apache/PHP user write access to this folder (for state/, that is tunnel-router\\state and tunnel-router\\state\\backups).");
    }
    // On Windows a rename onto a file another process has open (antivirus, a concurrent reader) fails briefly: retry.
    for ($try = 0; $try < 8; $try++) {
        if (@rename($tmp, $path)) return;
        usleep(40000);
    }
    @unlink($tmp);
    throw new RuntimeException("Could not replace $path — it may be read-only or locked by another program.");
}

/**
 * Run $fn while holding an exclusive lock for $path (a "$path.lock" file), so concurrent requests doing
 * read-modify-write on the same JSON file cannot overwrite each other. Gives up waiting after 5 s and
 * runs anyway rather than hanging the page.
 */
function withLock(string $path, callable $fn): mixed
{
    $fh = @fopen($path . '.lock', 'c');
    if (!$fh) return $fn();                                   // cannot create a lock file (read-only folder): better than failing
    try {
        $t = microtime(true);
        while (!flock($fh, LOCK_EX | LOCK_NB)) { if (microtime(true) - $t > 5) break; usleep(15000); }
        return $fn();
    } finally { flock($fh, LOCK_UN); fclose($fh); }
}

/**
 * Backup a file to $backupDir with a timestamped name.
 * Returns the backup path.
 */
function backupFile(string $path, string $backupDir): string
{
    if (!file_exists($path)) {
        throw new RuntimeException("backupFile: source not found: $path");
    }
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true)) {
        throw new RuntimeException("backupFile: cannot create backup dir: $backupDir");
    }
    $base   = pathinfo($path, PATHINFO_FILENAME);
    $ext    = pathinfo($path, PATHINFO_EXTENSION);
    $stamp  = date('Ymd_His');
    $dest   = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . "{$base}_{$stamp}.{$ext}";
    if (!copy($path, $dest)) {
        throw new RuntimeException("backupFile: copy failed: $path → $dest");
    }
    return $dest;
}

/**
 * Validate a project name (htdocs subfolder).
 * Rejects traversal attempts, absolute paths, and control characters.
 */
function sanitizeProjectName(string $name): bool
{
    if ($name === '' || $name === '.' || $name === '..') return false;
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) return false; // no slashes or NUL
    if (preg_match('/[<>:"|?*]/', $name)) return false;        // no Windows-reserved chars
    if (strlen($name) > 255) return false;
    return true;
}

/**
 * Validate a hostname for use in DNS and Apache vhost config.
 * Must be a valid DNS label string.
 */
function sanitizeHostname(string $h): bool
{
    if ($h === '') return false;
    if (strlen($h) > 253) return false;
    // Basic DNS hostname: labels separated by dots, each 1–63 chars, total ≤ 253
    return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $h);
}

/**
 * JSON-encode with pretty-print and no escaped slashes.
 */
function jsonPretty(mixed $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ?: throw new RuntimeException('jsonPretty: encode failed');
}

/**
 * Normalize Windows backslashes to forward slashes for PHP path use.
 */
function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * Return current UTC timestamp as ISO 8601.
 */
function nowUtc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Latest backup file matching $prefix in $backupDir, or null.
 */
function latestBackup(string $backupDir, string $prefix): ?string
{
    if (!is_dir($backupDir)) return null;
    $files = glob(rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $prefix . '_*.conf');
    if (!$files) return null;
    sort($files);
    return end($files) ?: null;
}

/** Page title block shared by all views. $actions is trusted HTML; $crumb is [label, href] or null. */
function pageHeader(string $title, string $sub = '', string $actions = '', ?array $crumb = null, string $titleId = ''): string
{
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
    $c = $crumb ? '<a href="' . $e($crumb[1]) . '" class="mb-1 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"><i data-lucide="arrow-left" class="!size-3.5"></i>' . $e($crumb[0]) . '</a>' : '';
    return '<div class="mb-6 flex flex-wrap items-end justify-between gap-3"><div class="min-w-0">' . $c
        . '<h1 class="truncate text-2xl font-bold"' . ($titleId ? ' id="' . $e($titleId) . '"' : '') . '>' . $e($title) . '</h1>'
        . ($sub !== '' ? '<p class="muted mt-1 text-sm">' . $sub . '</p>' : '')
        . '</div><div class="flex gap-2">' . $actions . '</div></div>';
}

/** Resolve zone_id + subdomain → [hostname, zoneName]. Empty subdomain means the apex. */
function resolveHostname(CloudflareClient $cf, string $zoneId, string $subdomain): array
{
    $zoneName = '';
    foreach ($cf->listZones() as $z) {
        if ($z['id'] === $zoneId) { $zoneName = $z['name']; break; }
    }
    if (!$zoneName) throw new RuntimeException("Zone not found: $zoneId");
    return [$subdomain !== '' ? rtrim($subdomain, '.') . '.' . $zoneName : $zoneName, $zoneName];
}

/** Path to a CA bundle for HTTPS requests from PHP, or null to use curl's default. Windows XAMPP needs this. */
function caBundle(): ?string
{
    foreach ([(string)ini_get('curl.cainfo'), (string)ini_get('openssl.cafile'), 'C:/xampp/php/extras/ssl/cacert.pem',
              '/Applications/XAMPP/xamppfiles/share/curl/cacert.pem', '/etc/ssl/cert.pem', '/usr/local/etc/openssl/cert.pem'] as $p) {
        if ($p !== '' && is_file($p)) return $p;
    }
    return null;
}

/** URL of a file in assets/, with its modification time so browsers cache it for a year yet pick up changes at once. */
function asset(string $file): string
{
    $path = dirname(__DIR__) . '/assets/' . $file;
    return 'assets/' . $file . (is_file($path) ? '?v=' . filemtime($path) : '');
}
