<?php $host = trim($_GET['hostname'] ?? ''); ?>
<?= pageHeader('Detach Route', 'Remove the DNS record and vhost block for a hostname', '', ['Dashboard', '?page=dashboard']) ?>
<div id="root"><script>document.write(UI.loading('Loading routes…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root'), E = UI.esc, PRE = <?= json_encode($host) ?>;
  try {
    let routes = (await UI.api('/api/routes')).routes;
    if (PRE) routes = routes.filter(r => r.hostname === PRE);
    if (!routes.length) { root.innerHTML = UI.empty('circle-check', 'No routes to detach', PRE ? 'Route not found.' : 'No active routes exist.', '<a href="?page=dashboard" class="btn btn-primary">Back to Dashboard</a>'); return; }

    root.innerHTML = `<div class="space-y-3">${routes.map(r => `<div class="card flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><a class="text-lg font-bold text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${E(r.hostname)}</a>
        ${UI.badge(r.type === 'apex' ? 'purple' : 'blue', r.type === 'apex' ? 'Apex' : 'Subdomain')}${UI.badge(r.enabled ? 'green' : 'yellow', r.enabled ? 'Enabled' : 'Disabled')}</div>
        <div class="muted mt-1 text-sm">${UI.i('folder', '!size-3.5 mr-1')}${E(r.project)} — <code class="text-xs">${E(r.document_root)}</code></div>
        <div class="mt-2">${UI.expiryControl(r.hostname, r.expires_at, r.expired_at)}</div></div>
      <button class="btn btn-danger" data-h="${E(r.hostname)}">${UI.i('trash')}Detach</button></div>`).join('')}</div>`;

    UI.bindExpiry(root, () => location.reload());
    root.querySelectorAll('[data-h]').forEach(b => b.onclick = async () => {
      const h = b.dataset.h;
      if (!await UI.confirm('Detach ' + h + '?', 'This permanently deletes the Cloudflare DNS record and removes the Apache vhost block. The project folder is not deleted.', 'Yes, detach')) return;
      UI.busy(b, async () => {
        await UI.api('/api/routes/' + encodeURIComponent(h) + '&confirm=1', { method: 'DELETE' });
        root.innerHTML = UI.empty('circle-check', 'Route detached', E(h) + ' removed. Apache reloaded.', '<a href="?page=dashboard" class="btn btn-primary">Back to Dashboard</a>');
      });
    });
  } catch (e) { root.innerHTML = UI.errBox('Failed to load routes: ' + e.message); }
})();
</script>
