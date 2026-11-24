<?php
/**
 * Create a customer account.
 *
 * The role is set in SQL here, not taken from the form — a role that comes
 * from a form is a role anyone can give themselves.
 */
require __DIR__ . '/inc/bootstrap.php';

$next  = safe_path((string) ($_GET['next'] ?? '/account'));
$error = '';

if (current_user()) {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone    = trim((string) ($_POST['phone'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        $error = 'Tell us what to call you.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'That does not look like an email address.';
    } elseif ($phone !== '' && !preg_match('/^\+?[0-9 \-]{10,15}$/', $phone)) {
        $error = 'That does not look like a phone number.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } elseif (!db_ready()) {
        $error = 'We cannot create accounts just now. Try again in a minute.';
    } elseif (auth_signup_blocked()) {
        // Checked after the form is otherwise valid, so a person who simply
        // mistyped their email is told that instead of being throttled.
        $error = 'That is several accounts from this connection in the last hour. '
               . 'If one of them is yours, sign in; otherwise give it a few minutes.';
    } else {
        try {
            exec_sql('INSERT INTO users (name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, ?)', [
                $name,
                $email,
                $phone !== '' ? $phone : null,
                password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]),
                'customer',
            ]);
            auth_log_signup($email);
            redirect('/login?registered=1&next=' . urlencode($next));
        } catch (PDOException $e) {
            // 23000 is the unique key on email. Saying "already registered"
            // does leak that the address exists — but a sign-up form always
            // does, and the alternative is a dead end for whoever forgot.
            $error = ((int) $e->getCode() === 23000)
                ? 'There is already an account on that email. Sign in instead.'
                : 'We could not create that account. Try again in a minute.';
            if ((int) $e->getCode() !== 23000) {
                error_log('register failed: ' . $e->getMessage());
            }
        }
    }
}

$page = [
    'title'   => 'Create an account',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="max-width:460px">
    <h1 class="h-page" style="font-size:30px">Create an account</h1>
    <p class="muted" style="margin-top:10px">You need one to buy, to sell, and to message anyone about a listing.</p>

    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="margin-top:22px;color:var(--bad)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><?= e($error) ?></span>
      </p>
    <?php endif; ?>

    <form class="card card--pad-lg" method="post" action="/register" style="margin-top:22px">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="field">
        <label class="field__label" for="name">Name</label>
        <input class="input" id="name" name="name" maxlength="120" required autocomplete="name"
               value="<?= e($_POST['name'] ?? '') ?>" autofocus>
      </div>

      <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" maxlength="190" required autocomplete="email"
               value="<?= e($_POST['email'] ?? '') ?>">
      </div>

      <div class="field">
        <label class="field__label" for="phone">Phone <span class="opt">optional</span></label>
        <input class="input" id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel"
               value="<?= e($_POST['phone'] ?? '') ?>">
        <p class="field__hint">Only used for a delivery, never for marketing.</p>
      </div>

      <div class="field">
        <label class="field__label" for="password">Password</label>
        <input class="input" id="password" name="password" type="password" required minlength="10"
               autocomplete="new-password">
        <p class="field__hint">At least 10 characters. Length does more than symbols &mdash; three unrelated words beat <em>P@ssw0rd</em>.</p>
      </div>

      <button class="btn btn--primary btn--block" type="submit" style="margin-top:22px">Create account</button>
    </form>

    <p class="small muted" style="margin-top:20px">
      Already have one? <a href="/login" style="font-weight:600">Sign in</a>.
    </p>
  </div>
<?php require __DIR__ . '/inc/footer.php';
