<?php $host = strtolower(preg_replace('/[^a-zA-Z0-9.-]/', '', $_GET['host'] ?? '')); ?>
<?= pageHeader('Route', '', '', ['Dashboard', '?page=dashboard'], 'title') ?>
<div id="root"><script>document.write(UI.loading('Loading route…'))</script></div>
<script>
const HOST = <?= json_encode($host) ?>;
const E = UI.esc, dash = '<span class="muted">…</span>', R = p => '/api/routes/' + encodeURIComponent(HOST) + p;
let charts = [], D = null;

/* The page fills in three steps:
   1. /detail     — everything this tool knows + the live DNS record      (instant)
   2. /analytics  — this hostname's traffic for the last 24 h (Cloudflare GraphQL; slowest, cached 2 min)
   3. /probe      — a fresh check through the tunnel                       */
async function load() {
  const root = document.getElementById('root');
  charts.forEach(c => c.destroy()); charts = [];
  let d; try { d = D = await UI.api(R('/detail')); } catch (e) { root.innerHTML = UI.errBox(e.message) + '<p class="mt-3"><a class="text-indigo-600 underline" href="?page=dashboard">Back to the dashboard</a></p>'; return; }
  const r = d.route, h = d.health, enabled = r.enabled !== false, expired = !!r.expired_at && !r.expires_at;
  document.getElementById('title').textContent = r.hostname;

  const problems = [];
  if (d.dns_error) problems.push(['yellow', 'Could not read Cloudflare DNS: ' + d.dns_error]);
  else if (r.zone_id && !d.dns) problems.push(['red', 'No DNS record exists for this hostname in Cloudflare — visitors cannot reach it. Re-attach it, or fix it on the Reconcile page.']);
  else if (d.dns && !d.dns.tunnel) problems.push(['yellow', 'Its DNS record does not point at a tunnel (' + d.dns.type + ' → ' + d.dns.content + ').']);
  if (!d.vhost) problems.push(['red', 'No Apache vhost block for this route — requests would fall through to the default site.']);
  else if (!d.vhost.enabled && enabled) problems.push(['yellow', 'The vhost block is commented out but the route is marked enabled.']);
  if (expired) problems.push(['gray', 'Switched off automatically ' + UI.ago(r.expired_at) + ' ago when its expiry passed. Enable it again below.']);

  root.innerHTML =
    problems.map(([c, t]) => `<div class="mb-3 flex gap-3 rounded-lg border p-3 text-sm ${c === 'red' ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' : c === 'yellow' ? 'border-yellow-300 bg-yellow-50 text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200' : 'border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300'}">${UI.i('triangle-alert')}<div>${E(t)}</div></div>`).join('') +
    `<div class="mb-6 flex flex-wrap items-center gap-2">
      <span id="now">${enabled ? dash : UI.badge('yellow', 'Disabled')}</span>
      ${d.zone_name ? `<a class="badge badge-gray hover:underline" href="?page=domain&zone=${r.zone_id}">${UI.i('globe', '!size-3')}${E(d.zone_name)}</a>` : ''}
      ${UI.badge('gray', r.type === 'apex' ? 'Apex' : 'Subdomain')}${r.www_alias ? UI.badge('blue', '+ www') : ''}
      <span class="ml-auto flex flex-wrap gap-2">
        <a class="btn btn-outline" href="https://${E(r.hostname)}" target="_blank" rel="noopener">${UI.i('external-link')}Open site</a>
        <a class="btn btn-outline" href="?page=files&site=${encodeURIComponent(r.hostname)}">${UI.i('folder-code')}Files</a>
        <button class="btn btn-outline" id="toggle">${UI.i(enabled ? 'toggle-right' : 'toggle-left')}${enabled ? 'Disable' : 'Enable'}</button>
        <button class="btn btn-ghost-danger" id="detach">${UI.i('trash')}Detach</button></span></div>

    <div id="stats" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
      ${UI.stat('heart-pulse', 'gray', dash, 'Now')}
      ${UI.stat('gauge', 'green', h && h.uptime24 != null ? h.uptime24 + '%' : '—', 'Uptime', '24h')}
      ${UI.stat('timer', 'blue', h && h.avg_ms != null ? h.avg_ms + ' ms' : '—', 'Avg latency', '24h')}
      ${UI.stat('arrow-left-right', 'green', dash, 'Requests', '24h')}
      ${UI.stat('cloud-download', 'purple', dash, 'Bandwidth', '24h')}</div>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
      <div class="card lg:col-span-2"><h2 class="card-h">Requests per hour</h2><div id="reqChart" class="min-h-[240px]">${UI.loading('Loading traffic…')}</div></div>
      <div class="card"><h2 class="card-h">Responses</h2><div id="statusChart" class="min-h-[220px]"></div></div></div>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
      <div class="card lg:col-span-2"><div class="mb-3 flex items-center justify-between"><h2 class="card-h !mb-0">Health history</h2>
          <button class="btn btn-outline" id="check">${UI.i('activity')}Check now</button></div>
        ${h && h.series && h.series.length > 1 ? '<div id="latChart"></div>' : '<p class="muted text-sm">Not enough checks yet — health is sampled every couple of minutes while any Tunnel Router page is open.</p>'}
        <p class="muted mt-2 text-xs">${h ? h.samples + ' checks' + (h.since ? ' since ' + new Date(h.since * 1000).toLocaleDateString() : '') + (h.uptime7 != null ? ' · 7-day uptime ' + h.uptime7 + '%' : '') : ''}</p>
        ${d.alerts.length ? `<h3 class="mt-4 mb-2 text-sm font-semibold">Recent alerts</h3><ul class="space-y-1 text-sm">${d.alerts.map(a => `<li class="flex gap-2">${UI.i(a.severity === 'error' ? 'circle-alert' : a.severity === 'warn' ? 'triangle-alert' : 'info', 'mt-0.5 shrink-0 ' + (a.severity === 'error' ? 'text-red-600' : a.severity === 'warn' ? 'text-yellow-600' : 'text-blue-600'))}<span>${E(a.msg)} <span class="muted text-xs">${new Date(a.ts * 1000).toLocaleString()}</span></span></li>`).join('')}</ul>` : ''}</div>
      <div class="card"><h2 class="card-h">Top countries</h2><table class="tbl"><tbody id="countries"><tr><td class="muted">…</td></tr></tbody></table></div></div>

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
      <div class="card"><h2 class="card-h">Settings</h2>
        <div class="grid gap-4">
          <div><label class="label" for="label">Label</label><div class="flex gap-2"><input id="label" class="input" maxlength="100" value="${E(r.label || r.project)}"><button class="btn btn-outline" id="save-label">Save</button></div></div>
          <div><div class="label">Expiry</div>${UI.expiryControl(r.hostname, r.expires_at, r.expired_at)}<p class="hint">When the time passes, the route is switched off or removed — you choose which in Settings.</p></div>
          <dl class="grid grid-cols-[7rem_1fr] gap-y-2 text-sm">
            <dt class="muted">Project</dt><dd><a class="text-indigo-600 hover:underline" href="?page=files&site=${encodeURIComponent(r.hostname)}">${E(r.project)}</a></dd>
            <dt class="muted">Folder</dt><dd class="break-all"><code class="text-xs">${E(r.document_root)}</code></dd>
            <dt class="muted">Created</dt><dd>${r.created_at ? UI.ago(r.created_at) + ' ago' : '—'}</dd></dl></div></div>
      <div class="card"><h2 class="card-h">DNS &amp; Apache</h2>
        <dl class="grid grid-cols-[7rem_1fr] gap-y-2 text-sm">
          <dt class="muted">DNS record</dt><dd>${d.dns ? `${UI.badge('blue', E(d.dns.type))} ${d.dns.proxied ? UI.badge('yellow', 'Proxied', 'cloud') : UI.badge('gray', 'DNS only')}` : (d.dns_error ? '<span class="muted">unavailable</span>' : UI.badge('red', 'missing'))}</dd>
          <dt class="muted">Points to</dt><dd class="break-all">${d.dns ? '<code class="text-xs">' + E(d.dns.content) + '</code>' : '—'}</dd>
          <dt class="muted">Record id</dt><dd class="break-all"><code class="text-xs">${E((d.dns && d.dns.id) || r.dns_record_id || '—')}</code></dd>
          <dt class="muted">Apache vhost</dt><dd>${d.vhost ? (d.vhost.enabled ? UI.badge('green', 'active') : UI.badge('yellow', 'disabled (commented out)')) : UI.badge('red', 'missing')}</dd>
          <dt class="muted">DocumentRoot</dt><dd class="break-all">${d.vhost ? '<code class="text-xs">' + E(d.vhost.document_root) + '</code>' : '—'}</dd></dl>
        <div class="mt-4 flex flex-wrap gap-2"><button class="btn btn-outline" id="purge">${UI.i('eraser')}Purge cache for this hostname</button>
          ${d.zone_name ? `<a class="btn btn-outline" href="?page=dns&zone=${r.zone_id}">${UI.i('list-tree')}DNS records</a>` : ''}</div></div></div>

    <div class="card mb-6" id="portal-card">${UI.loading('Loading file access…')}</div>

    <div class="card"><h2 class="card-h">Activity</h2>
      ${d.activity.length ? `<ul class="space-y-2 text-sm">${d.activity.map(a => `<li class="flex gap-2">${UI.i(a.ok ? 'circle-check' : 'circle-alert', 'mt-0.5 shrink-0 ' + (a.ok ? 'text-green-600' : 'text-red-600'))}
        <span><b>${E(a.action)}</b> <span class="muted">${E(a.detail || '')}</span> <span class="muted text-xs">· ${new Date(a.ts).toLocaleString()}</span></span></li>`).join('')}</ul>` : '<p class="muted text-sm">Nothing recorded for this route yet.</p>'}
    </div>`;
  portalCard();

  // ---- actions
  const on = (id, fn) => { const el = document.getElementById(id); if (el) el.onclick = () => UI.busy(el, fn); };
  on('toggle', async () => { await UI.post(R('/toggle')); UI.toast(enabled ? 'Route disabled' : 'Route enabled'); load(); });
  document.getElementById('detach').onclick = async e => {
    if (!await UI.confirm('Detach ' + HOST + '?', 'Deletes its Cloudflare DNS record and Apache vhost block. The project folder is not touched.', 'Yes, detach')) return;
    UI.busy(e.currentTarget, async () => { await UI.api('/api/routes/' + encodeURIComponent(HOST) + '&confirm=1', { method: 'DELETE' }); UI.toast('Route detached'); location.href = '?page=dashboard'; });
  };
  on('save-label', async () => { await UI.post(R('/label'), { label: document.getElementById('label').value }); UI.toast('Label saved'); });
  on('purge', async () => { await UI.post('/api/domains/' + r.zone_id + '/purge', { hostname: HOST }); UI.toast('Cache purged for ' + HOST); });
  on('check', async () => { fillProbe(); });
  UI.bindExpiry(root, load);

  if (h && h.series && h.series.length > 1)
    charts.push(UI.chart(document.getElementById('latChart'), { chart: { type: 'area', height: 200, animations: { enabled: false } },
      series: [{ name: 'Latency (ms)', data: h.series.map(x => [x[0] * 1000, x[1] ? x[2] : null]) }], xaxis: { type: 'datetime', labels: { datetimeUTC: false } }, yaxis: { min: 0, title: { text: 'ms' } },
      fill: { type: 'gradient', gradient: { opacityFrom: .3, opacityTo: .02 } } }));

  fillAnalytics(); if (enabled) fillProbe();
}

