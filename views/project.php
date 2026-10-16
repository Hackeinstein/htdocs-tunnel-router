<?php $name = trim($_GET['name'] ?? ''); ?>
<?= pageHeader('Project', '', '', ['Dashboard', '?page=dashboard'], 'title') ?>
<div id="root"><script>document.write(UI.loading('Loading project…'))</script></div>
<script>
const NAME = <?= json_encode($name) ?>, E = UI.esc;
(async () => {
  const root = document.getElementById('root');
  let d; try { d = await UI.api('/api/project&name=' + encodeURIComponent(NAME)); } catch (e) { root.innerHTML = UI.errBox(e.message) + '<p class="mt-3"><a class="text-indigo-600 underline" href="?page=dashboard">Back to the dashboard</a></p>'; return; }
  const p = d.project, routes = d.routes, routed = routes.length > 0;
  document.getElementById('title').textContent = p.name;
  const q = encodeURIComponent(p.name);

  root.innerHTML = `
    <div class="mb-6 flex flex-wrap items-center gap-2">
      ${routed ? UI.badge('green', routes.length + ' route' + (routes.length > 1 ? 's' : '')) : UI.badge('gray', 'Not routed — private to this computer')}
      ${p.entry ? UI.badge('blue', E(p.entry)) : UI.badge('yellow', 'no index file')}${p.has_htaccess ? UI.badge('gray', '.htaccess') : ''}
      <span class="ml-auto flex flex-wrap gap-2">
        <a class="btn btn-outline" href="${E(location.origin)}/${q}/" target="_blank" rel="noopener" title="Open the local copy">${UI.i('external-link')}Open locally</a>
        <a class="btn btn-outline" href="?page=files&project=${q}">${UI.i('folder-code')}Files</a>
        ${routed ? '' : `<button class="btn btn-outline" id="share">${UI.i('share-2')}Share temporarily</button>`}
        <a class="btn btn-primary" href="?page=attach&project=${q}">${UI.i('plug-zap')}${routed ? 'Attach another hostname' : 'Attach route'}</a></span></div>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      ${UI.stat('file', 'blue', UI.num(p.files) + (p.truncated ? '+' : ''), 'Files')}
      ${UI.stat('folder', 'gray', UI.num(p.folders) + (p.truncated ? '+' : ''), 'Folders')}
      ${UI.stat('hard-drive', 'purple', UI.bytes(p.bytes) + (p.truncated ? '+' : ''), 'Size')}
      ${UI.stat('clock', 'green', p.newest ? UI.ago(p.newest) + ' ago' : '—', 'Last change')}</div>
    ${p.truncated ? UI.warn('This folder is large, so the totals above are a lower bound.') : ''}

    <div class="mb-6 card !p-0"><h2 class="card-h px-5 pt-5">Routes</h2>
      ${routed ? `<div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Hostname</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody>
        ${routes.map(r => `<tr><td><a class="font-medium text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${E(r.hostname)}</a>${r.label && r.label !== p.name ? `<div class="muted text-xs">${E(r.label)}</div>` : ''}</td>
          <td>${r.enabled === false ? UI.badge('yellow', 'Disabled') : UI.badge('green', 'Live')}</td>
          <td>${UI.expiryControl(r.hostname, r.expires_at, r.expired_at)}</td>
          <td class="text-right"><a class="btn btn-outline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${UI.i('gauge')}Open page</a></td></tr>`).join('')}</tbody></table></div>`
        : `<div class="px-5 pb-5 text-sm"><p class="muted">Nobody outside this computer can reach this project yet. Attach it to a hostname to publish it through your tunnel, or create a temporary share link that switches itself off.</p></div>`}
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <div class="card"><h2 class="card-h">Folder</h2>
        <dl class="grid grid-cols-[6rem_1fr] gap-y-2 text-sm"><dt class="muted">Path</dt><dd class="break-all"><code class="text-xs">${E(p.path)}</code></dd>
          <dt class="muted">Entry file</dt><dd>${p.entry ? E(p.entry) : '<span class="text-yellow-600">none — visitors would see a directory listing or an error</span>'}</dd>
          <dt class="muted">.htaccess</dt><dd>${p.has_htaccess ? 'present' : 'none'}</dd></dl></div>
      <div class="card"><h2 class="card-h">Top level</h2>
        <ul class="grid grid-cols-2 gap-1 text-sm">${p.top.map(t => `<li class="flex items-center gap-2 truncate">${UI.i(t.dir ? 'folder' : 'file', '!size-4 shrink-0 ' + (t.dir ? 'text-indigo-500' : 'text-gray-400'))}<span class="truncate">${E(t.name)}</span></li>`).join('') || '<li class="muted">Empty folder</li>'}</ul>
        <a class="mt-3 inline-block text-sm text-indigo-600 hover:underline" href="?page=files&project=${q}">Open the file manager →</a></div>
    </div>`;

  UI.bindExpiry(root, () => location.reload());
  const sh = document.getElementById('share'); if (sh) sh.onclick = () => UI.share(p.name);
})();
</script>
