<?php
/**
 * Create or edit a listing. `?edit=<id>` loads your own.
 *
 * A new listing goes to `pending`, not straight to `live` — everything is
 * looked at before it appears. That is the whole reason the rules at the
 * bottom of this form are enforceable rather than decorative.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';
require __DIR__ . '/inc/listing_sales.php';
require __DIR__ . '/inc/uploads.php';
require __DIR__ . '/inc/mail.php';

$me = require_login('/sell');

$id = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$errors = [];
$notices = [];

$listing = [
    'id' => 0, 'title' => '', 'description' => '', 'included' => '', 'faults' => '',
    'bought_when' => '', 'brand' => '', 'grade' => '', 'gears' => '', 'fps' => '',
    'gearbox' => '', 'item_condition' => 'good', 'price_paise' => '', 'postage_paise' => 0,
    'handover' => 'courier', 'city' => '', 'state' => '', 'accepts_offers' => 1,
    'allow_inspection' => 1, 'status' => 'draft', 'slug' => '', 'reject_note' => '',
];

if ($id > 0 && db_ready()) {
    $found = q1('SELECT * FROM listings WHERE id = ?', [$id]);
    // Your own listing, and nobody else's.
    if ($found && (int) $found['seller_id'] === (int) $me['id']) {
        $listing = $found;
    } else {
        $id = 0;
    }
}

$locked = $listing['status'] === 'sold';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && db_ready() && !$locked) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'withdraw' && $id > 0) {
        exec_sql("UPDATE listings SET status = 'withdrawn' WHERE id = ? AND seller_id = ? AND status <> 'sold'",
            [$id, (int) $me['id']]);
        redirect('/account');
    }

    if ($action === 'delete_image') {
        $img = q1('SELECT i.* FROM listing_images i JOIN listings l ON l.id = i.listing_id
                   WHERE i.id = ? AND l.seller_id = ?',
            [(int) ($_POST['image_id'] ?? 0), (int) $me['id']]);
        if ($img) {
            exec_sql('DELETE FROM listing_images WHERE id = ?', [$img['id']]);
            upload_delete((string) $img['path']);
        }
        redirect('/sell?edit=' . $id);
    }

    $pick = static fn(string $k, array $allowed, string $fallback = ''): string
        => in_array((string) ($_POST[$k] ?? ''), $allowed, true) ? (string) $_POST[$k] : $fallback;
    $text = static fn(string $k, int $max): string
        => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $int  = static function (string $k, int $min, int $max): ?int {
        $raw = trim((string) ($_POST[$k] ?? ''));
        if ($raw === '') {
            return null;
        }
        $v = filter_var($raw, FILTER_VALIDATE_INT);
        return $v === false ? null : max($min, min($max, $v));
    };

    $was_status = (string) $listing['status'];
    $was_slug   = (string) ($listing['slug'] ?? '');

    $listing = [
        'id'               => $id,
        'title'            => $text('title', 160),
        'description'      => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 4000),
        'included'         => $text('included', 500),
        'faults'           => $text('faults', 500),
        'bought_when'      => $text('bought_when', 60),
        'brand'            => $text('brand', 80),
        'grade'            => $pick('grade', LISTING_ENUMS['grade']),
        'gears'            => $pick('gears', LISTING_ENUMS['gears']),
        'fps'              => $int('fps', 0, 1000),
        'gearbox'          => $text('gearbox', 80),
        'item_condition'   => $pick('item_condition', LISTING_ENUMS['item_condition'], 'good'),
        'handover'         => $pick('handover', LISTING_ENUMS['handover'], 'courier'),
        'city'             => $text('city', 90),
        'state'            => $text('state', 90),
        'accepts_offers'   => isset($_POST['accepts_offers']) ? 1 : 0,
        'allow_inspection' => isset($_POST['allow_inspection']) ? 1 : 0,
        'status'           => $was_status,
        'slug'             => $was_slug,
        'reject_note'      => '',
    ];

    $rupees  = $int('price', 100, 500000);
    $postage = $int('postage', 0, 5000) ?? 0;
    $listing['price_paise']   = $rupees !== null ? $rupees * 100 : null;
    $listing['postage_paise'] = $postage * 100;

    if ($listing['title'] === '') {
        $errors[] = 'Give it a title — what it is, in a few words.';
    }
    if (mb_strlen($listing['description']) < 30) {
        $errors[] = 'Write a little more in the description. Thirty characters is not enough for anyone to decide on.';
    }
    if ($listing['price_paise'] === null) {
        $errors[] = 'Put a price on it, between ₹100 and ₹500,000.';
    }
    if ($listing['handover'] !== 'courier' && $listing['city'] === '') {
        $errors[] = 'If you will hand it over in person, say which city.';
    }

    // The four rules are checked, not decorated.
    foreach (['own_it'        => 'that it is yours and the photos are your own',
              'declared'      => 'that the upgrades and the chrono figure are in the description',
              'not_realistic' => 'that it is not repainted to pass as a real firearm',
              'no_eyepro'     => 'that there is no used eye protection in it'] as $key => $what) {
        if (empty($_POST[$key])) {
            $errors[] = 'Confirm ' . $what . '.';
        }
    }

    $existing_images = $id > 0 ? count(listing_images($id)) : 0;
    $new_images = 0;
    foreach (($_FILES['photos']['error'] ?? []) as $err) {
        if ($err !== UPLOAD_ERR_NO_FILE) {
            $new_images++;
        }
    }
    if ($existing_images + $new_images === 0) {
        $errors[] = 'Add at least one photo. A listing without photos does not sell, and we will not approve one.';
    }

    if (!$errors) {
        $listing['slug'] = $was_slug !== '' ? $was_slug : unique_slug($listing['title'], 'listings');

        // Editing sends it back for review: reviewing is pointless if the
        // text can change afterwards.
        $cols = ['title', 'description', 'included', 'faults', 'bought_when', 'brand', 'grade',
                 'gears', 'fps', 'gearbox', 'item_condition', 'price_paise', 'postage_paise',
                 'handover', 'city', 'state', 'accepts_offers', 'allow_inspection'];
        $values = array_map(static fn($c) => ($listing[$c] === '' ? null : $listing[$c]), $cols);

        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn($c) => "{$c} = ?", $cols));
                exec_sql("UPDATE listings SET {$set}, status = 'pending', reject_note = NULL
                          WHERE id = ? AND seller_id = ?",
                    [...$values, $id, (int) $me['id']]);
            } else {
                $marks = implode(',', array_fill(0, count($cols), '?'));
                exec_sql('INSERT INTO listings (slug, seller_id, status, ' . implode(',', $cols) . ")
                          VALUES (?,?,'pending',{$marks})",
                    [$listing['slug'], (int) $me['id'], ...$values]);
                $id = (int) db()->lastInsertId();
            }

            $files = $_FILES['photos'] ?? null;
            if ($files && is_array($files['name'])) {
                $sort = (int) (q1('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM listing_images WHERE listing_id = ?', [$id])['n'] ?? 0);
                for ($i = 0; $i < count($files['name']); $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $result = upload_image([
                        'name' => $files['name'][$i], 'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ], 'listing-' . $listing['slug']);

                    if (isset($result['error'])) {
                        $notices[] = $files['name'][$i] . ': ' . $result['error'];
                        continue;
                    }
                    exec_sql('INSERT INTO listing_images (listing_id, path, alt, sort_order) VALUES (?,?,?,?)',
                        [$id, $result['path'], $listing['title'], $sort++]);
                }
            }

            if (!$notices) {
                redirect('/sell?edit=' . $id . '&sent=1');
            }
        } catch (Throwable $e) {
            error_log('listing save failed: ' . $e->getMessage());
            $errors[] = 'The listing could not be saved.';
        }
    }
}

$images  = $id > 0 ? listing_images($id) : [];
$price_r = ($listing['price_paise'] !== '' && $listing['price_paise'] !== null)
    ? intdiv((int) $listing['price_paise'], 100) : 8200;
$payout  = seller_payout($price_r * 100);

$page = [
    'title'   => $id > 0 ? 'Edit your listing' : 'Create a listing',
    'desc'    => 'Sell one blaster or a cupboard of spares. Free to list, payment held until the buyer confirms, payout to UPI.',
    'nav'     => 'marketplace',
    'noindex' => true,
    'util'    => ['note' => 'Free to list &middot; payment held until the buyer confirms &middot; payout to UPI',
                  'label' => 'Back to marketplace', 'href' => '/marketplace'],
];
require __DIR__ . '/inc/header.php';
?>
