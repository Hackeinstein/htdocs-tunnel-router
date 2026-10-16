<?php $id = preg_replace('/[^a-f0-9-]/', '', $_GET['id'] ?? ''); ?>
<?= pageHeader('Tunnel', '', '<button class="btn btn-outline" onclick="load()"><i data-lucide="refresh-cw"></i>Refresh</button>', ['Tunnels', '?page=tunnels'], 'title') ?>
<div id="msg"></div>
<div id="root"><script>document.write(UI.loading('Loading tunnel…'))</script></div>
<script>
const ID = <?= json_encode($id) ?>;
const COLOR = { healthy: 'green', degraded: 'yellow', down: 'red', inactive: 'gray' };
const E = UI.esc, dash = '<span class="muted">…</span>';

/* 1. /api/tunnels/{id}       — details, connections, ingress (three Cloudflare calls in parallel, cached)
   2. /api/tunnels/{id}/live  — traffic + live probes of its routes, filled in when ready */
async function load() {
  const root = document.getElementById('root');
  let d; try { d = await UI.api('/api/tunnels/' + ID); } catch (e) { root.innerHTML = UI.errBox(e.message); return; }
  const t = d.tunnel, er = d.errors || {};
  document.getElementById('title').textContent = t.name;

  // Connectors may be [{id, version, run_at, conns:[...]}] or a flat list of edge connections.
  const conns = d.connectors.flatMap(c => c.conns ? c.conns.map(x => ({ ...x, version: c.version, run_at: c.run_at })) : [c]);
  const runAt = conns.map(c => c.run_at || c.opened_at).filter(Boolean).sort()[0];
  const colos = [...new Set(conns.map(c => c.colo_name).filter(Boolean))], pending = conns.filter(c => c.is_pending_reconnect).length;

  root.innerHTML =
    Object.entries(er).map(([k, v]) => UI.warn(k + ': ' + v)).join('') + (d.stale ? UI.warn('Cloudflare did not answer — showing the last known values.') : '') +
    `<div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
      ${UI.stat('heart-pulse', COLOR[t.status] || 'gray', E(t.status || 'unknown'), 'Status')}
      ${UI.stat('plug', 'blue', conns.length, 'Edge connections', pending ? pending + ' reconnecting' : '')}
      ${UI.stat('clock', 'purple', runAt ? UI.ago(runAt) : '—', 'Connector uptime')}
      ${UI.stat('map-pin', 'orange', colos.length ? colos.join(', ') : '—', 'Edge locations')}</div>
    ${d.active ? `<div id="live" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      ${UI.stat('arrow-left-right', 'green', dash, 'Requests via tunnel', '24h')}${UI.stat('cloud-download', 'purple', dash, 'Bandwidth', '24h')}
      ${UI.stat('route', 'blue', d.routes.length, 'Routes')}${UI.stat('circle-check', 'gray', dash, 'Hostnames up now')}</div>` : '<div class="mb-6"></div>'}
    <div class="mb-6 grid gap-4 lg:grid-cols-5">
      <div class="card !p-0 lg:col-span-3"><h2 class="card-h px-5 pt-5">Connections</h2><div class="overflow-x-auto"><table class="tbl">
        <thead><tr><th>Location</th><th>Origin IP</th><th>Version</th><th>Opened</th></tr></thead><tbody>
        ${conns.map(c => `<tr><td>${E(c.colo_name || '—')}</td><td>${E(c.origin_ip || '—')}</td><td>${E(c.version || '—')}</td>
          <td>${UI.ago(c.opened_at)} ago ${c.is_pending_reconnect ? UI.badge('yellow', 'reconnecting') : ''}</td></tr>`).join('')
          || '<tr><td colspan="4" class="muted py-8 text-center">No active connections — cloudflared is not running for this tunnel.</td></tr>'}
        </tbody></table></div></div>
      <div class="card lg:col-span-2"><h2 class="card-h">Details</h2>
        <dl class="grid grid-cols-[5rem_1fr] gap-y-2 text-sm">
          <dt class="muted">ID</dt><dd class="break-all"><code>${E(t.id)}</code></dd>
          <dt class="muted">Created</dt><dd>${UI.ago(t.created_at)} ago</dd>
          <dt class="muted">Managed</dt><dd>${t.config_src === 'cloudflare' ? 'Remotely (dashboard)' : 'Locally (config.yml)'}</dd>
          <dt class="muted">CNAME</dt><dd class="break-all"><code>${E(t.id)}.cfargotunnel.com</code></dd>
          <dt class="muted">Router</dt><dd>${d.active ? UI.badge('green', 'used for new routes') : 'not in use'}</dd></dl>
        <div class="muted mt-4 mb-1 text-xs">Ingress</div>
        ${d.ingress && t.config_src === 'cloudflare' ? `<div class="code">${E(d.ingress.map(i => (i.hostname ? i.hostname + ' → ' : '') + i.service).join('\n'))}</div>`
                    : '<p class="muted text-sm">Local config — ingress lives in your config.yml, not in Cloudflare.</p>'}</div>
    </div>
    ${d.active ? `<div class="card mb-6 !p-0"><h2 class="card-h px-5 pt-5">Routes on this tunnel</h2><div class="overflow-x-auto"><table class="tbl">
      <thead><tr><th>Hostname</th><th>Status</th><th>Latency</th><th>Uptime</th><th>Expires</th><th></th></tr></thead><tbody>
      ${d.routes.map(r => `<tr data-h="${E(r.hostname)}"><td><a class="text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${E(r.hostname)}</a></td>
        <td class="c-status">${r.enabled === false ? UI.badge('yellow', 'Disabled') : dash}</td><td class="c-lat">${dash}</td><td class="c-up">${dash}</td>
        <td>${UI.expiryControl(r.hostname, r.expires_at, r.expired_at)}</td>
        <td class="text-right"><a class="btn btn-outline" href="?page=files&site=${encodeURIComponent(r.hostname)}">${UI.i('folder-code')}Files</a></td></tr>`).join('')
        || '<tr><td colspan="6" class="muted py-8 text-center">No routes yet.</td></tr>'}</tbody></table></div></div>` : ''}
    <div class="card"><h2 class="card-h">Settings</h2>
      <div class="grid gap-4 md:grid-cols-2 md:items-end">
        <div><label class="label" for="name">Name</label><div class="flex gap-2"><input id="name" class="input" value="${E(t.name)}"><button class="btn btn-outline" id="rename">Rename</button></div></div>
        <div class="flex flex-wrap gap-2">
          ${d.active ? '' : '<button class="btn btn-outline" id="activate">Use for new routes</button>'}
          <button class="btn btn-outline" id="cleanup">Clear stale connections</button></div></div>
      ${d.active ? '' : '<p class="hint">Switching only affects routes you create afterwards; existing DNS records keep pointing where they do.</p>'}
    </div>`;

  UI.bindExpiry(root, load);
  const on = (sel, fn) => { const el = document.getElementById(sel); if (el) el.onclick = () => UI.busy(el, fn); };
  on('rename',   async () => { await UI.post('/api/tunnels/' + ID + '/rename', { name: document.getElementById('name').value }); UI.toast('Renamed'); load(); });
  on('cleanup',  async () => { await UI.post('/api/tunnels/' + ID + '/cleanup'); UI.toast('Stale connections cleared'); load(); });
  on('activate', async () => { await UI.post('/api/tunnels/' + ID + '/activate'); UI.toast('Now used for new routes'); load(); });

  if (d.active) fillLive(d);
}

