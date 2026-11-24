<?php
/**
 * The marketplace: listings, and the sale of one.
 *
 * The fees, in one place so nothing has to remember them:
 *   - the SELLER pays `fee_pct` + `handling_pct` + a flat few rupees,
 *     whatever else happens;
 *   - the BUYER may choose an inspection, and pays `inspection_fee_pct` on
 *     top for it. That is a service they are buying, not a bigger cut of the
 *     sale, so it never changes what the seller receives.
 */
declare(strict_types=1);

const LISTING_ENUMS = [
    'item_condition' => ['like-new', 'good', 'fair', 'serviced'],
    'grade'          => ['toy', 'mid', 'premium'],
    'gears'          => ['metal', 'nylon', 'manual'],
    'handover'       => ['courier', 'pickup', 'both'],
];

const LISTING_SORTS = [
    'new'  => 'l.created_at DESC',
    'low'  => 'l.price_paise ASC',
    'high' => 'l.price_paise DESC',
];

const LISTINGS_PER_PAGE = 24;

/* ------------------------------------------------------------- money ---- */

/** What the seller is left with on a sale at this price. */
function seller_payout(int $price_paise): array
{
    $fee      = (int) round($price_paise * (int) cfg('marketplace.fee_pct', 8) / 100);
    $handling = (int) round($price_paise * (int) cfg('marketplace.handling_pct', 2) / 100)
              + (int) cfg('marketplace.flat_paise', 300);

    return [
        'fee'      => $fee,
        'handling' => $handling,
        'payout'   => max(0, $price_paise - $fee - $handling),
    ];
}

/** What the buyer pays, with or without an inspection. */
function buyer_total(int $price_paise, int $postage_paise, bool $inspected): array
{
    $inspection = $inspected
        ? (int) round($price_paise * (int) cfg('marketplace.inspection_fee_pct', 8) / 100)
        : 0;

    return [
        'inspection' => $inspection,
        'total'      => $price_paise + $postage_paise + $inspection,
    ];
}

/* ------------------------------------------------------------ reading --- */

function listing_filters(): array
{
    $pick = static function (string $key) {
        $raw = $_GET[$key] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];
        return array_values(array_intersect(LISTING_ENUMS[$key] ?? [], array_map('strval', $raw)));
    };

    $sort = (string) ($_GET['sort'] ?? 'new');

    return [
        'item_condition' => $pick('item_condition'),
        'grade'          => $pick('grade'),
        'gears'          => $pick('gears'),
        'inspectable'    => !empty($_GET['inspectable']),
        'offers'         => !empty($_GET['offers']),
        'price_max'      => isset($_GET['price_max']) && $_GET['price_max'] !== ''
                            ? max(0, min(1000000, (int) $_GET['price_max'])) : null,
        'q'              => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
        'sort'           => isset(LISTING_SORTS[$sort]) ? $sort : 'new',
        'page'           => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

function listing_filtered(array $f): bool
{
    return $f['item_condition'] || $f['grade'] || $f['gears'] || $f['inspectable']
        || $f['offers'] || $f['price_max'] !== null || $f['q'] !== '';
}

function listing_where(array $f, array $ignore = []): array
{
    $sql = ["l.status = 'live'"];
    $params = [];

    $in = static function (string $col, array $values) use (&$sql, &$params): void {
        if (!$values) {
            return;
        }
        $sql[] = $col . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    };

    foreach (['item_condition' => 'l.item_condition', 'grade' => 'l.grade', 'gears' => 'l.gears'] as $k => $col) {
        if (!in_array($k, $ignore, true)) {
            $in($col, $f[$k]);
        }
    }

    if ($f['inspectable'] && !in_array('inspectable', $ignore, true)) {
        $sql[] = 'l.allow_inspection = 1';
    }
    if ($f['offers']) {
        $sql[] = 'l.accepts_offers = 1';
    }
    if ($f['price_max'] !== null) {
        $sql[] = 'l.price_paise <= ?';
        $params[] = $f['price_max'] * 100;
    }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
        $sql[] = '(l.title LIKE ? OR l.description LIKE ? OR l.brand LIKE ?)';
        array_push($params, $like, $like, $like);
    }

    return [implode(' AND ', $sql), $params];
}

