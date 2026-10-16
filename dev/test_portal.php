<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_portal.php — the public portal over real HTTP: guard, login, throttling, sessions, invites, access, files.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

// ---- a deployment: the code, a state dir, two sites; the web server's document root is public/ — exactly like the portal vhost
$tmp = sys_get_temp_dir() . '/tr_portal_' . getmypid(); $app = "$tmp/app"; $ht = "$tmp/htdocs"; $PH = 'files.example.com';
foreach (["$app/state", "$ht/blog/css", "$ht/shop", "$ht/off", "$ht/nopor"] as $x) mkdir($x, 0777, true);
copy(APP_ROOT . '/api.php', "$app/api.php");
exec('cp -R ' . escapeshellarg(APP_ROOT . '/src') . ' ' . escapeshellarg(APP_ROOT . '/public') . ' ' . escapeshellarg(APP_ROOT . '/assets') . ' ' . escapeshellarg($app));
file_put_contents("$ht/blog/index.html", '<h1>blog</h1>'); file_put_contents("$ht/blog/css/site.css", 'a{}'); file_put_contents("$ht/blog/config.php", '<?php $db_password="hunter2";');
file_put_contents("$ht/blog/logo.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')); file_put_contents("$ht/blog/vector.svg", '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
file_put_contents("$ht/shop/secret.txt", 'shop-only'); file_put_contents("$ht/off/a.txt", 'x'); file_put_contents("$ht/nopor/a.txt", 'x');
$route = fn($h, $p, array $extra = []) => array_merge(['hostname' => $h, 'project' => $p, 'enabled' => true, 'portal_enabled' => true, 'zone_id' => str_repeat('a', 32), 'label' => ucfirst($p)], $extra);
file_put_contents("$app/state/routes.json", json_encode([$route('blog.example.com', 'blog'), $route('shop.example.com', 'shop'), $route('off.example.com', 'off', ['enabled' => false]), $route('nopor.example.com', 'nopor', ['portal_enabled' => false])]));
file_put_contents("$app/state/config.json", json_encode(['cloudflare' => ['api_token' => 'faketoken', 'tunnel_id' => '3fd144bc-d272-4001-a8ae-659c708ebeef'], 'paths' => ['htdocs' => $ht, 'vhosts_file' => "$tmp/v", 'apache_bin' => "$tmp/x"], 'portal' => ['hostname' => $PH, 'upload_mb' => 1, 'site_mb' => 5]]));
Config::init("$app/state"); UserStore::$cost = 4;
$store = new UserStore("$app/state");
$mkUser = function (string $name, bool $code = false) use ($store) { $r = $store->create($name, ucfirst($name), $code); $store->acceptInvite($r['token'], 'correct horse battery'); return $store->byUsername($name); };
$alice = $mkUser('alice'); $bob = $mkUser('bob'); $carl = $mkUser('carl');
$store->grant($alice['id'], 'blog.example.com', true, true, true, null);
$store->grant($alice['id'], 'shop.example.com', false, false, false, time() + 3600);
$store->grant($alice['id'], 'off.example.com', true, true, true, null);          // route disabled → invisible
$store->grant($alice['id'], 'nopor.example.com', true, true, true, null);        // portal switched off for that route → invisible
$store->grant($bob['id'], 'blog.example.com', false, false, false, null);        // view only
$store->grant($carl['id'], 'shop.example.com', true, true, true, time() + 3600);

$port = 20000 + getmypid() % 900;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$app/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $tmp) { proc_terminate($proc); exec('rm -rf ' . escapeshellarg($tmp)); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

/** a browser: keeps cookies, remembers the CSRF token */
class Browser {
    public string $jar; public ?string $csrf = null; public array $lastHeaders = [];
    public function __construct(private int $port, private string $host = 'files.example.com', private array $extra = []) { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    public function req(string $method, string $route, ?array $json = null, array $headers = [], ?array $multi = null, bool $csrf = true): array {
        $ch = curl_init("http://127.0.0.1:{$this->port}/api.php?route=$route");
        $h = array_merge(["Host: {$this->host}"], $this->extra); if ($csrf && $this->csrf) $h[] = 'X-CSRF-Token: ' . $this->csrf; foreach ($headers as $x) $h[] = $x;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
        if ($multi !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multi); elseif ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        $raw = (string)curl_exec($ch); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $this->lastHeaders = array_filter(explode("\r\n", substr($raw, 0, $hs))); $body = substr($raw, $hs);
        return ['code' => $code, 'json' => json_decode($body, true), 'body' => $body, 'head' => implode("\n", $this->lastHeaders)];
    }
    public function session(): array { $r = $this->req('GET', '/session'); $this->csrf = $r['json']['csrf'] ?? null; return $r; }
    public function login(string $u, string $p = 'correct horse battery'): array { $this->session(); $r = $this->req('POST', '/login', ['username' => $u, 'password' => $p]); if (!empty($r['json']['csrf'])) $this->csrf = $r['json']['csrf']; return $r; }
}
$new = fn(string $host = null, array $extra = []) => new Browser($port, $host ?? $PH, $extra);

// ================= who can reach it =================
check($new()->req('GET', '/session')['code'] === 200, 'the portal answers on its own hostname');
check($new('evil.example.com')->req('GET', '/session')['code'] === 404, 'any other hostname is refused');
check($new('localhost:' . $port)->req('GET', '/session')['code'] === 200, 'the admin can test it from this computer (localhost)');
check($new('localhost', ['CF-Connecting-IP: 9.9.9.9'])->req('GET', '/session')['code'] === 404, '"localhost" arriving through the tunnel is refused (cannot be spoofed)');
check($new($PH, ['CF-Connecting-IP: 9.9.9.9', 'CF-Ray: abc', 'X-Forwarded-For: 9.9.9.9'])->req('GET', '/session')['code'] === 200, 'the portal hostname works through the tunnel (that is its job)');
foreach (['/state/config.json', '/state/users.json', '/src/UserStore.php', '/../api.php', '/api.php/../../state/users.json', '/assets/../state/config.json', '/.htaccess'] as $path) {
    $ch = curl_init("http://127.0.0.1:$port$path"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Host: $PH"], CURLOPT_PATH_AS_IS => true]); $b = (string)curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    check($c !== 200 || (!str_contains($b, 'faketoken') && !str_contains($b, '"pw"')), "nothing sensitive is served at $path (HTTP $c)");
}
check(str_contains($new()->req('GET', '/projects')['json']['error'] ?? '', 'Please log in') || ($new()->req('GET', '/projects')['code'] ?? 0) >= 400, 'admin routes simply do not exist on the portal');
$ch = curl_init("http://127.0.0.1:$port/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => ["Host: $PH"]]); $page = (string)curl_exec($ch); curl_close($ch);
check(str_contains($page, "Content-Security-Policy: default-src 'self'") && str_contains($page, 'X-Frame-Options: DENY') && !preg_match('#<script(?![^>]*\bsrc=)[^>]*>\s*\S#', $page), 'the portal page sends a strict CSP, cannot be framed, and has no inline script');
$cfg = json_decode(file_get_contents("$app/state/config.json"), true); $cfg['portal']['hostname'] = ''; file_put_contents("$app/state/config.json", json_encode($cfg));
check($new()->req('GET', '/session')['code'] === 404, 'with the portal switched off, everything is refused');
$cfg['portal']['hostname'] = $PH; file_put_contents("$app/state/config.json", json_encode($cfg));

// ================= login =================
$b = $new(); $r = $b->session();
check($r['json']['user'] === null && strlen($r['json']['csrf']) === 48 && !str_contains($r['body'], 'hash'), 'anonymous session: no user, a CSRF token');
check($b->req('POST', '/login', ['username' => 'alice', 'password' => 'x'], [], null, false)['code'] === 403, 'login without the CSRF token is refused');
check($b->req('GET', '/sites')['code'] === 401, 'files need a login');
$msgs = [];
foreach ([['alice', 'wrong password!'], ['nobody', 'correct horse battery'], ['ALICE', '']] as [$u, $p]) { $x = $b->req('POST', '/login', ['username' => $u, 'password' => $p]); $msgs[] = $x['code'] . ':' . ($x['json']['error'] ?? ''); }
check(count(array_unique($msgs)) === 1 && $msgs[0] === '401:Wrong username or password.', 'wrong password, unknown user and empty password get the identical answer');
$store->update($carl['id'], ['disabled' => true]); $x = $new()->login('carl'); $y = $new()->login('nobody');
check($x['code'] === 401 && $x['json']['error'] === $y['json']['error'], 'a disabled account looks exactly like a wrong password');
$store->update($carl['id'], ['disabled' => false]);
$nopw = $store->create('invitedonly', 'I', false); check($new()->login('invitedonly', 'whatever1234')['code'] === 401, 'an account that has not accepted its invite cannot log in');

$sid1 = null; $br = $new(); $br->session(); preg_match('/tr_portal=([^;]+)/', $br->req('GET', '/session')['head'], $m0); $before = file_get_contents($br->jar);
$r = $br->login('alice');
check($r['code'] === 200 && $r['json']['user']['username'] === 'alice' && !isset($r['json']['user']['pw']), 'correct login works');
$cookieLine = implode("\n", array_filter(explode("\n", file_get_contents($br->jar)), fn($l) => str_contains($l, 'tr_portal')));
check(str_contains($cookieLine, '#HttpOnly_'), 'the session cookie is HttpOnly');
check(str_contains($br->req('GET', '/session')['head'] . $r['head'], 'SameSite=Strict') || str_contains($r['head'], 'SameSite=Strict'), 'the session cookie is SameSite=Strict');
$https = $new($PH, ['CF-Visitor: {"scheme":"https"}']); $x = $https->session(); $y = $https->login('alice');
check(str_contains($x['head'] . $y['head'], 'secure'), 'behind the tunnel (HTTPS at the edge) the cookie is marked Secure');
check($br->req('GET', '/session')['json']['user']['username'] === 'alice', 'the session persists');
check(!str_contains($before, 'tr_portal') || trim(explode("\t", trim(explode("\n", $cookieLine)[0]))[6] ?? '') !== (preg_match('/tr_portal\t([^\n]+)/', $before, $mm) ? $mm[1] : ''), 'the session id changes at login (no session fixation)');
check($br->req('POST', '/logout')['code'] === 200 && $br->req('GET', '/sites')['code'] === 401, 'logout ends the session');

// ================= throttling =================
$t = $new(); $t->session(); $codes = [];
for ($i = 0; $i < 7; $i++) $codes[] = $t->req('POST', '/login', ['username' => 'bob', 'password' => 'wrongwrongwrong'])['code'];
check($codes === [401, 401, 401, 401, 401, 429, 429], 'after 5 failures the account is locked (429): ' . implode(',', $codes));
$locked = $t->req('POST', '/login', ['username' => 'bob', 'password' => 'correct horse battery']);
check($locked['code'] === 429 && ($locked['json']['retry_after'] ?? 0) > 800, 'even the right password is refused while locked');
check($new()->login('alice')['code'] === 200, 'a different user is not affected (until the shared address limit)');
@unlink("$app/state/portal_throttle.json");

// ================= invites =================
$inv = $store->create('dana', 'Dana', false); $i = $new(); $i->session();
check($i->req('GET', '/invite/check&token=' . $inv['token'])['json']['username'] === 'dana' && $i->req('GET', '/invite/check&token=nope')['code'] === 410, 'the invite page knows who is being invited; a wrong token is 410');
check($i->req('POST', '/invite/accept', ['token' => $inv['token'], 'password' => 'short'])['code'] === 400 && $store->checkInvite($inv['token']) !== null, 'a weak password is refused and the invite survives');
$a = $i->req('POST', '/invite/accept', ['token' => $inv['token'], 'password' => 'my own long passphrase']);
$i->csrf = $a['json']['csrf'] ?? $i->csrf;                      // a new session starts at login, with a new CSRF token
check($a['code'] === 200 && $a['json']['user']['username'] === 'dana' && $i->req('GET', '/sites')['code'] === 200, 'accepting the invite sets the password and logs the user in');
check($new()->req('POST', '/invite/accept', ['token' => $inv['token'], 'password' => 'another long passphrase'], [], null, false)['code'] === 403 && $i->req('POST', '/invite/accept', ['token' => $inv['token'], 'password' => 'another long passphrase'])['code'] === 410, 'an invite works once');
check($new()->login('dana', 'my own long passphrase')['code'] === 200, 'the new password works for a normal login');

// ================= which sites a user sees =================
$al = $new(); $al->login('alice'); $s = $al->req('GET', '/sites')['json']['sites']; $hosts = array_column($s, 'host');
check($hosts === ['blog.example.com', 'shop.example.com'], 'alice sees only her valid grants (disabled route and portal-off route are hidden): ' . implode(',', $hosts));
check($s[0]['grant'] === ['upload' => true, 'edit' => true, 'delete' => true] && $s[1]['grant'] === ['upload' => false, 'edit' => false, 'delete' => false] && !str_contains(json_encode($s), $ht) && !isset($s[0]['project']), 'grants are reported; no folder path or project name leaks');
$bo = $new(); $bo->login('bob'); check(array_column($bo->req('GET', '/sites')['json']['sites'], 'host') === ['blog.example.com'], 'bob sees only his one site');
$store->grant($alice['id'], 'shop.example.com', false, false, false, time() + 1); $j = json_decode(file_get_contents("$app/state/users.json"), true); foreach ($j['access'] as &$g) if ($g['user'] === $alice['id'] && $g['host'] === 'shop.example.com') $g['until'] = time() - 5; file_put_contents("$app/state/users.json", json_encode($j));
check(array_column($al->req('GET', '/sites')['json']['sites'], 'host') === ['blog.example.com'] && $al->req('GET', '/fs/list&host=shop.example.com&path=')['code'] === 403, 'an expired grant disappears at once, including its files');

// ================= files =================
$L = fn(Browser $b, string $h, string $p = '') => $b->req('GET', '/fs/list&host=' . $h . '&path=' . urlencode($p));
$list = $L($al, 'blog.example.com'); $names = array_column($list['json']['entries'], 'name');
check($list['code'] === 200 && in_array('index.html', $names) && in_array('config.php', $names), 'alice lists her site');
check(array_column($list['json']['entries'], 'locked', 'name')['config.php'] === true && !str_contains($list['body'], $ht), 'protected files are marked locked; no filesystem paths in answers');
$other = $L($al, 'shop.example.com'); $unknown = $L($al, 'ghost.example.com'); $nopor = $L($al, 'nopor.example.com');
check($other['code'] === 403 && $unknown['code'] === 403 && $nopor['code'] === 403 && $other['json']['error'] === $unknown['json']['error'] && $unknown['json']['error'] === $nopor['json']['error'], 'a site you cannot use gives the same 403 whether it exists or not');
check($al->req('GET', '/fs/read&host=blog.example.com&path=index.html')['json']['content'] === '<h1>blog</h1>', 'reading a normal file works');
$cfgRead = $al->req('GET', '/fs/read&host=blog.example.com&path=config.php');
check($cfgRead['code'] === 403 && !str_contains($cfgRead['body'], 'hunter2'), 'a protected file (config.php) cannot be read — its password never leaves the server');
check($al->req('GET', '/fs/download&host=blog.example.com&path=config.php')['code'] === 403, '…or downloaded');
foreach (['../shop/secret.txt', '../../state/users.json', '..%2f..%2fstate%2fconfig.json', 'css/../../shop/secret.txt'] as $p) { $r = $al->req('GET', '/fs/read&host=blog.example.com&path=' . $p); check($r['code'] >= 400 && !str_contains($r['body'], 'shop-only') && !str_contains($r['body'], 'faketoken'), "cannot escape the site folder: $p"); }
check($al->req('GET', '/fs/list&host=blog.example.com&path=.git')['code'] === 404, 'hidden folders do not exist');

$w = $al->req('POST', '/fs/write', ['host' => 'blog.example.com', 'path' => 'index.html', 'content' => '<h1>new</h1>']);
check($w['code'] === 200 && file_get_contents("$ht/blog/index.html") === '<h1>new</h1>', 'editing a page works');
$st = $al->req('POST', '/fs/write', ['host' => 'blog.example.com', 'path' => 'index.html', 'content' => 'x', 'mtime' => 1]);
check($st['code'] === 409, 'a stale edit is a conflict (409), not a silent overwrite');
check($al->req('POST', '/fs/write', ['host' => 'blog.example.com', 'path' => 'evil.php', 'content' => '<?php system($_GET[0]);'])['code'] === 403 && !file_exists("$ht/blog/evil.php"), 'a PHP file cannot be created');
check($al->req('POST', '/fs/write', ['host' => 'blog.example.com', 'path' => 'page.html', 'content' => '<?php echo 1;'])['code'] === 403, 'script tags in an .html file are refused');
check($al->req('POST', '/fs/mkdir', ['host' => 'blog.example.com', 'path' => 'docs'])['code'] === 200 && is_dir("$ht/blog/docs"), 'new folders work');
check($al->req('POST', '/fs/rename', ['host' => 'blog.example.com', 'from' => 'docs', 'to' => 'docs2'])['code'] === 200 && $al->req('POST', '/fs/rename', ['host' => 'blog.example.com', 'from' => 'docs2', 'to' => 'docs.php'])['code'] === 403, 'rename works, but not into an executable name');
$up = $al->req('POST', '/fs/upload', null, [], ['host' => 'blog.example.com', 'dir' => '', 'files[]' => new CURLFile(tempnam(sys_get_temp_dir(), 'u'), 'text/plain', 'hello.txt')]);
check(($up['json']['results'][0]['ok'] ?? false) === true && is_file("$ht/blog/hello.txt"), 'multipart upload works');
$tmpPhp = tempnam(sys_get_temp_dir(), 'u'); file_put_contents($tmpPhp, '<?php echo 1;');
$up2 = $al->req('POST', '/fs/upload', null, [], ['host' => 'blog.example.com', 'dir' => '', 'files[]' => new CURLFile($tmpPhp, 'image/png', 'avatar.php')]);
check(($up2['json']['results'][0]['ok'] ?? true) === false && !file_exists("$ht/blog/avatar.php"), 'uploading a PHP file is rejected even with an image content-type');
$big = tempnam(sys_get_temp_dir(), 'u'); file_put_contents($big, str_repeat('x', 1500000));
$up3 = $al->req('POST', '/fs/upload', null, [], ['host' => 'blog.example.com', 'dir' => '', 'files[]' => new CURLFile($big, 'text/plain', 'big.txt')]);
check(($up3['json']['results'][0]['ok'] ?? true) === false && !file_exists("$ht/blog/big.txt"), 'the 1 MB per-upload limit (set in this test) is enforced');

$zf = tempnam(sys_get_temp_dir(), 'z'); $zz = new ZipArchive(); $zz->open($zf, ZipArchive::OVERWRITE); $zz->addFromString('p/a.txt', 'A'); $zz->addFromString('p/x.php', '<?php 1;'); $zz->close();
$upz = $al->req('POST', '/fs/upload', null, [], ['host' => 'blog.example.com', 'dir' => '', 'files[]' => new CURLFile($zf, 'application/zip', 'pack.zip')]);
check(!empty($upz['json']['results'][0]['ok']), 'a .zip can be uploaded');
$ex = $al->req('POST', '/fs/unzip', ['host' => 'blog.example.com', 'path' => 'pack.zip', 'here' => false]);
check($ex['code'] === 200 && $ex['json']['files'] === 1 && $ex['json']['skipped_n'] === 1, 'it extracts over HTTP, leaving out the PHP file');
$dz = $al->req('GET', '/fs/zip&host=blog.example.com&path=pack');
check($dz['code'] === 200 && str_starts_with($dz['body'], 'PK') && str_contains($dz['head'], 'attachment'), 'a folder downloads as a zip attachment');
check($new()->req('GET', '/fs/zip&host=blog.example.com&path=')['code'] === 401, 'zip download needs a login');
check($bo->req('POST', '/fs/unzip', ['host' => 'blog.example.com', 'path' => 'pack.zip'])['code'] === 403, 'a view-only user cannot extract');
check($al->req('POST', '/fs/delete', ['host' => 'blog.example.com', 'paths' => ['hello.txt']])['code'] === 200 && !file_exists("$ht/blog/hello.txt"), 'delete works with the permission');
check($al->req('POST', '/fs/delete', ['host' => 'blog.example.com', 'paths' => ['config.php']])['code'] === 403 && is_file("$ht/blog/config.php"), 'a protected file cannot be deleted');

// view-only bob
foreach ([['write', ['path' => 'index.html', 'content' => 'x']], ['mkdir', ['path' => 'd']], ['delete', ['paths' => ['index.html']]], ['rename', ['from' => 'index.html', 'to' => 'z.html']]] as [$op, $body])
    check($bo->req('POST', "/fs/$op", ['host' => 'blog.example.com'] + $body)['code'] === 403, "view-only user: $op is refused");
check($bo->req('GET', '/fs/read&host=blog.example.com&path=index.html')['code'] === 200, 'view-only user can read');

// ---- user content never runs in the portal's origin
$dl = $al->req('GET', '/fs/download&host=blog.example.com&path=index.html');
check(str_contains($dl['head'], 'Content-Disposition: attachment') && str_contains($dl['head'], 'application/octet-stream') && str_contains($dl['head'], "Content-Security-Policy: default-src 'none'; sandbox") && str_contains($dl['head'], 'nosniff'), 'downloads are attachments, sandboxed, nosniff');
$raw = $al->req('GET', '/fs/raw&host=blog.example.com&path=logo.png');
check(str_contains($raw['head'], 'Content-Type: image/png') && str_contains($raw['head'], 'inline') && str_starts_with($raw['body'], "\x89PNG"), 'a raster image may be previewed inline');
$svg = $al->req('GET', '/fs/raw&host=blog.example.com&path=vector.svg');
check(str_contains($svg['head'], 'attachment') && !str_contains($svg['head'], 'image/svg') && str_contains($svg['head'], 'sandbox'), 'an SVG (can carry script) is never shown inline');
$html = $al->req('GET', '/fs/raw&host=blog.example.com&path=index.html');
check(str_contains($html['head'], 'attachment') && !str_contains($html['head'], 'text/html'), 'HTML is never rendered inline from the portal');

// ================= revocation =================
$store->signOutEverywhere($alice['id']);
check($al->req('GET', '/sites')['code'] === 401, '"sign out everywhere" ends existing sessions at once');
$al2 = $new(); $al2->login('alice'); $store->update($alice['id'], ['disabled' => true]);
check($al2->req('GET', '/sites')['code'] === 401, 'disabling a user ends their session at once');
$store->update($alice['id'], ['disabled' => false]); $al3 = $new(); $al3->login('alice'); $store->resetInvite($alice['id']);
check($al3->req('GET', '/sites')['code'] === 401 && $new()->login('alice')['code'] === 401, 'an admin reset kills the session and the old password');
$bo2 = $new(); $bo2->login('bob'); $store->delete($bob['id']);
check($bo2->req('GET', '/sites')['code'] === 401, 'deleting a user ends their session');
$routes = json_decode(file_get_contents("$app/state/routes.json"), true); $c2 = $new(); $c2->login('carl'); $store->grant($carl['id'], 'blog.example.com', true, true, true, null);
$routes[0]['portal_enabled'] = false; file_put_contents("$app/state/routes.json", json_encode($routes));
check($c2->req('GET', '/fs/list&host=blog.example.com&path=')['code'] === 403, 'switching the portal off for a route cuts access immediately');

// ================= the log =================
$log = (new PortalLog("$app/state"))->read(1000);
check(count(array_filter($log, fn($e) => $e['action'] === 'login' && !$e['ok'])) >= 5 && count(array_filter($log, fn($e) => $e['action'] === 'save' && $e['user'] === 'alice')) >= 1 && !str_contains(json_encode($log), 'correct horse battery'), 'logins, failures and edits are logged — never passwords');
exit(empty($GLOBALS['failed']) ? 0 : 1);
