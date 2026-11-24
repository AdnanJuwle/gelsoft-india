<?php
/**
 * One marketplace sale, from either side.
 *
 * The buyer and the seller see the same page and different buttons, because
 * what each of them can do is decided in inc/listing_sales.php and not here.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';
require __DIR__ . '/inc/listing_sales.php';
require __DIR__ . '/inc/payments.php';
require __DIR__ . '/inc/mail.php';

$me = require_login('/account');

$reference = strtoupper(trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
$sale = $reference !== '' ? sale_by_reference($reference) : null;

if (!$sale || !sale_viewable($sale, $me)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $to = (string) ($_POST['to'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($to === 'tracking') {
        if ((int) $sale['seller_id'] !== (int) $me['id'] && !user_is_staff($me)) {
            $error = 'That is not yours to do.';
        } else {
            exec_sql('UPDATE listing_orders SET courier = ?, tracking = ? WHERE id = ?', [
                blank_null(mb_substr(trim((string) ($_POST['courier'] ?? '')), 0, 60)),
                blank_null(mb_substr(trim((string) ($_POST['tracking'] ?? '')), 0, 120)),
                (int) $sale['id'],
            ]);
            $notice = 'Saved.';
        }
    } else {
        $error = sale_move((int) $sale['id'], $to, $me, $note) ?? '';
        if ($error === '') {
            redirect('/sale?ref=' . urlencode($reference) . '&done=1');
        }
    }

    $sale = sale_by_reference($reference);
}

$listing = q1('SELECT l.*, (SELECT i.path FROM listing_images i WHERE i.listing_id = l.id
               ORDER BY i.sort_order, i.id LIMIT 1) AS image
               FROM listings l WHERE l.id = ?', [(int) $sale['listing_id']]);

$other = q1('SELECT name FROM users WHERE id = ?',
    [(int) ((int) $sale['buyer_id'] === (int) $me['id'] ? $sale['seller_id'] : $sale['buyer_id'])]);

$is_buyer  = (int) $sale['buyer_id'] === (int) $me['id'];
$is_seller = (int) $sale['seller_id'] === (int) $me['id'];
$events    = sale_events((int) $sale['id']);
$status    = (string) $sale['status'];

/** The buttons this person may press right now, straight from the table. */
$moves = [];
foreach (SALE_MOVES[$status] ?? [] as $to => $who) {
    $may = match ($who) {
        'admin'  => user_is_staff($me),
        'buyer'  => $is_buyer || user_is_staff($me),
        'seller' => $is_seller || user_is_staff($me),
        default  => false,
    };
    if ($may) {
        $moves[$to] = $who;
    }
}

