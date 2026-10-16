<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_files.php — the file sandbox: traversal, symlinks, the tool's own folder, trash, versions, zip safety.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

$base = sys_get_temp_dir() . '/tr_files_' . getmypid();
foreach (["$base/htdocs/site", "$base/htdocs/tunnel-router/state", "$base/outside", "$base/state"] as $d) mkdir($d, 0777, true);
file_put_contents("$base/htdocs/site/index.php", "<?php echo 1;");
file_put_contents("$base/htdocs/tunnel-router/state/config.json", '{"token":"SECRET"}');
file_put_contents("$base/outside/secret.txt", 'top secret');
symlink("$base/outside", "$base/htdocs/site/escape");
symlink("$base/htdocs/site", "$base/htdocs/alias");

function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function bad(callable $f, int $code = 0): bool { try { $f(); } catch (RouteException $e) { return !$code || $e->getCode() === $code; } return false; }

$fs = new Files("$base/htdocs", "$base/htdocs/tunnel-router", "$base/state", new ActivityLog("$base/state"));

foreach (['../outside', 'site/../../outside/secret.txt', "site/a\0b", 'C:/Windows', 'site/con:x', 'site/trail.', '..\\outside'] as $p)
    check(bad(fn() => $fs->resolve($p, false)), 'rejects ' . json_encode($p));
check(bad(fn() => $fs->read('site/escape/secret.txt')), 'symlink pointing outside htdocs is refused');
check(bad(fn() => $fs->write('site/escape/new.txt', 'x', null)), 'cannot write through an escaping symlink');
check(bad(fn() => $fs->read('tunnel-router/state/config.json')), 'the tool\'s own folder is off limits (read)');
check(bad(fn() => $fs->write('tunnel-router/pwn.php', 'x', null)), '…and (write)');
check(bad(fn() => $fs->delete(['tunnel-router'])), '…and (delete)');
check(!in_array('tunnel-router', array_column($fs->list(''), 'name'), true), 'the tool\'s folder is hidden from listings');
check(in_array('site', array_column($fs->list(''), 'name'), true), 'normal folders are listed');
check(bad(fn() => $fs->delete([''])), 'cannot delete the htdocs root');
check($fs->read('/site/index.php')['content'] === '<?php echo 1;', 'a leading slash means relative to htdocs, not the filesystem');

// editing + versions + conflicts
$r = $fs->read('site/index.php');
$w = $fs->write('site/index.php', '<?php echo 2;', $r['mtime']);
check(file_get_contents("$base/htdocs/site/index.php") === '<?php echo 2;', 'save writes the file');
check(count($fs->versions('site/index.php')) === 1, 'previous version kept');
check($fs->versionContent('site/index.php', $fs->versions('site/index.php')[0]['id']) === '<?php echo 1;', 'version holds the old content');
touch("$base/htdocs/site/index.php", time() + 50);
check(bad(fn() => $fs->write('site/index.php', 'x', $w['mtime']), 409), 'stale mtime → 409 conflict');
check(!bad(fn() => $fs->write('site/index.php', 'ok', null)), 'no mtime = force save');
for ($i = 0; $i < 14; $i++) { usleep(1100000 * 0 + 1); $fs->write('site/index.php', "v$i", null); }
check(count($fs->versions('site/index.php')) <= Files::KEEP_VERSIONS, 'versions capped');
file_put_contents("$base/htdocs/site/bin.dat", "a\0b");
check($fs->read('site/bin.dat')['editable'] === false, 'binary files are not opened as text');
check(bad(fn() => $fs->versionContent('site/index.php', '../../x')), 'version id traversal rejected');

// create / rename / copy
$fs->create('site/new.txt'); check(bad(fn() => $fs->create('site/new.txt')), 'create refuses to overwrite');
$fs->mkdir('site/dir'); $fs->rename('site/new.txt', 'site/dir/moved.txt');
check(is_file("$base/htdocs/site/dir/moved.txt"), 'rename/move works');
check(bad(fn() => $fs->rename('site', 'site/dir/inside')), 'cannot move a folder into itself');
check(bad(fn() => $fs->rename('site/dir/moved.txt', 'site/index.php')), 'rename will not clobber');
$fs->copy('site/dir', 'site/dir2'); check(is_file("$base/htdocs/site/dir2/moved.txt"), 'copy folder');
check(bad(fn() => $fs->copy('site', 'site/copy')), 'cannot copy a folder into itself');

