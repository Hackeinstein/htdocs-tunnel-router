<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_concurrency.php — parallel writers must not lose each other's updates.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

if (($argv[1] ?? '') === 'worker') {                         // child: hammer the state files
    [$dir, $id] = [$argv[2], (int)$argv[3]];
    $state = new State($dir); $log = new ActivityLog($dir); $alerts = new Alerts($dir);
    for ($i = 0; $i < 25; $i++) {
        $state->upsertRoute(['hostname' => "w$id-$i.example.com", 'enabled' => true]);
        $log->append('test', "w$id-$i", true, 'x');
        $alerts->add('t', 'info', "w$id-$i", 'm');
    }
    exit(0);
}
$dir = sys_get_temp_dir() . '/tr_conc_' . getmypid(); mkdir($dir);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

$W = 6; $procs = [];
for ($i = 0; $i < $W; $i++) $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', $dir, (string)$i], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
foreach ($procs as $p) proc_close($p);

$expect = $W * 25;
$routes = (new State($dir))->loadRoutes(); $log = (new ActivityLog($dir))->read(5000); $al = (new Alerts($dir))->all();
check(count($routes) === $expect, "routes.json: $W parallel writers kept every update (" . count($routes) . "/$expect)");
check(count($log) === $expect, "activity.json: no lost entries (" . count($log) . "/$expect)");
check(count($al) === $expect, "alerts.json: no lost entries (" . count($al) . "/$expect)");
check(json_decode((string)file_get_contents("$dir/routes.json"), true) !== null, 'routes.json is still valid JSON');
exec('rm -rf ' . escapeshellarg($dir));
exit(empty($GLOBALS['failed']) ? 0 : 1);
