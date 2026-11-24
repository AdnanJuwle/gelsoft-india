<?php
/**
 * Buying a listing: where it goes, then pay.
 *
 * Same rule as the shop — every number is computed here from the listing row.
 * The form chooses only *whether* an inspection is wanted.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';
require __DIR__ . '/inc/listing_sales.php';
require_once __DIR__ . '/inc/offers.php';
require __DIR__ . '/inc/payments.php';
require __DIR__ . '/inc/mail.php';

$me = require_login('/marketplace');

$listing_id = (int) ($_POST['listing_id'] ?? $_GET['listing'] ?? 0);
$listing = $listing_id > 0 && db_ready()
    ? q1('SELECT l.*, u.name AS seller_name FROM listings l JOIN users u ON u.id = l.seller_id WHERE l.id = ?', [$listing_id])
    : null;

if (!$listing) {
    redirect('/marketplace');
}

$errors = [];
$rzp = null;
$sale = null;

$inspected = !empty($_POST['inspected']) && !empty($listing['allow_inspection']);

// An accepted offer is a price for THIS buyer on THIS listing. Read from the
// database, never from the request: a price that arrives in a form is a price
// anyone can edit. sale_create() resolves it again the same way, so what is
// shown here and what is charged cannot drift apart.
$agreed  = offer_agreed((int) $listing['id'], (int) $me['id']);
$price   = $agreed ? (int) $agreed['offer_paise'] : (int) $listing['price_paise'];
$postage = (int) $listing['postage_paise'];
$totals  = buyer_total($price, $postage, $inspected);

$saved = db_ready()
    ? q1('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1', [(int) $me['id']])
    : null;

$form = [
    'name'  => $_POST['name']  ?? ($saved['name']  ?? $me['name']),
    'phone' => $_POST['phone'] ?? ($saved['phone'] ?? ($me['phone'] ?? '')),
    'line1' => $_POST['line1'] ?? ($saved['line1'] ?? ''),
    'line2' => $_POST['line2'] ?? ($saved['line2'] ?? ''),
    'city'  => $_POST['city']  ?? ($saved['city']  ?? ''),
    'state' => $_POST['state'] ?? ($saved['state'] ?? ''),
    'pin'   => $_POST['pin']   ?? ($saved['pin']   ?? ''),
];

// Step one is the listing page's Buy button, which posts only listing_id and
// the inspection choice. Step two is this form.
$confirming = isset($_POST['confirm']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirming) {
    csrf_require();

    $form = array_map(static fn($v) => trim((string) $v), $form);

    foreach (['name', 'phone', 'line1', 'city', 'state', 'pin'] as $required) {
        if ($form[$required] === '') {
            $errors[] = 'Fill in every field except the second address line.';
            break;
        }
    }
    if (!$errors && !preg_match('/^(\+91)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $form['phone']) ?? '')) {
        $errors[] = 'That does not look like an Indian mobile number.';
    }
    if (!$errors && !preg_match('/^[1-9]\d{5}$/', $form['pin'])) {
        $errors[] = 'A PIN code is six digits and does not start with a zero.';
    }

    // Same reason as checkout: the database refuses an over-long value, and
    // the message it produces does not name a field.
    if (!$errors) {
        $errors = array_merge($errors, length_errors($form, [
            'name'  => ['listing_orders.ship_name',  'Your name'],
            'line1' => ['listing_orders.ship_line1', 'The first address line'],
            'line2' => ['listing_orders.ship_line2', 'The second address line'],
            'city'  => ['listing_orders.ship_city',  'The city'],
        ]));
    }

    if (!$errors) {
        $made = sale_create($listing, (int) $me['id'], $form, $inspected);

        if (isset($made['error'])) {
            $errors[] = $made['error'];
        } else {
            $sale = $made['sale'];

            if (!rzp_configured()) {
                redirect('/sale?ref=' . urlencode((string) $sale['reference']) . '&unpaid=1');
            }

            $created = rzp_create_order((int) $sale['total_paise'], (string) $sale['reference'],
                ['sale_reference' => $sale['reference'], 'listing' => (string) $listing['id']]);

            if (isset($created['error'])) {
                $errors[] = $created['error'] . ' The sale is saved as ' . $sale['reference'] . ' and nothing has been charged.';
            } else {
                exec_sql('UPDATE listing_orders SET payment_order_id = ? WHERE id = ?',
                    [$created['id'], (int) $sale['id']]);
                $rzp = $created;
            }
        }
    }
}

if ($rzp) {
    csp_allow_payments();
}

$page = [
    'title'   => 'Buy ' . $listing['title'],
    'nav'     => 'marketplace',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section" style="padding-top:28px;max-width:62em">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/marketplace">Marketplace</a><span>/</span>
      <a href="<?= e(listing_url($listing)) ?>"><?= e($listing['title']) ?></a><span>/</span>
      <span class="here">Buy</span>
    </nav>

    <h1 class="h-page">Buy it</h1>

    <?php if (rzp_test_mode()): ?>
      <p class="notice notice--paper" style="margin-top:18px">
        <span class="small"><strong>Test mode.</strong> No real money moves. Card <code>4111 1111 1111 1111</code>, any future expiry, any CVV.</span>
      </p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <p class="notice notice--paper" role="alert" style="margin-top:14px;color:var(--bad)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small"><?= e($err) ?></span>
      </p>
    <?php endforeach; ?>

    <?php if ($rzp): ?>
      <div class="card card--pad-lg" style="margin-top:24px;max-width:34em">
        <h2 class="h-card" style="margin-bottom:10px">Sale <?= e($sale['reference']) ?> is ready</h2>
        <p class="small muted">Opening the payment window&hellip; if nothing happens, use the button.</p>
        <button class="btn btn--primary btn--block" type="button" id="pay-now" style="margin-top:18px">
          Pay <?= money((int) $sale['total_paise']) ?>
        </button>
        <p class="tiny muted" id="pay-note" style="margin-top:14px">Your money is held by us, not sent to the seller, until you confirm it arrived.</p>
      </div>

      <script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>
      <script src="<?= asset('assets/js/checkout.js') ?>" defer
              data-key="<?= e((string) cfg('razorpay.key_id')) ?>"
              data-order="<?= e($rzp['id']) ?>"
              data-amount="<?= (int) $sale['total_paise'] ?>"
              data-name="Gelsoft Marketplace"
              data-description="<?= e($listing['title']) ?>"
              data-reference="<?= e($sale['reference']) ?>"
              data-token=""
              data-return="/sale"
              data-prefill-name="<?= e($sale['ship_name']) ?>"
              data-prefill-email="<?= e($me['email']) ?>"
              data-prefill-contact="<?= e($sale['ship_phone']) ?>"></script>

    <?php else: ?>
      <form class="layout layout--pay" method="post" action="/buy" style="margin-top:24px;align-items:flex-start">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= (int) $listing['id'] ?>">
        <input type="hidden" name="confirm" value="1">
        <?php if ($inspected): ?><input type="hidden" name="inspected" value="1"><?php endif; ?>

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
                <input class="input" id="phone" name="phone" type="tel" required maxlength="20" autocomplete="tel" value="<?= e($form['phone']) ?>">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="line1">Address <span class="req">*</span></label>
              <input class="input" id="line1" name="line1" maxlength="190" required autocomplete="address-line1" value="<?= e($form['line1']) ?>">
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
                <input class="input" id="state" name="state" maxlength="90" required autocomplete="address-level1" value="<?= e($form['state']) ?>">
              </div>
              <div class="field" style="flex:1 1 120px;margin:0">
                <label class="field__label" for="pin">PIN <span class="req">*</span></label>
                <input class="input" id="pin" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" required autocomplete="postal-code" value="<?= e($form['pin']) ?>">
              </div>
            </div>
          </fieldset>

          <div class="notice notice--olive">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="1.9" aria-hidden="true"><path d="M12 3l7.5 3.4v5c0 4.3-3 8.2-7.5 9.6-4.5-1.4-7.5-5.3-7.5-9.6v-5z" stroke-linejoin="round"/></svg>
            <p class="small">Your money is held by us, not passed to the seller, until you say it arrived. You have <?= SALE_CONFIRM_HOURS ?> hours from delivery to raise a problem &mdash; film the unboxing, uncut, because that is what we can act on.</p>
          </div>

          <div class="row row--tight row--center">
            <button class="btn btn--primary" type="submit">Continue to payment</button>
            <a class="btn" href="<?= e(listing_url($listing)) ?>">Back to the listing</a>
          </div>
        </div>

        <aside class="rail" style="max-width:320px" aria-label="What you pay">
          <div class="card card--pad">
            <h2 class="h-kicker" style="margin-bottom:14px">What you pay</h2>
            <div class="row row--between" style="gap:10px;font-size:13.5px;padding-bottom:10px;margin-bottom:10px;border-bottom:1px solid #EDEAE3">
              <span style="min-width:0"><?= e($listing['title']) ?></span>
              <span style="font-weight:600;white-space:nowrap"><?= money($price) ?></span>
            </div>
            <?php if ($agreed): ?>
              <!-- A figure that is lower than the listing without saying why
                   reads as a mistake, and a buyer who thinks it is a mistake
                   does not pay it. -->
              <p class="tiny" style="margin:-4px 0 10px;color:var(--olive-dark);line-height:1.55">
                Your accepted offer, instead of <?= money((int) $listing['price_paise']) ?>.
                It stands until <?= e(date('j M, H:i', strtotime((string) $agreed['offer_expires_at']))) ?>.
              </p>
            <?php endif; ?>
            <div class="row row--between" style="gap:10px;font-size:13.5px">
              <span class="muted">Courier</span>
              <span style="font-weight:600"><?= $postage > 0 ? money($postage) : 'Included' ?></span>
            </div>
            <?php if ($inspected): ?>
              <div class="row row--between" style="gap:10px;font-size:13.5px;margin-top:8px">
                <span class="muted">Inspection</span>
                <span style="font-weight:600"><?= money($totals['inspection']) ?></span>
              </div>
            <?php endif; ?>
            <div class="row row--between" style="gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
              <span style="font-family:var(--display);font-weight:700">Total</span>
              <span class="price"><?= money($totals['total']) ?></span>
            </div>

            <?php if ($inspected): ?>
              <p class="tiny" style="margin-top:14px;color:var(--olive-dark)">We check it before it reaches you. If it is not what the listing says, the sale is cancelled and you are charged nothing &mdash; the inspection fee included.</p>
            <?php elseif ($listing['allow_inspection']): ?>
              <p class="tiny muted" style="margin-top:14px">Want us to check it first? <a href="<?= e(listing_url($listing)) ?>" style="font-weight:600">Go back and tick the box</a> &mdash; it is <?= (int) cfg('marketplace.inspection_fee_pct', 8) ?>%.</p>
            <?php endif; ?>
          </div>

          <div class="card card--pad" style="margin-top:16px">
            <p class="tiny muted">Selling to you: <strong style="color:var(--ink)"><?= e($listing['seller_name']) ?></strong></p>
          </div>
        </aside>
      </form>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
