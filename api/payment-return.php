<?php
/**
 * POST /api/payment-return.php — the browser reporting back from Razorpay.
 *
 * Deliberately almost powerless. It verifies the signature the widget handed
 * over, and if that checks out it records the payment id against the order so
 * support can match them up. It does **not** mark the order paid: that is the
 * webhook's job, because this request comes from a browser and a browser can
 * be told to say anything.
 *
 * The one thing it is good for is speed — the webhook usually lands within a
 * second or two, but the customer is already looking at the thank-you page.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/cart.php';
require __DIR__ . '/../inc/payments.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/mail.php';
require __DIR__ . '/../inc/listings.php';
require __DIR__ . '/../inc/listing_sales.php';

header('Content-Type: application/json');

$out = static function (bool $ok, string $message = '', int $code = 200): never {
    http_response_code($code);
    exit(json_encode(['ok' => $ok, 'message' => $message]));
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $out(false, 'POST only', 405);
}

$rzp_order   = (string) ($_POST['razorpay_order_id'] ?? '');
$payment_id  = (string) ($_POST['razorpay_payment_id'] ?? '');
$signature   = (string) ($_POST['razorpay_signature'] ?? '');

if ($rzp_order === '' || $payment_id === '' || $signature === '') {
    $out(false, 'Missing payment details.', 422);
}

if (!rzp_check_handback($rzp_order, $payment_id, $signature)) {
    error_log('payment return: signature did not verify for ' . $rzp_order);
    $out(false, 'That payment could not be verified.', 400);
}

$order = order_by_payment_order($rzp_order);
$sale  = $order === null ? sale_by_payment_order($rzp_order) : null;

if (!$order && !$sale) {
    $out(false, 'No such order.', 404);
}

if ($sale) {
    // Same rule as the shop: record the id, let the webhook decide the money.
    if (empty($sale['payment_id'])) {
        exec_sql('UPDATE listing_orders SET payment_id = ? WHERE id = ? AND payment_id IS NULL',
            [$payment_id, (int) $sale['id']]);
    }
    $out(true, 'Payment received. Confirming it now.');
}

// Record the payment id only. The money is not confirmed until Razorpay tells
// our server directly, and the order page reads the real status either way.
if (empty($order['payment_id'])) {
    exec_sql('UPDATE orders SET payment_id = ? WHERE id = ? AND payment_id IS NULL',
        [$payment_id, (int) $order['id']]);
}

// The cart has done its job. Emptying it here rather than on the thank-you
// page means a refresh of that page cannot re-empty a cart they have since
// started filling again.
cart_clear();

$out(true, 'Payment received. Confirming it now.');
