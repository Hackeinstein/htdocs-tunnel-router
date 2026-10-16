# Tunnel Router v2 — Task List

Status: `[ ]` todo · `[-]` deliberately skipped · `[~]` in progress · `[x]` done. Work top to bottom; each phase ships on its own.
Background and rationale live in `suggestion.md`.

Done so far: Phase 4 bulk operations (tested with dev/test_bulk.php); Phase 3 expiry + share links (tested with dev/test_expiry.php); Domains / Domain detail / Tunnels / Tunnel detail pages; Phase 1 UI rebuild (Tailwind v4 CDN + HyperUI patterns, Lucide, ApexCharts, dark mode).

---

## Phase 1 — UI rebuild (HyperUI + Lucide + ApexCharts)

Stack, no build step: Tailwind v4 browser build (CDN), HyperUI markup (application: side-menu, stats, tables, tabs, modals, toasts, toggles, empty-states, loaders; dark variants), Lucide via CDN (`data-lucide`, re-run `createIcons()` after each render), ApexCharts via CDN.

- [x] 1.1 New layout shell: HyperUI side menu, header, toasts, dark mode toggle
- [x] 1.2 Shared JS (`UI` helpers) rewritten for Tailwind + Lucide; replace the hand-made SVG bars with ApexCharts (area for requests, donut for status codes, sparklines on cards)
- [x] 1.3 Port existing pages: dashboard, attach, detach, reconcile, activity, settings
- [x] 1.4 Port new pages: domains, domain, tunnels, tunnel
- [x] 1.5 Remove Tabler CSS/JS and tabler-icons

## Phase 3 — Route expiry and share links

- [x] 3.1 `expires_at` on routes (null = permanent)
- [x] 3.2 Duration picker when attaching: 2h, 6h, 12h, 1d, 3d, 7d, 30d, 90d, 6mo, 12mo, custom date/time, or never
- [x] 3.3 Set / extend / remove expiry later from the route (dashboard, domain, bulk)
- [x] 3.4 Expiry enforced by a once-a-minute tick from any open page (no background worker); action on expiry is a setting: disable (reversible) or detach (remove DNS + vhost)
- [x] 3.5 Countdown badge on cards; warning when under 1h left
- [x] 3.6 Share link: one-click temporary route with random subdomain, copyable URL, default 2h

## Phase 4 — Bulk operations

- [x] 4.1 Multi-select on dashboard
- [x] 4.2 Bulk attach (pattern like `{project}.dev.example.com`), detach, enable/disable, set expiry
- [x] 4.3 Per-item result report; one failure never aborts the rest

## Phase 5 — Under Attack mode

- [x] 5.1 One-click toggle per domain (zone security level `under_attack`), shown prominently on domain page and dashboard
- [x] 5.2 Optional auto-revert timer (checked by the page tick)
- [-] 5.3 Per-hostname variant via a managed-challenge rule — not built (marked "only if wanted"; zone-wide toggle covers the request)

## Phase 6 — DNS browser

- [x] 6.1 List all records per zone with search/filter
- [x] 6.2 Add / edit / delete any record type; proxied toggle
- [x] 6.3 Warn before touching records that belong to tunnel routes
- [x] 6.4 Import / export zone records (JSON/CSV)

## Phase 7 — Export / restore

- [x] 7.1 Export bundle: config (token optional), routes, expiries, vhost/junction map, DNS records for routes
- [x] 7.2 Restore with dry-run preview, then apply; idempotent
- [x] 7.3 Automatic backup before bulk and restore operations

## Phase 8 — Health monitor and alerts

- [x] 8.1 Page tick probes routes on an interval while the tool is open, stores history (no background worker, so no uptime data while closed)
- [x] 8.2 Real uptime %, latency chart, incident list on domain/tunnel pages
- [x] 8.3 Alerts on down / tunnel offline / drift / expiring token (channel TBD)

## Phase 9 — File manager and code editor (mini hosting panel)

