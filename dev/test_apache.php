<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_apache.php — how validate() reads httpd -t, using fake httpd scripts (POSIX shell; Windows runs the real httpd.exe).
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
if (DIRECTORY_SEPARATOR === '\\') { echo "skip (POSIX only)\n"; exit(0); }
$dir = sys_get_temp_dir() . '/tr_ap_' . getmypid(); mkdir("$dir/bin", 0777, true);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function fake(string $dir, int $code, string $out): ApacheManager {
    file_put_contents("$dir/bin/httpd", "#!/bin/sh\ncat <<'EOT'\n$out\nEOT\nexit $code\n"); chmod("$dir/bin/httpd", 0755);
    return new ApacheManager("$dir/bin/httpd");
}
check(fake($dir, 0, 'Syntax OK')->validate()['ok'] === true, 'exit 0 → ok');
check(fake($dir, 1, "AH00526: Syntax error on line 12 of /x/httpd-vhosts.conf:\nInvalid command 'Foo'")->validate()['ok'] === false, 'syntax error → not ok');
check(fake($dir, 1, "AH02965: Child: Unable to retrieve my generation from the parent")->validate()['ok'] === true, 'the known mpm_winnt false alarm alone is tolerated');
check(fake($dir, 1, "AH02965: Child: Unable to retrieve my generation from the parent\nAH00526: Syntax error on line 3 of x.conf")->validate()['ok'] === false, 'false alarm + a real error → not ok');
check(fake($dir, 1, "AH00526: Syntax error: cannot read file in parent directory /etc/foo")->validate()['ok'] === false, 'a real error that mentions "parent" is NOT swallowed');
check(fake($dir, 1, "AH00526: bad generation setting on line 4")->validate()['ok'] === false, 'a real error that mentions "generation" is NOT swallowed');
check(fake($dir, 1, "Syntax OK\nbut something is wrong")->validate()['ok'] === false, 'non-zero exit with "Syntax OK" text is still a failure');
$vm = new VhostManager("$dir/v.conf", "$dir/b"); file_put_contents("$dir/v.conf", "original\n");
$ap = fake($dir, 1, 'AH00526: Syntax error'); $vm->addBlock('a.example.com', '/tmp', false);
$r = $ap->validateAndReload($vm);
check($r['ok'] === false && trim(file_get_contents("$dir/v.conf")) === 'original', 'failed validation restores the previous vhosts file');
exec('rm -rf ' . escapeshellarg($dir));
exit(empty($GLOBALS['failed']) ? 0 : 1);
