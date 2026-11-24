<?php
/**
 * Policy page editor. Picking a page in the dropdown loads that page's text —
 * which is the whole point, and is why the select is a real form control that
 * navigates rather than a decoration.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$all = db_ready() ? q('SELECT slug, title, updated_at FROM pages ORDER BY title') : [];

$slug = (string) ($_GET['slug'] ?? ($_POST['slug'] ?? ''));
$slugs = array_column($all, 'slug');
if (!in_array($slug, $slugs, true)) {
    $slug = $slugs[0] ?? '';
}

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $body  = (string) ($_POST['body'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));

    if ($slug === '' || $title === '' || trim($body) === '') {
        $notice = 'A page needs a title and some text.';
    } else {
        exec_sql('UPDATE pages SET title = ?, body = ? WHERE slug = ?', [$title, $body, $slug]);
        admin_log('page_edit', $slug, mb_substr($title, 0, 120));
        redirect('/admin/pages?slug=' . urlencode($slug) . '&saved=1');
    }
}

$current = $slug !== '' ? q1('SELECT * FROM pages WHERE slug = ?', [$slug]) : null;

/*
 * Is the page being edited the one the site actually serves?
 *
 * For 'policy' it is not: policy.php exists, the rewrite serves the file, and
 * this row is a second copy of the refund policy that nothing reads. Saving it
 * used to answer "Saved. The live page shows this now", which was false, and
 * false about the document a customer argues with.
 */
$shadowed = $current ? page_shadowed((string) $current['slug']) : false;

$page = ['title' => 'Policy pages'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>Policy pages</h1>
        <p>Replacement, terms and privacy. Plain text in, page out.</p>
      </div>
      <?php if ($current && !$shadowed): ?>
        <a class="btn btn--sm" href="/<?= e($current['slug']) ?>">View live page</a>
      <?php elseif ($current): ?>
        <a class="btn btn--sm" href="/<?= e($current['slug']) ?>">See what /<?= e($current['slug']) ?> shows</a>
      <?php endif; ?>
    </div>

    <?php if ($notice): ?>
      <p class="notice notice--paper" role="alert"><span class="small"><?= e($notice) ?></span></p>
    <?php elseif (isset($_GET['saved']) && !$shadowed): ?>
      <p class="notice notice--olive" role="status"><span class="small">Saved. The live page shows this now.</span></p>
    <?php elseif (isset($_GET['saved'])): ?>
      <p class="notice notice--paper" role="status"><span class="small">Saved to the database &mdash; but see the note below: this is not the text <code>/<?= e($current['slug']) ?></code> shows.</span></p>
    <?php endif; ?>

    <?php if ($shadowed): ?>
      <?php
      /*
       * Said here, where the person about to edit it is looking, rather than
       * left in a comment in a file they will never open.
       */
      ?>
      <p class="notice notice--ember" role="alert" style="margin-bottom:18px"><span class="small">
        <strong>This text is not on the site.</strong>
        <code>/<?= e($current['slug']) ?></code> is served by
        <code><?= e($current['slug']) ?>.php</code>, a hand-built page, and that
        file is what a customer reads. This row is a second copy: you can save
        it, and nothing will change. To change the live page, edit
        <code><?= e($current['slug']) ?>.php</code> &mdash; or delete this row,
        so the shop holds one copy of the policy instead of two that can
        disagree.
      </span></p>
    <?php endif; ?>

    <?php if (!$current): ?>
      <div class="card card--pad-lg">
        <p class="small muted">No pages in the database yet. Import <code>db/seed-content.sql</code> to create them.</p>
      </div>
    <?php else: ?>
      <div class="card card--pad-lg">
        <form class="row row--center" method="get" action="/admin/pages" style="gap:14px;margin-bottom:18px" data-autosubmit>
          <label class="field__label" style="margin:0" for="slug">Page</label>
          <select class="select" id="slug" name="slug" style="width:auto;min-height:42px">
            <?php foreach ($all as $p): ?>
              <option value="<?= e($p['slug']) ?>"<?= $p['slug'] === $slug ? ' selected' : '' ?>><?= e($p['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn--xs" type="submit">Open</button></noscript>
          <span style="flex-grow:1"></span>
          <span class="tiny muted">Last edited <?= e(date('j M Y, H:i', strtotime((string) $current['updated_at']))) ?></span>
        </form>

        <form method="post" action="/admin/pages">
          <?= csrf_field() ?>
          <input type="hidden" name="slug" value="<?= e($current['slug']) ?>">

          <div class="field">
            <label class="field__label" for="title">Page title</label>
            <input class="input" id="title" name="title" maxlength="160" required value="<?= e($current['title']) ?>">
          </div>

          <div class="field">
            <label class="field__label" for="body">Page text</label>
            <textarea class="textarea" id="body" name="body" rows="16" required><?= e($current['body']) ?></textarea>
            <p class="field__hint">Plain text. A blank line starts a new paragraph. Nothing here is treated as HTML, so a stray &lt; cannot break the page.</p>
          </div>

          <div class="row row--tight row--center" style="margin-top:18px">
            <button class="btn btn--primary btn--sm" type="submit">Save and publish</button>
            <span class="tiny muted">Saving replaces the live page immediately.</span>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
