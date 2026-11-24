<?php
/*
 * An accepted offer is a promise about a price. Two ways it could be broken:
 *
 *   the offer expires and the buyer is quietly charged the asking price;
 *   the seller raises the asking price after accepting, and the sale follows
 *   the new one.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/listings.php';
require_once $ROOT . '/inc/listing_sales.php';
require_once $ROOT . '/inc/messages.php';

$listing = q1("SELECT * FROM listings ORDER BY id LIMIT 1");
$seller  = (int) $listing['seller_id'];
$buyer   = (int) q1('SELECT id FROM users WHERE id <> ? ORDER BY id LIMIT 1', [$seller])['id'];

function setup(array $listing, int $seller, int $buyer, string $expires): int
{
    exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [(int) $listing['id'], $buyer]);
    exec_sql('INSERT INTO threads (listing_id, buyer_id, seller_id, last_message_at) VALUES (?,?,?,NOW())',
        [(int) $listing['id'], $buyer, $seller]);
    $tid = (int) db()->lastInsertId();
    exec_sql(
        "INSERT INTO messages (thread_id, sender_id, body, kind, offer_paise,
                               offer_status, offer_expires_at, offer_decided_at)
         VALUES (?, ?, 'Would you take this?', 'offer', 500000, 'accepted', {$expires}, NOW())",
        [$tid, $buyer]
    );
    return $tid;
}

printf("listing asking price: %s\n\n", money((int) $listing['price_paise']));

echo "--- an accepted offer that is still live ---\n";
setup($listing, $seller, $buyer, 'DATE_ADD(NOW(), INTERVAL 24 HOUR)');
$a = offer_agreed((int) $listing['id'], $buyer);
printf("  offer_agreed() finds it:  %s\n", $a ? money((int) $a['offer_paise']) : 'no');

echo "\n--- the same offer, expired an hour ago ---\n";
setup($listing, $seller, $buyer, 'DATE_SUB(NOW(), INTERVAL 1 HOUR)');
$b = offer_agreed((int) $listing['id'], $buyer);
printf("  offer_agreed() finds it:  %s\n", $b ? money((int) $b['offer_paise']) . '  <-- EXPIRED AND STILL USED' : 'no');
printf("  so the buyer would pay:   %s\n", $b ? money((int) $b['offer_paise']) : money((int) $listing['price_paise']) . ' (the asking price, correctly)');

echo "\n--- the seller raises the asking price after accepting ---\n";
setup($listing, $seller, $buyer, 'DATE_ADD(NOW(), INTERVAL 24 HOUR)');
$was = (int) $listing['price_paise'];
exec_sql('UPDATE listings SET price_paise = ? WHERE id = ?', [$was + 500000, (int) $listing['id']]);
$fresh = q1('SELECT * FROM listings WHERE id = ?', [(int) $listing['id']]);
$c = offer_agreed((int) $listing['id'], $buyer);
$charged = $c ? (int) $c['offer_paise'] : (int) $fresh['price_paise'];
printf("  asking price is now:      %s\n", money((int) $fresh['price_paise']));
printf("  the buyer is charged:     %s  %s\n", money($charged),
    $charged === 500000 ? '(the agreed offer, correctly)' : '<-- THE NEW ASKING PRICE');
exec_sql('UPDATE listings SET price_paise = ? WHERE id = ?', [$was, (int) $listing['id']]);

exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [(int) $listing['id'], $buyer]);
echo "\n(cleaned up)\n";
