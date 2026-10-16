<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_portal_setup.php — creating/removing the portal address (DNS + vhost) with rollback; per-route flag; detach cleanup.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function bad(callable $f, ?int $code = null): bool { try { $f(); } catch (RouteException $e) { return $code === null || $e->getCode() === $code; } return false; }
const Z = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; const TID = '3fd144bc-d272-4001-a8ae-659c708ebeef';
class FakeCf extends CloudflareClient { public array $recs = []; public array $calls = [];
    public function __construct() {}
    public function listZones(): array { return [['id' => Z, 'name' => 'example.com']]; }
    public function requestMany(array $specs): array { return array_map(fn() => ['ok' => true, 'data' => ['result' => [['id' => 'p', 'type' => 'CNAME', 'name' => 'files.example.com', 'content' => TID . '.cfargotunnel.com']]], 'error' => null], $specs); }
    public function getDnsRecord(string $z, string $h): ?array { foreach ($this->recs as $r) if ($r['name'] === $h) return $r; return null; }
    public function createCname(string $z, string $h): array { $this->calls[] = "create $h"; return $this->recs['r' . count($this->recs)] = ['id' => 'rec' . count($this->recs), 'name' => $h, 'content' => TID . '.cfargotunnel.com']; }
    public function deleteDnsRecord(string $z, string $id): bool { $this->calls[] = "delete $id"; foreach ($this->recs as $k => $r) if ($r['id'] === $id) unset($this->recs[$k]); return true; } }
class Apache extends ApacheManager { public bool $fail = false; public int $reloads = 0; public function __construct() {}
    public function validateAndReload(VhostManager $vm, bool $reload = true): array { if ($this->fail) { $vm->restoreLatestBackup(); return ['ok' => false, 'error' => 'bad conf', 'output' => 'x']; } $this->reloads++; return ['ok' => true]; } }

$d = sys_get_temp_dir() . '/tr_ps_' . getmypid(); mkdir("$d/state", 0777, true); mkdir("$d/app/public", 0777, true); file_put_contents("$d/v.conf", "");
file_put_contents("$d/state/config.json", json_encode(['cloudflare' => ['api_token' => 'abc', 'tunnel_id' => TID, 'account_id' => str_repeat('b', 32)], 'paths' => ['htdocs' => "$d/htdocs", 'vhosts_file' => "$d/v.conf", 'apache_bin' => "$d/x"]]));
Config::init("$d/state"); UserStore::$cost = 4;
$cf = new FakeCf; $vm = new VhostManager("$d/v.conf", "$d/b"); $ap = new Apache; $state = new State("$d/state"); $log = new ActivityLog("$d/state");
$setup = fn() => new PortalSetup($cf, $vm, $ap, $state, $log, "$d/app");

check(bad(fn() => $setup()->enable(Z, '')) && bad(fn() => $setup()->enable(Z, 'bad name')) && bad(fn() => $setup()->enable(Z, 'a/b')), 'the portal needs a proper subdomain (never the bare domain)');
$r = $setup()->enable(Z, 'Files');
check($r['hostname'] === 'files.example.com' && Config::portalHost() === 'files.example.com', 'enable: creates the hostname and saves it in the config');
check(str_contains((string)file_get_contents("$d/v.conf"), 'ServerName files.example.com') && str_contains((string)file_get_contents("$d/v.conf"), 'DocumentRoot "' . "$d/app/public" . '"'), 'its vhost points at the public/ folder and nothing else');
check($cf->getDnsRecord(Z, 'files.example.com') !== null && $ap->reloads === 1, 'DNS record created and Apache reloaded');
check(json_decode(file_get_contents("$d/state/config.json"), true)['portal']['dns_record_id'] === 'rec0' && json_decode(file_get_contents("$d/state/config.json"), true)['cloudflare']['api_token'] === 'abc', 'config keeps the token and records the DNS id for later removal');
check(bad(fn() => $setup()->enable(Z, 'other'), 409), 'a second portal address is refused while one exists');
$setup()->disable();
check(Config::portalHost() === '' && !str_contains((string)file_get_contents("$d/v.conf"), 'files.example.com') && $cf->getDnsRecord(Z, 'files.example.com') === null, 'disable: vhost and DNS record are removed, config cleared');
check(bad(fn() => $setup()->disable(), 409), 'disabling twice is a clear error');