async function fillLive(d) {
  const cell = (host, cls, html) => { const c = document.querySelector(`tr[data-h="${CSS.escape(host)}"] .${cls}`); if (c) c.innerHTML = html; };
  try {
    const l = await UI.api('/api/tunnels/' + ID + '/live'), probes = l.probes || {}, list = Object.values(probes), up = list.filter(p => p.ok).length;
    const s = document.getElementById('live').children;
    if (l.traffic) { s[0].querySelector('.truncate').textContent = UI.num(l.traffic.requests); s[1].querySelector('.truncate').textContent = UI.bytes(l.traffic.bytes); }
    s[3].querySelector('.truncate').textContent = list.length ? up + ' / ' + list.length : '—';
    s[3].querySelector('span.grid').className = 'grid size-10 shrink-0 place-content-center rounded-lg badge-' + (list.length && up === list.length ? 'green' : list.length ? 'red' : 'gray');
    Object.entries(l.errors || {}).forEach(([k, v]) => document.getElementById('live').insertAdjacentHTML('beforebegin', UI.warn(k + ': ' + v)));
    d.routes.forEach(r => { const p = probes[r.hostname];
      if (r.enabled !== false) cell(r.hostname, 'c-status', UI.probeBadge(p));
      cell(r.hostname, 'c-lat', p ? p.ms + ' ms' : '—'); cell(r.hostname, 'c-up', p ? p.uptime + '% (' + p.samples + ' checks)' : '—'); });
  } catch (e) {
    document.getElementById('live').insertAdjacentHTML('beforebegin', UI.warn('Live data unavailable: ' + e.message));
    d.routes.forEach(r => { ['c-status', 'c-lat', 'c-up'].forEach(c => cell(r.hostname, c, '—')); });
  }
}
load();
</script>
