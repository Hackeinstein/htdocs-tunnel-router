<?php
declare(strict_types=1);

// The portal page: a login form and a small file manager. All logic is in assets/portal.js and api.php.
ini_set('display_errors', '0');
define('APP_ROOT', dirname(__DIR__));
define('STATE_DIR', APP_ROOT . '/state');
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(function (string $class): void { if (is_file($f = APP_ROOT . "/src/$class.php")) require_once $f; });
Config::init(STATE_DIR);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
if ($why = PortalAuth::guard($_SERVER, Config::load())) {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><title>Not found</title><body style="font:16px system-ui;margin:20vh auto;max-width:28rem;text-align:center;color:#555"><h1 style="font-size:1.2rem">Nothing here</h1>');
}
// Scripts only from this site and the editor's CDN; no inline script; nothing can frame the page.
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob:; font-src 'self' data: https://cdn.jsdelivr.net; connect-src 'self'; worker-src blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

function pa(string $f): string { $p = __DIR__ . '/assets/' . $f; return 'assets/' . $f . (is_file($p) ? '?v=' . filemtime($p) : ''); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>File portal</title>
  <link rel="stylesheet" href="<?= pa('app.css') ?>">
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
  <div id="app" class="mx-auto max-w-5xl px-4 py-8"><p class="py-20 text-center text-gray-500">Loading…</p></div>
  <noscript><p class="p-8 text-center">This page needs JavaScript.</p></noscript>
  <script src="<?= pa('icons.js') ?>"></script>
  <script src="<?= pa('portal.js') ?>"></script>
</body>
</html>
