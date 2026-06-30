<?php
declare(strict_types=1);

/**
 * Atomic file write: write to .tmp then rename over target.
 * Prevents partial writes from corrupting the destination.
 */
function atomicWrite(string $path, string $content): void
{
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException("atomicWrite: cannot write temp file: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("atomicWrite: rename failed: $tmp → $path");
    }
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
    if (preg_match('#[/\\\0]#', $name)) return false;          // no slashes or nulls
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
