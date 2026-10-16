<?= pageHeader('Users', 'People who can view, upload and edit files in the sites you give them — through the file portal, never the admin panel.',
  '<button class="btn btn-primary" id="new"><i data-lucide="user-plus"></i>New user</button>') ?>
<div id="root"><script>document.write(UI.loading('Loading users…'))</script></div>
<script>
const E = UI.esc;
let D = null;
const DAY = 86400;
const UNTIL = [['', 'No end date'], [DAY, '1 day'], [7 * DAY, '7 days'], [30 * DAY, '30 days'], [90 * DAY, '90 days'], [365 * DAY, '1 year']];
const perms = g => ['upload', 'edit', 'delete'].filter(k => g[k]);

async function load() {
  const root = document.getElementById('root');
  try { D = await UI.api('/api/users'); } catch (e) { root.innerHTML = UI.errBox(e.message); return; }
  const host = D.portal.hostname, portalUrl = host ? 'https://' + host + '/' : '';
  root.innerHTML = `
    ${host ? `<div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-gray-800 dark:bg-gray-900">${UI.i('globe', 'text-indigo-600')}
        <span>Users log in at <a class="font-medium text-indigo-600 hover:underline" href="${E(portalUrl)}" target="_blank" rel="noopener">${E(portalUrl)}</a></span>
        <span class="muted ml-auto text-xs">${D.users.length} of ${D.max} users</span></div>`
      : `<div class="mb-4 flex gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">${UI.i('triangle-alert')}
        <div><b>The file portal has no web address yet</b>, so nobody can log in. Pick one in <a class="underline" href="?page=settings#portal">Settings → File portal</a>. You can still create users now.</div></div>`}
    ${D.users.length ? `<div class="card !p-0"><div class="overflow-x-auto"><table class="tbl"><thead><tr><th>User</th><th>Sites they can use</th><th>Last login</th><th></th></tr></thead><tbody>
      ${D.users.map(u => `<tr>
        <td><div class="font-medium">${E(u.display)}</div><div class="muted text-xs">${E(u.username)}</div>
          <div class="mt-1 flex flex-wrap gap-1">${u.disabled ? UI.badge('red', 'Disabled') : ''}${!u.has_password ? UI.badge(u.invite_pending ? 'blue' : 'yellow', u.invite_pending ? 'Invite pending' : 'Invite expired') : ''}${u.code_files ? UI.badge('orange', 'Code files', 'triangle-alert') : ''}</div></td>
        <td>${u.access.length ? u.access.map(g => { const r = D.routes.find(x => x.hostname === g.host); const dead = !r || !r.portal_enabled || r.enabled === false || (g.until && g.until * 1000 < Date.now());
              return `<div class="mb-1 flex flex-wrap items-center gap-1 text-sm ${dead ? 'opacity-60' : ''}"><a class="text-indigo-600 hover:underline" href="?page=route&host=${encodeURIComponent(g.host)}">${E(g.host)}</a>
                <span class="muted text-xs">view${perms(g).length ? ' · ' + perms(g).join(' · ') : ''}${g.until ? ' · until ' + new Date(g.until * 1000).toLocaleDateString() : ''}</span>
                ${!r || !r.portal_enabled ? UI.badge('gray', 'portal off for this site') : r.enabled === false ? UI.badge('yellow', 'site disabled') : ''}</div>`; }).join('')
            : '<span class="muted text-sm">None yet</span>'}</td>
        <td class="muted text-sm">${u.last_login ? UI.ago(u.last_login) + ' ago' : 'never'}</td>
        <td class="text-right whitespace-nowrap">
          <button class="btn btn-outline" data-a="sites" data-id="${u.id}">${UI.i('folder-key')}Sites</button>
          <button class="btn btn-ghost" data-a="activity" data-id="${u.id}" title="What this user did">${UI.i('clock')}</button>
          <button class="btn btn-ghost" data-a="edit" data-id="${u.id}" title="Edit name / permissions">${UI.i('pencil')}</button>
          <button class="btn btn-ghost" data-a="invite" data-id="${u.id}" title="Reset password (new invite link)">${UI.i('key-round')}</button>
          <button class="btn btn-ghost" data-a="signout" data-id="${u.id}" title="Sign out everywhere">${UI.i('log-out')}</button>
          <button class="btn btn-ghost" data-a="toggle" data-id="${u.id}" title="${u.disabled ? 'Enable' : 'Disable'}">${UI.i(u.disabled ? 'user-check' : 'user-x')}</button>
          <button class="btn btn-ghost-danger" data-a="delete" data-id="${u.id}" title="Delete">${UI.i('trash')}</button></td></tr>`).join('')}
      </tbody></table></div></div>`
      : UI.empty('users', 'No users yet', 'Create a user, give them access to a site, and send them the invitation link.', '<button class="btn btn-primary" id="new2">' + UI.i('user-plus') + 'New user</button>')}`;
  const n2 = document.getElementById('new2'); if (n2) n2.onclick = newUser;
  root.querySelectorAll('[data-a]').forEach(b => b.onclick = () => act(b.dataset.a, D.users.find(u => u.id === b.dataset.id), b));
}

