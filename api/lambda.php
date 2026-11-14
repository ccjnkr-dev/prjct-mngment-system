<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Vercel terminates HTTPS before forwarding requests to PHP.
if (($_ENV['VERCEL'] ?? $_SERVER['VERCEL'] ?? getenv('VERCEL')) === '1') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
}

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
