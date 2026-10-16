<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_validation.php — what a saved config may contain, and the helpers behind it.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
$dir = sys_get_temp_dir() . '/tr_val_' . getmypid(); mkdir($dir); Config::init($dir);
file_put_contents("$dir/config.json", json_encode(['cloudflare' => ['api_token' => 'SAVED_TOKEN']]));
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function bad(array $over): bool { try { Config::clean(array_replace_recursive(GOOD, $over)); } catch (RouteException) { return true; } return false; }
const GOOD = ['cloudflare' => ['api_token' => 'abc_DEF-123', 'account_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'tunnel_id' => '3fd144bc-d272-4001-a8ae-659c708e0000', 'tunnel_name' => 'home'],
              'paths' => ['htdocs' => 'C:/xampp/htdocs', 'vhosts_file' => 'C:/xampp/apache/conf/extra/httpd-vhosts.conf', 'apache_bin' => 'C:/xampp/apache/bin/httpd.exe'],
              'defaults' => ['www_alias' => true, 'canonical' => 'https', 'expiry_action' => 'detach']];

$c = Config::clean(GOOD);
check($c['defaults']['expiry_action'] === 'detach' && $c['defaults']['www_alias'] === true, 'a good config passes through');
check(Config::clean(array_replace_recursive(GOOD, ['cloudflare' => ['api_token' => '']]))['cloudflare']['api_token'] === 'SAVED_TOKEN', 'blank token keeps the saved one');
check(!isset(Config::clean(GOOD + ['evil' => 'x'])['evil']), 'unknown keys are dropped');
check(bad(['cloudflare' => ['api_token' => "abc\r\nX-Evil: 1"]]), 'newline in token (header injection) rejected');
check(bad(['cloudflare' => ['api_token' => 'has space']]), 'token charset enforced');
check(bad(['cloudflare' => ['account_id' => 'nope']]), 'account id shape enforced');
check(bad(['cloudflare' => ['tunnel_id' => 'not-a-uuid']]), 'tunnel id must be a UUID');
check(bad(['cloudflare' => ['tunnel_name' => "bad\nname"]]), 'control chars in tunnel name rejected');
foreach (['C:/x" & calc & "', 'C:/x|y', 'C:/x;y', 'C:/x`y`', 'C:/x$(y)', "C:/x\ny", 'C:/%PATH%/x'] as $p)
    check(bad(['paths' => ['apache_bin' => $p]]), 'shell metacharacters in apache_bin rejected: ' . json_encode($p));
check(bad(['paths' => ['htdocs' => '']]), 'empty htdocs rejected');
check(bad(['defaults' => ['expiry_action' => 'nuke']]), 'expiry action enum enforced');
check(bad(['defaults' => ['canonical' => 'ftp']]), 'canonical enum enforced');
check(Config::clean(array_replace_recursive(GOOD, ['paths' => ['htdocs' => 'C:/Program Files/xampp/htdocs']]))['paths']['htdocs'] === 'C:/Program Files/xampp/htdocs', 'spaces in paths are fine');

$ap = new ApacheManager('C:/x" & calc & "/httpd.exe');
check($ap->validate()['ok'] === false && $ap->reload()['ok'] === false, 'ApacheManager refuses an unsafe binary path');
check(!sanitizeProjectName('a/b') && !sanitizeProjectName('..') && !sanitizeProjectName("a\0b") && sanitizeProjectName('my site'), 'project name rules');
check(sanitizeHostname('a.example.com') && !sanitizeHostname("a.example.com\nServerAlias evil.com") && !sanitizeHostname('a b.com'), 'hostname rules (no newline injection into vhost)');
try { atomicWrite('/nonexistent-dir-xyz/file', 'x'); check(false, 'atomicWrite error'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Folder does not exist'), 'atomicWrite explains a missing folder'); }
// read-only folder, writable file (the root-owned apache/extra case)
mkdir("$dir/ro"); file_put_contents("$dir/ro/vhosts.conf", 'old'); chmod("$dir/ro", 0555);
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    atomicWrite("$dir/ro/vhosts.conf", 'new');
    check(file_get_contents("$dir/ro/vhosts.conf") === 'new' && !file_exists("$dir/ro/vhosts.conf.tmp"), 'atomicWrite falls back to in-place when only the folder is read-only');
}
chmod("$dir/ro", 0755); unlink("$dir/ro/vhosts.conf"); rmdir("$dir/ro");
array_map('unlink', glob("$dir/*") ?: []); rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