// trash
$ids = $fs->delete(['site/dir2']);
check(!file_exists("$base/htdocs/site/dir2") && count($fs->trashList()) === 1, 'delete moves to trash');
$fs->mkdir('site/dir2');
check(bad(fn() => $fs->trashRestore($ids[0])), 'restore refuses to overwrite');
$fs->delete(['site/dir2']); // the new empty one
$fs->trashRestore($fs->trashList()[1]['id'] ?? $ids[0]);
check(bad(fn() => $fs->trashRestore('../../etc')), 'trash id traversal rejected');
$fs->delete(['alias']);
check(is_link("$base/htdocs/site") === false && is_dir("$base/htdocs/site"), 'deleting a symlink removes the link, not its target');
check($fs->trashEmpty() >= 1 && $fs->trashList() === [], 'empty trash');

// zip / unzip
$tmp = $fs->zip(['site/dir']); $z = new ZipArchive; $z->open($tmp);
check($z->locateName('dir/moved.txt') !== false, 'zip contains folder contents');
$z->close(); unlink($tmp);
$evil = "$base/htdocs/evil.zip"; $z = new ZipArchive; $z->open($evil, ZipArchive::CREATE); $z->addFromString('../escaped.txt', 'x'); $z->close();
check(bad(fn() => $fs->unzip('evil.zip')) && !file_exists("$base/htdocs/escaped.txt"), 'zip-slip archive refused');
$good = "$base/htdocs/good.zip"; $z = new ZipArchive; $z->open($good, ZipArchive::CREATE); $z->addFromString('a/b.txt', 'hi'); $z->close();
$fs->unzip('good.zip'); check(file_get_contents("$base/htdocs/good/a/b.txt") === 'hi', 'normal zip extracts into its own folder');
check(bad(fn() => $fs->unzip('good.zip')), 'unzip will not merge into an existing folder');

// ---- per-site scope: a Files rooted at one project cannot see its sibling
mkdir("$base/htdocs/siteA"); mkdir("$base/htdocs/siteB"); file_put_contents("$base/htdocs/siteA/index.php", 'A'); file_put_contents("$base/htdocs/siteB/index.php", 'B');
$A = new Files("$base/htdocs/siteA", "$base/htdocs/tunnel-router", "$base/state", new ActivityLog("$base/state"));
$B = new Files("$base/htdocs/siteB", "$base/htdocs/tunnel-router", "$base/state", new ActivityLog("$base/state"));
check($A->read('index.php')['content'] === 'A' && $B->read('index.php')['content'] === 'B', 'each site root sees its own files');
check(bad(fn() => $A->read('../siteB/index.php')) && bad(fn() => $A->write('../siteB/x.php', 'x', null)), 'a site cannot reach a sibling site');
$A->write('index.php', 'A2', null); $B->write('index.php', 'B2', null);
check(count($A->versions('index.php')) === 1 && $A->versionContent('index.php', $A->versions('index.php')[0]['id']) === 'A', 'version history is per site (same file name, different sites)');
$A->delete(['index.php']);
check(count($A->trashList()) === 1 && $B->trashList() === [], 'trash only shows the site it came from');
check(bad(fn() => $B->trashRestore($A->trashList()[0]['id']), 404), 'a site cannot restore another site\'s trash item');
$A->trashRestore($A->trashList()[0]['id']); check(file_get_contents("$base/htdocs/siteA/index.php") === 'A2', 'restore into the right site');

// ---- permissions
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    check($B->chmod('index.php', '600') === '600' && decoct(fileperms("$base/htdocs/siteB/index.php") & 0777) === '600', 'chmod 600');
    check($B->list('')[0]['perms'] === '600', 'perms shown in listings');
}
foreach (['4755', '999', '75', 'rwx', '0644x', '-1'] as $m) check(bad(fn() => $B->chmod('index.php', $m)), 'chmod rejects ' . json_encode($m));
check(bad(fn() => $B->chmod('', '755')), 'chmod refuses the site root');

// ---- compress
mkdir("$base/htdocs/siteB/assets"); file_put_contents("$base/htdocs/siteB/assets/a.css", 'x');
$zip = $B->compress('', ['index.php', 'assets'], 'bundle');
check($zip === 'bundle.zip' && is_file("$base/htdocs/siteB/bundle.zip"), 'compress creates the archive in place');
$z = new ZipArchive; $z->open("$base/htdocs/siteB/bundle.zip");
check($z->locateName('assets/a.css') !== false && $z->locateName('index.php') !== false && $z->locateName('bundle.zip') === false, 'archive holds the items and not itself');
$z->close();
check(bad(fn() => $B->compress('', ['index.php'], 'bundle.zip')), 'compress will not overwrite');
foreach (['../evil', 'a/b', '.hidden', 'x;y'] as $n) check(bad(fn() => $B->compress('', ['index.php'], $n)), 'compress rejects name ' . json_encode($n));
check(bad(fn() => $B->compress('', ['../siteA/index.php'], 'leak')), 'compress cannot pull in files from another site');

exec('rm -rf ' . escapeshellarg($base));
exit(empty($GLOBALS['failed']) ? 0 : 1);
