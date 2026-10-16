<?php
/** @var string $content   — rendered page body */
/** @var string $page      — current page slug */
$cfg    = Config::isConfigured() ? Config::publicView() : [];
$tunnel = $cfg['cloudflare']['tunnel_name'] ?? '';

$navItems = [
    'dashboard' => ['icon' => 'layout-dashboard', 'label' => 'Dashboard'],
    'domains'   => ['icon' => 'globe',            'label' => 'Domains'],
    'tunnels'   => ['icon' => 'network',          'label' => 'Tunnels'],
    'files'     => ['icon' => 'folder-code',      'label' => 'Files'],
    'users'     => ['icon' => 'users',            'label' => 'Users'],
    'health'    => ['icon' => 'heart-pulse',      'label' => 'Health'],
    'dns'       => ['icon' => 'list-tree',        'label' => 'DNS'],
    'attach'    => ['icon' => 'plug-zap',         'label' => 'Attach Route'],
    'reconcile' => ['icon' => 'refresh-cw',       'label' => 'Reconcile'],
    'backup'    => ['icon' => 'archive',          'label' => 'Backup'],
    'activity'  => ['icon' => 'scroll-text',      'label' => 'Activity'],
    'settings'  => ['icon' => 'settings',         'label' => 'Settings'],
];
$parent  = ['domain' => 'domains', 'tunnel' => 'tunnels', 'detach' => 'dashboard', 'logs' => 'files', 'route' => 'dashboard', 'project' => 'dashboard'];
$current = $parent[$page] ?? $page;

$pageTitle = [
    'dashboard' => 'Dashboard', 'domains' => 'Domains', 'domain' => 'Domain', 'tunnels' => 'Tunnels',
    'tunnel' => 'Tunnel', 'files' => 'Files', 'logs' => 'Logs', 'route' => 'Route', 'project' => 'Project', 'users' => 'Users', 'health' => 'Health', 'dns' => 'DNS', 'backup' => 'Backup', 'attach' => 'Attach Route', 'detach' => 'Detach Route',
    'reconcile' => 'Reconcile', 'activity' => 'Activity', 'settings' => 'Settings',
][$page] ?? 'Tunnel Router';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $page !== 'dashboard' ? $pageTitle . ' — ' : '' ?>Tunnel Router</title>
  <script>try { if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}</script>
  <link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body class="bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">

  <!-- Mobile bar -->
  <div class="sticky top-0 z-30 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 lg:hidden dark:border-gray-800 dark:bg-gray-900">
    <a href="?page=dashboard" class="flex items-center gap-2 font-semibold"><i data-lucide="cloud" class="!size-5 text-indigo-600"></i>Tunnel Router</a>
    <button class="btn btn-ghost" onclick="document.getElementById('side').classList.toggle('-translate-x-full')" aria-label="Menu"><i data-lucide="menu"></i></button>
  </div>

  <!-- Sidebar (HyperUI side-menu pattern) -->
  <aside id="side" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col justify-between border-e border-gray-100 bg-white transition-transform lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900">
    <div class="p-4">
      <a href="?page=dashboard" class="flex items-center gap-2 px-2 py-1 text-lg font-bold">
        <i data-lucide="cloud" class="!size-6 text-indigo-600"></i>Tunnel Router
      </a>

      <?php if ($tunnel): ?>
      <a href="?page=tunnels" class="mt-4 flex items-center gap-2 rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
        <i data-lucide="network" class="text-indigo-600"></i>
        <span class="truncate"><?= htmlspecialchars($tunnel) ?></span>
        <span class="badge badge-green ml-auto">active</span>
      </a>
      <?php endif; ?>

      <ul class="mt-4 space-y-1">
        <?php foreach ($navItems as $slug => $meta): $on = $current === $slug; ?>
        <li>
          <a href="?page=<?= $slug ?>"
             class="flex items-center gap-3 rounded-lg px-4 py-2 text-sm font-medium transition-colors <?= $on
               ? 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-white'
               : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white' ?>">
            <i data-lucide="<?= $meta['icon'] ?>"></i><?= $meta['label'] ?>
            <?php if ($slug === 'health'): ?><span id="alert-badge" hidden class="ml-auto rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white"></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="flex items-center justify-between border-t border-gray-100 p-4 dark:border-gray-800">
      <span class="text-xs text-gray-400">htdocs Tunnel Router</span>
      <button class="btn btn-ghost" id="theme-toggle" aria-label="Toggle dark mode"><i data-lucide="sun"></i></button>
    </div>
  </aside>

  <div id="toasts" class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-full max-w-sm flex-col gap-2"></div>
  <dialog id="confirm" class="m-auto w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 text-gray-900 shadow-xl backdrop:bg-black/40 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
    <h3 id="confirm-title" class="text-lg font-semibold"></h3>
    <p id="confirm-text" class="muted mt-2 text-sm"></p>
    <div class="mt-5 flex justify-end gap-2">
      <button class="btn btn-outline" id="confirm-no">Cancel</button>
      <button class="btn btn-danger" id="confirm-yes">Confirm</button>
    </div>
  </dialog>

  <script>window.__apexSrc = <?= json_encode(asset('apexcharts.min.js')) ?>;</script>
  <script src="<?= asset('icons.js') ?>"></script>
  <script src="<?= asset('ui.js') ?>"></script>   <!-- must load before the page's own script, which follows in <main> -->

  <main class="lg:pl-64">
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

      <div id="reload-banner" hidden class="mb-4 flex gap-3 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        <i data-lucide="server-crash"></i>
        <div><strong>Apache has not applied the latest change.</strong> <span data-msg></span> <a href="?page=settings#reload" class="underline">Details and settings</a></div>
      </div>

      <?php if (!Config::isConfigured()): ?>
      <div class="mb-4 flex gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
        <i data-lucide="triangle-alert"></i>
        <div><strong>Not configured.</strong> Enter your Cloudflare API token and Tunnel ID in <a href="?page=settings" class="underline">Settings</a> to get started.</div>
      </div>
      <?php endif; ?>

      <?= $content ?>
    </div>
  </main>
</body>
</html>
