# htdocs Tunnel Router — Architecture Reference

**For agent handoff.** Read this before touching any code.  
This document is the authoritative design reference. The TASKS.md file tracks what has been built vs. what remains.

---

## 1. What This Tool Does

Routes folders in `C:\xampp\htdocs` to public HTTPS hostnames through a **single, pre-existing Cloudflare Tunnel**.

Attaching a folder does exactly two things:
1. Creates a proxied DNS CNAME in Cloudflare pointing to `<tunnel-id>.cfargotunnel.com`
2. Appends a fenced `<VirtualHost>` block to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`

Then signals the running Apache process to reload config in-place (`httpd -k restart` — see §6).

The tunnel itself (`cloudflared`) is started by the user separately and runs as a catch-all: every hostname Cloudflare receives is forwarded to `http://localhost:80`. Apache name-based vhosts then route by `Host:` header to the correct `DocumentRoot`.

---

## 2. Hard Constraints

| Constraint | Detail |
|---|---|
| PHP 8.0+ | Served by XAMPP Apache. No CLI PHP scripts, no background workers. |
| Frontend | Tabler (Bootstrap-based) + Tabler Icons + vanilla JS (fetch). No React, no build step. Server-render in PHP. |
| State | JSON files on disk (`state/`). No database. JSON is cache + metadata, NOT source of truth. |
| Single app | No daemon, no Windows Service for this tool itself. |
| Tunnel | One Cloudflare Tunnel only. Never create a tunnel per route. |
| TLS | Terminates at Cloudflare edge. Local Apache is plain HTTP :80. |

---

## 3. File & Directory Map

```
C:\xampp\htdocs\tunnel-router\           ← the tool's own folder
│
├── index.php                            ← front-controller + page router
├── api.php                              ← JSON API handler (all /api/* routes)
│
├── src\
│   ├── Config.php                       ← read/write state/config.json; defaults
│   ├── State.php                        ← read/write state/routes.json
│   ├── CloudflareClient.php             ← CF API: zones, DNS records
│   ├── VhostManager.php                 ← fenced block add/remove/toggle; backup
│   ├── ApacheManager.php               ← httpd -t validate + detached UAC restart
│   ├── ProjectScanner.php              ← enumerate htdocs subfolders
│   ├── Reconciler.php                  ← diff routes.json vs live vhosts + CF DNS
│   ├── ActivityLog.php                 ← append/read state/activity.json
│   └── helpers.php                     ← atomicWrite(), backupFile(), sanitize…
│
├── views\
│   ├── layout.php                      ← Tabler shell (sidebar, nav, <head>)
│   ├── dashboard.php                   ← project cards with route status + toggle
│   ├── attach.php                      ← multi-step: pick → preview → confirm
│   ├── detach.php                      ← confirm modal before delete
│   ├── reconcile.php                   ← drift table + fix buttons
│   ├── settings.php                    ← form for config.json fields
│   └── activity.php                    ← paginated activity log
│
└── state\                              ← writable by Apache process user
    ├── config.json
    ├── routes.json
    ├── activity.json
    └── backups\                        ← timestamped vhosts copies
```

---

## 4. State Files

### `state/config.json`
```json
{
  "cloudflare": {
    "account_id": "",
    "api_token":  "",
    "tunnel_id":  "",
    "tunnel_name":""
  },
  "paths": {
    "htdocs":     "C:/xampp/htdocs",
    "vhosts_file":"C:/xampp/apache/conf/extra/httpd-vhosts.conf",
    "apache_bin": "C:/xampp/apache/bin/httpd.exe"
  },
  "defaults": {
    "www_alias":  false,
    "canonical":  "https"
  }
}
```

### `state/routes.json`
Array of route objects:
```json
[
  {
    "hostname":      "app.example.com",
    "type":          "subdomain",
    "zone_id":       "abc123",
    "project":       "my-app",
    "document_root": "C:/xampp/htdocs/my-app",
    "www_alias":     false,
    "dns_record_id": "rec_xyz",
    "enabled":       true,
    "label":         "My App",
    "created_at":    "2025-01-01T00:00:00Z",
    "updated_at":    "2025-01-01T00:00:00Z"
  }
]
```

### `state/activity.json`
Append-only array of log entries:
```json
[
  {
    "ts":      "2025-01-01T00:00:00Z",
    "action":  "attach",
    "hostname":"app.example.com",
    "project": "my-app",
    "ok":      true,
    "detail":  "DNS record rec_xyz created; vhost written; Apache reloaded"
  }
]
```

