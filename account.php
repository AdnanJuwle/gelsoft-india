<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/messages.php';
require __DIR__ . '/inc/cart.php';
require __DIR__ . '/inc/payments.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/listings.php';
require __DIR__ . '/inc/seller_checks.php';
require __DIR__ . '/inc/listing_sales.php';

// Somebody else's orders and addresses are not a page anyone can just open.
$me = require_login('/account');
$unread = unread_count((int) $me['id']);

$notice = '';
$errors = [];
$error_action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $me['id'];

    // Which form these errors belong to. The page is four forms in tabbed
    // panels sharing one $errors array, and without this every panel prints
    // every message -- including panels the person has not touched.
    $error_action = $action;

    /*
     * Trimmed, NOT truncated.
     *
     * This used to be mb_substr(..., 0, $max), which saved the row and said
     * nothing -- so a delivery address one character too long was quietly cut
     * and the courier got the shortened version. The row was always valid;
     * the person was never told. length_errors() below says which field and
     * by how much, and nothing is saved until it fits.
     */
    $text = static fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

    if ($action === 'address') {
        $addr = [
            'name'  => $text('name'),  'phone' => $text('phone'),
            'line1' => $text('line1'), 'line2' => $text('line2'),
            'city'  => $text('city'),  'state' => $text('state'),
            'pin'   => $text('pin'),
        ];

        $errors = array_merge($errors, length_errors($addr, [
            'name'  => ['addresses.name',  'The name'],
            'line1' => ['addresses.line1', 'The first address line'],
            'line2' => ['addresses.line2', 'The second address line'],
            'city'  => ['addresses.city',  'The city'],
        ]));

        foreach (['name', 'phone', 'line1', 'city', 'state', 'pin'] as $need) {
            if ($addr[$need] === '') {
                $errors[] = 'Fill in every field except the second line.';
                break;
            }
        }
        if (!$errors && !preg_match('/^[1-9]\d{5}$/', $addr['pin'])) {
            $errors[] = 'A PIN code is six digits and does not start with a zero.';
        }
        if (!$errors && !preg_match('/^(\+91)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $addr['phone']) ?? '')) {
            $errors[] = 'That does not look like an Indian mobile number.';
        }

        if (!$errors) {
            $make_default = !empty($_POST['is_default']);
            $id = (int) ($_POST['address_id'] ?? 0);

            try {
                if ($make_default) {
                    // Exactly one default, so clear the rest first.
                    exec_sql('UPDATE addresses SET is_default = 0 WHERE user_id = ?', [$uid]);
                }

                if ($id > 0) {
                    // The user_id in the WHERE is what stops someone editing
                    // an address that is not theirs by changing the id.
                    exec_sql('UPDATE addresses SET name=?, phone=?, line1=?, line2=?, city=?, state=?, pin=?, is_default=?
                              WHERE id = ? AND user_id = ?',
                        [...array_values($addr), $make_default ? 1 : 0, $id, $uid]);
                } else {
                    exec_sql('INSERT INTO addresses (user_id, name, phone, line1, line2, city, state, pin, is_default)
                              VALUES (?,?,?,?,?,?,?,?,?)',
                        [$uid, ...array_values($addr), $make_default ? 1 : 0]);
                }
                redirect('/account?saved=address#addresses');
            } catch (Throwable $e) {
                error_log('address save failed: ' . $e->getMessage());
                $errors[] = 'That could not be saved.';
            }
        }
    }

    if ($action === 'address_delete') {
        exec_sql('DELETE FROM addresses WHERE id = ? AND user_id = ?',
            [(int) ($_POST['address_id'] ?? 0), $uid]);
        redirect('/account?saved=address-removed#addresses');
    }

    if ($action === 'details') {
        $name  = $text('name');
        $email = mb_strtolower($text('email'));
        $phone = $text('phone');

        $errors = array_merge($errors, length_errors(
            ['name' => $name, 'email' => $email],
            ['name' => ['users.name', 'Your name'], 'email' => ['users.email', 'That email address']]
        ));

        if ($errors) {
            // fall through to the page, which prints them
        } elseif ($name === '') {
            $errors[] = 'Tell us what to call you.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'That does not look like an email address.';
        } elseif ($phone !== '' && !preg_match('/^(\+91)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $phone) ?? '')) {
            $errors[] = 'That does not look like an Indian mobile number.';
        } else {
            try {
                exec_sql('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?',
                    [$name, $email, $phone !== '' ? $phone : null, $uid]);
                redirect('/account?saved=details#details');
            } catch (PDOException $e) {
                $errors[] = (int) $e->getCode() === 23000
                    ? 'There is already an account on that email address.'
                    : 'That could not be saved.';
            }
        }
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new     = (string) ($_POST['new'] ?? '');

        $row = q1('SELECT password_hash FROM users WHERE id = ?', [$uid]);

        if (!$row || !password_verify($current, (string) $row['password_hash'])) {
            $errors[] = 'That is not your current password.';
        } elseif ($problem = password_problem($new)) {
            $errors[] = $problem;
        } else {
            exec_sql('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]), $uid]);
            // A new session id, so anyone holding the old one is signed out.
            session_regenerate_id(true);
            redirect('/account?saved=password#details');
        }
    }

    if ($action === 'payout') {
        $upi  = $text('upi_id');
        $acct = $text('account_name');

        $errors = array_merge($errors, length_errors(
            ['upi_id' => $upi, 'account_name' => $acct],
            ['upi_id' => ['payout_details.upi_id', 'That UPI id'],
             'account_name' => ['payout_details.account_name', 'The account name']]
        ));

        if ($errors) {
            // fall through to the page, which prints them
        } elseif ($upi !== '' && !preg_match('/^[\w.\-]{2,}@[A-Za-z]{2,}$/', $upi)) {
            $errors[] = 'A UPI id looks like name@bank.';
        } else {
            exec_sql('INSERT INTO payout_details (user_id, upi_id, account_name) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE upi_id = VALUES(upi_id), account_name = VALUES(account_name)',
                [$uid, $upi !== '' ? $upi : null, $acct ?: null]);
            redirect('/account?saved=payout#listings');
        }
    }
}

$addresses = db_ready()
    ? q('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id', [(int) $me['id']])
    : [];

$payout_details = db_ready()
    ? q1('SELECT * FROM payout_details WHERE user_id = ?', [(int) $me['id']])
    : null;

$my_alerts = db_ready()
    ? q('SELECT a.*, p.name AS product_name, p.slug, p.category, p.stock_qty
         FROM restock_alerts a LEFT JOIN products p ON p.id = a.product_id
         WHERE a.contact = ? OR a.contact = ?
         ORDER BY a.created_at DESC',
        [(string) $me['email'], (string) ($me['phone'] ?? '-')])
    : [];

$my_listings = db_ready()
    ? q('SELECT l.*, (SELECT i.path FROM listing_images i WHERE i.listing_id = l.id
                       ORDER BY i.sort_order, i.id LIMIT 1) AS image
         FROM listings l WHERE l.seller_id = ? ORDER BY l.updated_at DESC', [(int) $me['id']])
    : [];

$my_sales = db_ready()
    ? q('SELECT lo.*, l.title,
                CASE WHEN lo.buyer_id = ? THEN 1 ELSE 0 END AS i_am_buyer
         FROM listing_orders lo JOIN listings l ON l.id = lo.listing_id
         WHERE lo.buyer_id = ? OR lo.seller_id = ?
         ORDER BY lo.created_at DESC', [(int) $me['id'], (int) $me['id'], (int) $me['id']])
    : [];

$my_orders = db_ready()
    ? q('SELECT o.*, (SELECT COALESCE(SUM(qty),0) FROM order_items i WHERE i.order_id = o.id) AS items
         FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 25', [(int) $me['id']])
    : [];

$page = [
    'title'   => 'Your account',
    'desc'    => 'Orders, addresses, restock alerts, your marketplace listings and offers, all in one place.',
    'noindex' => true,
];
require __DIR__ . '/inc/header.php';
?>

  <!-- who -->
  <section class="band">
    <div class="wrap row row--between row--center" style="padding:40px 24px">
      <div class="row row--center" style="gap:16px;flex:1 1 380px;min-width:0;flex-wrap:nowrap">
        <span class="avatar" style="width:52px;height:52px;font-size:17px">PR</span>
        <div>
          <h1 style="font-size:28px;font-weight:800;letter-spacing:-0.015em;color:#fff">Your account</h1>
          <p class="small on-dark" style="margin-top:6px"><?= e($me['name']) ?> &middot; <?= e($me['email']) ?><?= $me['seller_verified_at'] ? ' &middot; verified seller' : '' ?></p>
        </div>
      </div>
      <div class="row row--tight">
        <a class="btn btn--ghost-dark btn--sm" href="/sell">List something</a>
        <button class="btn btn--light btn--sm" type="button">Sign out</button>
      </div>
    </div>
  </section>

  <div class="wrap section" data-tabs="account">

    <div class="tabs scroller" role="tablist" aria-label="Account sections" style="margin-bottom:28px">
      <button role="tab" type="button" data-tab="orders" aria-selected="true">Orders</button>
      <button role="tab" type="button" data-tab="alerts" aria-selected="false">Restock alerts</button>
      <button role="tab" type="button" data-tab="listings" aria-selected="false">My listings</button>
      <button role="tab" type="button" data-tab="offers" aria-selected="false">Offers &amp; messages</button>
      <button role="tab" type="button" data-tab="addresses" aria-selected="false">Addresses</button>
      <button role="tab" type="button" data-tab="details" aria-selected="false">Details</button>
    </div>

    <!-- orders -->
    <section data-panel="orders" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="Orders">
      <div class="row row--between row--end" style="margin-bottom:20px">
        <div>
          <h2 class="h-sect">Orders</h2>
          <p class="small muted" style="margin-top:8px">Dispatch, tracking and what was in each parcel.</p>
        </div>
        <a class="small" style="font-weight:600" href="/policy">Replacement policy &rarr;</a>
      </div>

      <?php if ($my_orders): ?>
        <div class="table-wrap">
          <?php foreach ($my_orders as $o): ?>
            <div class="cart-line">
              <div class="cart-line__body">
                <a class="cart-line__name" href="/order?ref=<?= e(urlencode((string) $o['reference'])) ?>"><?= e($o['reference']) ?></a>
                <span class="tiny muted">
                  <?= e(date('j M Y', strtotime((string) $o['created_at']))) ?> &middot;
                  <?= (int) $o['items'] ?> item<?= (int) $o['items'] === 1 ? '' : 's' ?>
                  <?php if ($o['tracking']): ?> &middot; <?= e($o['courier'] ?: 'Courier') ?> <?= e($o['tracking']) ?><?php endif; ?>
                </span>
              </div>
              <span class="<?= in_array($o['status'], ['paid','packed','shipped','delivered'], true) ? 'tag-ok' : 'tag-warn' ?>">
                <?= e(order_status_label((string) $o['status'])) ?>
              </span>
              <span class="cart-line__total price"><?= money((int) $o['total_paise']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 8h16l-1.5 11.5H5.5z" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" stroke-linecap="round"/></svg>
          </span>
          <h3>No orders yet</h3>
          <p>When you order, this is where the tracking link lands &mdash; and where you start a replacement if the parcel turns up damaged.</p>
          <div class="row row--tight" style="justify-content:center">
            <a class="btn btn--primary btn--sm" href="/blasters">Shop blasters</a>
            <a class="btn btn--sm" href="/gear">Shop gear</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="notice notice--paper" style="margin-top:20px">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.6v.1" stroke-linecap="round"/></svg>
        <p class="small">Film the unboxing. One uncut take, parcel sealed at the start &mdash; it is the only way we can replace something the courier broke.</p>
      </div>
    </section>

    <!-- restock alerts -->
    <section data-panel="alerts" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="Restock alerts" hidden>
      <div style="margin-bottom:20px">
        <h2 class="h-sect">Restock alerts</h2>
        <p class="small muted" style="margin-top:8px">One message when a thing you asked about is back. Nothing else, ever.</p>
      </div>

      <?php if ($my_alerts): ?>
        <div class="table-wrap">
          <?php foreach ($my_alerts as $a): ?>
            <div class="cart-line">
              <div class="cart-line__body">
                <?php if ($a['product_name']): ?>
                  <a class="cart-line__name" href="<?= e(shop_path((string) $a['category']) . '/' . rawurlencode((string) $a['slug'])) ?>"><?= e($a['product_name']) ?></a>
                <?php elseif (str_starts_with((string) $a['category'], 'game:')): ?>
                  <span class="cart-line__name">Games near <?= e(substr((string) $a['category'], 5)) ?></span>
                <?php else: ?>
                  <span class="cart-line__name">Anything new<?= $a['category'] ? ' in ' . e((string) $a['category']) : '' ?></span>
                <?php endif; ?>
                <span class="tiny muted">Asked <?= e(date('j M Y', strtotime((string) $a['created_at']))) ?></span>
              </div>
              <span class="<?= $a['notified_at'] ? 'tag-ok' : 'tag-warn' ?>">
                <?= $a['notified_at'] ? 'Told you on ' . e(date('j M', strtotime((string) $a['notified_at']))) : 'Waiting' ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z" stroke-linejoin="round"/><path d="M10 20a2 2 0 0 0 4 0" stroke-linecap="round"/></svg>
          </span>
          <h3>No alerts set</h3>
          <p>Anything out of stock has an &ldquo;alert me&rdquo; box on it. We message you once when it lands and that is the end of it.</p>
          <a class="btn btn--sm" href="/blasters">Browse blasters</a>
        </div>
      <?php endif; ?>
    </section>

    <section data-panel="listings" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="My listings" hidden>
      <div class="row row--between row--end" style="margin-bottom:20px">
        <div>
          <h2 class="h-sect">My listings</h2>
          <p class="small muted" style="margin-top:8px">Everything you have up for sale, plus what has sold.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/sell">List something</a>
      </div>

      <?php
      $live_n = $sold_n = $paid_out = 0;
      foreach ($my_listings as $l) {
          if ($l['status'] === 'live') { $live_n++; }
          if ($l['status'] === 'sold') { $sold_n++; }
      }
      foreach ($my_sales as $s) {
          if (!$s['i_am_buyer'] && $s['status'] === 'released') { $paid_out += (int) $s['payout_paise']; }
      }
      ?>
      <dl class="grid grid--auto-s" style="margin-bottom:20px">
        <div class="stat"><dt>Live listings</dt><dd><?= $live_n ?></dd><p class="note"><?= $live_n ? 'Up right now' : 'Nothing up right now' ?></p></div>
        <div class="stat"><dt>Sold</dt><dd><?= $sold_n ?></dd><p class="note">All time</p></div>
        <div class="stat"><dt>Paid out</dt><dd><?= money($paid_out) ?></dd><p class="note">To your UPI</p></div>
      </dl>

      <?php if ($my_listings): ?>
        <div class="table-wrap">
          <?php foreach ($my_listings as $l): ?>
            <div class="cart-line">
              <span class="cart-line__media">
                <?php if ($l['image']): ?><img src="<?= e(url($l['image'])) ?>" alt="">
                <?php else: ?><span class="ph ph--thumb"><span class="tiny">No photo</span></span><?php endif; ?>
              </span>
              <div class="cart-line__body">
                <a class="cart-line__name" href="<?= e(listing_url($l)) ?>"><?= e($l['title']) ?></a>
                <span class="tiny muted">
                  <?= money((int) $l['price_paise']) ?> &middot; <?= (int) $l['views'] ?> view<?= (int) $l['views'] === 1 ? '' : 's' ?>
                  <?php if ($l['status'] === 'rejected' && $l['reject_note']): ?>
                    &middot; <span class="tag-warn"><?= e($l['reject_note']) ?></span>
                  <?php endif; ?>
                </span>
              </div>
              <span class="<?= $l['status'] === 'live' ? 'tag-ok' : 'tag-warn' ?>"><?= e(listing_status_label((string) $l['status'])) ?></span>
              <span class="cart-line__remove">
                <?php if ($l['status'] !== 'sold'): ?>
                  <a href="/sell?edit=<?= (int) $l['id'] ?>">Edit</a>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 9h16l-1.2 10H5.2z" stroke-linejoin="round"/><path d="M7 9l2-4h6l2 4" stroke-linejoin="round"/></svg>
          </span>
          <h3>Nothing listed</h3>
          <p>Free to list, and nothing to pay unless it sells. Most people here sell one or two things they have outgrown.</p>
          <a class="btn btn--primary btn--sm" href="/sell">List one thing</a>
        </div>
      <?php endif; ?>

      <?php if ($my_sales): ?>
        <h3 class="h-card" style="margin:28px 0 14px">Sales and purchases</h3>
        <div class="table-wrap">
          <?php foreach ($my_sales as $s): ?>
            <div class="cart-line">
              <div class="cart-line__body">
                <a class="cart-line__name" href="/sale?ref=<?= e(urlencode((string) $s['reference'])) ?>"><?= e($s['title']) ?></a>
                <span class="tiny muted">
                  <?= e($s['reference']) ?> &middot; you <?= $s['i_am_buyer'] ? 'bought' : 'sold' ?> this
                  &middot; <?= e(date('j M Y', strtotime((string) $s['created_at']))) ?>
                </span>
              </div>
              <span class="<?= in_array($s['status'], ['released','delivered','shipped','held'], true) ? 'tag-ok' : 'tag-warn' ?>">
                <?= e(sale_status_label((string) $s['status'])) ?>
              </span>
              <span class="cart-line__total price">
                <?= money((int) ($s['i_am_buyer'] ? $s['total_paise'] : $s['payout_paise'])) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- offers -->
    <section data-panel="offers" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="Offers and messages" hidden>
      <div style="margin-bottom:20px">
        <h2 class="h-sect">Offers &amp; messages</h2>
        <p class="small muted" style="margin-top:8px">Offers on your listings, questions from buyers, and your own offers out.</p>
      </div>

      <div class="empty">
        <span class="empty__icon" aria-hidden="true">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 6h16v10H9l-5 4z" stroke-linejoin="round"/></svg>
        </span>
        <h3>Your conversations live here</h3>
        <p>Keep it in here rather than in DMs: a conversation on the listing is what we can look at if a sale goes wrong. Every listing has a <strong>Message the seller</strong> button that starts one.</p>
        <div class="row row--tight" style="justify-content:center">
          <a class="btn btn--dark btn--sm" href="/messages">Open messages</a>
          <a class="btn btn--sm" href="/marketplace">Browse listings</a>
        </div>
      </div>
    </section>

    <!-- addresses -->
    <section data-panel="addresses" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="Addresses" hidden id="addresses">
      <div style="margin-bottom:20px">
        <h2 class="h-sect">Addresses</h2>
        <p class="small muted" style="margin-top:8px">Where parcels go. Add a second one if your game kit lives somewhere else.</p>
      </div>

      <?php
      // ?edit=<id> loads one of your own into the form beside the list.
      $edit = ['id' => 0, 'name' => $me['name'], 'phone' => $me['phone'] ?? '',
               'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'pin' => '', 'is_default' => 1];
      if (isset($_GET['edit_address'])) {
          foreach ($addresses as $a) {
              if ((int) $a['id'] === (int) $_GET['edit_address']) { $edit = $a; }
          }
      }
      ?>

      <div class="row row--top">
        <div style="flex:1 1 300px;min-width:0;display:flex;flex-direction:column;gap:14px">
          <?php if (!$addresses): ?>
            <div class="card card--pad">
              <p class="small muted" style="margin:0">No addresses saved. The first one you add becomes the default.</p>
            </div>
          <?php else: foreach ($addresses as $a): ?>
            <div class="card card--pad">
              <div class="row row--between" style="align-items:baseline">
                <?php if ($a['is_default']): ?>
                  <span class="chip chip--pill chip--ok">Default</span>
                <?php else: ?><span class="tiny muted">Saved</span><?php endif; ?>
                <span class="row row--tight" style="gap:10px">
                  <a class="btn--link" href="/account?edit_address=<?= (int) $a['id'] ?>#addresses">Edit</a>
                  <form method="post" action="/account" style="display:inline"
                        data-confirm="Remove this address?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="address_delete">
                    <input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                    <button class="btn--link" type="submit" style="color:var(--muted)">Remove</button>
                  </form>
                </span>
              </div>
              <p style="margin-top:14px;line-height:1.7">
                <strong><?= e($a['name']) ?></strong><br>
                <?= e($a['line1']) ?><br>
                <?php if ($a['line2']): ?><?= e($a['line2']) ?><br><?php endif; ?>
                <?= e($a['city']) ?>, <?= e($a['state']) ?> <?= e($a['pin']) ?><br>
                <span class="muted"><?= e($a['phone']) ?></span>
              </p>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <form class="card card--pad" method="post" action="/account#addresses" style="flex:999 1 420px;min-width:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="address">
          <input type="hidden" name="address_id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <h3 class="h-kicker" style="margin-bottom:16px"><?= ($edit['id'] ?? 0) ? 'Edit this address' : 'Add an address' ?></h3>

          <?php if (($_GET['saved'] ?? '') === 'address'): ?>
            <p class="notice notice--olive" role="status" style="margin-bottom:14px"><span class="small">Saved.</span></p>
          <?php elseif (($_GET['saved'] ?? '') === 'address-removed'): ?>
            <p class="notice notice--olive" role="status" style="margin-bottom:14px"><span class="small">Removed.</span></p>
          <?php endif; ?>

          <?php foreach ($error_action === 'address' ? $errors : [] as $err): ?>
            <p class="notice notice--paper" role="alert" style="margin-bottom:12px;color:var(--bad)"><span class="small"><?= e($err) ?></span></p>
          <?php endforeach; ?>

          <div class="row row--tight">
            <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
              <label class="field__label" for="an">Full name <span class="req">*</span></label>
              <input class="input" id="an" name="name" maxlength="120" required autocomplete="name" value="<?= e($edit['name']) ?>">
            </div>
            <div class="field" style="flex:1 1 200px;min-width:0;margin:0">
              <label class="field__label" for="ap">Phone <span class="req">*</span></label>
              <input class="input" id="ap" name="phone" type="tel" maxlength="20" required autocomplete="tel" value="<?= e($edit['phone']) ?>" placeholder="98765 43210">
            </div>
          </div>
          <div class="field">
            <label class="field__label" for="a1">Address <span class="req">*</span></label>
            <input class="input" id="a1" name="line1" maxlength="190" required autocomplete="address-line1" value="<?= e($edit['line1']) ?>" placeholder="Flat, building, street">
          </div>
          <div class="field">
            <label class="field__label" for="a2">Area or landmark <span class="opt">&mdash; optional</span></label>
            <input class="input" id="a2" name="line2" maxlength="190" autocomplete="address-line2" value="<?= e($edit['line2']) ?>">
          </div>
          <div class="row row--tight" style="margin-top:18px">
            <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
              <label class="field__label" for="ac">City <span class="req">*</span></label>
              <input class="input" id="ac" name="city" maxlength="90" required autocomplete="address-level2" value="<?= e($edit['city']) ?>">
            </div>
            <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
              <label class="field__label" for="as">State <span class="req">*</span></label>
              <select class="select" id="as" name="state" required autocomplete="address-level1">
                <option value="">Select your state</option>
                <?php foreach (['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Delhi',
                                'Goa','Gujarat','Haryana','Himachal Pradesh','Jammu and Kashmir','Jharkhand',
                                'Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya',
                                'Mizoram','Nagaland','Odisha','Puducherry','Punjab','Rajasthan','Sikkim',
                                'Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal',
                                'Andaman and Nicobar Islands','Chandigarh','Ladakh','Lakshadweep'] as $st): ?>
                  <option value="<?= e($st) ?>"<?= ($edit['state'] ?? '') === $st ? ' selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="flex:0 1 140px;min-width:0;margin:0">
              <label class="field__label" for="az">PIN code <span class="req">*</span></label>
              <input class="input" id="az" name="pin" inputmode="numeric" pattern="[1-9][0-9]{5}" maxlength="6" required autocomplete="postal-code" value="<?= e($edit['pin']) ?>">
            </div>
          </div>
          <label class="checkline checkline--top" style="margin-top:18px">
            <input type="checkbox" name="is_default" value="1"<?= ($edit['is_default'] ?? 1) ? ' checked' : '' ?>>
            Use this as my default address
          </label>
          <div class="row row--tight" style="margin-top:18px">
            <button class="btn btn--dark btn--sm" type="submit">Save address</button>
            <?php if ($edit['id'] ?? 0): ?>
              <a class="btn btn--sm" href="/account#addresses">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <!-- details -->
    <section data-panel="details" data-panel-group="account" role="tabpanel" class="tabpanel" aria-label="Details" hidden id="details">
      <div style="margin-bottom:20px">
        <h2 class="h-sect">Details</h2>
        <p class="small muted" style="margin-top:8px">How we reach you, and how you sign in.</p>
      </div>

      <?php if (in_array($_GET['saved'] ?? '', ['details', 'password', 'payout'], true)): ?>
        <p class="notice notice--olive" role="status" style="margin-bottom:18px">
          <span class="small"><?= e([
            'details'  => 'Saved.',
            'password' => 'Password changed. Anything else signed in as you has been signed out.',
            'payout'   => 'Payout details saved.',
          ][$_GET['saved']]) ?></span>
        </p>
      <?php endif; ?>

      <?php /* everything that is not an address, so nothing is lost */ ?>
      <?php foreach ($error_action === 'address' ? [] : $errors as $err): ?>
        <p class="notice notice--paper" role="alert" style="margin-bottom:12px;color:var(--bad)"><span class="small"><?= e($err) ?></span></p>
      <?php endforeach; ?>

      <div class="row row--top">
        <form class="card card--pad" method="post" action="/account#details" style="flex:999 1 420px;min-width:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="details">

          <h3 class="h-kicker" style="margin-bottom:16px">Your details</h3>
          <div class="field" style="margin:0">
            <label class="field__label" for="dn">Name</label>
            <input class="input" id="dn" name="name" maxlength="120" required autocomplete="name" value="<?= e($me['name']) ?>">
          </div>
          <div class="field">
            <label class="field__label" for="de">Email</label>
            <input class="input" id="de" name="email" type="email" maxlength="190" required autocomplete="email" value="<?= e($me['email']) ?>">
            <p class="field__hint">This is also how you sign in.</p>
          </div>
          <div class="field">
            <label class="field__label" for="dw">Phone <span class="opt">optional</span></label>
            <input class="input" id="dw" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= e($me['phone'] ?? '') ?>" placeholder="98765 43210">
            <p class="field__hint">Used for dispatch messages and restock alerts. Nothing else.</p>
          </div>
          <button class="btn btn--dark btn--sm" type="submit" style="margin-top:18px">Save changes</button>
        </form>

        <div style="flex:1 1 320px;min-width:0;display:flex;flex-direction:column;gap:20px">
          <form class="card card--pad" method="post" action="/account#details">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h3 class="h-kicker" style="margin-bottom:14px">Change your password</h3>
            <div class="field" style="margin:0">
              <label class="field__label" for="pw-current">Current password</label>
              <input class="input" id="pw-current" name="current" type="password" required autocomplete="current-password">
            </div>
            <div class="field">
              <label class="field__label" for="pw-new">New password</label>
              <input class="input" id="pw-new" name="new" type="password" required minlength="10" autocomplete="new-password">
              <p class="field__hint">At least 10 characters. Three unrelated words beat <em>P@ssw0rd</em>.</p>
            </div>
            <button class="btn btn--sm" type="submit" style="margin-top:14px">Change it</button>
          </form>

          <form class="card card--pad" method="post" action="/account#details">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="payout">
            <h3 class="h-kicker" style="margin-bottom:12px">Where your payouts go</h3>
            <p class="small muted" style="margin-bottom:14px">Only needed if you sell. We pay out after the buyer confirms delivery.</p>
            <div class="field" style="margin:0">
              <label class="field__label" for="upi">UPI id</label>
              <input class="input" id="upi" name="upi_id" maxlength="120" value="<?= e($payout_details['upi_id'] ?? '') ?>" placeholder="yourname@bank">
            </div>
            <div class="field">
              <label class="field__label" for="acname">Name on the account</label>
              <input class="input" id="acname" name="account_name" maxlength="120" value="<?= e($payout_details['account_name'] ?? '') ?>">
            </div>
            <button class="btn btn--sm" type="submit" style="margin-top:14px">Save</button>
          </form>

          <div class="card card--pad<?= $me['seller_verified_at'] ? ' card--olive' : '' ?>">
            <h3 class="h-kicker" style="margin-bottom:12px">Seller verification</h3>
            <?php if ($me['seller_verified_at']): ?>
              <p class="small" style="color:var(--olive-dark)">Verified on <?= e(date('j M Y', strtotime((string) $me['seller_verified_at']))) ?>. This is what lets a buyer trust a first listing.</p>
            <?php else: ?>
              <p class="small muted">Your listings cannot go live until this is done. It takes one call and one rupee.</p>
              <?php $have = seller_checks((int) $me['id']); ?>
              <ul class="list list--rule small" style="margin-top:12px">
                <?php foreach (SELLER_CHECKS as $kind => $meta): if ($kind === 'sale') continue; ?>
                  <li style="display:block">
                    <strong><?= isset($have[$kind]) ? '&#10003; ' : '' ?><?= e($meta['label']) ?></strong>
                    <span class="tiny muted" style="display:block;margin-top:2px">
                      <?= $kind === 'phone'
                            ? 'We call or WhatsApp the number on your account, so make sure it is right above.'
                            : 'Put your UPI id and the exact name on that account in Payout, above. We send ₹1 and check the name your bank reports matches.' ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <p class="tiny muted" style="margin-top:12px">
              We never ask for Aadhaar, PAN or any ID, and we do not store one. Your bank has already
              done that work &mdash; we only check the name it reports against the name you gave.
            </p>
          </div>
        </div>
      </div>
    </section>
  </div>
<?php require __DIR__ . '/inc/footer.php';
