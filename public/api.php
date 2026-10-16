<?php
declare(strict_types=1);

// The portal's API. This folder (public/) is the ONLY part of the tool the portal hostname's vhost points at; everything
// else (admin pages, state/, src/) sits outside the web root of that hostname.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ROOT', dirname(__DIR__));
define('STATE_DIR', APP_ROOT . '/state');
require_once APP_ROOT . '/src/helpers.php';
spl_autoload_register(function (string $class): void { if (is_file($f = APP_ROOT . "/src/$class.php")) require_once $f; });

Config::init(STATE_DIR);
PortalApi::run(STATE_DIR);
