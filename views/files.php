<?php
// Scope: ?site=<hostname> or ?project=<folder> = that one site's files; ?all=1 = all of htdocs; neither = pick a site.
$site  = trim($_GET['site'] ?? '');
$proj  = trim($_GET['project'] ?? '');
$all   = ($_GET['all'] ?? '') === '1';
$scope = $site !== '' ? '&site=' . rawurlencode($site) : ($proj !== '' ? '&project=' . rawurlencode($proj) : '');
$picker = $scope === '' && !$all;
$startDir = trim($_GET['path'] ?? '', '/');
?>
<?= pageHeader($picker ? 'Files' : 'File manager', $picker ? 'Pick a site to browse and edit its files — each site is sandboxed to its own folder.' : '',
  $picker ? '' : '<span id="exp-slot" class="self-center"></span><a id="route-link" class="btn btn-outline" href="#" hidden><i data-lucide="gauge"></i>Route page</a><button class="btn btn-outline" id="trash-btn"><i data-lucide="trash"></i>Trash</button>
                 <a class="btn btn-outline" href="?page=logs"><i data-lucide="terminal"></i>Logs</a>',
  $picker ? null : ['All sites', '?page=files'], 'title') ?>

<?php if ($picker): ?>
<div id="root"><script>document.write(UI.loading('Loading sites…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root'), E = UI.esc;
  try {
    const s = (await UI.api('/api/fs/sites')).sites;
    const card = x => {
      const q = x.hostname ? 'site=' + encodeURIComponent(x.hostname) : 'project=' + encodeURIComponent(x.project);
      return `<a href="?page=files&${q}" class="card block transition hover:border-indigo-400 hover:shadow-sm">
        <div class="flex items-center gap-2">${UI.i('folder-code', '!size-5 text-indigo-600')}<span class="truncate font-semibold">${E(x.hostname || x.project)}</span>
          <span class="ml-auto">${x.hostname ? UI.badge(x.enabled === false ? 'yellow' : 'green', x.enabled === false ? 'Disabled' : 'Live') : UI.badge('gray', 'Not routed')}</span></div>
        <div class="muted mt-1 text-xs">${UI.i('folder', '!size-3 mr-1')}${E(x.project)}${x.expires_at ? ' · ' + UI.expiryBadge(x.expires_at) : ''}</div>
        <div class="mt-4 flex items-center gap-1 text-sm text-indigo-600">${UI.i('folder-open', '!size-4')}Open file manager</div></a>`;
    };
    root.innerHTML = s.length ? `<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">${s.map(card).join('')}</div>
      <p class="mt-6 text-sm"><a class="text-indigo-600 hover:underline" href="?page=files&all=1">Browse all of htdocs →</a></p>`
      : UI.empty('folder-x', 'No sites yet', 'Add project folders to htdocs.', '<a class="btn btn-primary" href="?page=files&all=1">Browse htdocs</a>');
  } catch (e) { root.innerHTML = UI.errBox(e.message); }
})();
</script>
<?php return; endif; ?>

<div id="fm" class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" style="height: calc(100vh - 10rem); min-height: 30rem">
  <div id="toolbar" class="flex flex-wrap items-center gap-1 border-b border-gray-200 p-2 dark:border-gray-800"></div>
  <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-1.5 text-sm dark:border-gray-800">
    <button class="btn btn-ghost !px-1.5" id="up" title="Up one folder"><i data-lucide="corner-left-up"></i></button>
    <nav id="crumbs" class="flex min-w-0 flex-1 flex-wrap items-center gap-1"></nav>
    <span class="muted text-xs" id="count"></span>
  </div>
  <div class="flex min-h-0 flex-1">
    <aside id="tree" class="w-56 shrink-0 overflow-auto border-r border-gray-200 p-2 text-sm dark:border-gray-800 max-md:hidden"></aside>
    <div id="listwrap" class="relative min-w-0 flex-1 overflow-auto">
      <div id="drop" class="pointer-events-none absolute inset-2 z-10 hidden place-content-center rounded-lg border-2 border-dashed border-indigo-400 bg-indigo-50/80 text-indigo-700 dark:bg-indigo-950/80" ><span class="p-6 text-lg font-medium">Drop files to upload here</span></div>
      <div id="list"></div>
    </div>
  </div>
</div>
<input type="file" id="upload" multiple hidden>
<div id="ctx" class="fixed z-50 hidden min-w-48 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-700 dark:bg-gray-900"></div>

