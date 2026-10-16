<?= pageHeader('Health', 'Uptime and latency for your routes, plus alerts. Checks run about every 2 minutes while any Tunnel Router page is open — there is no background monitor, so there is no data for times the tool was closed.',
  '<button class="btn btn-outline" id="read"><i data-lucide="check-check"></i>Mark alerts read</button>
   <button class="btn btn-primary" id="check"><i data-lucide="activity"></i>Check now</button>') ?>
<div id="root"><script>document.write(UI.loading('Loading health data…'))</script></div>
<script>
(async () => {
  const root = document.getElementById('root'), E = UI.esc;
  let charts = [];
  const SEV = { error: ['red', 'circle-alert'], warn: ['yellow', 'triangle-alert'], info: ['blue', 'info'] };
  const pct = v => v == null ? '<span class="muted">—</span>' : `<span class="${v >= 99 ? 'text-green-600' : v >= 95 ? 'text-yellow-600' : 'text-red-600'} font-medium">${v}%</span>`;

  function draw(d) {
    charts.forEach(c => c.destroy()); charts = [];
    const routes = d.routes.filter(r => r.enabled), withData = routes.filter(r => r.samples);
    const avgUp = withData.filter(r => r.uptime24 != null);
    const down = routes.filter(r => r.last && !r.last[1]).length;
    root.innerHTML = `<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        ${UI.stat('route', 'blue', routes.length, 'Monitored routes')}
        ${UI.stat('heart-pulse', down ? 'red' : 'green', down ? down + ' down' : 'All up', 'Right now')}
        ${UI.stat('gauge', 'green', avgUp.length ? (avgUp.reduce((a, r) => a + r.uptime24, 0) / avgUp.length).toFixed(1) + '%' : '—', 'Average uptime', '24h')}
        ${UI.stat('bell', d.unread ? 'red' : 'gray', d.unread, 'Unread alerts')}</div>
      <div class="card mb-6"><h2 class="card-h">Latency, last 24h</h2><div id="lat"></div></div>
      <div class="card mb-6 !p-0"><h2 class="card-h px-5 pt-5">Routes</h2><div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Hostname</th><th>Now</th><th>Uptime 24h</th><th>Uptime 7d</th><th>Avg latency</th><th>Checks</th><th>Trend</th></tr></thead><tbody>
        ${d.routes.map((r, i) => `<tr><td><a class="text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">${E(r.hostname)}</a></td>
          <td>${!r.enabled ? UI.badge('yellow', 'Disabled') : r.last ? UI.badge(r.last[1] ? 'green' : 'red', r.last[1] ? 'Up' : 'Down') : UI.badge('gray', 'no data')}</td>
          <td>${pct(r.uptime24)}</td><td>${pct(r.uptime7)}</td><td>${r.avg_ms != null ? r.avg_ms + ' ms' : '—'}</td>
          <td class="muted">${r.samples}${r.since ? ' · since ' + new Date(r.since * 1000).toLocaleDateString() : ''}</td><td><div id="sp${i}" class="w-32"></div></td></tr>`).join('')
          || '<tr><td colspan="7" class="muted py-8 text-center">No routes yet.</td></tr>'}</tbody></table></div></div>
      <div class="card"><h2 class="card-h">Alerts</h2><ul class="space-y-3">${d.alerts.map(a => { const [c, ic] = SEV[a.severity] || SEV.info;
        return `<li class="flex gap-3 ${a.read ? 'opacity-60' : ''}">${UI.i(ic, 'mt-0.5 shrink-0 text-' + c + '-600')}
          <div><div class="text-sm">${E(a.msg)}${a.read ? '' : ' ' + UI.badge(c, 'new')}</div><div class="muted text-xs">${new Date(a.ts * 1000).toLocaleString()}</div></div></li>`; }).join('')
          || '<li class="muted text-sm">No alerts yet. You will see route outages, tunnel problems and expiring routes here.</li>'}</ul></div>`;

    // overall latency chart: one line per route that has data
    const lat = document.getElementById('lat');
    if (withData.some(r => r.series.length > 1)) {
      charts.push(UI.chart(lat, { chart: { type: 'line', height: 260, animations: { enabled: false } },
        series: withData.map(r => ({ name: r.hostname, data: r.series.map(x => [x[0] * 1000, x[1] ? x[2] : null]) })),
        xaxis: { type: 'datetime', labels: { datetimeUTC: false } }, yaxis: { title: { text: 'ms' }, min: 0 }, stroke: { width: 2, curve: 'straight' }, legend: { position: 'bottom' } }));
    } else lat.innerHTML = '<p class="muted text-sm">Not enough samples yet — keep this tool open for a few minutes.</p>';
    d.routes.forEach((r, i) => { if (r.series.length > 1) charts.push(UI.chart(document.getElementById('sp' + i), { chart: { type: 'area', height: 36, sparkline: { enabled: true }, animations: { enabled: false } },
      series: [{ name: 'ms', data: r.series.map(x => x[1] ? x[2] : 0) }], stroke: { width: 1.5 }, fill: { opacity: .2 }, colors: ['#4f46e5'], tooltip: { enabled: false } })); });
  }

  async function load(post) {
    try { draw(post ? await UI.post('/api/health/check') : await UI.api('/api/health')); } catch (e) { root.innerHTML = UI.errBox(e.message); }
  }
  document.getElementById('check').onclick = e => UI.busy(e.currentTarget, async () => { await load(true); UI.toast('Checked all routes'); });
  document.getElementById('read').onclick = e => UI.busy(e.currentTarget, async () => { await UI.post('/api/alerts/read'); await load(); const b = document.getElementById('alert-badge'); if (b) b.hidden = true; });
  load();
})();
</script>
