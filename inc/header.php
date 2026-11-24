<?php
/**
 * The top of every storefront page. The page sets $page before requiring this:
 *
 *   $page = ['title' => 'Blasters', 'desc' => '…', 'nav' => 'blasters'];
 *   require __DIR__ . '/inc/header.php';
 *
 * Keys, all optional:
 *   title       page name; " — Gelsoft India" is appended
 *   title_full  the whole <title>, when the page name alone will not do
 *   desc        meta description
 *   nav         which nav item is current: blasters|gear|parts|marketplace|request|games
 *   util        'default' | 'marketplace' | 'none' | ['note' => '…', 'label' => '…', 'href' => '…']
 *   css         extra stylesheets by name, e.g. ['admin']
 *   noindex     true to keep it out of search results
 *   body_class  extra class on <body>
 *   main_class  extra class on <main>
 *   canonical   path for the canonical link, defaults to the current path
 *   image       path to the picture a shared link should show, e.g. a
 *               product photo. Falls back to the site card.
 *   schema      one schema.org graph, or a list of them, rendered as
 *               JSON-LD. Pass arrays; jsonld() does the encoding.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$page = ($page ?? []) + [
    'title'      => null,
    'title_full' => null,
    'desc'       => null,
    'nav'        => '',
    'util'       => 'default',
    'css'        => [],
    'noindex'    => false,
    'body_class' => '',
    'main_class' => '',
    'main_style' => '',
    'canonical'  => null,
    'image'      => null,
    'schema'     => null,
];

$site = (string) cfg('site_name', 'Gelsoft India');

$title = $page['title_full']
    ?? ($page['title'] !== null ? $page['title'] . ' — ' . $site : $site);

// The storefront nav, in order. Adding a page is one line here.
$NAV = [
    'blasters'    => ['Blasters', '/blasters'],
    'gear'        => ['Gear', '/gear'],
    'parts'       => ['Parts', '/parts'],
    'marketplace' => ['Marketplace', '/marketplace'],
    'request'     => ['Request a model', '/request'],
];

// The strip above the header. Three presets, or pass your own.
$UTIL = [
    'default' => [
        'note'  => 'Eye protection on before anyone loads &middot; carry it concealed &middot; ships across India',
        'label' => 'Sell your gear',
        'href'  => '/sell',
    ],
    'marketplace' => [
        'note'  => 'Payment held until you confirm delivery &middot; 48 hours to inspect &middot; ships across India',
        'label' => 'Sell your gear',
        'href'  => '/sell',
    ],
];

$util = is_array($page['util'])
    ? $page['util'] + ['note' => '', 'label' => '', 'href' => '']
    : ($UTIL[$page['util']] ?? null);

// The cart badge is on every page, so the one function that knows the shape
// of the cart lives here too rather than each page counting it its own way.
require_once __DIR__ . '/cart.php';
$cart_count = cart_count();

$me = current_user();

$canonical = rtrim((string) cfg('site_url'), '/')
    . ($page['canonical'] ?? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($page['desc']): ?>
<meta name="description" content="<?= e($page['desc']) ?>">
<?php endif; ?>
<?php if ($page['noindex']): ?>
<meta name="robots" content="noindex">
<?php else: ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<?php endif; ?>
<?php
/*
 * Open Graph, because most of this site's links are shared on WhatsApp and
 * WhatsApp reads exactly these tags. Without them a product link posted into
 * a group renders as a bare blue URL -- no name, no price, no picture, and
 * nothing to tell anyone whether it is worth tapping.
 *
 * Built from the title and description the page already set, so a page gets
 * it by existing rather than by remembering to opt in. $page['image'] is the
 * only thing a page adds, and only when it has a better picture than the
 * site's own card.
 */
// Only a picture that exists. og-image.jpg was named here before it was
// ever uploaded, so every shared link sent crawlers to a 404. Now: the
// page's own picture, else the share card, else the hero photo, else
// none at all -- a card with no picture beats one with a broken picture.
$share_image = $page['image']
    ?: (site_photo('og-image.jpg') ?? site_photo('hero-blaster.jpg') ?? '');
