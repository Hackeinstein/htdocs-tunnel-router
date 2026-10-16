<?php
declare(strict_types=1);

/**
 * Started by ApacheManager::reload() in its own process:   php reload_worker.php <state dir>
 * Restarts Apache and records the verified result in state/reload.json. Not reachable from the web.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(function (string $c): void { if (is_file($f = APP_ROOT . "/src/$c.php")) require_once $f; });

$state = $argv[1] ?? (APP_ROOT . '/state');
if (!is_dir($state)) { fwrite(STDERR, "state dir not found: $state\n"); exit(2); }
$res = (new ReloadWorker($state))->run();
exit($res['state'] === 'ok' ? 0 : 1);
