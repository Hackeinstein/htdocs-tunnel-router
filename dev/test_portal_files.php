<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_portal_files.php — a portal user's file operations: permissions, file-type rules, quotas, hidden/locked files.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function denied(callable $f, ?int $code = null): bool { try { $f(); } catch (RouteException $e) { return $code === null || $e->getCode() === $code; } return false; }

$d = sys_get_temp_dir() . '/tr_pf_' . getmypid(); $site = "$d/htdocs/blog"; $other = "$d/htdocs/shop";
foreach ([$site . '/css', $site . '/.git', $other, "$d/state", "$d/app"] as $x) mkdir($x, 0777, true);
file_put_contents("$site/index.html", '<h1>hi</h1>'); file_put_contents("$site/css/a.css", 'a{}'); file_put_contents("$site/config.php", '<?php $pw="secret";'); file_put_contents("$site/.env", 'KEY=1'); file_put_contents("$site/.git/HEAD", 'x'); file_put_contents("$site/dump.sql", 'x');
file_put_contents("$other/private.txt", 'shop secret');
$mk = function (array $grant, bool $code = false, int $maxUp = 1048576, int $limit = 5242880) use ($site, $d, &$log) {
    $log = new PortalLog("$d/state");
    return new PortalFiles(new Files($site, "$d/app", "$d/state", new NullActivityLog("$d/state")), ['username' => 'alice', 'code_files' => $code], $grant, 'blog.example.com', $site, $log, '1.2.3.4', $maxUp, $limit, null, 'copy');   // 'copy' stands in for move_uploaded_file in the CLI
};
$all = ['upload' => true, 'edit' => true, 'delete' => true]; $view = ['upload' => false, 'edit' => false, 'delete' => false];
$ordinary = $mk($all);

// ---- what a user sees
$names = array_column($ordinary->list(''), 'name');
check(in_array('index.html', $names) && in_array('config.php', $names) && !in_array('.git', $names), 'listing shows the site but hides .git');
$byName = array_column($ordinary->list(''), null, 'name');
check($byName['config.php']['locked'] === true && $byName['.env']['locked'] === true && $byName['dump.sql']['locked'] === true && $byName['index.html']['locked'] === false, 'programs, secrets and dumps are listed as locked');
check(!isset($byName['index.html']['perms']) && !isset($byName['index.html']['link']), 'listings carry no server details (permissions, links)');
check($ordinary->read('index.html')['content'] === '<h1>hi</h1>', 'an ordinary file can be read');
foreach (['config.php', '.env', 'dump.sql'] as $f) check(denied(fn() => $ordinary->read($f), 403) && denied(fn() => $ordinary->file($f), 403), "reading/downloading $f is refused (it would reveal the site's secrets)");
check(denied(fn() => $ordinary->list('.git'), 404) && denied(fn() => $ordinary->read('.git/HEAD'), 404), '.git cannot be entered');

// ---- sandbox
foreach (['../shop/private.txt', '../../etc/passwd', 'css/../../shop/private.txt', "css\0x", 'C:/Windows/win.ini'] as $p) check(denied(fn() => $ordinary->read($p)), 'cannot escape the site folder: ' . json_encode($p));
check(denied(fn() => $ordinary->write('../shop/x.txt', 'x', null)) && !file_exists("$other/x.txt"), 'cannot write into another site');

// ---- permissions
$v = $mk($view);
check($v->read('index.html') !== [] && denied(fn() => $v->write('index.html', 'x', null), 403) && denied(fn() => $v->write('new.txt', 'x', null), 403) && denied(fn() => $v->mkdir('f'), 403) && denied(fn() => $v->rename('index.html', 'b.html'), 403) && denied(fn() => $v->delete(['index.html']), 403), 'view-only user can read but not create, change, rename or delete');
$up = $mk(['upload' => true, 'edit' => false, 'delete' => false]);
check($up->write('fresh.txt', 'new', null)['size'] === 3 && denied(fn() => $up->write('index.html', 'changed', null), 403) && file_get_contents("$site/index.html") === '<h1>hi</h1>', 'upload-only user can add new files but not overwrite existing ones');
$ed = $mk(['upload' => false, 'edit' => true, 'delete' => false]);
check($ed->write('index.html', '<h1>edited</h1>', null)['size'] === 15 && denied(fn() => $ed->write('brand-new.txt', 'x', null), 403), 'edit-only user can change existing files but not create new ones');
check($ordinary->rename('fresh.txt', 'renamed.txt') === null && is_file("$site/renamed.txt"), 'rename works with the edit permission');