**Permissions:** `state/` should be locked so only Apache's process user can read it. The API token lives in `config.json` — never log it, never echo it in responses.

---

## 5. Vhost Fenced Blocks

Every generated vhost block is wrapped with unique sentinel comments so add/remove is deterministic even if the file was hand-edited around the block.

```apache
# >>> tunnel-router: app.example.com
<VirtualHost *:80>
    ServerName app.example.com
    ServerAlias www.app.example.com
    DocumentRoot "C:/xampp/htdocs/my-app"
    <Directory "C:/xampp/htdocs/my-app">
        Require all granted
        AllowOverride All
    </Directory>
</VirtualHost>
# <<< tunnel-router: app.example.com
```

`ServerAlias www.*` is only included when `www_alias = true`.

**Rules for VhostManager:**
- Before every write: create a timestamped backup in `state/backups/vhosts_YYYYMMDD_HHmmss.conf`
- Atomic write: write to a `.tmp` file, then `rename()` over the real file
- Remove: strip from `# >>> tunnel-router: <hostname>` through `# <<< tunnel-router: <hostname>` inclusive
- Toggle disable: comment out the `<VirtualHost>` block (keep sentinel lines so it can be re-enabled cleanly)

---

## 6. Apache Restart (CRITICAL — Windows XAMPP)

Apache is launched by the XAMPP Control Panel as a console process, **not** a Windows Service. This means:

- **DO NOT** use `Restart-Service`, `net stop/start`, `sc stop/start`, or `httpd -n "Apache2.4" -k restart`
- **DO NOT** kill the process — that desyncs the Control Panel's "Running" indicator
- **DO** use `httpd -k restart` (or `-k graceful`) to signal the running instance in-place

### Exact restart sequence in `ApacheManager.php`:

```
Step 1 — Validate config:
  exec('"C:\\xampp\\apache\\bin\\httpd.exe" -t 2>&1', $out, $code)
  if $code !== 0:
    restore backup (VhostManager::restoreLatestBackup())
    ActivityLog::error("httpd -t failed: " . implode("\n", $out))
    return ['ok'=>false, 'error'=>...]
    STOP — do not restart

Step 2 — Detached UAC-elevated restart:
  $ps = 'Start-Sleep -Milliseconds 500; '
      . 'Start-Process -Verb RunAs -FilePath "C:\\xampp\\apache\\bin\\httpd.exe" '
      . '-ArgumentList \'-k\',\'restart\'';
  pclose(popen(
    'start /B powershell -NoProfile -WindowStyle Hidden -Command "' . $ps . '"',
    'r'
  ));
  return ['ok'=>true]
```

The `Start-Sleep 500ms` gives PHP time to flush its HTTP response before Apache cycles.  
The UAC elevation prompt is acceptable — the tool is used interactively over RDP.  
No Scheduled Task is needed.

---

## 7. Cloudflare API

Base URL: `https://api.cloudflare.com/client/v4`  
Auth header: `Authorization: Bearer <api_token>`  
Token scopes required: `Zone:Read`, `DNS:Edit`

### Endpoints used:

| Method | Path | Purpose |
|---|---|---|
| GET | `/zones?name=<zone>` | Resolve zone name → zone_id |
| GET | `/zones` | List all zones for zone picker |
| GET | `/zones/<zone_id>/dns_records?name=<hostname>` | Check for existing record |
| POST | `/zones/<zone_id>/dns_records` | Create CNAME |
| PATCH | `/zones/<zone_id>/dns_records/<id>` | Update existing CNAME |
| DELETE | `/zones/<zone_id>/dns_records/<id>` | Remove on detach |

### DNS record payload:
```json
{
  "type":    "CNAME",
  "name":    "app.example.com",
  "content": "<tunnel-id>.cfargotunnel.com",
  "proxied": true,
  "ttl":     1
}
```

**Apex domain handling:**
- `name` field = zone root (e.g., `"example.com"`) — Cloudflare CNAME flattening handles this automatically
- Before creating apex CNAME: check for existing apex A or CNAME record
- If found: **prompt user to confirm replacement** before overwriting (never silently overwrite)
- Never touch MX records

---

## 8. JSON API Surface

All routes in `api.php`. Responses: `Content-Type: application/json`.

