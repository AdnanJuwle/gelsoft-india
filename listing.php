<?php
/**
 * One marketplace listing. Reached as /marketplace/<slug>.
 */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/offers.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';
// For SALE_CONFIRM_HOURS, which the buyer-protection list quotes.
require __DIR__ . '/inc/listing_sales.php';
require __DIR__ . '/inc/messages.php';

$me   = current_user();
$slug = (string) ($_GET['slug'] ?? '');
$listing = $slug !== '' ? listing_by_slug($slug) : null;

if (!$listing || !listing_visible($listing, $me)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$images  = listing_images((int) $listing['id']);
$mine    = $me && (int) $listing['seller_id'] === (int) $me['id'];
$live    = $listing['status'] === 'live';
$price   = (int) $listing['price_paise'];
$postage = (int) $listing['postage_paise'];

$with    = buyer_total($price, $postage, true);
$without = buyer_total($price, $postage, false);

// Only count a view once per session, and never the seller's own.
if ($live && !$mine && empty($_SESSION['viewed'][$listing['id']])) {
    $_SESSION['viewed'][$listing['id']] = true;
    try {
        exec_sql('UPDATE listings SET views = views + 1 WHERE id = ?', [(int) $listing['id']]);
    } catch (Throwable) {
    }
}

// ------------------------------------------------------------ structured data
// Only for a live listing. A withdrawn or sold one is noindex anyway, and
// advertising a price for something nobody can buy is the mismatch that gets
// a site's rich results turned off.
//
// The SELLER is deliberately left out. Their display name is on the page for
// a buyer to read; repeating it as machine-readable data is handing a
// stranger's name to every scraper that walks the site, and the listing works
// just as well without it.
$listing_schema = null;
if ($live) {
    $listing_schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $listing['title'],
        'description' => mb_substr((string) ($listing['description'] ?: $listing['title']), 0, 400),
        'url'         => abs_url(listing_url($listing)),
        'sku'         => $listing['slug'],
        // 'serviced' is a seller who has had it worked on, which is what
        // Refurbished means; the other three grades are plainly Used.
        'itemCondition' => $listing['item_condition'] === 'serviced'
            ? 'https://schema.org/RefurbishedCondition'
            : 'https://schema.org/UsedCondition',
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => abs_url(listing_url($listing)),
            'priceCurrency' => 'INR',
            'price'         => schema_price((int) $listing['price_paise']),
            'availability'  => 'https://schema.org/InStock',
        ],
    ];
    if ($listing['brand']) {
        $listing_schema['brand'] = ['@type' => 'Brand', 'name' => $listing['brand']];
    }
    if ($images) {
        $listing_schema['image'] = array_map(fn($i) => abs_url($i['path']), $images);
    }
}

