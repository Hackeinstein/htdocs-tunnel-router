<?php
declare(strict_types=1);

define('APP_ROOT',   __DIR__);
define('STATE_DIR',  APP_ROOT . '/state');
define('BACKUP_DIR', STATE_DIR . '/backups');

require_once APP_ROOT . '/src/helpers.php';

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (file_exists($file)) require_once $file;
});

ini_set('display_errors', '0');     // never print warnings/paths into the page
ini_set('log_errors', '1');

Config::init(STATE_DIR);
Guard::enforce((string)(Config::load()['security']['allowed_hosts'] ?? ''));   // private to this computer: see Guard
if ($err = Config::ensureState()) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;max-width:36rem;margin:15vh auto"><h1 style="font-size:1.3rem">Tunnel Router cannot start</h1><p>' . htmlspecialchars($err) . '</p>');
}
if (PHP_VERSION_ID < 80100) exit('Tunnel Router needs PHP 8.1 or newer (this server runs ' . PHP_VERSION . ').');

// Redirect to settings on first run
if (!Config::isConfigured() && ($_GET['page'] ?? '') !== 'settings') {
    header('Location: ?page=settings&first_run=1');
    exit;
}

$page = preg_replace('/[^a-z_]/', '', strtolower($_GET['page'] ?? 'dashboard'));
$validPages = ['dashboard', 'attach', 'detach', 'reconcile', 'settings', 'activity', 'domains', 'domain', 'tunnels', 'tunnel', 'dns', 'backup', 'health', 'files', 'logs', 'route', 'project', 'users'];
if (!in_array($page, $validPages, true)) $page = 'dashboard';

$viewFile = APP_ROOT . '/views/' . $page . '.php';

ob_start();
require $viewFile;
$content = ob_get_clean();

require APP_ROOT . '/views/layout.php';
