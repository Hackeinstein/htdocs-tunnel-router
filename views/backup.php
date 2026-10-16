<?= pageHeader('Backup & restore', 'Move your setup to another machine, or roll back. Snapshots are taken automatically before bulk runs and restores.') ?>
<div class="grid gap-6 lg:grid-cols-2">
  <div class="card">
    <h2 class="card-h">Export</h2>
    <p class="muted mb-4 text-sm">One JSON file with your settings, routes (including expiries), vhost blocks and each route's DNS record.</p>
    <label class="mb-4 flex items-start gap-3"><input type="checkbox" id="tok" class="mt-1 size-4 rounded border-gray-300">
      <span class="text-sm">Include the Cloudflare API token<span class="hint block">Off by default. Anyone with the file could use the token, so keep it private if you tick this.</span></span></label>
    <a class="btn btn-primary" id="dl" href="#"><i data-lucide="download"></i>Download backup</a>
    <button class="btn btn-outline ml-2" id="snap"><i data-lucide="camera"></i>Take snapshot now</button>
  </div>
  <div class="card">
    <h2 class="card-h">Restore from a file</h2>
    <p class="muted mb-4 text-sm">You'll see exactly what would change before anything is applied. Existing DNS records and vhosts are kept, not overwritten.</p>
    <input type="file" id="file" accept=".json" class="input">
  </div>
</div>
<div class="card mt-6">
  <h2 class="card-h">Import an old <code>routes.json</code></h2>
  <p class="muted mb-4 text-sm">Moved the tool, or lost its list? Pick the old <code>state\routes.json</code>. You see what would be added first. Folder paths are rebuilt for <em>this</em> machine, sites you already track are left alone, and nothing in Apache or Cloudflare is changed. (No file? Use <a class="text-indigo-600 underline" href="?page=reconcile">Reconcile → Rebuild from your live setup</a>.)</p>
  <input type="file" id="rfile" accept=".json" class="input max-w-md">
</div>
<div class="card mt-6 !p-0"><h2 class="card-h px-5 pt-5">Snapshots</h2><div id="snaps"><script>document.write(UI.loading())</script></div></div>

