<?php
/**
 * Sending the restock alerts that have been collected all along.
 *
 * The rule that makes this worth having: **one message, then the row is
 * marked done.** A restock alert that turns into a mailing list is the fastest
 * way to teach people to ignore you, and the promise on every one of those
 * forms is "one message when it lands, nothing else".
 */
declare(strict_types=1);

/**
 * Who is waiting on this product. Includes the people who asked about the
 * whole category, since "tell me when there are blasters" is answered by a
 * blaster arriving.
 */
function alerts_waiting_for(int $product_id): array
{
    if (!db_ready()) {
        return [];
    }

    $product = q1('SELECT category FROM products WHERE id = ?', [$product_id]);
    if (!$product) {
        return [];
    }

    return q(
        'SELECT * FROM restock_alerts
         WHERE notified_at IS NULL
           AND (product_id = ? OR (product_id IS NULL AND category = ?))
         ORDER BY created_at',
        [$product_id, $product['category']]
    );
}

/**
 * Tell everyone waiting that this product is back. Returns how many were sent.
 *
 * Each row is marked notified *before* the mail goes out. If sending throws
 * halfway through a hundred rows, the ones already done are not sent twice
 * when somebody presses the button again — and a message that was promised
 * once is better missed than doubled.
 */
function alerts_send_for_product(int $product_id, ?int $actor_id = null): int
{
    $product = db_ready() ? q1('SELECT * FROM products WHERE id = ?', [$product_id]) : null;
    if (!$product) {
        return 0;
    }

    $waiting = alerts_waiting_for($product_id);
    if (!$waiting) {
        return 0;
    }

    $site = rtrim((string) cfg('site_url'), '/');
    $url  = $site . shop_path((string) $product['category']) . '/' . rawurlencode((string) $product['slug']);
    $sent = 0;

    foreach ($waiting as $row) {
        try {
            exec_sql('UPDATE restock_alerts SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL',
                [(int) $row['id']]);
        } catch (Throwable $e) {
            error_log('alert mark failed: ' . $e->getMessage());
            continue;
        }

        // A phone number goes in the log as sent; there is no SMS gateway
        // wired up, and pretending otherwise would be worse than saying so.
        if (!filter_var($row['contact'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $body = "{$product['name']} is back on the shelf.\n\n"
              . money((int) $product['price_paise'], false) . "\n"
              . ($product['summary'] ? $product['summary'] . "\n" : '')
              . "\n{$url}\n\n"
              . "Stock lands in batches and goes quickly, which is the only reason this email\n"
              . "exists. You asked once and this is the one message — you are off the list now.\n";

        if (send_mail((string) $row['contact'], $product['name'] . ' is back in stock', $body
            . "\nGelsoft India\n{$site}\n", 'restock')) {
            $sent++;
        }
    }

    if ($actor_id !== null) {
        admin_log('alerts_sent', (string) $product_id, $sent . ' of ' . count($waiting));
    }

    return $sent;
}

/** How many people are waiting on each product, for the admin list. */
function alerts_pending_counts(): array
{
    if (!db_ready()) {
        return [];
    }
    $rows = q('SELECT product_id, COUNT(*) n FROM restock_alerts
               WHERE notified_at IS NULL AND product_id IS NOT NULL
               GROUP BY product_id');
    return array_column($rows, 'n', 'product_id');
}

/** Everyone waiting on nothing in particular, grouped by what they asked for. */
function alerts_general(): array
{
    if (!db_ready()) {
        return [];
    }
    return q("SELECT COALESCE(category, 'anything') AS what, COUNT(*) n
              FROM restock_alerts
              WHERE notified_at IS NULL AND product_id IS NULL
              GROUP BY COALESCE(category, 'anything') ORDER BY n DESC");
}