function showInvite(r, title) {
  const link = r.invite_url || '';
  const d = UI.modal(`<h3 class="text-lg font-semibold">${E(title)}</h3>
    <p class="muted mt-1 text-sm">Send this to <b>${E(r.user.display)}</b>. It works <b>once</b> and expires in 48 hours; they choose their own password. <b>You will not see it again.</b></p>
    ${link ? `<div class="code mt-3 !whitespace-pre-wrap break-all" id="inv">${E(link)}</div>`
           : `<div class="mt-3 rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800">The portal has no address yet. Once you set one up in Settings, use <b>Reset password</b> on this user to get a working link. Invite code: <code class="break-all">${E(r.token)}</code></div>`}
    <div class="mt-5 flex justify-end gap-2">${link ? `<button class="btn btn-outline" id="cp">${UI.i('copy')}Copy link</button>` : ''}<button class="btn btn-primary" id="ok">Done</button></div>`);
  d.querySelector('#ok').onclick = () => d.close();
  const cp = d.querySelector('#cp'); if (cp) cp.onclick = () => navigator.clipboard.writeText(link).then(() => UI.toast('Link copied'));
  d.addEventListener('close', load);
}

async function newUser() {
  const d = UI.modal(`<h3 class="text-lg font-semibold">New user</h3>
    <div class="mt-4 grid gap-3">
      <div><label class="label" for="nu">Username</label><input class="input" id="nu" placeholder="e.g. sam" autocomplete="off" maxlength="32"><p class="hint">3–32 characters: lowercase letters, digits, . _ -</p></div>
      <div><label class="label" for="nd">Name <span class="muted">(optional)</span></label><input class="input" id="nd" maxlength="60" placeholder="e.g. Sam Rivera"></div>
      <label class="flex items-start gap-3 rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-900 dark:bg-orange-950"><input type="checkbox" id="nc" class="mt-1 size-4 rounded">
        <span class="text-sm"><b>Allow code files</b> (PHP and other programs)<span class="hint block !text-orange-700 dark:!text-orange-300">Leave this off unless you fully trust them. Someone who can upload a PHP file can run programs on this computer and read everything on it, including your Cloudflare token.</span></span></label></div>
    <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="x">Cancel</button><button class="btn btn-primary" id="go">Create and get invite link</button></div>`);
  d.querySelector('#x').onclick = () => d.close();
  d.querySelector('#go').onclick = e => UI.busy(e.currentTarget, async () => {
    const r = await UI.post('/api/users', { username: d.querySelector('#nu').value, display: d.querySelector('#nd').value, code_files: d.querySelector('#nc').checked });
    d.close(); showInvite(r, 'Invitation for ' + r.user.username);
  });
}
document.getElementById('new').onclick = newUser;

async function act(a, u, btn) {
  const id = u.id;
  if (a === 'invite') {
    if (!await UI.confirm('Reset ' + u.username + "'s password?", 'Their current password stops working and they are signed out. You get a new invitation link to send them.', 'Reset')) return;
    return UI.busy(btn, async () => showInvite(await UI.post('/api/users/' + id + '/invite'), 'New invitation for ' + u.username));
  }
  if (a === 'signout') return UI.busy(btn, async () => { await UI.post('/api/users/' + id + '/signout'); UI.toast(u.username + ' is signed out everywhere'); });
  if (a === 'toggle') return UI.busy(btn, async () => { await UI.post('/api/users/' + id + '/update', { disabled: !u.disabled }); UI.toast(u.username + (u.disabled ? ' enabled' : ' disabled and signed out')); load(); });
  if (a === 'delete') {
    if (!await UI.confirm('Delete ' + u.username + '?', 'Their account and all their access are removed. Files they uploaded stay in the sites.', 'Delete user')) return;
    return UI.busy(btn, async () => { await UI.api('/api/users/' + id + '&confirm=1', { method: 'DELETE' }); UI.toast('User deleted'); load(); });
  }
  if (a === 'edit') return editUser(u);
  if (a === 'sites') return manageSites(u);
  if (a === 'activity') return activity(u);
}

function editUser(u) {
  const d = UI.modal(`<h3 class="text-lg font-semibold">Edit ${E(u.username)}</h3><div class="mt-4 grid gap-3">
      <div><label class="label" for="ed">Name</label><input class="input" id="ed" maxlength="60" value="${E(u.display)}"></div>
      <label class="flex items-start gap-3 rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-900 dark:bg-orange-950"><input type="checkbox" id="ec" class="mt-1 size-4 rounded" ${u.code_files ? 'checked' : ''}>
        <span class="text-sm"><b>Allow code files</b> (PHP and other programs)<span class="hint block !text-orange-700 dark:!text-orange-300">Lets this person run programs on this computer through your sites. Only for people you fully trust.</span></span></label></div>
    <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="x">Cancel</button><button class="btn btn-primary" id="go">Save</button></div>`);
  d.querySelector('#x').onclick = () => d.close();
  d.querySelector('#go').onclick = e => UI.busy(e.currentTarget, async () => { await UI.post('/api/users/' + u.id + '/update', { display: d.querySelector('#ed').value, code_files: d.querySelector('#ec').checked }); d.close(); UI.toast('Saved'); load(); });
}

