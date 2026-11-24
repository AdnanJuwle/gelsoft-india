<?php
/**
 * First-run: create the first admin account from the browser, so getting
 * started does not depend on having SSH.
 *
 * The guard is the whole design: this page only works while the users table
 * holds no admin at all. The moment one exists it turns into a dead end, so
 * it cannot be used to add a second one later. `tools/make-admin.php` is the
 * way to add or reset an admin after that.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';

$error = '';
$ready = db_ready();

$admins = $ready
    ? (int) (q1("SELECT COUNT(*) n FROM users WHERE role IN ('admin','owner')")['n'] ?? 0)
    : 0;

if ($ready && $admins > 0) {
    // Already set up. Say so plainly and send them to the sign-in.
    $page = ['title' => 'Setup', 'public' => true];
    require dirname(__DIR__) . '/inc/admin_header.php';
    ?>
    <div class="wrap admin-shell">
      <main class="admin-main" style="max-width:460px;margin:40px auto">
        <div class="card card--pad-lg">
          <h1 style="font-size:22px;font-weight:700;margin-bottom:10px">Already set up</h1>
          <p class="small muted">There is an admin account on this site, so this page is closed. That is deliberate — otherwise anyone who found the URL could make themselves one.</p>
          <p class="small muted" style="margin-top:12px">To add another admin, or reset a password, run <code>php tools/make-admin.php</code> over SSH.</p>
          <a class="btn btn--primary btn--sm" href="/admin/login" style="margin-top:18px">Go to sign-in</a>
        </div>
      </main>
    </div>
    <?php
    require dirname(__DIR__) . '/inc/admin_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    csrf_require();

    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $again    = (string) ($_POST['password2'] ?? '');

    if ($name === '' || mb_strlen($name) > 120) {
        $error = 'Put a name in — it is what the admin bar will greet you with.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'That does not look like an email address.';
    } elseif ($password !== $again) {
        $error = 'The two passwords do not match.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } else {
        try {
            // Re-check inside the write: two people could open this page at
            // the same moment, and only one of them should get an admin.
            $still = (int) (q1("SELECT COUNT(*) n FROM users WHERE role IN ('admin','owner')")['n'] ?? 0);
            if ($still > 0) {
                redirect('/admin/setup');
            }

            $existing = q1('SELECT id FROM users WHERE email = ?', [$email]);
            $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);

            if ($existing) {
                // The first account is the OWNER, not an admin: somebody has to
                // be able to hand out access, and at this point nobody else can.
                exec_sql("UPDATE users SET name = ?, password_hash = ?, role = 'owner',
                          failed_logins = 0, locked_until = NULL WHERE id = ?",
                    [$name, $hash, $existing['id']]);
            } else {
                exec_sql("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'owner')",
                    [$name, $email, $hash]);
            }

            admin_log('owner_created', $email, 'first account, created from /admin/setup');
            redirect('/admin/login?created=1');
        } catch (Throwable $e) {
            error_log('admin setup failed: ' . $e->getMessage());
            $error = 'Could not create the account. Check the database and try again.';
        }
    }
}

$page = ['title' => 'Set up the first admin', 'public' => true];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="max-width:480px;margin:40px auto">
    <div class="card card--pad-lg">
      <h1 style="font-size:24px;font-weight:700;margin-bottom:8px">Set up the first admin</h1>
      <p class="small muted" style="margin-bottom:22px">There is no admin account on this site yet. Make one here, and this page closes itself behind you.</p>

      <?php if (!$ready): ?>
        <p class="notice notice--paper" role="alert">
          <span class="small"><strong>No database.</strong> Fill in the <code>db</code> block in <code>inc/config.php</code> first, then reload this page.</span>
        </p>
      <?php else: ?>

        <?php if ($error): ?>
          <p class="notice notice--paper" role="alert" style="margin-bottom:20px;color:var(--bad)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
            <span class="small"><?= e($error) ?></span>
          </p>
        <?php endif; ?>

        <form method="post" action="/admin/setup">
          <?= csrf_field() ?>

          <div class="field">
            <label class="field__label" for="name">Your name</label>
            <input class="input" id="name" name="name" maxlength="120" required autocomplete="name"
                   value="<?= e($_POST['name'] ?? '') ?>" autofocus>
          </div>

          <div class="field">
            <label class="field__label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" maxlength="190" required
                   autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>">
            <p class="field__hint">This is the username you sign in with.</p>
          </div>

          <div class="field">
            <label class="field__label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" required minlength="10"
                   autocomplete="new-password">
            <p class="field__hint">At least 10 characters. Three unrelated words beat <em>P@ssw0rd</em> &mdash; length is what makes a password hard to guess.</p>
          </div>

          <div class="field">
            <label class="field__label" for="password2">Password again</label>
            <input class="input" id="password2" name="password2" type="password" required minlength="10"
                   autocomplete="new-password">
          </div>

          <button class="btn btn--primary btn--block" type="submit" style="margin-top:22px">Create the admin account</button>
        </form>

        <div class="notice notice--paper" style="margin-top:22px">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3l8 3.5v5c0 4.6-3.3 8.4-8 9.5-4.7-1.1-8-4.9-8-9.5v-5z" stroke-linejoin="round"/></svg>
          <span class="small">Do this as soon as the site is up. Until an admin exists this page is open to whoever finds it, which is why the hPanel directory password on <code>/admin/</code> matters most right now.</span>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
