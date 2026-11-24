<?php
/**
 * The shop catalogue: reading products out of the database, and the filter
 * rails that narrow them.
 *
 * Filters are read from the query string, so a filtered shelf is a URL someone
 * can bookmark or send to a friend, the back button works, and it all still
 * works with JavaScript off. Only values from the whitelists below ever reach
 * SQL, and they reach it as bound parameters.
 */
declare(strict_types=1);

/** The filters each shelf offers, with the only values each one accepts. */
const SHOP_ENUMS = [
    'type'   => ['pistol', 'rifle', 'smg', 'bolt'],
    'grade'  => ['toy', 'mid', 'premium'],
    'gears'  => ['metal', 'nylon', 'manual'],
    'gear_group' => ['eye', 'head', 'body', 'hands', 'carry', 'legs'],
    'part_group' => ['internals', 'electrical', 'optics', 'externals', 'mags', 'gel'],
];

/** Sort options: label => ORDER BY. The key is what appears in the URL. */
const SHOP_SORTS = [
    'featured' => 'featured DESC, sort_order ASC, created_at DESC',
    'low'      => 'price_paise ASC',
    'high'     => 'price_paise DESC',
    'fps'      => 'fps_max DESC',
    'new'      => 'created_at DESC',
];

const SHOP_PER_PAGE = 24;

/**
 * Read the query string into a filter array. Anything unrecognised is dropped
 * rather than corrected, so a hand-edited URL cannot widen the query.
 */
function shop_filters(string|array $category): array
{
    $pick = static function (string $key) {
        $raw = $_GET[$key] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];
        $allowed = SHOP_ENUMS[$key] ?? [];
        return array_values(array_intersect($allowed, array_map('strval', $raw)));
    };

    $int = static function (string $key, int $min, int $max): ?int {
        if (!isset($_GET[$key]) || $_GET[$key] === '') {
            return null;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        return $value === false ? null : max($min, min($max, $value));
    };

    $brands = $_GET['brand'] ?? [];
    $brands = array_values(array_filter(
        array_map('trim', array_map('strval', is_array($brands) ? $brands : [$brands])),
        static fn($b) => $b !== '' && mb_strlen($b) <= 80
    ));

    $sort = (string) ($_GET['sort'] ?? 'featured');

    return [
        'category'   => $category,
        'type'       => $pick('type'),
        'grade'      => $pick('grade'),
        'gears'      => $pick('gears'),
        'gear_group' => $pick('gear_group'),
        'part_group' => $pick('part_group'),
        'brand'      => $brands,
        'fps_min'    => $int('fps_min', 0, 1000),
        'fps_max'    => $int('fps_max', 0, 1000),
        'price_max'  => $int('price_max', 0, 10_000_000),
        'in_stock'   => !empty($_GET['in_stock']),
        'q'          => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
        'sort'       => isset(SHOP_SORTS[$sort]) ? $sort : 'featured',
        'page'       => max(1, (int) ($_GET['page'] ?? 1)),
    ];
}

/** True when anything is narrowing the shelf. Drives the "Clear" link. */
function shop_filtered(array $f): bool
{
    foreach (['type', 'grade', 'gears', 'gear_group', 'part_group', 'brand'] as $key) {
        if (!empty($f[$key])) {
            return true;
        }
    }
    return $f['fps_min'] !== null || $f['fps_max'] !== null
        || $f['price_max'] !== null || $f['in_stock'] || $f['q'] !== '';
}

/**
 * Build the WHERE clause. Returns [sql, params]. Column names are literals in
 * this function and never come from the request.
 */
