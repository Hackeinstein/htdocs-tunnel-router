<?php
declare(strict_types=1);

class ActivityLog
{
    private string $logFile;
    private const MAX_ENTRIES = 1000;

    public function __construct(string $stateDir)
    {
        $this->logFile = rtrim(normalizePath($stateDir), '/') . '/activity.json';
    }

    public function append(string $action, string $hostname, bool $ok, string $detail): void
    {
        withLock($this->logFile, function () use ($action, $hostname, $ok, $detail) {
            $entries = $this->readAll();
            array_unshift($entries, ['ts' => nowUtc(), 'action' => $action, 'hostname' => $hostname, 'ok' => $ok, 'detail' => $detail]);
            atomicWrite($this->logFile, jsonPretty(array_slice($entries, 0, self::MAX_ENTRIES)));
        });
    }

    /** Return most-recent-first, limited to $limit entries, starting at $offset. */
    public function read(int $limit = 100, int $offset = 0): array
    {
        return array_slice($this->readAll(), $offset, $limit);
    }

    private function readAll(): array
    {
        if (!file_exists($this->logFile)) return [];
        $raw = json_decode(file_get_contents($this->logFile), true);
        return is_array($raw) ? $raw : [];
    }
}