// ---- file-type rules on the way in
foreach (['x.php', 'x.phtml', 'shell.php.jpg', '.htaccess', 'x.exe', '.env.local', 'dump2.sql', 'x.ini'] as $n)
    check(denied(fn() => $ordinary->write($n, 'harmless', null), 403) && denied(fn() => $ordinary->create($n), 403) && !file_exists("$site/$n"), "cannot create $n");
check(denied(fn() => $ordinary->rename('renamed.txt', 'renamed.php'), 403) && is_file("$site/renamed.txt"), 'cannot rename a safe file into an executable one');
check(denied(fn() => $ordinary->rename('config.php', 'config.txt'), 403) && is_file("$site/config.php"), 'cannot rename a protected file (that would let them read it)');
check(denied(fn() => $ordinary->write('page.html', '<html><?php system("id"); ?></html>', null), 403) && !file_exists("$site/page.html"), 'script tags hidden in an .html file are refused');
check(denied(fn() => $ordinary->write('config.php', 'x', null), 403) && file_get_contents("$site/config.php") === '<?php $pw="secret";', 'an existing config.php cannot be overwritten');
check(denied(fn() => $ordinary->delete(['config.php']), 403) && is_file("$site/config.php"), 'a protected file cannot be deleted');
mkdir("$site/lib"); file_put_contents("$site/lib/db.php", '<?php'); file_put_contents("$site/lib/ok.txt", 'x');
check(denied(fn() => $ordinary->delete(['lib']), 403) && is_dir("$site/lib"), 'a folder that contains programs cannot be deleted by an ordinary user');
check($ordinary->mkdir('docs') === null && is_dir("$site/docs") && denied(fn() => $ordinary->mkdir('.secret'), 403), 'new folders work; hidden-style names do not');

// ---- trusted ("code files") user
$trusted = $mk($all, true);
check($trusted->write('tool.php', '<?php echo 1;', null)['size'] === 13 && is_file("$site/tool.php") && $trusted->read('config.php')['content'] === '<?php $pw="secret";', 'a trusted user can create and open code files');
check(denied(fn() => $trusted->read('../shop/private.txt')) && denied(fn() => $trusted->write('../shop/x.php', '<?php', null)), 'but is still locked inside the site folder');
check(in_array('.git', array_column($trusted->list(''), 'name')), 'a trusted user sees hidden folders');

// ---- delete goes to the trash
$ordinary->delete(['renamed.txt']);
check(!file_exists("$site/renamed.txt") && count((new Files($site, "$d/app", "$d/state", new NullActivityLog("$d/state")))->trashList()) >= 1, 'deleting moves the file to the site trash (restorable by the owner)');

