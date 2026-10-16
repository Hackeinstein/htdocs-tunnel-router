<?php
declare(strict_types=1);

/** In-app alert inbox (state/alerts.json, newest first, capped). */
class Alerts
{
    private const MAX = 200;
    private string $file;

    public function __construct(string $stateDir)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/alerts.json';
    }

    /** @param string $severity info|warn|error */
    public function add(string $type, string $severity, string $host, string $msg, ?int $now = null): array
    {
        $a = ['id' => bin2hex(random_bytes(4)), 'ts' => $now ?? time(), 'type' => $type, 'severity' => $severity, 'host' => $host, 'msg' => $msg, 'read' => false];
        withLock($this->file, function () use ($a) {
            $all = $this->all();
            array_unshift($all, $a);
            atomicWrite($this->file, jsonPretty(array_slice($all, 0, self::MAX)));
        });
        return $a;
    }

    public function all(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        return is_array($raw) ? $raw : [];
    }

    public function unread(): int
    {
        return count(array_filter($this->all(), fn($a) => !$a['read']));
    }

    public function markAllRead(): void
    {
        withLock($this->file, fn() => atomicWrite($this->file, jsonPretty(array_map(fn($a) => ['read' => true] + $a, $this->all()))));
    }
}
