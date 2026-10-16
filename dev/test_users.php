<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_users.php — the JSON user store: accounts, invites, passwords, sign-out, access grants, the 50-user cap.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function bad(callable $f, ?int $code = null): bool { try { $f(); } catch (RouteException $e) { return $code === null || $e->getCode() === $code; } return false; }
UserStore::$cost = 4;                                    // fast hashing for the test run only
$d = sys_get_temp_dir() . '/tr_users_' . getmypid(); mkdir($d); $s = new UserStore($d);
const PW = 'correct horse battery';

// ---- accounts
$r = $s->create(' Alice ', 'Alice A', false);
check($r['user']['username'] === 'alice' && $r['user']['has_password'] === false && $r['user']['invite_pending'] && strlen($r['token']) === 48, 'create: username normalised, no password yet, one-time invite token issued');
check(!isset($r['user']['pw']) && !isset($r['user']['invite']) && !str_contains(json_encode($s->all()), $r['token']), 'listings never expose the hash or the invite token');
check(!str_contains((string)file_get_contents("$d/users.json"), $r['token']) && str_contains((string)file_get_contents("$d/users.json"), hash('sha256', $r['token'])), 'only a hash of the invite token is stored');
check(bad(fn() => $s->create('ALICE', 'dup', false), 409), 'usernames are unique, case-insensitively');
foreach (['ab', 'a b', 'bad!name', '-lead', str_repeat('x', 33), 'a/b', "x\ny"] as $n) check(bad(fn() => $s->create($n, '', false)), 'rejected username ' . json_encode($n));
foreach (['<b>x</b>', str_repeat('n', 61), "a\nb"] as $n) check(bad(fn() => $s->create('okname', $n, false)), 'rejected display name ' . json_encode(mb_substr($n, 0, 10)));

// ---- invites and passwords
check($s->authenticate('alice', PW) === null, 'no login before the invite is accepted');
check($s->checkInvite($r['token'])['username'] === 'alice' && $s->checkInvite('wrong') === null, 'the invite page can identify the user; a wrong token matches nobody');
foreach (['short', 'password1234', 'alicealicealice', 'aaaaaaaaaaaa', '1234567890', str_repeat('ab', 70)] as $w) check(bad(fn() => $s->acceptInvite($r['token'], $w)), 'weak password refused: ' . json_encode(mb_substr($w, 0, 14)));
check($s->checkInvite($r['token']) !== null, '…and a refused password does not burn the invite');
$u = $s->acceptInvite($r['token'], PW);
check($u['has_password'] && !$u['invite_pending'], 'accepting sets the password and clears the invite');
check(bad(fn() => $s->acceptInvite($r['token'], PW), 410), 'an invite works exactly once');
check(!str_contains((string)file_get_contents("$d/users.json"), PW), 'the password is not stored in plain text');
check($s->authenticate('alice', PW)['username'] === 'alice' && $s->authenticate('ALICE', PW) !== null, 'login works (username is case-insensitive)');
check($s->authenticate('alice', 'wrong password!') === null && $s->authenticate('nobody', PW) === null, 'wrong password and unknown user both fail the same way');
check($s->authenticate('alice', PW . ' ') === null && $s->authenticate('alice', substr(PW, 0, -1)) === null, 'every character counts (no silent truncation or trimming)');
$long = str_repeat('correct horse ', 6) . 'A'; $r2 = $s->create('bob', 'Bob', false); $s->acceptInvite($r2['token'], $long);
check($s->authenticate('bob', str_repeat('correct horse ', 6) . 'B') === null && $s->authenticate('bob', $long) !== null, 'characters beyond 72 bytes still matter (bcrypt would have ignored them)');
check($s->byUsername('alice')['last_login'] !== null, 'last login is recorded');

// ---- expired invite
$j = json_decode(file_get_contents("$d/users.json"), true); $r3 = $s->create('carol', 'Carol', false);
$j = json_decode(file_get_contents("$d/users.json"), true); foreach ($j['users'] as &$x) if ($x['username'] === 'carol') $x['invite']['exp'] = time() - 5; file_put_contents("$d/users.json", json_encode($j));
check($s->checkInvite($r3['token']) === null && bad(fn() => $s->acceptInvite($r3['token'], PW . '!'), 410), 'an expired invite is refused');