function shop_where(array $f, array $ignore = []): array
{
    // The parts shelf covers two categories (parts and gel), so this takes
    // either one or a list.
    $cats = (array) $f['category'];
    $sql = ['p.status = ?', 'p.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')'];
    $params = ['published', ...$cats];

    $in = static function (string $column, array $values) use (&$sql, &$params): void {
        if ($values === []) {
            return;
        }
        $sql[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    };

    foreach (['type' => 'p.type', 'grade' => 'p.grade', 'gears' => 'p.gears',
              'gear_group' => 'p.gear_group', 'part_group' => 'p.part_group'] as $key => $col) {
        if (!in_array($key, $ignore, true)) {
            $in($col, $f[$key]);
        }
    }

    if (!in_array('brand', $ignore, true) && $f['brand'] !== []) {
        $in('p.brand', $f['brand']);
    }

    if (!in_array('fps', $ignore, true)) {
        // An overlap test, not containment: a 200–240 blaster belongs in a
        // 230–300 search, because it can reach 230.
        if ($f['fps_min'] !== null) {
            $sql[] = 'COALESCE(p.fps_max, p.fps_min) >= ?';
            $params[] = $f['fps_min'];
        }
        if ($f['fps_max'] !== null) {
            $sql[] = 'COALESCE(p.fps_min, p.fps_max) <= ?';
            $params[] = $f['fps_max'];
        }
    }

    if (!in_array('price', $ignore, true) && $f['price_max'] !== null) {
        $sql[] = 'p.price_paise <= ?';
        $params[] = $f['price_max'] * 100;
    }

    if ($f['in_stock']) {
        $sql[] = 'p.stock_qty > 0';
    }

    if ($f['q'] !== '') {
        // % and _ are wildcards to LIKE, so a search for "100%" has to escape
        // them or it matches the whole shelf.
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
        $cols = ['p.name', 'p.summary', 'p.brand', 'p.gearbox', 'p.fits', 'p.description'];
        $sql[] = '(' . implode(' LIKE ? OR ', $cols) . ' LIKE ?)';
        array_push($params, ...array_fill(0, count($cols), $like));
    }

    return [implode(' AND ', $sql), $params];
}

/** The products for a shelf: ['rows' => [...], 'total' => n, 'pages' => n]. */
function shop_products(array $f): array
{
    if (!db_ready()) {
        return ['rows' => [], 'total' => 0, 'pages' => 0];
    }

    [$where, $params] = shop_where($f);

    $total = (int) (q1("SELECT COUNT(*) AS n FROM products p WHERE {$where}", $params)['n'] ?? 0);
    $pages = (int) ceil($total / SHOP_PER_PAGE);
    $page  = min(max(1, $f['page']), max(1, $pages));
    $offset = ($page - 1) * SHOP_PER_PAGE;

    // LIMIT and OFFSET are integers cast in PHP, not bound — MySQL will not
    // take a placeholder there under a real prepare.
    $rows = q(
        "SELECT p.*, (
             SELECT i.path FROM product_images i
             WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1
         ) AS image,
         (
             SELECT i.alt FROM product_images i
             WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1
         ) AS image_alt
         FROM products p
         WHERE {$where}
         ORDER BY " . SHOP_SORTS[$f['sort']] . "
         LIMIT " . SHOP_PER_PAGE . " OFFSET " . $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/**
 * How many products each filter option would leave. Counted with that option's
 * own filter lifted, which is why picking "Pistol" does not drop every other
 * type's count to nothing.
 */
function shop_counts(array $f, string $key, string $column): array
{
    if (!db_ready()) {
        return [];
    }
    [$where, $params] = shop_where($f, [$key === 'brand' ? 'brand' : $key]);
    $rows = q(
        "SELECT {$column} AS v, COUNT(*) AS n FROM products p
         WHERE {$where} AND {$column} IS NOT NULL AND {$column} <> ''
         GROUP BY {$column}",
        $params
    );
    return array_column($rows, 'n', 'v');
}

/** Every brand that has something published on this shelf. */
function shop_brands(string|array $category): array
{
    if (!db_ready()) {
        return [];
    }
    $cats = (array) $category;
    $rows = q(
        "SELECT DISTINCT brand FROM products
         WHERE status = 'published'
           AND category IN (" . implode(',', array_fill(0, count($cats), '?')) . ")
           AND brand IS NOT NULL AND brand <> ''
         ORDER BY brand",
        $cats
    );
    return array_column($rows, 'brand');
}

function product_by_slug(string $slug): ?array
{
    if (!db_ready()) {
        return null;
    }
    return q1('SELECT * FROM products WHERE slug = ? AND status = ? LIMIT 1', [$slug, 'published']);
}

function product_images(int $id): array
{
    if (!db_ready()) {
        return [];
    }
    return q('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]);
}

/** A few more from the same shelf, for the bottom of a product page. */
function products_related(array $product, int $limit = 4): array
{
    if (!db_ready()) {
        return [];
    }
    return q(
        "SELECT p.*, (
             SELECT i.path FROM product_images i
             WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1
         ) AS image
         FROM products p
         WHERE p.status = 'published' AND p.category = ? AND p.id <> ?
         ORDER BY (p.grade = ?) DESC, p.featured DESC, RAND()
         LIMIT " . max(1, min(12, $limit)),
        [$product['category'], $product['id'], $product['grade']]
    );
}

/* ----------------------------------------------------------- labels ------ */

function grade_label(?string $grade): string
{
    return [
        'toy'     => 'Toy grade',
        'mid'     => 'Mid hobby grade',
        'premium' => 'Premium grade',
    ][$grade] ?? '';
}

function gears_label(?string $gears): string
{
    return [
        'metal'  => 'Metal gears',
        'nylon'  => 'Nylon gears',
        'manual' => 'Manual — no gears',
    ][$gears] ?? '';
}

function type_label(?string $type): string
{
    return ['pistol' => 'Pistol', 'rifle' => 'Rifle', 'smg' => 'SMG', 'bolt' => 'Bolt-action'][$type] ?? '';
}

/** The chrono figure as it goes on a card: "230–250 FPS", or "" if unknown. */
function fps_label(array $p): string
{
    $min = $p['fps_min'] ?? null;
    $max = $p['fps_max'] ?? null;
    if (!$min && !$max) {
        return '';
    }
    if ($min && $max && $min !== $max) {
        return $min . '–' . $max . ' FPS';
    }
    return (string) ($max ?: $min) . ' FPS';
}

function stock_note(array $p): array
{
    // The QUANTITY decides, because the quantity is what decides whether the
    // Add to cart button is there. The label only chooses the wording within
    // that. It used to win outright, and a blaster on the shelf marked
    // Pre-order read "Pre-order - dispatched in 24 hours" with a working
    // buy button. Pre-order and Restocking soon are only ever true at zero.
    $qty   = (int) ($p['stock_qty'] ?? 0);
    $label = $p['stock_label'] ?? 'in';
    if ($qty > 0) {
        return $label === 'low' ? ['Only ' . $qty . ' left', 'tag-warn'] : ['In stock', 'tag-ok'];
    }
    return match ($label) {
        'pre'   => ['Pre-order', 'tag-warn'],
        'soon'  => ['Restocking soon', 'tag-warn'],
        default => ['Out of stock', 'tag-warn'],
    };
}

/* ------------------------------------------------------------ markup ----- */

/** The grade mark: one, two or three bars. Repeated on every card and rail. */
function grade_mark(?string $grade, bool $label = true): string
{
    if (!$grade) {
        return '';
    }
    $out = '<span class="grade grade--' . e($grade) . '"><span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span>';
    if ($label) {
        $out .= '<span class="grade__label">' . e(grade_label($grade)) . '</span>';
    }
    return $out . '</span>';
}

/** The shelf a category lives on. "ammo" is sold alongside the parts. */
function shop_path(string $category): string
{
    return ['blaster' => '/blasters', 'gear' => '/gear', 'part' => '/parts', 'ammo' => '/parts'][$category] ?? '/blasters';
}

function product_url(array $p): string
{
    return shop_path((string) $p['category']) . '/' . rawurlencode((string) $p['slug']);
}

/** One product card, as it appears on every shelf. */
function product_card(array $p): string
{
    $url = product_url($p);
    [$stock, $stockClass] = stock_note($p);

    $media = !empty($p['image'])
        ? '<img class="ph-img" src="' . e(url($p['image'])) . '" alt="' . e($p['image_alt'] ?: $p['name']) . '" loading="lazy" width="400" height="300">'
        : '<span class="ph ph--thumb"><span class="tiny">No photo yet</span></span>';

    $chips = '';
    if (!empty($p['gears'])) {
        $chips .= '<span class="chip chip--' . e($p['gears']) . '">' . e(gears_label($p['gears'])) . '</span>';
    }
    if ($fps = fps_label($p)) {
        $chips .= '<span class="chip">' . e($fps) . '</span>';
    }
    if (!empty($p['gearbox'])) {
        $chips .= '<span class="chip">' . e($p['gearbox']) . '</span>';
    }
    if (!empty($p['fits'])) {
        $chips .= '<span class="chip">Fits ' . e($p['fits']) . '</span>';
    }

    $was = !empty($p['was_paise']) && $p['was_paise'] > $p['price_paise']
        ? ' <s class="tiny muted">' . money((int) $p['was_paise']) . '</s>'
        : '';

    return '<a class="prod" href="' . e($url) . '">'
        . '<span class="prod__media">' . $media
        . (!empty($p['grade']) ? '<span class="prod__flag grade--badge">' . grade_mark($p['grade']) . '</span>' : '')
        . '</span>'
        . '<span class="prod__body">'
        . (!empty($p['brand']) ? '<span class="prod__brand">' . e($p['brand']) . '</span>' : '')
        . '<span class="prod__name">' . e($p['name']) . '</span>'
        . ($chips ? '<span class="chips">' . $chips . '</span>' : '')
        . '<span class="prod__foot"><span class="price">' . money((int) $p['price_paise']) . $was . '</span>'
        . '<span class="' . $stockClass . '">' . e($stock) . '</span></span>'
        . '</span></a>';
}

/**
 * A URL for this shelf with one filter value flipped on or off. Used by the
 * rail so every checkbox is also a plain link for anything without JavaScript.
 */
function shop_url(array $f, string $key, ?string $value = null): string
{
    $query = [];
    foreach (['type', 'grade', 'gears', 'gear_group', 'part_group', 'brand'] as $k) {
        if ($f[$k] !== []) {
            $query[$k] = $f[$k];
        }
    }
    foreach (['fps_min', 'fps_max', 'price_max'] as $k) {
        if ($f[$k] !== null) {
            $query[$k] = $f[$k];
        }
    }
    if ($f['in_stock']) {
        $query['in_stock'] = 1;
    }
    if ($f['q'] !== '') {
        $query['q'] = $f['q'];
    }
    if ($f['sort'] !== 'featured') {
        $query['sort'] = $f['sort'];
    }

    if ($key !== '' && $value !== null) {
        $current = (array) ($query[$key] ?? []);
        $query[$key] = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];
        if ($query[$key] === []) {
            unset($query[$key]);
        }
    } elseif ($key === 'clear') {
        $query = [];
    }

    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return $query === [] ? $path : $path . '?' . http_build_query($query);
}

/** One checkbox in a filter rail, with its count. */
function shop_option(array $f, string $key, string $value, string $label, array $counts): string
{
    $n = (int) ($counts[$value] ?? 0);
    $on = in_array($value, $f[$key], true);

    return '<label class="checkline' . ($n === 0 && !$on ? ' checkline--none' : '') . '">'
        . '<input type="checkbox" name="' . e($key) . '[]" value="' . e($value) . '"' . ($on ? ' checked' : '') . '>'
        . '<span class="name">' . $label . '</span>'
        . '<span class="count">' . $n . '</span></label>';
}
