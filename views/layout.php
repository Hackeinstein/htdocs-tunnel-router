<?php
/** @var string $content   — rendered page body */
/** @var string $page      — current page slug */
$cfg    = Config::isConfigured() ? Config::publicView() : [];
$tunnel = $cfg['cloudflare']['tunnel_name'] ?? '';

$navItems = [
    'dashboard' => ['icon' => 'ti-layout-dashboard', 'label' => 'Dashboard'],
    'attach'    => ['icon' => 'ti-plug-connected',    'label' => 'Attach Route'],
    'reconcile' => ['icon' => 'ti-refresh-alert',     'label' => 'Reconcile'],
    'activity'  => ['icon' => 'ti-history',           'label' => 'Activity'],
    'settings'  => ['icon' => 'ti-settings',          'label' => 'Settings'],
];

$pageTitle = [
    'dashboard' => 'Dashboard',
    'attach'    => 'Attach Route',
    'detach'    => 'Detach Route',
    'reconcile' => 'Reconcile',
    'activity'  => 'Activity',
    'settings'  => 'Settings',
][$page] ?? 'Tunnel Router';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $page !== 'dashboard' ? $pageTitle . ' — ' : '' ?>Tunnel Router</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
  <style>
    pre.code-preview {
      background: var(--tblr-bg-surface-tertiary);
      border: 1px solid var(--tblr-border-color);
      border-radius: 4px;
      padding: 1rem;
      font-size: .8rem;
      white-space: pre;
      overflow-x: auto;
    }
    .min-w-0 { min-width: 0; }
  </style>
</head>
<body class="antialiased">
  <div class="wrapper">

    <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
      <div class="container-fluid">
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
          <span class="navbar-toggler-icon"></span>
        </button>

        <h1 class="navbar-brand navbar-brand-autodark">
          <a href="?page=dashboard" class="text-decoration-none d-flex align-items-center gap-2">
            <span class="ti ti-cloud-network fs-2"></span>
            <span class="fw-bold">Tunnel Router</span>
          </a>
        </h1>

        <div class="collapse navbar-collapse" id="sidebar-menu">
          <?php if ($tunnel): ?>
          <div class="navbar-nav mb-3">
            <div class="d-flex align-items-center gap-2 px-2 py-1 rounded"
                 style="background:rgba(255,255,255,.08)">
              <span class="ti ti-cloud text-azure"></span>
              <span class="text-white small"><?= htmlspecialchars($tunnel) ?></span>
              <span class="badge bg-green ms-auto" style="font-size:.65rem">active</span>
            </div>
          </div>
          <?php endif; ?>

          <ul class="navbar-nav pt-lg-3">
            <?php foreach ($navItems as $slug => $meta): ?>
            <li class="nav-item">
              <a class="nav-link <?= $page === $slug ? 'active' : '' ?>"
                 href="?page=<?= $slug ?>">
                <span class="nav-link-icon d-md-none d-lg-inline-block">
                  <span class="ti <?= $meta['icon'] ?>"></span>
                </span>
                <span class="nav-link-title"><?= $meta['label'] ?></span>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>

          <div class="mt-auto pb-3 pt-3" style="border-top:1px solid rgba(255,255,255,.1)">
            <div class="text-secondary small px-2" style="font-size:.7rem; opacity:.6">
              htdocs Tunnel Router
            </div>
          </div>
        </div>
      </div>
    </aside>

    <div class="page-wrapper">
      <div class="container-xl">
        <div class="page-body">

          <?php if (!Config::isConfigured()): ?>
          <div class="alert alert-warning mb-4">
            <div class="d-flex align-items-center">
              <span class="ti ti-alert-triangle me-2 fs-3"></span>
              <div>
                <strong>Not configured.</strong>
                Enter your Cloudflare API token and Tunnel ID in
                <a href="?page=settings" class="alert-link">Settings</a> to get started.
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php
          $flash = $_SESSION['flash'] ?? null;
          unset($_SESSION['flash']);
          if ($flash): ?>
          <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible mb-4">
            <?= htmlspecialchars($flash['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
          <?php endif; ?>

          <?= $content ?>

        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js" defer></script>
</body>
</html>
