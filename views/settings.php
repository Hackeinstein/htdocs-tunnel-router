<?php
$cfg = Config::load();
$isConfigured = Config::isConfigured();
$firstRun = ($_GET['first_run'] ?? '') === '1';
$v = fn(string $a, string $b) => htmlspecialchars((string)($cfg[$a][$b] ?? ''), ENT_QUOTES);
?>
<?= pageHeader('Settings', 'Cloudflare credentials and system paths') ?>

<?php if ($firstRun): ?>
<div class="mb-4 flex gap-3 rounded-lg border border-blue-300 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">
  <i data-lucide="info"></i><div><b>Welcome!</b> This is your first run. Enter your Cloudflare API credentials and tunnel details to get started.</div>
</div>
<?php endif; ?>

<form id="form" autocomplete="off" class="grid max-w-3xl gap-6">
  <div class="card">
    <h2 class="card-h">Cloudflare</h2>
    <div class="grid gap-4">
      <div><label class="label" for="token">API token</label>
        <input type="password" class="input" id="token" name="cloudflare[api_token]" maxlength="255" autocomplete="off"
               placeholder="<?= $isConfigured ? '•••••••• (leave blank to keep current)' : 'Token with Zone:Read + DNS:Edit' ?>">
        <p class="hint">Needs <b>Zone:Read</b> + <b>DNS:Edit</b>. For the Domains and Tunnels pages also add <b>Analytics:Read</b>, <b>Zone Settings:Edit</b>, <b>Cache Purge</b> and <b>Cloudflare Tunnel:Read</b>. Stored server-side, never logged or returned.</p></div>
      <div><label class="label" for="acct">Account ID</label>
        <input class="input" id="acct" name="cloudflare[account_id]" value="<?= $v('cloudflare', 'account_id') ?>" maxlength="64" placeholder="Cloudflare Account ID"></div>
      <div><label class="label" for="tid">Tunnel ID</label>
        <input class="input" id="tid" name="cloudflare[tunnel_id]" value="<?= $v('cloudflare', 'tunnel_id') ?>" maxlength="64" placeholder="UUID of the Cloudflare Tunnel"></div>
      <div><label class="label" for="tname">Tunnel name <span class="muted">(optional)</span></label>
        <input class="input" id="tname" name="cloudflare[tunnel_name]" value="<?= $v('cloudflare', 'tunnel_name') ?>" maxlength="100" placeholder="Shown in the sidebar"></div>
      <div class="flex items-center gap-3"><button type="button" class="btn btn-outline" id="test"><i data-lucide="plug"></i>Test connection</button><span class="text-sm" id="test-result"></span></div>
    </div>
  </div>

  <div class="card">
    <h2 class="card-h">Paths</h2>
    <div class="grid gap-4">
      <div><label class="label" for="htdocs">htdocs folder</label><input class="input" id="htdocs" name="paths[htdocs]" value="<?= $v('paths', 'htdocs') ?>" maxlength="500" placeholder="C:/xampp/htdocs"></div>
      <div><label class="label" for="vhosts">VHosts config file</label><input class="input" id="vhosts" name="paths[vhosts_file]" value="<?= $v('paths', 'vhosts_file') ?>" maxlength="500" placeholder="C:/xampp/apache/conf/extra/httpd-vhosts.conf"></div>
      <div><label class="label" for="apache">Apache binary</label><input class="input" id="apache" name="paths[apache_bin]" value="<?= $v('paths', 'apache_bin') ?>" maxlength="500" placeholder="C:/xampp/apache/bin/httpd.exe">
        <p class="hint">Full path to <code>httpd.exe</code> (used for validation and reload).</p></div>
    </div>
  </div>

  <div class="card">
    <h2 class="card-h">Defaults</h2>
    <div class="grid gap-4">
      <label class="flex items-center gap-3"><?= '' ?><span id="www-slot"></span><span class="text-sm">Add www alias by default</span></label>
      <div><label class="label" for="canon">Canonical protocol</label>
        <select class="input" id="canon" name="defaults[canonical]">
          <?php foreach (['https', 'http'] as $p): ?><option <?= ($cfg['defaults']['canonical'] ?? 'https') === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
        </select></div>
    </div>
  </div>

  <div class="card">
    <h2 class="card-h">Expiry</h2>
    <label class="label" for="expact">When a route expires</label>
    <select class="input" id="expact" name="defaults[expiry_action]">
      <option value="disable" <?= ($cfg['defaults']['expiry_action'] ?? 'disable') === 'disable' ? 'selected' : '' ?>>Disable it (keeps DNS and vhost, easy to re-enable)</option>
      <option value="detach" <?= ($cfg['defaults']['expiry_action'] ?? '') === 'detach' ? 'selected' : '' ?>>Detach it (deletes the DNS record and vhost)</option>
    </select>
    <p class="hint">Checked once a minute while any Tunnel Router page is open (and on every dashboard load). If no page is open, a route stays up past its expiry until the next time you visit.</p>
  </div>

  <div class="card">
    <h2 class="card-h">Access</h2>
    <p class="muted mb-3 text-sm">This tool can edit files and holds your Cloudflare token, so it only answers a browser <b>on this computer</b>, opened at <code>localhost</code>, <code>127.0.0.1</code> or <code>[::1]</code>. Requests that arrive through the tunnel, or under any other name, are refused.</p>
    <label class="label" for="hosts">Extra local names <span class="muted">(optional, comma separated)</span></label>
    <input class="input" id="hosts" name="security[allowed_hosts]" value="<?= $v('security', 'allowed_hosts') ?>" maxlength="300" placeholder="e.g. router.test">
    <p class="hint">Only for names that point at 127.0.0.1 in your <code>hosts</code> file. <b>Never</b> add a name that is routed through the tunnel.</p>
  </div>

  <div class="card" id="portal">
    <h2 class="card-h">File portal</h2>
    <p class="muted mb-3 text-sm">A separate public web address where the people on the <a class="text-indigo-600 hover:underline" href="?page=users">Users</a> page log in with a username and password, and see only the sites you gave them. This admin tool is <b>never</b> reachable from it.</p>
    <div id="portal-state" class="mb-4 text-sm">Loading…</div>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label class="label" for="pm1">Largest single upload (MB)</label><input class="input" id="pm1" name="portal[upload_mb]" type="number" min="1" max="1024" value="<?= (int)($cfg['portal']['upload_mb'] ?? 25) ?>"></div>
      <div><label class="label" for="pm2">Most a user can store per site (MB)</label><input class="input" id="pm2" name="portal[site_mb]" type="number" min="1" max="102400" value="<?= (int)($cfg['portal']['site_mb'] ?? 500) ?>"></div>
    </div>
    <p class="hint">Applies when you press <b>Save settings</b>. PHP's own <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini also cap uploads.</p>
  </div>

  <div class="card" id="reload">
    <h2 class="card-h">Apache reload</h2>
    <p class="muted mb-4 text-sm">After you attach, detach or switch a route, the new vhost is checked with <code>httpd -t</code> and Apache is restarted automatically. Each restart is <b>confirmed in Apache's own error log</b>, and the result is shown here and in the banner. Methods are tried in this order until one is confirmed:</p>
    <ol class="mb-4 list-decimal space-y-1 pl-5 text-sm"><li><b>Graceful restart</b> (<code>httpd -k restart</code>) — always tried.</li>
      <li><b>Elevated restart</b> — same command through a UAC prompt. Skipped if you turn it off below.</li>
      <li><b>Hard restart</b> — stops and starts <code>httpd.exe</code>. Off by default.</li></ol>
    <div class="grid gap-3">
      <label class="flex items-center gap-3"><span id="el-slot"></span><span class="text-sm">Allow a UAC prompt<span class="hint block">Starting the XAMPP Control Panel "as administrator" means this never prompts.</span></span></label>
      <label class="flex items-center gap-3"><span id="hd-slot"></span><span class="text-sm">Allow a hard restart as a last resort<span class="hint block">The XAMPP Control Panel may show Apache as stopped afterwards until you click Start/Refresh.</span></span></label>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3"><button type="button" class="btn btn-outline" id="reload-test"><i data-lucide="refresh-cw"></i>Test reload now</button>
      <span class="muted text-xs">Restarts Apache for a moment. Save your settings first.</span></div>
    <div id="reload-out" class="mt-4 text-sm"></div>
  </div>

  <div><button type="submit" class="btn btn-primary" id="save"><i data-lucide="save"></i>Save settings</button></div>
