# Suggestions

Planning notes for the next round of work. `TASKS.md` (the old build list) was removed; a new task list will be written once the open questions at the bottom are answered.

---

## Done so far

- Domains page + per-domain metrics/settings page (`?page=domains`, `?page=domain&zone=`)
- Tunnels page + per-tunnel metrics/settings page (`?page=tunnels`, `?page=tunnel&id=`)
- Partially covers #1 (tunnel status) and #7 (health probes: on-demand only, no background monitor yet)

---

## Priority: pain points reported

### A. cloudflared won't start from the yml

Cause: `config.yml` has `tunnel:` and `ingress:` but no `credentials-file:`, so `cloudflared tunnel run` can't authenticate. Other common causes:
- `--config <path>` must come before `tunnel run`: `cloudflared --config C:\path\config.yml tunnel run`
- The MSI install location is usually not on PATH.

Proposed feature, **cloudflared manager**:
- Locate the binary (usual MSI paths, or a path set in Settings).
- Generate a working config from tunnel ID, credentials JSON path and ingress rules; show the exact command used.
- Start / stop / status from the UI, with the last log lines.
- Install as a service so it starts at boot:
  - Token tunnel (remotely managed): `cloudflared service install <token>`.
  - Local-yml tunnel: the config must live in the service account's `.cloudflared` folder; the tool can copy it there.

### B. Apache needs a manual restart after adding a route

Current reload (`httpd -k restart`, elevated) is unreliable when Apache runs as a console process from the XAMPP Control Panel.

Options:
1. **Fix the reload**: stop/start the way XAMPP does (`apache_stop.bat` / `apache_start.bat`, or kill and relaunch `httpd.exe`). Simple, but still a restart (brief outage, UAC prompt).
2. **Remove the need to reload (recommended)**: one catch-all vhost that resolves the folder from the hostname, using a mapping file Apache re-reads on change. New routes work instantly. Needs a one-time vhost change and a hostname-to-folder convention.

### C. Boot setup

Windows scheduled task or service that starts Apache and cloudflared in order at machine start.

---

## Tunnel management

1. **Tunnel status panel**: is `cloudflared` connected, connectors, version, location (`GET /accounts/{id}/cfd_tunnel/{id}`). Show a down tunnel on the dashboard.
2. **Remote ingress management**: for remotely managed tunnels, edit ingress rules via the API (per-hostname rules, projects on other local ports, not just catch-all to `:80`).
3. **Start/stop cloudflared from the UI** (covered by A).

## More Cloudflare

4. **Per-route security**: Cloudflare Access (login in front of a site), WAF / rate-limit presets, "Under Attack" mode. Access for private projects is likely the most useful.
5. **Cache and SSL controls**: purge cache per hostname, dev mode toggle, SSL status.
6. **Analytics per route**: requests, bandwidth, errors from the GraphQL analytics API, on the dashboard cards.
7. **Health checks**: probe each live hostname; show up / down / tunnel-ok-but-Apache-down, with history.
8. **Zone and DNS browser**: view and edit non-tunnel DNS records, import/export.

## Workflow

9. **Bulk attach/detach and route templates** (e.g. `{project}.dev.example.com`, wildcard routes).
10. **Custom vhost options**: per-route PHP settings, `.htaccess` allowances, custom DocumentRoot subfolder (e.g. `public/`), extra aliases.
11. **Share-link mode**: temporary route that auto-expires, for demos.
12. **Config export/restore**: back up and restore routes, vhosts and DNS to move machines.

## Tool itself

13. **UI authentication**: currently anyone who can reach the page controls DNS and Apache.
14. **Cross-platform reload**: macOS/Linux Apache support; drop the UAC-only path.
15. **Notifications**: alert on drift, tunnel down, expiring token.

## Carried over from the old task list (never finished)

- Input validation audit (traversal, hostname regex, `escapeshellarg()`, token never in responses/logs).
- Error handling audit (CF API down -> dashboard still renders; partial attach rollback; `state/` permission errors).
- `dev/test_cf.php` was listed but the `dev/` folder does not exist.
- No automated tests.

## Housekeeping

- `config.yml` is untracked and contains a tunnel ID: commit it or add it to `.gitignore`.

---

## Recommended first batch

13 (auth), 1 (tunnel status), 7 (health checks), 4 (Access), plus A and B above at the very top.

## Open questions

1. Which OS is the live machine? README says Windows, but this checkout is under `/Applications/XAMPP` (macOS).
2. Tunnel created with `cloudflared tunnel create` (local yml + credentials JSON) or in the dashboard (token)? This decides whether #2 (remote ingress) is possible.
3. No-restart catch-all vhost approach, or just fix the restart?
