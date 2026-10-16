<?= pageHeader('Reconcile', 'Detect drift between routes.json, live vhosts, and Cloudflare DNS',
  '<button class="btn btn-primary" onclick="location.reload()"><i data-lucide="refresh-cw"></i>Refresh</button>') ?>
<div class="card mb-6" id="rebuild">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div class="max-w-2xl"><h2 class="card-h !mb-1">Rebuild routes from your live setup</h2>
      <p class="muted text-sm">Dashboard showing sites as "Not routed" although they are live? The tool's own list (<code>routes.json</code>) is missing or out of date. This scans your <b>Apache vhosts</b> and <b>every Cloudflare zone</b> for records pointing at this tunnel and lets you pick what to add back.
        <b>Read-only for Apache and Cloudflare</b> — only the tool's list is written, entries you already have are never overwritten, and a snapshot is taken first.</p></div>
    <button class="btn btn-primary" id="scan"><i data-lucide="scan-search"></i>Scan my setup</button></div>
  <div id="scan-out" class="mt-4"></div>
</div>
<div id="root"><script>document.write(UI.loading('Checking for drift…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root'), E = UI.esc;
  const STATUS = { ok: ['green', 'OK'], dns_missing: ['red', 'DNS missing'], vhost_missing: ['red', 'VHost missing'], both_missing: ['orange', 'Both missing'], drifted: ['yellow', 'Drifted'], dns_only: ['blue', 'DNS only'] };
  const btn = (action, h, icon, text, cls) => `<button class="btn ${cls} fix" data-a="${action}" data-h="${E(h)}">${UI.i(icon)}${text}</button>`;
  const actions = r => ({
    dns_missing:   btn('create_dns', r.hostname, 'cloud-upload', 'Create DNS', 'btn-primary') + btn('delete_vhost', r.hostname, 'trash', 'Remove VHost', 'btn-ghost-danger'),
    vhost_missing: btn('create_vhost', r.hostname, 'server', 'Create VHost', 'btn-primary') + btn('delete_dns', r.hostname, 'trash', 'Remove DNS', 'btn-ghost-danger'),
    both_missing:  btn('delete_route_entry', r.hostname, 'trash', 'Remove route entry', 'btn-ghost-danger'),
    drifted:       `<span class="muted text-xs">Hand-edited vhost — <a class="text-indigo-600 underline" href="?page=detach&hostname=${encodeURIComponent(r.hostname)}">detach</a> to clean up</span>`,
    dns_only:      btn('delete_dns', r.hostname, 'trash', 'Delete DNS', 'btn-ghost-danger'),
  })[r.status] || '<span class="text-xs text-green-600">In sync</span>';

  // ---- rebuild from the live setup
  const sc = document.getElementById('scan'), out = document.getElementById('scan-out');
  const NOTE = { importable: ['green', 'Ready to import'], tracked: ['gray', 'Already tracked'], dns_only: ['yellow', 'DNS only'], project_missing: ['red', 'Folder not found'] };
  sc.onclick = () => UI.busy(sc, async () => {
    const d = await UI.api('/api/import/scan'), items = d.items;
    const failed = Object.entries(d.zones_failed || {});
    const ready = items.filter(i => i.status === 'importable');
    out.innerHTML = (failed.length ? UI.warn('Could not read: ' + failed.map(([z, m]) => (z === '*' ? 'zones' : z) + ' (' + m + ')').join('; ')) : '') +
      (items.length ? `<div class="overflow-x-auto"><table class="tbl"><thead><tr><th class="w-8"><input type="checkbox" id="rb-all" class="size-4 rounded" ${ready.length ? 'checked' : 'disabled'}></th><th>Hostname</th><th>Project folder</th><th>Status</th><th>DNS</th><th>Vhost</th></tr></thead><tbody>
        ${items.map(i => { const [c, t] = NOTE[i.status] || ['gray', i.status]; return `<tr>
          <td><input type="checkbox" class="rb size-4 rounded" data-h="${E(i.hostname)}" ${i.status === 'importable' ? 'checked' : 'disabled'}></td>
          <td><b>${E(i.hostname)}</b>${i.www_alias ? ' ' + UI.badge('blue', '+www') : ''}${i.enabled ? '' : ' ' + UI.badge('yellow', 'disabled')}</td>
          <td>${i.project ? E(i.project) : '<span class="muted">—</span>'}</td>
          <td>${UI.badge(c, t)}${i.status === 'dns_only' ? '<div class="muted text-xs">No vhost for it — connect it to a project from Attach Route.</div>' : ''}${i.status === 'importable' && !i.has_dns ? '<div class="muted text-xs">No DNS record found for it.</div>' : ''}${i.status === 'importable' && !i.zone_id ? '<div class="muted text-xs">Cloudflare zone not identified — detaching it later will not remove its DNS record.</div>' : ''}</td>
          <td>${i.has_dns ? UI.i('check', 'text-green-600') : UI.i('x', 'text-red-600')}</td><td>${i.has_vhost ? UI.i('check', 'text-green-600') : UI.i('x', 'text-red-600')}</td></tr>`; }).join('')}</tbody></table></div>
        <div class="mt-4 flex items-center gap-3"><button class="btn btn-success" id="rb-go" ${ready.length ? '' : 'disabled'}>${UI.i('download')}Import selected</button><span class="muted text-sm">${d.tracked} route(s) tracked now · ${ready.length} can be imported</span></div>`
        : UI.empty('search-x', 'Nothing found', 'No tunnel-router vhost blocks and no DNS records pointing at this tunnel.'));
    const boxes = () => [...out.querySelectorAll('.rb:not(:disabled)')];
    const all = document.getElementById('rb-all'); if (all) all.onchange = () => boxes().forEach(b => b.checked = all.checked);
    const go = document.getElementById('rb-go');
    if (go) go.onclick = () => UI.busy(go, async () => {
      const hostnames = boxes().filter(b => b.checked).map(b => b.dataset.h);
      if (!hostnames.length) return UI.toast('Tick at least one site.', 'error');
      const r = await UI.post('/api/import/apply', { hostnames });
      await UI.results('Import', r.results.map(x => ({ id: x.id, ok: x.ok, error: x.error || x.detail })));
      location.reload();
    });
  });

  try {
    const routes = (await UI.api('/api/reconcile')).routes || [];
    if (!routes.length) { root.innerHTML = UI.empty('circle-check', 'Nothing to reconcile', 'No routes, vhosts, or DNS records found.'); return; }
    if (routes.every(r => r.status === 'ok')) { root.innerHTML = UI.empty('circle-check', 'All clear', `All ${routes.length} routes are in sync — no drift detected.`); return; }

    root.innerHTML = `<div class="card !p-0"><div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Hostname</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead><tbody>
      ${routes.map(r => { const [c, l] = STATUS[r.status] || STATUS.both_missing; return `<tr>
        <td><code>${E(r.hostname)}</code></td><td>${UI.badge(c, l)}</td>
        <td class="muted text-xs">${r.route_entry ? 'Project: <b>' + E(r.route_entry.project) + '</b>' : 'Not in routes.json'}</td>
        <td><div class="flex gap-1">${actions(r)}</div></td></tr>`; }).join('')}</tbody></table></div></div>`;

    root.querySelectorAll('.fix').forEach(b => b.onclick = () => UI.busy(b, async () => {
      await UI.post('/api/reconcile/fix', { hostname: b.dataset.h, action: b.dataset.a });
      location.reload();
    }));
  } catch (e) { root.innerHTML = UI.errBox('Reconcile failed: ' + e.message); }
})();
</script>
