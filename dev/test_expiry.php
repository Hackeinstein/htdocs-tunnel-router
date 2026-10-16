<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_expiry.php — checks expiry parsing and the sweep against a throwaway state dir.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

$dir = sys_get_temp_dir() . '/tr_test_' . getmypid();
mkdir("$dir/backups", 0777, true);
file_put_contents("$dir/vhosts.conf", "");
file_put_contents("$dir/config.json", json_encode(['paths' => ['vhosts_file' => "$dir/vhosts.conf", 'apache_bin' => "$dir/none"]]));
Config::init($dir);

function check(bool $ok, string $what): void { echo ($ok ? "ok   " : "FAIL ") . $what . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function throws(callable $f): bool { try { $f(); } catch (RouteException) { return true; } return false; }

// --- resolveExpiry
$now = time();
check(RouteService::resolveExpiry(['never' => true]) === null, 'never → null');
check(abs(RouteService::resolveExpiry(['in' => 7200]) - ($now + 7200)) <= 1, 'in 2h');
check(abs(RouteService::resolveExpiry(['extend' => 3600], $now + 7200) - ($now + 10800)) <= 1, 'extend adds to current expiry');
check(abs(RouteService::resolveExpiry(['extend' => 3600], $now - 500) - ($now + 3600)) <= 1, 'extend on a lapsed expiry counts from now');
check(RouteService::resolveExpiry([]) === false, 'empty body → false');
check(throws(fn() => RouteService::resolveExpiry(['in' => 60])), 'under 5 min rejected');
check(throws(fn() => RouteService::resolveExpiry(['in' => 400 * 86400])), 'over ~12 months rejected');
check(!throws(fn() => RouteService::resolveExpiry(['in' => 365 * 86400])), '12 months accepted');

// --- sweep (disable mode)
$state = new State($dir); $log = new ActivityLog($dir);
$vm = new VhostManager("$dir/vhosts.conf", "$dir/backups"); $ap = new ApacheManager("$dir/none");
$svc = new RouteService($state, $log, $vm, $ap, null);
foreach (['old.example.com' => $now - 10, 'later.example.com' => $now + 9999, 'never.example.com' => null] as $h => $at) {
    $vm->addBlock($h, '/tmp', false);
    $state->upsertRoute(['hostname' => $h, 'enabled' => true, 'zone_id' => 'z', 'project' => 'p', 'expires_at' => $at]);
}
$res = $svc->sweep();
check(count($res) === 1 && $res[0]['hostname'] === 'old.example.com' && $res[0]['ok'], 'only the lapsed route is swept');
$old = $state->findRoute('old.example.com');
check($old['enabled'] === false && $old['expires_at'] === null && !empty($old['expired_at']), 'lapsed route disabled, expiry cleared, expired_at recorded');
check($state->findRoute('later.example.com')['enabled'] === true, 'future route untouched');
check($svc->sweep() === [], 'second sweep is a no-op');

// --- detach mode without Cloudflare fails loudly, not silently
$c = json_decode(file_get_contents("$dir/config.json"), true); $c['defaults']['expiry_action'] = 'detach';
file_put_contents("$dir/config.json", json_encode($c));
$state->upsertRoute(['hostname' => 'later.example.com', 'expires_at' => $now - 5]);
$res = $svc->sweep();
check(count($res) === 1 && !$res[0]['ok'], 'detach without CF reports failure');
check($state->findRoute('later.example.com') !== null, 'failed detach keeps the route');

array_map('unlink', array_merge(glob("$dir/backups/*") ?: [], glob("$dir/*.*") ?: [])); @rmdir("$dir/backups"); @rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
