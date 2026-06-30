# htdocs Tunnel Router — Build Task List

**Agent handoff document.**  
Work through phases in order. Each phase must be complete and tested before the next.  
Read ARCHITECTURE.md before starting any phase.

Status legend: `[ ]` not started · `[~]` in progress · `[x]` done

---

## Phase 1 — State & Config Scaffolding

- [x] **1.1** `src/helpers.php`
  - `atomicWrite(string $path, string $content): void` — write to `$path.tmp`, then `rename()`
  - `backupFile(string $path, string $backupDir): string` — copy to `backupDir/basename_YYYYMMDD_HHmmss.ext`, return backup path
  - `sanitizeProjectName(string $name): bool` — reject `.`, `..`, slashes, null bytes
  - `sanitizeHostname(string $h): bool` — must match `/^[a-z0-9]([a-z0-9\-\.]{0,251}[a-z0-9])?$/i`
  - `jsonPretty(mixed $data): string` — `json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)`

- [x] **1.2** `src/Config.php`
  - `load(): array` — merge defaults with `state/config.json`
  - `save(array $config): void` — atomicWrite
  - `isConfigured(): bool` — api_token + tunnel_id both non-empty
  - Defaults: see ARCHITECTURE.md §4
  - Never log or return the raw `api_token` in any public method response

- [x] **1.3** `src/State.php`
  - `loadRoutes(): array`
  - `saveRoutes(array $routes): void` — atomicWrite
  - `findRoute(string $hostname): ?array`
  - `upsertRoute(array $route): void`
  - `removeRoute(string $hostname): void`

- [x] **1.4** `src/ActivityLog.php`
  - `append(string $action, string $hostname, bool $ok, string $detail): void`
  - `read(int $limit = 100): array` — most-recent first
  - Log file: `state/activity.json` (append-only JSON array; cap at 1000 entries)

- [x] **1.5** Ensure `state/` is not web-accessible
  - Add `state/.htaccess` with `Require all denied`

---

## Phase 2 — Cloudflare Client

- [x] **2.1** `src/CloudflareClient.php`
  - Constructor: `__construct(string $apiToken, string $accountId, string $tunnelId)`
  - `listZones(): array` — GET `/zones`, return `[{id, name}]`, cache in `state/zones_cache.json` for 5 min
  - `getZone(string $zoneName): ?array` — GET `/zones?name=<name>`
  - `listDnsRecords(string $zoneId, ?string $name = null): array` — GET `/zones/<id>/dns_records`
  - `getDnsRecord(string $zoneId, string $hostname): ?array` — find single record by name
  - `createCname(string $zoneId, string $hostname): array` — POST; returns `{id, name, content}`
  - `updateCname(string $zoneId, string $recordId, string $hostname): array` — PATCH
  - `deleteDnsRecord(string $zoneId, string $recordId): bool` — DELETE
  - `checkApexConflict(string $zoneId, string $zoneName): ?array` — return existing apex A/CNAME if any
  - Private `request(string $method, string $path, array $body = []): array` — curl wrapper
  - All CF errors must surface the CF API error message, not a generic "CF error"

- [x] **2.2** Write test script `dev/test_cf.php` (not web-accessible)
  - Loads config, instantiates client, lists zones, prints result
  - Run from CLI: `php dev\test_cf.php`

---

## Phase 3 — Vhost & Apache Management

- [x] **3.1** `src/VhostManager.php`
  - `parseBlocks(string $vhostsFile): array` — parse all fenced blocks, return `[hostname => blockText]`
  - `hasBlock(string $hostname): bool`
  - `addBlock(string $hostname, string $docRoot, bool $wwwAlias, string $vhostsFile, string $backupDir): void`
    - backup first, then append fenced block, atomicWrite
  - `removeBlock(string $hostname, string $vhostsFile, string $backupDir): void`
    - backup first, strip block, atomicWrite
  - `disableBlock(string $hostname, ...): void` — comment out `<VirtualHost>` lines inside fence
  - `enableBlock(string $hostname, ...): void` — uncomment
  - `buildBlockText(string $hostname, string $docRoot, bool $wwwAlias): string` — returns the fenced block string
  - `restoreLatestBackup(string $vhostsFile, string $backupDir): bool`