</form>

<div class="card mt-8 max-w-3xl">
  <div class="mb-3 flex items-center justify-between"><h2 class="card-h !mb-0">Diagnostics</h2>
    <button class="btn btn-outline" id="diag"><i data-lucide="stethoscope"></i>Run check</button></div>
  <div id="diag-body" class="muted text-sm">Verify PHP, curl, exec(), the Apache binary and Cloudflare API connectivity.</div>
</div>

<script>
(() => {
  const E = UI.esc, form = document.getElementById('form');
  document.getElementById('www-slot').innerHTML = UI.toggle('www', <?= json_encode(!empty($cfg['defaults']['www_alias'])) ?>);
  document.getElementById('el-slot').innerHTML = UI.toggle('reload_elevate', <?= json_encode($cfg['defaults']['reload_elevate'] ?? true) ?>);
  document.getElementById('hd-slot').innerHTML = UI.toggle('reload_hard', <?= json_encode($cfg['defaults']['reload_hard'] ?? false) ?>);

  const payload = () => {
    const f = new FormData(form);
    return {
      cloudflare: { api_token: f.get('cloudflare[api_token]') || '', account_id: f.get('cloudflare[account_id]') || '', tunnel_id: f.get('cloudflare[tunnel_id]') || '', tunnel_name: f.get('cloudflare[tunnel_name]') || '' },
      portal: { upload_mb: +f.get('portal[upload_mb]') || 25, site_mb: +f.get('portal[site_mb]') || 500 },
      security: { allowed_hosts: f.get('security[allowed_hosts]') || '' },
      paths: { htdocs: f.get('paths[htdocs]') || '', vhosts_file: f.get('paths[vhosts_file]') || '', apache_bin: f.get('paths[apache_bin]') || '' },
      defaults: { www_alias: document.getElementById('www').checked, canonical: f.get('defaults[canonical]') || 'https', expiry_action: f.get('defaults[expiry_action]') || 'disable',
                   reload_elevate: document.getElementById('reload_elevate').checked, reload_hard: document.getElementById('reload_hard').checked },
    };
  };

  document.getElementById('test').onclick = e => UI.busy(e.currentTarget, async () => {
    const out = document.getElementById('test-result');
    await UI.post('/api/config', payload());           // save first so the test uses the form values
    try {
      const d = await UI.api('/api/zones');
      out.innerHTML = `<span class="text-green-600">${UI.i('check', 'mr-1')}Connected — ${d.zones.length} zone(s). Run diagnostics to verify DNS:Edit.</span>`;
    } catch (err) { out.innerHTML = `<span class="text-red-600">${UI.i('x', 'mr-1')}${E(err.message)}</span>`; }
  });

  form.onsubmit = async ev => {
    ev.preventDefault();
    const b = document.getElementById('save');
    await UI.busy(b, async () => {
      await UI.post('/api/config', payload());
      UI.toast('Settings saved');
      if (new URLSearchParams(location.search).get('first_run') === '1') setTimeout(() => location.href = '?page=dashboard', 800);
    });
  };


  // ---- File portal: address on/off / change
  async function portalState(msg, bad) {
    const box = document.getElementById('portal-state');
    const note = msg ? `<div class="mb-3 rounded-lg border p-3 ${bad ? 'border-red-300 bg-red-50 text-red-800' : 'border-yellow-300 bg-yellow-50 text-yellow-800'}" role="alert">${msg}</div>` : '';
    try {
      const { portal } = await UI.api('/api/portal');
      const turnOff = async (btn, then) => {
        if (!await UI.confirm('Turn the file portal off?', 'The address stops working and its DNS record and Apache entry are removed. Users and their access are kept.', 'Turn off')) return;
        UI.busy(btn, async () => {
          const r = await UI.post('/api/portal/disable');
          const w = (r.warnings || []).map(E).join('<br>');
          if (then) then(w); else { UI.toast('Portal turned off'); portalState(w || '', false); }
        });
      };
      if (portal.hostname) {
        box.innerHTML = note + `<div class="flex flex-wrap items-center gap-3">${UI.badge('green', 'On')}<a class="font-medium text-indigo-600 hover:underline" href="https://${E(portal.hostname)}/" target="_blank" rel="noopener">https://${E(portal.hostname)}/</a>
          <span class="ml-auto flex gap-2"><button type="button" class="btn btn-outline" id="p-change">Change address</button><button type="button" class="btn btn-outline" id="p-off">Turn off</button></span></div>`;
        document.getElementById('p-off').onclick = e => turnOff(e.currentTarget);
        document.getElementById('p-change').onclick = e => turnOff(e.currentTarget, w => portalState(w, false));      // off, then straight to the chooser
        return;
      }
      box.innerHTML = note + `<p class="mb-3">${UI.badge('gray', 'Off')} Choose the address people will log in at.</p>
        <div class="flex flex-wrap items-end gap-3"><div><label class="label" for="p-zone">Domain</label><select class="input" id="p-zone"><option value="">Loading…</option></select></div>
          <div><label class="label" for="p-sub">Name</label><input class="input" id="p-sub" value="files" maxlength="63" autocomplete="off"></div>
          <button type="button" class="btn btn-primary" id="p-on">Turn on</button></div><div id="p-err" class="mt-3 text-sm"></div>`;
      UI.api('/api/zones').then(d => { document.getElementById('p-zone').innerHTML = '<option value="">— choose —</option>' + d.zones.map(z => `<option value="${E(z.id)}">${E(z.name)}</option>`).join(''); })
        .catch(err => { document.getElementById('p-zone').innerHTML = '<option value="">No domains available</option>'; document.getElementById('p-err').innerHTML = `<span class="text-red-700">Could not load your domains: ${E(err.message)}. Check the Cloudflare token under <a class="underline" href="#top">Cloudflare</a> above (it needs Zone → Read), save, then reload.</span>`; });
      document.getElementById('p-on').onclick = async e => {
        const btn = e.currentTarget, err = document.getElementById('p-err'); err.textContent = ''; btn.disabled = true;
        try {
          const r = await UI.post('/api/portal/enable', { zone_id: document.getElementById('p-zone').value, subdomain: document.getElementById('p-sub').value.trim().toLowerCase() });
          UI.toast('Portal is on at ' + (r.hostname || '')); portalState();
        } catch (ex) { err.innerHTML = `<span class="text-red-700">${E(ex.message)}</span> <span class="muted">Nothing was changed, so you can fix this and try again.</span>`; }
        finally { btn.disabled = false; }
      };
    } catch (err) { box.innerHTML = UI.errBox(err.message); }
  }
  portalState();

  // ---- Apache reload: test + last result
  const showRl = r => {
    const out = document.getElementById('reload-out');
    if (!r) { out.innerHTML = '<span class="muted">No reload has run yet.</span>'; return; }
    const c = { ok: 'green', failed: 'red', skipped: 'yellow', pending: 'blue', running: 'blue' }[r.state] || 'gray';
    out.innerHTML = `<div class="mb-2">${UI.badge(c, E(r.state))} <span class="muted text-xs">${r.requested_at ? new Date(r.requested_at * 1000).toLocaleString() : ''}</span></div>
      ${r.detail ? `<p class="mb-2">${E(r.detail)}</p>` : ''}
      ${(r.attempts || []).length ? `<table class="tbl"><thead><tr><th>Method</th><th>Exit</th><th>Confirmed</th><th>Output</th></tr></thead><tbody>${r.attempts.map(a =>
        `<tr><td>${E(a.method)}</td><td>${a.exit}</td><td>${a.confirmed ? UI.badge('green', 'yes · ' + E(a.verified_by)) : UI.badge('red', 'no')}</td><td class="max-w-xs truncate" title="${E(a.output)}">${E(a.output) || '—'}</td></tr>`).join('')}</tbody></table>` : ''}`;
  };
  const pollRl = async () => { for (let i = 0; i < 90; i++) { const r = (await UI.api('/api/reload/status')).reload; showRl(r); if (!r || !['pending', 'running'].includes(r.state)) return; await new Promise(res => setTimeout(res, 2000)); } };
  document.getElementById('reload-test').onclick = e => UI.busy(e.currentTarget, async () => { await UI.post('/api/reload/test'); await pollRl(); });
  UI.api('/api/reload/status').then(r => { showRl(r.reload); if (r.reload && ['pending', 'running'].includes(r.reload.state)) pollRl(); }).catch(() => {});

  document.getElementById('diag').onclick = e => UI.busy(e.currentTarget, async () => {
    const body = document.getElementById('diag-body');
    const d = await UI.api('/api/debug');
    const row = (l, v, ok) => `<tr><td class="muted">${l}</td><td>${UI.i(ok === true ? 'check' : ok === false ? 'x' : 'minus', ok === true ? 'text-green-600 mr-1' : ok === false ? 'text-red-600 mr-1' : 'mr-1')}${E(String(v))}</td></tr>`;
    body.innerHTML = `<table class="tbl"><tbody>
      ${row('PHP version', d.php_version + (d.php_ok ? '' : ' — 8.1 or newer is required'), d.php_ok)}
      ${Object.entries(d.extensions || {}).map(([k, v]) => row('Extension ' + k, v ? 'loaded' : 'MISSING — enable extension=' + k + ' in php.ini', v)).join('')}
      ${row('exec / popen / proc_open', (d.functions_blocked || []).length ? 'BLOCKED: ' + d.functions_blocked.join(', ') + ' (check disable_functions)' : 'available', !(d.functions_blocked || []).length)}
      ${row('php.exe for Apache reload', d.php_exe_for_reload ? 'found' : 'NOT FOUND next to Apache (expected <xampp>\\php\\php.exe)', d.php_exe_for_reload)}
      ${row('curl extension', d.curl_loaded ? 'loaded' : 'MISSING — enable extension=curl in php.ini', d.curl_loaded)}
      ${row('exec() available', d.exec_available ? 'yes' : 'BLOCKED — needed for httpd -t', d.exec_available)}
      ${row('Apache binary', d.apache_bin || '(not set)', d.apache_bin_exists)}
      ${row('httpd.conf', d.httpd_conf_path || '(not found)', d.httpd_conf_exists)}
      ${row('vhosts file', d.vhosts_file || '(not set)', d.vhosts_exists)}
      ${row('state/ writable', d.state_dir_writable ? 'yes' : 'NO — grant write access', d.state_dir_writable)}
      ${row('Tunnel ID set', d.tunnel_id_set ? 'yes (' + d.tunnel_id_len + ' chars)' : 'MISSING', d.tunnel_id_set)}
      ${row('Cloudflare API', d.cf_zones_reachable ? d.cf_zones_count + ' zone(s) visible' : 'ERROR: ' + (d.cf_error || 'not configured'), d.cf_zones_reachable)}
    </tbody></table>
    ${!d.curl_loaded ? UI.errBox('curl is not loaded. Enable extension=curl in php.ini and restart Apache.') : ''}
    ${!d.exec_available ? UI.warn('exec() is disabled — Apache validation and reload will be skipped. Check disable_functions in php.ini.') : ''}`;
  });
})();
</script>
