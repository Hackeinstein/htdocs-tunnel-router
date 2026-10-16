<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_bulk.php — bulk actions: per-item results, one Apache reload for the whole batch.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

class CountingApache extends ApacheManager {
    public int $reloads = 0;
    public function validate(): array { return ['ok' => true, 'output' => '', 'skipped' => false]; }
    public function reload(): array { $this->reloads++; return ['ok' => true]; }
}
$dir = sys_get_temp_dir() . '/tr_bulk_' . getmypid();
mkdir("$dir/backups", 0777, true); file_put_contents("$dir/vhosts.conf", '');
file_put_contents("$dir/config.json", json_encode(['paths' => ['vhosts_file' => "$dir/vhosts.conf", 'apache_bin' => "$dir/x"]]));
Config::init($dir);

function check(bool $ok, string $what): void { echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

$state = new State($dir); $vm = new VhostManager("$dir/vhosts.conf", "$dir/backups"); $ap = new CountingApache("$dir/x");
$svc = new RouteService($state, new ActivityLog($dir), $vm, $ap, null);
foreach (['a.x.com', 'b.x.com', 'c.x.com'] as $h) { $vm->addBlock($h, '/tmp', false); $state->upsertRoute(['hostname' => $h, 'enabled' => true, 'zone_id' => 'z', 'project' => 'p']); }

check(RouteService::slug('My_Site 2') === 'my-site-2' && RouteService::slug('--A--') === 'a', 'slug');

$r = $svc->bulk('disable', ['hostnames' => ['a.x.com', 'b.x.com', 'nope.x.com']]);
check($r[0]['ok'] && $r[1]['ok'] && !$r[2]['ok'], 'disable: two succeed, unknown host reported');
check($ap->reloads === 1, 'disable: exactly one Apache reload for the batch (got ' . $ap->reloads . ')');
check($state->findRoute('a.x.com')['enabled'] === false && $state->findRoute('c.x.com')['enabled'] === true, 'disable: only the chosen routes changed');

$ap->reloads = 0;
$r = $svc->bulk('disable', ['hostnames' => ['a.x.com']]);
check($r[0]['ok'] && $ap->reloads === 0, 'disable of an already-disabled route is a no-op, no reload');

$r = $svc->bulk('expiry', ['hostnames' => ['a.x.com', 'c.x.com'], 'in' => 7200]);
check($r[0]['ok'] && $r[1]['ok'] && $state->findRoute('c.x.com')['expires_at'] > time() + 7000, 'expiry set on both');
$r = $svc->bulk('expiry', ['hostnames' => ['c.x.com'], 'extend' => 3600]);
check($state->findRoute('c.x.com')['expires_at'] > time() + 10600, 'expiry extended relative to each route');
$r = $svc->bulk('expiry', ['hostnames' => ['c.x.com'], 'never' => true]);
check($state->findRoute('c.x.com')['expires_at'] === null, 'expiry removed');

$r = $svc->bulk('detach', ['hostnames' => ['a.x.com']]);
check(!$r[0]['ok'] && $state->findRoute('a.x.com') !== null, 'detach without Cloudflare fails per item and keeps the route');

$r = $svc->bulk('attach', ['projects' => ['p1', 'P_1'], 'zone_id' => 'z', 'pattern' => '{project}.dev']);
check(!$r[0]['ok'] && !$r[1]['ok'], 'attach without Cloudflare reports each item');
foreach ([['attach', ['projects' => ['x'], 'pattern' => 'nope']], ['detach', []], ['wat', []]] as [$a, $b]) {
    try { $svc->bulk($a, $b); check(false, "bad request '$a' rejected"); } catch (RouteException) { check(true, "bad request '$a' rejected"); }
}

array_map('unlink', array_merge(glob("$dir/backups/*") ?: [], glob("$dir/*.*") ?: [])); @rmdir("$dir/backups"); @rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
