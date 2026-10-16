<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_import.php — rebuilding routes.json from the live vhosts + Cloudflare, without changing either.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);
$cfgDir = sys_get_temp_dir() . '/tr_import_cfg_' . getmypid(); @mkdir($cfgDir); Config::init($cfgDir);       // the importer reads the portal hostname from Config
register_shutdown_function(fn() => @array_map('unlink', glob($cfgDir . '/*') ?: []) && @rmdir($cfgDir));
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }

const TID = '3fd144bc-d272-4001-a8ae-659c708ebeef'; const Z1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; const Z2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
class FakeCf extends CloudflareClient {
    public array $calls = []; public bool $failZ2 = false;
    public function __construct() {}
    public function requestMany(array $specs): array {
        $out = [];
        foreach ($specs as $k => $sp) { try { $out[$k] = ['ok' => true, 'data' => $this->request($sp[0], $sp[1], $sp[2] ?? [], $sp[3] ?? []), 'error' => null]; } catch (Throwable $e) { $out[$k] = ['ok' => false, 'data' => null, 'error' => $e->getMessage()]; } }
        return $out;
    }
    public function listZones(): array { return [['id' => Z1, 'name' => 'example.com'], ['id' => Z2, 'name' => 'other.io']]; }
    public function request(string $m, string $p, array $b = [], array $q = []): array {
        $this->calls[] = "$m $p";
        if ($m !== 'GET') throw new RuntimeException('import must never write to Cloudflare');
        if (str_contains($p, Z2) && $this->failZ2) throw new RuntimeException('zone boom');
        return $this->shape($p);
    }
    private function shape(string $p): array {
        $rec = fn($id, $n) => ['id' => $id, 'type' => 'CNAME', 'name' => $n, 'content' => TID . '.cfargotunnel.com'];
        return ['result' => str_contains($p, Z1) ? [$rec(str_repeat('1', 32), 'blog.example.com'), $rec(str_repeat('2', 32), 'example.com'), $rec(str_repeat('3', 32), 'orphan.example.com')] : [$rec(str_repeat('4', 32), 'app.other.io')]];
    }
}
$d = sys_get_temp_dir() . '/tr_imp_' . getmypid(); foreach (['htdocs/blog', 'htdocs/shop', 'htdocs/app', 'state'] as $x) mkdir("$d/$x", 0777, true);
$vh = "$d/vhosts.conf"; $vm = new VhostManager($vh, "$d/b");
$vm->addBlock('blog.example.com', "$d/htdocs/blog", true);          // enabled, www alias
$vm->addBlock('shop.example.com', "$d/htdocs/shop", false);         // vhost but no DNS record
$vm->disableBlock('shop.example.com');                              // …and disabled
$vm->addBlock('app.other.io', 'C:/old-machine/xampp/htdocs/app', false);   // docroot from another machine
$vm->addBlock('ghost.example.com', "$d/htdocs/ghost", false);       // folder gone
file_put_contents($vh, str_replace('ServerName example.com', 'ServerName example.com', (string)file_get_contents($vh)));
$vm->addBlock('example.com', "$d/htdocs/blog", false);              // apex
$before = (string)file_get_contents($vh);

$state = new State("$d/state"); $cf = new FakeCf; $imp = new RouteImporter($state, $vm, new ActivityLog("$d/state"), $cf, TID, "$d/htdocs");
$scan = $imp->scan(); $by = array_column($scan['items'], null, 'hostname');

check(count($scan['items']) === 6 && $scan['zones_failed'] === [], 'scan finds the union of vhost blocks and tunnel CNAMEs (6)');
check($by['blog.example.com']['status'] === 'importable' && $by['blog.example.com']['www_alias'] === true && $by['blog.example.com']['project'] === 'blog' && $by['blog.example.com']['zone_id'] === Z1 && $by['blog.example.com']['dns_record_id'] === str_repeat('1', 32), 'a normal route: project, www alias, zone and DNS record id recovered');
check($by['shop.example.com']['enabled'] === false && $by['shop.example.com']['has_dns'] === false && $by['shop.example.com']['status'] === 'importable' && $by['shop.example.com']['zone_id'] === Z1, 'a disabled vhost with no DNS: still importable, flagged, zone matched by name');
check($by['app.other.io']['project'] === 'app' && $by['app.other.io']['zone_id'] === Z2, 'a DocumentRoot from another machine maps to the same-named folder here');
check($by['ghost.example.com']['status'] === 'project_missing', 'a vhost whose folder is gone is flagged, not imported');
check($by['orphan.example.com']['status'] === 'dns_only' && !$by['orphan.example.com']['has_vhost'], 'a DNS record with no vhost is reported as dns_only');
check($by['example.com']['type'] === 'apex', 'the zone root is recognised as an apex route');
check(count(array_filter($cf->calls, fn($c) => !str_starts_with($c, 'GET'))) === 0 && count($cf->calls) === 2, 'one filtered DNS query per zone, all GET');

