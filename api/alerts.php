<?php
/**
 * POST /api/alerts.php — "tell me when this is back in stock".
 *
 * Answers JSON to a fetch, and redirects back to the page for a plain form
 * post, so the same endpoint serves both.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

$wants_json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

/** Finish: JSON for a fetch, a redirect for a form. */
$done = function (bool $ok, string $message, int $status = 200) use ($wants_json): never {
    if ($wants_json) {
        http_response_code($ok ? $status : max(400, $status));
        header('Content-Type: application/json');
        exit(json_encode(['ok' => $ok, 'message' => $message]));
    }

    // Only ever go back to a path on this site — never to whatever the form
    // was told to return to, which anyone can edit.
    $back = (string) ($_POST['return'] ?? '/');
    $back = '/' . ltrim(parse_url($back, PHP_URL_PATH) ?: '/', '/');
    redirect($back . ($ok ? '?alerted=1' : '?alert_error=1'), 303);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $done(false, 'Use POST.', 405);
}

csrf_require();

$contact = trim((string) ($_POST['contact'] ?? ''));
if ($contact === '' || mb_strlen($contact) > 190) {
    $done(false, 'Leave an email address or a phone number.', 422);
}

// Either an email or an Indian mobile number. Anything else is a typo, and
// telling someone now beats a message that never arrives.
$is_email = (bool) filter_var($contact, FILTER_VALIDATE_EMAIL);
$digits   = preg_replace('/\D+/', '', $contact);
$is_phone = $digits !== null && strlen($digits) >= 10 && strlen($digits) <= 13;

if (!$is_email && !$is_phone) {
    $done(false, 'That does not look like an email address or a phone number.', 422);
}
if ($is_phone) {
    $contact = '+' . ltrim(strlen($digits) === 10 ? '91' . $digits : $digits, '+');
}

$product_id = ($_POST['product_id'] ?? '') !== '' ? (int) $_POST['product_id'] : null;
$category   = (string) ($_POST['category'] ?? '');
$category   = in_array($category, ['blaster', 'gear', 'part', 'ammo'], true) ? $category : null;

if (!db_ready()) {
    error_log('restock alert dropped, database unavailable: ' . $contact);
    $done(false, 'We could not save that just now. Try again in a minute.', 503);
}

try {
    // Asking twice should not mean being messaged twice.
    $existing = q1(
        'SELECT id FROM restock_alerts
         WHERE contact = ? AND notified_at IS NULL
           AND (product_id <=> ?) AND (category <=> ?)
         LIMIT 1',
        [$contact, $product_id, $category]
    );

    if (!$existing) {
        exec_sql(
            'INSERT INTO restock_alerts (product_id, category, contact) VALUES (?, ?, ?)',
            [$product_id, $category, $contact]
        );
    }
} catch (Throwable $e) {
    error_log('restock alert failed: ' . $e->getMessage());
    $done(false, 'We could not save that just now. Try again in a minute.', 500);
}

$done(true, 'You are on the list. One message when it lands, nothing else.');
