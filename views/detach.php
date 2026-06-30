<?php
$hostname = trim($_GET['hostname'] ?? '');
?>
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Detach Route</h2>
      <div class="text-secondary mt-1">Remove DNS record and vhost block for a hostname</div>
    </div>
  </div>
</div>

<div id="detach-root">
  <div class="text-center py-5">
    <div class="spinner-border text-secondary" role="status"></div>
    <div class="text-secondary mt-2">Loading routes...</div>
  </div>
</div>

<script>
(async function() {
  const root = document.getElementById('detach-root');
  const preselectedHostname = <?= json_encode($hostname) ?>;

  function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  async function load() {
    try {
      const res = await fetch('api.php?route=/api/routes');
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load routes');

      let routes = data.routes;
      if (preselectedHostname) {
        routes = routes.filter(r => r.hostname === preselectedHostname);
      }

      if (!routes.length) {
        root.innerHTML = `<div class="empty">
          <div class="empty-icon"><span class="ti ti-circle-check fs-1"></span></div>
          <p class="empty-title">No routes to detach</p>
          <p class="empty-subtitle text-secondary">${preselectedHostname ? 'Route not found.' : 'No active routes exist.'}</p>
          <a href="?page=dashboard" class="btn btn-primary mt-2">Back to Dashboard</a>
        </div>`;
        return;
      }

      let html = '';
      routes.forEach(r => {
        const typeBadge = r.type === 'apex'
          ? '<span class="badge bg-purple-lt text-purple">Apex</span>'
          : '<span class="badge bg-blue-lt text-blue">Subdomain</span>';
        const statusBadge = r.enabled
          ? '<span class="badge bg-success-lt text-success">Enabled</span>'
          : '<span class="badge bg-warning-lt text-warning">Disabled</span>';

        html += `<div class="card mb-3">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-md">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <strong class="fs-4">${escapeHtml(r.hostname)}</strong>
                  ${typeBadge} ${statusBadge}
                </div>
                <div class="text-secondary">
                  <span class="ti ti-folder me-1"></span>${escapeHtml(r.project)} &mdash;
                  <code class="small">${escapeHtml(r.document_root)}</code>
                </div>
                ${r.label && r.label !== r.project ? `<div class="text-secondary small">Label: ${escapeHtml(r.label)}</div>` : ''}
              </div>
              <div class="col-md-auto mt-3 mt-md-0">
                <button class="btn btn-danger detach-btn" data-hostname="${escapeHtml(r.hostname)}">
                  <span class="ti ti-trash me-1"></span>Detach
                </button>
              </div>
            </div>
          </div>
        </div>`;
      });

      root.innerHTML = html;

      // Bind detach buttons
      root.querySelectorAll('.detach-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const hostname = btn.dataset.hostname;
          confirmDetach(hostname);
        });
      });

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">Failed to load routes: ${escapeHtml(e.message)}</div>`;
    }
  }

  function confirmDetach(hostname) {
    root.innerHTML = `<div class="card">
      <div class="card-status-top bg-danger"></div>
      <div class="card-body text-center py-5">
        <div class="text-danger mb-3"><span class="ti ti-alert-triangle fs-1"></span></div>
        <h3>Detach ${escapeHtml(hostname)}?</h3>
        <p class="text-secondary">
          This will <strong>permanently delete</strong> the Cloudflare DNS record and remove the Apache vhost block.<br>
          The project folder itself will <strong>not</strong> be deleted.
        </p>
        <div class="d-flex gap-2 justify-content-center mt-4">
          <button class="btn btn-link" onclick="location.reload()">Cancel</button>
          <button class="btn btn-danger" id="btn-execute-detach">
            <span class="ti ti-trash me-1"></span>Yes, Detach
          </button>
        </div>
      </div>
    </div>`;

    document.getElementById('btn-execute-detach').addEventListener('click', () => executeDetach(hostname));
  }

  async function executeDetach(hostname) {
    root.innerHTML = `<div class="card"><div class="card-body text-center py-5">
      <div class="spinner-border text-danger" role="status"></div>
      <div class="text-secondary mt-2">Removing DNS record & vhost block...</div>
    </div></div>`;

    try {
      const res = await fetch(`api.php?route=/api/routes/${encodeURIComponent(hostname)}&confirm=1`, { method: 'DELETE' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);

      root.innerHTML = `<div class="card"><div class="card-body text-center py-5">
        <div class="text-success mb-3"><span class="ti ti-circle-check fs-1"></span></div>
        <h3>Route Detached</h3>
        <p class="text-secondary"><strong>${escapeHtml(hostname)}</strong> removed. Apache reloaded.</p>
        <a href="?page=dashboard" class="btn btn-primary mt-3">Back to Dashboard</a>
      </div></div>`;

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">
        <strong>Detach failed:</strong> ${escapeHtml(e.message)}
        <button class="btn btn-link btn-sm" onclick="location.reload()">Try again</button>
      </div>`;
    }
  }

  load();
})();
</script>
