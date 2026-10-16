<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_attack.php — Under Attack remembers and restores the previous level; timer reverts.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

class FakeInsights extends Insights {
    public array $levels = ['z1' => 'high'];
    public function __construct() {}
    public function securityLevel(string $z): string { return $this->levels[$z]; }
    public function setZoneSetting(string $z, string $n, string $v): void { $this->levels[$z] = $v; }
}
$dir = sys_get_temp_dir() . '/tr_atk_' . getmypid(); mkdir($dir);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

$in = new FakeInsights; $a = new AttackMode($in, $dir, new ActivityLog($dir));
$a->enable('z1', 3600);
check($in->levels['z1'] === 'under_attack' && $a->active()['z1']['prev'] === 'high', 'enable remembers previous level');
$a->enable('z1', null);
check($a->active()['z1']['prev'] === 'high' && $a->active()['z1']['revert_at'] === null, 're-enable keeps the original level, clears timer');
check($a->sweep() === [], 'no timer → sweep leaves it on');
$a->enable('z1', 3600);
$st = $a->active(); $st['z1']['revert_at'] = time() - 1; file_put_contents("$dir/attack.json", json_encode($st));
$r = $a->sweep();
check(count($r) === 1 && $r[0]['ok'] && $in->levels['z1'] === 'high' && $a->active() === [], 'lapsed timer restores the previous level');
$in->levels['z2'] = 'under_attack'; $a->enable('z2', null); $a->disable('z2');
check($in->levels['z2'] === 'medium', 'already-on outside the tool restores to medium');
check((function () use ($a) { try { $a->enable('z1', 30); } catch (RouteException) { return true; } return false; })(), 'revert under 5 min rejected');
array_map('unlink', glob("$dir/*") ?: []); rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
