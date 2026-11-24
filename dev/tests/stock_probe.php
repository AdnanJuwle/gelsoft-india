<?php
/*
 * Stock, through every way an order can stop being a sale.
 *
 * The four cases that matter are not "does it restore" but "when must it NOT":
 * a pending order never took stock, a shipped one is gone, and a webhook that
 * arrives twice must not pay out twice.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/orders.php';
require_once $ROOT . '/inc/cart.php';

function stock(int $pid): int
{
    return (int) q1('SELECT stock_qty FROM products WHERE id = ?', [$pid])['stock_qty'];
}

function make_order(int $pid, int $qty, string $status): int
{
    static $n = 0;
    $n++;
    exec_sql(
        "INSERT INTO orders (reference, user_id, email, phone, ship_name, ship_line1,
                             ship_city, ship_state, ship_pin, subtotal_paise,
                             shipping_paise, total_paise, status, payment_provider)
         VALUES (?, NULL, 'probe@example.com', '9876543210', 'Probe',
                 '1 Test St', 'Pune', 'Maharashtra', '411001', 100000, 0, 100000,
                 'pending', 'razorpay')",
        ['ST-PROBE-' . $n]
    );
    $oid = (int) db()->lastInsertId();
    exec_sql(
        'INSERT INTO order_items (order_id, product_id, name_at_purchase, slug_at_purchase, price_paise, qty)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$oid, $pid, 'Probe item', 'probe-item', 100000, $qty]
    );
    if ($status !== 'pending') {
        order_mark_paid($oid, 'pay_probe_' . $n);
        if ($status !== 'paid') {
            exec_sql('UPDATE orders SET status = ? WHERE id = ?', [$status, $oid]);
        }
    }
    return $oid;
}

$pid   = (int) q1("SELECT id FROM products WHERE status='published' AND stock_qty > 2 ORDER BY id LIMIT 1")['id'];
$start = stock($pid);
printf("product %d starts with %d in stock\n\n", $pid, $start);

$cases = [
    ['paid',      'cancelled', 2, 'taken off the shelf, not dispatched'],
    ['packed',    'refunded',  2, 'boxed but still here'],
    ['pending',   'cancelled', 2, 'an abandoned checkout — it never took any'],
    ['shipped',   'refunded',  2, 'out in the world'],
    ['delivered', 'refunded',  1, 'with the customer'],
];

foreach ($cases as [$from, $to, $qty, $why]) {
    $before = stock($pid);
    $oid = make_order($pid, $qty, $from);
    $afterPay = stock($pid);
    $r = order_close($oid, $to);
    $afterClose = stock($pid);

    printf("%-9s -> %-9s  %s\n", $from, $to, $why);
    printf("    stock %d -> %d (order placed) -> %d (closed)   restored=%d dispatched=%s\n",
        $before, $afterPay, $afterClose, $r['restored'], $r['dispatched'] ? 'yes' : 'no');
    printf("    net change: %+d  %s\n\n", $afterClose - $before,
        ($afterClose === $before) ? 'balanced' : 'SHORT');

    exec_sql('DELETE FROM orders WHERE id = ?', [$oid]);
    exec_sql('UPDATE products SET stock_qty = ? WHERE id = ?', [$start, $pid]);
}

echo "--- the same refund twice, which is what Razorpay sends ---\n";
$oid = make_order($pid, 2, 'paid');
$a = order_close($oid, 'refunded');
$afterOne = stock($pid);
$b = order_close($oid, 'refunded');
$afterTwo = stock($pid);
printf("  first call:  restored=%d changed=%s  stock=%d\n", $a['restored'], $a['changed'] ? 'yes' : 'no', $afterOne);
printf("  second call: restored=%d changed=%s  stock=%d\n", $b['restored'], $b['changed'] ? 'yes' : 'no', $afterTwo);
printf("  %s\n", $afterOne === $afterTwo ? '  no second helping' : '  DOUBLE RESTORE');

exec_sql('DELETE FROM orders WHERE id = ?', [$oid]);
exec_sql('UPDATE products SET stock_qty = ? WHERE id = ?', [$start, $pid]);
exec_sql("DELETE FROM orders WHERE reference LIKE 'ST-PROBE-%'");
printf("\n(cleaned up; stock back to %d)\n", stock($pid));
