<?php
declare(strict_types=1);

/**
 * Small file cache for Cloudflare reads (state/metrics_cache.json). Cloudflare takes ~1 s per call, so pages that
 * re-fetch the same thing on every click feel slow; this keeps results for a short TTL, lets writers invalidate by
 * prefix, and serves the last known value when Cloudflare is unreachable (marked stale) instead of an error page.
 */
class Cache
{
    private const KEEP_SECS = 86400;                   // entries older than this are dropped; also the limit for stale-on-error
    /** @var array<string, array> per-request copy so one request reads the file once */
    private static array $mem = [];
    private string $file;

    public function __construct(string $stateDir)
    {
        $this->file = rtrim(normalizePath($stateDir), '/') . '/metrics_cache.json';
    }

    /** Cached value, or compute it. A failing computation falls back to an older cached value if there is one. */
    public function remember(string $key, int $ttl, callable $fn): mixed
    {
        $all = $this->read();
        $hit = $all[$key] ?? null;
        if ($hit && time() - $hit['t'] < $ttl) return $hit['v'];
        try {
            $v = $fn();                                 // slow part (network) runs outside any lock
        } catch (Throwable $e) {
            if ($hit && time() - $hit['t'] < self::KEEP_SECS) return $hit['v'];
            throw $e;
        }
        $this->put($key, $v);
        return $v;
    }

    /** A value no older than $ttl seconds, or null. */
    public function get(string $key, int $ttl): mixed
    {
        $hit = $this->read()[$key] ?? null;
        return $hit && time() - $hit['t'] < $ttl ? $hit['v'] : null;
    }

    /** Whatever is cached for the key, however old (up to a day), or null. */
    public function peek(string $key): mixed
    {
        $hit = $this->read()[$key] ?? null;
        return $hit && time() - $hit['t'] < self::KEEP_SECS ? $hit['v'] : null;
    }

    public function put(string $key, mixed $v): void
    {
        withLock($this->file, function () use ($key, $v) {
            $all = $this->load();
            $all[$key] = ['t' => time(), 'v' => $v];
            $all = array_filter($all, fn($e) => time() - $e['t'] < self::KEEP_SECS);
            atomicWrite($this->file, json_encode($all));
            self::$mem[$this->file] = $all;
        });
    }

    /** Drop every entry whose key starts with $prefix (call after anything that changes what it cached). */
    public function forget(string $prefix): void
    {
        withLock($this->file, function () use ($prefix) {
            $all = array_filter($this->load(), fn($k) => !str_starts_with($k, $prefix), ARRAY_FILTER_USE_KEY);
            atomicWrite($this->file, json_encode($all));
            self::$mem[$this->file] = $all;
        });
    }

    public function clear(): void { @unlink($this->file); unset(self::$mem[$this->file]); }

    private function read(): array { return self::$mem[$this->file] ??= $this->load(); }

    private function load(): array
    {
        $raw = is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : null;
        return is_array($raw) ? $raw : [];
    }
}
