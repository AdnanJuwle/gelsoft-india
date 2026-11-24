<?php
/**
 * One order: the thank-you page, and where someone comes back to check on it.
 *
 * Reached with ?ref=GS-2026-0001&t=<token>. The token is derived from the
 * order with a server-side secret, so knowing a reference is not enough to
 * read somebody's address — and a signed-in customer does not need it.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cart.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/payments.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/mail.php';

$me = current_user();
$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
$order = $reference !== '' ? order_by_reference($reference) : null;

if (!$order || !order_viewable($order, $me)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$items = order_items((int) $order['id']);
$just_paid = !empty($order['payment_id']) && $order['status'] === 'pending';
$token = order_token($order);

$page = [
    'title'   => 'Order ' . $order['reference'],
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px;max-width:60em">

    <?php if (in_array($order['status'], ['paid','packed','shipped','delivered'], true)): ?>
      <?php
      /*
       * One banner per status, not one for all four.
       *
       * These four used to share "Paid. Thank you. ... we will email the
       * tracking number when it leaves", which is only true of the first one.
       * On a shipped order the page then promised a tracking number directly
       * above a chip reading "On its way" and a card holding that very number.
       *
       * The tracking number is also WHY somebody opens this page again, so on
       * a shipped order it belongs in the banner rather than below the money.
       */
      $has_track = (string) ($order['tracking'] ?? '') !== '';
      ?>
      <div class="notice notice--olive" style="margin-bottom:24px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <?php if ($order['status'] === 'paid'): ?>
            <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Paid. Thank you.</h1>
            <p class="small">A receipt is on its way to <?= e($order['email']) ?>.<?= $has_track ? '' : ' We will email again with the tracking number when it leaves.' ?></p>

          <?php elseif ($order['status'] === 'packed'): ?>
            <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Packed, waiting on the courier.</h1>
            <p class="small">It is boxed and labelled.<?= $has_track ? '' : ' We will email ' . e($order['email']) . ' with the tracking number the moment it is handed over.' ?></p>

          <?php elseif ($order['status'] === 'shipped'): ?>
            <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">On its way.</h1>
            <?php if (!$has_track): ?>
              <p class="small">Handed to the courier. We will email <?= e($order['email']) ?> the tracking number shortly.</p>
            <?php endif; ?>

          <?php else: ?>
            <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Delivered.</h1>
            <p class="small">Marked delivered<?= $has_track ? ' by ' . e($order['courier'] ?: 'the courier') : '' ?>. If something is wrong with it, the unboxing video is what we can act on &mdash; see below.</p>
          <?php endif; ?>

          <?php /* One place prints the number, whatever the status. Promising
                    to email a number the page is already showing is exactly
                    the contradiction this block came from. */ ?>
          <?php if ($has_track && $order['status'] !== 'delivered'): ?>
            <p class="small" style="margin-top:10px">With <?= e($order['courier'] ?: 'the courier') ?>, tracking number</p>
            <p class="order-track"><?= e($order['tracking']) ?></p>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($just_paid): ?>
      <!-- The browser came back with a verified payment but the webhook has
           not landed yet. Usually a second or two. -->
      <div class="notice notice--paper" style="margin-bottom:24px" data-poll-order="<?= e($order['reference']) ?>" data-poll-token="<?= e($token) ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2" stroke-linecap="round"/></svg>
        <div>
          <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Payment received, confirming it</h1>
          <p class="small">Your bank has told Razorpay. We are waiting for Razorpay to tell us, which is normally a few seconds. This page updates itself &mdash; nothing more for you to do, and the money is already taken care of.</p>
        </div>
      </div>

    <?php elseif ($order['status'] === 'pending' && ($order['payment_provider'] ?? '') === 'whatsapp'): ?>
      <?php
      /*
       * Placed, and paid for over WhatsApp. This is the page the customer
       * lands on straight after "Place order", so it says what happens next
       * rather than "not paid", which reads like something went wrong.
       *
       * The button opens WhatsApp to the shop with the order number already
       * typed: the quickest way for the team to hear about it, and it works
       * with no mailbox configured at all. Drawn only when contact.whatsapp
       * is set, so it never points at nobody.
       */
      $shop_wa  = shop_wa_digits();
      $blaster  = order_has_blaster((int) $order['id']);
      ?>
      <div class="notice notice--olive" style="margin-bottom:24px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Order placed. We will be in touch on WhatsApp.</h1>
          <p class="small">Our team will message <strong><?= e($order['phone']) ?></strong> on WhatsApp with the payment details (UPI or bank transfer)<?= $blaster ? ' and a video of your blaster being chrono-tested' : '' ?>. Nothing has been charged, and it ships once it is paid.</p>
          <?php if ($shop_wa !== ''): ?>
            <a class="btn btn--olive btn--sm" style="margin-top:14px"
               href="<?= e(wa_link($shop_wa, 'Hi Gelsoft, I have placed order ' . $order['reference'] . ' for ' . money((int) $order['total_paise']) . '.')) ?>"
               target="_blank" rel="noopener">Send us your order on WhatsApp</a>
            <p class="tiny" style="margin-top:8px">Quickest way to reach us: it opens WhatsApp with your order number already typed.</p>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($order['status'] === 'pending'): ?>
      <div class="notice notice--paper" style="margin-bottom:24px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
        <div>
          <h1 style="font-size:22px;font-weight:700;margin-bottom:4px">Not paid yet</h1>
          <p class="small">The order is saved and nothing has been charged. Nothing ships until it is paid.</p>
          <?php if (isset($_GET['unpaid'])): ?>
            <p class="small" style="margin-top:8px">Payments are not switched on yet on this site &mdash; we will be in touch about settling it.</p>
          <?php endif; ?>
        </div>
      </div>

    <?php elseif ($order['status'] === 'cancelled' || $order['status'] === 'refunded'): ?>
      <div class="notice notice--paper" style="margin-bottom:24px">
        <div>
          <h1 style="font-size:22px;font-weight:700;margin-bottom:4px"><?= e(order_status_label((string) $order['status'])) ?></h1>
          <p class="small">Nothing further is owed on this one.</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="row row--between row--center" style="margin-bottom:20px">
      <div>
        <p class="eyebrow">Order</p>
        <p style="font-family:var(--display);font-size:26px;font-weight:700;margin-top:6px"><?= e($order['reference']) ?></p>
      </div>
      <span class="chip chip--pill <?= in_array($order['status'], ['paid','packed','shipped','delivered'], true) ? 'chip--ok' : '' ?>">
        <?= e(order_status_label((string) $order['status'])) ?>
      </span>
    </div>

    <div class="layout" style="align-items:flex-start">
      <div class="results">
        <div class="table-wrap">
          <?php foreach ($items as $i): ?>
            <div class="cart-line">
              <div class="cart-line__body">
                <?php if ($i['slug_at_purchase']): ?>
                  <a class="cart-line__name" href="/blasters/<?= e(rawurlencode((string) $i['slug_at_purchase'])) ?>"><?= e($i['name_at_purchase']) ?></a>
                <?php else: ?>
                  <span class="cart-line__name"><?= e($i['name_at_purchase']) ?></span>
                <?php endif; ?>
                <span class="tiny muted"><?= money((int) $i['price_paise']) ?> &times; <?= (int) $i['qty'] ?></span>
              </div>
              <span class="cart-line__total price"><?= money((int) $i['price_paise'] * (int) $i['qty']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card card--pad" style="margin-top:18px">
          <div class="row row--between" style="gap:10px;font-size:14px">
            <span class="muted">Subtotal</span><span style="font-weight:600"><?= money((int) $order['subtotal_paise']) ?></span>
          </div>
          <div class="row row--between" style="gap:10px;font-size:14px;margin-top:8px">
            <span class="muted">Shipping</span>
            <span style="font-weight:600"><?= (int) $order['shipping_paise'] === 0 ? 'Free' : money((int) $order['shipping_paise']) ?></span>
          </div>
          <div class="row row--between" style="gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
            <span style="font-family:var(--display);font-weight:700">Total</span>
            <span class="price"><?= money((int) $order['total_paise']) ?></span>
          </div>
        </div>

        <?php if ($order['tracking'] && !in_array($order['status'], ['paid','packed','shipped'], true)): ?>
          <div class="card card--pad" style="margin-top:18px">
            <h2 class="h-kicker" style="margin-bottom:10px">Sent with</h2>
            <p class="small"><?= e($order['courier'] ?: 'Courier') ?> &middot; <strong><?= e($order['tracking']) ?></strong></p>
          </div>
        <?php endif; ?>
      </div>

      <aside class="rail" style="max-width:320px" aria-label="Delivery details">
        <div class="card card--pad">
          <h2 class="h-kicker" style="margin-bottom:12px">Going to</h2>
          <p class="small" style="line-height:1.7">
            <?= e($order['ship_name']) ?><br>
            <?= e($order['ship_line1']) ?><br>
            <?php if ($order['ship_line2']): ?><?= e($order['ship_line2']) ?><br><?php endif; ?>
            <?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?> <?= e($order['ship_pin']) ?><br>
            <span class="muted"><?= e($order['phone']) ?></span>
          </p>
          <?php if ($order['note']): ?>
            <p class="tiny muted" style="margin-top:12px;padding-top:12px;border-top:1px solid #EDEAE3"><?= e($order['note']) ?></p>
          <?php endif; ?>
        </div>

        <div class="card card--pad card--olive" style="margin-top:18px">
          <h2 class="h-kicker" style="margin-bottom:10px;color:var(--olive-ink)">When it lands</h2>
          <p class="small" style="color:var(--olive-dark);line-height:1.65">Film the unboxing, uncut, before you open the inner packaging. That video is the only thing we can act on if something is damaged in transit.</p>
          <a class="small" style="font-weight:600;display:inline-block;margin-top:10px;color:var(--olive-dark)" href="/policy">The policy &rarr;</a>
        </div>

        <?php if (!$me): ?>
          <p class="tiny muted" style="margin-top:16px">Keep this link &mdash; it is how you check on the order without an account. <a href="/register" style="font-weight:600">Make one</a> and your orders are all in one place.</p>
        <?php endif; ?>
      </aside>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
