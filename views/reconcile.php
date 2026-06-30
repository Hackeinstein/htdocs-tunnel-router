<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Reconcile</h2>
      <div class="text-secondary mt-1">Detect drift between routes.json, live vhosts, and Cloudflare DNS</div>
    </div>
    <div class="col-auto ms-auto">
      <button class="btn btn-primary" id="btn-refresh" onclick="location.reload()">
        <span class="ti ti-refresh me-2"></span>Refresh
      </button>
    </div>
  </div>
</div>

<div id="reconcile-root">
  <div class="text-center py-5">
    <div class="spinner-border text-secondary" role="status"></div>
    <div class="text-secondary mt-2">Checking for drift...</div>
  </div>
</div>

<script>
(async function() {
  const root = document.getElementById('reconcile-root');

  const statusConfig = {
    ok:           { badge: '<span class="badge bg-success-lt text-success">OK</span>',                    icon: 'ti-circle-check' },
    dns_missing:  { badge: '<span class="badge bg-danger-lt text-danger">DNS Missing</span>',             icon: 'ti-cloud-off' },
    vhost_missing:{ badge: '<span class="badge bg-danger-lt text-danger">VHost Missing</span>',           icon: 'ti-server-off' },
    both_missing: { badge: '<span class="badge bg-orange-lt text-orange">Both Missing</span>',            icon: 'ti-alert-triangle' },
    drifted:      { badge: '<span class="badge bg-warning-lt text-warning">Drifted</span>',               icon: 'ti-edit' },
    dns_only:     { badge: '<span class="badge bg-info-lt text-info">DNS Only</span>',                    icon: 'ti-cloud-question' },
  };

  function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  async function load() {
    try {
      const res = await fetch('api.php?route=/api/reconcile');
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Reconcile failed');

      const results = data.routes || [];
      const hasIssues = results.some(r => r.status !== 'ok');

      if (!results.length) {
        root.innerHTML = `<div class="empty">
          <div class="empty-icon"><span class="ti ti-circle-check fs-1"></span></div>
          <p class="empty-title">Nothing to reconcile</p>
          <p class="empty-subtitle text-secondary">No routes, vhosts, or DNS records found.</p>
        </div>`;
        return;
      }

      if (!hasIssues) {
        root.innerHTML = `<div class="card">
          <div class="card-body text-center py-4">
            <span class="ti ti-circle-check text-success fs-1 mb-2 d-block"></span>
            <h3>All Clear</h3>
            <p class="text-secondary">All ${results.length} routes are in sync — no drift detected.</p>
            <button class="btn btn-ghost-primary btn-sm" onclick="location.reload()"><span class="ti ti-refresh me-1"></span>Refresh</button>
          </div>
        </div>`;
        return;
      }

      const rows = results.map(r => {
        const cfg = statusConfig[r.status] || statusConfig.both_missing;
        const routeInfo = r.route_entry
          ? `<span class="text-secondary small">Project: <strong>${escapeHtml(r.route_entry.project)}</strong> | Zone: ${escapeHtml(r.route_entry.zone_id)}</span>`
          : '<span class="text-secondary small">Not in routes.json</span>';

        // Action buttons based on status
        let actions = '';
        switch (r.status) {
          case 'dns_missing':
            actions = `<button class="btn btn-sm btn-primary fix-btn" data-action="create_dns" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-cloud-up me-1"></span>Create DNS</button>
                       <button class="btn btn-sm btn-ghost-danger fix-btn" data-action="delete_vhost" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-trash me-1"></span>Remove VHost</button>`;
            break;
          case 'vhost_missing':
            actions = `<button class="btn btn-sm btn-primary fix-btn" data-action="create_vhost" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-server me-1"></span>Create VHost</button>
                       <button class="btn btn-sm btn-ghost-danger fix-btn" data-action="delete_dns" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-trash me-1"></span>Remove DNS</button>`;
            break;
          case 'both_missing':
            actions = `<button class="btn btn-sm btn-ghost-danger fix-btn" data-action="delete_route_entry" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-trash me-1"></span>Remove Route Entry</button>`;
            break;
          case 'drifted':
            actions = `<span class="text-secondary small">Hand-edited vhost — <a href="?page=detach&hostname=${encodeURIComponent(r.hostname)}">detach</a> to clean up</span>`;
            break;
          case 'dns_only':
            actions = `<button class="btn btn-sm btn-ghost-danger fix-btn" data-action="delete_dns" data-hostname="${escapeHtml(r.hostname)}"><span class="ti ti-trash me-1"></span>Delete DNS</button>`;
            break;
          default:
            actions = '<span class="text-success small">In sync</span>';
        }

        return `<tr>
          <td><code>${escapeHtml(r.hostname)}</code></td>
          <td>${cfg.badge}</td>
          <td>${routeInfo}</td>
          <td>${actions}</td>
        </tr>`;
      }).join('');

      root.innerHTML = `
        <div class="card">
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-vcenter card-table">
                <thead>
                  <tr>
                    <th>Hostname</th>
                    <th>Status</th>
                    <th>Details</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        </div>`;

      // Bind fix buttons
      root.querySelectorAll('.fix-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
          this.disabled = true;
          const hostname = this.dataset.hostname;
          const action = this.dataset.action;
          try {
            const res = await fetch('api.php?route=/api/reconcile/fix', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ hostname, action })
            });
            const d = await res.json();
            if (!d.ok) throw new Error(d.error);
            location.reload();
          } catch (e) {
            alert('Fix failed: ' + e.message);
            this.disabled = false;
          }
        });
      });

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">Reconcile failed: ${escapeHtml(e.message)}</div>`;
    }
  }

  load();
})();
</script>
