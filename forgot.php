<?php
/**
 * "I have forgotten my password."
 *
 * The page says the same thing whether or not the address has an account.
 * That is deliberate and it is the main security decision here: a form that
 * answers "no such account" is a tool for working out which addresses are
 * worth attacking, and the person who really did forget cannot tell the
 * difference anyway — they go and look in their inbox either way.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/resets.php';

if (current_user()) {
    redirect('/account');
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $email = trim((string) ($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That does not look like an email address.';
    } elseif (auth_ip_blocked()) {
        $error = 'Too many attempts from this connection. Wait a few minutes.';
    } elseif (!db_ready()) {
        // "Check your email" is answered whether or not the address exists,
        // which is right -- but during an outage no token is written and no
        // mail is sent, so it would be a promise of a message that is never
        // coming.
        $error = AUTH_DB_DOWN;
    } else {
        reset_prune();

        $token = reset_begin($email);

        if ($token !== null) {
            $link = rtrim((string) cfg('site_url'), '/') . '/reset?token=' . urlencode($token);

            send_mail($email, 'Reset your password',
                "Somebody asked to reset the password on your Gelsoft India account.\n\n"
                . "{$link}\n\n"
                . "That link works once and expires in " . RESET_TTL_MINUTES . " minutes.\n\n"
                . "If it was not you, ignore this — your password has not changed and nobody\n"
                . "can do anything without this email.\n\nGelsoft India\n",
                'password_reset');
        }

        // Recorded whether or not an account existed, so the rate limit
        // cannot be sidestepped by guessing addresses.
        auth_log_attempt($email, false);

        $sent = true;
    }
}

$page = [
    'title'   => 'Reset your password',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="max-width:460px">
    <h1 class="h-page" style="font-size:30px">Reset your password</h1>

    <?php if ($sent): ?>
      <div class="notice notice--olive" role="status" style="margin-top:24px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 6h16v12H4z" stroke-linejoin="round"/><path d="M4 7l8 6 8-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <h2 style="font-size:18px;font-weight:700;margin-bottom:6px">Check your email</h2>
          <p class="small">If that address has an account, a reset link is on its way. It works once and expires in <?= RESET_TTL_MINUTES ?> minutes.</p>
          <p class="small" style="margin-top:10px">Nothing arriving? Look in spam, and check you typed the address you signed up with.</p>
        </div>
      </div>

      <p class="small muted" style="margin-top:22px">
        <a href="/login" style="font-weight:600">Back to sign in</a>
      </p>

    <?php else: ?>
      <p class="muted" style="margin-top:10px">Put in the address you signed up with and we will email you a link.</p>

      <?php if ($error): ?>
        <p class="notice notice--paper" role="alert" style="margin-top:22px;color:var(--bad)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
          <span class="small"><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <form class="card card--pad-lg" method="post" action="/forgot" style="margin-top:22px">
        <?= csrf_field() ?>
        <div class="field" style="margin:0">
          <label class="field__label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" required autocomplete="username"
                 value="<?= e($_POST['email'] ?? '') ?>" autofocus>
        </div>
        <button class="btn btn--primary btn--block" type="submit" style="margin-top:20px">Email me a link</button>
      </form>

      <p class="small muted" style="margin-top:20px">
        Remembered it? <a href="/login" style="font-weight:600">Sign in</a>.
      </p>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
