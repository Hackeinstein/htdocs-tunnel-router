<?php $zone = preg_replace('/[^a-f0-9]/', '', $_GET['zone'] ?? ''); ?>
<?= pageHeader('DNS', 'Browse and edit records in a zone. Records that belong to a tunnel route are protected.',
  '<select id="zone" class="input !w-auto"></select>
   <button class="btn btn-outline" id="imp">' . '<i data-lucide="upload"></i>Import</button>
   <a class="btn btn-outline" id="exp-json" href="#"><i data-lucide="download"></i>JSON</a>
   <a class="btn btn-outline" id="exp-csv" href="#"><i data-lucide="download"></i>CSV</a>
   <button class="btn btn-primary" id="add"><i data-lucide="plus"></i>Add record</button>') ?>
<input type="file" id="file" accept=".json,.csv" hidden>
<div class="mb-4 flex flex-wrap gap-2">
  <input id="q" class="input !w-64" placeholder="Search name, content, comment…">
  <select id="type" class="input !w-auto"><option value="">All types</option></select>
</div>
<div id="root"><script>document.write(UI.loading('Loading records…'))</script></div>
<script>
(async () => {
  const E = UI.esc, root = document.getElementById('root');
  let ZONE = <?= json_encode($zone) ?>, records = [], zoneName = '';
  const TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS'];
  const typeSel = document.getElementById('type');
  TYPES.forEach(t => typeSel.insertAdjacentHTML('beforeend', `<option>${t}</option>`));

  let zones;
  try { zones = (await UI.api('/api/zones')).zones; } catch (e) { root.innerHTML = UI.errBox(e.message); return; }
  if (!zones.length) { root.innerHTML = UI.empty('globe', 'No zones', 'This token cannot see any zones.'); return; }
  if (!zones.some(z => z.id === ZONE)) ZONE = zones[0].id;
  const zsel = document.getElementById('zone');
  zsel.innerHTML = zones.map(z => `<option value="${z.id}" ${z.id === ZONE ? 'selected' : ''}>${UI.zoneLabel(z)}</option>`).join('');
  zsel.onchange = () => { ZONE = zsel.value; history.replaceState(null, '', '?page=dns&zone=' + ZONE); load(); };

  async function load() {
    zoneName = zones.find(z => z.id === ZONE).name;
    document.getElementById('exp-json').href = 'api.php?route=/api/dns/' + ZONE + '/export&format=json';
    document.getElementById('exp-csv').href = 'api.php?route=/api/dns/' + ZONE + '/export&format=csv';
    root.innerHTML = UI.loading('Loading records…');
    try { records = (await UI.api('/api/dns/' + ZONE)).records; render(); } catch (e) { root.innerHTML = UI.errBox(e.message); }
  }

  function render() {
    const q = document.getElementById('q').value.toLowerCase(), t = typeSel.value;
    const rows = records.filter(r => (!t || r.type === t) && (!q || (r.name + ' ' + r.content + ' ' + r.comment).toLowerCase().includes(q)));
    root.innerHTML = `<div class="card !p-0"><div class="overflow-x-auto"><table class="tbl">
      <thead><tr><th>Type</th><th>Name</th><th>Content</th><th>TTL</th><th>Proxy</th><th></th></tr></thead><tbody>
      ${rows.map(r => `<tr>
        <td>${UI.badge(r.editable ? 'blue' : 'gray', r.type)}</td>
        <td class="font-medium">${E(r.name)}${r.route ? `<div>${UI.badge('green', 'route', 'plug-zap')}</div>` : r.tunnel ? `<div>${UI.badge('yellow', 'tunnel record')}</div>` : ''}${r.comment ? `<div class="muted text-xs">${E(r.comment)}</div>` : ''}</td>
        <td class="max-w-md truncate" title="${E(r.content)}">${r.priority != null ? `<span class="muted">${r.priority}</span> ` : ''}${E(r.content)}</td>
        <td>${r.ttl === 1 ? 'Auto' : r.ttl}</td>
        <td>${r.proxied ? UI.badge('yellow', 'Proxied', 'cloud') : '<span class="muted">DNS only</span>'}</td>
        <td class="text-right">${r.editable ? `<button class="btn btn-ghost js-edit" data-id="${r.id}" title="Edit">${UI.i('pencil')}</button>` : ''}
          <button class="btn btn-ghost-danger js-del" data-id="${r.id}" title="Delete">${UI.i('trash')}</button></td></tr>`).join('')
        || '<tr><td colspan="6" class="muted py-8 text-center">No records match.</td></tr>'}</tbody></table></div></div>
      <p class="muted mt-2 text-xs">${rows.length} of ${records.length} records</p>`;
    root.querySelectorAll('.js-edit').forEach(b => b.onclick = () => edit(records.find(r => r.id === b.dataset.id)));
    root.querySelectorAll('.js-del').forEach(b => b.onclick = () => del(records.find(r => r.id === b.dataset.id), b));
  }
  document.getElementById('q').oninput = render; typeSel.onchange = render;

  // ---- add / edit ----
  function edit(rec) {
    const r = rec || { type: 'A', name: '', content: '', ttl: 1, proxied: false, priority: 10, comment: '' };
    const d = UI.modal(`<h3 class="text-lg font-semibold">${rec ? 'Edit' : 'Add'} record</h3>
      ${rec && (rec.route || rec.tunnel) ? UI.warn(rec.route ? 'This record belongs to the route ' + rec.route + '. Changing it can break the site.' : 'This record points at a tunnel.') : ''}
      <div class="mt-4 grid gap-3">
        <div class="grid grid-cols-3 gap-3"><div><label class="label">Type</label><select class="input" id="f-type">${TYPES.map(t => `<option ${t === r.type ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
          <div class="col-span-2"><label class="label">Name</label><input class="input" id="f-name" value="${E(rec ? r.name.replace(new RegExp('\\.?' + zoneName.replace(/\./g, '\\.') + '$'), '') || '@' : '')}" placeholder="app or @"><p class="hint">…${E(zoneName)}</p></div></div>
        <div><label class="label">Content</label><input class="input" id="f-content" value="${E(r.content)}"></div>
        <div class="grid grid-cols-3 gap-3"><div><label class="label">TTL (s, 1 = auto)</label><input class="input" id="f-ttl" type="number" value="${r.ttl}"></div>
          <div id="f-prio-w"><label class="label">MX priority</label><input class="input" id="f-prio" type="number" value="${r.priority ?? 10}"></div>
          <label class="flex items-end gap-2 pb-2" id="f-prox-w">${UI.toggle('f-prox', r.proxied)}<span class="text-sm">Proxied</span></label></div>
        <div><label class="label">Comment</label><input class="input" id="f-comment" maxlength="100" value="${E(r.comment)}"></div></div>
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="f-cancel">Cancel</button><button class="btn btn-primary" id="f-save">Save</button></div>`);
    const $ = id => d.querySelector('#' + id);
    const sync = () => { const t = $('f-type').value; $('f-prio-w').hidden = t !== 'MX'; $('f-prox-w').hidden = !['A', 'AAAA', 'CNAME'].includes(t); };
    $('f-type').onchange = sync; sync();
    $('f-cancel').onclick = () => d.close();
    $('f-save').onclick = () => UI.busy($('f-save'), async () => {
      const body = { type: $('f-type').value, name: $('f-name').value, content: $('f-content').value, ttl: +$('f-ttl').value, priority: +$('f-prio').value, proxied: $('f-prox').checked, comment: $('f-comment').value };
      if (rec) await UI.post('/api/dns/' + ZONE + '/' + rec.id, { ...body, force: !!(rec.route || rec.tunnel) }, 'PUT');
      else await UI.post('/api/dns/' + ZONE, body);
      d.close(); UI.toast('Record saved'); load();
    });
  }
  document.getElementById('add').onclick = () => edit(null);

  async function del(rec, btn) {
    const owned = rec.route;
    if (!await UI.confirm('Delete ' + rec.type + ' ' + rec.name + '?', owned ? 'This record belongs to the route ' + owned + '. Deleting it takes that site offline; detach the route instead if you want a clean removal.' : 'This cannot be undone.', 'Delete')) return;
    UI.busy(btn, async () => { await UI.api('/api/dns/' + ZONE + '/' + rec.id + (owned ? '&force=1' : ''), { method: 'DELETE' }); UI.toast('Record deleted'); load(); });
  }

  // ---- import (JSON or CSV file → dry run → apply) ----
  const parseCsv = text => {
    const rows = []; let row = [], cell = '', q = false;
    for (let i = 0; i < text.length; i++) { const c = text[i];
      if (q) { if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') q = false; else cell += c; }
      else if (c === '"') q = true; else if (c === ',') { row.push(cell); cell = ''; }
      else if (c === '\n') { row.push(cell.replace(/\r$/, '')); rows.push(row); row = []; cell = ''; } else cell += c; }
    if (cell || row.length) { row.push(cell.replace(/\r$/, '')); rows.push(row); }
    const [head, ...body] = rows.filter(r => r.some(x => x !== ''));
    return body.map(r => Object.fromEntries(head.map((h, i) => [h.trim(), r[i]])));
  };
  const file = document.getElementById('file');
  document.getElementById('imp').onclick = () => file.click();
  file.onchange = async () => {
    const f = file.files[0]; if (!f) return; file.value = '';
    try {
      const text = await f.text();
      const recs = f.name.endsWith('.csv') ? parseCsv(text) : JSON.parse(text);
      const dry = await UI.post('/api/dns/' + ZONE + '/import', { records: recs, dry_run: true });
      const creating = dry.results.filter(r => r.ok && r.detail === 'would create').length;
      const d = UI.modal(`<h3 class="text-lg font-semibold">Import ${recs.length} record(s)</h3><p class="muted mt-1 text-sm">${creating} would be created; existing records are skipped.</p>
        <ul class="mt-3 max-h-64 space-y-1 overflow-y-auto text-sm">${dry.results.map(r => `<li class="flex gap-2">${UI.i(r.ok ? 'circle-check' : 'circle-alert', 'mt-0.5 shrink-0 ' + (r.ok ? 'text-green-600' : 'text-red-600'))}<span><b>${E(r.id)}</b> <span class="muted text-xs">${E(r.detail || r.error)}</span></span></li>`).join('')}</ul>
        <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="i-cancel">Cancel</button><button class="btn btn-primary" id="i-go" ${creating ? '' : 'disabled'}>Import ${creating}</button></div>`);
      d.querySelector('#i-cancel').onclick = () => d.close();
      d.querySelector('#i-go').onclick = e => UI.busy(e.currentTarget, async () => { const r = await UI.post('/api/dns/' + ZONE + '/import', { records: recs }); d.close(); await UI.results('Import', r.results.map(x => ({ id: x.id, ok: x.ok, error: x.error }))); load(); });
    } catch (e) { UI.toast('Could not read file: ' + e.message, 'error'); }
  };

  load();
})();
</script>
