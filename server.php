<?php

/*
 * Router for `php artisan serve`, which uses this file instead of Laravel's own
 * when it exists (Illuminate\Foundation\Console\ServeCommand).
 *
 * It is Laravel's router plus one thing: Vite's built files are sent with a
 * one-year cache lifetime. PHP's built-in server sends static files with no
 * caching headers at all, so the browser downloaded the 636 KB stylesheet and
 * every script again on each click. Files under build/assets carry a hash of
 * their contents in their names, so a rebuilt file has a new name and is
 * fetched afresh; nothing stale is ever kept.
 *
 * A real web server (Nginx, Apache) does not read this file.
 */

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

$builtTypes = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];

if (str_starts_with($uri, '/build/assets/')) {
    $assets = realpath($publicPath.'/build/assets');
    $file = realpath($publicPath.$uri);
    $type = $builtTypes[strtolower(pathinfo($uri, PATHINFO_EXTENSION))] ?? null;

    // realpath() keeps a "/build/assets/../.." request inside the folder.
    if ($assets !== false && $file !== false && $type !== null
        && str_starts_with($file, $assets.DIRECTORY_SEPARATOR) && is_file($file)) {
        header('Content-Type: '.$type);
        header('Content-Length: '.filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            readfile($file);
        }

        return true;
    }
}

// From here on, Laravel's own router (vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php).

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