<!-- Code editor (opens over the explorer) -->
<div id="editor" class="fixed inset-0 z-40 hidden flex-col bg-white lg:left-64 dark:bg-gray-950">
  <div class="flex items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 pt-2 dark:border-gray-800 dark:bg-gray-900">
    <div id="etabs" class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto"></div>
    <div class="flex shrink-0 items-center gap-1 pb-1.5">
      <label class="flex items-center gap-1.5 px-2 text-xs" title="Save automatically 1.5 s after you stop typing; the preview reloads each time"><span id="autosave-w"></span>Auto-save</label>
      <button class="btn btn-ghost" id="wrap" title="Toggle word wrap"><i data-lucide="text-wrap"></i></button>
      <button class="btn btn-ghost" id="versions" title="Previous versions"><i data-lucide="rotate-ccw"></i></button>
      <button class="btn btn-outline" id="preview-toggle"><i data-lucide="panel-right"></i>Live preview</button>
      <button class="btn btn-primary" id="save"><i data-lucide="save"></i>Save <span class="opacity-70">Ctrl+S</span></button>
      <button class="btn btn-ghost" id="close-editor" title="Close editor"><i data-lucide="x"></i></button>
    </div>
  </div>
  <div class="flex items-center gap-3 border-b border-gray-200 px-3 py-1 text-xs dark:border-gray-800">
    <span class="muted truncate" id="epath"></span><span id="estatus" class="muted"></span>
    <span class="ml-auto flex items-center gap-3"><span class="muted" id="pos"></span><select id="lang" class="rounded border border-gray-300 bg-transparent px-1 py-0.5 dark:border-gray-700"></select></span>
  </div>
  <div class="flex min-h-0 flex-1">
    <div id="monaco" class="min-w-0 flex-1"></div>
    <div id="preview" class="hidden w-1/2 shrink-0 flex-col border-l border-gray-200 dark:border-gray-800">
      <div class="flex items-center gap-2 border-b border-gray-200 px-2 py-1 text-xs dark:border-gray-800">
        <button class="btn btn-ghost !px-1.5" id="p-reload" title="Reload"><i data-lucide="refresh-cw"></i></button>
        <span class="muted min-w-0 flex-1 truncate" id="p-url"></span>
        <select id="p-width" class="rounded border border-gray-300 bg-transparent px-1 py-0.5 dark:border-gray-700"><option value="100%">Desktop</option><option value="768px">Tablet</option><option value="375px">Phone</option></select>
        <a class="btn btn-ghost !px-1.5" id="p-open" target="_blank" rel="noopener" title="Open in new tab"><i data-lucide="external-link"></i></a>
      </div>
      <div class="flex-1 overflow-auto bg-gray-100 dark:bg-gray-900"><iframe id="frame" class="mx-auto h-full bg-white" style="width:100%" title="Live preview"></iframe></div>
      <div id="p-note" class="muted border-t border-gray-200 px-3 py-1 text-xs dark:border-gray-800"></div>
    </div>
  </div>
</div>