$page = [
    'title'   => 'Sale ' . $sale['reference'],
    'nav'     => 'marketplace',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px;max-width:62em">

    <?php if (isset($_GET['unpaid'])): ?>
      <p class="notice notice--paper" style="margin-bottom:20px">
        <span class="small"><strong>Not paid yet.</strong> Payments are not switched on here yet, so nothing has been charged and the seller has not been told to post anything.</span>
      </p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="notice notice--paper" role="alert" style="margin-bottom:20px;color:var(--bad)"><span class="small"><?= e($error) ?></span></p>
    <?php elseif ($notice || isset($_GET['done'])): ?>
      <p class="notice notice--olive" role="status" style="margin-bottom:20px"><span class="small"><?= e($notice ?: 'Saved.') ?></span></p>
    <?php endif; ?>

    <div class="row row--between row--center" style="margin-bottom:22px">
      <div>
        <p class="eyebrow">Marketplace sale</p>
        <p style="font-family:var(--display);font-size:26px;font-weight:700;margin-top:6px"><?= e($sale['reference']) ?></p>
      </div>
      <span class="chip chip--pill <?= in_array($status, ['held','shipped','delivered','released'], true) ? 'chip--ok' : '' ?>">
        <?= e(sale_status_label($status)) ?>
      </span>
    </div>

    <div class="layout" style="align-items:flex-start">
      <div class="results" style="display:flex;flex-direction:column;gap:20px">

        <div class="card card--pad">
          <div class="row row--tight" style="gap:16px;flex-wrap:nowrap">
            <span style="flex:0 0 auto;width:90px;height:68px;border-radius:8px;overflow:hidden;background:var(--surface);display:flex;align-items:center;justify-content:center">
              <?php if ($listing && $listing['image']): ?>
                <img src="<?= e(url($listing['image'])) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?><span class="tiny muted">No photo</span><?php endif; ?>
            </span>
            <div style="min-width:0">
              <a style="font-weight:700;font-size:16px" href="<?= e($listing ? listing_url($listing) : '/marketplace') ?>"><?= e($listing['title'] ?? 'Listing') ?></a>
              <p class="small muted" style="margin-top:4px">
                You are the <?= $is_buyer ? 'buyer' : ($is_seller ? 'seller' : 'admin') ?>
                <?php if ($other): ?>&middot; dealing with <?= e($other['name']) ?><?php endif; ?>
              </p>
            </div>
          </div>
        </div>

        <!-- what happens next, in plain words -->
        <div class="card card--pad card--olive">
          <h2 class="h-kicker" style="margin-bottom:10px;color:var(--olive-ink)">Where this is</h2>
          <p class="small" style="color:var(--olive-dark);line-height:1.7">
            <?php if ($status === 'pending'): ?>
              Nothing is charged yet and the seller has not been asked to post anything.
            <?php elseif ($status === 'held'): ?>
              <?= $sale['inspected']
                ? 'We are holding the money. The seller posts it <strong>to us</strong> first, because an inspection was asked for.'
                : 'We are holding the money. The seller has three days to post it.' ?>
            <?php elseif ($status === 'to_us'): ?>
              On its way to our bench. We will test it and photograph what we find.
            <?php elseif ($status === 'inspecting'): ?>
              On our bench. We are testing that it feeds, fires and chronos the way the listing says.
            <?php elseif ($status === 'failed'): ?>
              It is not what the listing described. The sale is cancelled and the buyer is being refunded in full.
            <?php elseif ($status === 'shipped'): ?>
              On its way<?= $sale['tracking'] ? ' — ' . e($sale['courier'] ?: 'courier') . ' ' . e($sale['tracking']) : '' ?>.
              <?= $is_buyer ? 'Tell us when it arrives, and film the unboxing uncut before you open it.' : 'The money is released once the buyer confirms.' ?>
            <?php elseif ($status === 'delivered'): ?>
              Delivered. <?= $sale['confirm_by']
                ? 'The buyer has until ' . e(date('j M, H:i', strtotime((string) $sale['confirm_by']))) . ' to raise a problem; after that the money goes to the seller.'
                : '' ?>
            <?php elseif ($status === 'disputed'): ?>
              The buyer has raised a problem. We are holding the money while we sort it out.
            <?php elseif ($status === 'released'): ?>
              Done. The money has gone to the seller.
            <?php elseif ($status === 'refunded'): ?>
              Refunded to the buyer.
            <?php else: ?>
              <?= e(sale_status_label($status)) ?>.
            <?php endif; ?>
          </p>
        </div>

        <?php if ($moves): ?>
          <div class="card card--pad-lg">
            <h2 class="h-card" style="margin-bottom:14px">What you can do</h2>

            <?php foreach ($moves as $to => $who): ?>
              <form method="post" action="/sale" style="margin-bottom:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="ref" value="<?= e($sale['reference']) ?>">
                <input type="hidden" name="to" value="<?= e($to) ?>">

                <?php if ($to === 'disputed'): ?>
                  <label class="field__label" for="note-<?= e($to) ?>">What is wrong with it?</label>
                  <textarea class="textarea" id="note-<?= e($to) ?>" name="note" rows="3" required
                            placeholder="What arrived, how it differs from the listing, and what the unboxing video shows."></textarea>
                  <button class="btn btn--sm" type="submit" style="margin-top:10px;color:var(--bad)">Raise a problem</button>
                <?php elseif ($to === 'failed' || $to === 'inspecting'): ?>
                  <label class="field__label" for="note-<?= e($to) ?>"><?= $to === 'failed' ? 'What is wrong with it' : 'What you found' ?></label>
                  <input class="input" id="note-<?= e($to) ?>" name="note" maxlength="1000">
                  <button class="btn btn--sm" type="submit" style="margin-top:10px"><?= e(ucfirst(str_replace('_', ' ', $to))) ?></button>
                <?php else: ?>
                  <button class="btn <?= $to === 'delivered' || $to === 'shipped' ? 'btn--primary' : '' ?> btn--sm" type="submit">
                    <?= e(match ($to) {
                        'to_us'     => 'I have posted it to you',
                        'shipped'   => $is_seller ? 'I have posted it to the buyer' : 'Send it on to the buyer',
                        'delivered' => 'It arrived — all good',
                        'released'  => 'Release the money',
                        'refunded'  => 'Refund the buyer',
                        'cancelled' => 'Cancel the sale',
                        default     => sale_status_label($to),
                    }) ?>
                  </button>
                <?php endif; ?>
              </form>
            <?php endforeach; ?>

            <?php if ($status === 'delivered' && $is_buyer): ?>
              <p class="tiny muted" style="margin-top:4px">Nothing to do if it is fine &mdash; the money goes to the seller on its own once the window closes.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (($is_seller || user_is_staff($me)) && in_array($status, ['held','to_us','inspecting','shipped'], true)): ?>
          <form class="card card--pad" method="post" action="/sale">
            <?= csrf_field() ?>
            <input type="hidden" name="ref" value="<?= e($sale['reference']) ?>">
            <input type="hidden" name="to" value="tracking">
            <h2 class="h-kicker" style="margin-bottom:12px">Tracking</h2>
            <div class="row row--tight">
              <div class="field" style="flex:1 1 150px;margin:0">
                <label class="field__label" for="courier">Courier</label>
                <input class="input" id="courier" name="courier" maxlength="60" value="<?= e($sale['courier']) ?>" placeholder="Delhivery">
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="tracking">Number</label>
                <input class="input" id="tracking" name="tracking" maxlength="120" value="<?= e($sale['tracking']) ?>">
              </div>
            </div>
            <button class="btn btn--sm" type="submit" style="margin-top:14px">Save tracking</button>
          </form>
        <?php endif; ?>

        <?php if ($sale['inspection_note']): ?>
          <div class="card card--pad">
            <h2 class="h-kicker" style="margin-bottom:10px">What we found on the bench</h2>
            <p class="small" style="color:var(--ink-3)"><?= nl2br(e((string) $sale['inspection_note'])) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($sale['dispute_note']): ?>
          <div class="card card--pad" style="border-color:var(--ember-line);background:var(--ember-tint)">
            <h2 class="h-kicker" style="margin-bottom:10px">The problem raised</h2>
            <p class="small"><?= nl2br(e((string) $sale['dispute_note'])) ?></p>
          </div>
        <?php endif; ?>

        <div class="card card--pad">
          <h2 class="h-kicker" style="margin-bottom:14px">What has happened</h2>
          <ol class="steps">
            <?php foreach ($events as $ev): ?>
              <li><span class="n">&middot;</span>
                <span>
                  <?= e(sale_status_label((string) $ev['became'])) ?>
                  <span class="tiny muted" style="display:block">
                    <?= e(date('j M Y, H:i', strtotime((string) $ev['created_at']))) ?>
                    <?= $ev['actor_name'] ? ' &middot; ' . e($ev['actor_name']) : '' ?>
                    <?= $ev['note'] ? ' &middot; ' . e($ev['note']) : '' ?>
                  </span>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>

      <aside class="rail" style="max-width:320px">
        <div class="card card--pad">
          <h2 class="h-kicker" style="margin-bottom:14px"><?= $is_seller ? 'Your payout' : 'What you paid' ?></h2>
          <?php if ($is_seller): ?>
            <div class="row row--between" style="gap:10px;font-size:13.5px"><span class="muted">Sale price</span><span style="font-weight:600"><?= money((int) $sale['price_paise']) ?></span></div>
            <div class="row row--between" style="gap:10px;font-size:13.5px;margin-top:8px"><span class="muted">Marketplace fee</span><span style="font-weight:600">&minus;<?= money((int) $sale['seller_fee_paise']) ?></span></div>
            <div class="row row--between" style="gap:10px;font-size:13.5px;margin-top:8px"><span class="muted">Payment handling</span><span style="font-weight:600">&minus;<?= money((int) $sale['handling_paise']) ?></span></div>
            <div class="row row--between" style="gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
              <span style="font-family:var(--display);font-weight:700">You receive</span>
              <span class="price"><?= money((int) $sale['payout_paise']) ?></span>
            </div>
            <?php if ($sale['inspected']): ?>
              <p class="tiny muted" style="margin-top:12px">The buyer paid for the inspection. It has not come out of your side.</p>
            <?php endif; ?>
          <?php else: ?>
            <div class="row row--between" style="gap:10px;font-size:13.5px"><span class="muted">Item</span><span style="font-weight:600"><?= money((int) $sale['price_paise']) ?></span></div>
            <div class="row row--between" style="gap:10px;font-size:13.5px;margin-top:8px"><span class="muted">Courier</span><span style="font-weight:600"><?= (int) $sale['postage_paise'] ? money((int) $sale['postage_paise']) : 'Included' ?></span></div>
            <?php if ((int) $sale['inspection_paise']): ?>
              <div class="row row--between" style="gap:10px;font-size:13.5px;margin-top:8px"><span class="muted">Inspection</span><span style="font-weight:600"><?= money((int) $sale['inspection_paise']) ?></span></div>
            <?php endif; ?>
            <div class="row row--between" style="gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
              <span style="font-family:var(--display);font-weight:700">Total</span>
              <span class="price"><?= money((int) $sale['total_paise']) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <div class="card card--pad" style="margin-top:16px">
          <h2 class="h-kicker" style="margin-bottom:12px">Going to</h2>
          <p class="small" style="line-height:1.7">
            <?= e($sale['ship_name']) ?><br>
            <?= e($sale['ship_line1']) ?><br>
            <?php if ($sale['ship_line2']): ?><?= e($sale['ship_line2']) ?><br><?php endif; ?>
            <?= e($sale['ship_city']) ?>, <?= e($sale['ship_state']) ?> <?= e($sale['ship_pin']) ?><br>
            <span class="muted"><?= e($sale['ship_phone']) ?></span>
          </p>
          <?php if ($is_seller && $sale['inspected'] && in_array($status, ['held','to_us'], true)): ?>
            <p class="tiny" style="margin-top:12px;padding-top:12px;border-top:1px solid #EDEAE3;color:var(--warn)">
              <strong>Not this address.</strong> An inspection was asked for, so it comes to us first. We will message you where to send it.
            </p>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
