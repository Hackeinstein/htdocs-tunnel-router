<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_bundle.php — export → wipe → restore round trip, plan/dry-run, rollback, snapshots.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

class FakeCf extends CloudflareClient {
    public array $recs = []; public int $n = 0;
    public function __construct() {}
    public function listZones(): array { return [['id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'name' => 'example.com']]; }
    public function getDnsRecord(string $z, string $h): ?array { foreach ($this->recs as $r) if ($r['name'] === $h) return $r; return null; }
    public function deleteDnsRecord(string $z, string $id): bool { unset($this->recs[$id]); return true; }
    public function request(string $m, string $p, array $body = [], array $q = []): array { $id = 'rec' . ++$this->n; return ['result' => $this->recs[$id] = $body + ['id' => $id]]; }
}
class Apache extends ApacheManager { public bool $fail = false; public function __construct() {}
    public function validateAndReload(VhostManager $vm, bool $reload = true): array { return $this->fail ? ['ok' => false, 'error' => 'bad conf', 'output' => 'x'] : ['ok' => true]; } }

$dir = sys_get_temp_dir() . '/tr_bun_' . getmypid(); mkdir("$dir/backups/", 0777, true); mkdir("$dir/htdocs/blog", 0777, true);
file_put_contents("$dir/vhosts.conf", '');
file_put_contents("$dir/config.json", json_encode(['cloudflare' => ['api_token' => 'SECRET', 'tunnel_id' => 't1'], 'paths' => ['htdocs' => "$dir/htdocs", 'vhosts_file' => "$dir/vhosts.conf", 'apache_bin' => 'x']]));
Config::init($dir);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

$state = new State($dir); $vm = new VhostManager("$dir/vhosts.conf", "$dir/backups"); $cf = new FakeCf; $ap = new Apache;
$mk = fn() => new Bundle($state, $vm, $ap, new ActivityLog($dir), $cf, "$dir/backups");
$vm->addBlock('blog.example.com', "$dir/htdocs/blog", false);
$cf->request('POST', '', ['type' => 'CNAME', 'name' => 'blog.example.com', 'content' => 't1.cfargotunnel.com', 'proxied' => true, 'ttl' => 1]);
$state->upsertRoute(['hostname' => 'blog.example.com', 'project' => 'blog', 'zone_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'enabled' => true, 'expires_at' => time() + 9999, 'dns_record_id' => 'rec1']);

$b = $mk()->export(false);
check($b['config']['cloudflare']['api_token'] === '' && !str_contains(json_encode($b), 'SECRET'), 'export leaves the API token out by default');
check($mk()->export(true)['config']['cloudflare']['api_token'] === 'SECRET', 'token included only when asked');
check(count($b['routes']) === 1 && $b['dns'][0]['zone_name'] === 'example.com' && isset($b['vhosts']['blog.example.com']), 'bundle has route, dns record, vhost block');

class DownCf extends FakeCf { public function listZones(): array { throw new RuntimeException('network down'); } }
$down = (new Bundle($state, $vm, $ap, new ActivityLog($dir), new DownCf, "$dir/backups"))->export(false);
check(count($down['routes']) === 1 && $down['dns'] === [] && str_contains($down['dns_error'], 'network down'), 'export still succeeds (without DNS) when Cloudflare is unreachable');

$p = $mk()->plan($b, false);
check($p['routes'][0]['actions'] === ['route' => 'update', 'vhost' => 'keep', 'dns' => 'keep'], 'plan on an intact system: nothing to create');

// wipe everything, then restore
$state->removeRoute('blog.example.com'); $vm->removeBlock('blog.example.com'); $cf->recs = [];
$p = $mk()->plan($b, false);
check($p['routes'][0]['actions'] === ['route' => 'add', 'vhost' => 'add', 'dns' => 'create'] && $p['routes'][0]['problems'] === [], 'plan after wipe: add everything');
check($state->findRoute('blog.example.com') === null, 'plan changed nothing');
$r = $mk()->apply($b, false, true);
check($r['results'][0]['ok'] && $state->findRoute('blog.example.com')['expires_at'] === $b['routes'][0]['expires_at'], 'restore brings the route back with its expiry');
check($vm->hasBlock('blog.example.com') && $cf->getDnsRecord('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'blog.example.com') !== null, 'restore recreates vhost and DNS');
check(!empty($r['snapshot']) && count($mk()->snapshots()) >= 1, 'restore took a snapshot first');

// missing project folder is reported, not fatal
$b2 = $b; $b2['routes'][0]['project'] = 'ghost'; $b2['routes'][0]['hostname'] = 'ghost.example.com'; $b2['dns'][0]['name'] = 'ghost.example.com';
$r = $mk()->apply($b2, false, true);
check(!$r['results'][0]['ok'] && str_contains($r['results'][0]['error'], 'project folder'), 'missing project folder reported per route');

// Apache refuses → rollback of vhosts, DNS and state
$state->removeRoute('blog.example.com'); $vm->removeBlock('blog.example.com'); $cf->recs = [];
$ap->fail = true;
try { $mk()->apply($b, false, true); check(false, 'rollback raises'); } catch (RouteException $e) { check(true, 'rollback raises'); }
check(!$vm->hasBlock('blog.example.com') && $cf->recs === [] && $state->findRoute('blog.example.com') === null, 'rollback undid vhost, DNS and route');
$ap->fail = false;

// a crafted backup file is untrusted: config goes through the same validation as the Settings form
$evil = $b; $evil['config']['paths']['apache_bin'] = 'C:/x" & calc & "';
$before = file_get_contents("$dir/config.json");
try { $mk()->apply($evil, true, false); check(false, 'crafted config rejected'); } catch (RouteException) { check(true, 'crafted config rejected'); }
check(file_get_contents("$dir/config.json") === $before, '…and the saved config is untouched');
$badZone = $b; $badZone['routes'][0]['zone_id'] = '../../accounts'; $badZone['dns'][0]['zone_id'] = '../../accounts';
$pl = $mk()->plan($badZone, false);
check(in_array('invalid zone id', $pl['routes'][0]['problems'], true), 'a zone id that could alter the API path is flagged');
$junk = $b; $junk['routes'][0]['evil_key'] = 'x'; $junk['routes'][0]['expires_at'] = 'soon'; $junk['routes'][0]['enabled'] = 'yes';
$state->removeRoute('blog.example.com'); $vm->removeBlock('blog.example.com'); $cf->recs = [];
$mk()->apply($junk, false, true);
$st = $state->findRoute('blog.example.com');
check(!isset($st['evil_key']) && $st['expires_at'] === null && $st['enabled'] === true, 'restored routes keep only known, correctly typed fields');

try { $mk()->plan(['format' => 'nope'], false); check(false, 'foreign file rejected'); } catch (RouteException) { check(true, 'foreign file rejected'); }
try { $mk()->loadSnapshot('../config.json'); check(false, 'snapshot path traversal rejected'); } catch (RouteException) { check(true, 'snapshot path traversal rejected'); }
for ($i = 0; $i < 22; $i++) $mk()->snapshot('x');
check(count($mk()->snapshots()) === 20, 'snapshots capped at 20');

foreach (glob("$dir/backups/*") ?: [] as $f) @unlink($f); @rmdir("$dir/backups");
foreach (glob("$dir/htdocs/*") ?: [] as $f) @rmdir($f); @rmdir("$dir/htdocs"); array_map('unlink', glob("$dir/*.*") ?: []); @rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