<script>
(() => {
  const E = UI.esc, tok = document.getElementById('tok');
  const dl = document.getElementById('dl');
  const link = () => dl.href = 'api.php?route=/api/backup/export' + (tok.checked ? '&token=1' : '');
  tok.onchange = link; link();

  async function snaps() {
    const box = document.getElementById('snaps');
    try {
      const s = (await UI.api('/api/backup/snapshots')).snapshots;
      box.innerHTML = s.length ? `<div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Taken</th><th>Reason</th><th>Routes</th><th></th></tr></thead><tbody>
        ${s.map(x => `<tr><td>${E(new Date(x.at).toLocaleString())}</td><td>${E(x.reason)}</td><td>${x.routes}</td>
          <td class="text-right"><button class="btn btn-outline" data-n="${E(x.name)}">${UI.i('archive-restore')}Restore</button></td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted px-5 pb-5 text-sm">No snapshots yet. The newest 20 are kept.</p>';
      box.querySelectorAll('[data-n]').forEach(b => b.onclick = () => preview({ snapshot: b.dataset.n }, 'snapshot ' + b.dataset.n));
    } catch (e) { box.innerHTML = '<div class="p-5">' + UI.errBox(e.message) + '</div>'; }
  }

  document.getElementById('snap').onclick = e => UI.busy(e.currentTarget, async () => { await UI.post('/api/backup/snapshot'); UI.toast('Snapshot saved'); snaps(); });

  document.getElementById('file').onchange = async e => {
    const f = e.target.files[0]; if (!f) return; e.target.value = '';
    try { preview({ bundle: JSON.parse(await f.text()) }, f.name); } catch (err) { UI.toast('Not a valid JSON file', 'error'); }
  };

  /** Dry run → show the plan → apply on confirm. */
  async function preview(src, label) {
    try {
      const plan = (await UI.post('/api/backup/plan', { ...src, config: false })).plan;
      const cfgPlan = (await UI.post('/api/backup/plan', { ...src, config: true })).plan.config;
      const ok = plan.routes.filter(r => !r.problems.length).length;
      const d = UI.modal(`<h3 class="text-lg font-semibold">Restore ${E(label)}</h3>
        <p class="muted mt-1 text-sm">${plan.routes.length} route(s) in the backup${plan.exported_at ? ', made ' + E(new Date(plan.exported_at).toLocaleString()) : ''}.</p>
        <ul class="mt-3 max-h-64 space-y-2 overflow-y-auto text-sm">${plan.routes.map(r => `<li class="flex gap-2">
          ${UI.i(r.problems.length ? 'circle-alert' : 'circle-check', 'mt-0.5 shrink-0 ' + (r.problems.length ? 'text-red-600' : 'text-green-600'))}
          <div><b>${E(r.hostname)}</b> <span class="muted text-xs">route: ${E(r.actions.route)} · vhost: ${E(r.actions.vhost)} · dns: ${E(r.actions.dns)}</span>
          ${r.problems.map(p => `<div class="text-xs text-red-600">${E(p)}</div>`).join('')}</div></li>`).join('') || '<li class="muted">No routes.</li>'}</ul>
        <label class="mt-4 flex items-start gap-3"><input type="checkbox" id="r-cfg" class="mt-1 size-4 rounded border-gray-300" ${cfgPlan.length ? '' : 'disabled'}>
          <span class="text-sm">Also restore settings<span class="hint block">${cfgPlan.length ? 'Would change: ' + E(cfgPlan.join(', ')) + '. The API token is only replaced if the file contains one.' : 'No differences.'}</span></span></label>
        <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="r-cancel">Cancel</button><button class="btn btn-primary" id="r-go" ${ok || cfgPlan.length ? '' : 'disabled'}>Restore</button></div>`);
      d.querySelector('#r-cancel').onclick = () => d.close();
      d.querySelector('#r-go').onclick = e => UI.busy(e.currentTarget, async () => {
        try {
          const r = await UI.post('/api/backup/apply', { ...src, config: d.querySelector('#r-cfg').checked, routes: true });
          d.close(); await UI.results('Restore', r.results.map(x => ({ id: x.id, ok: x.ok, error: x.error || x.detail }))); snaps();
        } catch (err) { UI.toast(err.message, 'error'); }
      });
    } catch (e) { UI.toast(e.message, 'error'); }
  }
  // ---- old routes.json: dry run → confirm → import
  document.getElementById('rfile').onchange = async e => {
    const f = e.target.files[0]; if (!f) return; e.target.value = '';
    try {
      let routes = JSON.parse(await f.text());
      if (!Array.isArray(routes)) routes = Array.isArray(routes.routes) ? routes.routes : null;     // also accepts a full backup file
      if (!routes) throw new Error('expected a list of routes');
      const dry = (await UI.post('/api/import/routes-file', { routes, dry_run: true })).results, n = dry.filter(r => r.ok && r.detail === 'would import').length;
      const d = UI.modal(`<h3 class="text-lg font-semibold">Import ${routes.length} route(s)</h3><p class="muted mt-1 text-sm">${n} would be added.</p>
        <ul class="mt-3 max-h-64 space-y-1 overflow-y-auto text-sm">${dry.map(r => `<li class="flex gap-2">${UI.i(r.ok ? 'circle-check' : 'circle-alert', 'mt-0.5 shrink-0 ' + (r.ok ? 'text-green-600' : 'text-red-600'))}<span><b>${E(r.id)}</b> <span class="muted text-xs">${E(r.detail || r.error)}</span></span></li>`).join('')}</ul>
        <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="ri-x">Cancel</button><button class="btn btn-primary" id="ri-go" ${n ? '' : 'disabled'}>Import ${n}</button></div>`);
      d.querySelector('#ri-x').onclick = () => d.close();
      d.querySelector('#ri-go').onclick = ev => UI.busy(ev.currentTarget, async () => { const r = await UI.post('/api/import/routes-file', { routes }); d.close(); await UI.results('Import', r.results.map(x => ({ id: x.id, ok: x.ok, error: x.error || x.detail }))); });
    } catch (err) { UI.toast('Could not read that file: ' + err.message, 'error'); }
  };
  snaps();
})();
</script>
