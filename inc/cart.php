<?php
/**
 * The cart.
 *
 * The session holds product ids and quantities and nothing else. Every price,
 * name and stock figure is read from the database each time the cart is
 * rendered or totalled — so a cart that has sat open for a week cannot buy
 * last week's price, and nothing a browser sends can set what something costs.
 */
declare(strict_types=1);

const CART_MAX_QTY   = 9;
const CART_MAX_LINES = 30;

/** Free over this. Below it, a flat rate. */
const SHIP_FLAT_PAISE = 18000;
const SHIP_FREE_OVER  = 500000;

function cart_raw(): array
{
    return is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];
}

function cart_count(): int
{
    $n = 0;
    foreach (cart_raw() as $qty) {
        $n += (int) $qty;
    }
    return $n;
}

/**
 * Add to the cart. Returns an error string, or null.
 *
 * Quantity is capped at what is actually on the shelf, rather than refused:
 * someone asking for five of a thing you have two of wants the two.
 */
function cart_add(int $product_id, int $qty = 1): ?string
{
    if (!db_ready()) {
        return 'The shop is not reachable just now.';
    }

    $p = q1("SELECT id, name, stock_qty FROM products WHERE id = ? AND status = 'published'", [$product_id]);
    if (!$p) {
        return 'That item is not for sale.';
    }
    if ((int) $p['stock_qty'] < 1) {
        return 'That one is out of stock.';
    }

    $cart = cart_raw();
    if (!isset($cart[$product_id]) && count($cart) >= CART_MAX_LINES) {
        return 'That is as many different items as one order can hold.';
    }

    $qty = max(1, min(CART_MAX_QTY, $qty));
    $want = (int) ($cart[$product_id] ?? 0) + $qty;
    $have = min(CART_MAX_QTY, (int) $p['stock_qty']);

    $cart[$product_id] = min($want, $have);
    $_SESSION['cart'] = $cart;

    return $want > $have
        ? 'We only have ' . $have . ' of those, so that is what is in your cart.'
        : null;
}

function cart_set(int $product_id, int $qty): void
{
    $cart = cart_raw();
    if ($qty < 1) {
        unset($cart[$product_id]);
    } else {
        $stock = db_ready()
            ? (int) (q1('SELECT stock_qty FROM products WHERE id = ?', [$product_id])['stock_qty'] ?? 0)
            : 0;
        $cart[$product_id] = max(1, min(CART_MAX_QTY, min($qty, $stock ?: CART_MAX_QTY)));
    }
    $_SESSION['cart'] = $cart;
}

function cart_remove(int $product_id): void
{
    $cart = cart_raw();
    unset($cart[$product_id]);
    $_SESSION['cart'] = $cart;
}

function cart_clear(): void
{
    unset($_SESSION['cart']);
}

/**
 * The cart as it stands right now, priced from the database.
 *
 * Returns ['lines' => [...], 'subtotal' => paise, 'shipping' => paise,
 *          'total' => paise, 'count' => n, 'problems' => [...]].
 *
 * `problems` is how the cart tells the truth about itself: an item that went
 * out of stock or was unpublished while it sat there is reported rather than
 * silently dropped, because a total that changes with no explanation is worse
 * than one that explains itself.
 */
function cart_contents(): array
{
    $cart = cart_raw();
    $out = ['lines' => [], 'subtotal' => 0, 'shipping' => 0, 'total' => 0,
            'count' => 0, 'problems' => []];

    if (!$cart || !db_ready()) {
        return $out;
    }

    $ids = array_map('intval', array_keys($cart));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = q(
        "SELECT p.*, (
             SELECT i.path FROM product_images i
             WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1
         ) AS image
         FROM products p WHERE p.id IN ({$marks})",
        $ids
    );

    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
    }

    $changed = false;

    foreach ($cart as $id => $qty) {
        $id = (int) $id;
        $qty = (int) $qty;
        $p = $byId[$id] ?? null;

        if (!$p || $p['status'] !== 'published') {
            $out['problems'][] = 'An item in your cart is no longer for sale, so we took it out.';
            unset($_SESSION['cart'][$id]);
            $changed = true;
            continue;
        }

        $stock = (int) $p['stock_qty'];
        if ($stock < 1) {
            $out['problems'][] = e_name($p) . ' sold out while it was in your cart.';
            unset($_SESSION['cart'][$id]);
            $changed = true;
            continue;
        }
        if ($qty > $stock) {
            $out['problems'][] = 'We only have ' . $stock . ' of ' . e_name($p) . ' left.';
            $_SESSION['cart'][$id] = $stock;
            $qty = $stock;
            $changed = true;
        }

        $line = (int) $p['price_paise'] * $qty;
        $out['lines'][] = [
            'product'  => $p,
            'qty'      => $qty,
            'line_paise' => $line,
        ];
        $out['subtotal'] += $line;
        $out['count'] += $qty;
    }

    $out['shipping'] = cart_shipping($out['subtotal'], $out['count']);
    $out['total'] = $out['subtotal'] + $out['shipping'];
    $out['changed'] = $changed;

    return $out;
}

function cart_shipping(int $subtotal, int $count): int
{
    if ($count === 0) {
        return 0;
    }
    return $subtotal >= SHIP_FREE_OVER ? 0 : SHIP_FLAT_PAISE;
}

/** Just the name, for a sentence. Escaped at the point of output, not here. */
function e_name(array $product): string
{
    return (string) $product['name'];
}
