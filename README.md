# htdocs Tunnel Router

A local web tool for Windows + XAMPP that routes folders in `C:\xampp\htdocs` to public HTTPS hostnames through a single Cloudflare Tunnel.

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

### 1. XAMPP with Apache running via Control Panel
- Apache must be started via the XAMPP Control Panel (not as a Windows Service)
- This tool controls Apache by signalling the running process — it does NOT use `net start/stop` or Windows Services

### 2. One pre-created Cloudflare Tunnel (catch-all)
Create the tunnel once before using this tool:
```
cloudflared tunnel create my-tunnel
```

Configure it as a catch-all (in the tunnel's config YAML or via the Cloudflare dashboard):
```yaml
ingress:
  - service: http://localhost:80
```

Then run it:
```
cloudflared tunnel run my-tunnel
```

Keep this process running (e.g., as a startup script or background task). The Tunnel Router does **not** manage the `cloudflared` process.

### 3. Cloudflare API Token
Create a token at https://dash.cloudflare.com/profile/api-tokens with these scopes:
- **Zone:Read** — to list your zones
- **DNS:Edit** — to create/delete CNAME records

### 4. PHP `curl` extension enabled
In `C:\xampp\php\php.ini`, ensure this line is uncommented:
```ini
extension=curl
```

---

## Installation

1. Copy the `tunnel-router` folder into `C:\xampp\htdocs\`:
   ```
   C:\xampp\htdocs\tunnel-router\
   ```

2. Ensure Apache can write to the `state\` and `state\backups\` subdirectories. If Apache runs as a restricted user, grant write access to those folders.

3. Open the tool in your browser:
   ```
   http://localhost/tunnel-router/
   ```

4. You will be redirected to **Settings** on first run.

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

## Apache Reload — UAC Prompt

When applying vhost changes, the tool fires:

```
Start-Process -Verb RunAs -FilePath "C:\xampp\apache\bin\httpd.exe" -ArgumentList '-k','restart'
```

**Windows will show a UAC (User Account Control) elevation prompt.** Click "Yes" to allow Apache to reload. This is expected — `httpd -k restart` requires elevation.

The HTTP response from the tool is sent before Apache cycles, so you will not see a broken page during reload.

### Why not a Windows Service?
Apache under XAMPP Control Panel is **not** a Windows Service. It is a console process whose PID is tracked by the Control Panel's green/red indicator. Stopping it via `net stop` or `sc stop` would desync that indicator. This tool always uses in-place reload (`httpd -k restart`), never stop/start.

---

## Apex Domains

To route a bare domain (e.g., `example.com` itself, not `app.example.com`):
- Check the **"Use apex (root domain)"** option in the Attach wizard
- If an existing A or CNAME record is found for the zone root, you will be prompted to confirm replacement
- MX records are never touched — email delivery is not affected

Cloudflare's CNAME flattening handles apex CNAMEs automatically. No Cloudflare plan upgrade is required.

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

- The CF API token is stored in `state/config.json`. The `state/` directory is protected by `.htaccess` (`Require all denied`) and should not be accessible over HTTP.
- The token is never logged, never echoed in API responses, and never pre-filled in the Settings form (write-only).
- All shell arguments (paths, hostnames) are validated before use — no shell injection.
- Project names and hostnames are validated against strict allowlists before use in DNS calls or Apache config writes.