// ---- uploads
$mkUpload = function (array $items) { $f = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []]; foreach ($items as [$name, $content]) { $tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, $content); $f['name'][] = $name; $f['type'][] = 'x'; $f['tmp_name'][] = $tmp; $f['error'][] = UPLOAD_ERR_OK; $f['size'][] = strlen($content); } return $f; };
$res = $ordinary->upload('', $mkUpload([['pic.png', "\x89PNG data"], ['evil.php', '<?php'], ['page.php.jpg', 'x'], ['ok.txt', 'fine'], ['tricky.png', '<?php system("id");'], ['.htaccess', 'x']]), false);
$by = array_column($res, null, 'id');
check(!empty($by['pic.png']['ok']) && !empty($by['ok.txt']['ok']) && empty($by['evil.php']['ok']) && empty($by['page.php.jpg']['ok']) && empty($by['tricky.png']['ok']) && empty($by['.htaccess']['ok']), 'upload: each file is judged on its own — safe ones land, programs and disguised scripts are rejected');
check(is_file("$site/pic.png") && !file_exists("$site/evil.php") && !file_exists("$site/page.php.jpg") && !file_exists("$site/tricky.png") && !file_exists("$site/.htaccess"), 'rejected uploads never touch the disk');
check(empty($ordinary->upload('', $mkUpload([['ok.txt', 'again']]), false)[0]['ok']) && file_get_contents("$site/ok.txt") === 'fine', 'an upload does not silently replace an existing file');
check(($ordinary->upload('', $mkUpload([['ok.txt', 'replaced']]), true)[0]['ok'] ?? false) === true && file_get_contents("$site/ok.txt") === 'replaced', 'with the edit permission, an explicit overwrite is allowed');
check(denied(fn() => $up->upload('', $mkUpload([['ok.txt', 'x']]), true), 403), 'overwrite needs the edit permission');
$small = $mk($all, false, 100);
check(empty($small->upload('', $mkUpload([['big.txt', str_repeat('x', 200)]]), false)[0]['ok']) && !file_exists("$site/big.txt"), 'a file over the per-file limit is rejected');
$tiny = $mk($all, false, 1048576, 5000);
file_put_contents("$site/filler.bin", str_repeat('z', 4000)); $ru = $tiny->upload('', $mkUpload([['a.txt', str_repeat('x', 600)], ['b.txt', str_repeat('x', 600)]]), false);
check(count(array_filter($ru, fn($r) => $r['ok'])) === 1 && str_contains(json_encode($ru), 'storage limit'), 'the per-site storage limit is enforced across a batch');
check(denied(fn() => $tiny->write('big.txt', str_repeat('x', 3000), null), 413), 'saving a file that would exceed the site limit is refused (413)');
check(denied(fn() => $v->upload('', $mkUpload([['a.txt', 'x']]), false), 403), 'view-only users cannot upload');

// ---- audit
$entries = $log->read(500);
check(count($entries) > 0 && !array_filter($entries, fn($e) => $e['user'] !== 'alice' || $e['host'] !== 'blog.example.com'), 'actions are logged with the user and the site');
check((bool)array_filter($log->read(500, 'alice'), fn($e) => !$e['ok']), 'refused actions are logged too');

