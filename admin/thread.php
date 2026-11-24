<?php
/**
 * Read one conversation, as an admin, when a sale goes wrong.
 *
 * Read-only on purpose. An admin who can post as somebody else destroys the
 * value of the record — the whole point is that what is here is what the two
 * of them actually said.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/messages.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $flag = isset($_POST['flagged']) ? 1 : 0;
    exec_sql('UPDATE threads SET flagged = ? WHERE id = ?', [$flag, $id]);
    admin_log($flag ? 'thread_flag' : 'thread_unflag', (string) $id);
    redirect('/admin/thread?id=' . $id);
}

$thread = db_ready()
    ? q1("SELECT t.*, l.title AS listing_title, l.slug AS listing_slug, l.price_paise,
                 g.title AS game_title,
                 COALESCE(l.title, g.title) AS subject_title,
                 CASE WHEN t.game_id IS NOT NULL THEN 'game' ELSE 'listing' END AS subject_kind,
                 b.name AS buyer_name, b.email AS buyer_email,
                 s.name AS seller_name, s.email AS seller_email
          FROM threads t
          LEFT JOIN listings l ON l.id = t.listing_id
          LEFT JOIN games g ON g.id = t.game_id
          JOIN users b ON b.id = t.buyer_id
          JOIN users s ON s.id = t.seller_id
          WHERE t.id = ?", [$id])
    : null;

if (!$thread) {
    http_response_code(404);
    $page = ['title' => 'Not found'];
    require dirname(__DIR__) . '/inc/admin_header.php';
    echo '<div class="wrap admin-shell"><main class="admin-main"><div class="card card--pad-lg">'
       . '<h1 style="font-size:22px;font-weight:700">No such conversation</h1>'
       . '<p class="small muted" style="margin-top:8px">It may have been deleted with its listing.</p>'
       . '<a class="btn btn--sm" href="/admin/" style="margin-top:16px">Back to admin</a>'
       . '</div></main></div>';
    require dirname(__DIR__) . '/inc/admin_footer.php';
    exit;
}

$messages = thread_messages($id);

$page = ['title' => 'Conversation'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>Conversation</h1>
        <p>
          About <strong><?= e((string) $thread['subject_title']) ?></strong><?php
            if ($thread['subject_kind'] === 'game'): ?> (a game)<?php
            else: ?> (<?= money((int) $thread['price_paise']) ?>)<?php endif; ?> &mdash;
          <?= e($thread['buyer_name']) ?> (<?= e($thread['buyer_email']) ?>) and
          <?= e($thread['seller_name']) ?> (<?= e($thread['seller_email']) ?>).
        </p>
      </div>
      <div class="row row--tight">
        <form method="post" action="/admin/thread?id=<?= (int) $id ?>">
          <?= csrf_field() ?>
          <?php if ($thread['flagged']): ?>
            <button class="btn btn--sm" type="submit">Remove flag</button>
          <?php else: ?>
            <input type="hidden" name="flagged" value="1">
            <button class="btn btn--sm" type="submit">Flag for review</button>
          <?php endif; ?>
        </form>
        <a class="btn btn--sm" href="/admin/">Back</a>
      </div>
    </div>

    <div class="card card--pad-lg">
      <div class="thread">
        <?php foreach ($messages as $m): $buyer = (int) $m['sender_id'] === (int) $thread['buyer_id']; ?>
          <?php if ($m['kind'] === 'message'): ?>
            <div class="bubble<?= $buyer ? '' : ' bubble--mine' ?>">
              <p class="tiny bubble__who">
                <?= e($m['sender_name']) ?> &middot; <?= e($buyer ? 'buyer' : 'seller') ?>
                &middot; <?= e(date('j M Y, H:i', strtotime((string) $m['created_at']))) ?>
              </p>
              <p class="small"><?= nl2br(e((string) $m['body'])) ?></p>
            </div>
          <?php else: ?>
            <p class="thread__event tiny">
              <?= e($m['sender_name']) ?> <?= e(message_line($m)) ?>
              &middot; <?= e(date('j M Y, H:i', strtotime((string) $m['created_at']))) ?>
            </p>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$messages): ?>
          <p class="small muted" style="text-align:center;padding:24px 0">The thread was opened but nothing was said.</p>
        <?php endif; ?>
      </div>

      <p class="tiny muted" style="margin-top:24px;padding-top:16px;border-top:1px solid var(--line)">
        Read-only. An admin posting as one of them would make this record worth nothing.
      </p>
    </div>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
