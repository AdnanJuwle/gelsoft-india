<?php
/*
 * The other half of the escrow race, as a separate process.
 *
 * Takes the sale's row lock, holds it for a moment, disputes the sale, and
 * commits. Run alongside sale_release() this is exactly the interleaving that
 * used to pay a seller over a dispute raised seconds earlier: the admin's
 * read happened before this one landed.
 *
 *   php race_holder.php <sale_id> <hold_seconds>
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/listings.php';
require_once $ROOT . '/inc/listing_sales.php';

$sale_id = (int) ($argv[1] ?? 0);
$hold    = (float) ($argv[2] ?? 2.0);

$pdo = db();
$pdo->beginTransaction();

// Lock it first, so a sale_release() starting now has to wait for us.
q1('SELECT id, status FROM listing_orders WHERE id = ? FOR UPDATE', [$sale_id]);

usleep((int) ($hold * 1_000_000));

exec_sql("UPDATE listing_orders SET status = 'disputed',
            dispute_note = 'It arrived broken.' WHERE id = ?", [$sale_id]);

$pdo->commit();
echo "disputed\n";
