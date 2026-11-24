<?php
/**
 * Checkout: where it is going, then pay.
 *
 * The total charged is recalculated here from the database. The page shows a
 * number, but the number the customer is charged is computed again in
 * order_create() and again when the Razorpay order is made — nothing a
 * browser sends gets anywhere near it.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/cart.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/payments.php';
require __DIR__ . '/inc/mail.php';

$me   = current_user();
$cart = cart_contents();

if (!$cart['lines']) {
    redirect('/cart');
}

$errors  = [];
$rzp     = null;   // set once the order exists and Razorpay has accepted it
$order   = null;

// No Razorpay keys yet: the order is placed and the team settles payment on
// WhatsApp. See payments_manual() in inc/payments.php.
$manual  = payments_manual();
$has_blaster = false;
foreach ($cart['lines'] as $line) {
    if (($line['product']['category'] ?? '') === 'blaster') {
        $has_blaster = true;
    }
}

// Pre-fill from the account, and from their default address if they have one.
$saved = ($me && db_ready())
    ? q1('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1', [$me['id']])
    : null;

$form = [
    'name'  => $_POST['name']  ?? ($saved['name']  ?? ($me['name'] ?? '')),
    'email' => $_POST['email'] ?? ($me['email'] ?? ''),
    'phone' => $_POST['phone'] ?? ($saved['phone'] ?? ($me['phone'] ?? '')),
    'line1' => $_POST['line1'] ?? ($saved['line1'] ?? ''),
    'line2' => $_POST['line2'] ?? ($saved['line2'] ?? ''),
    'city'  => $_POST['city']  ?? ($saved['city']  ?? ''),
    'state' => $_POST['state'] ?? ($saved['state'] ?? ''),
    'pin'   => $_POST['pin']   ?? ($saved['pin']   ?? ''),
    'note'  => $_POST['note']  ?? '',
];

const STATES = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa',
    'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala',
    'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland',
    'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
    'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
    'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
    'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    foreach (['name', 'email', 'phone', 'line1', 'city', 'state', 'pin'] as $required) {
        if (trim((string) $form[$required]) === '') {
            $errors[] = 'Fill in every field except the second address line and the note.';
            break;
        }
    }

    $form = array_map(static fn($v) => trim((string) $v), $form);

    if (!$errors && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address does not look right — it is where the receipt goes.';
    }
    if (!$errors && !preg_match('/^(\+91[\s-]?)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $form['phone']) ?? '')) {
        $errors[] = 'That does not look like an Indian mobile number. The courier will need it.';
    }
    if (!$errors && !preg_match('/^[1-9]\d{5}$/', $form['pin'])) {
        $errors[] = 'A PIN code is six digits and does not start with a zero.';
    }
    if (!$errors && !in_array($form['state'], STATES, true)) {
        $errors[] = 'Pick a state from the list.';
    }

    /*
     * Length, before the INSERT rather than after it.
     *
     * Without this the row reaches the database, STRICT_TRANS_TABLES refuses
     * it, order_create() rolls back and answers "The order could not be
     * created" -- true, safe, and no use at all to someone who cannot tell
     * which of six fields to shorten. A valid email can be 198 characters;
     * the column holds 190.
     */
    if (!$errors) {
        $errors = array_merge($errors, length_errors($form, [
            'name'  => ['orders.ship_name',  'Your name'],
            'email' => ['orders.email',      'That email address'],
            'line1' => ['orders.ship_line1', 'The first address line'],
            'line2' => ['orders.ship_line2', 'The second address line'],
            'city'  => ['orders.ship_city',  'The city'],
            'note'  => ['orders.note',       'The note'],
        ]));
    }

    if (!$errors) {
        $made = order_create(
            [
                'name'  => $form['name'],  'line1' => $form['line1'], 'line2' => $form['line2'],
                'city'  => $form['city'],  'state' => $form['state'], 'pin'   => $form['pin'],
            ],
            $me ? (int) $me['id'] : null,
            $form['email'],
            $form['phone'],
            $form['note'],
            $manual ? 'whatsapp' : 'razorpay'
        );

        if (isset($made['error'])) {
            $errors[] = $made['error'];
            if (!empty($made['recheck'])) {
                redirect('/cart');
            }
        } else {
            $order = $made['order'];

            if ($manual) {
                // Placed, unpaid, and settled on WhatsApp. The cart empties
                // now -- the order IS the purchase from the customer's side,
                // and a full cart afterwards invites placing it twice.
                cart_clear();
                try {
                    send_new_order_alert((int) $order['id']);
                } catch (Throwable $e) {
                    error_log('new order alert failed: ' . $e->getMessage());
                }
                redirect('/order?ref=' . urlencode((string) $order['reference'])
                    . '&t=' . urlencode(order_token($order)) . '&placed=1');
            }

            $created = rzp_create_order(
                (int) $order['total_paise'],
                (string) $order['reference'],
                ['order_reference' => $order['reference']]
            );

            if (isset($created['error'])) {
                $errors[] = $created['error'] . ' Your order is saved as ' . $order['reference'] . ' and nothing has been charged.';
            } else {
                exec_sql('UPDATE orders SET payment_order_id = ? WHERE id = ?',
                    [$created['id'], (int) $order['id']]);
                $rzp = $created;
            }
        }
    }
}