async function fillAnalytics() {
  const stat = i => document.getElementById('stats').children[i].querySelector('.truncate');
  try {
    const a = await UI.api(R('/analytics'));
    stat(3).textContent = UI.num(a.totals.requests); stat(4).textContent = UI.bytes(a.totals.bytes);
    document.getElementById('reqChart').innerHTML = '';
    if (a.series.length) charts.push(UI.area(document.getElementById('reqChart'), a.series.map(s => new Date(s.t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })), [{ name: 'Requests', data: a.series.map(s => s.requests) }], UI.num));
    else document.getElementById('reqChart').innerHTML = '<p class="muted text-sm">No traffic for this hostname in the last 24 hours.</p>';
    const sk = Object.keys(a.status), col = { '2xx': '#10b981', '3xx': '#06b6d4', '4xx': '#f59e0b', '5xx': '#ef4444' };
    if (sk.length) charts.push(UI.donut(document.getElementById('statusChart'), sk, sk.map(k => a.status[k]), sk.map(k => col[k] || '#9ca3af')));
    else document.getElementById('statusChart').innerHTML = '<p class="muted text-sm">No traffic yet.</p>';
    document.getElementById('countries').innerHTML = Object.entries(a.countries).map(([k, v]) => `<tr><td>${E(k)}</td><td class="text-right">${UI.num(v)}</td></tr>`).join('') || '<tr><td class="muted">No data</td></tr>';
  } catch (e) {
    stat(3).textContent = '—'; stat(4).textContent = '—';
    ['reqChart', 'statusChart'].forEach(id => document.getElementById(id).innerHTML = `<p class="muted text-sm">${E(e.message)}</p>`);
    document.getElementById('countries').innerHTML = '<tr><td class="muted">Unavailable</td></tr>';
  }
}

