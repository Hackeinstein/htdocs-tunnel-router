<?php
$preselectProject = trim($_GET['project'] ?? '');
?>
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Attach Route</h2>
      <div class="text-secondary mt-1">Route a project folder to a public hostname via Cloudflare Tunnel</div>
    </div>
  </div>
</div>

<div id="attach-root">
  <div class="text-center py-5">
    <div class="spinner-border text-secondary" role="status"></div>
    <div class="text-secondary mt-2">Loading...</div>
  </div>
</div>

<script>
(async function() {
  const root = document.getElementById('attach-root');
  let projects = [], zones = [];
  const preselected = <?= json_encode($preselectProject) ?>;

  // ---- Step 1: Pick Project ----
  function step1() {
    const projectCards = projects
      .filter(p => p.route_status === 'none' || (preselected && p.name === preselected))
      .map(p => {
        const selected = p.name === preselected ? ' card-active' : '';
        return `<div class="col-md-6 col-lg-4">
          <div class="card project-card${selected}" data-project="${escapeHtml(p.name)}">
            <div class="card-body">
              <div class="d-flex align-items-center">
                <span class="ti ti-folder-filled fs-2 text-secondary me-3"></span>
                <div>
                  <strong>${escapeHtml(p.name)}</strong>
                  <div class="text-secondary small text-truncate">${escapeHtml(p.path)}</div>
                </div>
              </div>
            </div>
          </div>
        </div>`;
      }).join('');

    const available = projects.filter(p => p.route_status === 'none');
    if (!available.length && !preselected) {
      root.innerHTML = `<div class="empty">
        <div class="empty-icon"><span class="ti ti-circle-check fs-1"></span></div>
        <p class="empty-title">All projects are already routed</p>
        <p class="empty-subtitle text-secondary">Every htdocs folder is attached. Detach or add new folders first.</p>
        <a href="?page=dashboard" class="btn btn-primary mt-2">Back to Dashboard</a>
      </div>`;
      return;
    }

    root.innerHTML = `
      <div class="card mb-4">
        <div class="card-header"><h3 class="card-title">Step 1: Choose Project</h3></div>
        <div class="card-body">
          <p class="text-secondary mb-3">Select the htdocs folder you want to make publicly accessible.</p>
          <div class="row row-cards">${projectCards}</div>
          <div class="mt-3 d-none" id="step1-next">
            <button class="btn btn-primary" id="btn-next-step">Next: Configure Hostname <span class="ti ti-arrow-right ms-1"></span></button>
          </div>
        </div>
      </div>`;

    if (preselected && projects.some(p => p.name === preselected && p.route_status === 'none')) {
      step2(preselected);
      return;
    }

    // Card selection
    let selectedProject = null;
    root.querySelectorAll('.project-card').forEach(card => {
      card.addEventListener('click', () => {
        root.querySelectorAll('.project-card').forEach(c => c.classList.remove('card-active'));
        card.classList.add('card-active');
        selectedProject = card.dataset.project;
        document.getElementById('step1-next').classList.remove('d-none');
      });
    });

    document.getElementById('btn-next-step').addEventListener('click', () => {
      if (selectedProject) step2(selectedProject);
    });
  }

  // ---- Step 2: Configure Hostname ----
  function step2(projectName) {
    const project = projects.find(p => p.name === projectName);
    const zoneOptions = zones.map(z => `<option value="${escapeHtml(z.id)}">${escapeHtml(z.name)}</option>`).join('');

    root.innerHTML = `
      <div class="card mb-4">
        <div class="card-header">
          <h3 class="card-title">Step 2: Configure Hostname</h3>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <strong>Project:</strong> <span class="badge bg-blue-lt text-blue">${escapeHtml(projectName)}</span>
            <div class="text-secondary small">${escapeHtml(project.path)}</div>
          </div>

          <div class="mb-3">
            <label class="form-label required">Cloudflare Zone</label>
            <select class="form-select" id="zone-select">
              <option value="">-- Choose a zone --</option>
              ${zoneOptions}
            </select>
            <div class="form-text">The domain your project will be served under.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Subdomain</label>
            <div class="input-group">
              <input type="text" class="form-control" id="subdomain-input" placeholder="e.g. app or my-project" maxlength="63" pattern="[a-z0-9]([a-z0-9\\-]*[a-z0-9])?">
              <span class="input-group-text" id="zone-suffix">.example.com</span>
            </div>
            <div class="form-text">
              Leave empty for apex (root domain). For <code>app.example.com</code>, enter <code>app</code>.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="apex-check">
              <span class="form-check-label">Use apex (root domain)</span>
            </label>
            <div class="form-text">Makes the project available at the zone root (e.g. <code>example.com</code>). This may replace any existing A/CNAME record.</div>
          </div>

          <div class="mb-3">
            <label class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="www-check">
              <span class="form-check-label">Add www alias</span>
            </label>
            <div class="form-text">Also serves the project at <code>www.&lt;hostname&gt;</code>.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Label <small class="text-secondary">(optional)</small></label>
            <input type="text" class="form-control" id="label-input" placeholder="Friendly name for this route" maxlength="100">
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-link" id="btn-back-step1"><span class="ti ti-arrow-left me-1"></span>Back</button>
            <button class="btn btn-primary" id="btn-preview">Preview Configuration</button>
          </div>
        </div>
      </div>`;

    const zoneSelect = document.getElementById('zone-select');
    const subdomainInput = document.getElementById('subdomain-input');
    const apexCheck = document.getElementById('apex-check');
    const wwwCheck = document.getElementById('www-check');
    const zoneSuffix = document.getElementById('zone-suffix');

    // Update zone suffix
    zoneSelect.addEventListener('change', () => {
      const opt = zoneSelect.selectedOptions[0];
      zoneSuffix.textContent = opt && opt.textContent ? '.' + opt.textContent : '.example.com';
    });

    // Apex toggle disables subdomain
    apexCheck.addEventListener('change', () => {
      subdomainInput.disabled = apexCheck.checked;
      if (apexCheck.checked) subdomainInput.value = '';
      zoneSuffix.textContent = apexCheck.checked ? ' (root)' : (zoneSelect.selectedOptions[0]?.textContent ? '.' + zoneSelect.selectedOptions[0].textContent : '.example.com');
    });

    document.getElementById('btn-back-step1').addEventListener('click', () => step1());
    document.getElementById('btn-preview').addEventListener('click', () => {
      const zoneId = zoneSelect.value;
      const subdomain = apexCheck.checked ? '' : subdomainInput.value.trim().toLowerCase();
      const wwwAlias = wwwCheck.checked;
      const label = document.getElementById('label-input').value.trim() || projectName;

      if (!zoneId) { alert('Please choose a zone.'); return; }
      if (!apexCheck.checked && !subdomain) { alert('Enter a subdomain or check "Use apex".'); return; }
      if (subdomain && !/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i.test(subdomain)) {
        alert('Invalid subdomain. Use only letters, numbers, and hyphens.'); return;
      }

      step3(projectName, zoneId, subdomain, wwwAlias, label);
    });
  }

  // ---- Step 3: Preview & Confirm ----
  async function step3(projectName, zoneId, subdomain, wwwAlias, label) {
    root.innerHTML = `<div class="card mb-4"><div class="card-body text-center py-5">
      <div class="spinner-border text-secondary" role="status"></div>
      <div class="text-secondary mt-2">Building preview...</div>
    </div></div>`;

    try {
      const params = new URLSearchParams({ project: projectName, zone_id: zoneId, subdomain, www_alias: wwwAlias ? '1' : '0' });
      const res = await fetch(`api.php?route=/api/preview&${params}`);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);

      let apexWarning = '';
      if (data.apex_conflict) {
        apexWarning = `<div class="alert alert-warning mt-3">
          <strong><span class="ti ti-alert-triangle me-2"></span>Apex Conflict</strong><br>
          An existing <code>${escapeHtml(data.apex_conflict.type)}</code> record points to <code>${escapeHtml(data.apex_conflict.content)}</code>.
          Creating this route will <strong>replace</strong> it. This may affect email if MX records point here.
          <div class="mt-2">
            <label class="form-check"><input class="form-check-input" type="checkbox" id="force-apex"> I understand, replace the existing record</label>
          </div>
        </div>`;
      }

      root.innerHTML = `
        <div class="card mb-4">
          <div class="card-header"><h3 class="card-title">Step 3: Review & Confirm</h3></div>
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-md-6">
                <strong>Hostname:</strong> <code>${escapeHtml(data.hostname)}</code>
              </div>
              <div class="col-md-6">
                <strong>Type:</strong> ${subdomain === '' ? 'Apex' : 'Subdomain'}
                ${wwwAlias ? ' <span class="badge bg-blue-lt text-blue">+ www</span>' : ''}
              </div>
            </div>

            <div class="mb-3">
              <strong>DNS Record (Cloudflare):</strong>
              <pre class="code-preview">${escapeHtml(JSON.stringify(data.dns_payload, null, 2))}</pre>
            </div>

            <div class="mb-3">
              <strong>VirtualHost Block (Apache):</strong>
              <pre class="code-preview">${escapeHtml(data.vhost_block)}</pre>
            </div>

            ${apexWarning}

            <div class="d-flex gap-2 mt-4">
              <button class="btn btn-link" id="btn-back-step2"><span class="ti ti-arrow-left me-1"></span>Back</button>
              <button class="btn btn-success" id="btn-confirm" ${data.apex_conflict ? 'disabled' : ''}>
                <span class="ti ti-check me-1"></span>Confirm & Apply
              </button>
            </div>
          </div>
        </div>`;

      if (data.apex_conflict) {
        document.getElementById('force-apex').addEventListener('change', function() {
          document.getElementById('btn-confirm').disabled = !this.checked;
        });
      }

      document.getElementById('btn-back-step2').addEventListener('click', () => step2(projectName));
      document.getElementById('btn-confirm').addEventListener('click', () => {
        step4(projectName, zoneId, subdomain, wwwAlias, label, data.hostname, !!data.apex_conflict);
      });

      // Bind retry-force and back-step2 buttons for apex conflict re-show
      const retryBtn = document.querySelector('.retry-force-btn');
      const backBtn = document.querySelector('.back-step2-btn');
      if (retryBtn) {
        retryBtn.addEventListener('click', function() {
          step4(this.dataset.project, this.dataset.zone, this.dataset.sub, this.dataset.www === 'true', this.dataset.label, this.dataset.host, true);
        });
      }
      if (backBtn) {
        backBtn.addEventListener('click', () => step2(backBtn.dataset.project));
      }

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">
        <strong>Preview failed:</strong> ${escapeHtml(e.message)}
        <button class="btn btn-link btn-sm" onclick="location.reload()">Try again</button>
      </div>`;
    }
  }

  // ---- Step 4: Apply ----
  async function step4(projectName, zoneId, subdomain, wwwAlias, label, hostname, forceApex) {
    root.innerHTML = `<div class="card mb-4"><div class="card-body text-center py-5">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">Creating DNS record & vhost block...</div>
    </div></div>`;

    try {
      const res = await fetch('api.php?route=/api/routes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          project: projectName,
          zone_id: zoneId,
          subdomain: subdomain,
          www_alias: wwwAlias,
          label: label,
          force_apex: forceApex
        })
      });
      const data = await res.json();

      if (data.apex_conflict && !data.ok) {
        // Show conflict again
        root.innerHTML = `<div class="card mb-4"><div class="card-body">
          <div class="alert alert-warning">
            <strong><span class="ti ti-alert-triangle me-2"></span>Apex Conflict</strong><br>
            An existing <code>${escapeHtml(data.apex_conflict.type)}</code> record points to <code>${escapeHtml(data.apex_conflict.content)}</code>.
            <div class="mt-3">
              <button class="btn btn-warning retry-force-apply-btn">Replace & Continue</button>
              <button class="btn btn-link back-retry-btn">Go Back</button>
            </div>
          </div>
        </div></div>`;

        // Bind the retry and back buttons
        document.querySelector('.retry-force-apply-btn')?.addEventListener('click', () => {
          step4(projectName, zoneId, subdomain, wwwAlias, label, hostname, true);
        });
        document.querySelector('.back-retry-btn')?.addEventListener('click', () => {
          step2(projectName);
        });
        return;
      }

      if (!data.ok) {
        // Surface Apache output if that's what failed
        const isApache = data.step === 'apache_validate';
        const apacheDetail = data.output
          ? `<div class="mt-3">
               <strong class="small">Apache <code>httpd -t</code> output:</strong>
               <pre class="code-preview mt-1">${escapeHtml(data.output)}</pre>
               <p class="text-secondary small mt-1">The DNS record was rolled back. Fix the Apache config issue, then try again.</p>
             </div>`
          : '';
        root.innerHTML = `<div class="card mb-4">
          <div class="card-status-top bg-danger"></div>
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-3 text-danger">
              <span class="ti ti-alert-circle fs-2"></span>
              <strong>${isApache ? 'Apache Validation Failed' : 'Attach Failed'}</strong>
            </div>
            <p>${escapeHtml(data.error)}</p>
            ${apacheDetail}
            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-link px-0" onclick="location.reload()">
                <span class="ti ti-refresh me-1"></span>Try again
              </button>
              <a href="?page=dashboard" class="btn btn-ghost-secondary btn-sm">Dashboard</a>
            </div>
          </div>
        </div>`;
        return;
      }

      root.innerHTML = `<div class="card mb-4">
        <div class="card-status-top bg-success"></div>
        <div class="card-body text-center py-5">
          <div class="text-success mb-3"><span class="ti ti-circle-check" style="font-size:3rem"></span></div>
          <h3>Route Attached!</h3>
          <p class="text-secondary mb-4"><strong>${escapeHtml(hostname)}</strong> → <code>${escapeHtml(projectName)}</code></p>
          <div class="row justify-content-center">
            <div class="col-md-7">
              <div class="card bg-green-lt text-start mb-3">
                <div class="card-body py-2">
                  <div class="small fw-bold text-success mb-1"><span class="ti ti-cloud-check me-1"></span>Cloudflare DNS Record Created</div>
                  <div class="small font-monospace">Type: CNAME</div>
                  <div class="small font-monospace">Name: ${escapeHtml(hostname)}</div>
                  <div class="small font-monospace text-secondary">Record ID: ${escapeHtml(data.dns_record_id || '—')}</div>
                </div>
              </div>
              <div class="card bg-blue-lt text-start mb-3">
                <div class="card-body py-2">
                  <div class="small fw-bold text-blue mb-1"><span class="ti ti-server me-1"></span>Apache VHost Block Written</div>
                  <div class="small font-monospace">httpd-vhosts.conf updated &amp; reloaded</div>
                </div>
              </div>
              <p class="text-secondary small">
                <span class="ti ti-clock me-1"></span>Verify in your
                <a href="https://dash.cloudflare.com" target="_blank" rel="noopener">Cloudflare dashboard</a>
                under DNS → Records. Propagation is instant for proxied CNAMEs.
              </p>
            </div>
          </div>
          <div class="mt-3 d-flex gap-2 justify-content-center">
            <a href="?page=dashboard" class="btn btn-primary">Back to Dashboard</a>
            <a href="?page=attach" class="btn btn-ghost-primary">Attach Another</a>
          </div>
        </div>
      </div>`;

    } catch (e) {
      root.innerHTML = `<div class="alert alert-danger">
        <span class="ti ti-alert-circle me-2"></span>
        <strong>Attach failed:</strong> ${escapeHtml(e.message)}
        <p class="mt-2 text-secondary small mb-0">The DNS record was rolled back — nothing is left in a broken state.</p>
        <button class="btn btn-link btn-sm ps-0 mt-1" onclick="location.reload()">Try again</button>
      </div>`;
    }
  }

  function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // ---- Init ----
  try {
    const [projRes, zoneRes] = await Promise.all([
      fetch('api.php?route=/api/projects'),
      fetch('api.php?route=/api/zones')
    ]);
    const projData = await projRes.json();
    const zoneData = await zoneRes.json();

    if (!projData.ok) throw new Error(projData.error || 'Failed to load projects');
    if (!zoneData.ok) throw new Error(zoneData.error || 'Failed to load zones');

    projects = projData.projects;
    zones = zoneData.zones;

    step1();
  } catch (e) {
    root.innerHTML = `<div class="alert alert-danger">Failed to load: ${escapeHtml(e.message)}</div>`;
  }
})();
</script>
