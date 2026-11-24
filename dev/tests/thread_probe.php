<?php
/*
 * Which listings can a conversation be started about?
 *
 * thread_open() reads the listing's status and never uses it. Its twin,
 * thread_open_game(), refuses anything that is not live.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/listings.php';
require_once $ROOT . '/inc/messages.php';

$listing = q1('SELECT * FROM listings ORDER BY id LIMIT 1');
$lid     = (int) $listing['id'];
$was     = (string) $listing['status'];
$seller  = (int) $listing['seller_id'];
$buyer   = (int) q1('SELECT id FROM users WHERE id <> ? ORDER BY id LIMIT 1', [$seller])['id'];

printf("listing %d, seller %d, would-be buyer %d\n\n", $lid, $seller, $buyer);

foreach (['live', 'draft', 'pending', 'rejected', 'withdrawn', 'sold'] as $status) {
    exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [$lid, $buyer]);
    exec_sql('UPDATE listings SET status = ? WHERE id = ?', [$status, $lid]);

    $t = thread_open($lid, $buyer);
    printf("  listing is %-10s -> %s\n", $status,
        $t ? 'conversation OPENED' : 'refused');
}

echo "\n--- and what the thread would show about it ---\n";
exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [$lid, $buyer]);
exec_sql("UPDATE listings SET status = 'draft' WHERE id = ?", [$lid]);
$t = thread_open($lid, $buyer);
if ($t) {
    $row = q1("SELECT l.title, l.price_paise FROM threads t
                 JOIN listings l ON l.id = t.listing_id WHERE t.id = ?", [(int) $t['id']]);
    printf("  a draft nobody has reviewed shows as: \"%s\", %s\n",
        $row['title'], money((int) $row['price_paise']));
}

echo "\n--- an existing conversation, once the listing sells ---\n";
exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [$lid, $buyer]);
exec_sql("UPDATE listings SET status = 'live' WHERE id = ?", [$lid]);
$first = thread_open($lid, $buyer);
exec_sql("UPDATE listings SET status = 'sold' WHERE id = ?", [$lid]);
$again = thread_open($lid, $buyer);
printf("  opened while live, reopened once sold: %s\n",
    $again && (int) $again['id'] === (int) $first['id']
        ? 'same conversation, still reachable' : 'LOST');

exec_sql('DELETE FROM threads WHERE listing_id = ? AND buyer_id = ?', [$lid, $buyer]);
exec_sql('UPDATE listings SET status = ? WHERE id = ?', [$was, $lid]);
printf("\n(listing put back to %s)\n", $was);
