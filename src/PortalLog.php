<?php
declare(strict_types=1);

/** What portal users did (state/portal_activity.json, newest first, capped). Kept apart from the admin activity log. */
class PortalLog
{
    private const MAX = 3000;
    private string $file;

    public function __construct(string $stateDir)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/portal_activity.json';
    }

    public function append(string $user, string $host, string $action, string $path, bool $ok, string $ip, string $detail = ''): void
    {
        $e = ['ts' => time(), 'user' => $user, 'host' => $host, 'action' => $action, 'path' => mb_substr($path, 0, 300), 'ok' => $ok, 'ip' => $ip, 'detail' => mb_substr($detail, 0, 300)];
        withLock($this->file, function () use ($e) {
            $all = $this->load(); array_unshift($all, $e);
            atomicWrite($this->file, json_encode(array_slice($all, 0, self::MAX)));
        });
    }

    /** @return array newest first, optionally only one user and/or one host */
    public function read(int $limit = 100, ?string $user = null, ?string $host = null): array
    {
        return array_slice(array_values(array_filter($this->load(), fn($e) => ($user === null || $e['user'] === $user) && ($host === null || $e['host'] === $host))), 0, $limit);
    }

    private function load(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        return is_array($raw) ? $raw : [];
    }
}
