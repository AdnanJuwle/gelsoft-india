<?php
/*
 * Two buyers, one second-hand blaster.
 *
 * sale_create() locks the listing FOR UPDATE and re-checks that it is still
 * live -- but it never CHANGES the status, so the second buyer's check passes
 * too. The listing only goes 'sold' when a payment clears, in
 * sale_mark_held(), which is called from the webhook.
 *
 * So the question is what happens when two people both get that far.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/listings.php';
require_once $ROOT . '/inc/listing_sales.php';

$listing = q1("SELECT * FROM listings ORDER BY id LIMIT 1");
exec_sql("UPDATE listings SET status = 'live', sold_at = NULL WHERE id = ?", [(int) $listing['id']]);
$listing = q1('SELECT * FROM listings WHERE id = ?', [(int) $listing['id']]);

$seller = (int) $listing['seller_id'];
$others = q('SELECT id FROM users WHERE id <> ? ORDER BY id LIMIT 2', [$seller]);
$a = (int) $others[0]['id'];
$b = (int) $others[1]['id'];

$ship = ['name' => 'Buyer', 'phone' => '9876543210', 'line1' => '1 St',
         'line2' => '', 'city' => 'Pune', 'state' => 'Maharashtra', 'pin' => '411001'];

printf("listing %d is %s\n\n", (int) $listing['id'], $listing['status']);

echo "--- buyer A starts a sale ---\n";
$ra = sale_create($listing, $a, $ship, false);
printf("  %s\n", isset($ra['sale']) ? 'sale ' . $ra['sale']['reference'] : 'refused: ' . $ra['error']);
printf("  listing is now: %s\n\n", q1('SELECT status FROM listings WHERE id = ?', [(int) $listing['id']])['status']);

echo "--- buyer B starts one too, a moment later ---\n";
$rb = sale_create($listing, $b, $ship, false);
printf("  %s\n\n", isset($rb['sale']) ? 'sale ' . $rb['sale']['reference'] : 'refused: ' . $rb['error']);

if (!isset($ra['sale'], $rb['sale'])) {
    echo "(only one sale was created; nothing more to test)\n";
} else {
    echo "--- both payments clear ---\n";
    $ida = (int) $ra['sale']['id'];
    $idb = (int) $rb['sale']['id'];
    printf("  A's payment held: %s\n", sale_mark_held($ida, 'pay_a') ? 'yes' : 'no');
    printf("  listing is now:   %s\n", q1('SELECT status FROM listings WHERE id = ?', [(int) $listing['id']])['status']);
    printf("  B's payment held: %s\n", sale_mark_held($idb, 'pay_b') ? 'yes' : 'no');
    printf("  listing is now:   %s\n", q1('SELECT status FROM listings WHERE id = ?', [(int) $listing['id']])['status']);

    $held = q("SELECT reference, status, total_paise FROM listing_orders WHERE listing_id = ? AND status = 'held'",
        [(int) $listing['id']]);
    printf("\n  sales holding money for this one item: %d\n", count($held));
    foreach ($held as $h) {
        printf("    %s  %s  %s\n", $h['reference'], $h['status'], money((int) $h['total_paise']));
    }
    printf("\n  %s\n", count($held) > 1
        ? 'TWO PEOPLE HAVE PAID FOR ONE BLASTER'
        : 'only one payment is held');
}

exec_sql("DELETE FROM listing_orders WHERE listing_id = ?", [(int) $listing['id']]);
exec_sql("UPDATE listings SET status = 'live', sold_at = NULL WHERE id = ?", [(int) $listing['id']]);
echo "\n(cleaned up)\n";
