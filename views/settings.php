<?php
$cfg = Config::load();
$isConfigured = Config::isConfigured();
$firstRun = ($_GET['first_run'] ?? '') === '1';
?>
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Settings</h2>
      <div class="text-secondary mt-1">Configure Cloudflare credentials and system paths</div>
    </div>
  </div>
</div>

<?php if ($firstRun): ?>
<div class="alert alert-info mb-4">
  <div class="d-flex">
    <span class="ti ti-info-circle me-2 fs-2"></span>
    <div>
      <strong>Welcome!</strong> This is your first run. Enter your Cloudflare API credentials and tunnel details to get started.
    </div>
  </div>
</div>
<?php endif; ?>

<form id="settings-form" autocomplete="off">
  <div class="card mb-4">
    <div class="card-header">
      <h3 class="card-title">Cloudflare</h3>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label required" for="cf-token">API Token</label>
        <input type="password" class="form-control" id="cf-token" name="cloudflare[api_token]"
               placeholder="<?= $isConfigured ? '•••••••• (leave blank to keep current)' : 'CF API token with Zone:Read + DNS:Edit' ?>"
               maxlength="255" autocomplete="off">
        <div class="form-text">
          Scoped to <strong>Zone:Read</strong> + <strong>DNS:Edit</strong>. The token is stored server-side and never logged or exposed.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label required" for="cf-account-id">Account ID</label>
        <input type="text" class="form-control" id="cf-account-id" name="cloudflare[account_id]"
               value="<?= htmlspecialchars($cfg['cloudflare']['account_id'] ?? '') ?>"
               placeholder="Cloudflare Account ID" maxlength="64">
      </div>

      <div class="mb-3">
        <label class="form-label required" for="cf-tunnel-id">Tunnel ID</label>
        <input type="text" class="form-control" id="cf-tunnel-id" name="cloudflare[tunnel_id]"
               value="<?= htmlspecialchars($cfg['cloudflare']['tunnel_id'] ?? '') ?>"
               placeholder="UUID of the pre-created Cloudflare Tunnel" maxlength="64">
      </div>

      <div class="mb-3">
        <label class="form-label" for="cf-tunnel-name">Tunnel Name <small class="text-secondary">(optional)</small></label>
        <input type="text" class="form-control" id="cf-tunnel-name" name="cloudflare[tunnel_name]"
               value="<?= htmlspecialchars($cfg['cloudflare']['tunnel_name'] ?? '') ?>"
               placeholder="Display name for the sidebar badge" maxlength="100">
      </div>

      <div class="mt-3">
        <button type="button" class="btn btn-ghost-secondary" id="btn-test">
          <span class="ti ti-plug me-1"></span>Test Connection
        </button>
        <span class="text-secondary ms-2 small" id="test-result"></span>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <h3 class="card-title">Paths</h3>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label required" for="path-htdocs">htdocs Folder</label>
        <input type="text" class="form-control" id="path-htdocs" name="paths[htdocs]"
               value="<?= htmlspecialchars($cfg['paths']['htdocs'] ?? '') ?>"
               placeholder="C:/xampp/htdocs" maxlength="500">
        <div class="form-text">Root folder containing all your project subdirectories.</div>
      </div>

      <div class="mb-3">
        <label class="form-label required" for="path-vhosts">VHosts Config File</label>
        <input type="text" class="form-control" id="path-vhosts" name="paths[vhosts_file]"
               value="<?= htmlspecialchars($cfg['paths']['vhosts_file'] ?? '') ?>"
               placeholder="C:/xampp/apache/conf/extra/httpd-vhosts.conf" maxlength="500">
        <div class="form-text">Apache's virtual hosts configuration file.</div>
      </div>

      <div class="mb-3">
        <label class="form-label required" for="path-apache">Apache Binary</label>
        <input type="text" class="form-control" id="path-apache" name="paths[apache_bin]"
               value="<?= htmlspecialchars($cfg['paths']['apache_bin'] ?? '') ?>"
               placeholder="C:/xampp/apache/bin/httpd.exe" maxlength="500">
        <div class="form-text">Full path to <code>httpd.exe</code> (used for validation and reload).</div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <h3 class="card-title">Defaults</h3>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="defaults[www_alias]" value="1"
                 <?= !empty($cfg['defaults']['www_alias']) ? 'checked' : '' ?>>
          <span class="form-check-label">Add www alias by default</span>
        </label>
      </div>

      <div class="mb-3">
        <label class="form-label">Canonical Protocol</label>
        <select class="form-select" name="defaults[canonical]">
          <option value="https" <?= ($cfg['defaults']['canonical'] ?? 'https') === 'https' ? 'selected' : '' ?>>https</option>
          <option value="http" <?= ($cfg['defaults']['canonical'] ?? '') === 'http' ? 'selected' : '' ?>>http</option>
        </select>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mb-5">
    <button type="submit" class="btn btn-primary" id="btn-save">
      <span class="ti ti-device-floppy me-1"></span>Save Settings
    </button>
  </div>