- [x] **3.2** `src/ApacheManager.php`
  - `validate(string $apacheBin): array` — run `httpd.exe -t`, return `{ok, output}`
  - `reload(string $apacheBin): array` — fire detached UAC-elevated restart (exact code from ARCHITECTURE.md §6)
  - `validateAndReload(string $apacheBin, string $vhostsFile, string $backupDir): array`
    - validate → if fail restore + return error; if ok fire reload

---

## Phase 4 — Attach Flow (core feature — priority)

- [x] **4.1** `api.php` — POST `/api/routes` endpoint
  - Validate inputs (project exists in htdocs, hostname format, zone_id is known)
  - `GET /api/preview` — dry run, return `{vhost_block, dns_payload, apex_conflict?}`
  - Attach sequence:
    1. Check for existing DNS record (idempotent: update if same tunnel, error if different)
    2. For apex: if conflict found, require `force=1` in body, abort otherwise
    3. `CloudflareClient::createCname()` (or updateCname)
    4. `VhostManager::addBlock()` (skip if already present — idempotent)
    5. `ApacheManager::validateAndReload()` — if fails, delete the DNS record just created (rollback)
    6. `State::upsertRoute()`
    7. `ActivityLog::append()`
  - Return `{ok, hostname, dns_record_id}` or `{ok:false, error, step}`

- [x] **4.2** `api.php` — GET `/api/projects` endpoint
  - Scan `htdocs` dir, skip `tunnel-router` itself and dot-folders
  - For each project, look up route status: none / active / disabled / drifted
  - Return `[{name, path, route_status, hostname?}]`

- [x] **4.3** `api.php` — GET `/api/zones` endpoint
  - Proxy to `CloudflareClient::listZones()`; use 5-min cache
  - Return `[{id, name}]`

---

## Phase 5 — Dashboard UI

- [x] **5.1** `views/layout.php` — Tabler shell
  - Sidebar: Dashboard, Attach Route, Reconcile, Activity Log, Settings
  - Top nav: app name + "Tunnel: <name>" badge
  - CDN includes: Tabler CSS, Tabler Icons, Tabler JS (pinned versions from ARCHITECTURE.md §12)
  - Flash message area (read from PHP session)

- [x] **5.2** `views/dashboard.php`
  - Fetch projects from `/api/projects` on page load (JS)
  - Cards or table per project: name, hostname (if routed), status badge, enable/disable toggle, attach/detach buttons
  - Status badges: green "Live", orange "Drifted", grey "Not Routed", yellow "Disabled"
  - Toggle calls `POST /api/routes/{hostname}/toggle` and updates badge in-place

- [x] **5.3** `index.php` — front-controller
  - Route `?page=` to views
  - Default: `?page=dashboard`
  - Redirect to `?page=settings` if `!Config::isConfigured()`
  - Include `src/` autoloader (simple spl_autoload_register on `src/` dir)

---

## Phase 6 — Attach View

- [x] **6.1** `views/attach.php` — multi-step wizard
  - Step 1: project picker (cards from `/api/projects`, only unrouted projects)
  - Step 2: zone dropdown (from `/api/zones`), subdomain input or "apex" checkbox, www alias toggle, label field
  - Step 3: preview pane — fetch `/api/preview?...` and display exact vhost block and DNS record in a `<pre>` block; "Confirm & Apply" button
  - Step 4: spinner while POST runs; success/error result

- [x] **6.2** Apex conflict UX
  - If preview returns `apex_conflict`, show warning: "An existing A/CNAME record for <zone> will be replaced. This may affect email if MX points here." + checkbox to confirm

---

## Phase 7 — Detach, Toggle, Reconcile, Activity

