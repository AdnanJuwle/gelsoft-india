<?php
/*
 * Escrow, after the fix.
 *
 * The dangerous interleaving was: admin reads "delivered", buyer disputes,
 * admin's write lands with `WHERE status <> 'released'` and overwrites it.
 * The write is now pinned to the status that was read, and the read is taken
 * FOR UPDATE inside the same transaction.
 *
 * The lock means the two halves can no longer be interleaved inside one
 * process -- which is the point -- so what is checked here is the property
 * that closes the window: a write built on a stale read changes nothing, and
 * nothing downstream of it runs.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/listings.php';
require_once $ROOT . '/inc/listing_sales.php';

function status_of(int $id): string
{
    return (string) q1('SELECT status FROM listing_orders WHERE id = ?', [$id])['status'];
}

function events_of(int $id): int
{
    return (int) q1('SELECT COUNT(*) n FROM listing_order_events WHERE order_id = ?', [$id])['n'];
}

$listing = q1("SELECT * FROM listings WHERE status IN ('live','sold') ORDER BY id LIMIT 1");
$seller  = (int) $listing['seller_id'];
$buyer   = (int) q1('SELECT id FROM users WHERE id <> ? ORDER BY id LIMIT 1', [$seller])['id'];
$admin   = q1("SELECT * FROM users WHERE role IN ('admin','owner') ORDER BY id LIMIT 1");

function fresh_sale(array $listing, int $buyer, string $status, ?string $confirm_by): int
{
    static $n = 0;
    $n++;
    exec_sql(
        "INSERT INTO listing_orders
           (reference, listing_id, buyer_id, seller_id, price_paise, postage_paise,
            inspection_paise, total_paise, seller_fee_paise, handling_paise, payout_paise,
            inspected, status, ship_name, ship_phone, ship_line1, ship_city,
            ship_state, ship_pin, confirm_by)
         VALUES (?, ?, ?, ?, 100000, 0, 0, 100000, 8000, 2300, 89700,
                 0, ?, 'Race', '9876543210', '1 St', 'Pune', 'Maharashtra', '411001', ?)",
        ['RACE-' . $n, (int) $listing['id'], $buyer, (int) $listing['seller_id'], $status, $confirm_by]
    );
    return (int) db()->lastInsertId();
}

$past = date('Y-m-d H:i:s', time() - 3600);

echo "--- a write built on a stale read changes nothing ---\n\n";

$sid = fresh_sale($listing, $buyer, 'delivered', $past);
$read = q1('SELECT status FROM listing_orders WHERE id = ?', [$sid])['status'];
printf("  admin reads it as                 %s\n", $read);

sale_move($sid, 'disputed', ['id' => $buyer, 'role' => 'customer'], 'It arrived broken.');
printf("  buyer disputes, so it is now      %s\n", status_of($sid));

$changed = exec_sql(
    "UPDATE listing_orders SET status = 'released', released_at = NOW() WHERE id = ? AND status = ?",
    [$sid, $read]
);
printf("  the admin's pinned write changes  %d row(s)\n", $changed);
printf("  the sale is still                 %s\n", status_of($sid));
printf("\n  the dispute %s\n\n",
    status_of($sid) === 'disputed' ? 'survived' : 'WAS OVERWRITTEN');

exec_sql('DELETE FROM listing_orders WHERE id = ?', [$sid]);

echo "--- and the real function refuses it outright ---\n\n";

$sid = fresh_sale($listing, $buyer, 'disputed', $past);
$before = events_of($sid);
$err = sale_release($sid, $admin, false);
printf("  release a disputed sale        -> %s\n", $err ?? 'ALLOWED');
printf("  status                         -> %s\n", status_of($sid));
printf("  audit rows written             -> %d\n", events_of($sid) - $before);

$err = sale_release($sid, $admin, true);
printf("  release it deliberately        -> %s\n", $err ?? 'ok');
printf("  status                         -> %s\n", status_of($sid));
printf("  audit rows written             -> %d\n", events_of($sid) - $before);

$err = sale_release($sid, $admin, true);
printf("  release the same one again     -> %s\n", $err ?? 'ALLOWED AGAIN');
printf("  audit rows written             -> %d  (a payout must appear once)\n",
    events_of($sid) - $before);

exec_sql('DELETE FROM listing_orders WHERE id = ?', [$sid]);

echo "\n--- a seller cannot release their own money ---\n\n";

$sid = fresh_sale($listing, $buyer, 'delivered', $past);
$err = sale_release($sid, ['id' => $seller, 'role' => 'customer'], false);
printf("  seller releases to themselves  -> %s\n", $err ?? 'ALLOWED');
$err = sale_release($sid, ['id' => $buyer, 'role' => 'customer'], false);
printf("  buyer releases                 -> %s\n", $err ?? 'ALLOWED');
printf("  status                         -> %s\n", status_of($sid));

exec_sql('DELETE FROM listing_orders WHERE id = ?', [$sid]);
exec_sql("DELETE FROM listing_orders WHERE reference LIKE 'RACE-%'");
echo "\n(cleaned up)\n";
