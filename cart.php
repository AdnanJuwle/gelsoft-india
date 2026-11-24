<?php
/**
 * The cart. Priced from the database every time it is drawn, so nothing here
 * depends on what the session was told an item cost.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cart.php';
require __DIR__ . '/inc/shop.php';
require_once __DIR__ . '/inc/payments.php';

$cart  = cart_contents();
$flash = (string) ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$page = [
    'title'   => 'Your cart',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Cart</span>
    </nav>

    <h1 class="h-page">Your cart</h1>

    <?php if ($flash): ?>
      <p class="notice notice--olive" role="status" style="margin-top:20px"><span class="small"><?= e($flash) ?></span></p>
    <?php endif; ?>

    <?php foreach ($cart['problems'] as $problem): ?>
      <p class="notice notice--paper" role="alert" style="margin-top:14px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><?= e($problem) ?></span>
      </p>
    <?php endforeach; ?>

    <?php if (!$cart['lines']): ?>
      <div class="empty" style="margin-top:28px">
        <span class="empty__icon" aria-hidden="true">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 6h15l-1.5 9.5H6.5z" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.4" fill="currentColor"/><circle cx="16" cy="20" r="1.4" fill="currentColor"/></svg>
        </span>
        <h2>Nothing in it yet</h2>
        <p>Add a blaster, some gear or a handful of parts and they will sit here until you are ready.</p>
        <div class="row row--tight" style="justify-content:center;gap:20px">
          <a class="btn btn--dark btn--sm" href="/blasters">Shop blasters</a>
          <a class="btn btn--sm" href="/parts">Parts &amp; gel</a>
        </div>
      </div>

    <?php else: ?>
      <div class="layout" style="margin-top:28px">
        <div class="results">
          <div class="table-wrap">
            <?php foreach ($cart['lines'] as $line): $p = $line['product']; ?>
              <div class="cart-line">
                <a class="cart-line__media" href="<?= e(product_url($p)) ?>">
                  <?php if ($p['image']): ?>
                    <img src="<?= e(url($p['image'])) ?>" alt="" width="80" height="60">
                  <?php else: ?>
                    <span class="ph ph--thumb"><span class="tiny">No photo</span></span>
                  <?php endif; ?>
                </a>

                <div class="cart-line__body">
                  <?php if ($p['brand']): ?><span class="prod__brand"><?= e($p['brand']) ?></span><?php endif; ?>
                  <a class="cart-line__name" href="<?= e(product_url($p)) ?>"><?= e($p['name']) ?></a>
                  <span class="tiny muted"><?= money((int) $p['price_paise']) ?> each</span>
                </div>

                <form class="cart-line__qty" method="post" action="/api/cart" data-cart-form>
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="set">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="return" value="/cart">
                  <label class="sr-only" for="qty-<?= (int) $p['id'] ?>">Quantity of <?= e($p['name']) ?></label>
                  <select class="select" id="qty-<?= (int) $p['id'] ?>" name="qty" data-autosubmit-field>
                    <?php for ($n = 1; $n <= min(CART_MAX_QTY, max(1, (int) $p['stock_qty'])); $n++): ?>
                      <option value="<?= $n ?>"<?= $n === $line['qty'] ? ' selected' : '' ?>><?= $n ?></option>
                    <?php endfor; ?>
                  </select>
                  <noscript><button class="btn btn--xs" type="submit">Update</button></noscript>
                </form>

                <span class="cart-line__total price"><?= money($line['line_paise']) ?></span>

                <form class="cart-line__rm" method="post" action="/api/cart">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="remove">
                  <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                  <input type="hidden" name="return" value="/cart">
                  <button class="btn--link cart-line__remove" type="submit" aria-label="Remove <?= e($p['name']) ?>">Remove</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="row row--tight" style="margin-top:20px">
            <a class="small" style="font-weight:600" href="/blasters">&larr; Keep shopping</a>
          </div>
        </div>

        <aside class="rail" style="max-width:340px" aria-label="Order summary">
          <div class="card card--pad" style="background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
            <h2 class="h-kicker" style="margin-bottom:18px;color:#fff">Summary</h2>
            <dl class="payout">
              <div class="row row--between" style="gap:12px">
                <dt class="on-dark"><?= (int) $cart['count'] ?> item<?= $cart['count'] === 1 ? '' : 's' ?></dt>
                <dd><?= money($cart['subtotal']) ?></dd>
              </div>
              <div class="row row--between payout__rule" style="gap:12px">
                <dt class="on-dark">Shipping</dt>
                <dd><?= $cart['shipping'] === 0 ? '<span class="payout__free">Free</span>' : money($cart['shipping']) ?></dd>
              </div>
              <div class="row row--between" style="gap:12px">
                <dt class="payout__net-label">Total</dt>
                <dd class="payout__net"><?= money($cart['total']) ?></dd>
              </div>
            </dl>

            <?php if ($cart['shipping'] > 0): ?>
              <p class="tiny payout__note">
                <?= money(SHIP_FREE_OVER - $cart['subtotal']) ?> more and the courier is on us.
              </p>
            <?php endif; ?>

            <a class="btn btn--primary btn--block" href="/checkout" style="margin-top:18px">Checkout</a>
            <p class="tiny payout__note" style="text-align:center"><?= payments_manual()
                ? 'UPI or bank transfer. We confirm the order with you on WhatsApp.'
                : 'UPI, cards and net banking.' ?></p>
          </div>

          <div class="card card--pad" style="margin-top:18px">
            <ul class="list" style="font-size:13.5px">
              <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Chrono-tested before it is boxed</li>
              <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Plain, unmarked packaging</li>
              <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Shipping damage replaced on an uncut unboxing video</li>
            </ul>
            <p class="tiny muted" style="margin-top:12px">All sales are otherwise final, and we do not offer warranties. <a href="/policy">The policy in full &rarr;</a></p>
          </div>
        </aside>
      </div>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
