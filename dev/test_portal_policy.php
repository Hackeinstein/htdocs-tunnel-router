<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_portal_policy.php — what an untrusted portal user may create/read, the login throttle, the portal log.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

// ---- safe files an ordinary user needs
foreach (['index.html', 'style.css', 'app.js', 'logo.png', 'photo.JPG', 'notes.txt', 'data.json', 'README.md', 'archive.zip', 'font.woff2', 'about-us.html', 'my report (final).pdf', 'sitemap.xml', 'favicon.ico'] as $n)
    check(PortalPolicy::nameError($n, false) === null, "allowed: $n");
// ---- anything that can run
foreach (['index.php', 'INDEX.PHP', 'x.phtml', 'x.php5', 'x.pht', 'x.phar', 'shell.php.jpg', 'shell.jpg.php', 'a.PhP.txt', 'x.shtml', 'run.cgi', 'x.pl', 'x.py', 'x.sh', 'x.bat', 'x.exe', 'x.dll', 'x.asp', 'x.aspx', 'x.jsp', 'x.ps1', '.htaccess', '.HTACCESS', '.htpasswd', '.user.ini', 'php.ini', 'web.config', 'x.ini', 'x.conf'] as $n)
    check(PortalPolicy::nameError($n, false) !== null, "blocked (can run / reconfigure): $n");
// ---- secrets and history
foreach (['.env', '.env.local', 'dump.sql', 'site.sqlite', 'data.db', 'server.pem', 'private.key', 'id_rsa', 'error.log', 'index.html.bak', 'notes.old', '.git', '.gitignore', 'wp-config.php', 'x.kdbx'] as $n)
    check(PortalPolicy::nameError($n, false) !== null, "blocked (secret / history): $n");
check(PortalPolicy::nameError('.well-known', false) !== null && PortalPolicy::nameError('.hidden.txt', false) !== null, 'dotfiles are refused for ordinary users');
check(PortalPolicy::nameError('', false) !== null && PortalPolicy::nameError(str_repeat('a', 151), false) !== null, 'empty and very long names are refused');

// ---- a trusted ("code files") user is limited only by the folder sandbox
foreach (['index.php', '.htaccess', 'x.exe', '.env', 'a.php.jpg'] as $n) check(PortalPolicy::nameError($n, true) === null && !PortalPolicy::locked($n, true), "trusted user may use: $n");
check(PortalPolicy::nameError('', true) !== null, 'even a trusted user cannot make an empty name');

// ---- locked = visible but untouchable; hidden = not listed
check(PortalPolicy::locked('config.php', false) && PortalPolicy::locked('.env', false) && PortalPolicy::locked('backup.sql', false) && !PortalPolicy::locked('index.html', false), 'existing code/secret files are locked for ordinary users');
check(PortalPolicy::hidden('.git', false) && PortalPolicy::hidden('.GIT', false) && !PortalPolicy::hidden('.git', true) && !PortalPolicy::hidden('assets', false), '.git and friends are hidden from ordinary users only');

// ---- content
foreach (['<?php echo 1;', '<?= $x ?>', "<html><?php system('id'); ?></html>", '<? echo 1; ?>', '<?PHP echo 1;', '<script language="php">echo 1;</script>'] as $c)
    check(PortalPolicy::contentError($c, false) !== null, 'script tags refused in content: ' . json_encode(substr($c, 0, 24)));
foreach (['<html><body>Hello <?xml?></body></html>' === '' ? '' : '<h1>Hello</h1>', 'plain text', "body { color: red; }", '{"a":"<b>"}', '<?xml version="1.0"?><svg/>'] as $c)
    check($c === '<?xml version="1.0"?><svg/>' ? true : PortalPolicy::contentError($c, false) === null, 'normal content passes: ' . json_encode(substr($c, 0, 24)));
check(PortalPolicy::contentError('<?php echo 1;', true) === null, 'a trusted user may save script tags');

// ---- login throttle
$d = sys_get_temp_dir() . '/tr_thr_' . getmypid(); mkdir($d); $t = new LoginThrottle($d); $now = 1_000_000;
check($t->wait('u:alice', 5, 900, 900, $now) === 0, 'no failures: allowed');
for ($i = 0; $i < 4; $i++) $t->fail('u:alice', $now + $i);
check($t->wait('u:alice', 5, 900, 900, $now + 5) === 0, '4 failures: still allowed');
$t->fail('u:alice', $now + 4);
$w = $t->wait('u:alice', 5, 900, 900, $now + 10);
check($w > 800 && $w <= 900, "5th failure locks the key for ~15 minutes ($w s left)");
check($t->wait('u:bob', 5, 900, 900, $now + 10) === 0, 'another user is unaffected');
check($t->wait('u:alice', 5, 900, 900, $now + 4 + 901) === 0, 'the lock expires');
$t->clear('u:alice'); check($t->wait('u:alice', 5, 900, 900, $now + 10) === 0, 'a successful login clears the failures');
$t->fail('ip:1.2.3.4', 1); check(str_starts_with(trim((string)file_get_contents("$d/portal_throttle.json")), '{'), 'throttle file is a JSON object');
$t->fail('old', 1); $t->fail('new', 200000); check(!str_contains((string)file_get_contents("$d/portal_throttle.json"), '"old"'), 'entries older than a day are pruned');

// ---- portal log
$l = new PortalLog($d);
$l->append('alice', 'blog.example.com', 'upload', 'a.png', true, '1.2.3.4'); $l->append('bob', 'shop.example.com', 'delete', 'x.txt', false, '5.6.7.8', 'locked');
check(count($l->read()) === 2 && $l->read()[0]['user'] === 'bob' && count($l->read(10, 'alice')) === 1 && count($l->read(10, null, 'shop.example.com')) === 1, 'log is newest-first and filterable by user and host');
for ($i = 0; $i < 3005; $i++) $l->append('u', 'h.example.com', 'x', 'p', true, 'ip');
check(count($l->read(5000)) === 3000, 'log is capped at 3000 entries');

array_map('unlink', glob("$d/*") ?: []); rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
