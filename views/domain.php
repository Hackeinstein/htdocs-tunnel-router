<?php $zone = preg_replace('/[^a-f0-9]/', '', $_GET['zone'] ?? ''); ?>
<?= pageHeader('Domain', '', '<button class="btn btn-outline" onclick="load()"><i data-lucide="refresh-cw"></i>Refresh</button>', ['Domains', '?page=domains'], 'title') ?>
<div id="root"><script>document.write(UI.loading('Loading domain…'))</script></div>
<script>
const ZONE = <?= json_encode($zone) ?>;
const LABELS = { ssl: 'SSL/TLS mode', always_use_https: 'Always use HTTPS', automatic_https_rewrites: 'Automatic HTTPS rewrites',
                 min_tls_version: 'Minimum TLS version', security_level: 'Security level', development_mode: 'Development mode (bypass cache)' };
let charts = [], DATA = null;
const E = UI.esc, dash = '<span class="muted">…</span>';

/* The page is filled in three steps so something useful shows at once:
   1. /api/domains/{zone}            — zone, settings, routes, DNS            (one parallel round trip, cached)
   2. /api/domains/{zone}/analytics  — traffic charts and per-hostname numbers (GraphQL)
   3. /api/domains/{zone}/health     — live probes through the tunnel          (slowest, so last) */
