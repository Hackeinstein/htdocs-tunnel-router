<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
// php dev/test_dns.php — DNS validation, route protection, import dedupe, export formats (in-memory Cloudflare).
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(fn($c) => is_file($f = APP_ROOT . "/src/$c.php") && require_once $f);

class FakeCf extends CloudflareClient {
    public array $recs = []; private int $n = 0;
    public function __construct() {}
    public function request(string $m, string $path, array $body = [], array $query = []): array {
        if ($m === 'GET')    return ['result' => array_values($this->recs), 'result_info' => ['page' => 1, 'total_pages' => 1]];
        if ($m === 'POST')   { $id = 'r' . ++$this->n; return ['result' => $this->recs[$id] = $body + ['id' => $id]]; }
        $id = basename($path);
        if ($m === 'PATCH')  return ['result' => $this->recs[$id] = $body + ['id' => $id]];
        if ($m === 'DELETE') { unset($this->recs[$id]); return ['result' => ['id' => $id]]; }
        return [];
    }
}
$dir = sys_get_temp_dir() . '/tr_dns_' . getmypid(); mkdir($dir);
function check(bool $ok, string $w): void { echo ($ok ? "ok   " : "FAIL ") . $w . "\n"; if (!$ok) $GLOBALS['failed'] = true; }
function bad(callable $f): bool { try { $f(); } catch (RouteException) { return true; } return false; }

$cf = new FakeCf; $state = new State($dir); $d = new DnsManager($cf, $state, new ActivityLog($dir)); $Z = 'example.com';

check($d->clean($Z, ['type' => 'a', 'name' => 'app', 'content' => '1.2.3.4'])['name'] === 'app.example.com', 'short name gets the zone appended');
check($d->clean($Z, ['type' => 'A', 'name' => '@', 'content' => '1.2.3.4'])['name'] === 'example.com', '@ is the apex');
check($d->clean($Z, ['type' => 'A', 'name' => 'x.example.com', 'content' => '1.2.3.4'])['name'] === 'x.example.com', 'full name kept as is');
check($d->clean($Z, ['type' => 'A', 'name' => 'x', 'content' => '1.2.3.4', 'proxied' => 'true'])['proxied'] === true, 'proxied flag parsed');
check(!isset($d->clean($Z, ['type' => 'TXT', 'name' => 'x', 'content' => 'v=spf1', 'proxied' => true])['proxied']), 'proxied dropped for TXT');
check(bad(fn() => $d->clean($Z, ['type' => 'A', 'name' => 'x', 'content' => 'nope'])), 'A needs an IPv4');
check(bad(fn() => $d->clean($Z, ['type' => 'AAAA', 'name' => 'x', 'content' => '1.2.3.4'])), 'AAAA needs an IPv6');
check(bad(fn() => $d->clean($Z, ['type' => 'CNAME', 'name' => 'x', 'content' => 'not a host'])), 'CNAME needs a hostname');
check(bad(fn() => $d->clean($Z, ['type' => 'SRV', 'name' => 'x', 'content' => 'y'])), 'unsupported type rejected');
check(bad(fn() => $d->clean($Z, ['type' => 'A', 'name' => "bad\nname", 'content' => '1.2.3.4'])), 'newline in name rejected');
check(bad(fn() => $d->clean($Z, ['type' => 'A', 'name' => 'x', 'content' => '1.2.3.4', 'ttl' => 5])), 'TTL below 60 rejected');
check($d->clean($Z, ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => 5])['priority'] === 5, 'MX priority kept');

$a = $d->create('z', $Z, ['type' => 'A', 'name' => 'app', 'content' => '1.2.3.4']);
$t = $d->create('z', $Z, ['type' => 'CNAME', 'name' => 'site', 'content' => 'abc.cfargotunnel.com', 'proxied' => true]);
$state->upsertRoute(['hostname' => 'site.example.com', 'dns_record_id' => $t['id'], 'zone_id' => 'z']);
$list = $d->list('z');
check(count($list) === 2 && $list[1]['tunnel'] === true && $list[1]['route'] === 'site.example.com' && $list[0]['route'] === null, 'list flags tunnel records and their route');
check(count($d->list('z', 'app')) === 1 && count($d->list('z', '', 'CNAME')) === 1, 'filter by text and by type');
check(bad(fn() => $d->delete('z', $t['id'], false)) && isset($cf->recs[$t['id']]), 'route-owned record is protected');
$d->delete('z', $t['id'], true);
check(!isset($cf->recs[$t['id']]), 'forced delete works');
$d->update('z', $Z, $a['id'], ['type' => 'A', 'name' => 'app', 'content' => '9.9.9.9'], false);
check($cf->recs[$a['id']]['content'] === '9.9.9.9', 'update patches the record');

$imp = $d->import('z', $Z, [['type' => 'A', 'name' => 'app', 'content' => '9.9.9.9'], ['type' => 'A', 'name' => 'new', 'content' => '5.5.5.5'], ['type' => 'A', 'name' => 'bad', 'content' => 'x']], true);
check(str_contains($imp[0]['detail'], 'skipped') && $imp[1]['detail'] === 'would create' && !$imp[2]['ok'], 'dry-run import: skip existing, create new, flag invalid');
check(count($cf->recs) === 1, 'dry run wrote nothing');
$d->import('z', $Z, [['type' => 'A', 'name' => 'new', 'content' => '5.5.5.5']], false);
check(count($cf->recs) === 2, 'real import creates');
check(str_starts_with($d->export('z', 'csv'), 'type,name,content') && count(json_decode($d->export('z', 'json'), true)) === 2, 'export csv/json');

array_map('unlink', glob("$dir/*") ?: []); rmdir($dir);
exit(empty($GLOBALS['failed']) ? 0 : 1);
