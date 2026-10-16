<?= pageHeader('Domains', 'Zones on your Cloudflare account · traffic is the last 7 days',
  '<button class="btn btn-outline" onclick="location.reload()"><i data-lucide="refresh-cw"></i>Refresh</button>') ?>
<div id="root"><script>document.write(UI.loading('Loading domains…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root');
  try {
    const d = await UI.api('/api/domains'), z = d.zones;
    if (!z.length) { root.innerHTML = UI.empty('globe', 'No zones found', 'This token cannot see any zones.'); return; }
    const t = z.reduce((a, x) => { if (x.stats) { a.r += x.stats.requests; a.b += x.stats.bytes; a.t += x.stats.threats; } return a; }, { r: 0, b: 0, t: 0 });
    const withTraffic = z.filter(x => x.stats && x.stats.requests).sort((a, b) => b.stats.requests - a.stats.requests).slice(0, 8);

    root.innerHTML =
      (d.stats_error ? UI.warn('Traffic unavailable: ' + d.stats_error + ' (token needs Analytics:Read)') : '') +
      `<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        ${UI.stat('globe', 'blue', z.length, 'Domains')}
        ${UI.stat('arrow-left-right', 'green', UI.num(t.r), 'Requests', '7d')}
        ${UI.stat('cloud-download', 'purple', UI.bytes(t.b), 'Bandwidth', '7d')}
        ${UI.stat('shield-alert', 'red', UI.num(t.t), 'Threats blocked')}
      </div>
      ${withTraffic.length ? `<div class="card mb-6"><h2 class="card-h">Requests by domain (7d)</h2><div id="byDomain"></div></div>` : ''}
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">${z.map(x => {
        const s = x.stats;
        return `<a href="?page=domain&zone=${x.id}" class="card block transition hover:border-indigo-400 hover:shadow-sm">
          <div class="mb-1 flex items-center gap-2">${UI.i('globe', '!size-5 text-indigo-600')}
            <span class="truncate font-semibold">${UI.esc(x.name)}</span>
            <span class="ml-auto flex gap-1">${d.attack[x.id] ? UI.badge('red', 'Under attack', 'shield-alert') : ''}${UI.badge(x.status === 'active' ? 'green' : 'yellow', UI.esc(x.status))}</span></div>
          <div class="muted mb-4 text-xs">${UI.esc(x.plan)} · ${x.routes} tunnel route${x.routes === 1 ? '' : 's'}</div>
          ${s ? `<div class="grid grid-cols-3 text-center">
            <div><div class="font-semibold">${UI.num(s.requests)}</div><div class="muted text-xs">Requests</div></div>
            <div><div class="font-semibold">${UI.bytes(s.bytes)}</div><div class="muted text-xs">Bandwidth</div></div>
            <div><div class="font-semibold">${UI.pct(s.cached, s.requests)}</div><div class="muted text-xs">Cached</div></div></div>`
          : '<div class="muted text-center text-xs">No traffic data</div>'}</a>`;
      }).join('')}</div>`;

    if (withTraffic.length) UI.hbar(document.getElementById('byDomain'), withTraffic.map(x => x.name), withTraffic.map(x => x.stats.requests), 'Requests');
  } catch (e) { root.innerHTML = UI.errBox(e.message); }
})();
</script>
