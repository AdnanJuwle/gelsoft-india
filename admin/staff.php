<?php
/**
 * Who can reach the admin panel.
 *
 * Promoting and removing both ask for your own password again — see
 * inc/staff.php for why a live session on its own is not enough to hand out
 * this particular privilege.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/staff.php';
require_admin();

$me    = current_user();
$owner = user_is_owner($me);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // An admin may read this page. Only the owner may change it -- that
    // separation is the point of the two roles, so it is enforced here and
    // not only by hiding the forms.
    if (!$owner) {
        http_response_code(403);
        exit('Only the owner can change who has access.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if (!staff_confirm_password((int) $me['id'], (string) ($_POST['confirm'] ?? ''))) {
        $error = 'That is not your password. Nothing was changed.';
        // Worth seeing in the log: somebody holding a live admin session who
        // cannot produce the password, whether that is a typo or not.
        admin_log('staff_confirm_failed', $action);
        usleep(random_int(200_000, 500_000));
    } elseif ($action === 'promote') {
        $problem = staff_promote((string) ($_POST['email'] ?? ''),
            ($_POST['role'] ?? '') === 'owner' ? 'owner' : 'admin');
        if ($problem === null) {
            redirect('/admin/staff?done=promoted');
        }
        $error = $problem;
    } elseif ($action === 'demote') {
        $problem = staff_demote((int) ($_POST['id'] ?? 0), (int) $me['id']);
        if ($problem === null) {
            redirect('/admin/staff?done=removed');
        }
        $error = $problem;
    }
}

$staff = staff_list();

// Everyone except me. Demoting yourself is refused by the model anyway, but
// there is no reason to offer it.
$others = array_values(array_filter($staff, fn($s) => (int) $s['id'] !== (int) $me['id']));

$page = ['title' => 'Admins'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>Admins</h1>
        <p><strong><?= count($staff) ?></strong> account<?= count($staff) === 1 ? '' : 's' ?> can reach this panel.
           <?= $owner ? 'You are the owner, so you can change who.' : 'Only an owner can change who.' ?></p>
      </div>
      <a class="btn btn--sm" href="/admin/">Back to the dashboard</a>
    </div>

    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($error) ?></span></p>
    <?php elseif (isset($_GET['done'])): ?>
      <p class="notice notice--olive" role="status">
        <span class="small"><?= $_GET['done'] === 'promoted'
          ? 'Done. They sign in at /admin/login with the password they already use for the shop.'
          : 'Done. They are an ordinary customer again &mdash; their account, orders and listings are untouched.' ?></span>
      </p>
    <?php endif; ?>

    <div class="table-wrap" style="margin-top:22px">
      <table class="table">
        <caption class="sr-only">Admin accounts</caption>
        <thead><tr>
          <th scope="col">Name</th>
          <th scope="col">Access</th>
          <th scope="col">Email</th>
          <th scope="col">Account made</th>
          <th scope="col">Last signed in</th>
        </tr></thead>
        <tbody>
          <?php if (!$staff): ?>
            <tr><td colspan="5" style="padding:44px 24px;text-align:center">
              <p class="small muted" style="margin:0">The database is not reachable.</p>
            </td></tr>
          <?php else: foreach ($staff as $s): ?>
            <tr>
              <td>
                <?= e($s['name']) ?>
                <?php if ((int) $s['id'] === (int) $me['id']): ?>
                  <span class="tiny muted" style="display:block">that&rsquo;s you</span>
                <?php endif; ?>
              </td>
              <td><?= $s['role'] === 'owner'
                    ? '<span class="tag-ok">Owner</span>'
                    : '<span class="tiny muted">Admin</span>' ?></td>
              <td class="small"><?= e($s['email']) ?></td>
              <td class="tiny muted"><?= e(date('j M Y', strtotime((string) $s['created_at']))) ?></td>
              <td class="tiny muted"><?= $s['last_login_at']
                    ? e(date('j M Y, H:i', strtotime((string) $s['last_login_at'])))
                    : 'never' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$owner): ?>

      <div class="card card--pad-lg" style="margin-top:28px;max-width:600px">
        <h2 style="font-size:20px;font-weight:700">Who can change this</h2>
        <p class="small muted" style="margin-top:8px">
          Only an owner. You can see the list, which is worth being able to check, but adding
          and removing access is held one level up on purpose: if an admin account is ever taken
          over, whoever has it can do damage to the shop but cannot lock the owner out, cannot
          remove the other admins, and cannot quietly add one of their own.
        </p>
      </div>

    <?php else: ?>

    <div class="admin-cols" style="margin-top:28px">

      <section class="card card--pad-lg">
        <h2 style="font-size:20px;font-weight:700">Give someone access</h2>
        <p class="small muted" style="margin-top:8px">
          They register at <a href="/register">/register</a> like any customer and choose their
          own password. You give that account access here. Nobody else ever types an
          admin&rsquo;s password, and it never goes over a message.
        </p>

        <form method="post" action="/admin/staff" style="margin-top:20px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="promote">

          <div class="field" style="margin:0">
            <label class="field__label" for="email">Their email</label>
            <input class="input" id="email" name="email" type="email" maxlength="190" required
                   autocomplete="off" placeholder="them@gelsoft.in">
            <p class="field__hint">The address on the account they already made.</p>
          </div>

          <div class="field">
            <label class="field__label" for="role">As</label>
            <select class="input" id="role" name="role">
              <option value="admin">Admin &mdash; runs the shop, cannot change this list</option>
              <option value="owner">Owner &mdash; can also add and remove access</option>
            </select>
            <p class="field__hint">Start with admin. An owner can remove <em>you</em>, so make one
              only when you genuinely want a second person who can.</p>
          </div>

          <div class="field">
            <label class="field__label" for="confirm">Your password</label>
            <input class="input" id="confirm" name="confirm" type="password" required
                   autocomplete="current-password">
            <p class="field__hint">Asked again because an admin reads every conversation and
              releases held money. A borrowed screen should not be enough to hand that out.</p>
          </div>

          <button class="btn btn--primary" type="submit" style="margin-top:18px">Give them access</button>
        </form>
      </section>

      <section class="card card--pad-lg">
        <h2 style="font-size:20px;font-weight:700">Remove access</h2>

        <?php if (!$others): ?>
          <p class="small muted" style="margin-top:8px">
            Nobody else has access, so there is nobody to remove. You cannot remove your own
            &mdash; that would leave the panel unreachable from the browser, and the way back
            would be <code>tools/make-admin.php</code> over SSH.
          </p>
        <?php else: ?>
          <p class="small muted" style="margin-top:8px">
            Takes away panel access and nothing else. They keep their account, their orders,
            their listings and their conversations, as an ordinary customer.
          </p>

          <form method="post" action="/admin/staff" style="margin-top:20px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="demote">

            <div class="field" style="margin:0">
              <label class="field__label" for="id">Who</label>
              <select class="input" id="id" name="id" required>
                <?php foreach ($others as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?> &mdash; <?= e($s['email']) ?><?= $s['role'] === 'owner' ? ' (owner)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="field__label" for="confirm2">Your password</label>
              <input class="input" id="confirm2" name="confirm" type="password" required
                     autocomplete="current-password">
            </div>

            <button class="btn" type="submit" style="margin-top:18px;color:var(--bad)"
                    data-confirm="Remove this account's access to the admin panel?">
              Remove access
            </button>
          </form>
        <?php endif; ?>
      </section>

    </div>

    <?php endif; ?>

    <p class="small muted" style="margin-top:24px;max-width:600px">
      Every promotion and removal goes into <code>admin_log</code> with who did it, when, and
      from which address &mdash; as does a failed password confirmation.
    </p>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
