<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_api.php — starts the real app on a temp copy and drives it over HTTP (offline: no Cloudflare calls).
// Also syntax-checks the JavaScript of every page with node, if node is installed.
define('APP_ROOT', dirname(__DIR__));
$tmp = sys_get_temp_dir() . '/tr_api_' . getmypid();
$app = "$tmp/app"; $htdocs = "$tmp/htdocs";
mkdir("$app/state/backups", 0777, true); mkdir("$htdocs/blog/css", 0777, true); mkdir("$htdocs/shop", 0777, true);
foreach (['api.php', 'index.php'] as $f) copy(APP_ROOT . "/$f", "$app/$f");
exec('cp -R ' . escapeshellarg(APP_ROOT . '/src') . ' ' . escapeshellarg(APP_ROOT . '/views') . ' ' . escapeshellarg(APP_ROOT . '/assets') . ' ' . escapeshellarg($app));
file_put_contents("$htdocs/blog/index.html", '<h1>hi</h1>'); file_put_contents("$htdocs/shop/secret.txt", 'shop-secret');
file_put_contents("$htdocs/blog/pic.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$cfg = ['cloudflare' => ['api_token' => 'faketoken', 'account_id' => '', 'tunnel_id' => '3fd144bc-d272-4001-a8ae-659c708ebeef', 'tunnel_name' => 'T'],
        'paths' => ['htdocs' => $htdocs, 'vhosts_file' => "$tmp/vhosts.conf", 'apache_bin' => "$tmp/none"]];
file_put_contents("$app/state/config.json", json_encode($cfg));
file_put_contents("$app/state/routes.json", json_encode([['hostname' => 'blog.example.com', 'project' => 'blog', 'zone_id' => str_repeat('a', 32), 'enabled' => true, 'type' => 'subdomain', 'document_root' => "$htdocs/blog", 'expires_at' => null]]));

