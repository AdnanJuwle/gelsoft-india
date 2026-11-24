<?php
/**
 * Orders, for whoever is packing them.
 *
 * `?id=` opens one. Status moves forward from here.
 *
 * Marking an order PAID is the payment provider's word, not an admin's --
 * with one exception: an order placed while payments run over WhatsApp
 * (payment_provider 'whatsapp', see payments_manual()). Nobody but the team
 * can know that money arrived, so the team marks it, with the UPI/bank
 * reference recorded and the stock checked. A Razorpay order can never be
 * marked paid here, whatever the form sends.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/cart.php';
require dirname(__DIR__) . '/inc/shop.php';
require dirname(__DIR__) . '/inc/payments.php';
require dirname(__DIR__) . '/inc/orders.php';
require dirname(__DIR__) . '/inc/mail.php';
require_admin();

// Deliberately excludes 'paid': an admin can move an order along once the
// money is in, but cannot declare that it is.
const ADMIN_STATUSES = ['packed', 'shipped', 'delivered', 'cancelled', 'refunded'];

$id     = (int) ($_GET['id'] ?? 0);
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready()) {
    csrf_require();
    $id = (int) ($_POST['id'] ?? 0);
    $order = q1('SELECT * FROM orders WHERE id = ?', [$id]);

    if (!$order) {
        $error = 'No such order.';
    } elseif (($_POST['do'] ?? '') === 'mark_paid') {
        $method = (string) ($_POST['method'] ?? '');
        $ref    = trim((string) ($_POST['pay_ref'] ?? ''));
        $how    = ['upi' => 'UPI', 'bank' => 'Bank transfer'][$method] ?? '';

        if (($order['payment_provider'] ?? '') !== 'whatsapp') {
            $error = 'This order is paid through Razorpay, so only Razorpay can mark it paid.';
        } elseif ($order['status'] !== 'pending') {
            $error = 'This order is not waiting for payment.';
        } elseif ($how === '') {
            $error = 'Pick how they paid.';
        } elseif (!preg_match('/^[A-Za-z0-9 \/_.-]{4,60}$/', $ref)) {
            $error = 'Enter the UPI transaction ID or bank reference from the payment (4 to 60 letters and digits), so the payment can be traced later.';
        } elseif (empty($_POST['confirm_money'])) {
            $error = 'Tick the box once you have checked the money is in the account. A screenshot alone is not enough.';
        } else {
            $why = null;
            if (order_mark_paid($id, mb_substr($how . ' ' . $ref, 0, 80), true, $why)) {
                admin_log('order_paid_manual', (string) $order['reference'], $how . ' ' . $ref);
                redirect('/admin/orders?id=' . $id . '&saved=paid');
            }
            $error = $why !== null
                ? 'Not marked paid. ' . $why . ' Restock it, or cancel this order and refund them.'
                : 'Not marked paid: it may have changed while you were looking. Reload and check.';
        }
    } else {
        $status   = (string) ($_POST['status'] ?? '');
        $tracking = mb_substr(trim((string) ($_POST['tracking'] ?? '')), 0, 120);
        $courier  = mb_substr(trim((string) ($_POST['courier'] ?? '')), 0, 60);
        $note     = mb_substr(trim((string) ($_POST['admin_note'] ?? '')), 0, 500);

        if ($status !== '' && !in_array($status, ADMIN_STATUSES, true)) {
            $error = 'That is not a status this screen can set.';
        } elseif ($order['status'] === 'pending' && $status !== '' && $status !== 'cancelled') {
            $error = 'That order has not been paid. The only thing you can do with it here is cancel it.';
        } else {
            $was = (string) $order['status'];

            /*
             * Cancelling and refunding go through order_close(), which also
             * puts the stock back. Everything else is the plain update.
             *
             * Split because the stock move has to happen in the same
             * transaction as the status change, and because it is the only
             * status change with a consequence outside the orders table.
             */
            $closing = in_array($status, ['cancelled', 'refunded'], true);
            $closed  = $closing ? order_close($id, $status) : null;

            // If the database refuses this, say WHAT it refused, here on the
            // page, instead of a blank 500 -- the live site hides PHP errors
            // from everyone, which left "Save crashes the site" undiagnosable.
            try {
                // The status column is only named when it is changing, so the
                // SQL never has to decide "empty means leave it" itself.
                $set_status = !$closing && $status !== '';
                exec_sql('UPDATE orders SET ' . ($set_status ? 'status = ?, ' : '')
                       . 'tracking = ?, courier = ?, admin_note = ? WHERE id = ?',
                    array_merge($set_status ? [$status] : [],
                        [blank_null($tracking), blank_null($courier), blank_null($note), $id]));
            } catch (Throwable $e) {
                error_log('order save failed for ' . $order['reference'] . ': ' . $e->getMessage());
                $error = 'Not saved. The database refused it: ' . $e->getMessage();
            }
        }

        if ($error === '' && isset($was)) {
            admin_log('order_update', (string) $order['reference'], $was . ' -> ' . ($status ?: $was));

            /*
             * What happened, carried through the redirect.
             *
             * A code rather than the sentence: ?saved= is a query parameter,
             * so whatever comes back has to be matched against a list here,
             * not printed.
             */
            $saved = '1';

            // Tell them it shipped, but only the first time it does.
            if ($status === 'shipped' && $was !== 'shipped') {
                send_dispatch_email($id);
                $saved = 'dispatched';
            } elseif ($closed && $closed['restored'] > 0) {
                $saved = 'restored-' . $closed['restored'];
            } elseif ($closed && $closed['dispatched']) {
                // Said rather than guessed: the parcel is out there, and
                // whether it comes back is not something this screen knows.
                $saved = 'gone';
            }

            redirect('/admin/orders?id=' . $id . '&saved=' . $saved);
        }
    }
}

