<?php
/**
 * Orders: creating one, and marking it paid.
 *
 * The rule the whole file is built around: **the browser never decides that
 * an order is paid.** It can be closed, faked, or simply lose its connection
 * at the wrong moment. The webhook from the payment provider is what moves an
 * order to `paid`, and `order_mark_paid()` is the only function that does it.
 */
declare(strict_types=1);

/**
 * Create a pending order from the cart. Returns ['order' => row] or
 * ['error' => '…'].
 *
 * The totals are recalculated here from the database — not taken from the
 * cart page, not taken from the form.
 */
function order_create(array $ship, ?int $user_id, string $email, string $phone, string $note = '', string $provider = 'razorpay'): array
{
    // 'whatsapp' while Razorpay is not live (see payments_manual()); it is what
    // later lets an admin mark THIS order paid and no Razorpay one.
    $provider = in_array($provider, ['razorpay', 'whatsapp'], true) ? $provider : 'razorpay';

    if (!db_ready()) {
        return ['error' => 'The shop is not reachable just now.'];
    }

    $cart = cart_contents();
    if (!$cart['lines']) {
        return ['error' => 'Your cart is empty.'];
    }
    if ($cart['problems']) {
        // Something changed under them. Send them back to look rather than
        // charging a total they have not seen.
        return ['error' => 'Your cart changed. Check it and try again.', 'recheck' => true];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Re-read stock inside the transaction and lock those rows, so two
        // people buying the last one cannot both succeed.
        foreach ($cart['lines'] as $line) {
            $id = (int) $line['product']['id'];
            $row = q1('SELECT stock_qty, status FROM products WHERE id = ? FOR UPDATE', [$id]);
            if (!$row || $row['status'] !== 'published' || (int) $row['stock_qty'] < $line['qty']) {
                $pdo->rollBack();
                return ['error' => 'Something in your cart just sold out. Check it and try again.', 'recheck' => true];
            }
        }

        $reference = next_order_reference();

        exec_sql(
            'INSERT INTO orders
               (reference, user_id, email, phone, ship_name, ship_line1, ship_line2,
                ship_city, ship_state, ship_pin, note,
                subtotal_paise, shipping_paise, total_paise, status, payment_provider)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $reference, $user_id, $email, $phone,
                $ship['name'], $ship['line1'], $ship['line2'] ?: null,
                $ship['city'], $ship['state'], $ship['pin'], $note !== '' ? $note : null,
                $cart['subtotal'], $cart['shipping'], $cart['total'],
                'pending', $provider,
            ]
        );

        $order_id = (int) $pdo->lastInsertId();

        foreach ($cart['lines'] as $line) {
            $p = $line['product'];
            exec_sql(
                'INSERT INTO order_items
                   (order_id, product_id, name_at_purchase, slug_at_purchase, price_paise, qty)
                 VALUES (?,?,?,?,?,?)',
                [$order_id, (int) $p['id'], $p['name'], $p['slug'], (int) $p['price_paise'], $line['qty']]
            );
        }

        $pdo->commit();

        return ['order' => q1('SELECT * FROM orders WHERE id = ?', [$order_id])];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('order create failed: ' . $e->getMessage());
        return ['error' => 'The order could not be created. Nothing has been charged.'];
    }
}

/**
 * Mark an order paid. The only place that does.
 *
 * Idempotent on purpose: a webhook arriving twice, or a webhook racing the
 * browser's return, must not decrement stock twice or send two emails. An
 * order that is already paid returns false and nothing happens.
 *
 * $check_stock is for an order an admin marks paid by hand (paid over
 * WhatsApp). Those orders do not hold stock while they wait -- nothing does
 * until it is paid -- so two people can order the last one and both be
 * waiting. Razorpay's webhook cannot be refused (the money has moved), but a
 * person marking it paid can be told, and is, with $why saying which item ran
 * short. Checked with the product rows locked, inside the same transaction
 * that takes the stock off, so two admins cannot both take the last one.
 */
