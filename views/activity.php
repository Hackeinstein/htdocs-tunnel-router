<?= pageHeader('Activity', 'Recent actions performed by Tunnel Router') ?>
<div id="root"><script>document.write(UI.loading('Loading activity…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root');
  const META = { attach: ['blue', 'plug-zap', 'Attached'], detach: ['red', 'unlink', 'Detached'], toggle: ['yellow', 'toggle-right', 'Toggled'],
                 reconcile_fix: ['purple', 'wrench', 'Reconcile fix'], zone_setting: ['blue', 'settings', 'Zone setting'], purge_cache: ['orange', 'eraser', 'Cache purged'],
                 tunnel_rename: ['blue', 'pencil', 'Tunnel renamed'], tunnel_cleanup: ['gray', 'brush-cleaning', 'Tunnel cleanup'], tunnel_activate: ['green', 'network', 'Tunnel activated'],
                 expiry: ['yellow', 'timer', 'Expiry changed'], expire: ['red', 'timer-off', 'Expired'], under_attack: ['red', 'shield-alert', 'Under Attack'],
                 dns_create: ['green', 'plus', 'DNS added'], dns_update: ['blue', 'pencil', 'DNS edited'], dns_delete: ['red', 'trash', 'DNS deleted'], dns_import: ['purple', 'upload', 'DNS import'], restore: ['purple', 'archive-restore', 'Restore'],
                 file_save: ['blue', 'save', 'File saved'], file_create: ['green', 'file-plus', 'Created'], file_move: ['yellow', 'move', 'Moved'], file_copy: ['blue', 'copy', 'Copied'],
                 file_delete: ['red', 'trash', 'Deleted'], file_restore: ['green', 'archive-restore', 'Restored'], file_upload: ['purple', 'upload', 'Uploaded'], file_unzip: ['purple', 'package-open', 'Extracted'],
                 file_trash_empty: ['red', 'trash', 'Trash emptied'] };
  const ago = ts => { const s = (Date.now() - new Date(ts)) / 1000;
    return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : s < 604800 ? Math.floor(s / 86400) + 'd ago' : new Date(ts).toLocaleDateString(); };
  const item = e => { const [c, icon, label] = META[e.action] || ['gray', 'activity', e.action];
    return `<li class="relative flex gap-4 pb-6 last:pb-0">
      <span class="relative z-10 grid size-9 shrink-0 place-content-center rounded-full badge-${c}">${UI.i(icon, '!size-4')}</span>
      <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><b>${UI.esc(label)}</b>
        ${UI.badge(e.ok ? 'green' : 'red', e.ok ? 'ok' : 'failed')}<span class="muted text-xs" title="${UI.esc(new Date(e.ts).toLocaleString())}">${ago(e.ts)}</span></div>
        <code class="text-sm break-all">${UI.esc(e.hostname)}</code>
        ${e.detail ? `<div class="muted mt-0.5 text-xs break-words">${UI.esc(e.detail)}</div>` : ''}</div></li>`; };

  try {
    let offset = 0; const PAGE = 100;
    const first = (await UI.api('/api/activity&limit=' + PAGE)).entries || [];
    if (!first.length) { root.innerHTML = UI.empty('scroll-text', 'No activity yet', 'Actions will appear here once you start attaching routes.'); return; }
    root.innerHTML = `<div class="card"><ul id="list">${first.map(item).join('')}</ul>
      ${first.length >= PAGE ? '<div class="mt-4 text-center"><button class="btn btn-outline" id="more">Load more</button></div>' : ''}</div>`;
    offset = first.length;
    const more = document.getElementById('more');
    if (more) more.onclick = () => UI.busy(more, async () => {
      const next = (await UI.api(`/api/activity&limit=${PAGE}&offset=${offset}`)).entries || [];
      document.getElementById('list').insertAdjacentHTML('beforeend', next.map(item).join(''));
      offset += next.length;
      if (next.length < PAGE) more.remove();
    });
  } catch (e) { root.innerHTML = UI.errBox(e.message); }
})();
</script>
