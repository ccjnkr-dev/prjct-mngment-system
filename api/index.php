<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

$root = dirname(__DIR__);
$defaults = [
    'LARAVEL_STORAGE_PATH' => '/tmp/laravel-storage',
    'VIEW_COMPILED_PATH' => '/tmp/laravel-storage/framework/views',
    'APP_CONFIG_CACHE' => '/tmp/laravel-config.php',
    'APP_SERVICES_CACHE' => '/tmp/laravel-services.php',
    'APP_PACKAGES_CACHE' => '/tmp/laravel-packages.php',
    'APP_ROUTES_CACHE' => '/tmp/laravel-routes.php',
    'APP_EVENTS_CACHE' => '/tmp/laravel-events.php',
    'LOG_CHANNEL' => 'stderr',
];
foreach ($defaults as $key => $fallback) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        $value = $fallback;
    }
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$storage = $_ENV['LARAVEL_STORAGE_PATH'] ?? $_SERVER['LARAVEL_STORAGE_PATH'] ?? getenv('LARAVEL_STORAGE_PATH');
foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    $path = $storage.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
        throw new RuntimeException('Could not initialize Laravel temporary storage.');
    }
}
// Vercel terminates HTTPS before forwarding to the community PHP server.
if (($_ENV['VERCEL'] ?? $_SERVER['VERCEL'] ?? getenv('VERCEL')) === '1') {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
}

define('LARAVEL_START', microtime(true));
require $root.'/vendor/autoload.php';
/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
// This manifest is built independently by the PHP builder's Composer hook.
$app->usePublicPath($root.'/vercel-php-public');
$app->handleRequest(Request::capture());
