<?php
/**
 * Transactional email over SMTP.
 *
 * Hostinger gives you a mailbox on the domain; mail from it passes SPF and
 * DKIM, which is most of what keeps an order confirmation out of the spam
 * folder. PHP's mail() sends as the web user from a shared IP and frequently
 * does not arrive at all, so this speaks SMTP directly.
 *
 * It is deliberately small: connect, authenticate, send, log. No attachments,
 * no HTML-with-fallback juggling — a plain text receipt is what people want
 * from a shop anyway, and it renders everywhere.
 */
declare(strict_types=1);

/**
 * Send one message. Returns true on success; never throws at the caller,
 * because an email that will not send must not take an order down with it.
 */
function send_mail(string $to, string $subject, string $body, string $template = ''): bool
{
    $ok = false;
    $error = '';

    try {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('not an email address');
        }
        // A newline in either of these would let someone append their own
        // headers — a Bcc, a different From — so they are stripped, not escaped.
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $to = trim(str_replace(["\r", "\n"], '', $to));

        if ((string) cfg('mail.pass') === '') {
            throw new RuntimeException('no mailbox password configured');
        }

        $ok = smtp_send($to, $subject, $body);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('mail to ' . $to . ' failed: ' . $error);
    }

    try {
        if (db_ready()) {
            exec_sql('INSERT INTO mail_log (recipient, subject, template, ok, error) VALUES (?,?,?,?,?)',
                [mb_substr($to, 0, 190), mb_substr($subject, 0, 190), $template ?: null,
                 $ok ? 1 : 0, $error !== '' ? mb_substr($error, 0, 255) : null]);
        }
    } catch (Throwable) {
        // Logging the log failing helps nobody.
    }

    return $ok;
}

/** The SMTP conversation. Throws on anything unexpected. */
function smtp_send(string $to, string $subject, string $body): bool
{
    $host   = (string) cfg('mail.host', 'smtp.hostinger.com');
    $port   = (int) cfg('mail.port', 465);
    $secure = (string) cfg('mail.secure', 'ssl');
    $user   = (string) cfg('mail.user');
    $pass   = (string) cfg('mail.pass');
    $from   = $user;
    $name   = (string) cfg('mail.from_name', 'Gelsoft India');

    // Port 465 is TLS from the first byte; 587 starts plain and upgrades.
    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $context = stream_context_create(['ssl' => [
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'allow_self_signed' => false,
    ]]);

    $fp = @stream_socket_client($target, $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $context);

    if (!$fp) {
        throw new RuntimeException("cannot reach {$host}:{$port} ({$errstr})");
    }
    stream_set_timeout($fp, 15);

    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            // The last line of a reply has a space after the code, not a dash.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };

    $say = static function (string $cmd, string $expect) use ($fp, $read): string {
        if ($cmd !== '') {
            fwrite($fp, $cmd . "\r\n");
        }
        $reply = $read();
        if (!str_starts_with($reply, $expect)) {
            throw new RuntimeException('SMTP said: ' . trim($reply) . ' (after ' .
                (str_starts_with($cmd, 'AUTH') || $cmd !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $cmd)
                    ? '[credentials]' : ($cmd ?: 'connect')) . ')');
        }
        return $reply;
    };

    try {
        $say('', '220');
        $ehlo = $say('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'gelsoft.in'), '250');

        if ($secure === 'tls') {
            $say('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('could not start TLS');
            }
            $ehlo = $say('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'gelsoft.in'), '250');
        }

        $say('AUTH LOGIN', '334');
        $say(base64_encode($user), '334');
        $say(base64_encode($pass), '235');

        $say('MAIL FROM:<' . $from . '>', '250');
        $say('RCPT TO:<' . $to . '>', '250');
        $say('DATA', '354');

        $headers = [
            'From: ' . smtp_encode_name($name) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . smtp_encode_header($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($host) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];

        // A line that is just a dot ends the message, so any real one has to
        // be escaped with a second dot.
        $text = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));
        $text = str_replace("\n", "\r\n", (string) $text);

        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $text . "\r\n.\r\n");
        $say('', '250');
        $say('QUIT', '221');
    } finally {
        fclose($fp);
    }

    return true;
}

/** RFC 2047, so a non-ASCII subject survives the trip. */
function smtp_encode_header(string $text): string
{
    return preg_match('/[\x80-\xFF]/', $text)
        ? '=?UTF-8?B?' . base64_encode($text) . '?='
        : $text;
}