- [x] 9.1 Backend: sandboxed to htdocs (realpath checks, no `..`, no symlink escape, tool's own `state/` and `src/` blocked), size limits, audit log
- [x] 9.2 File tree + file manager: browse, create, rename, move, copy, delete (to trash with undo), upload (drag/drop), download, zip/unzip
- [x] 9.3 Code editor (Monaco via CDN): tabs, syntax highlight, search/replace, save with Ctrl+S, conflict warning if file changed on disk, auto-backup of previous version
- [x] 9.4 Project view: open a routed project directly from its route card; `.htaccess` editor; image/text preview
- [x] 9.5 Log viewer: Apache access/error logs, PHP error log, live tail
- [-] 9.6 Later: terminal, database link, one-click project templates — not built (listed as "later")

## Phase 10 — Per-site file manager (rework of Phase 9 UI)

- [x] 10.1 Sandbox rooted at one site's folder (`?site=<hostname>` / `?project=<folder>`); per-site trash and version history
- [x] 10.2 Site picker, Hostinger-style toolbar, folder tree, breadcrumb, sortable table, search, multi-select (ctrl/shift), right-click menu, keyboard shortcuts, drag-and-drop upload
- [x] 10.3 Compress to archive, extract, permissions dialog (chmod), copy/move to a folder
- [x] 10.4 Editor opens over the explorer: tabs, language picker, word wrap, auto-save, versions, split **live preview** (reloads on save, desktop/tablet/phone widths)
- [x] 10.5 Entry points from the dashboard, domain page and tunnel route list

- [x] 3.7 One shared, labelled **Set expiry / Edit expiry** control (with an "Expired … ago" state) on the dashboard, domain page, tunnel route list, detach page, attach success page and the file manager header

## Phase 11 — Reliable Apache reload (Windows)

- [x] 11.1 Reload helper process (`reload_worker.php`) so a restart can't kill the request that asked for it
- [x] 11.2 Strategies in order: graceful restart → elevated (UAC, optional) → hard restart (opt-in)
- [x] 11.3 Each attempt verified against Apache's error log ("resuming normal operations"), not just the exit code
- [x] 11.4 Result in `state/reload.json`; toast, failure banner, and a Settings card with "Test reload now" and per-method output
- [ ] 11.5 **Needs a run on the real Windows machine** — the strategies can only be fully exercised against a real httpd.exe

## Phase 12 — Recovering existing sites

- [x] 12.1 Rebuild routes.json from the live vhost blocks + every zone's tunnel CNAMEs (read-only, snapshot first, never overwrites)
- [x] 12.2 Import an old routes.json (validated; folder paths rebuilt for this machine)
- [x] 12.3 Dashboard hint when nothing is tracked yet

## Phase 13 — Performance

- [x] 13.1 Measured first (page/API timings, page weight, idle DOM churn) — see README "Performance notes"
- [x] 13.2 Fixed an endless icon re-render loop (Lucide `createIcons` + MutationObserver): hundreds of DOM rewrites per 2 s on an idle page → 0
- [x] 13.3 Precompiled Tailwind (46 KB, was a 282 KB in-browser compiler), local icon subset (17 KB, was 447 KB), ApexCharts loaded only when a chart is drawn, shared `ui.js` as a cached file instead of ~25 KB inlined in every page
- [x] 13.4 Cloudflare client: reusable connection, forced IPv4, parallel `requestMany`; tunnel/domain/route data fetched in parallel and cached with explicit invalidation and stale-on-error
- [x] 13.5 Pages render progressively (dashboard no longer waits for the DNS check; domain/tunnel/route fill in analytics and health as they arrive)
- [x] 13.6 gzip + year-long immutable caching for versioned assets, `no-store` for the API, config parsed once per request, background ticks paused on hidden tabs
- [x] 13.8 Dashboard cards open their page when clicked anywhere on the body (buttons, links and the checkbox keep their own behaviour); new **project page** for projects without a route (folder facts, files, attach, share) that also lists a project's routes
- [x] 13.7 Per-route page (`?page=route&host=…`), and domain dropdowns show attached-route counts

## Carried over

- [x] Input validation audit (Config::clean, preview/reconcile inputs, shell-path guard, CSRF header, sanitizeProjectName bug) · [x] Error handling audit (attach DNS rollback on vhost failure, clearer unwritable-folder error) · [x] Automated tests (dev/test_*.php; Insights GraphQL parsing is untested without a real token)
- [ ] Decide on `config.yml` (commit vs `.gitignore`)

---

## Out of scope (decided)

cloudflared manager, boot setup, no-restart Apache, login/auth, cross-platform reload, background worker / Task Scheduler.

## Defaults I'm using unless told otherwise

- On expiry: **disable** the route (setting lets you switch to detach)
- Alerts: **in-app** only (channel can be added later)
- Editor: **Monaco**
- No login: the tool is assumed localhost-only. The file manager still sandboxes to htdocs, but with no auth, do not expose this tool through the tunnel.

## Phase 8 — Users & file portal

- [x] 8.1 JSON user store (invites, bcrypt, grants with permissions + end date, 50 users) — `dev/test_users.php`
- [x] 8.2 File policy (code/secret files blocked unless trusted), quotas, per-site sandbox — `dev/test_portal_policy.php`, `dev/test_portal_files.php`
- [x] 8.3 Public portal (`public/`): login throttling, sessions, CSRF, CSP, download-only serving — `dev/test_portal.php`
- [x] 8.4 Portal address set-up/rollback (DNS + vhost) — `dev/test_portal_setup.php`
- [x] 8.5 Admin: Users page, route card, Attach toggle, Settings card; portal front end
- [-] bulk-attach portal switch
