<?= pageHeader('Tunnels', 'Cloudflare Tunnels on your account',
  '<button class="btn btn-outline" onclick="location.reload()"><i data-lucide="refresh-cw"></i>Refresh</button>') ?>
<div id="root"><script>document.write(UI.loading('Loading tunnels…'))</script></div>
<script>
const TUNNEL_COLOR = { healthy: 'green', degraded: 'yellow', down: 'red', inactive: 'gray' };
(async () => {
  const root = document.getElementById('root');
  try {
    const d = await UI.api('/api/tunnels');
    root.innerHTML = d.tunnels.length ? `<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">${d.tunnels.map(t => `
      <a href="?page=tunnel&id=${t.id}" class="card block transition hover:border-indigo-400 hover:shadow-sm ${t.active ? '!border-green-500' : ''}">
        <div class="mb-1 flex items-center gap-2">${UI.i('network', '!size-5 text-indigo-600')}
          <span class="truncate font-semibold">${UI.esc(t.name)}</span>
          <span class="ml-auto">${UI.badge(TUNNEL_COLOR[t.status] || 'gray', UI.esc(t.status || 'unknown'))}</span></div>
        <div class="muted mb-4 text-xs">${t.remote ? 'Remotely managed' : 'Locally managed'} · created ${UI.ago(t.created_at)} ago</div>
        <div class="grid grid-cols-3 text-center">
          <div><div class="font-semibold">${t.connections}</div><div class="muted text-xs">Connections</div></div>
          <div><div class="font-semibold">${t.active ? t.routes : '—'}</div><div class="muted text-xs">Routes</div></div>
          <div><div class="font-semibold ${t.active ? 'text-green-600' : ''}">${t.active ? 'Yes' : 'No'}</div><div class="muted text-xs">Used by router</div></div></div>
      </a>`).join('')}</div>` : UI.empty('network', 'No tunnels found', 'Create one with cloudflared or the Zero Trust dashboard.');
  } catch (e) { root.innerHTML = UI.errBox(e.message + ' — Account ID and a token with Cloudflare Tunnel:Read are required.'); }
})();
</script>
