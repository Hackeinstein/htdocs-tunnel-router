<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_route.php — per-route analytics parsing and caching.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
class FakeCf extends CloudflareClient { public int $calls = 0; public array $last = [];
    public function __construct() {}
    public function request(string $m, string $p, array $b = [], array $q = []): array { $this->calls++; $this->last = [$m, $p, $b, $q];
        if ($p === '/graphql') return ['viewer' => ['zones' => [[
            'series' => [['count' => 10, 'dimensions' => ['datetimeHour' => '2026-10-09T10:00:00Z'], 'sum' => ['edgeResponseBytes' => 1000]], ['count' => 5, 'dimensions' => ['datetimeHour' => '2026-10-09T11:00:00Z'], 'sum' => ['edgeResponseBytes' => 500]]],
            'status' => [['count' => 12, 'dimensions' => ['edgeResponseStatus' => 200]], ['count' => 1, 'dimensions' => ['edgeResponseStatus' => 304]], ['count' => 2, 'dimensions' => ['edgeResponseStatus' => 404]], ['count' => 1, 'dimensions' => ['edgeResponseStatus' => 200]]],
            'country' => [['count' => 9, 'dimensions' => ['clientCountryName' => 'US']], ['count' => 6, 'dimensions' => ['clientCountryName' => 'NG']]]]]]];
        return ['result' => [['id' => 'r1', 'type' => 'CNAME', 'content' => 'abc.cfargotunnel.com', 'proxied' => true, 'ttl' => 1], ['id' => 'r2', 'type' => 'TXT', 'content' => 'x']]];
    } }
$d = sys_get_temp_dir() . '/tr_rt_' . getmypid(); mkdir($d); $cf = new FakeCf; $in = new Insights($cf, 'acct', $d);

$r = $in->routeAnalytics('z1', 'blog.example.com');
check($r['totals'] === ['requests' => 15, 'bytes' => 1500] && count($r['series']) === 2 && $r['series'][0]['t'] === '2026-10-09T10:00:00Z', 'hourly series and totals');
check($r['status'] === ['2xx' => 13, '3xx' => 1, '4xx' => 2], 'status codes are grouped into classes and merged');
check(array_keys($r['countries']) === ['US', 'NG'], 'countries sorted by traffic');
check($cf->last[2]['variables']['host'] === 'blog.example.com' && str_contains($cf->last[2]['query'], 'clientRequestHTTPHost:$host'), 'the query is filtered to exactly this hostname (passed as a variable, not interpolated)');
check($cf->calls === 1, 'all three breakdowns come from ONE Cloudflare request');
$in->routeAnalytics('z1', 'blog.example.com'); check($cf->calls === 1, 'the second view within 2 minutes is served from cache');
$in->routeAnalytics('z1', 'other.example.com'); check($cf->calls === 2, 'a different hostname is a different cache entry');
$rec = $in->dnsRecord('z1', 'blog.example.com');
check($rec['type'] === 'CNAME' && $rec['tunnel'] === true && $rec['proxied'] === true, 'DNS record summary picks the CNAME and flags a tunnel target');
array_map('unlink', glob("$d/*") ?: []); rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