// Razorpay's widget is only allowed to load on this page, and only once we
// actually have an order for it to collect.
if ($rzp) {
    csp_allow_payments();
}

$page = [
    'title'   => 'Checkout',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/cart">Cart</a><span>/</span><span class="here">Checkout</span>
    </nav>

    <h1 class="h-page">Checkout</h1>

    <?php if (rzp_test_mode()): ?>
      <p class="notice notice--paper" style="margin-top:18px">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><strong>Test mode.</strong> No real money moves. Use Razorpay's test card <code>4111 1111 1111 1111</code>, any future expiry, any CVV.</span>
      </p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <p class="notice notice--paper" role="alert" style="margin-top:14px;color:var(--bad)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><?= e($err) ?></span>
      </p>
    <?php endforeach; ?>

    <?php if ($rzp): ?>
      <!-- The order exists and is pending. Hand off to Razorpay. -->
      <div class="card card--pad-lg" style="margin-top:24px;max-width:34em">
        <h2 class="h-card" style="margin-bottom:10px">Order <?= e($order['reference']) ?> is ready</h2>
        <p class="small muted">Opening the payment window&hellip; if nothing happens, use the button.</p>
        <button class="btn btn--primary btn--block" type="button" id="pay-now" style="margin-top:18px">
          Pay <?= money((int) $order['total_paise']) ?>
        </button>
        <p class="tiny muted" style="margin-top:14px">Nothing is charged until you finish on Razorpay's screen. If you close it, the order waits as unpaid and we will not ship it.</p>
      </div>

      <script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>
      <script src="<?= asset('assets/js/checkout.js') ?>" defer
              data-key="<?= e((string) cfg('razorpay.key_id')) ?>"
              data-order="<?= e($rzp['id']) ?>"
              data-amount="<?= (int) $order['total_paise'] ?>"
              data-name="Gelsoft India"
              data-description="Order <?= e($order['reference']) ?>"
              data-reference="<?= e($order['reference']) ?>"
              data-token="<?= e(order_token($order)) ?>"
              data-prefill-name="<?= e($order['ship_name']) ?>"
              data-prefill-email="<?= e($order['email']) ?>"
              data-prefill-contact="<?= e($order['phone']) ?>"></script>

    <?php else: ?>
      <form class="layout layout--pay" method="post" action="/checkout" style="margin-top:24px;align-items:flex-start">
        <?= csrf_field() ?>

        <div class="results" style="display:flex;flex-direction:column;gap:20px">
          <fieldset class="fieldset--card">
            <legend>Where it is going</legend>

            <div class="row row--tight" style="margin-top:4px">
              <div class="field" style="flex:1 1 220px;margin:0">
                <label class="field__label" for="name">Full name <span class="req">*</span></label>
                <input class="input" id="name" name="name" maxlength="120" required autocomplete="name" value="<?= e($form['name']) ?>">
              </div>
              <div class="field" style="flex:1 1 180px;margin:0">
                <label class="field__label" for="phone">Mobile <span class="req">*</span></label>
                <input class="input" id="phone" name="phone" type="tel" required maxlength="20" autocomplete="tel" value="<?= e($form['phone']) ?>" placeholder="98765 43210">
                <p class="field__hint"><?= $manual ? 'We WhatsApp this number about payment, and the courier calls it.' : 'The courier calls this.' ?></p>
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="email">Email <span class="req">*</span></label>
              <input class="input" id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($form['email']) ?>">
              <p class="field__hint">The receipt and the tracking number go here.</p>
            </div>

            <div class="field">
              <label class="field__label" for="line1">Address <span class="req">*</span></label>
              <input class="input" id="line1" name="line1" maxlength="190" required autocomplete="address-line1" value="<?= e($form['line1']) ?>" placeholder="Flat, building, street">
            </div>

            <div class="field">
              <label class="sr-only" for="line2">Address line two</label>
              <input class="input" id="line2" name="line2" maxlength="190" autocomplete="address-line2" value="<?= e($form['line2']) ?>" placeholder="Area, landmark (optional)">
            </div>

            <div class="row row--tight">
              <div class="field" style="flex:1 1 170px;margin:0">
                <label class="field__label" for="city">City <span class="req">*</span></label>
                <input class="input" id="city" name="city" maxlength="90" required autocomplete="address-level2" value="<?= e($form['city']) ?>">
              </div>
              <div class="field" style="flex:1 1 190px;margin:0">
                <label class="field__label" for="state">State <span class="req">*</span></label>
                <select class="select" id="state" name="state" required autocomplete="address-level1">
                  <option value="">Pick one</option>
                  <?php foreach (STATES as $s): ?>
                    <option value="<?= e($s) ?>"<?= $form['state'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" style="flex:1 1 120px;margin:0">
                <label class="field__label" for="pin">PIN <span class="req">*</span></label>
                <input class="input" id="pin" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" required autocomplete="postal-code" value="<?= e($form['pin']) ?>">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="note">Anything we should know <span class="opt">optional</span></label>
              <input class="input" id="note" name="note" maxlength="500" value="<?= e($form['note']) ?>" placeholder="Leave with the guard, call before delivery&hellip;">
            </div>
          </fieldset>

          <div class="notice notice--olive">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="1.9" aria-hidden="true"><path d="M12 3l7.5 3.4v5c0 4.3-3 8.2-7.5 9.6-4.5-1.4-7.5-5.3-7.5-9.6v-5z" stroke-linejoin="round"/></svg>
            <p class="small">It ships in a plain box with nothing on the outside that says what is inside. By ordering you are agreeing to carry it concealed and use it on private ground &mdash; <a href="/safety" style="text-decoration:underline">the standards in full</a>.</p>
          </div>

          <?php if ($manual): ?>
            <!-- Said BEFORE the button, so nobody presses it expecting a
                 payment screen. -->
            <div class="notice notice--paper">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z" stroke-linejoin="round"/><path d="M9.5 9.5c.3 1.6 1.4 3.3 3 4.4l1.2-1 1.8.9-.4 1.5c-2.8.2-6.4-3.2-6.4-6.3l1.5-.3.8 1.8z" stroke-linejoin="round"/></svg>
              <div>
                <p class="small"><strong>How paying works for now.</strong> Placing the order does not charge you anything. Our team will message you on WhatsApp with the payment details (UPI or bank transfer)<?= $has_blaster ? ' and a video of your blaster being chrono-tested' : '' ?>. It ships once it is paid.</p>
              </div>
            </div>
          <?php endif; ?>

          <div class="row row--tight row--center">
            <button class="btn btn--primary" type="submit"><?= $manual ? 'Place order' : 'Continue to payment' ?></button>
            <a class="btn" href="/cart">Back to cart</a>
          </div>
        </div>

        <aside class="rail" style="max-width:320px" aria-label="Order summary">
          <div class="card card--pad">
            <h2 class="h-kicker" style="margin-bottom:16px"><?= (int) $cart['count'] ?> item<?= $cart['count'] === 1 ? '' : 's' ?></h2>
            <?php foreach ($cart['lines'] as $line): ?>
              <div class="row row--between" style="gap:10px;font-size:13.5px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid #EDEAE3">
                <span style="min-width:0"><?= e($line['product']['name']) ?> <span class="muted">&times;<?= (int) $line['qty'] ?></span></span>
                <span style="font-weight:600;white-space:nowrap"><?= money($line['line_paise']) ?></span>
              </div>
            <?php endforeach; ?>

            <div class="row row--between" style="gap:10px;font-size:13.5px">
              <span class="muted">Shipping</span>
              <span style="font-weight:600"><?= $cart['shipping'] === 0 ? 'Free' : money($cart['shipping']) ?></span>
            </div>
            <div class="row row--between" style="gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
              <span style="font-family:var(--display);font-weight:700">Total</span>
              <span class="price"><?= money($cart['total']) ?></span>
            </div>
          </div>
        </aside>
      </form>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