<script>
(async () => {
  const E = UI.esc, $ = id => document.getElementById(id);
  const SCOPE = <?= json_encode($scope) ?>;
  const MONACO = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.57.0/min';
  const IMG = /^(png|jpe?g|gif|webp|bmp|svg|ico)$/;
  const S = { cwd: <?= json_encode($startDir) ?>, sel: new Set(), anchor: null, entries: [], cache: {}, open: new Set(['']), sort: ['name', 1], filter: '', info: {} };
  const api = (r, o) => UI.api(r + SCOPE, o), post = (r, b, m) => UI.post(r + SCOPE, b, m);
  const q = encodeURIComponent, join = (a, b) => (a ? a + '/' : '') + b;
  const parent = p => p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '';
  const base = p => p.split('/').pop();
  const href = (kind, p) => `api.php?route=/api/fs/${kind}&path=${q(p)}${SCOPE}`;
  const size = n => n == null ? '—' : UI.bytes(n), when = t => t ? new Date(t * 1000).toLocaleString() : '';
  const find = p => S.entries.find(e => e.path === p);
  const picked = () => [...S.sel].map(find).filter(Boolean);

  try { S.info = await api('/api/fs/info'); } catch (e) { $('fm').innerHTML = '<div class="p-6">' + UI.errBox(e.message) + '</div>'; return; }
  $('title').textContent = S.info.hostname || S.info.label;
  const drawExpiry = () => { if (!S.info.hostname) return; $('exp-slot').innerHTML = UI.expiryControl(S.info.hostname, S.info.expires_at, S.info.expired_at);
    UI.bindExpiry($('exp-slot'), async () => { S.info = await api('/api/fs/info'); drawExpiry(); }); };
  drawExpiry(); if (S.info.hostname) { $('route-link').hidden = false; $('route-link').href = '?page=route&host=' + encodeURIComponent(S.info.hostname); }
  const label = S.info.label;

  // ================= explorer =================
  async function load(path) {
    S.cwd = path; S.sel.clear(); S.anchor = null;
    for (let p = path; p; p = parent(p)) S.open.add(p);
    history.replaceState(null, '', location.search.replace(/&path=[^&]*/, '') + (path ? '&path=' + q(path) : ''));
    $('list').innerHTML = UI.loading();
    try { S.entries = S.cache[path] = (await api('/api/fs/list&path=' + q(path))).entries; draw(); drawTree(); }
    catch (e) { $('list').innerHTML = '<div class="p-4">' + UI.errBox(e.message) + '</div>'; }
  }
  const reload = () => { delete S.cache[S.cwd]; return load(S.cwd); };

  function sorted() {
    const [k, d] = S.sort, f = S.filter.toLowerCase();
    return S.entries.filter(e => !f || e.name.toLowerCase().includes(f)).sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
      const v = k === 'size' ? (a.size || 0) - (b.size || 0) : k === 'mtime' ? a.mtime - b.mtime : a.name.toLowerCase().localeCompare(b.name.toLowerCase());
      return v * d;
    });
  }

  const BTN = (id, icon, text, need) => `<button class="btn btn-ghost" data-act="${id}" data-need="${need}" title="${text}">${UI.i(icon)}<span class="max-lg:hidden">${text}</span></button>`;
  function drawToolbar() {
    const n = S.sel.size, one = picked()[0], zip = n === 1 && one && /\.zip$/i.test(one.name);
    $('toolbar').innerHTML = `
      ${BTN('newfile', 'file-plus', 'New file', 'any')}${BTN('newdir', 'folder-plus', 'New folder', 'any')}${BTN('upload', 'upload', 'Upload', 'any')}
      <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
      ${BTN('download', 'download', 'Download', '1+')}${BTN('rename', 'pencil', 'Rename', '1')}${BTN('copy', 'copy', 'Copy', '1+')}${BTN('move', 'move', 'Move', '1+')}
      ${BTN('compress', 'archive', 'Compress', '1+')}${zip ? BTN('extract', 'package-open', 'Extract', '1') : ''}${BTN('perms', 'lock', 'Permissions', '1')}${BTN('delete', 'trash', 'Delete', '1+')}
      <input id="search" class="input ml-auto !w-48 !py-1" placeholder="Search this folder…" value="${E(S.filter)}">
      <button class="btn btn-ghost" data-act="refresh" title="Refresh">${UI.i('refresh-cw')}</button>`;
    $('toolbar').querySelectorAll('[data-act]').forEach(b => {
      const need = b.dataset.need; b.disabled = (need === '1' && n !== 1) || (need === '1+' && n < 1);
      b.onclick = () => act(b.dataset.act, b);
    });
    $('search').oninput = e => { S.filter = e.target.value; draw(true); };
  }

  function draw(keepToolbar) {
    const items = sorted(), all = items.length && items.every(e => S.sel.has(e.path));
    const th = (k, t, cls) => `<th class="cursor-pointer select-none ${cls || ''}" data-sort="${k}">${t}${S.sort[0] === k ? (S.sort[1] > 0 ? ' ▲' : ' ▼') : ''}</th>`;
    const crumbs = S.cwd ? S.cwd.split('/') : [];
    $('crumbs').innerHTML = `<a href="#" data-c="" class="inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline">${UI.i('house', '!size-3.5')}${E(label)}</a>` +
      crumbs.map((c, i) => `<span class="muted">/</span><a href="#" data-c="${E(crumbs.slice(0, i + 1).join('/'))}" class="text-indigo-600 hover:underline">${E(c)}</a>`).join('');
    $('crumbs').querySelectorAll('[data-c]').forEach(a => a.onclick = e => { e.preventDefault(); load(a.dataset.c); });
    $('up').disabled = !S.cwd; $('up').onclick = () => load(parent(S.cwd));
    $('count').textContent = `${S.entries.filter(e => e.type === 'dir').length} folders, ${S.entries.filter(e => e.type === 'file').length} files` + (S.sel.size ? ` · ${S.sel.size} selected` : '');

    $('list').innerHTML = `<table class="tbl"><thead><tr><th class="w-8"><input type="checkbox" id="all" class="size-4 rounded" ${all ? 'checked' : ''}></th>
      ${th('name', 'Name')}${th('size', 'Size')}${th('mtime', 'Modified')}<th>Permissions</th></tr></thead><tbody>
      ${items.map(e => `<tr data-p="${E(e.path)}" class="cursor-default select-none ${S.sel.has(e.path) ? 'bg-indigo-50 dark:bg-indigo-950/50' : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'}">
        <td><input type="checkbox" class="cb size-4 rounded" ${S.sel.has(e.path) ? 'checked' : ''}></td>
        <td><span class="inline-flex items-center gap-2">${UI.i(e.type === 'dir' ? 'folder' : IMG.test(e.ext) ? 'image' : /^(zip|gz|tar|rar|7z)$/.test(e.ext) ? 'file-archive' : 'file-code', '!size-4 ' + (e.type === 'dir' ? 'text-indigo-500' : 'text-gray-400'))}${E(e.name)}${e.link ? ' ' + UI.badge('gray', 'link') : ''}</span></td>
        <td class="muted">${size(e.size)}</td><td class="muted">${when(e.mtime)}</td><td class="muted font-mono text-xs">${E(e.perms || '')}</td></tr>`).join('')
        || `<tr><td colspan="5" class="muted py-12 text-center">${S.filter ? 'Nothing matches your search.' : 'This folder is empty. Drop files here to upload.'}</td></tr>`}</tbody></table>`;

    $('list').querySelectorAll('[data-sort]').forEach(h => h.onclick = () => { S.sort = [h.dataset.sort, S.sort[0] === h.dataset.sort ? -S.sort[1] : 1]; draw(); });
    $('all').onchange = e => { S.sel = new Set(e.target.checked ? items.map(x => x.path) : []); draw(); };
    $('list').querySelectorAll('tbody tr[data-p]').forEach(tr => {
      const p = tr.dataset.p;
      tr.querySelector('.cb').onclick = e => { e.stopPropagation(); S.sel.has(p) ? S.sel.delete(p) : S.sel.add(p); S.anchor = p; draw(); };
      tr.onclick = e => {
        if (e.shiftKey && S.anchor) { const ps = items.map(x => x.path), a = ps.indexOf(S.anchor), b = ps.indexOf(p); S.sel = new Set(ps.slice(Math.min(a, b), Math.max(a, b) + 1)); }
        else if (e.ctrlKey || e.metaKey) { S.sel.has(p) ? S.sel.delete(p) : S.sel.add(p); S.anchor = p; }
        else { S.sel = new Set([p]); S.anchor = p; }
        draw();
      };
      tr.ondblclick = () => openEntry(find(p));
      tr.oncontextmenu = e => { e.preventDefault(); if (!S.sel.has(p)) { S.sel = new Set([p]); S.anchor = p; draw(); } menu(e.clientX, e.clientY); };
    });
    if (!keepToolbar) drawToolbar();
  }

  // ---- tree ----
  async function drawTree() {
    const node = async (path, depth) => {
      const items = (S.cache[path] || (S.cache[path] = (await api('/api/fs/list&path=' + q(path))).entries)).filter(e => e.type === 'dir');
      let h = '';
      for (const e of items) {
        const open = S.open.has(e.path);
        h += `<div class="flex cursor-pointer items-center gap-1 rounded px-1 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800 ${S.cwd === e.path ? 'bg-gray-100 font-medium dark:bg-gray-800' : ''}" style="padding-left:${depth * 12 + 4}px" data-p="${E(e.path)}">
          <span class="chev">${UI.i(open ? 'chevron-down' : 'chevron-right', '!size-3.5')}</span>${UI.i(open ? 'folder-open' : 'folder', '!size-4 text-indigo-500')}<span class="truncate">${E(e.name)}</span></div>`;
        if (open) h += await node(e.path, depth + 1);
      }
      return h;
    };
    try {
      $('tree').innerHTML = `<div class="flex cursor-pointer items-center gap-1 rounded px-1 py-0.5 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 ${S.cwd === '' ? 'bg-gray-100 dark:bg-gray-800' : ''}" data-p="">${UI.i('house', '!size-4')}<span class="truncate">${E(label)}</span></div>` + await node('', 1);
      $('tree').querySelectorAll('[data-p]').forEach(el => el.onclick = e => {
        const p = el.dataset.p;
        if (p && e.target.closest('.chev')) { S.open.has(p) ? S.open.delete(p) : S.open.add(p); drawTree(); return; }
        if (p) S.open.add(p);
        load(p);
      });
    } catch (e) { /* tree is a convenience; the list shows errors */ }
  }

  // ---- context menu ----
  function menu(x, y) {
    const n = S.sel.size, one = picked()[0], zip = n === 1 && /\.zip$/i.test(one.name);
    const item = (a, icon, t, ok = true, danger) => ok ? `<button class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-gray-800 ${danger ? 'text-red-600' : ''}" data-m="${a}">${UI.i(icon, '!size-4')}${t}</button>` : '';
    $('ctx').innerHTML = item('open', one.type === 'dir' ? 'folder-open' : 'file-pen', one.type === 'dir' ? 'Open' : 'Edit', n === 1)
      + item('download', 'download', 'Download') + item('rename', 'pencil', 'Rename', n === 1) + item('copy', 'copy', 'Copy to…') + item('move', 'move', 'Move to…')
      + item('compress', 'archive', 'Compress') + item('extract', 'package-open', 'Extract here', zip) + item('perms', 'lock', 'Permissions', n === 1)
      + '<div class="my-1 border-t border-gray-100 dark:border-gray-800"></div>' + item('delete', 'trash', 'Delete', true, true);
    $('ctx').classList.remove('hidden');
    $('ctx').style.left = Math.min(x, innerWidth - 220) + 'px'; $('ctx').style.top = Math.min(y, innerHeight - $('ctx').offsetHeight - 8) + 'px';
    $('ctx').querySelectorAll('[data-m]').forEach(b => b.onclick = () => { $('ctx').classList.add('hidden'); b.dataset.m === 'open' ? openEntry(one) : act(b.dataset.m); });
  }
  document.addEventListener('click', () => $('ctx').classList.add('hidden'));
  document.addEventListener('scroll', () => $('ctx').classList.add('hidden'), true);

  // ---- actions ----
  const run = async (btn, fn) => { await (btn ? UI.busy(btn, fn) : fn().catch(e => UI.toast(e.message, 'error'))); await reload(); };
  async function act(a, btn) {
    const sel = picked(), paths = sel.map(e => e.path), one = sel[0];
    if (a === 'refresh') return reload();
    if (a === 'newfile') { const n = await UI.ask('New file', 'File name', '', 'Create'); if (n) { await run(btn, () => post('/api/fs/create', { path: join(S.cwd, n) })); openFile(join(S.cwd, n)); } return; }
    if (a === 'newdir')  { const n = await UI.ask('New folder', 'Folder name', '', 'Create'); if (n) run(btn, () => post('/api/fs/mkdir', { path: join(S.cwd, n) })); return; }
    if (a === 'upload')  return $('upload').click();
    if (a === 'download') {
      if (paths.length === 1 && one.type === 'file') { location.href = href('download', paths[0]); return; }
      return UI.busy(btn, async () => {
        const r = await fetch('api.php?route=/api/fs/zip' + SCOPE, { method: 'POST', headers: { 'X-Requested-With': 'tunnel-router', 'Content-Type': 'application/json' }, body: JSON.stringify({ paths }) });
        if (!r.ok) throw new Error((await r.json()).error || 'Download failed');
        const url = URL.createObjectURL(await r.blob()), el = document.createElement('a'); el.href = url; el.download = (paths.length === 1 ? base(paths[0]) : 'archive') + '.zip'; el.click(); URL.revokeObjectURL(url);
      });
    }
    if (a === 'rename') { const to = await UI.ask('Rename', 'New name', one.name, 'Rename'); if (to && to !== one.name) run(btn, async () => { await post('/api/fs/rename', { from: one.path, to: join(parent(one.path), to) }); closeTabsUnder([one.path]); }); return; }
    if (a === 'copy' || a === 'move') {
      const dest = await UI.ask(a === 'copy' ? 'Copy to folder' : 'Move to folder', 'Destination folder (relative to ' + label + '; use / for the top)', S.cwd || '/', a === 'copy' ? 'Copy' : 'Move');
      if (dest === null) return;
      return run(btn, async () => {
        for (const e of sel) {
          let to = join(dest.replace(/^\/+|\/+$/g, ''), e.name);
          if (to === e.path) to = join(dest.replace(/^\/+|\/+$/g, ''), 'copy-of-' + e.name);
          await post('/api/fs/' + (a === 'copy' ? 'copy' : 'rename'), { from: e.path, to });
        }
        if (a === 'move') closeTabsUnder(paths);
        UI.toast((a === 'copy' ? 'Copied ' : 'Moved ') + sel.length + ' item(s)');
      });
    }
    if (a === 'compress') { const n = await UI.ask('Compress', 'Archive name', (sel.length === 1 ? one.name : 'archive') + '.zip', 'Compress'); if (n) run(btn, async () => { const r = await post('/api/fs/compress', { dir: S.cwd, paths, name: n }); UI.toast('Created ' + base(r.path)); }); return; }
    if (a === 'extract') return run(btn, async () => { const r = await post('/api/fs/unzip', { path: one.path }); UI.toast('Extracted to ' + r.path); });
    if (a === 'perms') return permissions(one);
    if (a === 'delete') {
      if (await UI.confirm('Delete ' + (sel.length === 1 ? one.name : sel.length + ' items') + '?', 'Moved to the trash — you can restore it from there.', 'Move to trash'))
        run(btn, async () => { await post('/api/fs/delete', { paths }); closeTabsUnder(paths); UI.toast('Moved to trash'); });
    }
  }

  function permissions(e) {
    const mode = parseInt(e.perms || '644', 8), who = ['Owner', 'Group', 'Others'];
    const d = UI.modal(`<h3 class="text-lg font-semibold">Permissions</h3><p class="muted mt-1 truncate text-sm">${E(e.path)}</p>
      <table class="mt-4 w-full text-sm"><thead><tr><th></th><th>Read</th><th>Write</th><th>Execute</th></tr></thead><tbody>
      ${who.map((w, i) => `<tr><td class="py-1">${w}</td>${[4, 2, 1].map(b => `<td class="text-center"><input type="checkbox" class="size-4 rounded" data-i="${i}" data-b="${b}" ${(mode >> (6 - i * 3)) & b ? 'checked' : ''}></td>`).join('')}</tr>`).join('')}</tbody></table>
      <div class="mt-3 flex items-center gap-2"><label class="label !mb-0" for="oct">Octal</label><input id="oct" class="input !w-24 font-mono" maxlength="3" value="${(mode & 0o777).toString(8).padStart(3, '0')}"></div>
      <p class="hint">Typical: files 644, folders 755. 777 lets everyone write — avoid it.</p>
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="pm-cancel">Cancel</button><button class="btn btn-primary" id="pm-ok">Apply</button></div>`);
    const boxes = [...d.querySelectorAll('[data-i]')], oct = d.querySelector('#oct');
    const fromBoxes = () => oct.value = [0, 1, 2].map(i => boxes.filter(b => +b.dataset.i === i && b.checked).reduce((s, b) => s + +b.dataset.b, 0)).join('');
    boxes.forEach(b => b.onchange = fromBoxes);
    oct.oninput = () => { if (/^[0-7]{3}$/.test(oct.value)) boxes.forEach(b => b.checked = !!(+oct.value[+b.dataset.i] & +b.dataset.b)); };
    d.querySelector('#pm-cancel').onclick = () => d.close();
    d.querySelector('#pm-ok').onclick = ev => UI.busy(ev.currentTarget, async () => { await post('/api/fs/chmod', { path: e.path, mode: oct.value }); d.close(); UI.toast('Permissions set to ' + oct.value); reload(); });
  }

  // ---- upload (button + drag & drop) ----
  async function upload(files) {
    const fd = new FormData(); fd.append('dir', S.cwd); [...files].forEach(f => fd.append('files[]', f));
    UI.toast('Uploading ' + files.length + ' file(s)…', 'info');
    try {
      const r = await api('/api/fs/upload', { method: 'POST', body: fd });
      r.results.some(x => !x.ok) ? await UI.results('Upload', r.results) : UI.toast(r.results.length + ' file(s) uploaded');
      reload();
    } catch (e) { UI.toast(e.message, 'error'); }
  }
  $('upload').onchange = e => { if (e.target.files.length) upload(e.target.files); e.target.value = ''; };
  let depth = 0;
  const wrap = $('listwrap');
  wrap.addEventListener('dragenter', e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); depth++; $('drop').classList.replace('hidden', 'grid'); } });
  wrap.addEventListener('dragover', e => e.preventDefault());
  wrap.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; $('drop').classList.replace('grid', 'hidden'); } });
  wrap.addEventListener('drop', e => { e.preventDefault(); depth = 0; $('drop').classList.replace('grid', 'hidden'); if (e.dataTransfer.files.length) upload(e.dataTransfer.files); });

  // ---- keyboard ----
  document.addEventListener('keydown', e => {
    if ($('editor').classList.contains('flex') || e.target.closest('input, textarea, dialog, .monaco-editor')) return;
    if (e.key === 'Delete' && S.sel.size) act('delete');
    else if (e.key === 'F2' && S.sel.size === 1) act('rename');
    else if (e.key === 'Enter' && S.sel.size === 1) openEntry(picked()[0]);
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); S.sel = new Set(sorted().map(x => x.path)); draw(); }
  });

  // ---- trash ----
  $('trash-btn').onclick = async () => {
    const items = (await api('/api/fs/trash')).items;
    const d = UI.modal(`<h3 class="text-lg font-semibold">Trash — ${E(label)}</h3>
      <ul class="mt-3 max-h-72 space-y-1 overflow-y-auto text-sm">${items.map(x => `<li class="flex items-center justify-between gap-2 rounded px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-800">
        <span class="min-w-0"><span class="block truncate font-medium">${E(x.from)}</span><span class="muted text-xs">${E(when(x.ts))}${x.size != null ? ' · ' + size(x.size) : ' · folder'}</span></span>
        <button class="btn btn-outline" data-id="${E(x.id)}">Restore</button></li>`).join('') || '<li class="muted">Trash is empty.</li>'}</ul>
      <div class="mt-5 flex justify-between"><button class="btn btn-ghost-danger" id="t-empty" ${items.length ? '' : 'disabled'}>Empty trash</button><button class="btn btn-outline" id="t-close">Close</button></div>`);
    d.querySelector('#t-close').onclick = () => d.close();
    d.querySelectorAll('[data-id]').forEach(b => b.onclick = () => UI.busy(b, async () => { const r = await post('/api/fs/restore', { id: b.dataset.id }); d.close(); UI.toast('Restored ' + r.path); reload(); }));
    d.querySelector('#t-empty').onclick = async e => { if (await UI.confirm('Empty the trash?', 'Permanently deletes everything in the trash (all sites).', 'Delete forever')) UI.busy(e.currentTarget, async () => { await post('/api/fs/trash-empty'); d.close(); UI.toast('Trash emptied'); }); };
  };

  // ================= opening things =================
  async function openEntry(e) {
    if (!e) return;
    if (e.type === 'dir') return load(e.path);
    if (IMG.test(e.ext)) { const d = UI.modal(`<h3 class="mb-3 truncate font-semibold">${E(e.name)}</h3><img class="mx-auto max-h-[70vh] rounded border border-gray-200 dark:border-gray-700" src="${href('raw', e.path)}" alt="${E(e.name)}"><div class="mt-4 flex justify-end gap-2"><a class="btn btn-outline" href="${href('download', e.path)}">${UI.i('download')}Download</a><button class="btn btn-primary" id="im-x">Close</button></div>`); d.querySelector('#im-x').onclick = () => d.close(); d.style.maxWidth = '48rem'; return; }
    openFile(e.path);
  }

  // ================= editor =================
  const ED = { tabs: [], active: null, monaco: null, editor: null, previewOn: false, previewUrl: null, autosave: null, wrap: false };
  $('autosave-w').innerHTML = UI.toggle('autosave', false);
  const tab = () => ED.tabs.find(t => t.path === ED.active);

  function loadMonaco() {
    if (ED.monaco) return Promise.resolve();
    return new Promise((res, rej) => {
      window.MonacoEnvironment = { getWorkerUrl: () => URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${MONACO}/'};importScripts('${MONACO}/vs/base/worker/workerMain.js');`], { type: 'text/javascript' })) };
      const s = document.createElement('script'); s.src = MONACO + '/vs/loader.js'; s.integrity = 'sha384-jwxunh5pHKFzi60XobDcwOM7qnk4lSGpdBU/hNn9pUGp+ZUpaHJqadNLRF2k+jPu'; s.crossOrigin = 'anonymous'; s.onerror = () => rej(new Error('Could not load the editor from the CDN (offline?)'));
      s.onload = () => { require.config({ paths: { vs: MONACO + '/vs' } }); require(['vs/editor/editor.main'], () => {
        const m = ED.monaco = window.monaco;
        ED.editor = m.editor.create($('monaco'), { automaticLayout: true, theme: UI.isDark() ? 'vs-dark' : 'vs', minimap: { enabled: true }, fontSize: 13, scrollBeyondLastLine: false, tabSize: 4, wordWrap: 'off' });
        ED.editor.addCommand(m.KeyMod.CtrlCmd | m.KeyCode.KeyS, save);
        ED.editor.onDidChangeCursorPosition(ev => $('pos').textContent = `Ln ${ev.position.lineNumber}, Col ${ev.position.column}`);
        $('lang').innerHTML = m.languages.getLanguages().map(l => `<option value="${l.id}">${E(l.aliases ? l.aliases[0] : l.id)}</option>`).join('');
        res();
      }, rej); };
      document.head.appendChild(s);
    });
  }
  const langFor = name => {
    const ext = '.' + name.split('.').pop().toLowerCase(), lower = name.toLowerCase();
    if (lower === '.htaccess' || lower === 'httpd.conf') return 'ini';
    if (lower === '.env' || lower.endsWith('.env')) return 'ini';
    const l = ED.monaco.languages.getLanguages().find(l => (l.extensions || []).includes(ext) || (l.filenames || []).includes(name));
    return l ? l.id : 'plaintext';
  };

  async function openFile(path) {
    try {
      const f = await api('/api/fs/read&path=' + q(path));
      if (!f.editable) {
        const d = UI.modal(`<h3 class="font-semibold">${E(base(path))}</h3><p class="muted mt-2 text-sm">Can't be edited here: ${E(f.reason)}.</p><div class="mt-5 flex justify-end gap-2"><a class="btn btn-outline" href="${href('download', path)}">${UI.i('download')}Download</a><button class="btn btn-primary" id="x">Close</button></div>`);
        d.querySelector('#x').onclick = () => d.close(); return;
      }
      await loadMonaco();
      showEditor(true);
      let t = ED.tabs.find(t => t.path === path);
      if (!t) {
        const model = ED.monaco.editor.createModel(f.content, langFor(base(path)));
        t = { path, model, dirty: false, mtime: f.mtime };
        model.onDidChangeContent(() => { t.dirty = true; drawTabs(); setStatus(); if ($('autosave').checked) { clearTimeout(ED.autosave); ED.autosave = setTimeout(() => save(true), 1500); } });
        ED.tabs.push(t);
      }
      activate(path);
    } catch (e) { UI.toast(e.message, 'error'); }
  }

  function showEditor(on) {
    $('editor').classList.toggle('hidden', !on); $('editor').classList.toggle('flex', on);
    document.body.style.overflow = on ? 'hidden' : '';
    if (on && ED.editor) setTimeout(() => ED.editor.layout(), 0);
  }
  function activate(path) {
    ED.active = path; const t = tab();
    ED.editor.setModel(t.model); ED.editor.focus();
    $('epath').textContent = (S.info.hostname || label) + ' / ' + t.path; $('lang').value = t.model.getLanguageId();
    drawTabs(); setStatus(); updatePreviewFor(t.path);
  }
  function setStatus() { const t = tab(); $('estatus').textContent = t ? (t.dirty ? '● unsaved changes' : '✓ saved') : ''; $('estatus').className = t && t.dirty ? 'text-yellow-600' : 'muted'; }
  function drawTabs() {
    $('etabs').innerHTML = ED.tabs.map(t => `<div class="group flex shrink-0 cursor-pointer items-center gap-1.5 rounded-t-md border border-b-0 px-3 py-1.5 text-sm ${t.path === ED.active ? 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-950' : 'border-transparent text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800'}" data-t="${E(t.path)}">
      ${UI.i('file-code', '!size-3.5')}<span class="max-w-40 truncate" title="${E(t.path)}">${E(base(t.path))}</span>${t.dirty ? '<span class="size-2 rounded-full bg-indigo-500"></span>' : ''}
      <button class="rounded p-0.5 hover:bg-gray-200 dark:hover:bg-gray-700" data-x="${E(t.path)}" aria-label="Close">${UI.i('x', '!size-3')}</button></div>`).join('');
    $('etabs').querySelectorAll('[data-t]').forEach(el => el.onclick = e => { if (!e.target.closest('[data-x]')) activate(el.dataset.t); });
    $('etabs').querySelectorAll('[data-x]').forEach(el => el.onclick = () => closeTab(el.dataset.x));
  }
  async function closeTab(path, force) {
    const t = ED.tabs.find(t => t.path === path); if (!t) return;
    if (t.dirty && !force && !await UI.confirm('Discard unsaved changes?', base(path) + ' has changes that are not saved.', 'Discard')) return;
    t.model.dispose(); ED.tabs = ED.tabs.filter(x => x !== t);
    if (!ED.tabs.length) { ED.active = null; showEditor(false); reload(); } else if (ED.active === path) activate(ED.tabs[ED.tabs.length - 1].path); else drawTabs();
  }
  const closeTabsUnder = ps => ED.tabs.filter(t => ps.some(p => t.path === p || t.path.startsWith(p + '/'))).forEach(t => closeTab(t.path, true));
  $('close-editor').onclick = async () => {
    if (ED.tabs.some(t => t.dirty) && !await UI.confirm('Close the editor?', 'Some files have unsaved changes that will be lost.', 'Discard and close')) return;
    ED.tabs.forEach(t => t.model.dispose()); ED.tabs = []; ED.active = null; showEditor(false); reload();
  };
  window.addEventListener('beforeunload', e => { if (ED.tabs.some(t => t.dirty)) { e.preventDefault(); e.returnValue = ''; } });

  async function save(quiet) {
    const t = tab(); if (!t) return;
    const body = { path: t.path, content: t.model.getValue(), mtime: t.mtime };
    try {
      let r;
      try { r = await post('/api/fs/write', body); }
      catch (e) {
        if (!(e.data && e.data.mtime)) throw e;
        if (!await UI.confirm('File changed on disk', base(t.path) + ' was modified outside this editor. Overwrite it with your version? (The other version stays in Previous versions.)', 'Overwrite')) return;
        r = await post('/api/fs/write', { ...body, mtime: undefined });
      }
      t.mtime = r.mtime; t.dirty = false; drawTabs(); setStatus(); reloadPreview();
      if (quiet !== true) UI.toast('Saved ' + base(t.path));
    } catch (e) { UI.toast(e.message, 'error'); }
  }
  $('save').onclick = () => save();
  $('wrap').onclick = () => { ED.wrap = !ED.wrap; ED.editor.updateOptions({ wordWrap: ED.wrap ? 'on' : 'off' }); };
  $('lang').onchange = () => { const t = tab(); if (t) ED.monaco.editor.setModelLanguage(t.model, $('lang').value); };

  $('versions').onclick = async () => {
    const t = tab(); if (!t) return;
    const v = (await api('/api/fs/versions&path=' + q(t.path))).versions;
    const d = UI.modal(`<h3 class="text-lg font-semibold">Previous versions</h3><p class="muted mt-1 text-sm">Loading one puts it in the editor; nothing is saved until you press Save.</p>
      <ul class="mt-3 max-h-64 space-y-1 overflow-y-auto text-sm">${v.map(x => `<li class="flex items-center justify-between gap-2 rounded px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-800"><span>${E(when(x.ts))} <span class="muted text-xs">${size(x.size)}</span></span><button class="btn btn-outline" data-v="${E(x.id)}">Load</button></li>`).join('') || '<li class="muted">No earlier versions yet.</li>'}</ul>
      <div class="mt-5 flex justify-end"><button class="btn btn-outline" id="v-x">Close</button></div>`);
    d.querySelector('#v-x').onclick = () => d.close();
    d.querySelectorAll('[data-v]').forEach(b => b.onclick = async () => { t.model.setValue((await api('/api/fs/version&path=' + q(t.path) + '&id=' + b.dataset.v)).content); d.close(); });
  };

  // ---- live preview ----
  function previewBase() {
    if (S.info.hostname && S.info.enabled !== false) return 'https://' + S.info.hostname;
    return S.info.project ? location.origin + '/' + S.info.project.split('/').map(encodeURIComponent).join('/') : location.origin;   // local fallback: the default Apache vhost
  }
  function urlFor(path) {
    if (!/\.(php|html?|htm)$/i.test(path)) return ED.previewUrl || previewBase() + '/';
    return previewBase() + '/' + path.replace(/(^|\/)index\.(php|html?|htm)$/i, '$1').split('/').map(encodeURIComponent).join('/');
  }
  function updatePreviewFor(path) {
    const u = urlFor(path); if (u === ED.previewUrl && !ED.previewDirty) return;
    ED.previewUrl = u; $('p-url').textContent = u; $('p-open').href = u;
    $('p-note').textContent = S.info.hostname && S.info.enabled === false ? 'Route is disabled — showing the local copy instead.' : S.info.hostname ? 'Public site via your tunnel. If the preview stays blank the site may forbid embedding (X-Frame-Options).' : 'Local copy from Apache. Attach a route to preview the public site.';
    if (ED.previewOn) reloadPreview(true);
  }
  function reloadPreview(force) {
    if (!ED.previewOn || !ED.previewUrl) return;
    $('frame').src = ED.previewUrl + (ED.previewUrl.includes('?') ? '&' : '?') + '_tr=' + Date.now();
  }
  $('preview-toggle').onclick = () => {
    ED.previewOn = !ED.previewOn; $('preview').classList.toggle('hidden', !ED.previewOn); $('preview').classList.toggle('flex', ED.previewOn);
    $('preview-toggle').classList.toggle('btn-primary', ED.previewOn); $('preview-toggle').classList.toggle('btn-outline', !ED.previewOn);
    if (ED.previewOn) { if (tab()) updatePreviewFor(tab().path); reloadPreview(true); }
    setTimeout(() => ED.editor && ED.editor.layout(), 0);
  };
  $('p-reload').onclick = () => reloadPreview(true);
  $('p-width').onchange = () => $('frame').style.width = $('p-width').value;

  load(S.cwd);
})();
</script>
