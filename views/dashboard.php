<?php
$htdocs = htmlspecialchars(Config::load()['paths']['htdocs']);
?>
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Dashboard</h2>
      <div class="text-secondary mt-1">Projects in <code><?= $htdocs ?></code></div>
    </div>
    <div class="col-auto ms-auto d-flex gap-2">
      <button class="btn btn-ghost-secondary btn-sm" onclick="location.reload()">
        <span class="ti ti-refresh me-1"></span>Refresh
      </button>
      <a href="?page=attach" class="btn btn-primary">
        <span class="ti ti-plug-connected me-1"></span>Attach Route
      </a>
    </div>
  </div>
</div>

<div id="dash-alert"></div>
<div id="dash-stats" class="row mb-4 d-none"></div>
<div id="dashboard-root">
  <div class="text-center py-5">
    <div class="spinner-border text-blue" role="status"></div>
    <div class="text-secondary mt-2">Loading projects…</div>
  </div>
</div>

<script>
(async function () {
  const root      = document.getElementById('dashboard-root');
  const statsRow  = document.getElementById('dash-stats');
  const alertBox  = document.getElementById('dash-alert');

  function esc(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  /* ---- fetch projects + reconcile in parallel ---- */
  let projects = [], reconcileMap = {}, reconcileAvailable = false;

  try {
    const [pr, rr] = await Promise.all([
      fetch('api.php?route=/api/projects').then(r => r.json()),
      fetch('api.php?route=/api/reconcile').then(r => r.json()).catch(() => ({ ok: false, error: 'CF API unreachable' })),
    ]);

    if (!pr.ok) throw new Error(pr.error || 'Failed to load projects');
    projects = pr.projects || [];

    if (rr.ok && rr.routes) {
      reconcileAvailable = true;
      for (const r of rr.routes) reconcileMap[r.hostname] = r;
    } else if (rr.error) {
      alertBox.innerHTML = `<div class="alert alert-warning mb-3">
        <span class="ti ti-wifi-off me-2"></span>
        Live DNS check unavailable (<strong>${esc(rr.error)}</strong>) — showing cached status only.
        <a href="?page=settings" class="ms-2">Check Settings</a>
      </div>`;
    }
  } catch (e) {
    root.innerHTML = `<div class="alert alert-danger"><span class="ti ti-alert-circle me-2"></span>${esc(e.message)}</div>`;
    return;
  }

  if (!projects.length) {
    root.innerHTML = `<div class="empty py-5">
      <div class="empty-icon"><span class="ti ti-folder-off" style="font-size:3rem;color:#adb5bd"></span></div>
      <p class="empty-title">No projects found</p>
      <p class="empty-subtitle text-secondary">Add project folders to your htdocs directory, then refresh.</p>
    </div>`;
    return;
  }

  /* ---- stats row ---- */
  const live     = projects.filter(p => p.route_status === 'active').length;
  const disabled = projects.filter(p => p.route_status === 'disabled').length;
  const unrouted = projects.filter(p => p.route_status === 'none').length;
  const problems = reconcileAvailable
    ? Object.values(reconcileMap).filter(r => r.status !== 'ok').length
    : 0;

  statsRow.innerHTML = `
    <div class="col-6 col-sm-3">
      <div class="card card-sm">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-auto"><span class="avatar bg-success-lt text-success"><span class="ti ti-circle-check"></span></span></div>
            <div class="col"><div class="fw-medium">${live}</div><div class="text-secondary small">Live</div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card card-sm">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-auto"><span class="avatar bg-secondary-lt text-secondary"><span class="ti ti-folder"></span></span></div>
            <div class="col"><div class="fw-medium">${projects.length}</div><div class="text-secondary small">Projects</div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card card-sm">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-auto"><span class="avatar bg-yellow-lt text-yellow"><span class="ti ti-unlink"></span></span></div>
            <div class="col"><div class="fw-medium">${unrouted}</div><div class="text-secondary small">Unrouted</div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card card-sm">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-auto"><span class="avatar ${problems > 0 ? 'bg-danger-lt text-danger' : 'bg-green-lt text-success'}"><span class="ti ti-${problems > 0 ? 'alert-triangle' : 'check'}"></span></span></div>
            <div class="col"><div class="fw-medium">${problems > 0 ? problems + ' issue' + (problems > 1 ? 's' : '') : 'All OK'}</div><div class="text-secondary small">DNS health</div></div>
          </div>
        </div>
      </div>
    </div>`;
  statsRow.classList.remove('d-none');

  /* ---- render project cards ---- */
  const cards = projects.map(p => renderCard(p)).join('');
  root.innerHTML = `<div class="row row-cards">${cards}</div>`;

  /* ---- bind toggle buttons ---- */
  root.querySelectorAll('.js-toggle').forEach(btn => {
    btn.addEventListener('click', async function () {
      const hostname = this.dataset.hostname;
      const origHTML = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      try {
        const res  = await fetch(`api.php?route=/api/routes/${encodeURIComponent(hostname)}/toggle`, { method: 'POST' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error);
        location.reload();
      } catch (e) {
        alert('Toggle failed: ' + e.message);
        this.innerHTML = origHTML;
        this.disabled = false;
      }
    });
  });

  /* ---- card renderer ---- */
  function renderCard(p) {
    const recon  = p.hostname ? (reconcileMap[p.hostname] ?? null) : null;
    const dnsOk  = recon ? recon.has_dns   : null;  // null = unknown
    const vhostOk= recon ? recon.has_vhost : null;
    const rStatus= recon ? recon.status    : p.route_status;

    // Compute display status
    let status = p.route_status; // active | disabled | none
    if (recon && p.route_status !== 'none') {
      if (recon.status === 'dns_missing')   status = 'dns_missing';
      else if (recon.status === 'vhost_missing') status = 'vhost_missing';
      else if (recon.status === 'drifted')  status = 'drifted';
      else if (recon.status === 'both_missing') status = 'broken';
    }

    const badgeCfg = {
      active:        { cls: 'bg-success text-white',          label: 'Live'          },
      disabled:      { cls: 'bg-warning text-white',          label: 'Disabled'      },
      none:          { cls: 'bg-secondary-lt text-secondary', label: 'Not Routed'    },
      dns_missing:   { cls: 'bg-danger text-white',           label: 'DNS Missing'   },
      vhost_missing: { cls: 'bg-danger text-white',           label: 'VHost Missing' },
      drifted:       { cls: 'bg-orange text-white',           label: 'Drifted'       },
      broken:        { cls: 'bg-danger text-white',           label: 'Broken'        },
    };
    const bc    = badgeCfg[status] ?? badgeCfg.none;
    const badge = `<span class="badge ${bc.cls}">${bc.label}</span>`;

    const isRouted = p.route_status === 'active' || p.route_status === 'disabled';

    // Hostname row
    const hostnameHtml = p.hostname
      ? `<div class="mt-2">
           <a href="https://${esc(p.hostname)}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">
             <span class="ti ti-world text-secondary" style="font-size:.9rem"></span>
             <code class="small">${esc(p.hostname)}</code>
             <span class="ti ti-external-link text-secondary" style="font-size:.75rem"></span>
           </a>
         </div>`
      : '';

    // DNS + VHost health pills (only when reconcile data is available)
    let healthHtml = '';
    if (isRouted && reconcileAvailable && recon) {
      const pill = (ok, label) => {
        const color = ok ? 'text-success' : 'text-danger';
        const icon  = ok ? 'ti-check' : 'ti-x';
        return `<span class="small ${color} d-flex align-items-center gap-1"><span class="ti ${icon}" style="font-size:.8rem"></span>${label}</span>`;
      };
      healthHtml = `<div class="d-flex gap-3 mt-2">
        ${pill(dnsOk, 'DNS')}
        ${pill(vhostOk, 'VHost')}
      </div>`;
    } else if (isRouted && !reconcileAvailable) {
      healthHtml = `<div class="mt-2"><span class="text-secondary small"><span class="ti ti-cloud-question me-1"></span>DNS unknown — CF API not reachable</span></div>`;
    }

    // Action buttons
    let actionsHtml = '';
    if (isRouted) {
      const isEnabled   = p.route_status === 'active';
      const toggleLabel = isEnabled ? 'Disable' : 'Enable';
      const toggleIcon  = isEnabled ? 'ti-toggle-right' : 'ti-toggle-left';
      const needsFix    = ['dns_missing','vhost_missing','drifted','broken'].includes(status);
      actionsHtml = `<div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-sm btn-ghost-secondary js-toggle" data-hostname="${esc(p.hostname)}">
          <span class="ti ${toggleIcon} me-1"></span>${toggleLabel}
        </button>
        <a href="?page=detach&hostname=${encodeURIComponent(p.hostname)}" class="btn btn-sm btn-ghost-danger">
          <span class="ti ti-trash me-1"></span>Detach
        </a>
        ${needsFix ? `<a href="?page=reconcile" class="btn btn-sm btn-ghost-warning"><span class="ti ti-refresh-alert me-1"></span>Fix drift</a>` : ''}
      </div>`;
    } else {
      actionsHtml = `<div class="mt-3">
        <a href="?page=attach&project=${encodeURIComponent(p.name)}" class="btn btn-sm btn-primary">
          <span class="ti ti-plug-connected me-1"></span>Attach
        </a>
      </div>`;
    }

    // Top colour bar
    const barColor = { active: 'bg-success', disabled: 'bg-warning', dns_missing: 'bg-danger', vhost_missing: 'bg-danger', drifted: 'bg-warning', broken: 'bg-danger' }[status] ?? '';
    const topBar   = barColor ? `<div class="card-status-top ${barColor}"></div>` : '';

    return `<div class="col-md-6 col-xl-4">
      <div class="card h-100">
        ${topBar}
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="avatar avatar-sm bg-secondary-lt text-secondary flex-shrink-0">
                <span class="ti ti-folder-filled" style="font-size:1rem"></span>
              </span>
              <div class="min-w-0">
                <div class="fw-semibold text-truncate" title="${esc(p.name)}">${esc(p.name)}</div>
                <div class="text-secondary text-truncate" style="font-size:.72rem" title="${esc(p.path)}">${esc(p.path)}</div>
              </div>
            </div>
            <div class="flex-shrink-0">${badge}</div>
          </div>
          ${hostnameHtml}
          ${healthHtml}
          ${actionsHtml}
        </div>
      </div>
    </div>`;
  }
})();
</script>