// ---- zip: extraction obeys the same rules as uploads
$mkZip = function (string $name, array $entries, ?callable $post = null) use ($site) {
    $z = new ZipArchive(); $z->open("$site/$name", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $n => $c) $z->addFromString($n, $c);
    if ($post) $post($z); $z->close();
};
$mkZip('site.zip', ['page/index.html' => '<p>ok</p>', 'page/css/s.css' => 'b{}', 'page/shell.php' => '<?php system($_GET[1]);', 'page/.env' => 'K=1', 'page/trap.html' => 'x <?php echo 1; ?>', '__MACOSX/._a' => 'x', 'page/notes.txt' => 'n'],
    function ($z) { $z->addFromString('page/link.txt', '/etc/passwd'); $z->setExternalAttributesName('page/link.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16); });
$r = $ordinary->unzip('site.zip', false);
check($r['path'] === 'site' && $r['files'] === 3 && is_file("$site/site/page/index.html") && is_file("$site/site/page/css/s.css") && is_file("$site/site/page/notes.txt"), 'a zip extracts into a folder named after it');
check(!file_exists("$site/site/page/shell.php") && !file_exists("$site/site/page/.env") && !file_exists("$site/site/page/trap.html") && !file_exists("$site/site/__MACOSX") && !file_exists("$site/site/page/link.txt"), 'programs, secrets, hidden items, code hidden inside html, and links are all left out');
check($r['skipped_n'] === 5, 'the skipped items are reported (' . $r['skipped_n'] . ')');
check(denied(fn() => $ordinary->unzip('site.zip', false), 409), 'extracting into an existing folder is refused');
check(denied(fn() => $mk($view)->unzip('site.zip', false), 403), 'view-only users cannot extract');
$r = $ordinary->unzip('site.zip', true);
check($r['files'] === 3 && is_file("$site/page/index.html"), '"extract here" puts the files next to the zip');
check(denied(fn() => $mk(['upload' => true, 'edit' => false, 'delete' => false])->unzip('site.zip', true), 403), 'extract-here over existing files needs edit permission');

$mkZip('evil.zip', ['ok.txt' => 'x', '../escape.txt' => 'pwn']);
check(denied(fn() => $ordinary->unzip('evil.zip', false), 400) && !file_exists("$d/htdocs/escape.txt") && !file_exists("$site/escape.txt") && !is_dir("$site/evil"), '"../" entries abort the extraction and write nothing');
$mkZip('abs.zip', ['/etc/x' => 'x']);
check(denied(fn() => $ordinary->unzip('abs.zip', false), 400), 'absolute paths are refused');
$mkZip('win.zip', ['con.txt' => 'x']);
check(denied(fn() => $ordinary->unzip('win.zip', false), 400), 'reserved Windows names are refused');
$mkZip('big.zip', ['a.txt' => str_repeat('A', 300000), 'b.txt' => str_repeat('B', 300000)]);
check(denied(fn() => $mk($all, false, 1048576, 100000 + (int)array_sum(array_map('filesize', glob("$site/*.*"))))->unzip('big.zip', false), 413), 'an archive that would not fit the site quota is refused');
file_put_contents("$site/notzip.zip", 'not a zip');
check(denied(fn() => $ordinary->unzip('notzip.zip', false)), 'a file that is not really a zip is refused');
check(denied(fn() => $ordinary->unzip('index.html', false)), 'only .zip files can be extracted');
$trusted = $mk($all, true);
$mkZip('code.zip', ['app/run.php' => '<?php echo 1;']);
check($trusted->unzip('code.zip', false)['files'] === 1 && is_file("$site/code/app/run.php"), 'a trusted (code files) user may extract programs');

// ---- zip: hostile names
$mkZip('names.zip', ['evil.php/a.txt' => 'x', 'ok/file.PHP.jpg' => 'x', 'ok/fine.txt' => 'f']);
$r = $ordinary->unzip('names.zip', false);
check($r['files'] === 1 && is_file("$site/names/ok/fine.txt") && !file_exists("$site/names/evil.php") && !file_exists("$site/names/ok/file.PHP.jpg"), 'folders named like programs and double extensions are skipped inside archives');
foreach (['bad1.zip' => 'a.php.', 'bad2.zip' => 'a.php ', 'bad3.zip' => 'a.php:x', 'bad4.zip' => '..\\..\\x.txt'] as $zn => $en) { $mkZip($zn, [$en => 'x']); check(denied(fn() => $ordinary->unzip($zn, false), 400), "archive entry '" . addcslashes($en, "\\") . "' aborts the extraction"); }

// ---- zip download
$tmpz = $ordinary->zip('page');
$zz = new ZipArchive(); $zz->open($tmpz['abs']); $in = []; for ($i = 0; $i < $zz->numFiles; $i++) $in[] = $zz->getNameIndex($i);
check($tmpz['name'] === 'page.zip' && in_array('page/index.html', $in), 'a folder downloads as a zip');
$zz->close(); @unlink($tmpz['abs']);
$tmpz = $ordinary->zip(''); $zz = new ZipArchive(); $zz->open($tmpz['abs']); $in = []; for ($i = 0; $i < $zz->numFiles; $i++) $in[] = $zz->getNameIndex($i);
check(!array_filter($in, fn($n) => preg_match('#(^|/)(config\.php|\.env|dump\.sql|\.git)(/|$)#', $n)), 'a whole-site zip leaves out protected and hidden files');
$zz->close(); @unlink($tmpz['abs']);
check(denied(fn() => $ordinary->zip('config.php'), 403) && denied(fn() => $ordinary->zip('.git'), 404), 'protected files and hidden folders cannot be zipped');

exec('rm -rf ' . escapeshellarg($d));
exit(empty($GLOBALS['failed']) ? 0 : 1);
