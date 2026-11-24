<?php
/**
 * POST /api/cart.php — add, change or remove a cart line.
 *
 * Answers JSON to a fetch and redirects back for a plain form post, so the
 * cart works with JavaScript blocked.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/cart.php';

$wants_json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

$done = function (bool $ok, string $message = '', int $status = 200) use ($wants_json): never {
    if ($wants_json) {
        http_response_code($ok ? $status : max(400, $status));
        header('Content-Type: application/json');
        $cart = cart_contents();
        exit(json_encode([
            'ok'      => $ok,
            'message' => $message,
            'count'   => $cart['count'],
            'subtotal' => money($cart['subtotal']),
            'total'   => money($cart['total']),
        ]));
    }

    $back = safe_path((string) ($_POST['return'] ?? '/cart'));
    if ($message !== '') {
        $_SESSION['flash'] = $message;
    }
    redirect($back, 303);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $done(false, 'Use POST.', 405);
}

csrf_require();

$product_id = (int) ($_POST['product_id'] ?? 0);
$action     = (string) ($_POST['do'] ?? 'add');

if ($product_id <= 0) {
    $done(false, 'No item given.', 422);
}

switch ($action) {
    case 'add':
        $problem = cart_add($product_id, (int) ($_POST['qty'] ?? 1));
        $done($problem === null, $problem ?? 'Added to your cart.', $problem === null ? 200 : 409);

    case 'set':
        cart_set($product_id, (int) ($_POST['qty'] ?? 1));
        $done(true, '');

    case 'remove':
        cart_remove($product_id);
        $done(true, 'Removed.');

    default:
        $done(false, 'Unknown action.', 422);
}
