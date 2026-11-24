<?php
/**
 * The admin bar. Same contract as inc/header.php, but no storefront nav and no
 * cart. Admin pages are noindex.
 *
 * Every page that requires this is behind require_admin(), except the sign-in
 * page itself, which sets $page['public'] = true.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$page = ($page ?? []) + [
    'title'   => 'Admin',
    'section' => '',
    'public'  => false,
];

if (!$page['public']) {
    require_admin();
}

$admin = current_user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($page['title']) ?> — Admin — Gelsoft India</title>
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
 * list in assets/img/NEEDED.md rather than invented here.
 */
?>
<link rel="icon" type="image/svg+xml" href="<?= asset('assets/img/favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/site.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
</head>
<body class="admin">

<header class="admin-bar">
  <div class="wrap">
    <span class="logo"><b>GELSOFT</b><span>INDIA</span></span>
    <span class="admin-badge">Admin</span>
    <span class="admin-bar__spacer"></span>
    <?php if ($admin): ?>
      <span class="small admin-bar__who">Signed in as <strong style="color:var(--on-dark);font-weight:600"><?= e($admin['name']) ?></strong></span>
      <a class="btn btn--ghost-dark btn--xs admin-bar__btn" href="/">View site</a>
      <form method="post" action="/admin/logout" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn--link" type="submit" style="color:var(--on-dark-3)">Sign out</button>
      </form>
    <?php else: ?>
      <a class="btn btn--ghost-dark btn--xs admin-bar__btn" href="/">View site</a>
    <?php endif; ?>
  </div>
</header>