// ---- reset, disable, sign out
$sv = $s->byUsername('alice')['sv'];
$rs = $s->resetInvite($s->byUsername('alice')['id']);
check($s->authenticate('alice', PW) === null && $s->byUsername('alice')['sv'] === $sv + 1, 'reset: the old password stops working at once and sessions are invalidated');
$s->acceptInvite($rs['token'], 'a brand new passphrase'); check($s->authenticate('alice', 'a brand new passphrase') !== null, 'reset: the new invite sets a new password');
$sv = $s->byUsername('alice')['sv']; $s->signOutEverywhere($s->byUsername('alice')['id']); check($s->byUsername('alice')['sv'] === $sv + 1, 'sign out everywhere bumps the session version');
$s->update($s->byUsername('alice')['id'], ['disabled' => true]);
check($s->authenticate('alice', 'a brand new passphrase') === null && $s->byUsername('alice')['sv'] === $sv + 2, 'a disabled user cannot log in (and is signed out)');
$s->update($s->byUsername('alice')['id'], ['disabled' => false, 'code_files' => true, 'display' => 'Alice B']);
check($s->authenticate('alice', 'a brand new passphrase') !== null && $s->byUsername('alice')['code_files'] === true && $s->byUsername('alice')['display'] === 'Alice B', 're-enable and edit name / code-file permission');
check(bad(fn() => $s->update('u_nope', ['disabled' => true]), 404) && bad(fn() => $s->resetInvite('u_nope'), 404), 'unknown ids give 404');

// ---- access grants
$aid = $s->byUsername('alice')['id']; $bid = $s->byUsername('bob')['id'];
$s->grant($aid, 'blog.example.com', true, true, false, null); $s->grant($aid, 'shop.example.com', false, false, false, time() + 3600); $s->grant($bid, 'blog.example.com', false, false, false, null);
check(count($s->access($aid)) === 2 && count($s->access(null, 'blog.example.com')) === 2, 'grants are many-to-many');
$s->grant($aid, 'blog.example.com', true, true, true, null);
check(count($s->access($aid)) === 2 && array_values(array_filter($s->access($aid), fn($g) => $g['host'] === 'blog.example.com'))[0]['delete'] === true, 'granting again updates instead of duplicating');
check(bad(fn() => $s->grant($aid, 'bad host', true, true, true, null)) && bad(fn() => $s->grant($aid, 'a.example.com', true, true, true, time() - 5)) && bad(fn() => $s->grant('u_nope', 'a.example.com', true, true, true, null), 404), 'grants validate host, end date and user');
$s->revoke($aid, 'shop.example.com'); check(count($s->access($aid)) === 1, 'revoke removes one grant');
$s->revokeHost('blog.example.com'); check($s->access() === [], 'removing a host removes everyone\'s access to it');
$s->grant($aid, 'x.example.com', true, true, true, null); $s->delete($aid);
check($s->byUsername('alice') === null && $s->access() === [], 'deleting a user removes their grants too');

// ---- the cap
$free = UserStore::MAX_USERS - $s->count();
for ($i = 0; $i < $free; $i++) $s->create('user' . $i . 'x', '', false);
check($s->count() === 50 && bad(fn() => $s->create('onetoomany', '', false), 409), 'the 50-user limit is enforced');
check(json_decode((string)file_get_contents("$d/users.json"), true)['version'] === 1, 'the file carries a schema version');

// ---- concurrency: parallel creators never lose an account
if (($argv[1] ?? '') === 'worker') exit;
@unlink("$d/users.json"); $procs = [];
for ($i = 0; $i < 5; $i++) $procs[] = proc_open([PHP_BINARY, '-r', 'define("APP_ROOT", ' . var_export(APP_ROOT, true) . '); require APP_ROOT."/src/helpers.php"; spl_autoload_register(fn($c)=>is_file($f=APP_ROOT."/src/$c.php")&&require_once $f); UserStore::$cost=4; $s=new UserStore(' . var_export($d, true) . '); for($k=0;$k<8;$k++) $s->create("w'. $i . '_u$k","",false);'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
foreach ($procs as $p) proc_close($p);
check((new UserStore($d))->count() === 40, 'five parallel processes creating users kept every account (' . (new UserStore($d))->count() . '/40)');

array_map('unlink', glob("$d/*") ?: []); @rmdir($d);
exit(empty($GLOBALS['failed']) ? 0 : 1);
