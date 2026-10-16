<?php
declare(strict_types=1);

/** Slows down password guessing: too many failures for a key (a username or an address) locks it for a while. */
class LoginThrottle
{
    private string $file;

    public function __construct(string $stateDir)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/portal_throttle.json';
    }

    /** Seconds until $key may try again; 0 when it may try now. */
    public function wait(string $key, int $max, int $window, int $lock, ?int $now = null): int
    {
        $now ??= time();
        $f = array_filter($this->load()[$key] ?? [], fn($t) => $t > $now - $window);
        if (count($f) < $max) return 0;
        return max(0, (int)max($f) + $lock - $now);
    }

    public function fail(string $key, ?int $now = null): void
    {
        $now ??= time();
        withLock($this->file, function () use ($key, $now) {
            $all = $this->load();
            $all[$key] = array_slice(array_filter($all[$key] ?? [], fn($t) => $t > $now - 86400), -50);
            $all[$key][] = $now;
            foreach ($all as $k => $v) { $all[$k] = array_values(array_filter($v, fn($t) => $t > $now - 86400)); if (!$all[$k]) unset($all[$k]); }
            atomicWrite($this->file, json_encode($all));
        });
    }

    public function clear(string $key): void
    {
        withLock($this->file, function () use ($key) { $all = $this->load(); unset($all[$key]); atomicWrite($this->file, json_encode((object)$all)); });
    }

    private function load(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        return is_array($raw) ? $raw : [];
    }
}
