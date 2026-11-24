<?php
/**
 * GET /api/order-status.php?ref=…&t=… — has the webhook landed yet?
 *
 * The thank-you page polls this for a few seconds after a payment, so it can
 * stop saying "confirming" the moment the money is confirmed. It answers with
 * a status and nothing else: no address, no totals, no email.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/cart.php';
require __DIR__ . '/../inc/payments.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/mail.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
$order = $reference !== '' ? order_by_reference($reference) : null;

if (!$order || !order_viewable($order, current_user())) {
    http_response_code(404);
    exit(json_encode(['ok' => false]));
}

exit(json_encode([
    'ok'       => true,
    'status'   => $order['status'],
    'label'    => order_status_label((string) $order['status']),
    'settled'  => $order['status'] !== 'pending',
]));