$port = 18000 + getmypid() % 1000;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $app], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $tmp) { proc_terminate($proc); exec('rm -rf ' . escapeshellarg($tmp)); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
/** @return array{code:int, json:?array, body:string} */
function req(string $method, string $route, ?array $json = null, array $headers = ['X-Requested-With: tunnel-router'], ?array $multipart = null): array {
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/api.php?route=$route");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    if ($multipart !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    elseif ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $code, 'json' => json_decode($body, true), 'body' => $body];
}

// ---- security & plumbing
$r = req('POST', '/api/fs/mkdir', ['path' => 'x'], []);
check($r['code'] === 403 && !is_dir("$htdocs/x"), 'POST without X-Requested-With is refused (CSRF guard)');
check(req('DELETE', '/api/fs/trash-empty', null, [])['code'] === 403 && req('PUT', '/api/dns/' . str_repeat('a', 32) . '/' . str_repeat('b', 32), [], [])['code'] === 403, 'DELETE/PUT without the header are refused too');
check(req('GET', '/api/nope')['code'] === 404 && req('GET', '/api/nope')['json']['ok'] === false, 'unknown route → JSON 404');
check(req('GET', '/api/projects')['json']['projects'][0]['name'] === 'blog', 'GET /api/projects needs no header and lists htdocs');
$p = req('GET', '/api/projects')['json']['projects']; $by = array_column($p, null, 'name');
check($by['blog']['hostname'] === 'blog.example.com' && $by['shop']['route_status'] === 'none', 'projects carry their route status');
$d = req('GET', '/api/debug')['json'];
check($d['state_dir_writable'] === true && $d['curl_loaded'] === true && !str_contains(json_encode($d), 'faketoken'), 'debug reports health and never leaks the token');

// ---- config
$good = ['cloudflare' => ['api_token' => '', 'account_id' => '', 'tunnel_id' => '3fd144bc-d272-4001-a8ae-659c708ebeef', 'tunnel_name' => 'T2'], 'paths' => $cfg['paths'], 'defaults' => ['canonical' => 'https', 'expiry_action' => 'detach']];
check(req('POST', '/api/config', $good)['json']['ok'] === true, 'config saves');
$saved = json_decode(file_get_contents("$app/state/config.json"), true);
check($saved['cloudflare']['api_token'] === 'faketoken' && $saved['defaults']['expiry_action'] === 'detach', 'a blank token keeps the saved one; defaults stored');
$bad = $good; $bad['paths']['apache_bin'] = 'x" & calc & "';
check(req('POST', '/api/config', $bad)['code'] === 400, 'dangerous apache path → 400');
check(json_decode(file_get_contents("$app/state/config.json"), true)['paths']['apache_bin'] === "$tmp/none", 'rejected config was not saved');

// ---- routes (offline-safe paths)
check(req('POST', '/api/routes/nope.example.com/toggle')['code'] === 404, 'toggle unknown route → 404');
check(req('POST', '/api/routes/nope.example.com/expiry', ['in' => 7200])['code'] === 404, 'expiry on unknown route → 404');
check(req('POST', '/api/routes/blog.example.com/expiry', ['in' => 30])['code'] === 400, 'expiry under 5 min → 400');
check(req('POST', '/api/routes/blog.example.com/expiry', [])['code'] === 400, 'expiry with no instruction → 400');
$r = req('POST', '/api/routes/blog.example.com/expiry', ['in' => 7200]);
check($r['json']['ok'] === true && abs(json_decode(file_get_contents("$app/state/routes.json"), true)[0]['expires_at'] - (time() + 7200)) < 5, 'setting an expiry persists it');
$info = req('GET', '/api/fs/info&site=blog.example.com')['json'];
check(abs($info['expires_at'] - (time() + 7200)) < 5, 'the Files page gets the route\'s expiry from /api/fs/info');
check(abs(array_column(req('GET', '/api/projects')['json']['projects'], null, 'name')['blog']['expires_at'] - (time() + 7200)) < 5, '/api/projects carries the expiry');
$r = req('POST', '/api/routes/blog.example.com/expiry', ['extend' => 3600]);
check(abs($r['json']['expires_at'] - (time() + 10800)) < 5, 'extending adds to the current expiry');
check(req('POST', '/api/routes/blog.example.com/expiry', ['never' => true])['json']['expires_at'] === null, 'removing the expiry works');
req('POST', '/api/routes/blog.example.com/expiry', ['in' => 7200]);
check(req('DELETE', '/api/routes/nope.example.com&confirm=1')['code'] === 404, 'detach unknown route → 404 before touching Cloudflare');
check(req('DELETE', '/api/routes/blog.example.com')['code'] === 400, 'detach requires confirm=1');
check(req('POST', '/api/bulk', ['action' => 'wat'])['code'] === 400, 'bulk with an unknown action → 400');
check(req('POST', '/api/bulk', ['action' => 'enable', 'hostnames' => []])['code'] === 400, 'bulk with no targets → 400');
check(req('GET', '/api/preview&project=../x&zone_id=z')['code'] === 400, 'preview rejects a traversal project name');
check(req('POST', '/api/reconcile/fix', ['hostname' => "a.com\nServerAlias evil.com", 'action' => 'create_vhost'])['code'] === 400, 'reconcile fix rejects newline-injected hostnames');

// ---- files (scoped)
$L = fn(string $q) => req('GET', "/api/fs/list&path=$q&site=blog.example.com");
check(array_column($L('')['json']['entries'], 'name') === ['css', 'index.html', 'pic.png'], 'site scope lists only that site (dirs first)');
check(req('GET', '/api/fs/read&path=../shop/secret.txt&site=blog.example.com')['code'] === 400, 'cannot read a sibling site');
check(req('GET', '/api/fs/list&site=ghost.example.com')['code'] === 404, 'unknown site → 404');
check(req('GET', '/api/fs/list&project=../shop')['code'] === 404, 'traversal in ?project= → 404');
$w = req('POST', '/api/fs/write&site=blog.example.com', ['path' => 'index.html', 'content' => '<h1>edited</h1>']);
check($w['json']['ok'] === true && file_get_contents("$htdocs/blog/index.html") === '<h1>edited</h1>', 'write saves the file');
$c = req('POST', '/api/fs/write&site=blog.example.com', ['path' => 'index.html', 'content' => 'x', 'mtime' => 1]);
check($c['code'] === 409 && isset($c['json']['mtime']), 'stale mtime → 409 with the current mtime');
check(req('POST', '/api/fs/write&site=blog.example.com', ['path' => '../shop/x.txt', 'content' => 'x'])['code'] === 400 && !file_exists("$htdocs/shop/x.txt"), 'cannot write into a sibling site');
foreach (['CON', 'nul.txt', 'Aux', 'com1.log', 'LPT9'] as $n) check(req('POST', '/api/fs/create&site=blog.example.com', ['path' => $n])['code'] === 400, "Windows device name $n refused");
$tmpf = "$tmp/up.txt"; file_put_contents($tmpf, 'uploaded');
$u = req('POST', '/api/fs/upload&site=blog.example.com', null, ['X-Requested-With: tunnel-router'], ['dir' => '', 'files[]' => new CURLFile($tmpf, 'text/plain', 'up.txt')]);
check(($u['json']['results'][0]['ok'] ?? false) === true && file_get_contents("$htdocs/blog/up.txt") === 'uploaded', 'multipart upload lands in the site');
$u2 = req('POST', '/api/fs/upload&site=blog.example.com', null, ['X-Requested-With: tunnel-router'], ['dir' => '', 'files[]' => new CURLFile($tmpf, 'text/plain', '../../evil.txt')]);
check(!file_exists("$htdocs/evil.txt") && !file_exists("$tmp/evil.txt"), 'upload filename path tricks are neutralised');
$r = req('GET', '/api/fs/raw&path=pic.png&site=blog.example.com');
check($r['code'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'raw serves an image inline');
$r = req('GET', '/api/fs/download&path=css&site=blog.example.com');
check($r['code'] === 200 && str_starts_with($r['body'], 'PK'), 'downloading a folder streams a zip');
$r = req('POST', '/api/fs/compress&site=blog.example.com', ['dir' => '', 'paths' => ['index.html', 'css'], 'name' => 'pack']);
check($r['json']['path'] === 'pack.zip' && is_file("$htdocs/blog/pack.zip"), 'compress creates the archive in the site');
check(req('POST', '/api/fs/chmod&site=blog.example.com', ['path' => 'index.html', 'mode' => '4777'])['code'] === 400, 'chmod with setuid bits refused');
$dl = req('POST', '/api/fs/delete&site=blog.example.com', ['paths' => ['up.txt']]);
check(!is_file("$htdocs/blog/up.txt") && count(req('GET', '/api/fs/trash&site=blog.example.com')['json']['items']) === 1 && req('GET', '/api/fs/trash&project=shop')['json']['items'] === [], 'delete → trash, visible only in its own site');
check(req('POST', '/api/fs/restore&project=shop', ['id' => $dl['json']['ids'][0]])['code'] === 404, "another site cannot restore it");
req('POST', '/api/fs/trash-empty');

// ---- backup / logs offline paths
check(str_contains(req('GET', '/api/backup/export')['body'], '"format": "tunnel-router-backup"') && str_contains(req('GET', '/api/backup/export')['body'], 'dns_error') && !str_contains(req('GET', '/api/backup/export')['body'], 'faketoken'), 'backup export works and omits the token');
check(req('POST', '/api/backup/plan', ['bundle' => ['format' => 'x']])['code'] === 400, 'plan rejects a foreign file');
check(req('POST', '/api/backup/snapshot')['json']['ok'] === true && count(req('GET', '/api/backup/snapshots')['json']['snapshots']) >= 1, 'manual snapshot is listed');
check(req('GET', '/api/logs/sources')['json']['sources'][0]['name'] === 'apache_error' && req('GET', '/api/logs&name=../../etc/passwd')['code'] === 400, 'logs: fixed names only');

// ---- Apache reload status
check(req('GET', '/api/reload/status')['json']['reload'] === null, 'no reload yet → status is null');
check(req('POST', '/api/reload/test', null, [])['code'] === 403, 'reload/test needs the CSRF header');
$r = req('POST', '/api/reload/test');
check($r['json']['reload']['state'] === 'skipped' && str_contains($r['json']['reload']['detail'], 'not found'), 'Apache binary missing → "skipped" with a hint (nothing is launched)');
file_put_contents("$app/state/reload.json", json_encode(['id' => 'x1', 'state' => 'pending', 'requested_at' => time() - 600, 'attempts' => []]));
check(req('GET', '/api/reload/status')['json']['reload']['state'] === 'failed', 'a reload still "pending" after 3 minutes is reported as failed, not pending forever');
file_put_contents("$app/state/reload.json", json_encode(['id' => 'x2', 'state' => 'pending', 'requested_at' => time(), 'attempts' => []]));
check(req('GET', '/api/reload/status')['json']['reload']['state'] === 'pending', 'a fresh pending reload stays pending');

// ---- tunnel routes must exist (regression: the id pattern once went missing and every /api/tunnels/{id}… silently 404'd)
$c = json_decode(file_get_contents("$app/state/config.json"), true); $c['cloudflare']['account_id'] = str_repeat('a', 32); file_put_contents("$app/state/config.json", json_encode($c));
$tid = '3fd144bc-d272-4001-a8ae-659c708ebeef';
foreach ([['GET', "/api/tunnels/$tid"], ['GET', "/api/tunnels/$tid/live"], ['POST', "/api/tunnels/$tid/rename"], ['POST', "/api/tunnels/$tid/cleanup"], ['POST', "/api/tunnels/$tid/activate"]] as [$m, $p]) {
    $r = req($m, $p, $m === 'POST' ? ['name' => 'x'] : null);
    check(!str_contains((string)($r['json']['error'] ?? ''), 'No API route'), "$m $p is routed (reaches Cloudflare, which rejects the fake token)");
}
check(str_contains(req('GET', '/api/tunnels/not-a-uuid')['json']['error'] ?? '', 'No API route'), 'a malformed tunnel id is not routed');

// ---- rebuild routes.json (Cloudflare unreachable here: the scan must still work from the vhosts file, and say what it couldn't read)
file_put_contents("$htdocs/../vhosts.conf", "# >>> tunnel-router: old.example.com\n<VirtualHost 127.0.0.1:80>\n    ServerName old.example.com\n    DocumentRoot \"C:/other-pc/htdocs/shop\"\n</VirtualHost>\n# <<< tunnel-router: old.example.com\n");
$sc = req('GET', '/api/import/scan');
check($sc['json']['ok'] === true && ($sc['json']['items'][0]['hostname'] ?? '') === 'old.example.com' && $sc['json']['items'][0]['project'] === 'shop' && $sc['json']['items'][0]['status'] === 'importable', 'scan works from the vhosts file alone and maps a foreign DocumentRoot to the local folder');
check(!empty($sc['json']['zones_failed']), '…and reports that Cloudflare could not be read');
check(req('POST', '/api/import/apply', ['hostnames' => ['old.example.com']], [])['code'] === 403, 'import/apply needs the CSRF header');
$ap2 = req('POST', '/api/import/apply', ['hostnames' => ['old.example.com', 'nope.example.com']]);
check($ap2['json']['results'][0]['ok'] === true && $ap2['json']['results'][1]['ok'] === false, 'apply imports the valid one and refuses the unknown one');
check(array_column(json_decode(file_get_contents("$app/state/routes.json"), true), 'document_root', 'hostname')['old.example.com'] === "$htdocs/shop", 'routes.json now has it, with this machine\'s folder');
check(count(glob("$app/state/backups/snapshot_*.json")) >= 1, 'a snapshot was taken before importing');
$rf = req('POST', '/api/import/routes-file', ['routes' => [['hostname' => 'a.example.com', 'project' => 'blog'], ['hostname' => 'bad host', 'project' => 'blog']], 'dry_run' => true]);
check($rf['json']['results'][0]['detail'] === 'would import' && $rf['json']['results'][1]['ok'] === false, 'routes.json import: dry run previews and rejects bad rows');

// ---- the per-route page
$dt = req('GET', '/api/routes/blog.example.com/detail')['json'];
check($dt['ok'] === true && $dt['route']['hostname'] === 'blog.example.com' && $dt['vhost'] === null && is_array($dt['activity']) && array_key_exists('dns_error', $dt), 'route detail works from the tool\'s own data even when Cloudflare is unreachable');
check(req('GET', '/api/routes/nope.example.com/detail')['code'] === 404 && req('GET', '/api/routes/nope.example.com/analytics')['code'] === 404, 'unknown route → 404');
check(req('POST', '/api/routes/blog.example.com/label', ['label' => 'My Blog'])['json']['label'] === 'My Blog' && json_decode(file_get_contents("$app/state/routes.json"), true)[0]['label'] === 'My Blog', 'label saves');
foreach (['', str_repeat('x', 101), '<b>x</b>', "a\nb"] as $bad) check(req('POST', '/api/routes/blog.example.com/label', ['label' => $bad])['code'] === 400, 'label rejected: ' . json_encode(mb_substr($bad, 0, 12)));
check(req('POST', '/api/routes/blog.example.com/label', ['label' => 'x'], [])['code'] === 403, 'label needs the CSRF header');

// ---- the project page
$pj = req('GET', '/api/project&name=blog')['json'];
check($pj['ok'] && $pj['project']['name'] === 'blog' && $pj['project']['files'] >= 2 && $pj['project']['entry'] === 'index.html' && count($pj['routes']) === 1 && $pj['routes'][0]['hostname'] === 'blog.example.com', 'project page data: folder facts and its routes');
mkdir("$htdocs/plain"); file_put_contents("$htdocs/plain/notes.txt", 'x');
$pj = req('GET', '/api/project&name=plain')['json'];
check($pj['ok'] && $pj['routes'] === [] && $pj['project']['entry'] === null, 'an unrouted project has no routes and reports a missing index file');
foreach (['../x', 'nope', '', 'a/b'] as $bad) check(req('GET', '/api/project&name=' . urlencode($bad))['code'] === 404, 'project page rejects ' . json_encode($bad));

// ---- users + portal (admin side)
$cr = req('POST', '/api/users', ['username' => 'Alice', 'display' => 'Alice A', 'code_files' => false]);
check($cr['code'] === 200 && $cr['json']['user']['username'] === 'alice' && strlen($cr['json']['token']) === 48 && $cr['json']['invite_url'] === null, 'create a user: one-time invite token returned (no URL until the portal has an address)');
check(req('POST', '/api/users', ['username' => 'alice'])['code'] === 409 && req('POST', '/api/users', ['username' => 'a'])['code'] === 400, 'duplicate / invalid usernames are refused');
check(req('POST', '/api/users', ['username' => 'nocsrf'], [])['code'] === 403, 'creating users needs the CSRF header');
$ul = req('GET', '/api/users')['json'];
check(count($ul['users']) === 1 && !isset($ul['users'][0]['pw']) && !str_contains(json_encode($ul), $cr['json']['token']) && $ul['max'] === 50 && $ul['portal']['hostname'] === '', 'listing exposes no hash and no token');
$uid = $cr['json']['user']['id'];
check(req('POST', "/api/users/$uid/grant", ['host' => 'nope.example.com', 'upload' => true])['code'] === 404, 'cannot grant access to a route that does not exist');
check(req('POST', "/api/users/$uid/grant", ['host' => 'blog.example.com', 'upload' => true, 'edit' => true, 'delete' => false, 'in' => 3600])['code'] === 200, 'grant access to a route (with an end time)');
$ul = req('GET', '/api/users')['json']; $g = $ul['users'][0]['access'][0];
check($g['host'] === 'blog.example.com' && $g['upload'] === true && $g['delete'] === false && abs($g['until'] - (time() + 3600)) < 5, 'the grant is stored with its permissions and end time');
check(req('POST', '/api/routes/blog.example.com/portal', ['enabled' => true])['json']['enabled'] === true && json_decode(file_get_contents("$app/state/routes.json"), true)[0]['portal_enabled'] === true, 'file access can be switched on for a route');
check(req('POST', '/api/routes/nope.example.com/portal', ['enabled' => true])['code'] === 404, '…only for routes that exist');
check(req('POST', "/api/users/$uid/update", ['code_files' => true, 'display' => 'Alice B'])['json']['user']['code_files'] === true, 'edit name and the code-file permission');
$rs = req('POST', "/api/users/$uid/invite"); check($rs['json']['user']['has_password'] === false && strlen($rs['json']['token']) === 48, 'reset: password cleared, new invite issued');
check(req('POST', "/api/users/$uid/signout")['code'] === 200, 'sign out everywhere');
check(req('DELETE', "/api/users/$uid")['code'] === 400, 'deleting needs confirm=1');
check(req('POST', '/api/users/u_00000000/update', ['disabled' => true])['code'] === 404 && req('POST', '/api/users/not-an-id/update', [])['code'] === 404, 'unknown users → 404');
check(req('DELETE', "/api/users/$uid&confirm=1")['code'] === 200 && req('GET', '/api/users')['json']['users'] === [], 'delete removes the user');
check(req('GET', '/api/users/activity')['json']['entries'] === [], 'the portal activity feed is available (empty so far)');
check(req('POST', '/api/portal/disable')['code'] === 400 || req('POST', '/api/portal/disable')['code'] >= 400, 'portal enable/disable need Cloudflare (refused here: fake token)');
$ps = req('POST', '/api/portal/enable', ['zone_id' => str_repeat('a', 32), 'subdomain' => 'files']); check($ps['json']['ok'] === false && (json_decode(file_get_contents("$app/state/config.json"), true)['portal']['hostname'] ?? '') === '', 'enabling the portal against an unreachable Cloudflare fails cleanly and changes nothing');
$cf0 = json_decode(file_get_contents("$app/state/config.json"), true); check(($cf0['portal']['upload_mb'] ?? 25) === 25 && ($cf0['portal']['hostname'] ?? '') === '', 'portal settings keep their defaults (25 MB upload, 500 MB per site)');

// ---- every page renders, and its JavaScript parses
$pages = ['users', 'project', 'route', 'dashboard', 'domains', 'domain', 'tunnels', 'tunnel', 'dns', 'attach', 'detach', 'reconcile', 'backup', 'health', 'files', 'logs', 'activity', 'settings'];
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
foreach ($pages as $pg) {
    $q = in_array($pg, ['files']) ? '&site=blog.example.com' : '';
    $html = (string)file_get_contents("http://127.0.0.1:$port/index.php?page=$pg&zone=" . str_repeat('a', 32) . '&id=3fd144bc-d272-4001-a8ae-659c708ebeef' . $q);
    check($html !== '' && !preg_match('/(Warning|Fatal error|Notice|Deprecated|Parse error)<\/b>|<b>(Warning|Fatal)/', $html), "page $pg renders without PHP errors");
    if ($node) {
        preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
        $bad = 0;
        foreach ($m[1] as $i => $js) {
            if (trim($js) === '') continue;
            $f = "$tmp/{$pg}_$i.js"; file_put_contents($f, $js);
            exec('node --check ' . escapeshellarg($f) . ' 2>&1', $out, $rc); if ($rc !== 0) { $bad++; echo "     $pg script $i: " . implode(' ', array_slice($out, 0, 4)) . "\n"; } $out = [];
        }
        check($bad === 0, "page $pg: all inline scripts parse (" . count($m[1]) . ')');
    }
}
if ($node) { foreach (['ui.js', 'icons.js'] as $a) { exec('node --check ' . escapeshellarg("$app/assets/$a") . ' 2>&1', $o2, $rc2); check($rc2 === 0, "assets/$a parses"); } }
// unconfigured install → every page but settings redirects there
unlink("$app/state/config.json");
$ch = curl_init("http://127.0.0.1:$port/index.php?page=dashboard"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$raw = (string)curl_exec($ch); curl_close($ch);
check(str_contains($raw, '302') && str_contains($raw, 'page=settings'), 'unconfigured → redirected to Settings');
exit(empty($GLOBALS['failed']) ? 0 : 1);