async function fillProbe() {
  const now = document.getElementById('now'), card = document.getElementById('stats').children[0];
  now.innerHTML = '<span class="muted text-sm">checking…</span>';
  try {
    const p = (await UI.api(R('/probe'))).probe;
    now.innerHTML = UI.probeBadge(p) + (p ? ` <span class="muted text-sm">${p.ms} ms · HTTP ${p.code}</span>` : '');
    card.querySelector('.truncate').textContent = p ? ({ up: 'Up', tunnel_down: 'Tunnel down', error: 'HTTP ' + p.code, unreachable: 'Unreachable' }[p.state] || p.state) : '—';
    card.querySelector('span.grid').className = 'grid size-10 shrink-0 place-content-center rounded-lg badge-' + (p && p.ok ? 'green' : 'red');
  } catch (e) { now.innerHTML = UI.badge('gray', 'unknown'); }
}
async function portalCard() {
  const box = document.getElementById('portal-card'); if (!box) return;
  try {
    const u = await UI.api('/api/users');
    const r = u.routes.find(x => x.hostname === HOST) || {}, on = !!r.portal_enabled;
    const mine = u.users.map(x => ({ x, g: x.access.find(g => g.host === HOST) })).filter(o => o.g);
    box.innerHTML = `<div class="flex flex-wrap items-center justify-between gap-3"><h2 class="card-h !mb-0">File access for users</h2>
        <label class="flex items-center gap-2 text-sm">${UI.toggle('portal-on', on)}<span>${on ? 'On' : 'Off'}</span></label></div>
      <p class="muted mt-2 text-sm">${on ? 'People you assign below can open this site\'s files at ' + (u.portal.hostname ? '<a class="text-indigo-600 hover:underline" target="_blank" rel="noopener" href="https://' + E(u.portal.hostname) + '/">' + E(u.portal.hostname) + '</a>' : 'the file portal (<a class="underline" href="?page=settings#portal">set its address first</a>)') + '. Switching this off locks them all out at once without removing their access.' : 'Off: nobody but you can reach this site\'s files.'}</p>
      ${mine.length ? `<ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800">${mine.map(({ x, g }) => `<li class="flex flex-wrap items-center gap-2 py-2"><b>${E(x.display)}</b> <span class="muted">${E(x.username)}</span>
        <span class="muted text-xs">view${['upload', 'edit', 'delete'].filter(k => g[k]).map(k => ' · ' + k).join('')}${g.until ? ' · until ' + new Date(g.until * 1000).toLocaleDateString() : ''}</span></li>`).join('')}</ul>` : '<p class="muted mt-3 text-sm">No users assigned.</p>'}
      <div class="mt-3"><a class="btn btn-outline" href="?page=users">${UI.i('users')}Manage users and permissions</a></div>`;
    document.getElementById('portal-on').onchange = async e => {
      try { await UI.post(R('/portal'), { enabled: e.target.checked }); UI.toast(e.target.checked ? 'File access on' : 'File access off'); portalCard(); }
      catch (err) { e.target.checked = !e.target.checked; UI.toast(err.message, 'error'); }
    };
  } catch (err) { box.innerHTML = UI.errBox(err.message); }
}
load();
</script>
