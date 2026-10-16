/* Tunnel Router — shared UI helpers (toasts, dialogs, expiry picker, charts, API client, tick). */
/* Icons: Lucide, but only the ~100 the interface uses (assets/icons.js). UI.i() returns inline SVG directly, so there is
   no DOM scanning at all for icons built in JS; only server-rendered <i data-lucide="x"> placeholders are swapped, once. */
(() => {
  const ICONS = window.__ICONS || {};
  const svg = (name, cls) => `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide ${cls || ''}" aria-hidden="true">${ICONS[name] || ''}</svg>`;
  window.__svgIcon = svg;
  const swap = root => root.querySelectorAll && root.querySelectorAll('i[data-lucide]').forEach(el => {
    const t = document.createElement('template'); t.innerHTML = svg(el.getAttribute('data-lucide'), el.getAttribute('class'));
    el.replaceWith(t.content.firstChild);          // the new element is an <svg>, never an <i>, so it is never processed again
  });
  swap(document);
  new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) { if (n.tagName === 'I' && n.hasAttribute('data-lucide')) swap({ querySelectorAll: () => [n] }); else swap(n); } })))
    .observe(document.documentElement, { childList: true, subtree: true });

  const toggle = document.getElementById('theme-toggle');
  if (toggle) toggle.onclick = () => {
    const dark = document.documentElement.classList.toggle('dark');
    try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
    location.reload(); // charts pick their palette at render time
  };
})();

// While any page is open, tick: route expiry, Under Attack auto-revert, health checks and Apache-reload progress.
let tickTimer = null;
const tick = () => document.hidden ? Promise.resolve() : fetch('api.php?route=/api/tick').then(r => r.json()).then(d => {
  (d.expired || []).forEach(e => UI.toast(e.ok ? e.hostname + ' expired (' + e.detail + ')' : 'Expiry failed for ' + e.hostname + ': ' + e.detail, e.ok ? 'info' : 'error'));
  (d.reverted || []).forEach(e => UI.toast(e.ok ? 'Under Attack mode reverted (' + e.detail + ')' : 'Auto-revert failed: ' + e.detail, e.ok ? 'info' : 'error'));
  (d.new_alerts || []).forEach(a => UI.toast(a.msg, a.severity === 'error' ? 'error' : 'info'));
  const b = document.getElementById('alert-badge');
  if (b) { b.textContent = d.unread || ''; b.hidden = !d.unread; }
  UI.showReload(d.reload);
  if (d.reload && ['pending', 'running'].includes(d.reload.state)) { clearTimeout(tickTimer); tickTimer = setTimeout(tick, 3000); }   // follow a reload closely
}).catch(() => {});
window.__tick = () => { clearTimeout(tickTimer); tickTimer = setTimeout(tick, 1200); };
setTimeout(tick, 1500); setInterval(tick, 60000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) window.__tick(); });   // catch up when you come back to the tab

