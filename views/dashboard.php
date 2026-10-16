<?php $htdocs = htmlspecialchars(Config::load()['paths']['htdocs']); ?>
<?= pageHeader('Dashboard', 'Projects in <code>' . $htdocs . '</code>',
  '<button class="btn btn-outline" onclick="location.reload()"><i data-lucide="refresh-cw"></i>Refresh</button>
   <a href="?page=attach" class="btn btn-primary"><i data-lucide="plug-zap"></i>Attach Route</a>') ?>

<div id="alert"></div>
<div id="stats" class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4"></div>
<div id="root"><script>document.write(UI.loading('Loading projects…'))</script></div>

<script>
(async () => {
  const root = document.getElementById('root'), statsEl = document.getElementById('stats');
  let projects = [], recon = {}, reconOk = false, reconState = 'loading';   // loading → ok | failed

  // Local data only: the page paints at once. The Cloudflare DNS check (the slow part) fills in afterwards.
  try { projects = (await UI.api('/api/projects')).projects || []; }
  catch (e) { root.innerHTML = UI.errBox(e.message); return; }

  if (!projects.length) {
    root.innerHTML = UI.empty('folder-x', 'No projects found', 'Add project folders to your htdocs directory, then refresh.');
    return;
  }

  if (projects.length && projects.every(p => p.route_status === 'none')) {
    document.getElementById('alert').insertAdjacentHTML('beforeend', `<div class="mb-4 flex gap-3 rounded-lg border border-blue-300 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">${UI.i('info')}
      <div><b>No routes are being tracked yet.</b> If you had sites live before, they still exist in Apache and Cloudflare — <a class="underline" href="?page=reconcile">rebuild the list from your live setup</a>, or <a class="underline" href="?page=backup">import an old routes.json</a>. Nothing is changed in Apache or Cloudflare.</div></div>`);
  }
  const count = s => projects.filter(p => p.route_status === s).length;
  function drawStats() {
    const problems = reconOk ? Object.values(recon).filter(r => r.status !== 'ok').length : 0;
    statsEl.innerHTML =
      UI.stat('circle-check', 'green', count('active'), 'Live') +
      UI.stat('folder', 'gray', projects.length, 'Projects') +
      UI.stat('unlink', 'yellow', count('none'), 'Unrouted') +
      (reconState === 'loading' ? UI.stat('heart-pulse', 'gray', '…', 'DNS health') : reconState === 'failed' ? UI.stat('wifi-off', 'gray', 'Unknown', 'DNS health')
        : UI.stat(problems ? 'triangle-alert' : 'heart-pulse', problems ? 'red' : 'green', problems ? problems + ' issue' + (problems > 1 ? 's' : '') : 'All OK', 'DNS health'));
  }
  drawStats();

  const BADGE = {
    active: ['green', 'Live'], disabled: ['yellow', 'Disabled'], none: ['gray', 'Not routed'],
    dns_missing: ['red', 'DNS missing'], vhost_missing: ['red', 'VHost missing'], drifted: ['orange', 'Drifted'], broken: ['red', 'Broken'],
  };

  function card(p) {
    const r = p.hostname ? recon[p.hostname] : null;
    let status = p.route_status;
    if (r && status !== 'none') status = { dns_missing: 'dns_missing', vhost_missing: 'vhost_missing', drifted: 'drifted', both_missing: 'broken' }[r.status] || status;
    const [color, label] = BADGE[status] || BADGE.none;
    const routed = status !== 'none';
    const fix = ['dns_missing', 'vhost_missing', 'drifted', 'broken'].includes(status);
    const pill = (ok, t) => `<span class="inline-flex items-center gap-1 text-xs ${ok ? 'text-green-600' : 'text-red-600'}">${UI.i(ok ? 'check' : 'x', '!size-3')}${t}</span>`;

    const page = p.hostname ? '?page=route&host=' + encodeURIComponent(p.hostname) : '?page=project&name=' + encodeURIComponent(p.name);
    return `<div class="card flex cursor-pointer flex-col gap-3 transition hover:border-indigo-400 hover:shadow-sm" data-card="${UI.esc(p.name)}" data-href="${page}">
      <div class="flex items-start justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2">
          <input type="checkbox" class="sel size-4 shrink-0 rounded border-gray-300" data-name="${UI.esc(p.name)}" data-host="${UI.esc(p.hostname || '')}" aria-label="Select ${UI.esc(p.name)}">
          <span class="grid size-9 shrink-0 place-content-center rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800">${UI.i('folder', '!size-5')}</span>
          <div class="min-w-0"><div class="truncate font-semibold"><a href="${page}" class="hover:text-indigo-600 hover:underline" title="Open the ${p.hostname ? 'route' : 'project'} page">${UI.esc(p.name)}</a></div>
            <div class="muted truncate text-xs" title="${UI.esc(p.path)}">${UI.esc(p.path)}</div></div>
        </div>${UI.badge(color, label)}
      </div>
      ${p.hostname ? `<span class="inline-flex items-center gap-2 text-sm"><a href="?page=route&host=${encodeURIComponent(p.hostname)}" class="inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline" title="Open this route's page">${UI.i('globe', '!size-3.5')}${UI.esc(p.hostname)}</a><a href="https://${UI.esc(p.hostname)}" target="_blank" rel="noopener" class="text-gray-400 hover:text-indigo-600" title="Open the live site">${UI.i('external-link', '!size-3.5')}</a></span>` : ''}
      ${routed ? UI.expiryControl(p.hostname, p.expires_at, p.expired_at) : ''}
      ${routed && reconOk && r ? `<div class="flex gap-3">${pill(r.has_dns, 'DNS')}${pill(r.has_vhost, 'VHost')}</div>` : ''}
      ${routed && reconState === 'loading' ? '<div class="muted text-xs">Checking DNS…</div>' : ''}
      ${routed && reconState === 'failed' ? '<div class="muted text-xs">DNS unknown — Cloudflare not reachable</div>' : ''}
      <div class="mt-auto flex flex-wrap gap-2 pt-1">
        <a href="?page=files&${p.hostname ? 'site=' + encodeURIComponent(p.hostname) : 'project=' + encodeURIComponent(p.name)}" class="btn btn-ghost" title="Open this site's file manager">${UI.i('folder-code')}Files</a>
        ${routed ? `
          <button class="btn btn-outline js-toggle" data-host="${UI.esc(p.hostname)}">${UI.i(p.route_status === 'active' ? 'toggle-right' : 'toggle-left')}${p.route_status === 'active' ? 'Disable' : 'Enable'}</button>
          <a href="?page=detach&hostname=${encodeURIComponent(p.hostname)}" class="btn btn-ghost-danger">${UI.i('trash')}Detach</a>
          ${fix ? `<a href="?page=reconcile" class="btn btn-ghost text-orange-600">${UI.i('wrench')}Fix drift</a>` : ''}`
        : `<a href="?page=attach&project=${encodeURIComponent(p.name)}" class="btn btn-primary">${UI.i('plug-zap')}Attach</a>
           <button class="btn btn-outline js-share" data-project="${UI.esc(p.name)}">${UI.i('share-2')}Share</button>`}
      </div></div>`;
  }

  root.innerHTML = `<label class="mb-3 inline-flex items-center gap-2 text-sm"><input type="checkbox" id="selAll" class="size-4 rounded border-gray-300"> Select all</label>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">${projects.map(card).join('')}</div>
    <div id="bulkbar" hidden class="fixed bottom-4 left-1/2 z-40 flex max-w-[95vw] -translate-x-1/2 flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg lg:ml-32 dark:border-gray-700 dark:bg-gray-900">
      <span class="px-2 text-sm font-medium" id="bulkcount"></span>
      <button class="btn btn-outline" data-bulk="attach">${UI.i('plug-zap')}Attach</button>
      <button class="btn btn-outline" data-bulk="enable">${UI.i('toggle-right')}Enable</button>
      <button class="btn btn-outline" data-bulk="disable">${UI.i('toggle-left')}Disable</button>
      <button class="btn btn-outline" data-bulk="expiry">${UI.i('timer')}Expiry</button>
      <button class="btn btn-danger" data-bulk="detach">${UI.i('trash')}Detach</button>
      <button class="btn btn-ghost" id="bulkclear" aria-label="Clear selection">${UI.i('x')}</button></div>`;

  // ---- bulk selection ----
  const boxes = () => [...root.querySelectorAll('.sel')];
  const picked = () => boxes().filter(b => b.checked);
  const bar = document.getElementById('bulkbar');
  function refreshBar() {
    const sel = picked(), routed = sel.filter(b => b.dataset.host), free = sel.length - routed.length;
    bar.hidden = !sel.length;
    document.getElementById('bulkcount').textContent = sel.length + ' selected';
    bar.querySelectorAll('[data-bulk]').forEach(b => b.hidden = b.dataset.bulk === 'attach' ? !free : !routed.length);
    document.getElementById('selAll').checked = sel.length === projects.length;
  }
  root.addEventListener('change', e => { if (e.target.classList.contains('sel')) refreshBar(); });
  document.getElementById('selAll').onchange = e => { boxes().forEach(b => b.checked = e.target.checked); refreshBar(); };
  document.getElementById('bulkclear').onclick = () => { boxes().forEach(b => b.checked = false); refreshBar(); };

  async function runBulk(btn, title, body) {
    await UI.busy(btn, async () => { const r = await UI.post('/api/bulk', body); await UI.results(title, r.results); location.reload(); });
  }
  bar.querySelectorAll('[data-bulk]').forEach(btn => btn.onclick = async () => {
    const kind = btn.dataset.bulk, sel = picked();
    const hostnames = sel.map(b => b.dataset.host).filter(Boolean), free = sel.filter(b => !b.dataset.host).map(b => b.dataset.name);
    if (kind === 'attach') return bulkAttach(btn, free);
    if (kind === 'detach' && !await UI.confirm('Detach ' + hostnames.length + ' route(s)?', 'Deletes their DNS records and vhost blocks. Project folders are not touched.', 'Detach all')) return;
    let extra = {};
    if (kind === 'expiry') { extra = await UI.pickExpiry(hostnames.length + ' routes', null, hostnames.length); if (!extra) return; }
    runBulk(btn, { enable: 'Enable', disable: 'Disable', detach: 'Detach', expiry: 'Set expiry' }[kind], { action: kind, hostnames, ...extra });
  });

  async function bulkAttach(btn, names) {
    let zones; try { zones = (await UI.api('/api/zones')).zones; } catch (e) { return UI.toast(e.message, 'error'); }
    let last = ''; try { last = localStorage.getItem('share_zone') || ''; } catch (e) {}
    const d = UI.modal(`<h3 class="text-lg font-semibold">Attach ${names.length} project(s)</h3>
      <div class="mt-4 grid gap-3">
        <div><label class="label">Domain</label><select class="input" id="ba-zone">${zones.map(z => `<option value="${z.id}" ${z.id === last ? 'selected' : ''}>${UI.zoneLabel(z)}</option>`).join('')}</select></div>
        <div><label class="label">Subdomain pattern</label><input class="input" id="ba-pat" value="{project}"><p class="hint">Use <code>{project}</code>, e.g. <code>{project}.dev</code>. Folder names are made DNS-safe.</p></div>
        <div class="code max-h-32 overflow-y-auto" id="ba-prev"></div>
        <label class="flex items-center gap-3">${UI.toggle('ba-www')}<span class="text-sm">Add www alias</span></label>
        <div><label class="label">Expires</label>${UI.expiryField('ba-exp')}</div></div>
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="ba-cancel">Cancel</button><button class="btn btn-primary" id="ba-go">Attach all</button></div>`);
    UI.wireExpiryField('ba-exp');
    const slug = n => n.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63);
    const zoneName = () => (zones.find(z => z.id === d.querySelector('#ba-zone').value) || {}).name || '';
    const prev = () => {
      const pat = d.querySelector('#ba-pat').value.trim();
      d.querySelector('#ba-prev').textContent = pat.includes('{project}') ? names.map(n => pat.replace('{project}', slug(n)).replace(/^\.+|\.+$/g, '') + '.' + zoneName()).join('\n') : 'Pattern must contain {project}';
    };
    d.querySelector('#ba-pat').oninput = prev; d.querySelector('#ba-zone').onchange = prev; prev();
    d.querySelector('#ba-cancel').onclick = () => d.close();
    const go = d.querySelector('#ba-go');
    go.onclick = () => UI.busy(go, async () => {
      let exp; try { exp = UI.readExpiryField('ba-exp'); } catch (e) { return UI.toast(e.message, 'error'); }
      const zone_id = d.querySelector('#ba-zone').value;
      try { localStorage.setItem('share_zone', zone_id); } catch (e) {}
      const r = await UI.post('/api/bulk', { action: 'attach', projects: names, zone_id, pattern: d.querySelector('#ba-pat').value.trim(),
                                              www_alias: d.querySelector('#ba-www').checked, ...exp });
      d.close(); await UI.results('Attach', r.results); location.reload();
    });
  }

  function bindCards(scope) {
    scope.querySelectorAll('.js-share').forEach(b => b.onclick = () => UI.share(b.dataset.project).then(() => {}));
    UI.bindExpiry(scope, () => location.reload());
    scope.querySelectorAll('.js-toggle').forEach(b => b.onclick = () => UI.busy(b, async () => {
      await UI.post('/api/routes/' + encodeURIComponent(b.dataset.host) + '/toggle');
      location.reload();
    }));
  }
  bindCards(root);
  root.addEventListener('click', e => {
    if (e.target.closest('a, button, input, select, label, textarea, dialog')) return;       // those do their own thing
    const card = e.target.closest('[data-card]'); if (card && card.dataset.href) location.href = card.dataset.href;
  });

  // ---- the slow part: compare with live DNS, then update only what changed (selection is kept)
  UI.api('/api/reconcile', { allowFail: true }).catch(e => ({ ok: false, error: e.message })).then(rr => {
    if (rr.ok) { reconOk = true; reconState = 'ok'; for (const r of rr.routes) recon[r.hostname] = r; }
    else { reconState = 'failed'; document.getElementById('alert').insertAdjacentHTML('beforeend', UI.warn('Live DNS check unavailable (' + (rr.error || 'Cloudflare unreachable') + ') — showing local status only.')); }
    projects.forEach(p => {
      const old = root.querySelector(`[data-card="${CSS.escape(p.name)}"]`); if (!old) return;
      const checked = old.querySelector('.sel').checked;
      old.outerHTML = card(p);
      const fresh = root.querySelector(`[data-card="${CSS.escape(p.name)}"]`); fresh.querySelector('.sel').checked = checked; bindCards(fresh);
    });
    drawStats(); refreshBar();
  });
})();
</script>
