<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_reload.php — the reload worker: strategy order, verification against the Apache log, status file.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
$d = sys_get_temp_dir() . '/tr_rl_' . getmypid(); mkdir("$d/state", 0777, true); mkdir("$d/apache/logs", 0777, true); mkdir("$d/apache/bin", 0777, true);
$log = "$d/apache/logs/error.log"; file_put_contents($log, "[notice] old line resuming normal operations\n");   // an OLD confirmation must not count
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function request(string $d, array $over = []): void {
    file_put_contents("$d/state/reload_request.json", json_encode($over + ['id' => 'abc123', 'requested_at' => time(), 'bin' => "$d/apache/bin/httpd", 'log' => "$d/apache/logs/error.log", 'elevate' => true, 'hard' => false]));
}
$writesLog = fn(string $name, int $code = 0) => ['name' => $name, 'run' => function () use ($name, $code) { global $log; file_put_contents($log, "[mpm_winnt:notice] Apache configured -- resuming normal operations\n", FILE_APPEND); return ['code' => $code, 'output' => "$name ran"]; }];
$silent = fn(string $name, int $code = 0, string $out = '') => ['name' => $name, 'run' => fn() => ['code' => $code, 'output' => $out]];
$w = new ReloadWorker("$d/state");

request($d);
$r = $w->run([$writesLog('graceful')], 2, 0);
check($r['state'] === 'ok' && count($r['attempts']) === 1 && $r['attempts'][0]['confirmed'] && $r['attempts'][0]['verified_by'] === 'apache error log', 'strategy 1 works → ok after one attempt, verified from the log');
check(ReloadWorker::status("$d/state")['state'] === 'ok', 'status file holds the result');

request($d);
$r = $w->run([$silent('graceful', 0), $writesLog('elevated')], 1, 0);
check($r['state'] === 'ok' && count($r['attempts']) === 2 && !$r['attempts'][0]['confirmed'], 'exit code 0 WITHOUT a log line is not trusted: falls through to the next strategy');

request($d);
$r = $w->run([$silent('graceful', 1, 'The requested operation has failed!'), $silent('elevated', 1, 'canceled by the user')], 1, 0);
check($r['state'] === 'failed' && count($r['attempts']) === 2 && str_contains($r['attempts'][0]['output'], 'operation has failed'), 'all strategies fail → failed, with each command\'s output kept');
check(str_contains($r['detail'], 'Control Panel') && str_contains($r['detail'], 'administrator'), '…and the message tells the user what to do');

request($d, ['log' => null]);
$r = $w->run([$silent('graceful', 0)], 1, 0);
check($r['state'] === 'ok' && $r['attempts'][0]['verified_by'] === 'exit code only', 'no readable log: falls back to the exit code, and says so');

file_put_contents($log, str_repeat("x\n", 50)); request($d);
$r = $w->run([['name' => 'rotating', 'run' => function () use ($log) { file_put_contents($log, "[notice] resuming normal operations\n"); return ['code' => 0, 'output' => '']; }]], 2, 0);
check($r['state'] === 'ok', 'a log that is rotated/truncated during the restart is handled');

unlink("$d/state/reload_request.json");
check($w->run([$silent('x')], 1, 0)['state'] === 'failed', 'no request file → a clear failure, not a crash');

// the real command runner: output capture, exit codes, timeout
class RunProbe extends ReloadWorker { public function go(array $a, int $t = 60): array { return $this->exec($a, $t); } }
$p = new RunProbe("$d/state");
$o = $p->go([PHP_BINARY, '-r', 'fwrite(STDOUT,"out"); fwrite(STDERR,"err"); exit(3);']);
check($o['code'] === 3 && str_contains($o['output'], 'out') && str_contains($o['output'], 'err'), 'exec() captures stdout+stderr and the exit code');
check($p->go(['/definitely/not/a/binary'])['code'] !== 0, 'exec() of a missing binary fails cleanly');
check($p->go([PHP_BINARY, '-r', 'sleep(10);'], 1)['code'] === 124, 'exec() gives up on a hung command');

// ApacheManager::reload writes the request + a pending status and spawns the helper (spawn stubbed here)
file_put_contents("$d/state/config.json", json_encode(['defaults' => ['reload_elevate' => false, 'reload_hard' => true]])); Config::init("$d/state");
file_put_contents("$d/apache/bin/httpd", "#!/bin/sh\n"); chmod("$d/apache/bin/httpd", 0755);
class SpawnStub extends ApacheManager { public int $spawned = 0; public bool $ok = true; protected function spawnWorker(string $s): bool { $this->spawned++; return $this->ok; } }
$ap = new SpawnStub("$d/apache/bin/httpd"); $r = $ap->reload();
$req = json_decode(file_get_contents("$d/state/reload_request.json"), true);
check($r['ok'] && $r['pending'] && $ap->spawned === 1 && $req['elevate'] === false && $req['hard'] === true && $req['log'] === "$d/apache/logs/error.log", 'reload() records the request with the user\'s settings and spawns the helper');
check(ReloadWorker::status("$d/state")['state'] === 'pending' && ReloadWorker::status("$d/state")['id'] === $req['id'], '…and a pending status the UI can show immediately');
$ap->ok = false; $ap->reload();
check(ReloadWorker::status("$d/state")['state'] === 'failed', 'if the helper cannot be started, the status says failed (not silently pending)');
$gone = new SpawnStub("$d/nope"); $gone->reload();
check(ReloadWorker::status("$d/state")['state'] === 'skipped', 'missing Apache binary → "skipped" with a hint, nothing spawned');

// end to end, for real: spawn the actual CLI worker against a fake httpd that "restarts" by logging
file_put_contents("$d/apache/bin/httpd", "#!/bin/sh\necho \"[mpm_winnt:notice] AH00455: Apache configured -- resuming normal operations\" >> \"$d/apache/logs/error.log\"\n"); chmod("$d/apache/bin/httpd", 0755);
$real = new class("$d/apache/bin/httpd") extends ApacheManager { };
$real->reload();
for ($i = 0; $i < 60 && (ReloadWorker::status("$d/state")['state'] ?? '') !== 'ok'; $i++) usleep(250000);
$st = ReloadWorker::status("$d/state");
check($st['state'] === 'ok' && $st['attempts'][0]['confirmed'] === true, 'end to end: the real reload_worker.php process restarts and confirms');

exec('rm -rf ' . escapeshellarg($d));
exit(empty($GLOBALS['failed']) ? 0 : 1);