/** Which sites a user may use, and how much they may do there. */
function manageSites(u) {
  const have = Object.fromEntries(u.access.map(g => [g.host, g]));
  const rows = D.routes.map(r => { const g = have[r.hostname];
    return `<div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800" data-h="${E(r.hostname)}">
      <label class="flex items-center gap-2 font-medium"><input type="checkbox" class="use size-4 rounded" ${g ? 'checked' : ''}>${E(r.hostname)}
        ${!r.portal_enabled ? UI.badge('gray', 'portal off') : ''}${r.enabled === false ? UI.badge('yellow', 'disabled') : ''}</label>
      <div class="opts mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 pl-6 text-sm" ${g ? '' : 'hidden'}>
        <span class="muted">can view, and also:</span>
        ${['upload', 'edit', 'delete'].map(k => `<label class="flex items-center gap-1"><input type="checkbox" class="p-${k} size-4 rounded" ${g && g[k] ? 'checked' : ''}>${k}</label>`).join('')}
        <select class="until input !w-auto !py-1 text-sm">${UNTIL.map(([v, l]) => `<option value="${v}">${v === '' && g && g.until ? 'Keep current end date' : l}</option>`).join('')}</select>
        ${g && g.until ? `<span class="muted text-xs">now until ${new Date(g.until * 1000).toLocaleDateString()}</span>` : ''}</div>
      ${!r.portal_enabled ? `<p class="hint pl-6">Users can only reach this site once <a class="underline" href="?page=route&host=${encodeURIComponent(r.hostname)}">file access is switched on for it</a>.</p>` : ''}</div>`; }).join('');
  const d = UI.modal(`<h3 class="text-lg font-semibold">Sites for ${E(u.display)}</h3>
    <p class="muted mt-1 text-sm">Pick the sites they can open in the portal. <b>Upload</b> adds files, <b>edit</b> changes and renames existing ones, <b>delete</b> moves files to the site's trash.</p>
    <div class="mt-4 grid max-h-[55vh] gap-2 overflow-y-auto">${rows || '<p class="muted text-sm">There are no sites yet.</p>'}</div>
    <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="x">Cancel</button><button class="btn btn-primary" id="go">Save</button></div>`);
  d.style.maxWidth = '40rem';
  d.querySelectorAll('[data-h]').forEach(row => row.querySelector('.use').onchange = e => row.querySelector('.opts').hidden = !e.target.checked);
  d.querySelector('#x').onclick = () => d.close();
  d.querySelector('#go').onclick = e => UI.busy(e.currentTarget, async () => {
    for (const row of d.querySelectorAll('[data-h]')) {
      const host = row.dataset.h, on = row.querySelector('.use').checked, g = have[host];
      if (!on && g) await UI.api('/api/users/' + u.id + '/grant&host=' + encodeURIComponent(host), { method: 'DELETE' });
      if (on) {
        const body = { host, upload: row.querySelector('.p-upload').checked, edit: row.querySelector('.p-edit').checked, delete: row.querySelector('.p-delete').checked };
        const sel = row.querySelector('.until').value;
        if (sel) body.in = +sel; else if (g && g.until) body.until = g.until;       // untouched "no end date" keeps an existing end date; choosing one replaces it
        if (!sel && g && !g.until) body.until = null;
        await UI.post('/api/users/' + u.id + '/grant', body);
      }
    }
    d.close(); UI.toast('Access updated'); load();
  });
}

async function activity(u) {
  const r = await UI.api('/api/users/activity&limit=100&user=' + encodeURIComponent(u.username));
  const d = UI.modal(`<h3 class="text-lg font-semibold">Activity — ${E(u.username)}</h3>
    <ul class="mt-3 max-h-[60vh] space-y-2 overflow-y-auto text-sm">${r.entries.map(e => `<li class="flex gap-2">${UI.i(e.ok ? 'circle-check' : 'circle-alert', 'mt-0.5 shrink-0 ' + (e.ok ? 'text-green-600' : 'text-red-600'))}
      <span><b>${E(e.action)}</b> ${e.host ? E(e.host) + ' ' : ''}<code class="text-xs">${E(e.path)}</code> ${e.detail ? '<span class="muted text-xs">' + E(e.detail) + '</span>' : ''}<br><span class="muted text-xs">${new Date(e.ts * 1000).toLocaleString()} · ${E(e.ip)}</span></span></li>`).join('') || '<li class="muted">Nothing yet.</li>'}</ul>
    <div class="mt-5 flex justify-end"><button class="btn btn-outline" id="x">Close</button></div>`);
  d.style.maxWidth = '40rem'; d.querySelector('#x').onclick = () => d.close();
}
load();
</script>