function smtp_encode_name(string $name): string
{
    return preg_match('/[\x80-\xFF]/', $name)
        ? smtp_encode_header($name)
        : '"' . str_replace('"', '', $name) . '"';
}

/* ---------------------------------------------------------- templates --- */

function send_order_confirmation(int $order_id): bool
{
    $order = q1('SELECT * FROM orders WHERE id = ?', [$order_id]);
    if (!$order) {
        return false;
    }

    $items = order_items($order_id);
    $site  = rtrim((string) cfg('site_url'), '/');
    $lines = [];

    foreach ($items as $i) {
        $lines[] = sprintf('  %-40s x%d   %s',
            mb_substr((string) $i['name_at_purchase'], 0, 40),
            (int) $i['qty'],
            money((int) $i['price_paise'] * (int) $i['qty'], false));
    }

    $lines_joined = implode("\n", $lines);
    $subtotal = money((int) $order['subtotal_paise'], false);
    $shipping = (int) $order['shipping_paise'] === 0 ? 'free' : money((int) $order['shipping_paise'], false);
    $total    = money((int) $order['total_paise'], false);
    $address  = trim($order['ship_line1'] . ($order['ship_line2'] ? "\n  " . $order['ship_line2'] : ''));
    $token    = order_token($order);

    $body = <<<TEXT
Thanks — your order is in.

Order {$order['reference']}

{$lines_joined}

  Subtotal                                  {$subtotal}
  Shipping                                  {$shipping}
  Total paid                                {$total}

Going to:
  {$order['ship_name']}
  {$address}
  {$order['ship_city']}, {$order['ship_state']} {$order['ship_pin']}
  {$order['phone']}

We pack in plain, unmarked boxes. In-stock items leave within 24 hours on a
working day; you will get another email with the tracking number when it does.

Track it here:
{$site}/order?ref={$order['reference']}&t={$token}

A reminder, because it matters more than the parcel: full-seal eye protection
goes on before anyone loads, and a blaster travels concealed and stays off
public ground.

Something wrong with the order? Reply to this email.

Gelsoft India
{$site}
TEXT;

    return send_mail((string) $order['email'], 'Your order ' . $order['reference'], $body, 'order_confirmation');
}

/**
 * Tell the shop a WhatsApp-route order has come in and somebody needs to
 * message the customer. Goes to contact.email.
 *
 * Only useful once mail.pass is set; until then send_mail() logs the failure
 * and returns false, and the order is still there in admin -- the customer's
 * "send this order on WhatsApp" button is the other way the team finds out.
 */
function send_new_order_alert(int $order_id): bool
{
    $order = q1('SELECT * FROM orders WHERE id = ?', [$order_id]);
    $to    = (string) cfg('contact.email');
    if (!$order || $to === '') {
        return false;
    }

    $lines = [];
    foreach (order_items($order_id) as $i) {
        $lines[] = '  ' . $i['name_at_purchase'] . ' x' . (int) $i['qty'];
    }
    $lines_joined = implode("\n", $lines);
    $total = money((int) $order['total_paise'], false);
    $site  = rtrim((string) cfg('site_url'), '/');

    $body = <<<TEXT
New order {$order['reference']}, waiting for payment over WhatsApp.

{$lines_joined}

  Total {$total}

{$order['ship_name']}, {$order['ship_city']}
WhatsApp: {$order['phone']}

Open it, message them, and mark it paid once the money is in:
{$site}/admin/orders?id={$order['id']}
TEXT;

    return send_mail($to, 'New order ' . $order['reference'] . ' - message the customer', $body, 'new_order_alert');
}

function send_dispatch_email(int $order_id): bool
{
    $order = q1('SELECT * FROM orders WHERE id = ?', [$order_id]);
    if (!$order) {
        return false;
    }

    $site = rtrim((string) cfg('site_url'), '/');
    $token = order_token($order);
    $tracking = $order['tracking']
        ? "Courier: {$order['courier']}\nTracking: {$order['tracking']}"
        : 'It is with the courier now.';

    $body = <<<TEXT
Your order {$order['reference']} is on its way.

{$tracking}

Going to:
  {$order['ship_name']}
  {$order['ship_city']}, {$order['ship_state']} {$order['ship_pin']}

When it arrives: film the unboxing, uncut, before you open the inner packaging.
That video is the only thing we can act on if something is damaged in transit —
it is in the policy for that reason, not as a formality.

{$site}/order?ref={$order['reference']}&t={$token}

Gelsoft India
TEXT;

    return send_mail((string) $order['email'], 'Order ' . $order['reference'] . ' has shipped', $body, 'dispatch');
}
