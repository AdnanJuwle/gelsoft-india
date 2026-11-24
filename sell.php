<?php
/**
 * Create or edit a listing. `?edit=<id>` loads your own.
 *
 * A new listing goes to `pending`, not straight to `live` — everything is
 * looked at before it appears. That is the whole reason the four rules at the
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
    'gearbox' => '', 'item_condition' => 'good', 'price_paise' => '', 'postage_paise' => 18000,
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

    $was_slug = (string) ($listing['slug'] ?? '');

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
        'status'           => 'pending',
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
        $errors[] = 'Write a little more in the condition notes. Thirty characters is not enough for anyone to decide on.';
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

                // Counted against what is already on the listing, not against
                // this one submission -- otherwise the form can simply be sent
                // again to add another batch, and the cap means nothing.
                $slots = upload_slots_left('listing_images', 'listing_id', $id, 'listing');
                $cap   = (int) upload_profile('listing')['per_item'];

                for ($i = 0; $i < count($files['name']); $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($slots <= 0) {
                        $notices[] = 'A listing holds ' . $cap . ' photos. Remove one to add another'
                                   . ' -- and pick the ' . $cap . ' that show the wear, the serial'
                                   . ' and what is in the box.';
                        break;
                    }

                    $result = upload_image([
                        'name' => $files['name'][$i], 'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ], 'listing-' . $listing['slug'], 'listing');

                    if (isset($result['error'])) {
                        $notices[] = $files['name'][$i] . ': ' . $result['error'];
                        continue;
                    }
                    exec_sql('INSERT INTO listing_images (listing_id, path, alt, sort_order) VALUES (?,?,?,?)',
                        [$id, $result['path'], $listing['title'], $sort++]);
                    $slots--;
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
  <section class="wrap" style="padding-top:44px">
    <h1 class="h-page"><?= $id > 0 ? 'Edit your listing' : 'Create a listing' ?></h1>
    <p class="lead measure" style="margin-top:12px">Three photos and an honest condition note sell a blaster faster than a long description. Everything below takes about two minutes &mdash; and you do not need a shop, a GST number or a selling history. Most people here list one thing and never come back.</p>

    <ol class="grid grid--auto" style="margin-top:32px">
      <li class="card card--pad" style="border-left:3px solid var(--ember)">
        <p class="eyebrow eyebrow--ember" style="font-size:11.5px;margin-bottom:7px">Step 1 &middot; now</p>
        <h2 style="font-size:16px;font-weight:700">Describe it and price it</h2>
      </li>
      <li class="card card--pad">
        <p class="eyebrow" style="font-size:11.5px;margin-bottom:7px">Step 2</p>
        <h2 style="font-size:16px;font-weight:700;color:var(--ink-3)">We check it against the rules</h2>
      </li>
      <li class="card card--pad">
        <p class="eyebrow" style="font-size:11.5px;margin-bottom:7px">Step 3</p>
        <h2 style="font-size:16px;font-weight:700;color:var(--ink-3)">Post it, get paid on delivery</h2>
      </li>
    </ol>
  </section>

  <div class="wrap" style="padding-top:20px">
    <?php if (isset($_GET['sent'])): ?>
      <p class="notice notice--olive" role="status">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="small"><strong>Sent for review.</strong> We look at every listing before it goes up &mdash; usually within a day. You will see it on the marketplace once it is through.</span>
      </p>
    <?php endif; ?>

    <?php if ($locked): ?>
      <p class="notice notice--paper"><span class="small">This one has sold, so it cannot be changed.</span></p>
    <?php endif; ?>

    <?php if ($listing['status'] === 'rejected' && $listing['reject_note']): ?>
      <p class="notice notice--paper" role="alert">
        <span class="small"><strong>Sent back:</strong> <?= e($listing['reject_note']) ?> &mdash; fix it and save again.</span>
      </p>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
      <p class="notice notice--paper" role="alert" style="color:var(--bad)"><span class="small"><?= e($err) ?></span></p>
    <?php endforeach; ?>
    <?php foreach ($notices as $note): ?>
      <p class="notice notice--paper" role="status"><span class="small"><?= e($note) ?></span></p>
    <?php endforeach; ?>
  </div>

  <div class="wrap layout section" data-payout
       data-fee-pct="<?= (int) cfg('marketplace.fee_pct', 8) ?>"
       data-handling-pct="<?= (int) cfg('marketplace.handling_pct', 2) ?>"
       data-flat="<?= intdiv((int) cfg('marketplace.flat_paise', 300), 100) ?>">

    <form class="col" method="post" action="/sell<?= $id ? '?edit=' . $id : '' ?>"
          enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <fieldset class="fieldset--card">
        <legend>What are you selling?</legend>

        <div class="field">
          <label class="field__label" for="title">Listing title <span class="req">*</span></label>
          <input class="input" id="title" name="title" maxlength="160" required value="<?= e($listing['title']) ?>"
                 placeholder="e.g. TG Gen8 with 3 hi-cap mags">
          <p class="field__hint">Model, then what comes with it. Buyers search by model name.</p>
        </div>

        <div class="row row--tight">
          <div class="field" style="flex:1 1 160px;min-width:0;margin:0">
            <label class="field__label" for="brand">Brand <span class="opt">optional</span></label>
            <input class="input" id="brand" name="brand" maxlength="80" value="<?= e($listing['brand']) ?>" placeholder="TG">
          </div>
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="grade">Grade</label>
            <select class="select" id="grade" name="grade">
              <option value="">Not sure / not a blaster</option>
              <?php foreach (LISTING_ENUMS['grade'] as $g): ?>
                <option value="<?= e($g) ?>"<?= $listing['grade'] === $g ? ' selected' : '' ?>><?= e(grade_label($g)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="gears">Gears</label>
            <select class="select" id="gears" name="gears">
              <option value="">Not sure</option>
              <?php foreach (LISTING_ENUMS['gears'] as $g): ?>
                <option value="<?= e($g) ?>"<?= $listing['gears'] === $g ? ' selected' : '' ?>><?= e(gears_label($g)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row row--tight">
          <div class="field" style="flex:1 1 160px;min-width:0;margin:0">
            <label class="field__label" for="gearbox">Gearbox <span class="opt">optional</span></label>
            <input class="input" id="gearbox" name="gearbox" maxlength="80" value="<?= e($listing['gearbox']) ?>" placeholder="Gen 8">
          </div>
          <div class="field" style="flex:1 1 130px;min-width:0;margin:0">
            <label class="field__label" for="fps">Chrono <span class="opt">if known</span></label>
            <input class="input" id="fps" name="fps" type="number" min="0" max="1000" value="<?= e($listing['fps']) ?>" placeholder="238">
          </div>
          <div class="field" style="flex:1 1 160px;min-width:0;margin:0">
            <label class="field__label" for="bought_when">Bought <span class="opt">optional</span></label>
            <input class="input" id="bought_when" name="bought_when" maxlength="60" value="<?= e($listing['bought_when']) ?>" placeholder="March 2025">
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="included">What is included</label>
          <input class="input" id="included" name="included" maxlength="500" value="<?= e($listing['included']) ?>"
                 placeholder="3 hi-cap mags, battery, charger, original box">
        </div>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>Condition</legend>
        <p class="field__hint" style="margin-top:12px">Be blunt. An honest &ldquo;fair&rdquo; sells; an optimistic &ldquo;like new&rdquo; comes back as a dispute.</p>

        <div class="grid grid--auto-s" style="margin-top:18px">
          <?php foreach (['like-new' => 'Boxed or barely used. Nothing a buyer would point at.',
                          'good'     => 'Field wear, works properly, nothing needs fixing.',
                          'fair'     => 'Runs, but something is tired and you will say what.',
                          'serviced' => 'You have opened it and replaced parts. List them.'] as $c => $blurb): ?>
            <label class="radio-card">
              <input type="radio" name="item_condition" value="<?= e($c) ?>"<?= $listing['item_condition'] === $c ? ' checked' : '' ?>>
              <span style="display:flex;flex-direction:column;gap:5px">
                <strong><?= e(condition_label($c)) ?></strong><span><?= e($blurb) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="field">
          <label class="field__label" for="description">Condition notes <span class="req">*</span></label>
          <textarea class="textarea" id="description" name="description" rows="4" required
                    placeholder="Scuffs, stiff mag, what you have replaced, when the gearbox was last opened&hellip;"><?= e($listing['description']) ?></textarea>
        </div>

        <div class="field">
          <label class="field__label" for="faults">Faults and wear</label>
          <input class="input" id="faults" name="faults" maxlength="500" value="<?= e($listing['faults']) ?>"
                 placeholder="Scuff on the stock, hop-up a bit stiff&hellip;">
          <p class="field__hint">Say what is wrong with it. A listing that admits a fault sells; one that hides it gets disputed.</p>
        </div>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>Photos <span class="req">*</span></legend>
        <p class="field__hint" style="margin-top:12px">At least one, ideally three: the whole thing, the gearbox or mag well, and anything you mentioned in the notes. Daylight, no filters, your own photos.</p>

        <?php if ($images): ?>
          <div class="grid grid--auto-s" style="margin:18px 0">
            <?php foreach ($images as $img): ?>
              <div class="card card--soft" style="padding:10px">
                <img src="<?= e(url($img['path'])) ?>" alt="" style="width:100%;border-radius:8px;display:block">
                <button class="btn btn--xs btn--block" type="submit" name="action" value="delete_image" formnovalidate
                        style="margin-top:8px;color:var(--ember-dark)" data-sets="image_id:<?= (int) $img['id'] ?>">Remove</button>
              </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="image_id" id="image_id" value="">
        <?php endif; ?>

        <div class="field">
          <label class="field__label" for="photos">Add photos</label>
          <input class="input" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp"
                 multiple style="padding:10px 12px;min-height:auto">
          <p class="field__hint">Up to <?= (int) upload_profile('listing')['per_item'] ?> photos<?php
            if ($id > 0): $left = upload_slots_left('listing_images', 'listing_id', $id, 'listing');
            ?> &mdash; <?= $left > 0 ? 'room for ' . $left . ' more' : 'that is all of them; remove one to add another' ?><?php
            endif; ?>. Choose the ones that answer a buyer's questions: the wear, the serial, and
            everything that is in the box. They are re-encoded to WebP, which strips anything hidden
            in the file and keeps the page quick on mobile data. The first one is the card image.</p>
        </div>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>If the buyer asks for an inspection</legend>
        <p class="field__hint tier-intro">A buyer can pay us <?= (int) cfg('marketplace.inspection_fee_pct', 8) ?>% to check the blaster before it reaches them. <strong>It costs you nothing</strong> &mdash; your fee is the same <?= (int) cfg('marketplace.fee_pct', 8) ?>% either way &mdash; and all that changes is where you post it.</p>

        <div class="tiers">
          <div class="tier tier--static">
            <span class="tier__body">
              <span class="tier__head">
                <span class="tier__name">What you do differently</span>
                <span class="tier__rate">no extra cost</span>
              </span>
              <span class="tier__note">You courier it <strong>to us</strong> instead of to the buyer, and we tell you when the sale is made. We test that it feeds and fires, chrono it, and send it on. The payment is held the whole time, exactly as it would be otherwise.</span>
              <span class="tier__note tier__note--warn">If it does not work the way the listing describes, the sale is cancelled and it comes back to you at your cost.</span>
            </span>
          </div>
        </div>

        <label class="checkline checkline--top" style="margin-top:14px"><input type="checkbox" name="allow_inspection" value="1"<?= $listing['allow_inspection'] ? ' checked' : '' ?>> Let buyers ask for this on my listing &mdash; listings that allow it sell faster, because the buyer is not taking a stranger's word for the condition</label>
      </fieldset>

      <fieldset class="fieldset--card">
        <legend>Price and postage</legend>

        <div class="row row--tight" style="margin-top:18px">
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="price">Asking price <span class="req">*</span></label>
            <div class="input--money">
              <span>&#8377;</span>
              <input id="price" name="price" type="number" min="100" max="500000" required value="<?= e($price_r) ?>" data-payout-price>
            </div>
          </div>

          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="postage">Courier the buyer pays</label>
            <div class="input--money">
              <span>&#8377;</span>
              <input id="postage" name="postage" type="number" min="0" max="5000" value="<?= intdiv((int) ($listing['postage_paise'] ?: 0), 100) ?>" placeholder="180">
            </div>
            <p class="field__hint">Zero if you are absorbing it.</p>
          </div>

          <!-- The rail card beside this form carries the same figure, but on a
               phone the rail stacks ~3,800px below the price field, so the one
               number that answers "what do I actually get?" was invisible
               exactly while the price was being typed. Shown only where the
               rail is no longer alongside. -->
          <p class="sell__net" style="flex:1 0 100%;margin:0">
            You receive <strong data-out="net"><?= money($payout['payout']) ?></strong>
            <span class="muted">after fees, before courier</span>
          </p>

          <div class="field" style="flex:1 1 180px;min-width:0;margin:0">
            <label class="field__label" for="handover">Handover</label>
            <select class="select" id="handover" name="handover">
              <?php foreach (LISTING_ENUMS['handover'] as $h): ?>
                <option value="<?= e($h) ?>"<?= $listing['handover'] === $h ? ' selected' : '' ?>><?= e(handover_label($h)) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="field__hint">Pickup still goes through held payment.</p>
          </div>
        </div>

        <div class="row row--tight" style="margin-top:16px">
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="city">City <span class="opt">for pickup</span></label>
            <input class="input" id="city" name="city" maxlength="90" autocomplete="address-level2" value="<?= e($listing['city']) ?>">
          </div>
          <div class="field" style="flex:1 1 170px;min-width:0;margin:0">
            <label class="field__label" for="state">State</label>
            <input class="input" id="state" name="state" maxlength="90" autocomplete="address-level1" value="<?= e($listing['state']) ?>">
          </div>
        </div>

        <label class="checkline" style="margin-top:18px"><input type="checkbox" name="accepts_offers" value="1"<?= $listing['accepts_offers'] ? ' checked' : '' ?>> Accept offers from buyers</label>
      </fieldset>

      <fieldset class="fieldset--card" style="background:var(--olive-tint);border-color:var(--olive-line)">
        <legend>Before it goes live</legend>
        <p class="field__hint" style="margin-top:12px;color:var(--olive-dark)">All four are required. We check the photos against them too, and a listing that fails gets sent back with a reason rather than quietly removed.</p>

        <div style="display:flex;flex-direction:column;gap:14px;margin-top:18px">
          <label class="checkline checkline--top" style="color:var(--olive-ink)"><input type="checkbox" name="own_it" value="1" style="accent-color:var(--olive)"> I have this in hand, and the photos are mine &mdash; not stock images</label>
          <label class="checkline checkline--top" style="color:var(--olive-ink)"><input type="checkbox" name="declared" value="1" style="accent-color:var(--olive)"> Every internal upgrade is in the description, with the chrono figure if I have one</label>
          <label class="checkline checkline--top" style="color:var(--olive-ink)"><input type="checkbox" name="not_realistic" value="1" style="accent-color:var(--olive)"> It is not repainted or modified to pass as a real firearm</label>
          <label class="checkline checkline--top" style="color:var(--olive-ink)"><input type="checkbox" name="no_eyepro" value="1" style="accent-color:var(--olive)"> There is no used eye protection in this listing</label>
        </div>
      </fieldset>

      <div class="row row--tight row--center">
        <button class="btn btn--primary" type="submit" name="action" value="save">
          <?= $id > 0 ? 'Save and resubmit' : 'Send it for review' ?>
        </button>
        <?php if ($id > 0 && !$locked): ?>
          <button class="btn" type="submit" name="action" value="withdraw" formnovalidate
                  data-confirm="Take this listing down?">Take it down</button>
        <?php endif; ?>
        <a class="small" style="font-weight:600;margin-left:4px" href="/marketplace">Back to the marketplace</a>
      </div>
    </form>

    <aside class="rail" style="max-width:380px" aria-label="Payout summary">
      <div class="card card--pad" style="background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
        <h2 class="h-kicker" style="margin-bottom:20px;color:#fff">Your payout</h2>
        <dl class="payout">
          <div class="row row--between" style="gap:12px"><dt class="on-dark">Asking price</dt><dd data-out="price"><?= money($price_r * 100) ?></dd></div>
          <div class="row row--between" style="gap:12px"><dt class="on-dark">Listing fee</dt><dd class="payout__free">Free</dd></div>
          <div class="row row--between" style="gap:12px"><dt class="on-dark">Marketplace fee &mdash; <?= (int) cfg('marketplace.fee_pct', 8) ?>%</dt><dd data-out="fee">&minus;<?= money($payout['fee']) ?></dd></div>
          <div class="row row--between payout__rule" style="gap:12px"><dt class="on-dark">Payment handling</dt><dd data-out="handling">&minus;<?= money($payout['handling']) ?></dd></div>
          <div class="row row--between" style="gap:12px">
            <dt class="payout__net-label">You receive</dt>
            <dd class="payout__net" data-out="net"><?= money($payout['payout']) ?></dd>
          </div>
        </dl>
        <p class="tiny payout__note">Nothing to pay unless it sells. Courier is passed through in full.</p>
      </div>

      <div class="card card--pad" style="margin-top:20px">
        <h2 class="h-kicker" style="margin-bottom:16px">When you get paid</h2>
        <ol class="steps">
          <li><span class="n">1</span> We look at the listing, usually within a day.</li>
          <li><span class="n">2</span> Buyer pays. We hold the money &mdash; the listing shows as sold, not paid.</li>
          <li><span class="n">3</span> You courier it within three days &mdash; to the buyer, or to us if they asked for an inspection.</li>
          <li><span class="n">4</span> Buyer confirms it arrived, or the <?= SALE_CONFIRM_HOURS ?>-hour window closes quietly.</li>
          <li><span class="n">5</span> Payout lands on your UPI the next working day.</li>
        </ol>
      </div>

      <div class="card card--pad" style="margin-top:20px">
        <h2 class="h-kicker" style="margin-bottom:14px">Sells faster</h2>
        <ul class="list">
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Say what is missing, not just what is included</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Photograph the wear instead of hiding it</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Reply within a day &mdash; your response time is public</li>
          <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Leave the inspection option on &mdash; it costs you nothing, and it is what convinces a nervous buyer</li>
        </ul>
      </div>
    </aside>
  </div>
<?php require __DIR__ . '/inc/footer.php';
