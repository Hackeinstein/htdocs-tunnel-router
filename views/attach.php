<?php $pre = trim($_GET['project'] ?? ''); ?>
<?= pageHeader('Attach Route', 'Route a project folder to a public hostname via Cloudflare Tunnel') ?>
<div id="root"><script>document.write(UI.loading())</script></div>
<script>
(async () => {
  const root = document.getElementById('root'), E = UI.esc;
  const PRE = <?= json_encode($pre) ?>;
  let projects = [], zones = [];
  const form = { project: '', zone_id: '', subdomain: '', www_alias: false, label: '' };
  const card = (title, body) => `<div class="card"><h2 class="card-h">${title}</h2>${body}</div>`;
  const back = id => `<button class="btn btn-ghost" id="${id}">${UI.i('arrow-left')}Back</button>`;

  function step1() {
    const free = projects.filter(p => p.route_status === 'none');
    if (!free.length) { root.innerHTML = UI.empty('circle-check', 'All projects are already routed', 'Detach one or add a new folder to htdocs.', '<a href="?page=dashboard" class="btn btn-primary">Back to Dashboard</a>'); return; }
    root.innerHTML = card('Step 1 · Choose project', `<p class="muted mb-4 text-sm">Pick the htdocs folder to make publicly accessible.</p>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">${free.map(p => `
        <button data-p="${E(p.name)}" class="pick flex items-center gap-3 rounded-lg border border-gray-200 p-3 text-left hover:border-indigo-400 dark:border-gray-700">
          ${UI.i('folder', '!size-5 text-gray-400')}<span class="min-w-0"><b class="block truncate">${E(p.name)}</b><span class="muted block truncate text-xs">${E(p.path)}</span></span></button>`).join('')}</div>`);
    root.querySelectorAll('.pick').forEach(b => b.onclick = () => { form.project = b.dataset.p; step2(); });
  }

  function step2() {
    const suffix = () => { const z = zones.find(z => z.id === form.zone_id); return z ? '.' + z.name : '.example.com'; };
    root.innerHTML = card('Step 2 · Hostname', `
      <div class="mb-4 text-sm">Project: ${UI.badge('blue', E(form.project))}</div>
      <div class="grid max-w-xl gap-4">
        <div><label class="label">Cloudflare zone</label><select class="input" id="zone"><option value="">— choose a zone —</option>${zones.map(z => `<option value="${E(z.id)}" ${z.id === form.zone_id ? 'selected' : ''}>${UI.zoneLabel(z)}</option>`).join('')}</select></div>
        <div><label class="label">Subdomain</label><div class="flex"><input id="sub" class="input !rounded-r-none" placeholder="app" maxlength="63" value="${E(form.subdomain)}">
          <span id="suffix" class="inline-flex items-center rounded-r-lg border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800">${E(suffix())}</span></div>
          <p class="hint">For <code>app.example.com</code> enter <code>app</code>.</p></div>
        <label class="flex items-center gap-3">${UI.toggle('apex')}<span class="text-sm">Use apex (root domain)<span class="hint block">May replace an existing A/CNAME record.</span></span></label>
        <label class="flex items-center gap-3">${UI.toggle('www', form.www_alias)}<span class="text-sm">Add www alias</span></label>
        <div><label class="label">Expires</label>${UI.expiryField('exp', form.exp)}<p class="hint">Optional. The route is switched off (or removed, see Settings) when it expires. You can change this later.</p></div>
        <label class="flex items-center gap-3">${UI.toggle('portal', form.portal)}<span class="text-sm">Let assigned users manage files for this route<span class="hint block">Off by default. You choose who on the Users page afterwards.</span></span></label>
        <div><label class="label">Label <span class="muted">(optional)</span></label><input id="label" class="input" maxlength="100" placeholder="Friendly name" value="${E(form.label)}"></div>
        <div class="flex gap-2">${back('b1')}<button class="btn btn-primary" id="next">Preview configuration</button></div>
      </div>`);
    const $ = id => document.getElementById(id);
    UI.wireExpiryField('exp');
    $('zone').onchange = () => { form.zone_id = $('zone').value; $('suffix').textContent = $('apex').checked ? ' (root)' : suffix(); };
    $('apex').onchange = () => { $('sub').disabled = $('apex').checked; if ($('apex').checked) $('sub').value = ''; $('suffix').textContent = $('apex').checked ? ' (root)' : suffix(); };
    $('b1').onclick = step1;
    $('next').onclick = () => {
      form.zone_id = $('zone').value; form.www_alias = $('www').checked; form.label = $('label').value.trim(); form.portal = $('portal').checked;
      form.subdomain = $('apex').checked ? '' : $('sub').value.trim().toLowerCase();
      try { form.expiry = UI.readExpiryField('exp'); form.exp = form.expiry.expires_in; } catch (e) { return UI.toast(e.message, 'error'); }
      if (!form.zone_id) return UI.toast('Choose a zone.', 'error');
      if (!$('apex').checked && !form.subdomain) return UI.toast('Enter a subdomain or enable apex.', 'error');
      if (form.subdomain && !/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(form.subdomain)) return UI.toast('Subdomain: letters, numbers, hyphens only.', 'error');
      step3();
    };
  }

  async function step3() {
    root.innerHTML = UI.loading('Building preview…');
    try {
      const q = new URLSearchParams({ project: form.project, zone_id: form.zone_id, subdomain: form.subdomain, www_alias: form.www_alias ? '1' : '0' });
      const d = await UI.api('/api/preview&' + q);
      form.hostname = d.hostname;
      root.innerHTML = card('Step 3 · Review & confirm', `
        <div class="mb-4 flex flex-wrap items-center gap-3 text-sm"><b>${E(d.hostname)}</b>${UI.badge('gray', form.subdomain === '' ? 'Apex' : 'Subdomain')}${form.www_alias ? UI.badge('blue', '+ www') : ''}
          ${form.expiry && Object.keys(form.expiry).length ? UI.badge('yellow', 'temporary', 'timer') : ''}</div>
        <div class="mb-1 text-sm font-medium">DNS record (Cloudflare)</div><div class="code mb-4">${E(JSON.stringify(d.dns_payload, null, 2))}</div>
        <div class="mb-1 text-sm font-medium">VirtualHost block (Apache)</div><div class="code mb-4">${E(d.vhost_block)}</div>
        ${d.apex_conflict ? `<div class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
          <b>Apex conflict:</b> an existing <code>${E(d.apex_conflict.type)}</code> record points to <code>${E(d.apex_conflict.content)}</code>. This will <b>replace</b> it (may affect email if MX points here).
          <label class="mt-2 flex items-center gap-2"><input type="checkbox" id="force"> I understand, replace it</label></div>` : ''}
        <div class="flex gap-2">${back('b2')}<button class="btn btn-success" id="go" ${d.apex_conflict ? 'disabled' : ''}>${UI.i('check')}Confirm & apply</button></div>`);
      document.getElementById('b2').onclick = step2;
      if (d.apex_conflict) document.getElementById('force').onchange = e => document.getElementById('go').disabled = !e.target.checked;
      document.getElementById('go').onclick = () => step4(!!d.apex_conflict);
    } catch (e) { root.innerHTML = UI.errBox('Preview failed: ' + e.message) + `<div class="mt-3">${back('b2')}</div>`; document.getElementById('b2').onclick = step2; }
  }

  async function step4(force) {
    root.innerHTML = UI.loading('Creating DNS record & vhost block…');
    try {
      const d = await UI.post('/api/routes', { project: form.project, zone_id: form.zone_id, subdomain: form.subdomain, www_alias: form.www_alias, label: form.label, portal_enabled: !!form.portal, ...(form.expiry || {}), force_apex: force });
      root.innerHTML = `<div class="card py-10 text-center">${UI.i('circle-check', '!size-12 mx-auto text-green-600')}
        <h2 class="mt-3 text-xl font-semibold">Route attached</h2>
        <p class="muted mt-1"><b>${E(d.hostname)}</b> → <code>${E(form.project)}</code></p>
        <p class="mt-2">${UI.expiryControl(d.hostname, d.expires_at)}</p>
        <div class="mx-auto mt-5 max-w-md space-y-2 text-left text-sm">
          <div class="rounded-lg bg-green-50 p-3 dark:bg-green-950">${UI.i('cloud', 'mr-1 text-green-600')}<b>Cloudflare CNAME created</b><div class="muted mt-1 font-mono text-xs">${E(d.dns_record_id || '')}</div></div>
          <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-950">${UI.i('server', 'mr-1 text-blue-600')}<b>Apache vhost written</b><div class="muted mt-1 text-xs">httpd-vhosts.conf updated &amp; reloaded</div></div></div>
        <div class="mt-6 flex justify-center gap-2"><a href="?page=dashboard" class="btn btn-primary">Back to Dashboard</a><a href="?page=attach" class="btn btn-outline">Attach another</a></div></div>`;
      UI.bindExpiry(root, () => location.reload());
    } catch (e) {
      const d = e.data || {};
      if (d.apex_conflict) { root.innerHTML = UI.errBox(e.message) + `<div class="mt-3 flex gap-2"><button class="btn btn-danger" id="force">Replace & continue</button>${back('b2')}</div>`;
        document.getElementById('force').onclick = () => step4(true); document.getElementById('b2').onclick = step2; return; }
      root.innerHTML = card(d.step === 'apache_validate' ? 'Apache validation failed' : 'Attach failed',
        UI.errBox(e.message) + (d.output ? `<div class="mt-3 text-sm font-medium"><code>httpd -t</code> output</div><div class="code mt-1">${E(d.output)}</div><p class="muted mt-2 text-xs">The DNS record was rolled back. Fix the Apache config, then try again.</p>` : '')
        + `<div class="mt-4 flex gap-2">${back('b2')}<a href="?page=dashboard" class="btn btn-outline">Dashboard</a></div>`);
      document.getElementById('b2').onclick = step2;
    }
  }

  try {
    const [p, z] = await Promise.all([UI.api('/api/projects'), UI.api('/api/zones')]);
    projects = p.projects; zones = z.zones;
    if (PRE && projects.some(x => x.name === PRE && x.route_status === 'none')) { form.project = PRE; step2(); } else step1();
  } catch (e) { root.innerHTML = UI.errBox('Failed to load: ' + e.message); }
})();
</script>
