<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_monitor.php — alerts on state changes only, debounce, expiry warning, tunnel status, summaries.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

class FakeInsights extends Insights {
    public array $probe = []; public string $tunnel = 'healthy'; public bool $cfFail = false;
    public function __construct() {}
    public function probe(array $hosts): array { return array_intersect_key($this->probe, array_flip($hosts)); }
    public function tunnel(string $id): array { if ($this->cfFail) throw new RuntimeException('auth error'); return ['status' => $this->tunnel]; }
}
$dir = sys_get_temp_dir() . '/tr_mon_' . getmypid(); mkdir($dir);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
$up   = ['code' => 200, 'ms' => 100, 'ok' => true,  'state' => 'up'];
$down = ['code' => 530, 'ms' => 50,  'ok' => false, 'state' => 'tunnel_down'];

$state = new State($dir); $in = new FakeInsights; $al = new Alerts($dir);
$m = new Monitor($state, $in, $al, $dir, 't1');
$state->upsertRoute(['hostname' => 'a.x.com', 'enabled' => true]);
$state->upsertRoute(['hostname' => 'off.x.com', 'enabled' => false]);
$now = 1_000_000; $types = fn(array $a) => array_column($a, 'type');

$in->probe = ['a.x.com' => $up]; 
check($m->run($now) === [], 'healthy start raises nothing');
$in->probe = ['a.x.com' => $down];
check($m->run($now += 120) === [], 'one failed probe is not an alert (debounce)');
check($types($m->run($now += 120)) === ['route_down'], 'second consecutive failure raises route_down');
check($m->run($now += 120) === [], 'still down: no repeat alert');
$in->probe = ['a.x.com' => $up];
check($types($m->run($now += 120)) === ['route_up'], 'recovery raises route_up');
$in->probe = ['a.x.com' => $down]; $m->run($now += 120); $in->probe = ['a.x.com' => $up]; $m->run($now += 120);
check($al->unread() === 2, 'a blip that recovers before debounce raised nothing (still 2 alerts total)');

$state->upsertRoute(['hostname' => 'a.x.com', 'expires_at' => $now + 1800]);
check($types($m->run($now += 120)) === ['expiring'], 'expiry within the hour warns');
check($m->run($now += 120) === [], 'expiry warning not repeated');
$state->upsertRoute(['hostname' => 'a.x.com', 'expires_at' => $now + 1700]);
check($types($m->run($now += 120)) === ['expiring'], 'a changed expiry that is still within the hour warns again');

$in->tunnel = 'down';
check(in_array('tunnel_down', $types($m->run($now += 400)), true), 'tunnel going down raises tunnel_down');
check(!in_array('tunnel_down', $types($m->run($now += 400)), true), 'tunnel still down: no repeat');
$in->tunnel = 'healthy';
check(in_array('tunnel_up', $types($m->run($now += 400)), true), 'tunnel recovery raises tunnel_up');
$in->cfFail = true;
check(in_array('cf_error', $types($m->run($now += 400)), true) && !in_array('cf_error', $types($m->run($now += 400)), true), 'Cloudflare failure alerts once');

// history summary
file_put_contents("$dir/health.json", json_encode(['a.x.com' => [[time() - 100, 1, 100], [time() - 90, 0, 0], [time() - 80, 1, 300], [time() - 8 * 86400, 0, 0]]]));
$s = $m->summary()[0];
check($s['uptime24'] === 66.7 && $s['avg_ms'] === 200 && $s['samples'] === 4, 'summary: 24h uptime and average latency ignore failures/old samples');
check($s['uptime7'] === 66.7, 'summary: 7d window excludes the 8-day-old sample');

$al->markAllRead(); check($al->unread() === 0, 'mark all read');
array_map('unlink', glob("$dir/*") ?: []); rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
