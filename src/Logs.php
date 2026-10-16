<?php
declare(strict_types=1);

/** Read-only tail of the Apache and PHP logs (fixed names only — no arbitrary paths). */
class Logs
{
    private const CHUNK = 262144; // never read more than 256 KB per call

    private array $paths;

    public function __construct(string $apacheBin, ?string $phpLog = null)
    {
        $root = dirname(dirname(normalizePath($apacheBin)));
        $php  = $phpLog ?? (string)ini_get('error_log');
        $this->paths = [
            'apache_error'  => "$root/logs/error.log",
            'apache_access' => "$root/logs/access.log",
            'php_error'     => $php !== '' ? normalizePath($php) : "$root/../php/logs/php_error_log",
        ];
    }

    public function sources(): array
    {
        $out = [];
        foreach ($this->paths as $k => $p) $out[] = ['name' => $k, 'path' => $p, 'exists' => is_file($p), 'size' => is_file($p) ? (int)filesize($p) : 0];
        return $out;
    }

    /**
     * Last $lines lines (no offset), or everything appended since $offset (live tail).
     * @return array{exists:bool, size:int, offset:int, text:string, reset:bool}
     */
    public function tail(string $name, int $lines = 300, ?int $offset = null): array
    {
        $p = $this->paths[$name] ?? throw new RouteException('Unknown log');
        if (!is_file($p)) return ['exists' => false, 'size' => 0, 'offset' => 0, 'text' => '', 'reset' => false];
        clearstatcache(true, $p);
        $size = (int)filesize($p);
        $fh = fopen($p, 'rb');
        if (!$fh) throw new RouteException('Cannot read the log (permissions?)', 500);
        try {
            $reset = false;
            if ($offset !== null && $offset > $size) { $offset = null; $reset = true; }   // log was rotated/truncated
            if ($offset !== null) {
                $from = max($offset, $size - self::CHUNK);
                fseek($fh, $from);
                return ['exists' => true, 'size' => $size, 'offset' => $size, 'text' => (string)stream_get_contents($fh), 'reset' => false];
            }
            $from = max(0, $size - self::CHUNK);
            fseek($fh, $from);
            $text = (string)stream_get_contents($fh);
            if ($from > 0) $text = substr($text, (int)strpos($text, "\n") + 1);          // drop the partial first line
            $all = explode("\n", rtrim($text, "\r\n"));
            return ['exists' => true, 'size' => $size, 'offset' => $size, 'text' => implode("\n", array_slice($all, -max(1, min($lines, 2000)))), 'reset' => $reset];
        } finally { fclose($fh); }
    }
}
