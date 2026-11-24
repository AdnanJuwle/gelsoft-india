<?php
/**
 * Add or edit a shop item, with photos. `?edit=<id>` loads an existing one.
 *
 * Everything is whitelisted on the way in: category, grade, type, gears and
 * status are checked against fixed lists, and the price is an integer number
 * of paise. A form can send anything at all.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/shop.php';
require dirname(__DIR__) . '/inc/uploads.php';
require_admin();

const CATS   = ['blaster', 'gear', 'part', 'ammo'];
const STATUS = ['draft', 'published', 'archived'];
const STOCKL = ['in', 'low', 'pre', 'soon'];

$id      = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$errors  = [];
$notices = [];

$item = [
    'id' => 0, 'category' => 'blaster', 'brand' => '', 'name' => '', 'summary' => '',
    'description' => '', 'grade' => '', 'type' => '', 'gears' => '', 'gearbox' => '',
    'fps_min' => '', 'fps_max' => '', 'battery' => '', 'magazine' => '', 'needs_to_run' => '',
    'fits' => '', 'sizes' => '', 'part_group' => '', 'gear_group' => '',
    'price_paise' => '', 'was_paise' => '', 'stock_qty' => '0', 'stock_label' => 'in',
    'status' => 'draft', 'featured' => 0, 'slug' => '',
];

if ($id > 0 && db_ready()) {
    $found = q1('SELECT * FROM products WHERE id = ?', [$id]);
    if ($found) {
        $item = $found;
    } else {
        $id = 0;
    }
}

/* ------------------------------------------------------------- actions -- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete_image') {
        $img = q1('SELECT * FROM product_images WHERE id = ?', [(int) ($_POST['image_id'] ?? 0)]);
        if ($img) {
            exec_sql('DELETE FROM product_images WHERE id = ?', [$img['id']]);
            upload_delete((string) $img['path']);
            admin_log('image_delete', (string) $img['product_id'], (string) $img['path']);
        }
        redirect('/admin/add-item?edit=' . $id . '&saved=1');
    }

    if ($action === 'delete_item' && $id > 0) {
        foreach (product_images($id) as $img) {
            upload_delete((string) $img['path']);
        }
        exec_sql('DELETE FROM products WHERE id = ?', [$id]);
        admin_log('product_delete', (string) $id, (string) $item['name']);
        redirect('/admin/?deleted=1');
    }

    // --- collect -----------------------------------------------------------
    $pick = static fn(string $key, array $allowed, string $fallback = ''): string
        => in_array((string) ($_POST[$key] ?? ''), $allowed, true) ? (string) $_POST[$key] : $fallback;

    $text = static fn(string $key, int $max): string
        => mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);

    $intOrNull = static function (string $key, int $min, int $max): ?int {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        $v = filter_var($raw, FILTER_VALIDATE_INT);
        return $v === false ? null : max($min, min($max, $v));
    };

    $item = [
        'id'           => $id,
        'category'     => $pick('category', CATS, 'blaster'),
        'brand'        => $text('brand', 80),
        'name'         => $text('name', 160),
        'summary'      => $text('summary', 255),
        'description'  => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 8000),
        'grade'        => $pick('grade', SHOP_ENUMS['grade']),
        'type'         => $pick('type', SHOP_ENUMS['type']),
        'gears'        => $pick('gears', SHOP_ENUMS['gears']),
        'gearbox'      => $text('gearbox', 80),
        'fps_min'      => $intOrNull('fps_min', 0, 1000),
        'fps_max'      => $intOrNull('fps_max', 0, 1000),
        'battery'      => $text('battery', 120),
        'magazine'     => $text('magazine', 120),
        'needs_to_run' => $text('needs_to_run', 255),
        'fits'         => $text('fits', 160),
        'sizes'        => $text('sizes', 80),
        'part_group'   => $pick('part_group', SHOP_ENUMS['part_group']),
        'gear_group'   => $pick('gear_group', SHOP_ENUMS['gear_group']),
        'stock_qty'    => $intOrNull('stock_qty', 0, 100000) ?? 0,
        'stock_label'  => $pick('stock_label', STOCKL, 'in'),
        'status'       => $pick('status', STATUS, 'draft'),
        'featured'     => isset($_POST['featured']) ? 1 : 0,
        'slug'         => $text('slug', 140),
    ];

    // Rupees in the form, paise in the database. One multiplication, in one
    // place, so no page has to remember which unit it is holding.
    $rupees   = $intOrNull('price', 0, 10000000);
    $wasRupee = $intOrNull('was', 0, 10000000);
    $item['price_paise'] = $rupees !== null ? $rupees * 100 : null;
    $item['was_paise']   = $wasRupee ? $wasRupee * 100 : null;

    // --- check -------------------------------------------------------------
    if ($item['name'] === '') {
        $errors[] = 'The item needs a name.';
    }
    if ($item['price_paise'] === null) {
        $errors[] = 'Put a price in, even a rough one. You can change it later.';
    }
    if ($item['fps_min'] !== null && $item['fps_max'] !== null && $item['fps_min'] > $item['fps_max']) {
        $errors[] = 'The lower chrono figure is higher than the upper one.';
    }
    if ($item['was_paise'] !== null && $item['price_paise'] !== null
        && $item['was_paise'] <= $item['price_paise']) {
        $errors[] = 'The "was" price has to be higher than the price, or there is no saving to show.';
    }
    if ($item['category'] === 'blaster' && $item['grade'] === '') {
        $errors[] = 'Blasters need a grade — it is on every card and in every filter.';
    }

    // --- save --------------------------------------------------------------
    if (!$errors) {
        $item['slug'] = unique_slug($item['slug'] !== '' ? $item['slug'] : $item['name'], 'products', $id ?: null);

        $cols = ['slug', 'category', 'brand', 'name', 'summary', 'description', 'grade', 'type',
                 'gears', 'gearbox', 'fps_min', 'fps_max', 'battery', 'magazine', 'needs_to_run',
                 'fits', 'sizes', 'part_group', 'gear_group', 'price_paise', 'was_paise',
                 'stock_qty', 'stock_label', 'status', 'featured'];

        // '' becomes NULL: an enum column will not accept an empty string, and
        // a blank brand means "no brand", not a brand named "".
        $values = array_map(static fn($c) => ($item[$c] === '' ? null : $item[$c]), $cols);

        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($c) => "{$c} = ?", $cols));
                exec_sql("UPDATE products SET {$set} WHERE id = ?", [...$values, $id]);
                admin_log('product_edit', (string) $id, $item['name']);
            } else {
                $marks = implode(',', array_fill(0, count($cols), '?'));
                exec_sql('INSERT INTO products (' . implode(',', $cols) . ") VALUES ({$marks})", $values);
                $id = (int) db()->lastInsertId();
                $item['id'] = $id;
                admin_log('product_add', (string) $id, $item['name']);
            }

            // --- photos ---
            $files = $_FILES['photos'] ?? null;
            if ($files && is_array($files['name'])) {
                $sort = (int) (q1('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM product_images WHERE product_id = ?', [$id])['n'] ?? 0);

                // Counted against what the item already has, not against this
                // submission -- saving again would otherwise add another batch.
                $slots = upload_slots_left('product_images', 'product_id', $id, 'product');
                $cap   = (int) upload_profile('product')['per_item'];

                for ($i = 0; $i < count($files['name']); $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($slots <= 0) {
                        $notices[] = 'An item shows ' . $cap . ' photos. Remove one to add another.';
                        break;
                    }

                    $result = upload_image([
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    ], (string) $item['slug'], 'product');

                    if (isset($result['error'])) {
                        $notices[] = $files['name'][$i] . ': ' . $result['error'];
                        continue;
                    }
                    exec_sql('INSERT INTO product_images (product_id, path, alt, sort_order) VALUES (?, ?, ?, ?)',
                        [$id, $result['path'], $item['name'], $sort++]);
                    $slots--;
                }
            }

            if (!$notices) {
                redirect('/admin/add-item?edit=' . $id . '&saved=1');
            }
        } catch (Throwable $e) {
            error_log('product save failed: ' . $e->getMessage());
            $errors[] = 'The item could not be saved. The reason is in the server error log.';
        }
    }
}

$images = $id > 0 ? product_images($id) : [];

$page = ['title' => $id > 0 ? 'Edit item' : 'Add an item'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell">
  <main class="admin-main" style="width:100%">
    <div class="admin-head">
      <div>
        <h1><?= $id > 0 ? 'Edit item' : 'Add an item' ?></h1>
        <p>It appears on the shop the moment its status is <strong>Published</strong>. Brand is optional &mdash; plenty of what sells here has no brand worth printing.</p>
      </div>
      <div class="row row--tight">
        <?php if ($id > 0 && $item['status'] === 'published'): ?>
          <a class="btn btn--sm" href="<?= e(shop_path((string) $item['category']) . '/' . rawurlencode((string) $item['slug'])) ?>">View live</a>
        <?php endif; ?>
        <a class="btn btn--sm" href="/admin/">Back</a>
      </div>
    </div>

    <?php if (isset($_GET['saved']) && !$errors && !$notices): ?>
      <p class="notice notice--olive" role="status"><span class="small">Saved.<?= $item['status'] === 'published' ? ' It is live on the shop now.' : ' It is a draft, so nobody can see it yet.' ?></span></p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($err) ?></span></p>
    <?php endforeach; ?>
    <?php foreach ($notices as $note): ?>
      <p class="notice notice--paper" role="status"><span class="small"><?= e($note) ?></span></p>
    <?php endforeach; ?>

    <?php if (!db_ready()): ?>
      <div class="card card--pad-lg">
        <p class="small"><strong>No database.</strong> Nothing can be saved until <code>inc/config.php</code> points at one.</p>
      </div>
      <?php require dirname(__DIR__) . '/inc/admin_footer.php'; exit; ?>
    <?php endif; ?>

    <form class="row row--top" method="post" action="/admin/add-item<?= $id ? '?edit=' . $id : '' ?>"
          enctype="multipart/form-data" style="gap:28px;align-items:flex-start">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <div class="col" style="flex:999 1 460px;min-width:0;display:flex;flex-direction:column;gap:20px">

        <fieldset class="fieldset--card">
          <legend>The basics</legend>

          <div class="row row--tight" style="margin-top:4px">
            <div class="field" style="flex:1 1 200px;margin:0">
              <label class="field__label" for="category">Category</label>
              <select class="select" id="category" name="category" data-controls="specs-scope">
                <option value="blaster"<?= $item['category'] === 'blaster' ? ' selected' : '' ?>>Blaster</option>
                <option value="gear"<?= $item['category'] === 'gear' ? ' selected' : '' ?>>Gear</option>
                <option value="part"<?= $item['category'] === 'part' ? ' selected' : '' ?>>Part or upgrade</option>
                <option value="ammo"<?= $item['category'] === 'ammo' ? ' selected' : '' ?>>Mags &amp; ammo</option>
              </select>
            </div>
            <div class="field" style="flex:1 1 200px;margin:0">
              <label class="field__label" for="brand">Brand <span class="opt">optional</span></label>
              <input class="input" id="brand" name="brand" maxlength="80" value="<?= e($item['brand']) ?>"
                     placeholder="TG, Bohan, SBL, Jieying, Handi&hellip;">
            </div>
          </div>

          <div class="field">
            <label class="field__label" for="name">Item name <span class="req">*</span></label>
            <input class="input" id="name" name="name" maxlength="160" required value="<?= e($item['name']) ?>"
                   placeholder="Gen8 &mdash; M4 pattern">
          </div>

          <div class="field">
            <label class="field__label" for="summary">One line for the card</label>
            <input class="input" id="summary" name="summary" maxlength="255" value="<?= e($item['summary']) ?>"
                   placeholder="230&ndash;250 FPS &middot; metal gears &middot; Gen 8 gearbox">
          </div>

          <div class="field">
            <label class="field__label" for="description">Description</label>
            <textarea class="textarea" id="description" name="description" rows="14" aria-describedby="description-hint"
                      placeholder="What it is, who it suits, what it comes with, anything you would tell someone in person."><?= e($item['description']) ?></textarea>
            <p class="field__hint" id="description-hint">Formatting works the way it does in most chat apps:
              <code>**bold**</code>, <code>*italic*</code>, <code>### Heading</code> on its own line,
              <code>- item</code> for a list, <code>[text](https://&hellip;)</code> for a link, and a blank line for a new paragraph.
              A table is rows of <code>| a | b |</code> with <code>|---|---|</code> as the second line.
              No need for a heading with the product&rsquo;s name: the page already shows it.</p>
          </div>

          <div class="field">
            <label class="field__label" for="slug">URL <span class="opt">leave blank and we make one from the name</span></label>
            <input class="input" id="slug" name="slug" maxlength="140" value="<?= e($item['slug']) ?>"
                   placeholder="tg-gen8-m4">
            <p class="field__hint">Changing this breaks any link already shared to the old one.</p>
          </div>
        </fieldset>

        <div id="specs-scope">
          <fieldset class="fieldset--card" data-when="blaster"<?= $item['category'] === 'blaster' ? '' : ' hidden' ?>>
            <legend>Blaster specs</legend>

            <div class="row row--tight" style="margin-top:4px">
              <div class="field" style="flex:1 1 160px;margin:0">
                <label class="field__label" for="grade">Grade <span class="req">*</span></label>
                <select class="select" id="grade" name="grade" data-preview="pv-grade" data-preview-mode="grade">
                  <option value="">Pick one</option>
                  <option value="toy"<?= $item['grade'] === 'toy' ? ' selected' : '' ?>>Toy grade</option>
                  <option value="mid"<?= $item['grade'] === 'mid' ? ' selected' : '' ?>>Mid hobby grade</option>
                  <option value="premium"<?= $item['grade'] === 'premium' ? ' selected' : '' ?>>Premium grade</option>
                </select>
              </div>
              <div class="field" style="flex:1 1 160px;margin:0">
                <label class="field__label" for="type">Type</label>
                <select class="select" id="type" name="type">
                  <option value="">Not set</option>
                  <?php foreach (SHOP_ENUMS['type'] as $t): ?>
                    <option value="<?= e($t) ?>"<?= $item['type'] === $t ? ' selected' : '' ?>><?= e(type_label($t)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" style="flex:1 1 160px;margin:0">
                <label class="field__label" for="gears">Gears</label>
                <select class="select" id="gears" name="gears" data-preview="pv-gears" data-preview-mode="chip">
                  <option value="">Not set</option>
                  <?php foreach (SHOP_ENUMS['gears'] as $g): ?>
                    <option value="<?= e($g) ?>"<?= $item['gears'] === $g ? ' selected' : '' ?>><?= e(gears_label($g)) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field__hint">The gears, not the gearbox.</p>
              </div>
            </div>

            <div class="row row--tight">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="gearbox">Gearbox</label>
                <input class="input" id="gearbox" name="gearbox" maxlength="80" value="<?= e($item['gearbox']) ?>"
                       placeholder="Gen 8 &middot; V2 &middot; metal shell">
              </div>
              <div class="field" style="flex:1 1 90px;margin:0">
                <label class="field__label" for="fps_min">FPS from</label>
                <input class="input" id="fps_min" name="fps_min" type="number" min="0" max="1000"
                       value="<?= e($item['fps_min']) ?>" placeholder="230">
              </div>
              <div class="field" style="flex:1 1 90px;margin:0">
                <label class="field__label" for="fps_max">FPS to</label>
                <input class="input" id="fps_max" name="fps_max" type="number" min="0" max="1000"
                       value="<?= e($item['fps_max']) ?>" placeholder="250">
              </div>
            </div>

            <div class="row row--tight">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="battery">Battery</label>
                <input class="input" id="battery" name="battery" maxlength="120" value="<?= e($item['battery']) ?>"
                       placeholder="7.4V or 11.1V LiPo, T-plug">
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="magazine">Magazine</label>
                <input class="input" id="magazine" name="magazine" maxlength="120" value="<?= e($item['magazine']) ?>"
                       placeholder="One hi-cap">
              </div>
            </div>

            <div class="field">
              <label class="field__label" for="needs_to_run">What it needs to run</label>
              <input class="input" id="needs_to_run" name="needs_to_run" maxlength="255"
                     value="<?= e($item['needs_to_run']) ?>" placeholder="Battery and charger, eye protection, gel">
            </div>
          </fieldset>

          <fieldset class="fieldset--card" data-when="gear"<?= $item['category'] === 'gear' ? '' : ' hidden' ?>>
            <legend>Gear details</legend>
            <div class="row row--tight" style="margin-top:4px">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="gear_group">Section</label>
                <select class="select" id="gear_group" name="gear_group">
                  <option value="">Not set</option>
                  <?php foreach (['eye' => 'Eye & face', 'head' => 'Head', 'body' => 'Body',
                                  'hands' => 'Hands', 'carry' => 'Carry & holsters', 'legs' => 'Legs'] as $k => $v): ?>
                    <option value="<?= e($k) ?>"<?= $item['gear_group'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="sizes">Sizes</label>
                <input class="input" id="sizes" name="sizes" maxlength="80" value="<?= e($item['sizes']) ?>"
                       placeholder="One size &middot; S&ndash;XL">
              </div>
            </div>
          </fieldset>

          <fieldset class="fieldset--card" data-when="part"<?= $item['category'] === 'part' ? '' : ' hidden' ?>>
            <legend>Part details</legend>
            <div class="row row--tight" style="margin-top:4px">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="part_group">Section</label>
                <select class="select" id="part_group" name="part_group">
                  <option value="">Not set</option>
                  <?php foreach (['internals' => 'Internals', 'electrical' => 'Electrical', 'optics' => 'Optics',
                                  'externals' => 'Externals', 'mags' => 'Magazines', 'gel' => 'Gel & ammo'] as $k => $v): ?>
                    <option value="<?= e($k) ?>"<?= $item['part_group'] === $k ? ' selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="fits">Fits</label>
                <input class="input" id="fits" name="fits" maxlength="160" value="<?= e($item['fits']) ?>"
                       placeholder="gen8, gen9, v2 &mdash; or any">
                <p class="field__hint">Comma separated, lowercase. <code>any</code> shows on every platform.</p>
              </div>
            </div>
          </fieldset>

          <fieldset class="fieldset--card" data-when="ammo"<?= $item['category'] === 'ammo' ? '' : ' hidden' ?>>
            <legend>Mags &amp; ammo details</legend>
            <div class="row row--tight" style="margin-top:4px">
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="ammo_sizes">Size or count</label>
                <input class="input" id="ammo_sizes" name="sizes" maxlength="80" value="<?= e($item['sizes']) ?>"
                       placeholder="7&ndash;8 mm &middot; 10,000 rounds">
              </div>
              <div class="field" style="flex:1 1 200px;margin:0">
                <label class="field__label" for="ammo_fits">Fits</label>
                <input class="input" id="ammo_fits" name="fits" maxlength="160" value="<?= e($item['fits']) ?>"
                       placeholder="any">
              </div>
            </div>
          </fieldset>
        </div>

        <fieldset class="fieldset--card">
          <legend>Price and stock</legend>
          <div class="row row--tight" style="margin-top:4px">
            <div class="field" style="flex:1 1 150px;margin:0">
              <label class="field__label" for="price">Price <span class="req">*</span></label>
              <div class="input--money"><span>&#8377;</span>
                <input id="price" name="price" type="number" min="0" max="10000000" required
                       value="<?= ($item['price_paise'] !== '' && $item['price_paise'] !== null) ? intdiv((int) $item['price_paise'], 100) : '' ?>"
                       placeholder="12500">
              </div>
              <p class="field__hint">In rupees. We store paise.</p>
            </div>
            <div class="field" style="flex:1 1 150px;margin:0">
              <label class="field__label" for="was">Was <span class="opt">optional</span></label>
              <div class="input--money"><span>&#8377;</span>
                <input id="was" name="was" type="number" min="0" max="10000000"
                       value="<?= $item['was_paise'] ? intdiv((int) $item['was_paise'], 100) : '' ?>" placeholder="14000">
              </div>
              <p class="field__hint">Shows struck through.</p>
            </div>
            <div class="field" style="flex:1 1 110px;margin:0">
              <label class="field__label" for="stock_qty">In stock</label>
              <input class="input" id="stock_qty" name="stock_qty" type="number" min="0" max="100000"
                     value="<?= e($item['stock_qty']) ?>">
            </div>
            <div class="field" style="flex:1 1 170px;margin:0">
              <label class="field__label" for="stock_label">Shows as</label>
              <select class="select" id="stock_label" name="stock_label">
                <option value="in"<?= $item['stock_label'] === 'in' ? ' selected' : '' ?>>In stock</option>
                <option value="low"<?= $item['stock_label'] === 'low' ? ' selected' : '' ?>>Low stock</option>
                <option value="pre"<?= $item['stock_label'] === 'pre' ? ' selected' : '' ?>>Pre-order</option>
                <option value="soon"<?= $item['stock_label'] === 'soon' ? ' selected' : '' ?>>Restocking soon</option>
              </select>
              <p class="field__hint">Pre-order and Restocking soon only show while In stock is 0. With stock on the shelf it always reads In stock (or &ldquo;Only N left&rdquo; for Low stock).</p>
            </div>
          </div>

          <div class="row row--tight" style="margin-top:16px">
            <div class="field" style="flex:1 1 220px;margin:0">
              <label class="field__label" for="status">Status</label>
              <select class="select" id="status" name="status">
                <option value="draft"<?= $item['status'] === 'draft' ? ' selected' : '' ?>>Draft &mdash; nobody sees it</option>
                <option value="published"<?= $item['status'] === 'published' ? ' selected' : '' ?>>Published &mdash; live on the shop</option>
                <option value="archived"<?= $item['status'] === 'archived' ? ' selected' : '' ?>>Archived &mdash; taken down</option>
              </select>
            </div>
            <label class="checkline" style="align-self:flex-end;padding-bottom:12px">
              <input type="checkbox" name="featured" value="1"<?= $item['featured'] ? ' checked' : '' ?>>
              <span class="name">Feature it &mdash; shows first, and on the home page</span>
            </label>
          </div>
        </fieldset>

        <fieldset class="fieldset--card">
          <legend>Photos</legend>

          <?php if ($images): ?>
            <div class="grid grid--auto-s" style="margin:14px 0 18px">
              <?php foreach ($images as $img): ?>
                <div class="card card--soft" style="padding:10px">
                  <img src="<?= e(url($img['path'])) ?>" alt="" style="width:100%;border-radius:8px;display:block">
                  <button class="btn btn--xs btn--block" type="submit" name="action" value="delete_image"
                          formnovalidate style="margin-top:8px;color:var(--ember-dark)"
                          data-sets="image_id:<?= (int) $img['id'] ?>">Remove</button>
                </div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="image_id" id="image_id" value="">
          <?php endif; ?>

          <div class="field">
            <label class="field__label" for="photos">Add photos</label>
            <input class="input" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp"
                   multiple style="padding:10px 12px;min-height:auto">
            <p class="field__hint">JPEG, PNG or WebP, up to 5&nbsp;MB each, <?= (int) upload_profile('product')['per_item'] ?> per item<?php
              if ($id > 0): $left = upload_slots_left('product_images', 'product_id', $id, 'product');
              ?> &mdash; <?= $left > 0 ? 'room for ' . $left . ' more' : 'full; remove one to add another' ?><?php
              endif; ?>. They are re-encoded to WebP and capped at <?= (int) upload_profile('product')['max'] ?>px, which strips anything hidden in the file and keeps the page quick. The first one is the card image.</p>
          </div>
        </fieldset>

        <div class="row row--tight row--center">
          <button class="btn btn--primary" type="submit" name="action" value="save">
            <?= $id > 0 ? 'Save changes' : 'Add the item' ?>
          </button>
          <a class="btn" href="/admin/">Cancel</a>
          <?php if ($id > 0): ?>
            <span style="flex-grow:1"></span>
            <button class="btn btn--xs" type="submit" name="action" value="delete_item" formnovalidate
                    style="color:var(--bad)"
                    data-confirm="Delete this item and its photos? That cannot be undone.">
              Delete this item
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- live preview -->
      <aside class="rail" style="max-width:300px" aria-label="Preview">
        <h2 class="h-kicker" style="margin-bottom:14px">How the card will look</h2>
        <div class="prod" style="pointer-events:none">
          <span class="prod__media">
            <?php if ($images): ?>
              <img class="ph-img" src="<?= e(url($images[0]['path'])) ?>" alt="">
            <?php else: ?>
              <span class="ph ph--thumb"><span class="tiny">No photo yet</span></span>
            <?php endif; ?>
            <span class="prod__flag grade--badge" id="pv-grade"><?= grade_mark($item['grade'] ?: null) ?></span>
          </span>
          <span class="prod__body">
            <span class="prod__brand"><?= e($item['brand']) ?></span>
            <span class="prod__name"><?= e($item['name'] ?: 'Item name') ?></span>
            <span class="chips"><span class="chip chip--<?= e($item['gears'] ?: 'nylon') ?>" id="pv-gears"><?= e(gears_label($item['gears']) ?: 'Gears') ?></span></span>
            <span class="prod__foot">
              <span class="price"><?= $item['price_paise'] ? money((int) $item['price_paise']) : '&#8377;0' ?></span>
              <span class="tag-ok"><?= e(stock_note($item)[0]) ?></span>
            </span>
          </span>
        </div>
        <p class="tiny muted" style="margin-top:14px">The preview updates as you pick a grade or gears. Nothing is live until you save, and a draft shows nowhere at all.</p>
      </aside>
    </form>
  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