function order_mark_paid(int $order_id, string $payment_id, bool $check_stock = false, ?string &$why = null): bool
{
    $why = null;
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // FOR UPDATE, so two deliveries of the same event cannot both read
        // "pending" and both proceed.
        $order = q1('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order_id]);

        if (!$order || $order['status'] !== 'pending') {
            $pdo->commit();
            return false;
        }

        if ($check_stock) {
            foreach (q('SELECT product_id, qty, name_at_purchase FROM order_items WHERE order_id = ?', [$order_id]) as $item) {
                if ($item['product_id'] === null) {
                    continue;
                }
                $p = q1('SELECT stock_qty FROM products WHERE id = ? FOR UPDATE', [(int) $item['product_id']]);
                $have = $p ? (int) $p['stock_qty'] : 0;
                if ($have < (int) $item['qty']) {
                    $pdo->rollBack();
                    $why = $item['name_at_purchase'] . ': this order needs ' . (int) $item['qty']
                         . ' and only ' . $have . ' ' . ($have === 1 ? 'is' : 'are') . ' in stock.';
                    return false;
                }
            }
        }

        exec_sql(
            "UPDATE orders SET status = 'paid', payment_id = ?, paid_at = NOW() WHERE id = ?",
            [$payment_id, $order_id]
        );

        // Stock comes off when the money is real, not when the cart was
        // filled — otherwise an abandoned checkout holds stock nobody bought.
        foreach (q('SELECT product_id, qty FROM order_items WHERE order_id = ?', [$order_id]) as $item) {
            if ($item['product_id'] === null) {
                continue;
            }
            exec_sql(
                'UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?',
                [(int) $item['qty'], (int) $item['product_id']]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('order_mark_paid failed for ' . $order_id . ': ' . $e->getMessage());
        return false;
    }

    // Outside the transaction: a mail server being slow must not hold a
    // database lock, and a failed email must not roll back a real payment.
    try {
        send_order_confirmation($order_id);
    } catch (Throwable $e) {
        error_log('order confirmation email failed: ' . $e->getMessage());
    }

    return true;
}

/*
 * Which statuses are holding stock, and which have let go of it.
 *
 * Stock comes off at `paid`. It is still on the premises through `packed`.
 * Once it is `shipped` it is somebody else's parcel.
 */
const ORDER_STOCK_HELD = ['paid', 'packed'];
const ORDER_STOCK_SENT = ['shipped', 'delivered'];

/**
 * Close an order as cancelled or refunded, and put the stock back if the
 * stock is still here.
 *
 * Returns ['changed' => bool, 'restored' => int, 'dispatched' => bool]:
 *   changed     the status actually moved (false if it was already there)
 *   restored    how many units went back on the shelf
 *   dispatched  it had already shipped, so nothing was restored and somebody
 *               has to decide what happened to the parcel
 *
 * Idempotent, and it has to be: an admin double-clicks, and Razorpay sends
 * refund.created AND refund.processed for the same refund. The lock plus the
 * "already there" check is what makes a second call a no-op rather than a
 * second helping of stock.
 *
 * A `pending` order is closed without restoring anything, because it never
 * took anything -- that is the abandoned-checkout case, and restoring there
 * would create inventory that does not exist.
 */
function order_close(int $order_id, string $to): array
{
    $none = ['changed' => false, 'restored' => 0, 'dispatched' => false];

    if (!in_array($to, ['cancelled', 'refunded'], true)) {
        return $none;
    }

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }

    try {
        $order = q1('SELECT id, status FROM orders WHERE id = ? FOR UPDATE', [$order_id]);

        if (!$order || $order['status'] === $to) {
            if ($own) {
                $pdo->commit();
            }
            return $none;
        }

        $was       = (string) $order['status'];
        $holding   = in_array($was, ORDER_STOCK_HELD, true);
        $dispatched = in_array($was, ORDER_STOCK_SENT, true);

        exec_sql('UPDATE orders SET status = ? WHERE id = ?', [$to, $order_id]);

        $restored = 0;
        if ($holding) {
            foreach (q('SELECT product_id, qty FROM order_items WHERE order_id = ?', [$order_id]) as $item) {
                if ($item['product_id'] === null) {
                    continue;   // the product was deleted; there is no shelf to return it to
                }
                exec_sql(
                    'UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?',
                    [(int) $item['qty'], (int) $item['product_id']]
                );
                $restored += (int) $item['qty'];
            }
        }

        if ($own) {
            $pdo->commit();
        }

        return ['changed' => true, 'restored' => $restored, 'dispatched' => $dispatched];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('order_close failed for ' . $order_id . ': ' . $e->getMessage());
        return $none;
    }
}

function order_by_reference(string $reference): ?array
{
    return db_ready() ? q1('SELECT * FROM orders WHERE reference = ?', [$reference]) : null;
}

function order_by_payment_order(string $payment_order_id): ?array
{
    return db_ready() ? q1('SELECT * FROM orders WHERE payment_order_id = ?', [$payment_order_id]) : null;
}

function order_items(int $order_id): array
{
    return db_ready() ? q('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$order_id]) : [];
}

/**
 * Can this visitor see this order?
 *
 * Signed in and it is theirs, or they hold the one-time token we put on the
 * thank-you URL — which is how a guest checkout sees its own order without an
 * account, and how nobody else sees it by guessing a reference.
 */
function order_viewable(array $order, ?array $user): bool
{
    if ($user && (int) $order['user_id'] === (int) $user['id']) {
        return true;
    }
    if (user_is_staff($user)) {
        return true;
    }
    $token = (string) ($_GET['t'] ?? '');
    return $token !== '' && hash_equals(order_token($order), $token);
}

/**
 * A token derived from the order itself, so nothing extra has to be stored.
 *
 * Keyed on a server-side secret, so it cannot be computed by anyone holding
 * only the reference -- and the references are sequential, so that is the
 * whole point.
 *
 * NOT the Razorpay secret, which is what this used to use. That secret is
 * meant to change: switching from test keys to live ones is a step in
 * DEPLOY.md, and a leaked key has to be rotated. Either would have silently
 * invalidated the ?t= link in every receipt already sent, and the customer
 * would have found out by clicking it and getting a 404.
 *
 * app.secret exists for this and changes for no other reason. The database
 * password is the fallback so an inc/config.php written before app.secret
 * existed keeps working untouched -- rotating THAT breaks the links the same
 * way, which is worth knowing and is written down in DEPLOY.md.
 */
function order_token(array $order): string
{
    return substr(hash_hmac('sha256',
        'order:' . $order['id'] . ':' . $order['reference'] . ':' . $order['created_at'],
        (string) (cfg('app.secret') ?: cfg('db.pass'))), 0, 32);
}

function order_status_label(string $status): string
{
    return [
        'pending'   => 'Awaiting payment',
        'paid'      => 'Paid — being packed',
        'packed'    => 'Packed',
        'shipped'   => 'On its way',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refunded'  => 'Refunded',
    ][$status] ?? $status;
}
