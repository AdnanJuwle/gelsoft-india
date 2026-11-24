<?php
/**
 * Moderating the marketplace: listings waiting for review, and sales in
 * flight.
 *
 * Reviewing is the thing that makes the four rules on the sell form real.
 * Approving is one click; sending one back asks for a reason, because a
 * listing that vanishes with no explanation is how you lose a seller.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/shop.php';
require dirname(__DIR__) . '/inc/listings.php';
require dirname(__DIR__) . '/inc/listing_sales.php';
require dirname(__DIR__) . '/inc/payments.php';
require dirname(__DIR__) . '/inc/uploads.php';
require dirname(__DIR__) . '/inc/seller_checks.php';
require dirname(__DIR__) . '/inc/mail.php';
require_admin();

$me = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve') {
        // Nothing goes live behind an unverified seller. The badge is what a
        // buyer is trusting when they hand over money we then hold, so it has
        // to be true before the listing exists, not after the first complaint.
        $seller = q1('SELECT seller_id FROM listings WHERE id = ?', [$id]);
        $missing = $seller ? seller_missing((int) $seller['seller_id']) : SELLER_REQUIRED;

        if ($missing) {
            $error = 'That seller is not verified yet — still to do: '
                   . implode(', ', array_map(fn($k) => SELLER_CHECKS[$k]['label'], $missing))
                   . '. Do those first, below.';
        } else {
            exec_sql("UPDATE listings SET status = 'live', reviewed_at = NOW(), reject_note = NULL
                      WHERE id = ? AND status = 'pending'", [$id]);
            admin_log('listing_approve', (string) $id);
            redirect('/admin/marketplace?done=approved');
        }
    }

    if ($action === 'listing_delete') {
        $problem = listing_delete($id);
        if ($problem === null) {
            redirect('/admin/marketplace?done=deleted');
        }
        $error = $problem;
    }

    if ($action === 'listing_withdraw') {
        $problem = listing_withdraw($id);
        if ($problem === null) {
            redirect('/admin/marketplace?done=withdrawn');
        }
        $error = $problem;
    }

    if ($action === 'reject') {
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        if ($note === '') {
            $error = 'Say why, so they can fix it. A listing that disappears without a reason loses you a seller.';
        } else {
            exec_sql("UPDATE listings SET status = 'rejected', reviewed_at = NOW(), reject_note = ?
                      WHERE id = ? AND status = 'pending'", [$note, $id]);
            admin_log('listing_reject', (string) $id, $note);

            $seller = q1('SELECT u.email, l.title FROM listings l JOIN users u ON u.id = l.seller_id WHERE l.id = ?', [$id]);
            if ($seller) {
                send_mail((string) $seller['email'], 'Your listing needs a change',
                    "We looked at \"{$seller['title']}\" and sent it back.\n\n{$note}\n\n"
                    . "Fix that and save it again — it goes straight back in the queue.\n\n"
                    . rtrim((string) cfg('site_url'), '/') . "/account\n\nGelsoft India\n",
                    'listing_rejected');
            }
            redirect('/admin/marketplace?done=sent-back');
        }
    }

    if ($action === 'seller_check') {
        $problem = seller_check_set($id, (string) ($_POST['kind'] ?? ''), (int) $me['id'],
            (string) ($_POST['note'] ?? ''));
        if ($problem === null) {
            redirect('/admin/marketplace?done=checked#sellers');
        }
        $error = $problem;
    }

    if ($action === 'seller_uncheck') {
        $problem = seller_check_clear($id, (string) ($_POST['kind'] ?? ''));
        if ($problem === null) {
            /*
             * Undoing a check takes the badge away. It does NOT take their
             * listings down, and it should not: an admin un-ticks a check to
             * correct a mistake as often as to act on one, and withdrawing a
             * seller's whole catalogue is a large thing to hang on a small
             * button.
             *
             * But somebody can still buy from those listings in the meantime,
             * so say how many there are. The decision is the admin's; the
             * number is what lets them make it.
             */
            $still_live = (int) (q1(
                "SELECT COUNT(*) n FROM listings WHERE seller_id = ? AND status = 'live'",
                [$id]
            )['n'] ?? 0);

            redirect('/admin/marketplace?done=unchecked'
                . ($still_live > 0 ? '&live=' . $still_live : '') . '#sellers');
        }
        $error = $problem;
    }

    if ($action === 'release') {
        $error = sale_release((int) ($_POST['sale_id'] ?? 0), $me, !empty($_POST['force'])) ?? '';
        if ($error === '') {
            redirect('/admin/marketplace?done=released');
        }
    }
}

