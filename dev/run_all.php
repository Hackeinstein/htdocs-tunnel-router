<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/run_all.php — runs every dev/test_*.php and prints a summary. Exit code 1 if anything fails.
$total = $failed = 0; $bad = [];
foreach (glob(__DIR__ . '/test_*.php') as $t) {
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off ' . escapeshellarg($t) . ' 2>&1', $out, $rc);
    $ok = count(preg_grep('/^ok /', $out)); $f = count(preg_grep('/^FAIL /', $out));
    $total += $ok + $f; $failed += $f;
    printf("%-24s %3d checks  %s\n", basename($t), $ok + $f, $f || $rc ? 'FAILED' : 'ok');
    if ($f || $rc) { $bad[] = basename($t); foreach (preg_grep('/^FAIL |Fatal|Warning/', $out) as $l) echo "    $l\n"; }
}
echo "\n$total checks, $failed failed" . ($bad ? ' (' . implode(', ', $bad) . ')' : '') . "\n";
exit($bad ? 1 : 0);