async function load() {
  const root = document.getElementById('root');
  charts.forEach(c => c.destroy()); charts = [];
  let d;
  try { d = DATA = await UI.api('/api/domains/' + ZONE); } catch (e) { root.innerHTML = UI.errBox(e.message); return; }
  document.getElementById('title').textContent = d.zone.name;
  const er = d.errors || {}, underAttack = d.settings && d.settings.security_level === 'under_attack';

  const rows = d.routes.map(r => `<tr data-h="${E(r.hostname)}">
      <td><a class="text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${E(r.hostname)}</a><div class="muted text-xs">${E(r.project)}</div></td>
      <td class="c-status">${r.enabled === false ? UI.badge('yellow', 'Disabled') : dash}</td><td class="c-lat">${dash}</td><td class="c-up">${dash}</td>
      <td>${UI.expiryControl(r.hostname, r.expires_at, r.expired_at)}</td><td class="c-req">${dash}</td><td class="c-bw">${dash}</td>
      <td class="text-right whitespace-nowrap"><a class="btn btn-ghost" href="?page=files&site=${encodeURIComponent(r.hostname)}" title="File manager">${UI.i('folder-code')}</a><button class="btn btn-ghost js-purge" data-host="${E(r.hostname)}" title="Purge cache">${UI.i('eraser')}</button></td></tr>`).join('');

  const settings = d.settings ? Object.entries(d.options).map(([name, opts]) => `
    <div class="mb-3"><label class="label">${LABELS[name] || name}</label>
      <select class="input" data-setting="${name}">${opts.map(o => `<option ${d.settings[name] === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div>`).join('')
    : '<p class="muted text-sm">Unavailable (token needs Zone Settings:Edit).</p>';

  root.innerHTML =
    Object.entries(er).map(([k, v]) => UI.warn(k + ': ' + v)).join('') + (d.stale ? UI.warn('Cloudflare did not answer — showing the last known values.') : '') +
    `<div class="card mb-6 flex flex-wrap items-center gap-4 ${underAttack ? '!border-red-400 !bg-red-50 dark:!bg-red-950' : ''}">
      <span class="grid size-10 shrink-0 place-content-center rounded-lg ${underAttack ? 'bg-red-600 text-white' : 'badge-gray'}">${UI.i('shield-alert', '!size-5')}</span>
      <div class="min-w-0 flex-1"><div class="font-semibold">Under Attack mode ${underAttack ? UI.badge('red', 'ON') : UI.badge('gray', 'off')}</div>
        <div class="muted text-sm">${underAttack
          ? 'Every visitor gets a 5-second browser check.' + (d.attack && d.attack.revert_at ? ` Reverts to <b>${E(d.attack.prev)}</b> in ${UI.until(d.attack.revert_at).text}.` : ' Stays on until you turn it off.')
          : 'Turn on while the site is being hit; visitors get a short challenge.'}</div></div>
      ${underAttack ? `<button class="btn btn-success" id="atk-off">${UI.i('shield-check')}Turn off</button>`
        : `<select class="input !w-auto" id="atk-for"><option value="">Until I turn it off</option><option value="3600">For 1 hour</option><option value="21600">For 6 hours</option><option value="86400">For 24 hours</option></select>
           <button class="btn btn-danger" id="atk-on">${UI.i('shield-alert')}Turn on</button>`}</div>
    <div id="stats" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">${UI.stat('arrow-left-right', 'green', dash, 'Requests', '7d')}${UI.stat('cloud-download', 'purple', dash, 'Bandwidth', '7d')}${UI.stat('users', 'blue', dash, 'Unique visitors')}${UI.stat('heart-pulse', 'gray', dash, 'Hostnames up now')}</div>
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
      <div class="card lg:col-span-2"><h2 class="card-h">Requests per day</h2><div id="reqChart" class="min-h-[240px]">${UI.loading('Loading traffic…')}</div></div>
      <div class="card"><h2 class="card-h">Responses</h2><div id="statusChart" class="min-h-[220px]"></div><p id="cache-line" class="muted mt-2 text-xs"></p></div>
    </div>
    <div class="card mb-6 !p-0"><div class="flex items-center justify-between px-5 pt-5"><h2 class="card-h">Tunnel routes</h2>
      <span class="muted text-xs">traffic = last 24h · uptime = checks made while this tool is open</span></div>
      <div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Hostname</th><th>Status</th><th>Latency</th><th>Uptime</th><th>Expires</th><th>Requests</th><th>Bandwidth</th><th></th></tr></thead>
      <tbody>${rows || '<tr><td colspan="8" class="muted py-8 text-center">No routes on this domain yet.</td></tr>'}</tbody></table></div></div>
    <div class="grid gap-4 lg:grid-cols-3">
      <div class="card"><h2 class="card-h">Top countries</h2><table class="tbl"><tbody id="countries"><tr><td class="muted">…</td></tr></tbody></table></div>
      <div class="card"><h2 class="card-h">DNS pointing at a tunnel</h2><table class="tbl"><tbody>${(d.dns || []).map(r => `<tr><td>${E(r.name)}</td>
        <td class="text-right">${r.tunnel_id === d.tunnel_id ? UI.badge('green', 'active tunnel') : `<a class="text-indigo-600 hover:underline" href="?page=tunnel&id=${r.tunnel_id}">other tunnel</a>`}</td></tr>`).join('') || '<tr><td class="muted">None</td></tr>'}</tbody></table></div>
      <div class="card"><h2 class="card-h">Settings</h2>${settings}
        <a href="?page=dns&zone=${ZONE}" class="btn btn-outline mt-1 mb-2 w-full">${UI.i('list-tree')}Manage DNS records</a>
        <button class="btn btn-outline mt-1 w-full !text-red-600" id="purgeAll">${UI.i('eraser')}Purge entire cache</button></div>
    </div>`;

  // ---- handlers for what is already on screen
  root.querySelectorAll('[data-setting]').forEach(sel => sel.onchange = async () => {
    try { await UI.post('/api/domains/' + ZONE + '/setting', { name: sel.dataset.setting, value: sel.value });
          UI.toast(LABELS[sel.dataset.setting] + ' → ' + sel.value); if (sel.dataset.setting === 'security_level') load(); }
    catch (e) { UI.toast(e.message, 'error'); load(); }
  });
  const atk = (id, body) => { const el = document.getElementById(id); if (el) el.onclick = () => UI.busy(el, async () => { await UI.post('/api/domains/' + ZONE + '/attack', body()); UI.toast('Under Attack mode ' + (body().on ? 'on' : 'off')); load(); }); };
  atk('atk-on', () => ({ on: true, revert_in: +document.getElementById('atk-for').value || null })); atk('atk-off', () => ({ on: false }));
  UI.bindExpiry(root, load);
  root.querySelectorAll('.js-purge').forEach(b => b.onclick = () => purge(b.dataset.host, b));
  document.getElementById('purgeAll').onclick = e => purge('', e.currentTarget);

  // ---- slow parts, in parallel, each filling its own area when it arrives
  fillAnalytics(d); fillHealth(d);
}

function setCell(host, cls, html) { const c = document.querySelector(`tr[data-h="${CSS.escape(host)}"] .${cls}`); if (c) c.innerHTML = html; }

async function fillAnalytics(d) {
  try {
    const r = await UI.api('/api/domains/' + ZONE + '/analytics'), a = r.analytics, t = a && a.totals;
    Object.entries(r.errors || {}).forEach(([k, v]) => document.getElementById('stats').insertAdjacentHTML('beforebegin', UI.warn(k + ': ' + v)));
    if (!a) throw new Error('Analytics unavailable (token needs Analytics:Read)');
    const stats = document.getElementById('stats').children;
    stats[0].querySelector('.truncate').textContent = UI.num(t.requests); stats[1].querySelector('.truncate').textContent = UI.bytes(t.bytes); stats[2].querySelector('.truncate').textContent = UI.num(t.uniques);
    d.routes.forEach(rt => { const s = (r.hosts || {})[rt.hostname] || { requests: 0, bytes: 0 }; setCell(rt.hostname, 'c-req', UI.num(s.requests)); setCell(rt.hostname, 'c-bw', UI.bytes(s.bytes)); });
    document.getElementById('reqChart').innerHTML = '';
    charts.push(UI.area(document.getElementById('reqChart'), a.series.map(s => s.date.slice(5)), [{ name: 'Requests', data: a.series.map(s => s.requests) }], UI.num));
    const sk = Object.keys(a.status), col = { '2xx': '#10b981', '3xx': '#06b6d4', '4xx': '#f59e0b', '5xx': '#ef4444' };
    if (sk.length) charts.push(UI.donut(document.getElementById('statusChart'), sk, sk.map(k => a.status[k]), sk.map(k => col[k] || '#9ca3af')));
    else document.getElementById('statusChart').innerHTML = '<p class="muted text-sm">No traffic yet.</p>';
    document.getElementById('cache-line').textContent = `Cache hit ${UI.pct(t.cached, t.requests)} · Threats ${UI.num(t.threats)}`;
    document.getElementById('countries').innerHTML = Object.entries(a.countries).map(([k, v]) => `<tr><td>${E(k)}</td><td class="text-right">${UI.num(v)}</td></tr>`).join('') || '<tr><td class="muted">No data</td></tr>';
  } catch (e) {
    ['reqChart', 'statusChart'].forEach(id => { const el = document.getElementById(id); if (el) el.innerHTML = `<p class="muted text-sm">${E(e.message)}</p>`; });
    const c = document.getElementById('countries'); if (c) c.innerHTML = '<tr><td class="muted">Unavailable</td></tr>';
    d.routes.forEach(rt => { setCell(rt.hostname, 'c-req', '—'); setCell(rt.hostname, 'c-bw', '—'); });
  }
}

async function fillHealth(d) {
  try {
    const probes = (await UI.api('/api/domains/' + ZONE + '/health')).probes || {}, list = Object.values(probes), up = list.filter(p => p.ok).length;
    d.routes.forEach(r => {
      const p = probes[r.hostname];
      if (r.enabled !== false) setCell(r.hostname, 'c-status', UI.probeBadge(p));
      setCell(r.hostname, 'c-lat', p ? p.ms + ' ms' : '—');
      setCell(r.hostname, 'c-up', p ? p.uptime + '% <span class="muted text-xs">(' + p.samples + ')</span>' : '—');
    });
    const card = document.getElementById('stats').children[3];
    card.querySelector('.truncate').textContent = list.length ? up + ' / ' + list.length : '—';
    card.querySelector('span.grid').className = 'grid size-10 shrink-0 place-content-center rounded-lg badge-' + (list.length && up === list.length ? 'green' : list.length ? 'red' : 'gray');
  } catch (e) { d.routes.forEach(r => { setCell(r.hostname, 'c-status', '—'); setCell(r.hostname, 'c-lat', '—'); setCell(r.hostname, 'c-up', '—'); }); }
}

async function purge(host, btn) {
  if (!host && !await UI.confirm('Purge entire cache?', 'Every cached file for this domain is dropped; the next visits hit your origin.', 'Purge')) return;
  UI.busy(btn, async () => { await UI.post('/api/domains/' + ZONE + '/purge', { hostname: host }); UI.toast('Cache purged' + (host ? ' for ' + host : '')); });
}
load();
</script>