- [x] **7.1** `api.php` — DELETE `/api/routes/{hostname}`
  - Requires `?confirm=1` query param (guard against accidental delete)
  - Sequence: delete CF DNS record → remove vhost block → validateAndReload → remove from routes.json
  - If Apache validate fails after vhost removal: re-add the block, return error (don't leave a dangling DNS record)
  - ActivityLog entry

- [x] **7.2** `api.php` — POST `/api/routes/{hostname}/toggle`
  - VhostManager::disableBlock / enableBlock
  - validateAndReload
  - Update `routes.json` `enabled` field
  - Return `{ok, enabled}`

- [x] **7.3** `src/Reconciler.php`
  - `reconcile(Config $config, CloudflareClient $cf, VhostManager $vm): array`
  - Algorithm: see ARCHITECTURE.md §10
  - Return `[{hostname, status, route_entry, has_vhost, has_dns, dns_record_id}]`

- [x] **7.4** `api.php` — GET `/api/reconcile`
  - Run reconciler, return results

- [x] **7.5** `api.php` — POST `/api/reconcile/fix`
  - Body: `{hostname, action}` where action is `create_dns | create_vhost | delete_dns | delete_vhost | import | delete_both`
  - Apply the fix, reload if vhost changed

- [x] **7.6** `views/reconcile.php`
  - Table of reconcile results with status badges
  - Per-row action buttons based on status
  - "Fix All OK-able" bulk button

- [x] **7.7** `views/activity.php`
  - Fetch `/api/activity`, render as timeline (Tabler `timeline` component)
  - Green dot = ok, red dot = error
  - Most recent first

---

## Phase 8 — Settings UI

- [x] **8.1** `views/settings.php`
  - Form fields for all `config.json` values
  - Token field: type=password, never pre-filled (write-only display)
  - On save: POST to `api.php?action=save_config`
  - "Test Connection" button: calls `/api/zones` and shows zone count or error

- [x] **8.2** `api.php` — POST `/api/config`
  - Validate and save config
  - Clear zones cache on token change

---

## Phase 9 — Polish & Security

- [ ] **9.1** Input validation audit
  - Project name: no traversal characters
  - Hostname: regex `/^[a-z0-9]([a-z0-9\-\.]{0,251}[a-z0-9])?$/i`
  - All shell args through `escapeshellarg()`
  - CF API token never in any response body or log

- [ ] **9.2** Error handling audit
  - CF API down → dashboard still renders with local state + error banner
  - Apache validate failure → backup restored, error surfaced, no partial state
  - Partial attach (DNS created, vhost fails) → delete DNS record (rollback)
  - File permission error on `state/` → clear error message with fix instructions

- [x] **9.3** `.htaccess` for the tool root
  - Block direct access to `state/` and `src/`
  - Optional: rewrite `/api/*` to `api.php` (if clean URLs desired)

- [x] **9.4** README.md
  - Prerequisites
  - First-run setup
  - UAC behaviour explanation
  - Tunnel prerequisite

---

## Implementation Notes for Agent

### Autoloader
Use a simple `spl_autoload_register` in `index.php` and `api.php`:
```php
spl_autoload_register(function($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) require $file;
});
```

### State directory path
All classes should resolve `state/` relative to `__DIR__` of the tunnel-router root, not relative to the PHP file. Pass the state dir path via Config or a constant.

### Windows path handling in PHP on Windows XAMPP
- Use forward slashes everywhere in PHP code (`C:/xampp/htdocs/...`) — PHP on Windows accepts both
- `realpath()` will return backslashes on Windows — normalize with `str_replace('\\', '/', ...)`
- Shell commands need double backslashes: `"C:\\xampp\\apache\\bin\\httpd.exe"`

### Curl on Windows XAMPP
- XAMPP ships with libcurl and `php_curl.dll` — it's available, just confirm `extension=curl` is enabled in `php.ini`
- Set `CURLOPT_SSL_VERIFYPEER => true` and use XAMPP's bundled CA bundle: `curl_setopt($ch, CURLOPT_CAINFO, 'C:/xampp/php/extras/ssl/cacert.pem')`

### Testing without Windows
The PHP logic (JSON state, vhost string generation, CF API calls) can be developed and unit-tested on macOS/Linux. The `ApacheManager::reload()` method is the only Windows-only piece — stub it for dev.

### CF API rate limits
- DNS record list: 1200 req/5min per zone — not a concern for this tool
- Cache zone list for 5 min in `state/zones_cache.json` to avoid hammering on every page load
