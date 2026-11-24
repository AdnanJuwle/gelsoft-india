<?php
/**
 * Admin sign-in. The only page under /admin/ that does not require a session.
 *
 * The hPanel directory password stays on as well — two locks on the room with
 * the money in it is not paranoid.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';

$next  = safe_path((string) ($_GET['next'] ?? '/admin/'));
$error = '';

if (is_admin()) {
    redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if (auth_ip_blocked()) {
        $error = 'Too many attempts from this connection. Wait a few minutes.';
    } elseif (!db_ready()) {
        // The owner reaching for the admin panel to find out WHY the site is
        // broken should not be told their password is wrong.
        $error = AUTH_DB_DOWN;
    } else {
        $user = auth_attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));

        if ($user === null) {
            // One message for a wrong address and a wrong password, because
            // two messages tell an attacker which addresses exist.
            $error = 'That email and password do not match an account.';
            usleep(random_int(200_000, 500_000));
        } elseif (!user_is_staff($user)) {
            admin_log('admin_login_denied', $user['email']);
            session_destroy_fully();
            $error = 'That account cannot open the admin panel.';
        } else {
            admin_log('admin_login', $user['email']);
            redirect(safe_path((string) ($_POST['next'] ?? '/admin/')));
        }
    }
}

$page = ['title' => 'Sign in', 'public' => true];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell admin-shell--narrow">
  <main class="admin-main" style="max-width:420px;margin:40px auto">
    <div class="card card--pad-lg">
      <h1 style="font-size:24px;font-weight:700;margin-bottom:8px">Admin sign-in</h1>
      <p class="small muted" style="margin-bottom:24px">This panel changes what the whole site shows. Sign in to open it.</p>

      <?php if (isset($_GET['created'])): ?>
        <p class="notice notice--olive" role="status" style="margin-bottom:20px">
          <span class="small">Admin account created. Sign in with it.</span>
        </p>
      <?php endif; ?>

      <?php if ($error): ?>
        <p class="notice notice--paper" role="alert" style="margin-bottom:20px;color:var(--bad)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
          <span class="small"><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <?php if (!db_ready()): ?>
        <p class="notice notice--paper" style="margin-bottom:20px">
          <span class="small"><strong>No database.</strong> Nobody can sign in until <code>inc/config.php</code> points at one.</span>
        </p>
      <?php endif; ?>

      <form method="post" action="/admin/login">
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

      <?php if (db_ready() && (int) (q1("SELECT COUNT(*) n FROM users WHERE role IN ('admin','owner')")['n'] ?? 0) === 0): ?>
        <p class="notice notice--olive" style="margin-top:20px">
          <span class="small">No admin account exists yet. <a href="/admin/setup" style="font-weight:600">Create the first one</a>.</span>
        </p>
      <?php else: ?>
        <p class="tiny muted" style="margin-top:20px">
          Forgotten it? <a href="/forgot" style="font-weight:600">Email yourself a reset link</a>,
          or run <code>php tools/make-admin.php your@email</code> over SSH.
        </p>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
