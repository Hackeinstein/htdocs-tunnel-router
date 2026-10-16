/* File portal front end: login / invitation / site list / file manager. No inline handlers (the page's CSP forbids them),
   and every string from the server goes through esc() before it reaches innerHTML. */
(() => {
  const ICONS = window.__ICONS || {};
  const ic = (n, c) => `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide ${c || ''}" aria-hidden="true">${ICONS[n] || ''}</svg>`;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const bytes = b => { const u = ['B', 'KB', 'MB', 'GB']; let i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return b.toFixed(i ? 1 : 0) + ' ' + u[i]; };
  const app = document.getElementById('app');
  const S = { csrf: '', user: null, limits: { upload_mb: 25 }, sites: [], site: null, path: '', entries: [], grant: {} };
  const IMG = /\.(png|jpe?g|gif|webp)$/i;

  async function api(route, { method = 'GET', query = {}, body, form } = {}) {
    const q = new URLSearchParams({ route, ...query });
    const opt = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } };
    if (form) opt.body = form; else if (body) { opt.body = JSON.stringify(body); opt.headers['Content-Type'] = 'application/json'; }
    let r, d;
    try { r = await fetch('api.php?' + q, opt); d = await r.json(); } catch (e) { throw new Error('Could not reach the server. Check your connection and try again.'); }
    if (!d.ok) { const err = new Error(d.error || 'Something went wrong.'); err.status = r.status; err.data = d; throw err; }
    return d;
  }

  function toast(msg, bad) {
    const t = document.createElement('div');
    t.className = 'fixed right-4 bottom-4 z-50 max-w-sm rounded-lg px-4 py-3 text-sm text-white shadow-lg ' + (bad ? 'bg-red-600' : 'bg-gray-900');
    t.textContent = msg; t.setAttribute('role', 'status'); document.body.appendChild(t);
    setTimeout(() => t.remove(), bad ? 6000 : 2800);
  }
  const busy = async (btn, fn) => { btn.disabled = true; try { return await fn(); } catch (e) { toast(e.message, true); } finally { btn.disabled = false; } };

  function dialog(html) {
    const d = document.createElement('dialog');
    d.className = 'm-auto w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 text-gray-900 shadow-xl backdrop:bg-black/40';
    d.innerHTML = html; document.body.appendChild(d); d.addEventListener('close', () => d.remove()); d.showModal(); return d;
  }
  const ask = (title, text, ok) => new Promise(res => {
    const d = dialog(`<h3 class="text-lg font-semibold">${esc(title)}</h3><p class="mt-2 text-sm text-gray-600">${esc(text)}</p>
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" data-v="0">Cancel</button><button class="btn btn-danger" data-v="1">${esc(ok)}</button></div>`);
    d.querySelectorAll('[data-v]').forEach(b => b.onclick = () => { d.close(); res(b.dataset.v === '1'); });
    d.addEventListener('cancel', () => res(false));
  });
  const prompt2 = (title, label, value, ok) => new Promise(res => {
    const d = dialog(`<h3 class="text-lg font-semibold">${esc(title)}</h3><label class="label mt-3" for="pv">${esc(label)}</label><input class="input" id="pv" maxlength="200" value="${esc(value)}" autocomplete="off">
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" data-v="0">Cancel</button><button class="btn btn-primary" data-v="1">${esc(ok)}</button></div>`);
    const inp = d.querySelector('#pv'); inp.focus(); inp.select();
    const done = v => { d.close(); res(v); };
    d.querySelector('[data-v="0"]').onclick = () => done(null);
    d.querySelector('[data-v="1"]').onclick = () => done(inp.value.trim());
    inp.onkeydown = e => { if (e.key === 'Enter') done(inp.value.trim()); };
    d.addEventListener('cancel', () => res(null));
  });

  const shell = inner => `<div class="mb-6 flex items-center justify-between gap-3"><div class="flex items-center gap-2 text-lg font-semibold">${ic('folder-code', '!size-6 text-indigo-600')}File portal</div>
    ${S.user ? `<div class="flex items-center gap-3 text-sm"><span class="text-gray-600">${esc(S.user.display || S.user.username)}</span><button class="btn btn-outline" data-act="logout">${ic('log-out')}Sign out</button></div>` : ''}</div>${inner}`;
  const box = inner => `<div class="mx-auto mt-10 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-xs">${inner}</div>`;
  const wire = () => app.querySelectorAll('[data-act="logout"]').forEach(b => b.onclick = () => busy(b, async () => { await api('/logout', { method: 'POST' }); await boot(); }));

  // ---- login & invitation -------------------------------------------------
  function loginView(msg) {
    app.innerHTML = shell(box(`<h1 class="text-xl font-semibold">Log in</h1>
      ${msg ? `<p class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">${esc(msg)}</p>` : ''}
      <form id="lf" class="mt-4 grid gap-3"><div><label class="label" for="u">Username</label><input class="input" id="u" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="64"></div>
        <div><label class="label" for="p">Password</label><input class="input" id="p" type="password" autocomplete="current-password" required maxlength="1024"></div>
        <button class="btn btn-primary" id="go">Log in</button></form>`));
    const u = document.getElementById('u'); u.focus();
    document.getElementById('lf').onsubmit = async e => {
      e.preventDefault(); const go = document.getElementById('go'); go.disabled = true;
      try { const d = await api('/login', { method: 'POST', body: { username: u.value, password: document.getElementById('p').value } }); S.csrf = d.csrf; S.user = d.user; await sitesView(); }
      catch (err) { loginView(err.message); }
    };
  }

  async function inviteView(token) {
    let who;
    try { who = await api('/invite/check', { query: { token } }); }
    catch (e) { history.replaceState(null, '', location.pathname); return loginView(e.message); }
    app.innerHTML = shell(box(`<h1 class="text-xl font-semibold">Welcome, ${esc(who.display || who.username)}</h1>
      <p class="mt-1 text-sm text-gray-600">Choose a password for <b>${esc(who.username)}</b>.</p>
      <p id="em" class="mt-3 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert"></p>
      <form id="inv" class="mt-4 grid gap-3"><input type="text" value="${esc(who.username)}" autocomplete="username" hidden readonly>
        <div><label class="label" for="p1">Password</label><input class="input" id="p1" type="password" autocomplete="new-password" required minlength="10" maxlength="1024"><p class="hint">At least 10 characters. A few random words work well.</p></div>
        <div><label class="label" for="p2">Repeat password</label><input class="input" id="p2" type="password" autocomplete="new-password" required maxlength="1024"></div>
        <button class="btn btn-primary" id="go">Set password and continue</button></form>`));
    const em = document.getElementById('em');
    document.getElementById('inv').onsubmit = async e => {
      e.preventDefault(); em.classList.add('hidden');
      const p1 = document.getElementById('p1').value;
      if (p1 !== document.getElementById('p2').value) { em.textContent = 'The two passwords are different.'; return em.classList.remove('hidden'); }
      const go = document.getElementById('go'); go.disabled = true;
      try { const d = await api('/invite/accept', { method: 'POST', body: { token, password: p1 } }); S.csrf = d.csrf; S.user = d.user; history.replaceState(null, '', location.pathname); await sitesView(); }
      catch (err) { em.textContent = err.message; em.classList.remove('hidden'); go.disabled = false; }
    };
  }

  // ---- sites ----------------------------------------------------------------
  async function sitesView() {
    try { S.sites = (await api('/sites')).sites; } catch (e) { if (e.status === 401) return loginView(); return app.innerHTML = shell(`<p class="text-red-700">${esc(e.message)}</p>`), wire(); }
    if (S.sites.length === 1) return openSite(S.sites[0]);
    app.innerHTML = shell(`<h1 class="mb-4 text-xl font-semibold">Your sites</h1>` + (S.sites.length
      ? `<div class="grid gap-3 sm:grid-cols-2">${S.sites.map((s, i) => `<button class="rounded-lg border border-gray-200 bg-white p-4 text-left hover:border-indigo-400" data-site="${i}"><div class="flex items-center gap-2 font-medium">${ic('globe', 'text-indigo-600')}${esc(s.host)}</div>
          <div class="mt-1 text-xs text-gray-500">view${['upload', 'edit', 'delete'].filter(k => s.grant[k]).map(k => ' · ' + k).join('')}${s.until ? ' · until ' + new Date(s.until * 1000).toLocaleDateString() : ''}</div></button>`).join('')}</div>`
      : `<div class="rounded-lg border border-gray-200 bg-white p-8 text-center text-gray-600">${ic('folder-lock', '!size-10 mx-auto text-gray-400')}<p class="mt-3">You don't have access to any site right now.</p><p class="text-sm">Ask whoever invited you.</p></div>`));
    wire(); app.querySelectorAll('[data-site]').forEach(b => b.onclick = () => openSite(S.sites[+b.dataset.site]));
  }

  async function openSite(site) { S.site = site; S.path = ''; await listView(); }

  // ---- file manager -----------------------------------------------------------
  const fq = (extra = {}) => ({ host: S.site.host, ...extra });
  const join = (d, n) => (d ? d + '/' : '') + n;

  async function listView(keepMsg) {
    try { const d = await api('/fs/list', { query: fq({ path: S.path }) }); S.entries = d.entries; S.grant = d.grant; }
    catch (e) { if (e.status === 401) return loginView('Please log in again.'); toast(e.message, true); if (S.path) { S.path = ''; return listView(); } return sitesView(); }
    const g = S.grant, parts = S.path ? S.path.split('/') : [];
    const crumbs = [`<button class="text-indigo-600 hover:underline" data-go="">${esc(S.site.host)}</button>`]
      .concat(parts.map((p, i) => i === parts.length - 1 ? `<span class="font-medium">${esc(p)}</span>` : `<button class="text-indigo-600 hover:underline" data-go="${esc(parts.slice(0, i + 1).join('/'))}">${esc(p)}</button>`)).join(' <span class="text-gray-400">/</span> ');
    const rows = [...S.entries].sort((a, b) => (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1) || a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
    app.innerHTML = shell(`
      <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">${S.sites.length > 1 ? `<button class="btn btn-ghost" data-act="sites">${ic('arrow-left')}Sites</button>` : ''}<div class="flex flex-wrap items-center gap-1">${crumbs}</div>
        <div class="ml-auto flex flex-wrap gap-2">
          ${g.upload ? `<button class="btn btn-primary" data-act="upload">${ic('upload')}Upload</button><input type="file" id="pick" multiple hidden>` : ''}
          <a class="btn btn-outline" href="api.php?${new URLSearchParams({ route: '/fs/zip', host: S.site.host, path: S.path })}" title="Download everything in this folder as one zip">${ic('archive')}Download as zip</a>
          ${g.upload ? `<button class="btn btn-outline" data-act="newfile">${ic('file-plus')}New file</button><button class="btn btn-outline" data-act="newdir">${ic('folder-plus')}New folder</button>` : ''}</div></div>
      <div id="drop" class="rounded-lg border border-gray-200 bg-white ${g.upload ? '' : ''}">
        ${rows.length ? `<div class="overflow-x-auto"><table class="tbl"><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th></th></tr></thead><tbody>${rows.map(rowHtml).join('')}</tbody></table></div>`
          : `<div class="p-10 text-center text-gray-500">${ic('folder-open', '!size-10 mx-auto text-gray-300')}<p class="mt-2">This folder is empty.${g.upload ? ' Drop files here to upload.' : ''}</p></div>`}</div>
      <p class="mt-3 text-xs text-gray-500">${g.upload ? `Files up to ${S.limits.upload_mb} MB. ` : ''}${g.edit ? '' : 'You can view and download. '}Some file types (programs and settings files) are protected and can't be opened or uploaded.</p>`);
    wire(); bindList(); if (keepMsg) toast(keepMsg);
  }

  function rowHtml(e) {
    const g = S.grant, p = join(S.path, e.name), dir = e.type === 'dir';
    const nm = dir ? `<button class="flex items-center gap-2 font-medium text-gray-900 hover:text-indigo-600" data-go="${esc(p)}">${ic('folder', 'text-indigo-500')}${esc(e.name)}</button>`
      : `<span class="flex items-center gap-2 ${e.locked ? 'text-gray-400' : ''}">${ic(e.locked ? 'lock' : 'file', e.locked ? 'text-gray-400' : 'text-gray-500')}${esc(e.name)}${e.locked ? ' <span class="badge badge-gray">protected</span>' : ''}</span>`;
    const a = [];
    const zipUrl = q => `api.php?${new URLSearchParams({ route: '/fs/zip', host: S.site.host, path: q })}`;
    if (dir) a.push(`<a class="btn btn-ghost" href="${esc(zipUrl(p))}" title="Download folder as zip">${ic('archive')}</a>`);
    if (!dir && !e.locked && g.upload && /\.zip$/i.test(e.name)) a.push(`<button class="btn btn-ghost" data-row="${esc(p)}" data-name="${esc(e.name)}" data-act="unzip" title="Extract this zip">${ic('package-open')}</button>`);
    if (!dir && !e.locked) {
      a.push(`<button class="btn btn-ghost" data-row="${esc(p)}" data-act="open" title="${g.edit ? 'Edit' : 'View'}">${ic(IMG.test(e.name) ? 'image' : g.edit ? 'pencil' : 'eye')}</button>`);
      a.push(`<a class="btn btn-ghost" href="api.php?${new URLSearchParams({ route: '/fs/download', host: S.site.host, path: p })}" title="Download">${ic('download')}</a>`);
    }
    if (!e.locked && g.edit) a.push(`<button class="btn btn-ghost" data-row="${esc(p)}" data-name="${esc(e.name)}" data-act="rename" title="Rename">${ic('text-cursor-input')}</button>`);
    if (!e.locked && g.delete) a.push(`<button class="btn btn-ghost-danger" data-row="${esc(p)}" data-name="${esc(e.name)}" data-act="delete" title="Delete">${ic('trash')}</button>`);
    return `<tr><td>${nm}</td><td class="text-gray-500">${dir ? '—' : bytes(e.size)}</td><td class="text-gray-500">${e.mtime ? new Date(e.mtime * 1000).toLocaleString() : ''}</td><td class="text-right">${a.join('')}</td></tr>`;
  }

  function bindList() {
    app.querySelectorAll('[data-go]').forEach(b => b.onclick = () => { S.path = b.dataset.go; listView(); });
    const act = (a, fn) => app.querySelectorAll(`[data-act="${a}"]`).forEach(b => b.onclick = () => fn(b));
    act('sites', () => sitesView());
    act('open', b => openFile(b.dataset.row));
    act('rename', b => busy(b, async () => {
      const to = await prompt2('Rename', 'New name', b.dataset.name, 'Rename'); if (!to || to === b.dataset.name) return;
      await api('/fs/rename', { method: 'POST', body: { host: S.site.host, from: b.dataset.row, to: join(S.path, to) } }); await listView('Renamed');
    }));
    act('delete', b => busy(b, async () => {
      if (!await ask('Delete ' + b.dataset.name + '?', 'It is moved to the site\'s trash. The site owner can restore it.', 'Delete')) return;
      await api('/fs/delete', { method: 'POST', body: { host: S.site.host, paths: [b.dataset.row] } }); await listView('Deleted');
    }));
    act('unzip', b => busy(b, async () => {
      const here = await new Promise(res => {
        const d = dialog(`<h3 class="text-lg font-semibold">Extract ${esc(b.dataset.name)}</h3><p class="mt-2 text-sm text-gray-600">Where should the files go?</p>
          <div class="mt-4 grid gap-2"><button class="btn btn-primary" data-v="new">Into a new folder “${esc(b.dataset.name.replace(/\.zip$/i, ''))}”</button>
            <button class="btn btn-outline" data-v="here">Here, in this folder${S.grant.edit ? ' (replaces files with the same name)' : ''}</button><button class="btn btn-ghost" data-v="no">Cancel</button></div>`);
        d.querySelectorAll('[data-v]').forEach(x => x.onclick = () => { d.close(); res(x.dataset.v); });
        d.addEventListener('cancel', () => res('no'));
      });
      if (here === 'no') return;
      toast('Extracting…');
      const r = await api('/fs/unzip', { method: 'POST', body: { host: S.site.host, path: b.dataset.row, here: here === 'here' } });
      await listView(r.files + ' file(s) extracted' + (r.skipped_n ? ', ' + r.skipped_n + ' skipped (programs, hidden or protected files)' : ''));
    }));
    act('newfile', b => busy(b, async () => { const n = await prompt2('New file', 'File name', '', 'Create'); if (!n) return; await api('/fs/create', { method: 'POST', body: { host: S.site.host, path: join(S.path, n) } }); await listView('Created'); }));
    act('newdir', b => busy(b, async () => { const n = await prompt2('New folder', 'Folder name', '', 'Create'); if (!n) return; await api('/fs/mkdir', { method: 'POST', body: { host: S.site.host, path: join(S.path, n) } }); await listView('Created'); }));
    const pick = document.getElementById('pick');
    if (pick) { act('upload', () => pick.click()); pick.onchange = () => upload([...pick.files]); }
    const drop = document.getElementById('drop');
    if (drop && S.grant.upload) {
      ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('ring-2', 'ring-indigo-400'); }));
      ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('ring-2', 'ring-indigo-400'); }));
      drop.addEventListener('drop', e => upload([...e.dataTransfer.files]));
    }
  }

  async function upload(files) {
    if (!files.length) return;
    const over = files.filter(f => f.size > S.limits.upload_mb * 1048576);
    if (over.length) toast(over.map(f => f.name).join(', ') + ' is larger than ' + S.limits.upload_mb + ' MB and was skipped.', true);
    files = files.filter(f => !over.includes(f)); if (!files.length) return;
    let overwrite = false;
    if (S.grant.edit && S.entries.some(e => files.some(f => f.name === e.name))) overwrite = await ask('Replace existing files?', 'Some files already exist here. Replacing overwrites them.', 'Replace');
    toast('Uploading ' + files.length + ' file(s)…');
    const form = new FormData(); form.append('host', S.site.host); form.append('dir', S.path); if (overwrite) form.append('overwrite', '1');
    files.forEach(f => form.append('files[]', f));
    try {
      const d = await api('/fs/upload', { method: 'POST', form });
      const bad = d.results.filter(r => !r.ok);
      await listView(bad.length ? null : d.results.length + ' uploaded');
      bad.forEach(r => toast(r.id + ': ' + r.error, true));
    } catch (e) { toast(e.message, true); }
  }

  // ---- open / edit a file -------------------------------------------------------
  async function openFile(path) {
    if (IMG.test(path)) {
      const d = dialog(`<h3 class="truncate text-lg font-semibold">${esc(path.split('/').pop())}</h3><img class="mt-3 max-h-[70vh] max-w-full rounded" alt="" src="api.php?${new URLSearchParams({ route: '/fs/raw', host: S.site.host, path })}">
        <div class="mt-4 flex justify-end"><button class="btn btn-outline" id="x">Close</button></div>`);
      d.style.maxWidth = '48rem'; d.querySelector('#x').onclick = () => d.close(); return;
    }
    let f;
    try { f = await api('/fs/read', { query: fq({ path }) }); } catch (e) { return toast(e.message, true); }
    const name = path.split('/').pop();
    if (!f.editable) return toast(f.reason || 'This file can only be downloaded.', true);
    const can = !!S.grant.edit;
    const d = dialog(`<h3 class="truncate text-lg font-semibold">${esc(name)}</h3>
      <div id="ed" class="mt-3 h-[60vh] overflow-hidden rounded-lg border border-gray-300"><p class="p-4 text-sm text-gray-500">Loading editor…</p></div>
      <div class="mt-4 flex items-center justify-end gap-2"><span class="mr-auto text-xs text-gray-500">${can ? '' : 'Read only — you can view this file but not change it.'}</span>
        <button class="btn btn-outline" id="x">${can ? 'Cancel' : 'Close'}</button>${can ? '<button class="btn btn-primary" id="save">Save</button>' : ''}</div>`);
    d.style.maxWidth = '60rem'; d.querySelector('#x').onclick = () => d.close();
    const host = d.querySelector('#ed'); let get, model; const m = await loadMonaco().catch(() => null);
    if (m) {
      host.innerHTML = '';
      const ed = m.editor.create(host, { value: f.content, language: langFor(m, name), automaticLayout: true, readOnly: !can, minimap: { enabled: false }, fontSize: 13, scrollBeyondLastLine: false, tabSize: 4 });
      model = ed.getModel(); get = () => ed.getValue(); d.addEventListener('close', () => { ed.dispose(); model && model.dispose(); });
    } else {                                                           // CDN unreachable: a plain text box still works
      host.innerHTML = `<textarea class="h-full w-full p-3 font-mono text-xs" spellcheck="false" wrap="off" ${can ? '' : 'readonly'}></textarea>`;
      const ta = host.firstChild; ta.value = f.content; get = () => ta.value;        // value, never innerHTML
    }
    const save = d.querySelector('#save'); let mtime = f.mtime;
    if (save) save.onclick = () => busy(save, async () => {
      try { const r = await api('/fs/write', { method: 'POST', body: { host: S.site.host, path, content: get(), mtime } }); mtime = r.mtime || mtime; toast('Saved'); d.close(); listView(); }
      catch (e) { if (e.status === 409) throw new Error('Someone else changed this file since you opened it. Close and reopen it, then redo your change.'); throw e; }
    });
  }

  // Monaco comes from jsDelivr, pinned to one version and checked against a hash; the page's CSP allows only that host.
  const MONACO = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.57.0/min'; let monacoP;
  function loadMonaco() {
    return monacoP ||= new Promise((res, rej) => {
      window.MonacoEnvironment = { getWorkerUrl: () => URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${MONACO}/'};importScripts('${MONACO}/vs/base/worker/workerMain.js');`], { type: 'text/javascript' })) };
      const sc = document.createElement('script'); sc.src = MONACO + '/vs/loader.js'; sc.integrity = 'sha384-jwxunh5pHKFzi60XobDcwOM7qnk4lSGpdBU/hNn9pUGp+ZUpaHJqadNLRF2k+jPu'; sc.crossOrigin = 'anonymous';
      sc.onerror = () => { monacoP = null; rej(new Error('editor unavailable')); };
      sc.onload = () => { require.config({ paths: { vs: MONACO + '/vs' } }); require(['vs/editor/editor.main'], () => res(window.monaco), () => { monacoP = null; rej(new Error('editor unavailable')); }); };
      document.head.appendChild(sc);
    });
  }
  const langFor = (m, name) => { const ext = '.' + name.split('.').pop().toLowerCase(); const l = m.languages.getLanguages().find(l => (l.extensions || []).includes(ext) || (l.filenames || []).includes(name)); return l ? l.id : 'plaintext'; };

  // ---- start ---------------------------------------------------------------------
  async function boot() {
    try {
      const d = await api('/session'); S.csrf = d.csrf; S.user = d.user; S.limits = d.limits || S.limits;
      const inv = new URLSearchParams(location.search).get('invite');
      if (!S.user && inv) return inviteView(inv);
      if (S.user) { if (inv) history.replaceState(null, '', location.pathname); return sitesView(); }
      loginView();
    } catch (e) { app.innerHTML = `<p class="py-20 text-center text-gray-600">${esc(e.message)}</p>`; }
  }
  boot();
})();
