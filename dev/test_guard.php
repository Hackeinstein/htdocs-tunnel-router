<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_guard.php — who may talk to the control panel.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
$ok = fn(array $o = [], string $extra = '') => Guard::check($o + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], $extra);

foreach (['localhost', 'localhost:8080', '127.0.0.1', '127.0.0.1:80', 'LOCALHOST', '[::1]', '[::1]:8080'] as $h)
    check($ok(['HTTP_HOST' => $h]) === null, "allowed: Host $h");
check(Guard::check(['REMOTE_ADDR' => '::1', 'HTTP_HOST' => 'localhost']) === null && Guard::check(['REMOTE_ADDR' => '::ffff:127.0.0.1', 'HTTP_HOST' => 'localhost']) === null, 'loopback client addresses (v4, v6, mapped) are fine');

foreach (['ocxlot.site', 'evil.attacker.com', 'localhost.evil.com', 'xlocalhost', '', '127.0.0.1.evil.com', '[::2]'] as $h)
    check($ok(['HTTP_HOST' => $h]) !== null, 'refused: Host ' . json_encode($h));
foreach (['HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'HTTP_CF_RAY' => 'abc', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9', 'HTTP_X_FORWARDED_HOST' => 'a.com', 'HTTP_FORWARDED' => 'for=1.1.1.1', 'HTTP_CDN_LOOP' => 'cloudflared', 'HTTP_VIA' => '1.1 proxy'] as $k => $v)
    check($ok([$k => $v]) !== null, "refused: proxy header $k");
check($ok(['HTTP_HOST' => 'localhost', 'HTTP_CF_RAY' => '']) === null, 'an empty proxy header is ignored');
foreach (['192.168.1.20', '10.0.0.5', '203.0.113.9', '::2', ''] as $a)
    check(Guard::check(['REMOTE_ADDR' => $a, 'HTTP_HOST' => 'localhost']) !== null, 'refused: client address ' . json_encode($a));
check($ok(['HTTP_HOST' => 'router.test'], 'router.test') === null && $ok(['HTTP_HOST' => 'router.test:81'], 'a.test, ROUTER.test') === null, 'a configured extra name works (case-insensitive, port ignored)');
check($ok(['HTTP_HOST' => 'other.test'], 'router.test') !== null, '…and only that one');
check($ok(['HTTP_HOST' => 'router.test', 'HTTP_CF_RAY' => 'x'], 'router.test') !== null, 'an allowed name still cannot be reached through the tunnel');
check(!str_contains((string)$ok(['HTTP_HOST' => "evil<script>"]), '<script>'), 'the refusal message does not echo markup');

// settings validation for the extra names
$base = ['cloudflare' => ['api_token' => 'abc', 'account_id' => '', 'tunnel_id' => '', 'tunnel_name' => ''], 'paths' => ['htdocs' => '/h', 'vhosts_file' => '/v', 'apache_bin' => '/b'], 'defaults' => []];
$d = sys_get_temp_dir() . '/tr_g_' . getmypid(); mkdir($d); Config::init($d);
check(Config::clean($base + ['security' => ['allowed_hosts' => ' Router.test, a.test ,router.test ']])['security']['allowed_hosts'] === 'router.test,a.test', 'extra names are normalised and de-duplicated');
foreach (['bad host', 'a/b', 'x;y', '-a.com', 'a..b', str_repeat('a', 64) . '.com'] as $h) { try { Config::clean($base + ['security' => ['allowed_hosts' => $h]]); check(false, "rejected host $h"); } catch (RouteException) { check(true, 'rejected host ' . json_encode($h)); } }
rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
