<?php
/**
 * Razorpay, over plain HTTPS.
 *
 * No SDK and no Composer: the two calls this site makes are a POST to create
 * an order and an HMAC to check a signature. Pulling in a dependency tree to
 * do that would be more code to keep patched, not less.
 */
declare(strict_types=1);

const RZP_API = 'https://api.razorpay.com/v1';

function rzp_configured(): bool
{
    return (string) cfg('razorpay.key_id') !== '' && (string) cfg('razorpay.key_secret') !== '';
}

/** True while the keys are Razorpay's test ones. Shown on the checkout page. */
function rzp_test_mode(): bool
{
    return str_starts_with((string) cfg('razorpay.key_id'), 'rzp_test');
}

/**
 * Create the order at Razorpay's end. Returns ['id' => 'order_…'] or
 * ['error' => '…'].
 *
 * The amount comes from our own total, never from the browser.
 */
function rzp_create_order(int $amount_paise, string $receipt, array $notes = []): array
{
    if (!rzp_configured()) {
        return ['error' => 'Payments are not configured.'];
    }
    if ($amount_paise < 100) {
        return ['error' => 'That total is too small to charge.'];
    }

    $body = json_encode([
        'amount'   => $amount_paise,
        'currency' => 'INR',
        'receipt'  => mb_substr($receipt, 0, 40),
        'notes'    => $notes,
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init(RZP_API . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => cfg('razorpay.key_id') . ':' . cfg('razorpay.key_secret'),
        CURLOPT_TIMEOUT        => 20,
        // Both on, explicitly. Turning either off to "fix" a certificate
        // error is handing the connection to whoever is in the middle.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('razorpay create order failed: ' . $err);
        return ['error' => 'Could not reach the payment provider. Try again in a moment.'];
    }

    $data = json_decode((string) $raw, true);
    if ($code !== 200 || !isset($data['id'])) {
        error_log('razorpay create order rejected (' . $code . '): ' . $raw);
        return ['error' => 'The payment provider refused that order.'];
    }

    return ['id' => (string) $data['id'], 'amount' => (int) $data['amount']];
}

/**
 * The signature Razorpay Checkout hands back to the browser.
 *
 * Worth being clear about what this is for: it proves the browser is not
 * making the payment up. It does NOT replace the webhook — the browser can
 * close before it ever reaches us — so this only unlocks the thank-you page,
 * and the webhook is what marks an order paid.
 */
function rzp_check_handback(string $order_id, string $payment_id, string $signature): bool
{
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, (string) cfg('razorpay.key_secret'));
    return hash_equals($expected, $signature);
}

/**
 * The signature on a webhook request.
 *
 * Computed over the raw body, before any parsing: re-encoding the JSON would
 * change the bytes and the signature would never match.
 */
function rzp_check_webhook(string $raw_body, string $signature): bool
{
    $secret = (string) cfg('razorpay.webhook_secret');
    if ($secret === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $raw_body, $secret), $signature);
}

/**
 * A human order reference: GS-2026-0007.
 *
 * Sequential within the year, and separate from the row id, because the row
 * id tells anyone who looks how many orders you have ever taken.
 */
function next_order_reference(): string
{
    $year = date('Y');
    $row = q1("SELECT reference FROM orders WHERE reference LIKE ? ORDER BY id DESC LIMIT 1",
        ['GS-' . $year . '-%']);

    $n = 1;
    if ($row && preg_match('/-(\d+)$/', (string) $row['reference'], $m)) {
        $n = (int) $m[1] + 1;
    }
    return sprintf('GS-%s-%04d', $year, $n);
}

/* ------------------------------------------------- paying over WhatsApp ---
 *
 * The stop-gap until Razorpay is live. With no Razorpay keys, checkout places
 * the order as `pending` with payment_provider 'whatsapp' and says so; the
 * team messages the customer on WhatsApp with how to pay (and, for a blaster,
 * a video of that unit on the chrono), and marks it paid in admin once the
 * money is in the account.
 *
 * It switches itself off: the moment razorpay.key_id and key_secret are in
 * inc/config.php, checkout goes back to Razorpay and nothing else changes.
 * Orders placed the WhatsApp way keep their own route to "paid" -- an admin
 * can only ever mark a WhatsApp order paid, never a Razorpay one, whose
 * payment is Razorpay's word and nobody else's.
 */

function payments_manual(): bool
{
    return !rzp_configured();
}

/**
 * A number as wa.me wants it: country code and digits, no plus, no spaces.
 * A bare ten-digit Indian mobile gets its 91. Anything else that does not
 * look like a phone number comes back '' so no link is drawn to nowhere.
 */
function wa_digits(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}

/** The shop's own WhatsApp, from contact.whatsapp in inc/config.php, or ''. */
function shop_wa_digits(): string
{
    return wa_digits((string) cfg('contact.whatsapp'));
}

/** A click-to-chat link that opens WhatsApp with the message already typed. */
function wa_link(string $digits, string $text): string
{
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
}

/** Does this order have a blaster in it -- i.e. is there a chrono video to send? */
function order_has_blaster(int $order_id): bool
{
    return (bool) q1(
        "SELECT 1 FROM order_items i JOIN products p ON p.id = i.product_id
          WHERE i.order_id = ? AND p.category = 'blaster' LIMIT 1",
        [$order_id]
    );
}

/**
 * The message the team sends a customer to ask for payment, ready to go.
 *
 * Everything in it comes from the order as stored, so it cannot quote a
 * different total from the one the customer saw. payments.upi_id in
 * inc/config.php fills in where to pay; without it the line is left for the
 * team to finish by hand rather than inventing a payee.
 */
function wa_payment_request(array $order, array $items, bool $blaster): string
{
    $first = trim(strtok((string) $order['ship_name'], ' ') ?: (string) $order['ship_name']);
    $lines = [];
    foreach ($items as $i) {
        $lines[] = '- ' . $i['name_at_purchase'] . ' x' . (int) $i['qty'];
    }
    $upi = trim((string) cfg('payments.upi_id'));

    $msg = 'Hi ' . $first . ', thanks for your Gelsoft India order ' . $order['reference'] . ".\n\n"
         . implode("\n", $lines) . "\n"
         . 'Total: ' . money((int) $order['total_paise'])
         . ((int) $order['shipping_paise'] === 0 ? ' (free shipping)' : ' (shipping included)') . "\n\n";
    if ($blaster) {
        $msg .= "Here is a video of your blaster on the chrono, tested today.\n\n";
    }
    $msg .= $upi !== ''
        ? 'You can pay by UPI to ' . $upi . '. Please send the payment screenshot here and we will dispatch it.'
        : 'Payment details: ';
    return $msg;
}
