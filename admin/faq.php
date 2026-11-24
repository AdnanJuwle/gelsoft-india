<?php
/**
 * FAQ editor. Add, edit, reorder, publish and delete — all plain form posts,
 * so Edit and Remove do what their labels say with or without JavaScript.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$notice = '';
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $question = trim((string) ($_POST['question'] ?? ''));
        $answer   = trim((string) ($_POST['answer'] ?? ''));

        if ($question === '' || $answer === '') {
            $notice = 'A question needs both a question and an answer.';
            $editing = ['id' => $id, 'question' => $question, 'answer' => $answer, 'published' => 1];
        } else {
            $published = isset($_POST['published']) ? 1 : 0;
            if ($id > 0) {
                exec_sql('UPDATE faq_entries SET question = ?, answer = ?, published = ? WHERE id = ?',
                    [$question, $answer, $published, $id]);
                admin_log('faq_edit', (string) $id, mb_substr($question, 0, 120));
            } else {
                $next = (int) (q1('SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM faq_entries')['n'] ?? 10);
                exec_sql('INSERT INTO faq_entries (question, answer, published, sort_order) VALUES (?, ?, ?, ?)',
                    [$question, $answer, $published, $next]);
                admin_log('faq_add', null, mb_substr($question, 0, 120));
            }
            redirect('/admin/faq?saved=1');
        }
    } elseif ($action === 'delete' && $id > 0) {
        $row = q1('SELECT question FROM faq_entries WHERE id = ?', [$id]);
        exec_sql('DELETE FROM faq_entries WHERE id = ?', [$id]);
        admin_log('faq_delete', (string) $id, mb_substr((string) ($row['question'] ?? ''), 0, 120));
        redirect('/admin/faq?deleted=1');
    } elseif ($action === 'move' && $id > 0) {
        // Swap with the neighbour instead of renumbering the lot: fewer rows
        // touched, and the order cannot end up with duplicates.
        $dir  = ($_POST['dir'] ?? 'up') === 'down' ? 'down' : 'up';
        $self = q1('SELECT id, sort_order FROM faq_entries WHERE id = ?', [$id]);
        if ($self) {
            $neighbour = $dir === 'up'
                ? q1('SELECT id, sort_order FROM faq_entries WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1', [$self['sort_order']])
                : q1('SELECT id, sort_order FROM faq_entries WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1', [$self['sort_order']]);
            if ($neighbour) {
                exec_sql('UPDATE faq_entries SET sort_order = ? WHERE id = ?', [$neighbour['sort_order'], $self['id']]);
                exec_sql('UPDATE faq_entries SET sort_order = ? WHERE id = ?', [$self['sort_order'], $neighbour['id']]);
                admin_log('faq_reorder', (string) $id, $dir);
            }
        }
        redirect('/admin/faq');
    }
}

if ($editing === null && isset($_GET['edit'])) {
    $editing = q1('SELECT * FROM faq_entries WHERE id = ?', [(int) $_GET['edit']]);
}
if (isset($_GET['new'])) {
    $editing = ['id' => 0, 'question' => '', 'answer' => '', 'published' => 1];
}

$entries = db_ready() ? q('SELECT * FROM faq_entries ORDER BY sort_order, id') : [];
$count   = count($entries);

$page = ['title' => 'FAQ'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>FAQ</h1>
        <p>The questions on the public FAQ page, in the order they appear there.</p>
      </div>
      <div class="row row--tight">
        <a class="btn btn--sm" href="/faq">View live page</a>
        <a class="btn btn--primary btn--sm" href="/admin/faq?new=1">Add a question</a>
      </div>
    </div>

    <?php if ($notice): ?>
      <p class="notice notice--paper" role="alert"><span class="small"><?= e($notice) ?></span></p>
    <?php elseif (isset($_GET['saved'])): ?>
      <p class="notice notice--olive" role="status"><span class="small">Saved. It is live on the FAQ page now.</span></p>
    <?php elseif (isset($_GET['deleted'])): ?>
      <p class="notice notice--olive" role="status"><span class="small">Removed from the FAQ page.</span></p>
    <?php endif; ?>

    <?php if ($editing !== null): ?>
      <div class="card card--pad-lg" style="margin-bottom:20px">
        <h2 class="h-card" style="margin-bottom:16px"><?= $editing['id'] ? 'Edit question' : 'New question' ?></h2>
        <form method="post" action="/admin/faq">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

          <div class="field">
            <label class="field__label" for="question">Question</label>
            <input class="input" id="question" name="question" maxlength="255" required
                   value="<?= e($editing['question']) ?>">
          </div>

          <div class="field">
            <label class="field__label" for="answer">Answer</label>
            <textarea class="textarea" id="answer" name="answer" rows="8" required><?= e($editing['answer']) ?></textarea>
            <p class="field__hint">Plain text. A blank line starts a new paragraph.</p>
          </div>

          <label class="checkline" style="margin-top:14px">
            <input type="checkbox" name="published" value="1"<?= $editing['published'] ? ' checked' : '' ?>>
            <span class="name">Show it on the FAQ page</span>
          </label>

          <div class="row row--tight row--center" style="margin-top:20px">
            <button class="btn btn--primary btn--sm" type="submit">Save</button>
            <a class="btn btn--sm" href="/admin/faq">Cancel</a>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <?php if (!$entries): ?>
        <div class="rowline"><p class="small muted" style="margin:0">Nothing here yet.</p></div>
      <?php else: foreach ($entries as $i => $row): ?>
        <div class="rowline">
          <span class="actions">
            <form method="post" action="/admin/faq" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="dir" value="up">
              <button class="btn btn--xs" type="submit" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>>&uarr;</button>
            </form>
            <form method="post" action="/admin/faq" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <input type="hidden" name="dir" value="down">
              <button class="btn btn--xs" type="submit" aria-label="Move down"<?= $i === $count - 1 ? ' disabled' : '' ?>>&darr;</button>
            </form>
          </span>
          <span class="title"><?= e($row['question']) ?></span>
          <span class="meta">
            <?= mb_strlen((string) $row['answer']) ?> chars
            <?= $row['published'] ? '' : ' &middot; <span class="tag-warn">hidden</span>' ?>
          </span>
          <span class="actions">
            <a class="btn btn--xs" href="/admin/faq?edit=<?= (int) $row['id'] ?>">Edit</a>
            <form method="post" action="/admin/faq" style="display:inline" data-confirm="Remove &ldquo;<?= e($row['question']) ?>&rdquo; from the FAQ?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button class="btn btn--xs" type="submit" style="color:var(--ember-dark)">Remove</button>
            </form>
          </span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
