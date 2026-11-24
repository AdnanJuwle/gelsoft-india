<?php
/**
 * Local development only. PHP's built-in server has no .htaccess, so this does
 * the one thing the rewrite rules do: serve /blasters from blasters.php.
 *
 *   php -S localhost:8099 router.php
 *
 * Hostinger uses .htaccess instead and ignores this file entirely.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// A real file (css, js, an image): let the server hand it over as-is.
if ($path !== '/' && is_file($file)) {
    return false;
}

// A directory: its index.
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}

// /blasters -> blasters.php
$clean = rtrim($path, '/');
if ($clean !== '' && is_file(__DIR__ . $clean . '.php')) {
    require __DIR__ . $clean . '.php';
    return true;
}

// /listing/message -> listing-message.php
if ($clean === '/listing/message') {
    require __DIR__ . '/listing-message.php';
    return true;
}

// /game/message -> game-message.php
if ($clean === '/game/message') {
    require __DIR__ . '/game-message.php';
    return true;
}

// /sitemap.xml -> sitemap.php.
//
// This branch sits BELOW the is_file() short-circuit at the top, so it is
// reached only because the static sitemap.xml has been deleted. If a copy of
// that file ever reappears in the folder it wins, silently, and the
// difference does not show until something is missing from the sitemap.
if ($clean === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}

// /terms, /privacy -> page.php?slug=…
if (preg_match('~^/(terms|privacy)$~', $clean, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/page.php';
    return true;
}

// /marketplace/<slug> -> listing.php?slug=…
if (preg_match('~^/marketplace/([a-z0-9][a-z0-9-]*)$~', $clean, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/listing.php';
    return true;
}

// /blasters/tg-gen8-m4 -> product.php?slug=tg-gen8-m4
if (preg_match('~^/(blasters|gear|parts)/([a-z0-9][a-z0-9-]*)$~', $clean, $m)) {
    $_GET['slug'] = $m[2];
    require __DIR__ . '/product.php';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
require __DIR__ . '/404.php';
return true;
