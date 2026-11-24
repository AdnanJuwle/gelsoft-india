<?php
/**
 * POST /api/webhook.php — Razorpay tells us a payment happened.
 *
 * **This is the only thing on the site that marks an order paid.** The
 * browser's return from the payment screen cannot be trusted: it can be
 * closed, it can be replayed, and on a flaky connection it simply never
 * arrives. This request comes from Razorpay's servers to ours and is signed
 * with a secret only the two of us know.
 *
 * Set it up: Razorpay dashboard → Settings → Webhooks → add
 * https://gelsoft.in/api/webhook.php, subscribe to `payment.captured` and
 * `payment.failed`, and paste the secret into inc/config.php.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/cart.php';
require __DIR__ . '/../inc/payments.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/mail.php';
require __DIR__ . '/../inc/listings.php';
require __DIR__ . '/../inc/listing_sales.php';

// Razorpay retries anything that is not a 2xx, so the answer here is always
// short and the work is always done before replying.
$reply = static function (int $code, string $text): never {
    http_response_code($code);
    header('Content-Type: text/plain');
    exit($text);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, 'POST only');
}

// The raw bytes, before any parsing. The signature is over exactly these —
// decode and re-encode and it will never match again.
$raw = file_get_contents('php://input') ?: '';
$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if ($raw === '' || $signature === '') {
    error_log('webhook: missing body or signature');
    $reply(400, 'bad request');
}

if (!rzp_check_webhook($raw, $signature)) {
    // Either someone is poking the endpoint, or the secret in config.php does
    // not match the one in the dashboard. Both are worth a log line.
    error_log('webhook: signature did not verify');
    $reply(401, 'bad signature');
}

try {
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    $reply(400, 'bad json');
}

$event = (string) ($data['event'] ?? '');
$payment = $data['payload']['payment']['entity'] ?? [];
$rzp_order_id = (string) ($payment['order_id'] ?? '');
$payment_id   = (string) ($payment['id'] ?? '');

// Razorpay's own id for this delivery. Falling back to a hash of the body
// keeps the de-duplication working even if the header is ever missing.
$event_id = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
if ($event_id === '') {
    $event_id = 'sha:' . hash('sha256', $raw);
}

if (!db_ready()) {
    // Tell Razorpay it failed so they send it again when we are back.
    error_log('webhook: database unavailable, asking for a retry');
    $reply(503, 'try again');
}

// A Razorpay order id belongs to either a shop order or a marketplace sale.
$order = $rzp_order_id !== '' ? order_by_payment_order($rzp_order_id) : null;
$sale  = ($order === null && $rzp_order_id !== '') ? sale_by_payment_order($rzp_order_id) : null;

// Record the event first. The unique key on event_id is what makes this whole
// endpoint idempotent: Razorpay delivers at least once, and sometimes that
// means twice. The second insert fails, and we stop right there rather than
// charging stock twice or sending a second confirmation.
try {
    exec_sql('INSERT INTO payment_events (event_id, event, order_id, payload) VALUES (?,?,?,?)',
        [$event_id, mb_substr($event, 0, 60), $order['id'] ?? null, mb_substr($raw, 0, 60000)]);
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000) {
        $reply(200, 'already seen');
    }
    error_log('webhook: could not record event: ' . $e->getMessage());
    $reply(500, 'retry');
}

// ---- a marketplace sale -------------------------------------------------
if ($sale) {
    switch ($event) {
        case 'payment.captured':
            $paid = (int) ($payment['amount'] ?? 0);
            if ($paid < (int) $sale['total_paise']) {
                error_log(sprintf('webhook: underpaid sale %s — %d of %d',
                    $sale['reference'], $paid, $sale['total_paise']));
                exec_sql('UPDATE listing_orders SET admin_note = ? WHERE id = ?',
                    ['Underpaid: ' . $paid . ' of ' . $sale['total_paise'], (int) $sale['id']]);
                break;
            }
            sale_mark_held((int) $sale['id'], $payment_id);
            break;

        case 'payment.failed':
            error_log('webhook: payment failed for sale ' . $sale['reference']);
            break;
    }

    exec_sql('UPDATE payment_events SET handled_at = NOW() WHERE event_id = ?', [$event_id]);
    $reply(200, 'ok');
}

if (!$order) {
    // Not ours, or the row never got its payment_order_id. Accept it so
    // Razorpay stops retrying, and leave the event row for someone to read.
    error_log('webhook: nothing matches ' . $rzp_order_id);
    $reply(200, 'no matching order');
}

switch ($event) {
    case 'payment.captured':
        // The amount is checked against our own total. A payment for less
        // than the order is not that order being paid.
        $paid = (int) ($payment['amount'] ?? 0);
        if ($paid < (int) $order['total_paise']) {
            error_log(sprintf('webhook: underpaid order %s — %d of %d',
                $order['reference'], $paid, $order['total_paise']));
            exec_sql("UPDATE orders SET admin_note = ? WHERE id = ?",
                ['Underpaid: received ' . $paid . ' paise of ' . $order['total_paise'], (int) $order['id']]);
            break;
        }

        order_mark_paid((int) $order['id'], $payment_id);
        break;

    case 'payment.failed':
        // Left pending on purpose, not cancelled: people retry, and the order
        // they retry is this one.
        error_log('webhook: payment failed for ' . $order['reference']);
        break;

    case 'refund.created':
    case 'refund.processed':
        // Both events arrive for the same refund, so this runs twice.
        // order_close() locks the row and does nothing the second time --
        // without that, the stock would go back on the shelf twice.
        order_close((int) $order['id'], 'refunded');
        break;
}

exec_sql('UPDATE payment_events SET handled_at = NOW() WHERE event_id = ?', [$event_id]);

$reply(200, 'ok');