```
GET  /api/projects                         → [{name, path, has_route, route_status}]
GET  /api/zones                            → [{id, name}]  (from CF API, cached 5 min)
GET  /api/routes                           → routes.json array
POST /api/routes                           → attach
     body: {project, zone_id, subdomain|null, www_alias, label?}
     returns: {ok, hostname, dns_record_id, preview?}
DELETE /api/routes/{hostname}              → detach (confirm=1 required)
POST /api/routes/{hostname}/toggle         → enable/disable vhost block
GET  /api/reconcile                        → {routes: [{hostname, status, issues[]}]}
POST /api/reconcile/fix                    → body: {hostname, action}
GET  /api/activity                         → [{ts, action, hostname, ok, detail}]
GET  /api/preview                          → dry-run: returns vhost text + DNS payload
     query: ?project=&zone_id=&subdomain=&www_alias=
```

Status values from reconcile: `ok` | `dns_missing` | `vhost_missing` | `both_missing` | `drifted` | `dns_only` | `vhost_only`

---

## 9. Routing Logic in `index.php`

`index.php` is the front-controller. No `.htaccess` rewriting needed if you use query-string routing:

```
?page=dashboard    → views/dashboard.php
?page=attach       → views/attach.php
?page=detach       → views/detach.php (+ ?hostname=)
?page=reconcile    → views/reconcile.php
?page=settings     → views/settings.php
?page=activity     → views/activity.php
```

API calls go to `api.php` directly.

For development convenience, an `.htaccess` can rewrite `/api/*` to `api.php?route=$1` and `/` to `index.php?page=dashboard`.

---

## 10. Reconciler Logic

Source of truth = **live system** (vhosts file + Cloudflare DNS). `routes.json` is a cache.

```
reconcile():
  1. parseVhosts()  → hostnames found in fenced blocks in the vhosts file
  2. listCFRecords() → {hostname: dns_record_id} from CF API for the zones in routes.json
  3. routesJson = State::loadRoutes()

  For each entry in routes.json:
    - has_vhost = hostname in parseVhosts() result
    - has_dns   = hostname in listCFRecords()
    - match both → status=ok
    - vhost but no dns → status=dns_missing
    - dns but no vhost → status=vhost_missing
    - neither → status=both_missing

  For each fenced vhost NOT in routes.json → flag as drifted (hand-edited)
  For each CF DNS record pointing to tunnel that is NOT in routes.json → flag as dns_only
```

---

## 11. Frontend Patterns

- All views are rendered server-side in PHP and wrapped by `views/layout.php`
- The layout includes Tabler CSS/JS from CDN (pinned version — see §12)
- JS is inline `<script>` blocks in each view, using `fetch('/api/...')` for live data
- No page reloads for toggle/detach — use fetch + DOM update
- Attach flow is a multi-step form:
  1. Step 1: pick project from scan
  2. Step 2: pick zone, enter subdomain (or check apex), www option
  3. Step 3: preview panel showing exact vhost block + DNS record that will be created — **user must confirm**
  4. Step 4: submit → spinner → result

---

## 12. CDN Assets (pin these versions)

```html
<!-- Tabler CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
<!-- Tabler JS -->
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js" defer></script>
<!-- Tabler Icons (SVG sprite via CSS) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
```

---

## 13. Security Considerations

- `state/config.json` holds the CF API token. The `state/` directory must not be web-accessible. Add to `.htaccess` or Apache config: `<Directory ".../state"> Require all denied </Directory>`
- Never echo the API token in any response or log
- All user inputs going into shell commands must be escaped with `escapeshellarg()`
- The `project` name used in `DocumentRoot` must be validated against the actual htdocs directory listing (no path traversal: reject `.`, `..`, `/`, `\`, null bytes)
- `hostname` must match `/^[a-z0-9.-]+$/i` before use in DNS API calls or vhost config

---

## 14. Key Decisions & Rationale

| Decision | Why |
|---|---|
| Single Cloudflare Tunnel, catch-all | Avoids per-route tunnel provisioning; simplifies routing to Apache vhosts |
| `httpd -k restart` not stop/start | XAMPP Control Panel tracks process PID; killing it desyncs the UI |
| UAC-elevated detached PowerShell | `httpd.exe -k restart` needs elevated privileges; detached so PHP can respond first |
| Fenced vhost markers | Makes add/remove idempotent even across hand-edits |
| Atomic writes (tmp + rename) | Prevents partial writes from corrupting the vhosts file mid-Apache-reload |
| Validate before restart (`httpd -t`) | Never leave Apache in an unstartable state; restore backup on failure |
| JSON files, no DB | XAMPP is the only dependency; state is rebuildable from live system |
| Source of truth = live system | routes.json can drift; reconciler detects and fixes |
| CNAME flattening for apex | No dummy A record needed; no Cloudflare plan upgrade |
| Apex overwrite prompt | Email MX records must not be disrupted; user must confirm apex replacement |