$share_image_url = $share_image !== '' ? abs_url($share_image) : '';
$share_desc = $page['desc'] ?: 'Gel blasters, gear and parts, shipped across India, with a second-hand marketplace.';
?>
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($share_desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if ($share_image_url !== ''): ?>
<meta property="og:image" content="<?= e($share_image_url) ?>">
<?php endif; ?>
<meta property="og:locale" content="en_IN">
<meta name="twitter:card" content="<?= $share_image_url !== '' ? 'summary_large_image' : 'summary' ?>">
<?php
/*
 * Structured data. A search result for a blaster can carry its price and
 * whether it is in stock, and a breadcrumb trail replaces the bare URL under
 * the title -- both of which this site had the data for and was not saying.
 *
 * Nothing here is invented: no rating, no review count, no availability the
 * page itself does not show. A rich result that disagrees with the page is
 * worse than none, and a made-up rating would be a lie about customers we
 * have not asked.
 */
$graphs = $page['schema'];
if (is_array($graphs) && $graphs) {
    foreach (array_is_list($graphs) ? $graphs : [$graphs] as $graph) {
        if (is_array($graph) && $graph) {
            echo jsonld($graph), "
";
        }
    }
}
?>
<meta name="theme-color" content="#15171A">
<?php
/*
 * The tab icon. Until now there was none at all, so every page load ended in
 * a request for /favicon.ico that answered 404, and a tab in a row of tabs
 * had nothing on it but the start of the title.
 *
 * Declared explicitly rather than left to the browser's own /favicon.ico
 * guess: that guess cannot be versioned, cannot be an SVG, and is the request
 * that was 404ing. asset() appends the file's mtime, so replacing the mark
 * replaces it for everyone instead of waiting out a year-long cache.
 *
 * iOS home screens want a 180x180 PNG, which is a picture and so is on the
 * list in assets/img/NEEDED.md rather than invented here. It is linked
 * the moment it is uploaded, and not before.
 */
?>
<?php /* A PNG as well as the SVG: Google's result icon wants a raster
         image that is a multiple of 48px square. Same artwork --
         favicon-192.png is favicon.svg rendered at 192px. */ ?>
<?php if ($png_icon = site_photo('favicon-192.png')): ?>
<link rel="icon" type="image/png" sizes="192x192" href="<?= e($png_icon) ?>">
<?php endif; ?>
<link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
<?php if ($touch_icon = site_photo('apple-touch-icon.png')): ?>
<link rel="apple-touch-icon" href="<?= e($touch_icon) ?>">
<?php endif; ?>
<meta name="format-detection" content="telephone=no">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/site.css') ?>">
<?php foreach ((array) $page['css'] as $sheet): ?>
<link rel="stylesheet" href="<?= asset('assets/css/' . $sheet . '.css') ?>">
<?php endforeach; ?>
</head>
<body<?= $page['body_class'] ? ' class="' . e($page['body_class']) . '"' : '' ?>>

<a class="skip" href="#main">Skip to content</a>

<?php if ($util): ?>
<div class="util">
  <div class="wrap util__inner">
    <span><?= $util['note'] ?></span>
    <?php if ($util['label']): ?><a href="<?= e($util['href']) ?>"><?= e($util['label']) ?></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<header class="hdr">
  <div class="wrap hdr__inner">
    <a class="logo" href="/"><b>GELSOFT</b><span>INDIA</span></a>

    <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="nav" aria-label="Menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path class="nav-toggle__bars" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/><path class="nav-toggle__x" d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/></svg>
    </button>

    <!--
      The inner wrapper exists so the drawer can animate open: a grid row can
      transition 0fr to 1fr, but only with one child to collapse. On desktop
      it is display:contents, so the links lay out as if it were not there.
    -->
    <nav class="nav" id="nav" data-nav aria-label="Main">
      <div class="nav__inner">
        <?php foreach ($NAV as $key => [$label, $href]): ?>
        <a href="<?= e($href) ?>"<?= nav_current($key) ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <a class="nav__games" href="/games"<?= nav_current('games') ?>><span class="dot" aria-hidden="true"></span> Games in India</a>
        <?php if ($me): ?>
          <a class="nav__only-mobile" href="/account">Your account</a>
          <a class="nav__only-mobile" href="/messages">Messages</a>
        <?php else: ?>
          <a class="nav__only-mobile" href="/login">Sign in</a>
        <?php endif; ?>
        <form class="nav__search" action="/search" method="get" role="search">
          <label class="sr-only" for="q-mobile">Search the store</label>
          <input class="search" id="q-mobile" name="q" type="search" placeholder="Search model, part, brand&hellip;" value="<?= e($_GET['q'] ?? '') ?>">
        </form>
      </div>
    </nav>

    <div class="hdr__actions">
      <form class="search-form" action="/search" method="get" role="search">
        <label class="sr-only" for="q">Search the store</label>
        <input class="search" id="q" name="q" type="search" placeholder="Search model, part, brand&hellip;" value="<?= e($_GET['q'] ?? '') ?>">
      </form>
      <a class="icon-btn" href="<?= $me ? '/account' : '/login' ?>" aria-label="<?= $me ? 'Your account' : 'Sign in' ?>">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c0-3.6 3.1-5.6 7-5.6s7 2 7 5.6" stroke-linecap="round"/></svg>
      </a>
      <a class="cart-btn" href="/cart" aria-label="Cart, <?= $cart_count === 0 ? 'empty' : $cart_count . ' items' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h15l-1.5 9.5H6.5z" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.4" fill="currentColor"/><circle cx="16" cy="20" r="1.4" fill="currentColor"/></svg>
        <?= $cart_count ?>
      </a>
    </div>
  </div>
</header>

<main id="main"<?= $page['main_class'] ? ' class="' . e($page['main_class']) . '"' : '' ?><?= $page['main_style'] ? ' style="' . e($page['main_style']) . '"' : '' ?>>