$res = $imp->import(['blog.example.com', 'shop.example.com', 'app.other.io', 'ghost.example.com', 'orphan.example.com', 'nope.example.com']);
$ok = array_column($res, 'ok', 'id');
check($ok['blog.example.com'] && $ok['shop.example.com'] && $ok['app.other.io'] && !$ok['ghost.example.com'] && !$ok['orphan.example.com'] && !$ok['nope.example.com'], 'importable ones are imported; the rest are refused with a reason');
check(str_contains(json_encode($res), 'Attach Route') && str_contains(json_encode($res), 'not found in htdocs'), '…and the reasons say what to do');
$r = $state->findRoute('shop.example.com');
check($r['enabled'] === false && $r['project'] === 'shop' && $r['document_root'] === "$d/htdocs/shop" && $r['zone_id'] === Z1 && $r['expires_at'] === null, 'imported entry has clean, correctly typed fields (document root rebuilt for this machine)');
check($state->findRoute('app.other.io')['document_root'] === "$d/htdocs/app", 'document root of a foreign path is rebuilt from THIS htdocs');
check((string)file_get_contents($vh) === $before, 'the vhosts file was not changed');
check(count(array_filter($cf->calls, fn($c) => !str_starts_with($c, 'GET'))) === 0, 'Cloudflare was only ever read');

$again = $imp->import(['blog.example.com']);
check(str_contains($again[0]['detail'], 'already tracked') && count($state->loadRoutes()) === 3, 'importing again changes nothing (idempotent)');
$state->upsertRoute(['hostname' => 'blog.example.com', 'expires_at' => time() + 9999, 'label' => 'My Blog']);
$imp->import(['blog.example.com']);
check($state->findRoute('blog.example.com')['label'] === 'My Blog' && $state->findRoute('blog.example.com')['expires_at'] > time(), 'an existing entry (with your expiry/label) is never overwritten');

$cf->failZ2 = true; $s2 = $imp->scan();
check(isset($s2['zones_failed']['other.io']) && count($s2['items']) >= 5, 'one zone failing is reported, the rest of the scan still works');

// old routes.json
$old = [['hostname' => 'new.example.com', 'project' => 'shop', 'zone_id' => Z1, 'enabled' => true, 'document_root' => 'C:/old/htdocs/shop', 'expires_at' => time() + 5000, 'evil' => 'x'],
        ['hostname' => 'blog.example.com', 'project' => 'blog'], ['hostname' => 'bad host', 'project' => 'blog'], ['hostname' => 'x.example.com', 'project' => '../etc'],
        ['hostname' => 'y.example.com', 'project' => 'missing'], ['hostname' => 'z.example.com', 'project' => 'blog', 'zone_id' => '../../x']];
$dry = $imp->fromRoutesFile($old, true);
check(!$state->findRoute('new.example.com') && $dry[0]['detail'] === 'would import', 'dry run writes nothing');
$real = $imp->fromRoutesFile($old, false); $ok = array_column($real, 'ok');
check($ok === [true, true, false, false, false, false], 'old file: valid kept, tracked skipped, bad hostname / traversal / missing folder / bad zone id rejected');
$n = $state->findRoute('new.example.com');
check($n['document_root'] === "$d/htdocs/shop" && !isset($n['evil']) && $n['expires_at'] > time(), 'old entry: document root rebuilt for this machine, unknown fields dropped, a future expiry kept');

exec('rm -rf ' . escapeshellarg($d));
exit(empty($GLOBALS['failed']) ? 0 : 1);
