<?= pageHeader('Logs', 'Apache and PHP logs, with live tail.', '<a class="btn btn-outline" href="?page=files"><i data-lucide="folder-open"></i>Files</a>') ?>
<div class="mb-3 flex flex-wrap items-center gap-2">
  <select id="src" class="input !w-auto"></select>
  <select id="lines" class="input !w-auto"><option>200</option><option selected>500</option><option>1000</option><option>2000</option></select>
  <input id="filter" class="input !w-56" placeholder="Filter lines…">
  <label class="flex items-center gap-2 text-sm"><span id="live-w"></span>Live tail</label>
  <button class="btn btn-outline" id="reload"><i data-lucide="refresh-cw"></i>Reload</button>
  <button class="btn btn-ghost" id="clear"><i data-lucide="eraser"></i>Clear view</button>
  <span class="muted ml-auto text-xs" id="meta"></span>
</div>
<pre id="out" class="code !whitespace-pre-wrap break-all" style="height: calc(100vh - 17rem); min-height: 16rem; overflow: auto"></pre>
<script>
(() => {
  const E = UI.esc, out = document.getElementById('out'), src = document.getElementById('src');
  document.getElementById('live-w').outerHTML = UI.toggle('live', false);
  let offset = null, timer = null, lines = [];
  const LABEL = { apache_error: 'Apache error log', apache_access: 'Apache access log', php_error: 'PHP error log' };

  const paint = () => {
    const f = document.getElementById('filter').value.toLowerCase();
    const near = out.scrollHeight - out.scrollTop - out.clientHeight < 60;
    out.innerHTML = lines.filter(l => !f || l.toLowerCase().includes(f)).map(l =>
      /\b(error|fatal|crit|alert|emerg)\b/i.test(l) ? `<span class="text-red-600">${E(l)}</span>` : /\bwarn(ing)?\b/i.test(l) ? `<span class="text-yellow-600">${E(l)}</span>` : E(l)).join('\n');
    if (near) out.scrollTop = out.scrollHeight;
  };

  async function load(full) {
    const cap = +document.getElementById('lines').value;
    try {
      const r = await UI.api(`/api/logs&name=${src.value}&lines=${cap}` + (!full && offset != null ? '&offset=' + offset : ''));
      if (!r.exists) { lines = []; out.innerHTML = '<span class="muted">This log file does not exist yet.</span>'; offset = null; return; }
      const fresh = r.text ? r.text.replace(/\r?\n$/, '').split(/\r?\n/) : [];
      lines = full || r.reset ? fresh : lines.concat(fresh).slice(-cap * 2);
      offset = r.offset; paint();
      document.getElementById('meta').textContent = UI.bytes(r.size) + ' · ' + new Date().toLocaleTimeString();
    } catch (e) { out.innerHTML = UI.errBox(e.message); live.checked = false; stop(); }
  }
  const stop = () => { clearInterval(timer); timer = null; };
  const live = document.getElementById('live');
  live.onchange = () => { stop(); if (live.checked) timer = setInterval(() => load(false), 2000); };
  document.getElementById('reload').onclick = () => { offset = null; load(true); };
  document.getElementById('clear').onclick = () => { lines = []; out.innerHTML = ''; };
  document.getElementById('filter').oninput = paint;
  src.onchange = () => { offset = null; load(true); }; document.getElementById('lines').onchange = () => load(true);

  (async () => {
    try {
      const s = (await UI.api('/api/logs/sources')).sources;
      src.innerHTML = s.map(x => `<option value="${x.name}" ${x.exists ? '' : 'disabled'}>${LABEL[x.name]}${x.exists ? '' : ' (not found)'}</option>`).join('');
      const first = s.find(x => x.exists);
      if (!first) { out.innerHTML = '<span class="muted">No log files found. Check the Apache binary path in Settings.</span>'; return; }
      src.value = first.name; load(true);
    } catch (e) { out.innerHTML = UI.errBox(e.message); }
  })();
})();
</script>
