<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Activity Log</h2>
      <div class="text-secondary mt-1">Recent actions performed by Tunnel Router</div>
    </div>
  </div>
</div>

<div id="activity-root">
  <div class="text-center py-5">
    <div class="spinner-border text-secondary" role="status"></div>
    <div class="text-secondary mt-2">Loading activity...</div>
  </div>
</div>

<script>
(async function() {
  const root = document.getElementById('activity-root');

  function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function timeAgo(ts) {
    const d = new Date(ts);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return d.toLocaleDateString();
  }

  function formatTs(ts) {
    const d = new Date(ts);
    return d.toLocaleString();
  }

  const actionLabels = {
    attach:         'Attached',
    detach:         'Detached',
    toggle:         'Toggled',
    reconcile_fix:  'Reconcile Fix',
  };
  const actionIcons = {
    attach:         'ti-plug-connected',
    detach:         'ti-plug-connected-off',
    toggle:         'ti-toggle-right',
    reconcile_fix:  'ti-tool',
  };
  const actionColors = {
    attach:         'bg-blue-lt text-blue',
    detach:         'bg-red-lt text-red',
    toggle:         'bg-yellow-lt text-yellow',
    reconcile_fix:  'bg-purple-lt text-purple',
  };

  async function load() {
    try {
      const res = await fetch('api.php?route=/api/activity&limit=100');
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load activity');

      const entries = data.entries || [];
      if (!entries.length) {
        root.innerHTML = `<div class="empty">
          <div class="empty-icon"><span class="ti ti-history fs-1"></span></div>
          <p class="empty-title">No activity yet</p>
          <p class="empty-subtitle text-secondary">Actions will appear here once you start attaching routes.</p>
        </div>`;
        return;
      }

      const items = entries.map((entry, i) => {
        const label = actionLabels[entry.action] || entry.action;
        const icon = actionIcons[entry.action] || 'ti-activity';
        const colorClass = actionColors[entry.action] || 'bg-secondary-lt text-secondary';
        const dotColor = entry.ok ? 'text-success' : 'text-danger';
        const dot = `<span class="ti ti-circle-filled ${dotColor}"></span>`;

        return `<li class="timeline-item">
          <div class="timeline-item-marker">
            <div class="timeline-item-marker-indicator ${colorClass}">
              <span class="ti ${icon}"></span>
            </div>
            <div class="timeline-item-marker-line"></div>
          </div>
          <div class="timeline-item-content">
            <div class="d-flex align-items-center gap-2">
              <strong>${escapeHtml(label)}</strong>
              ${dot}
              <span class="text-secondary small" title="${escapeHtml(formatTs(entry.ts))}">${timeAgo(entry.ts)}</span>
            </div>
            <div class="mt-1">
              <code>${escapeHtml(entry.hostname)}</code>
            </div>
            ${entry.detail ? `<div class="text-secondary small mt-1">${escapeHtml(entry.detail)}</div>` : ''}
          </div>
        </li>`;
      }).join('');

      root.innerHTML = `<div class="card">
        <div class="card-body">
          <ul class="timeline">${items}</ul>
        </div>
        ${entries.length >= 100 ? `<div class="card-footer text-center">
          <button class="btn btn-ghost-primary btn-sm" id="btn-load-more"><span class="ti ti-arrow-down me-1"></span>Load More</button>
        </div>` : ''}
      </div>`;

      // Load more pagination
      const loadMoreBtn = document.getElementById('btn-load-more');
      if (loadMoreBtn) {
        let offset = 100;
        loadMoreBtn.addEventListener('click', async () => {
          loadMoreBtn.disabled = true;
          loadMoreBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
          try {
            const res = await fetch(`api.php?route=/api/activity&limit=100&offset=${offset}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error);
            const more = data.entries || [];
            if (!more.length) {
              loadMoreBtn.textContent = 'No more entries';
              return;
            }
            const timeline = document.querySelector('.timeline');
            const moreItems = more.map(entry => {
              const label = actionLabels[entry.action] || entry.action;
              const icon = actionIcons[entry.action] || 'ti-activity';
              const colorClass = actionColors[entry.action] || 'bg-secondary-lt text-secondary';
              const dotColor = entry.ok ? 'text-success' : 'text-danger';
              return `<li class="timeline-item">
                <div class="timeline-item-marker">
                  <div class="timeline-item-marker-indicator ${colorClass}"><span class="ti ${icon}"></span></div>
                  <div class="timeline-item-marker-line"></div>
                </div>
                <div class="timeline-item-content">
                  <div class="d-flex align-items-center gap-2">
                    <strong>${escapeHtml(label)}</strong>
                    <span class="ti ti-circle-filled ${dotColor}"></span>
                    <span class="text-secondary small" title="${escapeHtml(formatTs(entry.ts))}">${timeAgo(entry.ts)}</span>
                  </div>
                  <div class="mt-1"><code>${escapeHtml(entry.hostname)}</code></div>
                  ${entry.detail ? `<div class="text-secondary small mt-1">${escapeHtml(entry.detail)}</div>` : ''}
                </div>
              </li>`;
            }).join('');
            timeline.insertAdjacentHTML('beforeend', moreItems);
            offset += more.length;
            if (more.length < 100) {
              loadMoreBtn.textContent = 'No more entries';
            } else {
              loadMoreBtn.innerHTML = '<span class="ti ti-arrow-down me-1"></span>Load More';
              loadMoreBtn.disabled = false;
            }
          } catch (e) {
            loadMoreBtn.textContent = 'Error';
          }
        });
      }

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">Failed to load activity: ${escapeHtml(e.message)}</div>`;
    }
  }

  load();
})();
</script>