function listings_query(array $f): array
{
    if (!db_ready()) {
        return ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => 1];
    }

    [$where, $params] = listing_where($f);

    $total = (int) (q1("SELECT COUNT(*) n FROM listings l WHERE {$where}", $params)['n'] ?? 0);
    $pages = (int) ceil($total / LISTINGS_PER_PAGE);
    $page  = min(max(1, $f['page']), max(1, $pages));

    $rows = q(
        "SELECT l.*, u.name AS seller_name, u.seller_verified_at,
                (SELECT i.path FROM listing_images i
                  WHERE i.listing_id = l.id ORDER BY i.sort_order, i.id LIMIT 1) AS image
         FROM listings l
         JOIN users u ON u.id = l.seller_id
         WHERE {$where}
         ORDER BY " . LISTING_SORTS[$f['sort']] . "
         LIMIT " . LISTINGS_PER_PAGE . ' OFFSET ' . (($page - 1) * LISTINGS_PER_PAGE),
        $params
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function listing_counts(array $f, string $key, string $column): array
{
    if (!db_ready()) {
        return [];
    }
    [$where, $params] = listing_where($f, [$key]);
    $rows = q("SELECT {$column} AS v, COUNT(*) n FROM listings l
               WHERE {$where} AND {$column} IS NOT NULL GROUP BY {$column}", $params);
    return array_column($rows, 'n', 'v');
}

function listing_by_slug(string $slug): ?array
{
    if (!db_ready()) {
        return null;
    }
    return q1('SELECT l.*, u.name AS seller_name, u.seller_verified_at, u.created_at AS seller_since
               FROM listings l JOIN users u ON u.id = l.seller_id
               WHERE l.slug = ? LIMIT 1', [$slug]);
}

function listing_images(int $id): array
{
    return db_ready()
        ? q('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order, id', [$id])
        : [];
}

/** Can this person see this listing? Live ones, their own, and admins. */
function listing_visible(array $listing, ?array $user): bool
{
    if ($listing['status'] === 'live' || $listing['status'] === 'sold') {
        return true;
    }
    if (!$user) {
        return false;
    }
    return user_is_staff($user) || (int) $listing['seller_id'] === (int) $user['id'];
}

/* ------------------------------------------------------------ labels ---- */

function condition_label(?string $c): string
{
    return [
        'like-new' => 'Like new',
        'good'     => 'Good',
        'fair'     => 'Fair',
        'serviced' => 'Serviced',
    ][$c] ?? '';
}

function handover_label(?string $h): string
{
    return [
        'courier' => 'Courier only',
        'pickup'  => 'Pickup only',
        'both'    => 'Courier or pickup',
    ][$h] ?? '';
}

function listing_status_label(string $s): string
{
    return [
        'draft'     => 'Draft',
        'pending'   => 'Waiting for review',
        'live'      => 'Live',
        'sold'      => 'Sold',
        'withdrawn' => 'Withdrawn',
        'rejected'  => 'Sent back',
    ][$s] ?? $s;
}

function sale_status_label(string $s): string
{
    return [
        'pending'    => 'Awaiting payment',
        'held'       => 'Paid — we are holding the money',
        'to_us'      => 'On its way to us',
        'inspecting' => 'On our bench',
        'failed'     => 'Failed inspection',
        'shipped'    => 'On its way to the buyer',
        'delivered'  => 'Delivered',
        'released'   => 'Paid out to the seller',
        'disputed'   => 'Disputed',
        'refunded'   => 'Refunded',
        'cancelled'  => 'Cancelled',
    ][$s] ?? $s;
}

/* ------------------------------------------------------------ markup ---- */

function listing_url(array $l): string
{
    return '/marketplace/' . rawurlencode((string) $l['slug']);
}

/** One listing card. */
function listing_card(array $l): string
{
    $media = !empty($l['image'])
        ? '<img class="ph-img" src="' . e(url($l['image'])) . '" alt="" loading="lazy" width="400" height="300">'
        : '<span class="ph ph--thumb"><span class="tiny">No photo</span></span>';

    $chips = '<span class="chip">' . e(condition_label($l['item_condition'])) . '</span>';
    if (!empty($l['gears'])) {
        $chips .= '<span class="chip chip--' . e($l['gears']) . '">' . e(gears_label($l['gears'])) . '</span>';
    }
    if (!empty($l['fps'])) {
        $chips .= '<span class="chip">' . (int) $l['fps'] . ' FPS</span>';
    }

    $flag = !empty($l['grade'])
        ? '<span class="prod__flag grade--badge">' . grade_mark($l['grade']) . '</span>'
        : '';

    $inspect = !empty($l['allow_inspection'])
        ? '<span class="tiny" style="color:var(--olive)">Inspection available</span>'
        : '<span class="tiny muted">' . e($l['city'] ?: 'India') . '</span>';

    return '<a class="prod" href="' . e(listing_url($l)) . '">'
        . '<span class="prod__media">' . $media . $flag . '</span>'
        . '<span class="prod__body">'
        . '<span class="prod__brand">' . e($l['brand'] ?: 'Second-hand') . '</span>'
        . '<span class="prod__name">' . e($l['title']) . '</span>'
        . '<span class="chips">' . $chips . '</span>'
        . '<span class="prod__foot"><span class="price">' . money((int) $l['price_paise']) . '</span>'
        . $inspect . '</span>'
        . '</span></a>';
}

function listing_option(array $f, string $key, string $value, string $label, array $counts): string
{
    $n = (int) ($counts[$value] ?? 0);
    $on = in_array($value, $f[$key], true);
    return '<label class="checkline' . ($n === 0 && !$on ? ' checkline--none' : '') . '">'
        . '<input type="checkbox" name="' . e($key) . '[]" value="' . e($value) . '"' . ($on ? ' checked' : '') . '>'
        . '<span class="name">' . $label . '</span>'
        . '<span class="count">' . $n . '</span></label>';
}

/**
 * Delete a listing outright, with its photos.
 *
 * Two different jobs get confused here, so they are kept apart:
 *
 *   WITHDRAWN hides a listing from the board and keeps everything. That is
 *             what you want for anything real that has been sold or pulled.
 *   DELETE    removes it. Only for spam and mistakes, and only when nobody
 *             has ever bought it.
 *
 * The database enforces the second part itself -- `listing_orders` references
 * `listings` ON DELETE RESTRICT, because a sale is an accounting record and
 * must outlive the advert. This checks first so the admin gets a sentence
 * rather than a foreign-key error.
 */
function listing_delete(int $id): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }

    $listing = q1('SELECT id, title, status FROM listings WHERE id = ?', [$id]);
    if (!$listing) {
        return 'That listing is already gone.';
    }

    $sales = (int) (q1('SELECT COUNT(*) n FROM listing_orders WHERE listing_id = ?', [$id])['n'] ?? 0);
    if ($sales > 0) {
        return 'Somebody has bought this, so it cannot be deleted -- the sale record has to '
             . 'outlive the advert. Withdraw it instead and it comes off the board.';
    }

    // The files, before the rows that point at them: a row without a file is
    // tidy, a file with no row is litter nobody will ever find.
    foreach (q('SELECT path FROM listing_images WHERE listing_id = ?', [$id]) as $img) {
        upload_delete((string) $img['path']);
    }

    try {
        // listing_images and threads both cascade.
        exec_sql('DELETE FROM listings WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        error_log('listing delete failed: ' . $e->getMessage());
        return 'That could not be deleted. The reason is in the server error log.';
    }

    admin_log('listing_delete', (string) $id, (string) $listing['title']);
    return null;
}

/** Take a listing off the board without destroying anything. */
function listing_withdraw(int $id): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }
    $n = exec_sql("UPDATE listings SET status = 'withdrawn' WHERE id = ? AND status <> 'withdrawn'", [$id]);
    if ($n === 0) {
        return 'That listing is already off the board.';
    }
    admin_log('listing_withdraw', (string) $id);
    return null;
}