$order = $id > 0 && db_ready() ? q1('SELECT * FROM orders WHERE id = ?', [$id]) : null;
$items = $order ? order_items((int) $order['id']) : [];

$filter = (string) ($_GET['status'] ?? '');
$filter = in_array($filter, ['pending', 'paid', 'packed', 'shipped', 'delivered', 'cancelled', 'refunded'], true)
    ? $filter : '';

$list = [];
$counts = ['pending' => 0, 'paid' => 0, 'shipped' => 0];
if (db_ready()) {
    $list = $filter !== ''
        ? q('SELECT * FROM orders WHERE status = ? ORDER BY created_at DESC LIMIT 100', [$filter])
        : q('SELECT * FROM orders ORDER BY created_at DESC LIMIT 100');

    foreach (q("SELECT status, COUNT(*) n FROM orders GROUP BY status") as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
}

$page = ['title' => $order ? 'Order ' . $order['reference'] : 'Orders'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">

    <?php if ($order): ?>
      <div class="admin-head">
        <div>
          <h1>Order <?= e($order['reference']) ?></h1>
          <p>
            <?= e(date('j M Y, H:i', strtotime((string) $order['created_at']))) ?> &middot;
            <?= e($order['email']) ?> &middot; <?= e($order['phone']) ?>
            <?= $order['user_id'] ? '' : ' &middot; guest checkout' ?>
          </p>
        </div>
        <a class="btn btn--sm" href="/admin/orders">All orders</a>
      </div>

      <?php if ($error): ?>
        <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($error) ?></span></p>
      <?php elseif (isset($_GET['saved'])): ?>
        <?php
        $code = (string) $_GET['saved'];
        if ($code === 'paid') {
            $msg = 'Marked paid. Stock has come off the shelf, and it is ready to pack.';
        } elseif ($code === 'dispatched') {
            $msg = 'Saved, and the dispatch email has gone out.';
        } elseif (preg_match('/^restored-(\d+)$/', $code, $m)) {
            // Capped at what this order actually holds. The number arrives in
            // the query string, so on its own it will happily say "999999
            // back in stock" to whoever types that -- which is only an admin
            // lying to themselves, but a message that states a fact should
            // not be able to state a false one.
            $could = array_sum(array_map(static fn($i) => (int) $i['qty'], $items));
            $msg = 'Saved. ' . min((int) $m[1], $could) . ' back in stock.';
        } elseif ($code === 'gone') {
            $msg = 'Saved. It had already shipped, so nothing was added back to stock — '
                 . 'put it back by hand if the parcel comes home.';
        } else {
            $msg = 'Saved.';
        }
        ?>
        <p class="notice notice--olive" role="status"><span class="small"><?= e($msg) ?></span></p>
      <?php endif; ?>

      <div class="row row--top" style="gap:24px;align-items:flex-start">
        <div class="col" style="flex:999 1 420px;min-width:0">
          <?php if ($order['status'] === 'pending' && ($order['payment_provider'] ?? '') === 'whatsapp'): ?>
            <?php
            $cust_wa = wa_digits((string) $order['phone']);
            $blaster = order_has_blaster((int) $order['id']);
            ?>
            <!-- Waiting on WhatsApp: message them, then mark it paid. -->
            <div class="card card--pad-lg" style="margin-bottom:20px;border-color:var(--olive-line)">
              <h2 class="h-card" style="margin-bottom:6px">Waiting for payment on WhatsApp</h2>
              <p class="small muted" style="margin-bottom:14px">
                1. Message them<?= $blaster ? ' and send the chrono video of their blaster' : '' ?>.
                2. When the money is in the account, mark it paid below. Stock only comes off then.
              </p>
              <?php if ($cust_wa !== ''): ?>
                <a class="btn btn--olive btn--sm" target="_blank" rel="noopener"
                   href="<?= e(wa_link($cust_wa, wa_payment_request($order, $items, $blaster))) ?>">WhatsApp <?= e($order['phone']) ?></a>
                <p class="tiny muted" style="margin-top:8px">Opens WhatsApp with the order, the total<?= trim((string) cfg('payments.upi_id')) !== '' ? ' and your UPI ID' : '' ?> already typed. Read it before you send it<?= $blaster ? ', and attach the video' : '' ?>.</p>
              <?php else: ?>
                <p class="small">The number they gave, <?= e($order['phone']) ?>, does not look like a mobile WhatsApp can open. Call them instead.</p>
              <?php endif; ?>

              <form method="post" action="/admin/orders" style="margin-top:20px;padding-top:18px;border-top:1px solid var(--line)">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="do" value="mark_paid">
                <div class="row row--tight">
                  <div class="field" style="flex:1 1 150px;margin:0">
                    <label class="field__label" for="method">Paid by</label>
                    <select class="select" id="method" name="method" required>
                      <option value="">Pick one</option>
                      <option value="upi">UPI</option>
                      <option value="bank">Bank transfer</option>
                    </select>
                  </div>
                  <div class="field" style="flex:2 1 220px;margin:0">
                    <label class="field__label" for="pay_ref">UPI transaction ID or bank reference</label>
                    <input class="input" id="pay_ref" name="pay_ref" required maxlength="60" placeholder="412345678901">
                  </div>
                </div>
                <label class="check" style="margin-top:14px;display:flex;gap:10px;align-items:flex-start">
                  <input type="checkbox" name="confirm_money" value="1" required>
                  <span class="small">I have checked <strong><?= money((int) $order['total_paise']) ?></strong> is in our account, not just seen a screenshot.</span>
                </label>
                <button class="btn btn--primary btn--sm" type="submit" style="margin-top:14px">Mark as paid</button>
              </form>
            </div>
          <?php endif; ?>

          <div class="table-wrap">
            <table class="table">
              <caption class="sr-only">Items</caption>
              <thead><tr><th scope="col">Item</th><th scope="col">Qty</th><th scope="col">Each</th><th scope="col">Line</th></tr></thead>
              <tbody>
                <?php foreach ($items as $i): ?>
                  <tr>
                    <td><?= e($i['name_at_purchase']) ?></td>
                    <td><?= (int) $i['qty'] ?></td>
                    <td><?= money((int) $i['price_paise']) ?></td>
                    <td><?= money((int) $i['price_paise'] * (int) $i['qty']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr><td colspan="3" style="text-align:right">Shipping</td><td><?= money((int) $order['shipping_paise']) ?></td></tr>
                <tr><td colspan="3" style="text-align:right"><strong>Total</strong></td><td><strong><?= money((int) $order['total_paise']) ?></strong></td></tr>
              </tbody>
            </table>
          </div>

          <form class="card card--pad-lg" method="post" action="/admin/orders" style="margin-top:20px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

            <h2 class="h-card" style="margin-bottom:16px">Move it along</h2>

            <div class="row row--tight">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="status">Status</label>
                <select class="select" id="status" name="status">
                  <option value="">Leave as <?= e(order_status_label((string) $order['status'])) ?></option>
                  <?php foreach (ADMIN_STATUSES as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(order_status_label($s)) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($order['status'] === 'pending'): ?>
                  <p class="field__hint"><?= ($order['payment_provider'] ?? '') === 'whatsapp'
                      ? 'Unpaid. Mark it paid above once the money is in.'
                      : 'Unpaid. Only the payment provider can mark it paid.' ?></p>
                <?php endif; ?>
              </div>
              <div class="field" style="flex:1 1 150px;margin:0">
                <label class="field__label" for="courier">Courier</label>
                <input class="input" id="courier" name="courier" maxlength="60" value="<?= e($order['courier']) ?>" placeholder="Delhivery">
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="tracking">Tracking number</label>
                <input class="input" id="tracking" name="tracking" maxlength="120" value="<?= e($order['tracking']) ?>">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="admin_note">Private note</label>
              <input class="input" id="admin_note" name="admin_note" maxlength="500" value="<?= e($order['admin_note']) ?>"
                     placeholder="Only you see this.">
            </div>

            <div class="row row--tight row--center" style="margin-top:18px">
              <button class="btn btn--primary btn--sm" type="submit">Save</button>
              <span class="tiny muted">Setting it to <strong>On its way</strong> emails the tracking number, once.</span>
            </div>
          </form>
        </div>

        <aside class="rail" style="max-width:300px">
          <div class="card card--pad">
            <h2 class="h-kicker" style="margin-bottom:12px">Ship to</h2>
            <p class="small" style="line-height:1.7">
              <?= e($order['ship_name']) ?><br>
              <?= e($order['ship_line1']) ?><br>
              <?php if ($order['ship_line2']): ?><?= e($order['ship_line2']) ?><br><?php endif; ?>
              <?= e($order['ship_city']) ?>, <?= e($order['ship_state']) ?> <?= e($order['ship_pin']) ?><br>
              <?= e($order['phone']) ?>
            </p>
            <?php if ($order['note']): ?>
              <p class="tiny" style="margin-top:12px;padding-top:12px;border-top:1px solid #EDEAE3"><strong>They said:</strong> <?= e($order['note']) ?></p>
            <?php endif; ?>
          </div>

          <div class="card card--pad" style="margin-top:16px">
            <h2 class="h-kicker" style="margin-bottom:12px">Payment</h2>
            <p class="tiny muted" style="line-height:1.8">
              Status: <strong style="color:var(--ink)"><?= e(order_status_label((string) $order['status'])) ?></strong><br>
              <?php if ($order['paid_at']): ?>Paid <?= e(date('j M Y, H:i', strtotime((string) $order['paid_at']))) ?><br><?php endif; ?>
              <?php if ($order['payment_order_id']): ?>Order: <code><?= e($order['payment_order_id']) ?></code><br><?php endif; ?>
              <?php if ($order['payment_id']): ?>Payment: <code><?= e($order['payment_id']) ?></code><?php endif; ?>
            </p>
          </div>
        </aside>
      </div>

    <?php else: ?>
      <div class="admin-head">
        <div>
          <h1>Orders</h1>
          <p>Newest first. <strong><?= (int) ($counts['paid'] ?? 0) ?></strong> paid and waiting to be packed.
            <?php $on_wa = db_ready() ? (int) (q1("SELECT COUNT(*) n FROM orders WHERE status = 'pending' AND payment_provider = 'whatsapp'")['n'] ?? 0) : 0; ?>
            <?php if ($on_wa > 0): ?><strong><?= (int) $on_wa ?></strong> waiting for payment on WhatsApp.<?php endif; ?></p>
        </div>
      </div>

      <div class="toolbar">
        <a class="btn btn--xs<?= $filter === '' ? ' btn--dark' : '' ?>" href="/admin/orders">All</a>
        <?php foreach (['paid' => 'To pack', 'shipped' => 'Shipped', 'pending' => 'Unpaid',
                        'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $s => $label): ?>
          <a class="btn btn--xs<?= $filter === $s ? ' btn--dark' : '' ?>" href="/admin/orders?status=<?= e($s) ?>">
            <?= e($label) ?><?= isset($counts[$s]) && $counts[$s] ? ' (' . (int) $counts[$s] . ')' : '' ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Orders</caption>
          <thead>
            <tr><th scope="col">Reference</th><th scope="col">When</th><th scope="col">Who</th>
                <th scope="col">Total</th><th scope="col">Status</th><th scope="col"></th></tr>
          </thead>
          <tbody>
            <?php if (!$list): ?>
              <tr><td colspan="6" style="padding:44px 24px;text-align:center">
                <p class="small muted" style="margin:0">No orders<?= $filter ? ' with that status' : ' yet' ?>.</p>
              </td></tr>
            <?php else: foreach ($list as $o): ?>
              <tr>
                <td><a href="/admin/orders?id=<?= (int) $o['id'] ?>"><?= e($o['reference']) ?></a></td>
                <td class="tiny muted"><?= e(date('j M, H:i', strtotime((string) $o['created_at']))) ?></td>
                <td><?= e($o['ship_name']) ?><br><span class="tiny muted"><?= e($o['ship_city']) ?></span></td>
                <td><?= money((int) $o['total_paise']) ?></td>
                <td><span class="<?= in_array($o['status'], ['paid','packed','shipped','delivered'], true) ? 'tag-ok' : 'tag-warn' ?>"><?= e(order_status_label((string) $o['status'])) ?></span>
                  <?php if ($o['status'] === 'pending' && ($o['payment_provider'] ?? '') === 'whatsapp'): ?><br><span class="tiny muted">on WhatsApp</span><?php endif; ?></td>
                <td><a class="btn btn--xs" href="/admin/orders?id=<?= (int) $o['id'] ?>">Open</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