// ---- a failed attempt must never trap the user: retry works, and turning off always works
$cf2 = new FakeCf; $ap2 = new Apache; $vm2 = new VhostManager("$d/v2.conf", "$d/b2"); file_put_contents("$d/v2.conf", '');
class Boom extends Apache { public function validateAndReload(VhostManager $vm, bool $reload = true): array { throw new RuntimeException('reload crashed'); } }
$boomSetup = new PortalSetup($cf2, $vm2, new Boom, $state, $log, "$d/app");
check(bad(fn() => $boomSetup->enable(Z, 'files'), 500) && Config::portalHost() === '' && !$vm2->hasBlock('files.example.com') && $cf2->getDnsRecord(Z, 'files.example.com') === null, 'an Apache crash during set-up rolls back both the vhost and the DNS record');
$vm2->addBlock('files.example.com', "$d/app/public", false);                                  // a leftover from an older failed attempt
$ok = (new PortalSetup($cf2, $vm2, $ap2, $state, $log, "$d/app"))->enable(Z, 'files');
check($ok['hostname'] === 'files.example.com' && substr_count((string)file_get_contents("$d/v2.conf"), 'ServerName files.example.com') === 1, 'a leftover block from a failed attempt is replaced, so the same name can be retried');
$vm2->removeBlock('files.example.com'); $vm2->addBlock('files.example.com', "$d/htdocs/other", false);
(new PortalSetup($cf2, $vm2, $ap2, $state, $log, "$d/app"))->disable();
$vm2->addBlock('files.example.com', "$d/htdocs/other", false);
check(bad(fn() => (new PortalSetup($cf2, $vm2, $ap2, $state, $log, "$d/app"))->enable(Z, 'files'), 409), "someone else's block with that name is never overwritten");
check(bad(fn() => (new PortalSetup($cf2, $vm2, $ap2, $state, $log, "$d/app"))->enable('0' . substr(Z, 1), 'files2'), 400), 'a zone the token cannot see gives a clear message');

$vm3 = new VhostManager("$d/v3.conf", "$d/b3"); file_put_contents("$d/v3.conf", ''); $cf3 = new FakeCf; $setup3 = new PortalSetup($cf3, $vm3, new Apache, $state, $log, "$d/app");
$setup3->enable(Z, 'portal');
class DeadCf extends FakeCf { public function deleteDnsRecord(string $z, string $id): bool { throw new RuntimeException('API down'); } }
$w = (new PortalSetup(new DeadCf, $vm3, new Apache, $state, $log, "$d/app"))->disable();
check(Config::portalHost() === '' && count($w) === 1 && str_contains($w[0], 'DNS record'), 'turning off still works when Cloudflare is unreachable, and says what to clean up');
$setup3->enable(Z, 'portal2');
$w = (new PortalSetup(null, $vm3, new Apache, $state, $log, "$d/app"))->disable();
check(Config::portalHost() === '' && count($w) === 1, 'turning off works with no Cloudflare token at all');
$setup3->enable(Z, 'portal3');
class DeadAp extends Apache { public function validateAndReload(VhostManager $vm, bool $reload = true): array { throw new RuntimeException('no apache'); } }
$w = (new PortalSetup($cf3, $vm3, new DeadAp, $state, $log, "$d/app"))->disable();
check(Config::portalHost() === '' && $cf3->getDnsRecord(Z, 'portal3.example.com') === null, 'turning off still frees the address when Apache cannot be reloaded');

// ---- conflicts never overwrite anything
$state->upsertRoute(['hostname' => 'blog.example.com', 'project' => 'blog']);
check(bad(fn() => $setup()->enable(Z, 'blog'), 409), 'cannot reuse the address of an existing site');
$cf->recs['x'] = ['id' => 'foreign', 'name' => 'files.example.com', 'content' => '1.2.3.4'];
check(bad(fn() => $setup()->enable(Z, 'files'), 409) && isset($cf->recs['x']), 'cannot take over a DNS name that points elsewhere — and that record is left alone');
unset($cf->recs['x']);

// ---- rollback when Apache refuses
$ap->fail = true; $before = file_get_contents("$d/v.conf"); $cf->calls = [];
check(bad(fn() => $setup()->enable(Z, 'files')) && file_get_contents("$d/v.conf") === $before && Config::portalHost() === '' && $cf->getDnsRecord(Z, 'files.example.com') === null, 'Apache says no → vhost restored, DNS record deleted again, nothing saved');
$ap->fail = false;

// ---- reconcile / importer treat the portal's address as neither a drifted site nor something to import
$setup()->enable(Z, 'files');
$imp = new RouteImporter($state, $vm, $log, $cf, TID, "$d/htdocs");
check(!in_array('files.example.com', array_column($imp->scan()['items'], 'hostname'), true), 'the portal address is not offered by "Rebuild routes"');

// ---- the per-route flag and what happens on detach
$users = new UserStore("$d/state"); $u = $users->create('alice', '', false); $users->acceptInvite($u['token'], 'correct horse battery');
$aid = $users->byUsername('alice')['id']; $users->grant($aid, 'blog.example.com', true, true, true, null);
$state->upsertRoute(['hostname' => 'blog.example.com', 'portal_enabled' => true, 'zone_id' => Z, 'dns_record_id' => 'recX', 'project' => 'blog', 'enabled' => true]);
$svc = new RouteService($state, $log, $vm, $ap, $cf); $vm->addBlock('blog.example.com', "$d/htdocs/blog", false);
$svc->detach('blog.example.com');
check($state->findRoute('blog.example.com') === null && $users->access() === [], 'detaching a route removes every user\'s access to it');

exec('rm -rf ' . escapeshellarg($d));
exit(empty($GLOBALS['failed']) ? 0 : 1);
