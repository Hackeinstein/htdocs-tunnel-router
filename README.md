# htdocs Tunnel Router

A local web tool for Windows + XAMPP that routes folders in `C:\xampp\htdocs` to public HTTPS hostnames through a single Cloudflare Tunnel.

---

## What's in v2

| Page | What it does |
|---|---|
| **Dashboard** | Project cards (click a card to open its **route page** with metrics, health, settings and DNS — or its **project page** if it isn't routed yet), bulk select (attach / enable / disable / expiry / detach), **Set / Edit expiry** button on every route (also on the domain, tunnel, detach and file-manager pages), **Share** button for temporary links |
| **Domains** → domain | Traffic charts, response codes, countries, per-route health, zone settings, cache purge, **Under Attack** toggle with auto-revert |
| **Tunnels** → tunnel | Status, edge connections, ingress, traffic, rename, clear stale connections, switch the tunnel used for new routes |
| **Files** | Hostinger-style file manager **per site** (each route's folder is its own sandbox): toolbar, right-click menu, drag-and-drop upload, rename/copy/move, compress/extract, permissions (chmod), trash. Opens files in a Monaco editor over the explorer with tabs, Ctrl+S, auto-save, version history and a **live preview** of the site that reloads on save. Reach it from the dashboard, a domain or a tunnel's route list |
| **Health** | Uptime and latency per route, alert inbox (route down/up, tunnel unhealthy, route expiring soon, Cloudflare unreachable) |
| **DNS** | Browse/edit A, AAAA, CNAME, TXT, MX, NS records; records owned by a route are protected; JSON/CSV import and export |
| **Backup** | Export/restore config, routes, vhosts and DNS (with a preview); automatic snapshots before bulk runs and restores |
| **Logs** | Apache and PHP logs with live tail |

**Extra API token permissions** (beyond Zone:Read + DNS:Edit): Account Analytics:Read, Zone Settings:Edit, Cache Purge, Cloudflare Tunnel:Read (Edit to rename/clean up tunnels). A missing permission greys out only the panel that needs it.

**There is no background monitor.** (Apache restarts do run in a short-lived helper process.) Route expiry, Under Attack auto-revert and health checks run from a once-a-minute tick that any open Tunnel Router page fires. Close every tab and nothing is checked until you open the tool again.

**There is no login.** Run it on localhost only. Do not route the tool itself through the tunnel — the Files page can write PHP into htdocs. State-changing requests require an `X-Requested-With` header, which stops other websites from driving it through your browser. The tool's own folder is hidden from, and refused by, the file manager.

Developer checks: `php dev/run_all.php` runs every test in `dev/` (unit tests per feature, plus `test_api.php`, which starts the real app and drives its HTTP API and syntax-checks every page's JavaScript if `node` is installed, and `test_concurrency.php`, which proves parallel writers don't lose updates).

---

## How It Works

One Cloudflare Tunnel (started by you separately) acts as a catch-all: every hostname Cloudflare receives is forwarded to `http://localhost:80`. Apache name-based vhosts route by `Host:` header to the correct `DocumentRoot`.

**Attaching a folder does exactly two things:**
1. Creates a proxied CNAME DNS record in Cloudflare pointing to `<tunnel-id>.cfargotunnel.com`
2. Appends a fenced `<VirtualHost>` block to `httpd-vhosts.conf`

Then signals the running Apache process to reload config in-place.

TLS terminates at Cloudflare's edge — local Apache stays plain HTTP on `:80`.

---

## Prerequisites

### 1. XAMPP on Windows, with PHP 8.1 or newer
In `C:\xampp\php\php.ini` these must be enabled (the **Settings → Diagnostics** check tells you which are missing):

```ini
extension=curl
extension=zip
extension=fileinfo
extension=mbstring
```
`exec`, `popen` and `proc_open` must not be in `disable_functions` (Apache validation and the reload helper need them).

### 2. Start the XAMPP Control Panel "as administrator" (recommended)
Apache then runs elevated and the tool can restart it without a UAC prompt. See **Apache Reload** below.

### 3. One pre-created Cloudflare Tunnel (catch-all)
Create the tunnel once before using this tool:
```
cloudflared tunnel create my-tunnel
```
Configure it as a catch-all (in the tunnel's config YAML or via the Cloudflare dashboard):
```yaml
ingress:
  - service: http://localhost:80
```
Then run it with `cloudflared tunnel run my-tunnel`. The Tunnel Router does **not** manage the `cloudflared` process.

### 4. A Cloudflare API token
Create one at https://dash.cloudflare.com/profile/api-tokens (or an account-owned token). Minimum: **Zone:Read** and **DNS:Edit**. For the Domains, Tunnels and Health pages also add **Account Analytics:Read**, **Zone Settings:Edit**, **Cache Purge**, **Cloudflare Tunnel:Read** (Edit to rename or clean up tunnels).

### 5. Internet access (only for the code editor)
The interface itself is self-contained: the stylesheet, icons, shared script and charting library are local files in `assets/`. Only the **code editor** (Monaco, on the Files page) is fetched from the jsDelivr CDN the first time you open a file (pinned version, integrity-checked loader). Everything else works offline.

### 6. Keep it private
The tool only answers on this computer (`http://localhost/…`); requests through the tunnel or under any other hostname get a 403 (see **Access** in Settings). It has no login, so this is what protects it. **Never** attach the tool's own folder as a route.

---

## Performance notes

- **Pages paint at once and fill in.** Cloudflare takes ~1 s per call, so pages show what the tool already knows immediately (routes, settings) and fill in analytics and live health as they arrive.
- **Independent Cloudflare calls run in parallel** (tunnel details, domain settings + DNS, analytics, all 19 zones in the rebuild scan) and results are cached for 20–120 s. Changing a route, a DNS record or a setting drops the relevant cache. If Cloudflare is unreachable, the last known values are shown instead of an error.
- **Apache compresses and caches** pages, JSON and assets via `.htaccess` (needs `mod_deflate`, `mod_headers`, `mod_expires` — all on in XAMPP). Assets carry `?v=<modified time>` so a year-long cache never goes stale.
- **No build step to run the tool.** `assets/app.css` (Tailwind, precompiled), `assets/icons.js` (the ~100 Lucide icons used) and `assets/apexcharts.min.js` are committed. Only if you change the interface: `sh dev/build_assets.sh` (needs Node and internet, developers only).
- Idle tabs don't poll: the once-a-minute tick pauses while a tab is hidden.

---

## Installation

1. Copy the project folder into `C:\xampp\htdocs\tunnel-router\` (do not copy `.git`, `dev` or any `state\*.json`).
2. The `state\` folder is created automatically on first run. If Apache runs as a restricted user, give it write access to `tunnel-router\state\`.
3. Open `http://localhost/tunnel-router/` — you are sent to **Settings** on the first run.
4. Run `C:\xampp\php\php.exe dev\run_all.php` any time to check the install (optional; needs `node` only for the JavaScript syntax checks).

---

## First-Run Setup

Fill in the Settings page:

| Field | Where to find it |
|---|---|
| **API Token** | Cloudflare → Profile → API Tokens |
| **Account ID** | Cloudflare dashboard URL: `dash.cloudflare.com/<account-id>` |
| **Tunnel ID** | `cloudflared tunnel list` or the Cloudflare Zero Trust dashboard |
| **Tunnel Name** | Display label for the sidebar badge (any text) |
| **htdocs Folder** | Usually `C:/xampp/htdocs` |
| **VHosts Config File** | Usually `C:/xampp/apache/conf/extra/httpd-vhosts.conf` |
| **Apache Binary** | Usually `C:/xampp/apache/bin/httpd.exe` |

Click **Test Connection** to verify the API token works before saving.

---

## Attaching a Route

1. Go to **Attach Route** from the sidebar
2. Pick the htdocs project folder you want to expose
3. Choose a Cloudflare zone (domain), enter a subdomain (or check "apex" for the root domain)
4. Review the **preview panel** showing the exact DNS record and Apache vhost block that will be created
5. Click **Confirm & Apply**

The tool will:
- Create the CNAME in Cloudflare
- Write the fenced vhost block to `httpd-vhosts.conf`
- Validate the config with `httpd -t`
- Signal Apache to reload
- If validation fails: delete the DNS record, restore the vhosts backup, report the error

---

## Apache Reload

After any change to the vhosts (attach, detach, enable/disable, restore, expiry) the tool validates the config with `httpd -t` and then restarts Apache **by itself**, in a helper process (`reload_worker.php`) so a restart can't cut off the page that asked for it. The page returns immediately and shows progress as a toast and, if something goes wrong, a red banner.

Each restart is **verified**: the helper only counts it as done once Apache writes "resuming normal operations" to its own `error.log` after the command ran. A command that exits cleanly but doesn't restart anything is not trusted. Methods are tried in order until one is confirmed:

1. `httpd -k restart` — no prompt; works when this tool and Apache run as the same user.
2. The same command through a **UAC prompt** (turn off in Settings → Apache reload if you don't want prompts).
3. A **hard restart** (kill and start `httpd.exe`) — off by default; the XAMPP Control Panel may show Apache as stopped until you click Refresh.

**To never see a UAC prompt:** start the XAMPP Control Panel "as administrator". Apache then runs elevated and method 1 just works.

If nothing is confirmed, the config is still saved and checked; it applies the next time Apache restarts, and Settings → Apache reload shows exactly what each method printed. **Test reload now** on that card runs the whole thing on demand so you can see it work (or not) on your machine.

Requirements: `php.exe` at `<xampp>\php\php.exe` (standard) and `exec`/`popen` not blocked in `php.ini`.

---

## Apex Domains

To route a bare domain (e.g., `example.com` itself, not `app.example.com`):
- Check the **"Use apex (root domain)"** option in the Attach wizard
- If an existing A or CNAME record is found for the zone root, you will be prompted to confirm replacement
- MX records are never touched — email delivery is not affected

Cloudflare's CNAME flattening handles apex CNAMEs automatically. No Cloudflare plan upgrade is required.

---

## Getting your existing sites back (rebuild / import)

The dashboard decides what is "routed" from the tool's own list, `state/routes.json`. If that file is missing, empty or from another install, every project shows **Not routed** even though the DNS records and vhost blocks are still live. Fix it without touching Apache or Cloudflare:

- **Rebuild from your live setup** — *Reconcile → Scan my setup.* Reads the fenced blocks in `httpd-vhosts.conf` and every Cloudflare zone for CNAMEs pointing at this tunnel, then lets you tick what to add back. Folder paths from another machine are matched to the same-named folder in this htdocs.
- **Import an old `routes.json`** — *Backup → Import an old routes.json.* Previews first, validates every entry, rebuilds folder paths for this machine.
- **Copying the old `state/routes.json` straight into `state/`** also works (same format), but the import above is safer: it validates, never overwrites entries you already have, and takes a snapshot first. Do **not** copy an old `config.json` over a working one unless you mean to (it holds the token and paths).

Both only ever write `routes.json`. A site that only has a DNS record (no vhost) is listed as "DNS only" — connect it to a project from **Attach Route** (re-attaching reuses the existing record).

---

## Reconcile

The **Reconcile** page compares three sources of truth and flags drift:
- `state/routes.json` (the tool's cache)
- Live Apache vhosts file (parsed for fenced blocks)
- Live Cloudflare DNS (records pointing to your tunnel)

Possible statuses:

| Status | Meaning |
|---|---|
| `ok` | DNS record + vhost block both present and tracked |
| `dns_missing` | VHost block exists, no matching DNS record |
| `vhost_missing` | DNS record exists, no vhost block |
| `both_missing` | Neither found — stale routes.json entry |
| `drifted` | VHost block exists but not in routes.json (hand-edited) |
| `dns_only` | DNS CNAME points to tunnel but no vhost block or routes.json entry |

Each drift type has a one-click fix button.

---

## Users & the file portal

Give other people access to the files of **specific sites** without giving them this admin tool.

- **How it works.** You pick one public address for the portal (Settings → *File portal*, e.g. `files.yourdomain.com`). People log in there with a username and password and see only the sites you assigned. The portal is served from the `public/` folder alone, so none of the admin pages, `src/` or `state/` are reachable through it. This admin tool still only answers on this computer.
- **Set-up.** Settings → *File portal* → choose a domain and name → *Turn on* (creates the DNS record and Apache entry). Then: **Users → New user**, give them access under *Sites*, and send the one-time invitation link. They choose their own password; you never see it. *Reset* gives a new link. Maximum 50 users.
- **Per site.** Each route has a switch **File access for users** (off by default; also in the Attach wizard). Off, or the route disabled/expired, locks everyone out of that site instantly. Per user and site you choose upload / edit / delete rights (viewing is always included) and an optional end date.
- **Limits.** 25 MB per upload and 500 MB per site by default (Settings). Deleted files go to the site's trash.
- **Zip files.** Users can upload a `.zip` and extract it (into a new folder, or in place), and download any folder, or the whole site, as a zip. Every item inside an archive follows the same file rules as a normal upload; skipped items are reported. Needs PHP's `zip` extension (on in XAMPP). Large archives also need `upload_max_filesize` / `post_max_size` in php.ini raised (XAMPP's default is 40 MB).
- **Code files — read this.** By default users cannot upload, open or change programs (PHP and similar) or settings/secret files (`.env`, `.sql`, keys, logs, …). The per-user switch **Allow code files** removes that. Anyone who can put a PHP file on a site can run programs on this computer and read everything on it, including your Cloudflare token — switch it on only for people you fully trust.
- **Safety built in.** Passwords are stored hashed (bcrypt); repeated wrong logins lock the account and the address for 15 minutes; sessions expire after 2 h idle / 12 h total and can be ended for a user at any time (*Sign out everywhere*, disabling or deleting them); files are only ever served as downloads; every action is logged (user's clock icon on the Users page).
- **Backups** do not include users or password hashes (`state/users.json`); copy that file yourself if you need it.
- **Portal front end** lives in `assets/portal.js`; `dev/build_assets.sh` copies it with the CSS and icons into `public/assets/`. Re-run it after changing any of them.

## State Files

All state lives in the `state\` directory (never web-accessible):

| File | Purpose |
|---|---|
| `state/config.json` | API credentials and paths |
| `state/routes.json` | Route metadata cache (rebuildable from live system) |
| `state/activity.json` | Append-only action log (capped at 1000 entries) |
| `state/zones_cache.json` | Cloudflare zone list cache (5-min TTL) |
| `state/backups/` | Timestamped vhosts file backups (created before every edit) |

**The API token is stored in `state/config.json`.** Ensure the `state\` directory is not readable by other users on the machine.

---

## Troubleshooting

**Apache does not reload after attaching a route**
- The UAC prompt may have timed out. Try the operation again and click "Yes" promptly.
- Check that `apache_bin` in Settings points to the correct `httpd.exe`.

**"Apache config validation failed"**
- The vhosts file was not changed (backup restored if one existed).
- Check the error output — it is the raw output of `httpd -t`.
- Common cause: syntax error in `httpd-vhosts.conf` from a previous manual edit.

**Cloudflare API errors**
- "Authentication error" → token is wrong or expired.
- "insufficient permissions" → token is missing Zone:Read or DNS:Edit scopes.
- The Dashboard still renders from local state if CF API is temporarily unavailable.

**State directory not writable**
- Grant the Apache process user (typically `SYSTEM` or `NT AUTHORITY\NetworkService` on Windows) write access to `tunnel-router\state\` and `tunnel-router\state\backups\`.

---

## Security Notes

- The CF API token is stored in `state/config.json`. The `state/` directory is protected by `.htaccess` (`Require all denied`) and should not be accessible over HTTP. Backups exclude the token unless you tick the box.
- The token is never logged, never echoed in API responses, and never pre-filled in the Settings form (write-only).
- All shell arguments (paths, hostnames) are validated before use — no shell injection.
- Project names and hostnames are validated against strict allowlists before use in DNS calls or Apache config writes.
