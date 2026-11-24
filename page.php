<?php
/**
 * A plain text page out of the `pages` table — terms, privacy, and anything
 * else added from the admin panel. Reached as /terms, /privacy, …
 *
 * The body is plain text and is escaped on the way out, so nothing an editor
 * types can put markup, let alone a script, onto the page.
 */
require __DIR__ . '/inc/bootstrap.php';

$slug = (string) ($_GET['slug'] ?? '');
$row  = (preg_match('~^[a-z0-9-]{1,60}$~', $slug) && db_ready())
    ? q1('SELECT * FROM pages WHERE slug = ? LIMIT 1', [$slug])
    : null;

if (!$row) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$paragraphs = preg_split('/\n\s*\n/', (string) $row['body']) ?: [];

$page = [
    'title'     => $row['title'],
    'desc'      => mb_substr(trim((string) $row['body']), 0, 155),
    'canonical' => '/' . $row['slug'],
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here"><?= e($row['title']) ?></span>
    </nav>

    <h1 class="h-page"><?= e($row['title']) ?></h1>

    <div class="card card--pad-lg measure" style="margin-top:24px;max-width:46em">
      <?php foreach ($paragraphs as $para): if (trim($para) === '') { continue; } ?>
        <p style="color:var(--ink-3);line-height:1.75;margin-bottom:16px"><?= nl2br(e(trim($para))) ?></p>
      <?php endforeach; ?>

      <p class="tiny muted" style="margin-top:8px">Last updated <?= e(date('j F Y', strtotime((string) $row['updated_at']))) ?>.</p>
    </div>

    <div class="row row--tight" style="margin-top:24px">
      <a class="small" style="font-weight:600" href="/policy">Replacement &amp; refunds &rarr;</a>
      <a class="small" style="font-weight:600" href="/safety">Safety standards &rarr;</a>
      <a class="small" style="font-weight:600" href="/faq">FAQ &rarr;</a>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
