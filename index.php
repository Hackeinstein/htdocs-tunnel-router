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

session_start();

Config::init(STATE_DIR);

// Redirect to settings on first run
if (!Config::isConfigured() && ($_GET['page'] ?? '') !== 'settings') {
    header('Location: ?page=settings&first_run=1');
    exit;
}

$page = preg_replace('/[^a-z_]/', '', strtolower($_GET['page'] ?? 'dashboard'));
$validPages = ['dashboard', 'attach', 'detach', 'reconcile', 'settings', 'activity'];
if (!in_array($page, $validPages, true)) $page = 'dashboard';

$viewFile = APP_ROOT . '/views/' . $page . '.php';

ob_start();
require $viewFile;
$content = ob_get_clean();

require APP_ROOT . '/views/layout.php';
