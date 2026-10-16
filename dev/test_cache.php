<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_cache.php — TTL, invalidation by prefix, stale-on-error.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
$d = sys_get_temp_dir() . '/tr_cache_' . getmypid(); mkdir($d);
$c = new Cache($d); $calls = 0; $fn = function () use (&$calls) { return ++$calls; };

check($c->remember('a', 60, $fn) === 1 && $c->remember('a', 60, $fn) === 1 && $calls === 1, 'second read inside the TTL does not recompute');
check((new Cache($d))->remember('a', 60, $fn) === 1 && $calls === 1, '…and it survives into a new request (file-backed)');
$c->remember('dns_x', 60, $fn); $c->remember('dns_y', 60, $fn); $c->remember('keep', 60, $fn);
$c->forget('dns_');
$n = $calls; $c->remember('dns_x', 60, $fn); $c->remember('keep', 60, $fn);
check($calls === $n + 1, 'forget(prefix) drops only the matching entries');
check($c->remember('a', 0, $fn) > 1, 'TTL 0 always recomputes');
$c->put('stale', 'old'); $all = json_decode(file_get_contents("$d/metrics_cache.json"), true); $all['stale']['t'] = time() - 3600; file_put_contents("$d/metrics_cache.json", json_encode($all));
$c2 = new Cache($d);
check($c2->remember('stale', 60, function () { throw new RuntimeException('Cloudflare is down'); }) === 'old', 'Cloudflare failing → the last known value is served instead of an error');
try { $c2->remember('never-cached', 60, function () { throw new RuntimeException('boom'); }); check(false, 'no cache + failure raises'); } catch (RuntimeException) { check(true, 'with nothing cached, the failure is raised'); }
$all = json_decode(file_get_contents("$d/metrics_cache.json"), true); $all['ancient'] = ['t' => time() - 200000, 'v' => 1]; file_put_contents("$d/metrics_cache.json", json_encode($all));
(new Cache($d))->put('x', 1);
check(!isset(json_decode(file_get_contents("$d/metrics_cache.json"), true)['ancient']), 'entries older than a day are pruned');
array_map('unlink', glob("$d/*") ?: []); rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
