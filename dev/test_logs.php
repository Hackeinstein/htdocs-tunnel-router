<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_logs.php — log tail: last N lines, live offset, rotation, fixed names only.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
$d = sys_get_temp_dir() . '/tr_logs_' . getmypid(); mkdir("$d/apache/logs", 0777, true); mkdir("$d/apache/bin");
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
$err = "$d/apache/logs/error.log";
file_put_contents($err, implode("\n", array_map(fn($i) => "line $i", range(1, 50))) . "\n");
$l = new Logs("$d/apache/bin/httpd.exe", "$d/php.log");

$t = $l->tail('apache_error', 5);
check($t['text'] === "line 46\nline 47\nline 48\nline 49\nline 50", 'last 5 lines');
file_put_contents($err, "line 51\nline 52\n", FILE_APPEND);
$n = $l->tail('apache_error', 5, $t['offset']);
check($n['text'] === "line 51\nline 52\n", 'live tail returns only what was appended');
check($l->tail('apache_error', 5, $n['offset'])['text'] === '', 'nothing new → empty');
file_put_contents($err, "fresh\n");
$r = $l->tail('apache_error', 5, $n['offset']);
check($r['reset'] === true && trim($r['text']) === 'fresh', 'rotation detected, restarts from the top');
check($l->tail('apache_access')['exists'] === false, 'missing log reports exists=false');
try { $l->tail('../../etc/passwd'); check(false, 'unknown name rejected'); } catch (RouteException) { check(true, 'unknown name rejected'); }
file_put_contents($err, str_repeat("x\n", 500000));
check(strlen($l->tail('apache_error', 3)['text']) < 20 && substr_count($l->tail('apache_error', 3)['text'], "\n") === 2, 'huge log: only reads the tail');
unlink($err); array_map('unlink', glob("$d/apache/logs/*") ?: []); rmdir("$d/apache/logs"); rmdir("$d/apache/bin"); rmdir("$d/apache"); @rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
