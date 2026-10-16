<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_install.php — what a clean deployment does on first run, and what would be committed/shipped.
define('APP_ROOT', dirname(__DIR__));
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

// ---- 1. the shippable file set: what git would commit
$list = [];
if (is_dir(APP_ROOT . '/.git')) {
    exec('cd ' . escapeshellarg(APP_ROOT) . ' && git ls-files -co --exclude-standard 2>/dev/null', $list);
    $list = array_values(array_filter($list, fn($f) => is_file(APP_ROOT . '/' . $f)));
    check(count($list) > 20, 'git sees the project files (' . count($list) . ')');
    check(!array_filter($list, fn($f) => str_starts_with($f, 'state/') && $f !== 'state/.htaccess'), 'nothing under state/ would be committed except its .htaccess');
    check(!array_filter($list, fn($f) => preg_match('#(^|/)(config\.yml|\.env|\.remember|\.claude|\.playwright-mcp)#', $f)), 'no local config or tooling folders would be committed');
    $leaks = [];
    foreach ($list as $f) {
        $c = (string)file_get_contents(APP_ROOT . "/$f");
        if (preg_match('/\bcfat_[A-Za-z0-9]{20,}|\bcfut_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|"api_token"\s*:\s*"[A-Za-z0-9_-]{20,}"|-----BEGIN [A-Z ]*PRIVATE KEY-----/', $c)) $leaks[] = $f;
    }
    check(!$leaks, 'no API tokens, keys or credentials in any file that would be committed' . ($leaks ? ': ' . implode(', ', $leaks) : ''));
    check(!array_filter($list, fn($f) => filesize(APP_ROOT . "/$f") > 3_000_000), 'no accidental large files');
}

// ---- 2. a clean deployment: copy the code only, no state/ at all
$tmp = sys_get_temp_dir() . '/tr_inst_' . getmypid(); mkdir("$tmp/htdocs/blog", 0777, true); mkdir("$tmp/app");
foreach (['api.php', 'index.php', 'reload_worker.php'] as $f) copy(APP_ROOT . "/$f", "$tmp/app/$f");
exec('cp -R ' . escapeshellarg(APP_ROOT . '/src') . ' ' . escapeshellarg(APP_ROOT . '/views') . ' ' . escapeshellarg(APP_ROOT . '/assets') . ' ' . escapeshellarg("$tmp/app"));
$port = 19000 + getmypid() % 900;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$tmp/app"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $tmp) { proc_terminate($proc); exec('chmod -R u+w ' . escapeshellarg($tmp) . '; rm -rf ' . escapeshellarg($tmp)); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
function get(string $path, array $h = [], string $method = 'GET', ?string $body = null): array {
    global $port; $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
check(!is_dir("$tmp/app/state"), '(precondition) the clean copy has no state/ folder');
$r = get('/index.php');
check($r['code'] === 302 && str_contains($r['head'], 'page=settings'), 'first visit → redirected to Settings');
check(is_dir("$tmp/app/state/backups") && trim((string)@file_get_contents("$tmp/app/state/.htaccess")) === 'Require all denied', 'state/ and state/backups were created on their own, and protected');
$r = get('/index.php?page=settings&first_run=1');
check($r['code'] === 200 && str_contains($r['body'], 'Welcome') && str_contains($r['body'], 'Access') && str_contains($r['body'], 'Apache reload'), 'the first-run Settings page renders');
check(get('/index.php?page=dashboard')['code'] === 302, 'other pages stay behind Settings until configured');

$html = get('/index.php?page=settings&first_run=1')['body'];
preg_match_all('#(?:href|src)="(assets/[^"]+)"#', $html, $am); $bad = [];
foreach (array_unique($am[1]) as $a) { $r = get('/' . $a); if ($r['code'] !== 200 || strlen($r['body']) < 100) $bad[] = $a; }
check(count($am[1]) >= 3 && !$bad, 'every asset the page references (' . count(array_unique($am[1])) . ') is served: ' . ($bad ? 'MISSING ' . implode(', ', $bad) : implode(', ', array_map(fn($a) => explode('?', $a)[0], array_unique($am[1])))));
check(!preg_match('#<script[^>]+src="https?://#', $html), 'the page loads no third-party script on its own (the editor loads Monaco only when a file is opened)');
check(strlen($html) < 40000, 'the page HTML is small (' . round(strlen($html) / 1024, 1) . ' KB: the shared script and styles are cached files, not inlined)');

$payload = json_encode(['cloudflare' => ['api_token' => 'abc_DEF-123', 'account_id' => '', 'tunnel_id' => '3fd144bc-d272-4001-a8ae-659c708ebeef', 'tunnel_name' => 'T'],
    'paths' => ['htdocs' => "$tmp/htdocs", 'vhosts_file' => "$tmp/v.conf", 'apache_bin' => "$tmp/none"], 'defaults' => ['canonical' => 'https', 'expiry_action' => 'disable']]);
check(get('/api.php?route=/api/config', ['X-Requested-With: tunnel-router'], 'POST', $payload)['code'] === 200, 'saving the first configuration works on a clean install');
check(is_file("$tmp/app/state/config.json") && get('/index.php?page=dashboard')['code'] === 200, '…and the dashboard opens afterwards');
$cfg = json_decode((string)file_get_contents("$tmp/app/state/config.json"), true);
check($cfg['cloudflare']['api_token'] === 'abc_DEF-123', 'the token is stored in state/config.json (and only there)');
check(!str_contains(get('/index.php?page=settings')['body'], 'abc_DEF-123') && !str_contains(get('/api.php?route=/api/debug')['body'], 'abc_DEF-123'), 'the token never appears in any page or API response');

// ---- 3. privacy guard on a clean install
check(get('/index.php', ['Host: ocxlot.site'])['code'] === 403, 'a public Host name is refused');
check(get('/api.php?route=/api/projects', ['CF-Connecting-IP: 1.2.3.4'])['code'] === 403, 'tunnel traffic is refused (API)');
check(get('/index.php', ['X-Forwarded-For: 8.8.8.8'])['code'] === 403, 'proxied traffic is refused (UI)');

// ---- 4. an unwritable state folder gives a clear message, not a blank page or a stack trace
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod("$tmp/app/state", 0555);
    $r = get('/index.php?page=dashboard');
    check($r['code'] === 500 && str_contains($r['body'], 'not writable') && !str_contains($r['body'], 'Stack trace'), 'read-only state/ → a readable explanation');
    check(str_contains(get('/api.php?route=/api/projects')['body'], 'not writable'), '…same from the API');
    chmod("$tmp/app/state", 0755);
}
exit(empty($GLOBALS['failed']) ? 0 : 1);