window.UI = {
  esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; },
  num(n) { return n == null ? '—' : new Intl.NumberFormat().format(n); },
  bytes(b) {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return b.toFixed(i ? 1 : 0) + ' ' + u[i];
  },
  pct(a, b) { return b ? (a / b * 100).toFixed(1) + '%' : '—'; },
  /** Elapsed time since ts (ISO string or unix seconds). */
  ago(ts) {
    if (!ts) return '—';
    const s = Math.max(0, (Date.now() - new Date(typeof ts === 'number' ? ts * 1000 : ts)) / 1000);
    if (s < 60) return Math.floor(s) + 's';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h ' + Math.floor(s % 3600 / 60) + 'm';
    return Math.floor(s / 86400) + 'd ' + Math.floor(s % 86400 / 3600) + 'h';
  },
  /** "example.com (2)" — the domain with the number of routes already attached to it. */
  zoneLabel(z) { return this.esc(z.name) + ' (' + (z.routes || 0) + ')'; },
  isDark: () => document.documentElement.classList.contains('dark'),

  // ---- network ----
  async api(route, opts) {
    opts = { ...opts, headers: { 'X-Requested-With': 'tunnel-router', ...(opts && opts.headers) } };   // required on non-GET; blocks cross-site requests
    const r = await fetch('api.php?route=' + route, opts);
    const text = await r.text();
    let j; try { j = JSON.parse(text); } catch (e) { throw new Error('Server returned invalid JSON: ' + text.slice(0, 120)); }
    if (opts && opts.method && opts.method !== 'GET' && j.ok) window.__tick && window.__tick();   // a change may have queued an Apache reload: watch it
    if (!j.ok && !(opts && opts.allowFail)) { const err = new Error(j.error || 'Request failed'); err.data = j; throw err; }
    return j;
  },
  post(route, body, method) {
    return this.api(route, { method: method || 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  },

  // ---- markup helpers ----
  i(name, cls) { return window.__svgIcon(name, cls); },
  badge(color, text, icon) { return `<span class="badge badge-${color}">${icon ? this.i(icon, '!size-3') : ''}${text}</span>`; },
  stat(icon, color, value, label, sub) {
    return `<div class="card flex items-center gap-4 !p-4">
      <span class="grid size-10 shrink-0 place-content-center rounded-lg badge-${color}">${this.i(icon, '!size-5')}</span>
      <div class="min-w-0"><div class="truncate text-xl font-semibold">${value}</div>
        <div class="muted truncate text-xs">${label}${sub ? ' · ' + sub : ''}</div></div></div>`;
  },
  loading(t) { return `<div class="py-16 text-center">${this.i('loader-circle', '!size-8 mx-auto animate-spin text-indigo-600')}<div class="muted mt-3 text-sm">${t || 'Loading…'}</div></div>`; },
  /** Show Apache-reload progress: one toast per state change, and a banner while the last reload is unconfirmed. */
  showReload(r) {
    const banner = document.getElementById('reload-banner');
    if (banner) {
      banner.hidden = !(r && r.state === 'failed');
      if (r && r.state === 'failed') banner.querySelector('[data-msg]').textContent = r.detail || 'Apache did not confirm the reload.';
    }
    if (!r || !r.id) return;
    let seen = ''; try { seen = sessionStorage.getItem('reload_seen') || ''; } catch (e) {}
    const key = r.id + ':' + r.state;
    if (seen === key) return;
    try { sessionStorage.setItem('reload_seen', key); } catch (e) {}
    if (['pending', 'running'].includes(r.state)) this.toast('Applying the change: restarting Apache…', 'info');
    else if (r.state === 'ok') this.toast(r.detail || 'Apache reloaded.');
    else if (r.state === 'failed') this.toast('Apache did not confirm the reload — see the banner at the top.', 'error');
  },
  errBox(msg) { return `<div class="flex gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">${this.i('circle-alert')}<div>${this.esc(msg)}</div></div>`; },
  warn(msg) { return `<div class="mb-4 flex gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">${this.i('triangle-alert')}<div>${this.esc(msg)}</div></div>`; },
  empty(icon, title, sub, action) {
    return `<div class="card py-14 text-center">${this.i(icon, '!size-10 mx-auto text-gray-400')}
      <p class="mt-3 font-semibold">${title}</p><p class="muted mt-1 text-sm">${sub || ''}</p>${action ? `<div class="mt-4">${action}</div>` : ''}</div>`;
  },
  /** Switch. Render it inside a <label> (every caller does) — a <label> within a <label> is invalid HTML. */
  toggle(id, checked, attrs) {
    return `<span class="relative block h-6 w-11 shrink-0 cursor-pointer rounded-full bg-gray-300 transition-colors has-checked:bg-green-500 dark:bg-gray-700">
      <input type="checkbox" id="${id}" class="peer sr-only" ${checked ? 'checked' : ''} ${attrs || ''}>
      <span class="absolute inset-y-0 start-0 m-0.5 size-5 rounded-full bg-white transition-[inset-inline-start] peer-checked:start-5"></span></span>`;
  },

  probeBadge(p) {
    if (!p) return this.badge('gray', 'not probed');
    const map = { up: ['green', 'Up'], tunnel_down: ['red', 'Tunnel down'], error: ['orange', 'HTTP ' + p.code], unreachable: ['red', 'Unreachable'] };
    const [c, t] = map[p.state] || ['gray', p.state];
    return this.badge(c, t);
  },

  // ---- feedback ----
  toast(msg, type) {
    const c = { success: ['green', 'circle-check'], error: ['red', 'circle-alert'], info: ['blue', 'info'] }[type || 'success'];
    const el = document.createElement('div');
    el.className = `pointer-events-auto flex items-start gap-3 rounded-md border border-${c[0]}-500 bg-${c[0]}-50 p-4 text-${c[0]}-800 shadow-sm dark:bg-gray-900 dark:text-${c[0]}-300`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `${this.i(c[1], '!size-5 shrink-0')}<p class="text-sm">${this.esc(msg)}</p>`;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), type === 'error' ? 8000 : 4000);
  },
  confirm(title, text, okLabel, danger = true) {
    const d = document.getElementById('confirm');
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-text').textContent = text || '';
    const yes = document.getElementById('confirm-yes'), no = document.getElementById('confirm-no');
    yes.textContent = okLabel || 'Confirm';
    yes.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    return new Promise(res => {
      const done = v => { d.close(); yes.onclick = no.onclick = null; res(v); };
      yes.onclick = () => done(true); no.onclick = () => done(false);
      d.oncancel = () => done(false);
      d.showModal();
    });
  },
  /** Run an async action on a button: disable it, toast errors, restore. */
  async busy(btn, fn) {
    const html = btn.innerHTML; btn.disabled = true;
    btn.innerHTML = this.i('loader-circle', 'animate-spin') + ' Working…';
    try { return await fn(); } catch (e) { this.toast(e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = html; }
  },

  // ---- charts (ApexCharts) ----
  /** ApexCharts is ~1 MB, so it is only fetched the first time a chart is drawn (and cached by the browser after that). */
  loadApex() {
    return this._apex || (this._apex = new Promise((res, rej) => {
      if (window.ApexCharts) return res();
      const s = document.createElement('script'); s.src = window.__apexSrc; s.onload = res; s.onerror = () => { this._apex = null; rej(new Error('Could not load the charting library')); };
      document.head.appendChild(s);
    }));
  },
  /** Returns at once with a handle (destroy() works before and after the chart exists); the chart appears when the library is ready. */
  chart(el, options) {
    const dark = this.isDark(), fg = dark ? '#9ca3af' : '#6b7280';
    const base = {
      chart: { toolbar: { show: false }, foreColor: fg, fontFamily: 'inherit', background: 'transparent', animations: { speed: 400 } },
      theme: { mode: dark ? 'dark' : 'light' },
      colors: ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'],
      grid: { borderColor: dark ? '#1f2937' : '#f3f4f6' },
      dataLabels: { enabled: false },
      stroke: { curve: 'smooth', width: 2 },
      noData: { text: 'No data for this period' },
    };
    const merge = (a, b) => { for (const k in b) a[k] = (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k])) ? merge(a[k] || {}, b[k]) : b[k]; return a; };
    const handle = { chart: null, dead: false, destroy() { this.dead = true; if (this.chart) { try { this.chart.destroy(); } catch (e) {} } } };
    this.loadApex().then(() => requestAnimationFrame(() => {
      if (handle.dead || !el.isConnected) return;
      handle.chart = new ApexCharts(el, merge(base, options)); handle.chart.render();
    })).catch(e => { if (el.isConnected) el.innerHTML = '<p class="muted text-sm">' + this.esc(e.message) + '</p>'; });
    return handle;
  },
  area(el, categories, series, fmt) {
    return this.chart(el, {
      chart: { type: 'area', height: 240 },
      series, xaxis: { categories, labels: { rotate: 0 } },
      yaxis: { labels: { formatter: v => fmt ? fmt(v) : Math.round(v) }, min: 0 },
      fill: { type: 'gradient', gradient: { opacityFrom: .35, opacityTo: .02 } },
      tooltip: { y: { formatter: v => fmt ? fmt(v) : v } },
    });
  },
  donut(el, labels, series, colors) {
    return this.chart(el, { chart: { type: 'donut', height: 220 }, labels, series, colors,
      legend: { position: 'bottom' }, stroke: { width: 0 }, plotOptions: { pie: { donut: { size: '68%' } } } });
  },
  hbar(el, labels, series, name) {
    return this.chart(el, { chart: { type: 'bar', height: Math.max(120, labels.length * 38) }, series: [{ name, data: series }],
      plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } }, xaxis: { categories: labels }, stroke: { width: 0 } });
  },
};