</form>

<div class="card mb-5">
  <div class="card-header d-flex align-items-center">
    <h3 class="card-title me-auto">Diagnostics</h3>
    <button class="btn btn-sm btn-ghost-secondary" id="btn-diag">
      <span class="ti ti-stethoscope me-1"></span>Run Check
    </button>
  </div>
  <div class="card-body" id="diag-body">
    <p class="text-secondary small mb-0">Click "Run Check" to verify PHP, curl, exec(), Apache binary, and Cloudflare API connectivity.</p>
  </div>
</div>

<script>
(function() {
  const form = document.getElementById('settings-form');
  const testBtn = document.getElementById('btn-test');
  const testResult = document.getElementById('test-result');
  const saveBtn = document.getElementById('btn-save');

  function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // Diagnostics
  document.getElementById('btn-diag').addEventListener('click', async () => {
    const body = document.getElementById('diag-body');
    body.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Running...';
    try {
      const res  = await fetch('api.php?route=/api/debug');
      const text = await res.text();
      let d;
      try { d = JSON.parse(text); } catch (_) {
        body.innerHTML = `<div class="alert alert-danger mb-0"><strong>Invalid JSON response.</strong> Raw output:<pre class="mt-2 mb-0">${escapeHtml(text.slice(0, 500))}</pre></div>`;
        return;
      }
      if (!d.ok) { body.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(d.error)}</div>`; return; }

      function row(label, val, ok) {
        const icon = ok === true ? '<span class="ti ti-check text-success"></span>'
                   : ok === false ? '<span class="ti ti-x text-danger"></span>'
                   : '<span class="ti ti-minus text-secondary"></span>';
        return `<tr><td class="text-secondary">${label}</td><td>${icon} ${escapeHtml(String(val))}</td></tr>`;
      }

      body.innerHTML = `<table class="table table-sm table-borderless mb-0">
        <tbody>
          ${row('PHP version', d.php_version, true)}
          ${row('curl extension', d.curl_loaded ? 'loaded' : 'MISSING — enable extension=curl in php.ini', d.curl_loaded)}
          ${row('exec() available', d.exec_available ? 'yes' : 'BLOCKED — needed for httpd -t', d.exec_available)}
          ${row('Apache binary', d.apache_bin || '(not set)', d.apache_bin_exists)}
          ${row('httpd.conf', d.httpd_conf_path || '(not found)', d.httpd_conf_exists)}
          ${row('vhosts file', d.vhosts_file || '(not set)', d.vhosts_exists)}
          ${row('state/ dir writable', d.state_dir_writable ? 'yes' : 'NO — grant write access', d.state_dir_writable)}
          ${row('Tunnel ID set', d.tunnel_id_set ? 'yes (' + d.tunnel_id_len + ' chars)' : 'MISSING', d.tunnel_id_set)}
          ${row('Cloudflare API', d.cf_zones_reachable ? d.cf_zones_count + ' zone(s) visible' : ('ERROR: ' + (d.cf_error || 'not configured')), d.cf_zones_reachable)}
        </tbody>
      </table>
      ${!d.curl_loaded ? '<div class="alert alert-danger mt-3 mb-0"><strong>curl is not loaded.</strong> Open <code>C:\\xampp\\php\\php.ini</code>, find <code>;extension=curl</code>, remove the semicolon, then restart Apache.</div>' : ''}
      ${!d.exec_available ? '<div class="alert alert-warning mt-3 mb-0"><strong>exec() is disabled.</strong> Apache config validation and reload will be skipped. Check <code>disable_functions</code> in php.ini.</div>' : ''}
      ${!d.cf_zones_reachable && d.cf_configured ? '<div class="alert alert-danger mt-3 mb-0"><strong>Cloudflare API is not reachable.</strong> Check your API token. It needs <strong>Zone:Read</strong> + <strong>DNS:Edit</strong> scopes.</div>' : ''}`;
    } catch (e) {
      body.innerHTML = `<div class="alert alert-danger mb-0">Network error: ${escapeHtml(e.message)}</div>`;
    }
  });

  // Test connection — save config first so form values are used
  testBtn.addEventListener('click', async () => {
    testBtn.disabled = true;
    testResult.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving & testing...';

    try {
      // Save current form values first
      const formData = new FormData(form);
      const token = formData.get('cloudflare[api_token]') || '';
      const accountId = formData.get('cloudflare[account_id]') || '';
      const tunnelId = formData.get('cloudflare[tunnel_id]') || '';

      const payload = {
        cloudflare: { api_token: token, account_id: accountId, tunnel_id: tunnelId, tunnel_name: formData.get('cloudflare[tunnel_name]') || '' },
        paths: { htdocs: formData.get('paths[htdocs]') || '', vhosts_file: formData.get('paths[vhosts_file]') || '', apache_bin: formData.get('paths[apache_bin]') || '' },
        defaults: { www_alias: formData.get('defaults[www_alias]') === '1', canonical: formData.get('defaults[canonical]') || 'https' },
      };

      // Save
      await fetch('api.php?route=/api/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      // Test zones (Zone:Read) then DNS list on first zone (confirms DNS access)
      testResult.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
      const res = await fetch('api.php?route=/api/zones');
      const data = await res.json();
      if (!data.ok) {
        testResult.innerHTML = `<span class="text-danger"><span class="ti ti-x me-1"></span>${escapeHtml(data.error || 'Connection failed')}</span>`;
      } else {
        const zoneCount = data.zones?.length ?? 0;
        testResult.innerHTML = `<span class="text-success"><span class="ti ti-check me-1"></span>Connected — ${zoneCount} zone(s). Check Diagnostics panel to verify DNS:Edit scope.</span>`;
      }
    } catch (e) {
      testResult.innerHTML = `<span class="text-danger"><span class="ti ti-x me-1"></span>Network error: ${escapeHtml(e.message)}</span>`;
    }
    testBtn.disabled = false;
  });

  // Save form
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    const formData = new FormData(form);
    const payload = {
      cloudflare: {
        api_token: formData.get('cloudflare[api_token]') || '',
        account_id: formData.get('cloudflare[account_id]') || '',
        tunnel_id: formData.get('cloudflare[tunnel_id]') || '',
        tunnel_name: formData.get('cloudflare[tunnel_name]') || '',
      },
      paths: {
        htdocs: formData.get('paths[htdocs]') || '',
        vhosts_file: formData.get('paths[vhosts_file]') || '',
        apache_bin: formData.get('paths[apache_bin]') || '',
      },
      defaults: {
        www_alias: formData.get('defaults[www_alias]') === '1',
        canonical: formData.get('defaults[canonical]') || 'https',
      },
    };

    try {
      const res = await fetch('api.php?route=/api/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);

      saveBtn.innerHTML = '<span class="ti ti-check me-1"></span>Saved';
      saveBtn.classList.remove('btn-primary');
      saveBtn.classList.add('btn-success');

      // If there's a first_run flag, redirect to dashboard
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('first_run') === '1') {
        setTimeout(() => { window.location.href = '?page=dashboard'; }, 1000);
      } else {
        setTimeout(() => {
          saveBtn.innerHTML = '<span class="ti ti-device-floppy me-1"></span>Save Settings';
          saveBtn.classList.remove('btn-success');
          saveBtn.classList.add('btn-primary');
          saveBtn.disabled = false;
        }, 2000);
      }

    } catch (err) {
      saveBtn.innerHTML = '<span class="ti ti-x me-1"></span>Error';
      saveBtn.classList.remove('btn-primary');
      saveBtn.classList.add('btn-danger');
      alert('Save failed: ' + err.message);
      setTimeout(() => {
        saveBtn.innerHTML = '<span class="ti ti-device-floppy me-1"></span>Save Settings';
        saveBtn.classList.remove('btn-danger');
        saveBtn.classList.add('btn-primary');
        saveBtn.disabled = false;
      }, 2000);
    }
  });
})();
</script>
