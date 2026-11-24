<?php
/**
 * Sourcing requests, the games board, and the restock alerts waiting to go
 * out — the three things people send us that need a human answer.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/shop.php';
require dirname(__DIR__) . '/inc/community.php';
require dirname(__DIR__) . '/inc/alerts.php';
require dirname(__DIR__) . '/inc/mail.php';
require dirname(__DIR__) . '/inc/uploads.php';
require_admin();

$me = current_user();
$error = '';
$done = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'quote') {
        $status = (string) ($_POST['status'] ?? '');
        $reply  = mb_substr(trim((string) ($_POST['reply'] ?? '')), 0, 1000);
        $quote  = trim((string) ($_POST['quote'] ?? '')) !== ''
            ? max(0, (int) $_POST['quote']) * 100 : null;
        $lead   = mb_substr(trim((string) ($_POST['lead_time'] ?? '')), 0, 60);

        if (!in_array($status, ['new', 'quoting', 'quoted', 'ordered', 'declined', 'closed'], true)) {
            $error = 'That is not a status.';
        } elseif (in_array($status, ['quoted', 'declined'], true) && $reply === '') {
            $error = 'Write what you are telling them. That is the whole answer.';
        } else {
            $req = q1('SELECT * FROM sourcing_requests WHERE id = ?', [$id]);
            exec_sql('UPDATE sourcing_requests SET status = ?, reply = ?,
                      quote_paise = ?, lead_time = ? WHERE id = ?',
                [$status, blank_null((string) $reply), $quote, blank_null((string) $lead), $id]);
            admin_log('request_' . $status, (string) $id);

            // Tell them, and everyone who backed it.
            if ($req && in_array($status, ['quoted', 'declined', 'ordered'], true) && $reply !== '') {
                $subject = match ($status) {
                    'quoted'   => 'About the ' . $req['model'],
                    'declined' => 'We cannot get the ' . $req['model'],
                    default    => $req['model'] . ' is on order',
                };

                $body = $reply . "\n\n"
                      . ($quote ? 'Price: ' . money($quote, false) . "\n" : '')
                      . ($lead !== '' ? 'Lead time: ' . $lead . "\n" : '')
                      . ($status === 'quoted' ? "\nA quote is not a commitment on either side until a deposit is paid.\n" : '')
                      . "\nGelsoft India\n" . rtrim((string) cfg('site_url'), '/') . "\n";

                $told = [$req['contact']];
                foreach (q('SELECT contact FROM request_backers WHERE request_id = ?', [$id]) as $b) {
                    $told[] = $b['contact'];
                }
                foreach (array_unique($told) as $to) {
                    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        send_mail((string) $to, $subject, $body, 'request_' . $status);
                    }
                }
            }
            redirect('/admin/community?done=request#requests');
        }
    }

    if ($action === 'game_cancel') {
        $problem = game_cancel($id);
        if ($problem === null) {
            redirect('/admin/community?done=game-cancelled#games');
        }
        $error = $problem;
    }

    if ($action === 'game_delete') {
        $problem = game_delete($id);
        if ($problem === null) {
            redirect('/admin/community?done=game-deleted#games');
        }
        $error = $problem;
    }

    if ($action === 'game_approve') {
        exec_sql("UPDATE games SET status = 'live', reject_note = NULL WHERE id = ? AND status = 'pending'", [$id]);
        admin_log('game_approve', (string) $id);
        $g = q1('SELECT contact, title FROM games WHERE id = ?', [$id]);
        if ($g && filter_var($g['contact'], FILTER_VALIDATE_EMAIL)) {
            send_mail((string) $g['contact'], 'Your game is on the board',
                "\"{$g['title']}\" is live on the board now.\n\n"
                . rtrim((string) cfg('site_url'), '/') . "/games\n\n"
                . "If anything changes — the date, the cap, the fee — tell us and we will update it.\n\nGelsoft India\n",
                'game_approved');
        }
        redirect('/admin/community?done=game#games');
    }

    if ($action === 'game_reject') {
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        if ($note === '') {
            $error = 'Say why, so the host can fix it.';
        } else {
            exec_sql("UPDATE games SET status = 'rejected', reject_note = ? WHERE id = ? AND status = 'pending'",
                [$note, $id]);
            admin_log('game_reject', (string) $id, $note);
            $g = q1('SELECT contact, title FROM games WHERE id = ?', [$id]);
            if ($g && filter_var($g['contact'], FILTER_VALIDATE_EMAIL)) {
                send_mail((string) $g['contact'], 'About your game listing',
                    "We looked at \"{$g['title']}\" and could not put it up yet.\n\n{$note}\n\n"
                    . "Send it again with that sorted and it goes straight back in the queue.\n\nGelsoft India\n",
                    'game_rejected');
            }
            redirect('/admin/community?done=game-sent-back#games');
        }
    }

    if ($action === 'send_alerts') {
        $n = alerts_send_for_product($id, (int) $me['id']);
        redirect('/admin/community?done=alerts-' . $n . '#alerts');
    }
}

$requests = db_ready()
    ? q("SELECT r.*, (SELECT COUNT(*) FROM request_backers b WHERE b.request_id = r.id) AS backers
         FROM sourcing_requests r
         WHERE r.status IN ('new','quoting','quoted')
         ORDER BY backers DESC, r.created_at")
    : [];

$live_games = games_live();
$pending_games = db_ready()
    ? q("SELECT * FROM games WHERE status = 'pending' ORDER BY starts_at")
    : [];

$alert_counts = alerts_pending_counts();
$alert_products = $alert_counts && db_ready()
    ? q('SELECT id, name, slug, category, stock_qty, status FROM products WHERE id IN ('
        . implode(',', array_fill(0, count($alert_counts), '?')) . ')', array_keys($alert_counts))
    : [];

$general_alerts = alerts_general();

$page = ['title' => 'Requests & games'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>Requests &amp; games</h1>
        <p><strong><?= count($requests) ?></strong> open request<?= count($requests) === 1 ? '' : 's' ?>,
           <strong><?= count($pending_games) ?></strong> game<?= count($pending_games) === 1 ? '' : 's' ?> waiting.</p>
      </div>
    </div>

    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($error) ?></span></p>
    <?php elseif (isset($_GET['done'])): ?>
      <p class="notice notice--olive" role="status">
        <span class="small">
          <?php $d = (string) $_GET['done'];
          if (str_starts_with($d, 'alerts-')) {
              $n = (int) substr($d, 7);
              echo $n === 0 ? 'Nobody was waiting on that one.' : e((string) $n) . ' alert' . ($n === 1 ? '' : 's') . ' sent.';
          } else {
              echo 'Done &mdash; ' . e(str_replace('-', ' ', $d)) . '.';
          } ?>
        </span>
      </p>
    <?php endif; ?>

    <!-- restock alerts -->
    <h2 class="h-card" style="margin:24px 0 14px" id="alerts">Restock alerts waiting</h2>

    <?php if (!$alert_products && !$general_alerts): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Nobody is waiting on anything.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">People waiting on stock</caption>
          <thead><tr><th scope="col">Item</th><th scope="col">Waiting</th><th scope="col">In stock</th><th scope="col"></th></tr></thead>
          <tbody>
            <?php foreach ($alert_products as $p): $n = (int) ($alert_counts[$p['id']] ?? 0); ?>
              <tr>
                <td><a href="<?= e(shop_path((string) $p['category']) . '/' . rawurlencode((string) $p['slug'])) ?>"><?= e($p['name']) ?></a></td>
                <td><strong><?= $n ?></strong></td>
                <td><?= (int) $p['stock_qty'] ?: '<span class="tag-warn">0</span>' ?></td>
                <td>
                  <?php if ((int) $p['stock_qty'] > 0 && $p['status'] === 'published'): ?>
                    <form method="post" action="/admin/community">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="send_alerts">
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <button class="btn btn--xs" type="submit"
                              data-confirm="Email <?= $n ?> <?= $n === 1 ? 'person' : 'people' ?> that this is back? They each get one message and come off the list.">
                        Tell them
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="tiny muted">Not back yet</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php foreach ($general_alerts as $g): ?>
              <tr>
                <td><span class="muted">Anything in <?= e(str_starts_with((string) $g['what'], 'game:') ? 'games near ' . substr((string) $g['what'], 5) : (string) $g['what']) ?></span></td>
                <td><strong><?= (int) $g['n'] ?></strong></td>
                <td>&mdash;</td>
                <td><span class="tiny muted">Tell them from a product</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tiny muted" style="margin-top:10px">One message each, then they come off the list. That promise is on every form they filled in, so the button only ever sends once.</p>
    <?php endif; ?>

    <!-- requests -->
    <h2 class="h-card" style="margin:30px 0 14px" id="requests">Sourcing requests</h2>

    <?php if (!$requests): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Nothing open.</p></div>
    <?php else: foreach ($requests as $r): ?>
      <div class="card card--pad-lg" style="margin-bottom:16px">
        <div class="row row--top" style="gap:20px;align-items:flex-start">
          <div style="flex:999 1 320px;min-width:0">
            <div class="row row--tight row--center">
              <h3 style="font-size:17px;font-weight:700"><?= e($r['model']) ?></h3>
              <?php if ($r['brand']): ?><span class="chip"><?= e($r['brand']) ?></span><?php endif; ?>
              <span class="chip chip--pill"><?= e(request_status_label((string) $r['status'])) ?></span>
              <?php if ((int) $r['backers'] > 0): ?>
                <span class="chip chip--ok"><?= (int) $r['backers'] + 1 ?> people want it</span>
              <?php endif; ?>
            </div>

            <p class="tiny muted" style="margin-top:8px">
              <?= e($r['name'] ?: 'No name') ?> &middot; <?= e($r['contact']) ?>
              &middot; <?= e(date('j M', strtotime((string) $r['created_at']))) ?>
              <?php if ($r['budget_max_paise']): ?>
                &middot; budget <?= money((int) $r['budget_min_paise']) ?>&ndash;<?= money((int) $r['budget_max_paise']) ?>
              <?php elseif ($r['budget_min_paise']): ?>
                &middot; budget <?= money((int) $r['budget_min_paise']) ?>+
              <?php endif; ?>
              <?php if ($r['wanted_by']): ?>&middot; <?= e($r['wanted_by']) ?><?php endif; ?>
            </p>

            <?php if ($r['details']): ?><p class="small" style="margin-top:10px"><?= e($r['details']) ?></p><?php endif; ?>
            <?php if ($r['notes']): ?><p class="small muted" style="margin-top:6px"><?= e($r['notes']) ?></p><?php endif; ?>
            <?php /* An admin session is the most expensive thing to click a bad link. */ ?>
            <?php if ($ref_link = safe_external_url($r['link'])): ?>
              <p class="tiny" style="margin-top:8px"><a href="<?= e($ref_link) ?>" rel="noopener noreferrer" target="_blank">Their reference link &rarr;</a></p>
            <?php endif; ?>
          </div>

          <form method="post" action="/admin/community" style="flex:0 0 280px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quote">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

            <div class="row row--tight">
              <div class="field" style="flex:1 1 120px;margin:0">
                <label class="field__label" for="q-<?= (int) $r['id'] ?>">Price &#8377;</label>
                <input class="input" id="q-<?= (int) $r['id'] ?>" name="quote" type="number" min="0"
                       value="<?= $r['quote_paise'] ? intdiv((int) $r['quote_paise'], 100) : '' ?>">
              </div>
              <div class="field" style="flex:1 1 120px;margin:0">
                <label class="field__label" for="l-<?= (int) $r['id'] ?>">Lead time</label>
                <input class="input" id="l-<?= (int) $r['id'] ?>" name="lead_time" maxlength="60"
                       value="<?= e($r['lead_time']) ?>" placeholder="5–6 weeks">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="r-<?= (int) $r['id'] ?>">What you are telling them</label>
              <textarea class="textarea" id="r-<?= (int) $r['id'] ?>" name="reply" rows="3"><?= e($r['reply']) ?></textarea>
            </div>

            <div class="field">
              <label class="sr-only" for="s-<?= (int) $r['id'] ?>">Status</label>
              <select class="select" id="s-<?= (int) $r['id'] ?>" name="status">
                <?php foreach (['new','quoting','quoted','ordered','declined','closed'] as $st): ?>
                  <option value="<?= e($st) ?>"<?= $r['status'] === $st ? ' selected' : '' ?>><?= e(request_status_label($st)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button class="btn btn--primary btn--sm btn--block" type="submit" style="margin-top:12px">Save and tell them</button>
            <p class="tiny muted" style="margin-top:8px">Quoted, declined and on-order email the asker and everyone backing it.</p>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <!-- games -->
    <h2 class="h-card" style="margin:30px 0 14px" id="games">Games waiting for review</h2>

    <?php if (!$pending_games): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Queue is clear.</p></div>
    <?php else: foreach ($pending_games as $g): ?>
      <div class="card card--pad-lg" style="margin-bottom:16px">
        <div class="row row--top" style="gap:20px;align-items:flex-start">
          <div style="flex:999 1 320px;min-width:0">
            <h3 style="font-size:17px;font-weight:700"><?= e($g['title']) ?></h3>
            <p class="tiny muted" style="margin-top:6px">
              <?= e($g['city']) ?><?= $g['venue'] ? ' &middot; ' . e($g['venue']) : '' ?>
              &middot; <?= e(date('j M Y, g:ia', strtotime((string) $g['starts_at']))) ?>
              &middot; <?= e($g['host_name']) ?> (<?= e($g['contact']) ?>)
            </p>

            <div class="chips" style="margin-top:12px">
              <span class="chip<?= $g['fps_cap'] ? '' : ' chip--nylon' ?>"><?= e(fps_cap_label($g['fps_cap'] !== null ? (int) $g['fps_cap'] : null)) ?></span>
              <?php if ($g['fee_paise'] !== null): ?><span class="chip"><?= money((int) $g['fee_paise']) ?></span><?php endif; ?>
              <?php if ($g['rental']): ?><span class="chip">Rentals</span><?php endif; ?>
              <?php if ($g['beginners']): ?><span class="chip">Beginners</span><?php endif; ?>
            </div>

            <?php if ($g['details']): ?><p class="small" style="margin-top:12px;color:var(--ink-3)"><?= nl2br(e((string) $g['details'])) ?></p><?php endif; ?>

            <div class="notice notice--paper" style="margin-top:14px">
              <span class="small">Check before approving: is there an FPS cap, and does the description say whether eye protection is provided and whether rentals exist? Those are the three things players write in to ask about.</span>
            </div>
          </div>

          <div style="flex:0 0 240px;display:flex;flex-direction:column;gap:10px">
            <form method="post" action="/admin/community">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="game_approve">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn--primary btn--sm btn--block" type="submit">Put it on the board</button>
            </form>
            <form method="post" action="/admin/community">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="game_reject">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <label class="sr-only" for="gn-<?= (int) $g['id'] ?>">Why it is going back</label>
              <input class="input" id="gn-<?= (int) $g['id'] ?>" name="note" maxlength="255" placeholder="Why it is going back&hellip;">
              <button class="btn btn--sm btn--block" type="submit" style="margin-top:8px">Send it back</button>
            </form>
            <form method="post" action="/admin/community">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="game_delete">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn--xs btn--block" type="submit" style="color:var(--bad)"
                      data-confirm="Delete this game for good? Sending it back tells the host why; deleting tells them nothing.">
                Delete &mdash; it is spam
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <!-- games already on the board -->
    <h2 class="h-card" style="margin:30px 0 14px" id="live-games">On the board now</h2>

    <?php if (!$live_games): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Nothing on the board yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Games currently listed</caption>
          <thead><tr>
            <th scope="col">Game</th><th scope="col">Where</th>
            <th scope="col">When</th><th scope="col">Host</th><th scope="col"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($live_games as $g): ?>
              <tr>
                <td><?= e($g['title']) ?></td>
                <td class="small"><?= e($g['city']) ?><?= $g['venue'] ? ' &middot; ' . e($g['venue']) : '' ?></td>
                <td class="tiny muted"><?= e(date('j M Y, H:i', strtotime((string) $g['starts_at']))) ?></td>
                <td class="small"><?= e($g['host_name']) ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <form method="post" action="/admin/community" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="game_cancel">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button class="btn btn--xs" type="submit"
                            data-confirm="Take this game off the board? The record is kept and the host can ask why.">Take it down</button>
                  </form>
                  <form method="post" action="/admin/community" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="game_delete">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button class="btn btn--xs" type="submit" style="color:var(--bad)"
                            data-confirm="Delete this game for good? Taking it down keeps the record; deleting does not.">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tiny muted" style="margin-top:10px">
        <strong>Take it down</strong> for a real game that fell through &mdash; the record stays and the
        host can ask why. <strong>Delete</strong> is for spam, where there is nothing worth keeping.
      </p>
    <?php endif; ?>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