// ---- expiry / share ----
Object.assign(UI, {
  PRESETS: [['2 hours', 7200], ['6 hours', 21600], ['12 hours', 43200], ['1 day', 86400], ['3 days', 259200], ['7 days', 604800],
            ['30 days', 2592000], ['90 days', 7776000], ['6 months', 15552000], ['12 months', 31536000]],

  /** "1d 4h" until a unix timestamp, plus a severity colour. */
  until(ts) {
    const s = ts - Date.now() / 1000;
    if (s <= 0) return { text: 'expiring now', color: 'red' };
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
    return { text: d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`, color: s < 3600 ? 'red' : s < 86400 ? 'yellow' : 'blue' };
  },
  expiryBadge(ts) {
    if (!ts) return '';
    const u = this.until(ts);
    return this.badge(u.color, 'Expires in ' + u.text, 'timer');
  },

  /**
   * The expiry control used on every page that lists a route: current state + a LABELLED button.
   * Pair with bindExpiry(container, onDone) once the markup is in the DOM.
   */
  expiryControl(host, at, expiredAt) {
    const state = at ? this.expiryBadge(at)
      : expiredAt ? this.badge('gray', 'Expired ' + this.ago(expiredAt) + ' ago', 'timer-off')
      : '<span class="muted text-xs whitespace-nowrap">No expiry</span>';
    return `<span class="inline-flex flex-wrap items-center gap-2">${state}<button class="btn btn-outline !px-2 !py-0.5 js-expiry" data-host="${this.esc(host)}" data-at="${at || ''}" title="Choose when this route switches itself off">${this.i('timer')}${at ? 'Edit expiry' : 'Set expiry'}</button></span>`;
  },
  bindExpiry(root, done) {
    root.querySelectorAll('.js-expiry').forEach(b => b.onclick = async () => {
      const body = await this.pickExpiry(b.dataset.host, +b.dataset.at || null);
      if (!body) return;
      this.busy(b, async () => { await this.post('/api/routes/' + encodeURIComponent(b.dataset.host) + '/expiry', body); this.toast('Expiry updated'); if (done) await done(); });
    });
  },

  /** Select (Never / presets / Custom) + datetime input. Pair with readExpiryField(id). */
  expiryField(id, def) {
    return `<div id="${id}"><select class="input" data-x="sel"><option value="">Never expire</option>
        ${this.PRESETS.map(([l, v]) => `<option value="${v}" ${v === def ? 'selected' : ''}>${l}</option>`).join('')}<option value="custom">Custom date & time…</option></select>
      <input type="datetime-local" class="input mt-2" data-x="custom" hidden></div>`;
  },
  wireExpiryField(id) {
    const r = document.getElementById(id), sel = r.querySelector('[data-x=sel]'), c = r.querySelector('[data-x=custom]');
    sel.onchange = () => c.hidden = sel.value !== 'custom';
  },
  /** → {expires_in} | {expires_at} | {} ; throws on a bad custom date. */
  readExpiryField(id) {
    const r = document.getElementById(id), v = r.querySelector('[data-x=sel]').value;
    if (v === '') return {};
    if (v !== 'custom') return { expires_in: +v };
    const at = Math.floor(new Date(r.querySelector('[data-x=custom]').value).getTime() / 1000);
    if (!at) throw new Error('Pick a date and time');
    return { expires_at: at };
  },

  modal(html) {
    const d = document.createElement('dialog');
    d.className = 'm-auto w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 text-gray-900 shadow-xl backdrop:bg-black/40 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';
    d.innerHTML = html; document.body.appendChild(d);
    d.addEventListener('close', () => d.remove());
    d.showModal();
    return d;
  },

  /** Set/extend dialog for an existing route → API body ({in}|{extend}|{at}|{never}) or null if cancelled. */
  pickExpiry(host, current, multi) {
    const cur = multi ? `Applies to ${multi} routes — "extend" adds to each route's own expiry.` : current ? `Currently expires in <b>${this.until(current).text}</b>.` : 'Currently never expires.';
    return new Promise(res => {
      const d = this.modal(`<h3 class="text-lg font-semibold">Expiry for ${this.esc(host)}</h3><p class="muted mt-1 text-sm">${cur}</p>
        <div class="mt-4 grid gap-3">
          ${current || multi ? `<div class="flex gap-4 text-sm"><label class="flex items-center gap-1"><input type="radio" name="mode" value="extend" checked> Extend by</label>
            <label class="flex items-center gap-1"><input type="radio" name="mode" value="in"> Set from now</label></div>` : ''}
          <select class="input" id="px-sel">${this.PRESETS.map(([l, v]) => `<option value="${v}">${l}</option>`).join('')}<option value="custom">Custom date & time…</option></select>
          <input type="datetime-local" class="input" id="px-custom" hidden></div>
        <div class="mt-5 flex flex-wrap justify-between gap-2">
          <button class="btn btn-ghost-danger" id="px-never">Never expire</button>
          <span class="flex gap-2"><button class="btn btn-outline" id="px-cancel">Cancel</button><button class="btn btn-primary" id="px-ok">Save</button></span></div>`);
      const $ = i => d.querySelector('#' + i);
      let out = null;
      $('px-sel').onchange = () => $('px-custom').hidden = $('px-sel').value !== 'custom';
      $('px-cancel').onclick = () => d.close();
      $('px-never').onclick = () => { out = { never: true }; d.close(); };
      $('px-ok').onclick = () => {
        const v = $('px-sel').value;
        if (v === 'custom') {
          const at = Math.floor(new Date($('px-custom').value).getTime() / 1000);
          if (!at) return this.toast('Pick a date and time', 'error');
          out = { at };
        } else {
          const mode = d.querySelector('input[name=mode]:checked');
          out = { [mode ? mode.value : 'in']: +v };
        }
        d.close();
      };
      d.addEventListener('close', () => res(out));
    });
  },

  /** Single-line text prompt (window.prompt is unavailable in some embeds). → string, or null if cancelled. */
  ask(title, label, value, okLabel) {
    return new Promise(res => {
      const d = this.modal(`<h3 class="text-lg font-semibold">${this.esc(title)}</h3>
        <label class="label mt-4" for="ask-in">${this.esc(label)}</label><input class="input" id="ask-in" value="${this.esc(value || '')}" autocomplete="off">
        <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="ask-no">Cancel</button><button class="btn btn-primary" id="ask-ok">${this.esc(okLabel || 'OK')}</button></div>`);
      const inp = d.querySelector('#ask-in'); let out = null;
      inp.focus(); inp.setSelectionRange(0, (value || '').lastIndexOf('.') > 0 ? (value || '').lastIndexOf('.') : (value || '').length);
      const ok = () => { if (inp.value.trim()) { out = inp.value.trim(); d.close(); } };
      inp.onkeydown = e => { if (e.key === 'Enter') ok(); };
      d.querySelector('#ask-ok').onclick = ok; d.querySelector('#ask-no').onclick = () => d.close();
      d.addEventListener('close', () => res(out));
    });
  },

  /** Per-item outcome list for bulk runs; resolves when closed. */
  results(title, results) {
    const ok = results.filter(r => r.ok).length;
    return new Promise(res => {
      const d = this.modal(`<h3 class="text-lg font-semibold">${this.esc(title)}</h3>
        <p class="muted mt-1 text-sm">${ok} of ${results.length} succeeded.</p>
        <ul class="mt-4 max-h-72 space-y-2 overflow-y-auto text-sm">${results.map(r => `<li class="flex gap-2">
          ${this.i(r.ok ? 'circle-check' : 'circle-alert', 'mt-0.5 shrink-0 ' + (r.ok ? 'text-green-600' : 'text-red-600'))}
          <div class="min-w-0"><b class="break-all">${this.esc(r.id)}</b>${r.error ? `<div class="muted break-words text-xs">${this.esc(r.error)}</div>` : ''}</div></li>`).join('')}</ul>
        <div class="mt-5 flex justify-end"><button class="btn btn-primary" id="rs-ok">Done</button></div>`);
      d.querySelector('#rs-ok').onclick = () => d.close();
      d.addEventListener('close', res);
    });
  },

  /** Share-link dialog: temporary route on a random subdomain. */
  async share(project) {
    let zones;
    try { zones = (await this.api('/api/zones')).zones; } catch (e) { return this.toast(e.message, 'error'); }
    let last = ''; try { last = localStorage.getItem('share_zone') || ''; } catch (e) {}
    const d = this.modal(`<h3 class="text-lg font-semibold">Share ${this.esc(project)}</h3>
      <p class="muted mt-1 text-sm">Creates a temporary public URL that switches itself off when time is up.</p>
      <div class="mt-4 grid gap-3" id="sh-body">
        <div><label class="label">Domain</label><select class="input" id="sh-zone">${zones.map(z => `<option value="${z.id}" ${z.id === last ? 'selected' : ''}>${this.zoneLabel(z)}</option>`).join('')}</select></div>
        <div><label class="label">Available for</label><select class="input" id="sh-dur">${this.PRESETS.map(([l, v]) => `<option value="${v}" ${v === 7200 ? 'selected' : ''}>${l}</option>`).join('')}</select></div></div>
      <div class="mt-5 flex justify-end gap-2"><button class="btn btn-outline" id="sh-cancel">Close</button><button class="btn btn-primary" id="sh-go">${this.i('share-2')}Create link</button></div>`);
    d.querySelector('#sh-cancel').onclick = () => d.close();
    const go = d.querySelector('#sh-go');
    go.onclick = () => this.busy(go, async () => {
      const zone_id = d.querySelector('#sh-zone').value;
      try { localStorage.setItem('share_zone', zone_id); } catch (e) {}
      const r = await this.post('/api/share', { project, zone_id, in: +d.querySelector('#sh-dur').value });
      d.querySelector('#sh-body').innerHTML = `<div class="rounded-lg bg-green-50 p-3 text-sm dark:bg-green-950"><div class="font-medium">Link is live</div>
        <a class="mt-1 block break-all text-indigo-600 hover:underline" href="${this.esc(r.url)}" target="_blank">${this.esc(r.url)}</a>
        <div class="muted mt-1 text-xs">Expires in ${this.until(r.expires_at).text}</div></div>`;
      go.outerHTML = `<button class="btn btn-primary" id="sh-copy">${this.i('copy')}Copy link</button>`;
      d.querySelector('#sh-copy').onclick = () => { navigator.clipboard.writeText(r.url).then(() => this.toast('Link copied')); };
      this.toast('Share link created');
    });
  },
});