$pending = db_ready()
    ? q("SELECT l.*, u.name AS seller_name, u.email AS seller_email, u.seller_verified_at,
                (SELECT COUNT(*) FROM listing_images i WHERE i.listing_id = l.id) AS photos
         FROM listings l JOIN users u ON u.id = l.seller_id
         WHERE l.status = 'pending' ORDER BY l.created_at")
    : [];

$sellers = sellers_awaiting_checks();

$live = db_ready()
    ? q("SELECT l.*, u.name AS seller_name, u.seller_verified_at,
                (SELECT COUNT(*) FROM listing_orders o WHERE o.listing_id = l.id) AS sales
         FROM listings l JOIN users u ON u.id = l.seller_id
         WHERE l.status IN ('live','sold') ORDER BY l.status, l.created_at DESC")
    : [];

$sales = db_ready()
    ? q("SELECT lo.*, l.title, b.name AS buyer_name, s.name AS seller_name
         FROM listing_orders lo
         JOIN listings l ON l.id = lo.listing_id
         JOIN users b ON b.id = lo.buyer_id
         JOIN users s ON s.id = lo.seller_id
         WHERE lo.status NOT IN ('pending','released','refunded','cancelled')
         ORDER BY FIELD(lo.status,'disputed','inspecting','to_us','delivered','shipped','held'), lo.created_at")
    : [];

$awaiting = db_ready()
    ? q("SELECT lo.*, l.title, s.name AS seller_name
         FROM listing_orders lo
         JOIN listings l ON l.id = lo.listing_id
         JOIN users s ON s.id = lo.seller_id
         WHERE lo.status = 'delivered' ORDER BY lo.confirm_by")
    : [];

$page = ['title' => 'Marketplace'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1>Marketplace</h1>
        <p><strong><?= count($pending) ?></strong> listing<?= count($pending) === 1 ? '' : 's' ?> waiting, <strong><?= count($sales) ?></strong> sale<?= count($sales) === 1 ? '' : 's' ?> in flight.</p>
      </div>
      <a class="btn btn--sm" href="/marketplace">View the marketplace</a>
    </div>

    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($error) ?></span></p>
    <?php elseif (isset($_GET['done'])): ?>
      <?php
      /*
       * A map, not an echo. This used to print whatever was in ?done=, so the
       * sentence on the page was written by the query string -- an admin-only
       * nuisance rather than a hole, but the same shape as the order screen's
       * ?saved= and wrong for the same reason.
       */
      $done_says = [
          'approved'  => 'Approved. It is on the marketplace now.',
          'deleted'   => 'Deleted.',
          'withdrawn' => 'Withdrawn. It is off the marketplace.',
          'sent-back' => 'Sent back to the seller with your note.',
          'released'  => 'Released. The payout is on its way.',
          'checked'   => 'Checked.',
          'unchecked' => 'Check undone. The badge is gone.',
      ][(string) $_GET['done']] ?? 'Done.';

      /*
       * Undoing a check does not take that seller's live listings down, and
       * it should not -- an admin un-ticks a check to fix a mistake as often
       * as to act on one. But somebody can still buy from them meanwhile, so
       * the number is said out loud and the decision is left where it
       * belongs.
       */
      $still_live = max(0, (int) ($_GET['live'] ?? 0));
      if (($_GET['done'] ?? '') === 'unchecked' && $still_live > 0) {
          $done_says .= ' ' . $still_live . ($still_live === 1
              ? ' listing of theirs is still live and can still be bought.'
              : ' listings of theirs are still live and can still be bought.');
      }
      ?>
      <p class="notice <?= $still_live > 0 ? 'notice--ember' : 'notice--olive' ?>" role="status"><span class="small"><?= e($done_says) ?></span></p>
    <?php endif; ?>

    <!-- waiting for review -->
    <h2 class="h-card" style="margin:24px 0 14px">Waiting for review</h2>

    <?php if (!$pending): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Queue is clear.</p></div>
    <?php else: foreach ($pending as $l): ?>
      <div class="card card--pad-lg" style="margin-bottom:16px">
        <div class="row row--top" style="gap:20px;align-items:flex-start">
          <div style="flex:999 1 320px;min-width:0">
            <div class="row row--tight row--center">
              <a style="font-weight:700;font-size:17px" href="<?= e(listing_url($l)) ?>"><?= e($l['title']) ?></a>
              <?php if ($l['grade']): ?><span class="grade--badge"><?= grade_mark($l['grade']) ?></span><?php endif; ?>
            </div>

            <p class="tiny muted" style="margin-top:6px">
              <?= e($l['seller_name']) ?> (<?= e($l['seller_email']) ?>)
              <?php if (!$l['seller_verified_at']): ?>
                &middot; <span class="tag-warn">not verified</span>
              <?php endif; ?>
              &middot; <?= (int) $l['photos'] ?> photo<?= (int) $l['photos'] === 1 ? '' : 's' ?>
              &middot; <?= money((int) $l['price_paise']) ?>
              &middot; <?= e(condition_label($l['item_condition'])) ?>
            </p>

            <p class="small" style="margin-top:12px;color:var(--ink-3);line-height:1.65"><?= nl2br(e(mb_substr((string) $l['description'], 0, 600))) ?></p>

            <?php if ($l['faults']): ?>
              <p class="tiny" style="margin-top:8px;color:var(--warn)"><strong>Faults declared:</strong> <?= e($l['faults']) ?></p>
            <?php endif; ?>

            <div class="notice notice--paper" style="margin-top:14px">
              <span class="small">Check before approving: photos are theirs and show the actual item, no used eye protection, nothing repainted to look like a real firearm, and the declared faults match what the photos show.</span>
            </div>
          </div>

          <div style="flex:0 0 230px;display:flex;flex-direction:column;gap:10px">
            <form method="post" action="/admin/marketplace">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <button class="btn btn--primary btn--sm btn--block" type="submit">Approve &mdash; put it live</button>
            </form>

            <form method="post" action="/admin/marketplace">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <label class="sr-only" for="note-<?= (int) $l['id'] ?>">Why it is going back</label>
              <input class="input" id="note-<?= (int) $l['id'] ?>" name="note" maxlength="255"
                     placeholder="Why it is going back&hellip;">
              <button class="btn btn--sm btn--block" type="submit" style="margin-top:8px">Send it back</button>
            </form>

            <?php if (!$l['seller_verified_at']): ?>
              <p class="tiny" style="color:var(--bad);margin-top:4px">
                This seller is not verified, so <strong>Approve will refuse</strong>.
                <a href="#sellers">Do the checks first</a>.
              </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <!-- money waiting to be released -->
    <?php if ($awaiting): ?>
      <h2 class="h-card" style="margin:30px 0 14px">Delivered &mdash; payout due</h2>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Sales awaiting payout</caption>
          <thead><tr><th scope="col">Sale</th><th scope="col">Item</th><th scope="col">Seller</th>
                     <th scope="col">Payout</th><th scope="col">Window closes</th><th scope="col"></th></tr></thead>
          <tbody>
            <?php foreach ($awaiting as $s): $open = $s['confirm_by'] && strtotime((string) $s['confirm_by']) > time(); ?>
              <tr>
                <td><a href="/sale?ref=<?= e(urlencode((string) $s['reference'])) ?>"><?= e($s['reference']) ?></a></td>
                <td><?= e($s['title']) ?></td>
                <td><?= e($s['seller_name']) ?></td>
                <td><?= money((int) $s['payout_paise']) ?></td>
                <td class="tiny <?= $open ? 'muted' : 'tag-ok' ?>">
                  <?= $s['confirm_by'] ? e(date('j M, H:i', strtotime((string) $s['confirm_by']))) : '—' ?>
                  <?= $open ? '' : ' (closed)' ?>
                </td>
                <td>
                  <form method="post" action="/admin/marketplace">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="sale_id" value="<?= (int) $s['id'] ?>">
                    <?php if ($open): ?><input type="hidden" name="force" value="1"><?php endif; ?>
                    <button class="btn btn--xs" type="submit"<?= $open ? ' data-confirm="The buyer still has time to raise a problem. Release it anyway?"' : '' ?>>
                      <?= $open ? 'Release early' : 'Release' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tiny muted" style="margin-top:10px">Releasing records it here. The transfer itself you still make by hand &mdash; a handful a week is not worth the compliance work of automating.</p>
    <?php endif; ?>

    <!-- in flight -->
    <h2 class="h-card" style="margin:30px 0 14px">Sales in flight</h2>
    <div class="table-wrap">
      <table class="table">
        <caption class="sr-only">Marketplace sales</caption>
        <thead><tr><th scope="col">Sale</th><th scope="col">Item</th><th scope="col">Buyer</th>
                   <th scope="col">Seller</th><th scope="col">Total</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
        <tbody>
          <?php if (!$sales): ?>
            <tr><td colspan="7" style="padding:40px 24px;text-align:center">
              <p class="small muted" style="margin:0">Nothing in flight.</p></td></tr>
          <?php else: foreach ($sales as $s): ?>
            <tr>
              <td><a href="/sale?ref=<?= e(urlencode((string) $s['reference'])) ?>"><?= e($s['reference']) ?></a></td>
              <td><?= e($s['title']) ?><?= $s['inspected'] ? ' <span class="chip chip--ok">inspect</span>' : '' ?>
                <?php if ($s['admin_note']): ?>
                  <?php /* Two sales on one item land here. Said in the row,
                          not left in a column nobody opens. */ ?>
                  <br><span class="tiny" style="color:var(--bad)"><?= e($s['admin_note']) ?></span>
                <?php endif; ?></td>
              <td><?= e($s['buyer_name']) ?></td>
              <td><?= e($s['seller_name']) ?></td>
              <td><?= money((int) $s['total_paise']) ?></td>
              <td><span class="<?= $s['status'] === 'disputed' ? 'tag-warn' : 'tag-ok' ?>"><?= e(sale_status_label((string) $s['status'])) ?></span></td>
              <td><a class="btn btn--xs" href="/sale?ref=<?= e(urlencode((string) $s['reference'])) ?>">Open</a></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- seller verification -->
    <h2 class="h-card" style="margin:30px 0 6px" id="sellers">Sellers</h2>
    <p class="small muted" style="margin:0 0 14px;max-width:62em">
      A seller is verified by <strong>checks we carry out</strong>, never by documents they send
      us. We do not ask for ID and we do not store any &mdash; an unlicensed business may not keep
      Aadhaar copies at all, and holding any ID would make us the custodian of the most sensitive
      thing a person owns for no real gain. We already hold the buyer&rsquo;s money until delivery,
      which is the protection that actually matters.
    </p>

    <?php if (!$sellers): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Nobody has listed anything yet.</p></div>
    <?php else: foreach ($sellers as $sel):
      $have = seller_checks((int) $sel['id']); ?>
      <div class="card card--pad" style="margin-bottom:12px">
        <div class="row row--tight" style="justify-content:space-between;align-items:flex-start">
          <div>
            <strong><?= e($sel['name']) ?></strong>
            <?= $sel['seller_verified_at']
                  ? '<span class="tag-ok">verified</span>'
                  : '<span class="tag-warn">not verified</span>' ?>
            <p class="tiny muted" style="margin-top:4px">
              <?= e($sel['email']) ?>
              &middot; phone <?= $sel['phone'] ? e($sel['phone']) : '<em>none on file</em>' ?>
              &middot; UPI <?= $sel['upi_id'] ? e($sel['upi_id']) : '<em>none on file</em>' ?>
              <?= $sel['account_name'] ? ' as <strong>' . e($sel['account_name']) . '</strong>' : '' ?>
            </p>
          </div>
        </div>

        <div class="admin-cols" style="margin-top:14px">
          <?php foreach (SELLER_CHECKS as $kind => $meta):
            $done = isset($have[$kind]); ?>
            <div>
              <p class="small" style="font-weight:600">
                <?= $done ? '&#10003; ' : '' ?><?= e($meta['label']) ?>
                <?= in_array($kind, SELLER_REQUIRED, true) ? '' : '<span class="tiny muted">(optional)</span>' ?>
              </p>
              <p class="tiny muted" style="margin:4px 0 8px"><?= e($meta['how']) ?></p>

              <?php if ($done): ?>
                <p class="tiny" style="color:var(--olive-dark)">
                  <?= e(date('j M Y', strtotime((string) $have[$kind]['created_at']))) ?>
                  <?= $have[$kind]['by_name'] ? ' by ' . e($have[$kind]['by_name']) : '' ?>
                  <?= $have[$kind]['note'] ? ' &mdash; ' . e($have[$kind]['note']) : '' ?>
                </p>
                <form method="post" action="/admin/marketplace">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="seller_uncheck">
                  <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
                  <input type="hidden" name="kind" value="<?= e($kind) ?>">
                  <button class="btn btn--xs" type="submit" style="color:var(--bad)">Undo</button>
                </form>
              <?php else: ?>
                <form method="post" action="/admin/marketplace">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="seller_check">
                  <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
                  <input type="hidden" name="kind" value="<?= e($kind) ?>">
                  <label class="sr-only" for="n-<?= (int) $sel['id'] ?>-<?= e($kind) ?>">What you found</label>
                  <input class="input" id="n-<?= (int) $sel['id'] ?>-<?= e($kind) ?>" name="note"
                         maxlength="160" placeholder="What you found&hellip;">
                  <button class="btn btn--xs btn--block" type="submit" style="margin-top:6px">Confirm</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <!-- everything already on the marketplace -->
    <h2 class="h-card" style="margin:30px 0 14px" id="live">On the marketplace now</h2>

    <?php if (!$live): ?>
      <div class="card card--pad"><p class="small muted" style="margin:0">Nothing is live yet.</p></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Live listings</caption>
          <thead><tr>
            <th scope="col">Listing</th><th scope="col">Seller</th>
            <th scope="col">Price</th><th scope="col">Status</th><th scope="col"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($live as $l): ?>
              <tr>
                <td><a href="<?= e(listing_url($l)) ?>"><?= e($l['title']) ?></a></td>
                <td class="small"><?= e($l['seller_name']) ?><?= $l['seller_verified_at'] ? '' : ' <span class="tag-warn">unverified</span>' ?></td>
                <td><?= money((int) $l['price_paise']) ?></td>
                <td><span class="<?= $l['status'] === 'live' ? 'tag-ok' : 'tag-warn' ?>"><?= e(ucfirst((string) $l['status'])) ?></span></td>
                <td style="text-align:right;white-space:nowrap">
                  <?php if ($l['status'] === 'live'): ?>
                    <form method="post" action="/admin/marketplace" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="listing_withdraw">
                      <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                      <button class="btn btn--xs" type="submit"
                              data-confirm="Take this listing off the marketplace? Everything is kept.">Withdraw</button>
                    </form>
                  <?php endif; ?>
                  <?php if ((int) $l['sales'] === 0): ?>
                    <form method="post" action="/admin/marketplace" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="listing_delete">
                      <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                      <button class="btn btn--xs" type="submit" style="color:var(--bad)"
                              data-confirm="Delete this listing and its photos for good? Withdrawing keeps everything; this does not.">Delete</button>
                    </form>
                  <?php else: ?>
                    <span class="tiny muted">sold &mdash; cannot delete</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="tiny muted" style="margin-top:10px">
        <strong>Withdraw</strong> takes a listing off the board and keeps everything &mdash; use it for
        anything real. <strong>Delete</strong> is for spam, and is refused once somebody has bought the
        item, because the sale record has to outlive the advert.
      </p>
    <?php endif; ?>

  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
