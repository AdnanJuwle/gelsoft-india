<?php
/**
 * Customer sign-in.
 *
 * One message for a wrong address and a wrong password, deliberately: two
 * messages tell whoever is guessing which addresses are worth attacking.
 */
require __DIR__ . '/inc/bootstrap.php';

$next  = safe_path((string) ($_GET['next'] ?? '/account'));
$error = '';

if (current_user()) {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (auth_ip_blocked()) {
        $error = 'Too many attempts from this connection. Wait a few minutes and try again.';
    } elseif (!db_ready()) {
        // auth_attempt() returns null here too, and saying "that password is
        // wrong" about a password nobody checked sends people to the reset
        // form, which cannot work either.
        $error = AUTH_DB_DOWN;
    } else {
        $user = auth_attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($user === null) {
            $error = 'That email and password do not match an account.';
            usleep(random_int(200_000, 500_000));
        } else {
            redirect(safe_path((string) ($_POST['next'] ?? '/account')));
        }
    }
}

$page = [
    'title'   => 'Sign in',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="max-width:460px">
    <h1 class="h-page" style="font-size:30px">Sign in</h1>
    <p class="muted" style="margin-top:10px">For your orders, your listings, and the conversations on them.</p>

    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="margin-top:22px;color:var(--bad)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><?= e($error) ?></span>
      </p>
    <?php endif; ?>

    <?php if (isset($_GET['registered'])): ?>
      <p class="notice notice--olive" role="status" style="margin-top:22px"><span class="small">Account created. Sign in with it.</span></p>
    <?php endif; ?>

    <form class="card card--pad-lg" method="post" action="/login" style="margin-top:22px">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" required autocomplete="username"
               value="<?= e($_POST['email'] ?? '') ?>" autofocus>
      </div>

      <div class="field">
        <label class="field__label" for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
      </div>

      <button class="btn btn--primary btn--block" type="submit" style="margin-top:22px">Sign in</button>
    </form>

    <p class="small muted" style="margin-top:14px">
      <a href="/forgot" style="font-weight:600">Forgotten your password?</a>
    </p>

    <p class="small muted" style="margin-top:8px">
      No account? <a href="/register<?= $next !== '/account' ? '?next=' . e(urlencode($next)) : '' ?>" style="font-weight:600">Create one</a> &mdash; it takes a minute and you need one to buy or sell.
    </p>
  </div>
<?php require __DIR__ . '/inc/footer.php';
