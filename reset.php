<?php
/**
 * Setting a new password from a reset link.
 *
 * The token arrives in the query string, which means it ends up in browser
 * history and in any Referer header the page sends. So: the link is spent the
 * moment it is used, it expires in an hour, and this page sends no referrer
 * at all — see the meta below.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/resets.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$row   = reset_user_for($token);

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $new   = (string) ($_POST['password'] ?? '');
    $again = (string) ($_POST['password2'] ?? '');

    if (!db_ready()) {
        // Without this the token lookup fails and the page says the link has
        // expired -- about a link that is perfectly good.
        $error = AUTH_DB_DOWN;
    } elseif ($new !== $again) {
        $error = 'The two passwords do not match.';
    } else {
        $problem = reset_complete($token, $new);
        if ($problem === null) {
            // Signed out everywhere: whoever was using the old password is
            // not carried into the new one.
            session_destroy_fully();
            $done = true;
        } else {
            $error = $problem;
            $row = reset_user_for($token);
        }
    }
}

$page = [
    'title'   => 'Set a new password',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <!-- The token is in the URL, so this page must not leak it in a Referer. -->
  <meta name="referrer" content="no-referrer">

  <div class="wrap section" style="max-width:460px">

    <?php if ($done): ?>
      <h1 class="h-page" style="font-size:30px">Done</h1>
      <div class="notice notice--olive" role="status" style="margin-top:22px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <h2 style="font-size:18px;font-weight:700;margin-bottom:6px">Your password is changed</h2>
          <p class="small">Anything else signed in as you has been signed out, and that link will not work again.</p>
        </div>
      </div>
      <a class="btn btn--primary btn--block" href="/login" style="margin-top:22px">Sign in</a>

    <?php elseif (!$row): ?>
      <h1 class="h-page" style="font-size:30px">That link has gone</h1>
      <p class="muted" style="margin-top:12px">
        Reset links work once and last <?= RESET_TTL_MINUTES ?> minutes. This one has been used,
        has expired, or was never quite right &mdash; links sometimes get broken in half by an
        email client.
      </p>
      <a class="btn btn--primary btn--block" href="/forgot" style="margin-top:22px">Send me a new one</a>

    <?php else: ?>
      <h1 class="h-page" style="font-size:30px">Set a new password</h1>
      <p class="muted" style="margin-top:10px">For <strong><?= e($row['email']) ?></strong>.</p>

      <?php if ($error): ?>
        <p class="notice notice--paper" role="alert" style="margin-top:22px;color:var(--bad)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
          <span class="small"><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <form class="card card--pad-lg" method="post" action="/reset" style="margin-top:22px">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field" style="margin:0">
          <label class="field__label" for="password">New password</label>
          <input class="input" id="password" name="password" type="password" required minlength="10"
                 autocomplete="new-password" autofocus>
          <p class="field__hint">At least 10 characters. Three unrelated words beat <em>P@ssw0rd</em> &mdash; length is what makes a password hard to guess.</p>
        </div>

        <div class="field">
          <label class="field__label" for="password2">Again</label>
          <input class="input" id="password2" name="password2" type="password" required minlength="10"
                 autocomplete="new-password">
        </div>

        <button class="btn btn--primary btn--block" type="submit" style="margin-top:20px">Change it</button>
      </form>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