$page = [
    'title_full' => $listing['title'] . ' — Gelsoft Marketplace',
    'desc'       => mb_substr((string) ($listing['description'] ?: $listing['title']), 0, 155),
    'nav'        => 'marketplace',
    'util'       => 'marketplace',
    'canonical'  => listing_url($listing),
    'noindex'    => !$live,
    // The seller's own first photo. A marketplace link is usually shared to
    // ask someone whether it is a good buy, and that question needs a picture.
    'image'      => $images ? $images[0]['path'] : null,
    'schema'     => $live
        ? [$listing_schema, breadcrumb_schema([
            'Home'        => '/',
            'Marketplace' => '/marketplace',
            $listing['title'] => listing_url($listing),
        ])]
        : null,
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:24px">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="/">Home</a><span>/</span>
      <a href="/marketplace">Marketplace</a><span>/</span>
      <span class="here"><?= e($listing['title']) ?></span>
    </nav>
  </div>

  <?php if (!$live): ?>
    <div class="wrap" style="padding-top:18px">
      <p class="notice notice--paper">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
        <span class="small">
          <strong><?= e(listing_status_label((string) $listing['status'])) ?>.</strong>
          <?php if ($listing['status'] === 'sold'): ?>
            This one has gone. <a href="/marketplace">See what else is listed</a>.
          <?php elseif ($listing['status'] === 'pending'): ?>
            We are looking at it. Nobody else can see this page yet.
          <?php elseif ($listing['status'] === 'rejected' && $listing['reject_note']): ?>
            <?= e($listing['reject_note']) ?>
          <?php endif; ?>
        </span>
      </p>
    </div>
  <?php endif; ?>

  <!-- Same three-part grid as a product page, and for the same reason: as a
       flex row the description and the spec table sat inside the photo column,
       so on a phone the title and the PRICE landed 859px down, under a grey
       "No photos" box and a wall of the seller's prose. -->
  <section class="wrap pdp">

    <div class="pdp__media">
      <div class="gallery" id="gal">
        <?php if ($images): ?>
          <?php foreach ($images as $i => $img): ?>
            <div data-view="v<?= $i ?>" class="gallery__view"<?= $i ? ' hidden' : '' ?>>
              <img class="ph-img" src="<?= e(url($img['path'])) ?>" alt="<?= e($img['alt'] ?: $listing['title']) ?>"<?= $i ? ' loading="lazy"' : '' ?>>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="gallery__view">
            <div class="ph"><span class="ph__name">No photos</span>
              <span class="ph__meta">A listing without photos is a listing nobody buys</span></div>
          </div>
        <?php endif; ?>
      </div>

      <?php if (count($images) > 1): ?>
        <div class="thumbs" role="group" aria-label="Photos" data-press-group data-target="gal">
          <?php foreach ($images as $i => $img): ?>
            <button class="thumb" type="button" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" data-view="v<?= $i ?>" aria-label="Photo <?= $i + 1 ?>">
              <img class="ph-img" src="<?= e(url($img['path'])) ?>" alt="" loading="lazy" width="80" height="60">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="pdp__detail">
      <div class="card card--pad">
        <h2 class="h-sect" style="font-size:20px;margin-bottom:12px">From the seller</h2>
        <p class="small" style="color:var(--ink-3);line-height:1.75"><?= nl2br(e((string) $listing['description'])) ?></p>

        <?php if ($listing['included']): ?>
          <h3 class="field__label" style="margin-top:18px">What is included</h3>
          <p class="small" style="color:var(--ink-3)"><?= nl2br(e((string) $listing['included'])) ?></p>
        <?php endif; ?>

        <?php if ($listing['faults']): ?>
          <h3 class="field__label" style="margin-top:18px;color:var(--warn)">Faults and wear, in their words</h3>
          <p class="small" style="color:var(--ink-3)"><?= nl2br(e((string) $listing['faults'])) ?></p>
        <?php endif; ?>
      </div>

      <table class="table table--spec" style="margin-top:22px">
        <caption class="sr-only">Details</caption>
        <tbody>
          <?php
          $specs = [
              'Condition' => condition_label($listing['item_condition']),
              'Brand'     => $listing['brand'],
              'Grade'     => grade_label($listing['grade']),
              'Gears'     => gears_label($listing['gears']),
              'Gearbox'   => $listing['gearbox'],
              'Chrono'    => $listing['fps'] ? $listing['fps'] . ' FPS' : '',
              'Bought'    => $listing['bought_when'],
              'Handover'  => handover_label($listing['handover']),
              'Located'   => trim(($listing['city'] ?? '') . ($listing['state'] ? ', ' . $listing['state'] : ''), ', '),
          ];
          foreach ($specs as $label => $value): if (!$value) { continue; } ?>
            <tr><th scope="row"><?= e($label) ?></th><td><?= e($value) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- buy -->
    <div class="pdp__buy">
      <div class="row row--tight row--center">
        <span class="eyebrow">Second-hand</span>
        <?php if ($listing['grade']): ?><span class="grade--badge"><?= grade_mark($listing['grade']) ?></span><?php endif; ?>
      </div>

      <h1 style="font-size:clamp(25px,3.4vw,32px);font-weight:800;letter-spacing:-0.02em;margin-top:14px"><?= e($listing['title']) ?></h1>

      <div class="row row--tight" style="align-items:baseline;margin-top:16px">
        <span class="price price--lg"><?= money($price) ?></span>
        <span class="small muted">
          <?= $postage > 0 ? '+ ' . money($postage) . ' courier' : 'courier included' ?>
        </span>
      </div>

      <?php if ($live && !$mine): ?>
        <?php $my_offer = $me ? offer_agreed((int) $listing['id'], (int) $me['id']) : null; ?>
        <?php if ($my_offer): ?>
          <!-- Otherwise the only place an accepted offer exists is inside the
               conversation, and a buyer who does not reopen it pays full price. -->
          <div class="card card--pad offer-box" style="margin-top:20px">
            <h2 class="h-kicker" style="margin-bottom:8px">Your offer was accepted</h2>
            <p class="offer-box__amount"><?= money((int) $my_offer['offer_paise']) ?></p>
            <p class="tiny muted" style="margin-top:6px">
              Yours until <?= e(date('j M, H:i', strtotime((string) $my_offer['offer_expires_at']))) ?>,
              and not held &mdash; it is whoever pays first.
            </p>
          </div>
        <?php endif; ?>

        <form method="post" action="/buy" style="margin-top:22px">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_id" value="<?= (int) $listing['id'] ?>">

          <?php if ($listing['allow_inspection']): ?>
            <label class="checkline checkline--top" style="background:var(--olive-tint);border:1px solid var(--olive-line);border-radius:var(--r);padding:14px">
              <input type="checkbox" name="inspected" value="1" style="accent-color:var(--olive)"
                     data-price-switch data-with="<?= $with['total'] ?>" data-without="<?= $without['total'] ?>">
              <span class="name" style="line-height:1.55">
                <strong>Have us inspect it first</strong> &mdash; +<?= money($with['inspection']) ?>
                <span class="tiny muted" style="display:block;margin-top:4px">The seller posts it to us, we test that it feeds and fires, chrono it and photograph what we find, then send it on. If it is not what the listing says, the sale is cancelled and you are charged nothing &mdash; the inspection fee included.</span>
              </span>
            </label>
          <?php endif; ?>

          <button class="btn btn--primary btn--block" type="submit" style="margin-top:16px">
            Buy &mdash; <span data-price-out-total><?= money($without['total']) ?></span>
          </button>
          <p class="tiny muted" style="text-align:center;margin-top:8px">Payment held until you confirm it arrived.</p>
        </form>

        <form method="post" action="/listing/message" style="margin-top:10px">
          <?= csrf_field() ?>
          <input type="hidden" name="listing_id" value="<?= (int) $listing['id'] ?>">
          <button class="btn btn--block" type="submit">Message the seller</button>
        </form>
        <?php if ($listing['accepts_offers']): ?>
          <p class="tiny muted" style="text-align:center;margin-top:8px">They take offers &mdash; make one inside the conversation, so there is a record of what was agreed.</p>
        <?php endif; ?>

      <?php elseif ($mine): ?>
        <div class="card card--pad" style="margin-top:22px">
          <h2 class="h-kicker" style="margin-bottom:10px">This is your listing</h2>
          <p class="small muted"><?= (int) $listing['views'] ?> view<?= (int) $listing['views'] === 1 ? '' : 's' ?> &middot; <?= e(listing_status_label((string) $listing['status'])) ?></p>
          <div class="row row--tight" style="margin-top:14px">
            <a class="btn btn--sm" href="/sell?edit=<?= (int) $listing['id'] ?>">Edit it</a>
            <a class="btn btn--sm" href="/account">Your listings</a>
          </div>
        </div>
      <?php endif; ?>

      <ul class="list" style="margin-top:22px;padding-top:20px;border-top:1px solid #EDEAE3;font-size:13.5px">
        <li><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Your money is held by us until you confirm it arrived</li>
        <li><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> <?= SALE_CONFIRM_HOURS ?> hours to inspect it and raise a problem</li>
        <li><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Not as described? The payment never completes</li>
      </ul>

      <div class="card card--pad" style="margin-top:20px">
        <div class="row row--tight row--center">
          <span class="avatar" style="width:40px;height:40px"><?= e(mb_strtoupper(mb_substr((string) $listing['seller_name'], 0, 1))) ?></span>
          <div style="min-width:0">
            <p style="font-weight:700;font-size:15px"><?= e($listing['seller_name']) ?></p>
            <p class="tiny muted">
              <?php if ($listing['seller_verified_at']): ?>
                <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2" style="vertical-align:-2px" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M6.4 10.4l2.4 2.3 4.6-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Verified &middot;
              <?php endif; ?>
              here since <?= e(date('M Y', strtotime((string) $listing['seller_since']))) ?>
            </p>
          </div>
        </div>
        <p class="small muted" style="margin-top:14px;padding-top:14px;border-top:1px solid #EDEAE3;line-height:1.6">Most people here are selling their own kit, not running a shop. Your payment is held either way, so someone with no sales is no riskier than someone with fifty.</p>
      </div>

      <div class="card card--pad card--surface" style="margin-top:16px">
        <h2 class="h-kicker" style="margin-bottom:10px">Worth asking</h2>
        <p class="small" style="color:var(--ink-3);line-height:1.65">Ask for a short video of it firing, ask when the gearbox was last opened, and ask what the chrono read. A seller who knows all three has looked after it.</p>
      </div>
    </div>
  </section>
<?php require __DIR__ . '/inc/footer.php';
