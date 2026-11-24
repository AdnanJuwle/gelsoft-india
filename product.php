<?php
/**
 * One product. Reached as /blasters/<slug>, /gear/<slug> or /parts/<slug>;
 * .htaccess rewrites those onto this file.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require_once __DIR__ . '/inc/richtext.php';

$slug    = (string) ($_GET['slug'] ?? '');
$product = $slug !== '' ? product_by_slug($slug) : null;

if (!$product) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// The shelf it lives on decides the breadcrumb and which nav item is current.
$shelf = shop_path((string) $product['category']);
$shelfName = ['/blasters' => 'Blasters', '/gear' => 'Gear', '/parts' => 'Parts'][$shelf] ?? 'Shop';
$navKey = ['/blasters' => 'blasters', '/gear' => 'gear', '/parts' => 'parts'][$shelf] ?? '';

$images  = product_images((int) $product['id']);
$related = products_related($product);

$fps = fps_label($product);
[$stockText, $stockClass] = stock_note($product);
$inStock = (int) $product['stock_qty'] > 0;

// ------------------------------------------------------------ structured data
// The breadcrumb trail, built to match the one the page draws below -- the
// optional type level included, because a trail that disagrees with the
// visible one is the kind of mismatch Google drops the rich result for.
$trail = ['Home' => '/', $shelfName => $shelf];
if (!empty($product['type'])) {
    $trail[type_label($product['type'])] = $shelf . '?type%5B%5D=' . $product['type'];
}
$trail[$product['name']] = product_url($product);

// Availability has to say what the page says. stock_label 'pre' is a model
// that has not landed yet, which is PreOrder rather than OutOfStock.
$availability = $inStock
    ? 'https://schema.org/InStock'
    : ($product['stock_label'] === 'pre' ? 'https://schema.org/PreOrder' : 'https://schema.org/OutOfStock');

$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $product['name'],
    'description' => (string) ($product['summary'] ?: richtext_plain((string) $product['description'])),
    'url'         => abs_url(product_url($product)),
    'sku'         => $product['slug'],
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => abs_url(product_url($product)),
        'priceCurrency' => 'INR',
        'price'         => schema_price((int) $product['price_paise']),
        'availability'  => $availability,
        'itemCondition' => 'https://schema.org/NewCondition',
    ],
];
if ($product['brand']) {
    $schema['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
}
// Only real photos. Pointing a crawler at the site's generic card and calling
// it the product would be a picture of the wrong thing.
if ($images) {
    $schema['image'] = array_map(fn($i) => abs_url($i['path']), $images);
}

$page = [
    'title'     => $product['name'],
    'desc'      => $product['summary'] ?: mb_substr(richtext_plain((string) $product['description']), 0, 155),
    'nav'       => $navKey,
    'canonical' => product_url($product),
    // The first photo, so a link shared into a WhatsApp group shows the
    // blaster rather than the site's generic card.
    'image'     => $images ? $images[0]['path'] : null,
    'schema'    => [$schema, breadcrumb_schema($trail)],
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:24px">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="/">Home</a><span>/</span>
      <a href="<?= e($shelf) ?>"><?= e($shelfName) ?></a><span>/</span>
      <?php if (!empty($product['type'])): ?>
        <a href="<?= e($shelf) ?>?type%5B%5D=<?= e($product['type']) ?>"><?= e(type_label($product['type'])) ?></a><span>/</span>
      <?php endif; ?>
      <span class="here"><?= e($product['name']) ?></span>
    </nav>
  </div>

  <section class="wrap pdp">

    <!-- gallery -->
    <div class="pdp__media">
      <div class="gallery" id="gal">
        <?php if ($images): ?>
          <?php foreach ($images as $i => $img): ?>
            <div data-view="v<?= $i ?>" class="gallery__view"<?= $i ? ' hidden' : '' ?>>
              <img class="ph-img" src="<?= e(url($img['path'])) ?>" alt="<?= e($img['alt'] ?: $product['name']) ?>" width="1400" height="1050"<?= $i ? ' loading="lazy"' : '' ?>>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="gallery__view">
            <div class="ph">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span class="ph__name">No photo yet</span>
              <span class="ph__meta">Add one from the admin panel &mdash; 1400 &times; 1050, plain background</span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <?php if (count($images) > 1): ?>
        <div class="thumbs" role="group" aria-label="Views" data-press-group data-target="gal">
          <?php foreach ($images as $i => $img): ?>
            <button class="thumb" type="button" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" data-view="v<?= $i ?>" aria-label="View <?= $i + 1 ?>">
              <img class="ph-img" src="<?= e(url($img['path'])) ?>" alt="" loading="lazy" width="80" height="60">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- buy -->
    <div class="pdp__buy">
      <div class="row row--tight row--center">
        <?php if ($product['brand'] || $product['type']): ?>
          <span class="eyebrow"><?= e(implode(' · ', array_filter([$product['brand'], type_label($product['type'])]))) ?></span>
        <?php endif; ?>
        <?php if ($product['grade']): ?>
          <span class="grade--badge"><?= grade_mark($product['grade']) ?></span>
        <?php endif; ?>
      </div>

      <h1 style="font-size:clamp(28px,4vw,38px);font-weight:800;letter-spacing:-0.02em;margin-top:14px"><?= e($product['name']) ?></h1>

      <div class="row row--tight" style="align-items:baseline;margin-top:18px">
        <span class="price price--lg"><?= money((int) $product['price_paise']) ?></span>
        <?php if (!empty($product['was_paise']) && $product['was_paise'] > $product['price_paise']): ?>
          <s class="muted"><?= money((int) $product['was_paise']) ?></s>
        <?php endif; ?>
        <span class="chip chip--pill <?= $stockClass === 'tag-ok' ? 'chip--ok' : '' ?>"><?= e($stockText) ?><?= $inStock ? ' &middot; dispatched in 24 hours' : '' ?></span>
      </div>

      <?php if ($product['summary']): ?>
        <p style="color:var(--ink-3);line-height:1.7;margin-top:20px"><?= e($product['summary']) ?></p>
      <?php endif; ?>

      <div class="row row--tight row--center" style="margin-top:26px">
        <?php if ($inStock): ?>
          <form class="row row--tight row--center" method="post" action="/api/cart"
                data-add-to-cart style="flex:1 1 100%;gap:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="add">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <input type="hidden" name="return" value="<?= e(product_url($product)) ?>">

            <div class="stepper" data-stepper data-price="<?= intdiv((int) $product['price_paise'], 100) ?>" data-total="line-total" data-max="<?= min(9, (int) $product['stock_qty']) ?>" data-field="qty">
              <button type="button" data-step="-1" aria-label="Decrease quantity">&minus;</button>
              <output aria-live="polite">1</output>
              <button type="button" data-step="1" aria-label="Increase quantity">+</button>
              <input type="hidden" name="qty" value="1">
            </div>

            <button class="btn btn--primary" type="submit" style="flex:1 1 200px">Add to cart &mdash; <span id="line-total"><?= money((int) $product['price_paise']) ?></span></button>
            <p class="tiny" data-cart-note hidden role="status" style="flex:1 1 100%;margin-top:4px"></p>
          </form>
        <?php else: ?>
          <div style="flex:1 1 100%">
            <p class="small muted" style="margin-bottom:12px"><?= $product['stock_label'] === 'pre' ? 'Not landed yet. Leave a contact and we will tell you the day it does.' : 'Out of stock. Leave a contact and we will tell you the day it is back.' ?></p>
            <?php $alert_product = (int) $product['id']; require __DIR__ . '/inc/alert-form.php'; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- At a glance, straight under the button: chrono, gears and gearbox
           are what someone checks right before they buy, so they sit beside
           the decision rather than down under the photos. -->
      <?php if ($fps || $product['gears'] || $product['gearbox'] || $product['fits']): ?>
        <dl class="pdp__specs grid">
          <?php if ($fps): ?><div class="stat"><dt>Chrono</dt><dd style="font-size:18px"><?= e($fps) ?></dd></div><?php endif; ?>
          <?php if ($product['gears']): ?>
            <div class="stat"><dt>Gears</dt><dd style="font-size:18px;color:var(--<?= e($product['gears']) ?>-fg)"><?= e(ucfirst((string) $product['gears'])) ?></dd></div>
          <?php endif; ?>
          <?php if ($product['gearbox']): ?><div class="stat"><dt>Gearbox</dt><dd style="font-size:18px"><?= e($product['gearbox']) ?></dd></div><?php endif; ?>
          <?php if ($product['fits']): ?><div class="stat"><dt>Fits</dt><dd style="font-size:18px"><?= e($product['fits']) ?></dd></div><?php endif; ?>
        </dl>
      <?php endif; ?>

      <?php if ($product['needs_to_run']): ?>
        <div class="card card--pad" style="margin-top:16px">
          <h2 class="h-kicker" style="margin-bottom:10px">What it needs to run</h2>
          <p class="small" style="color:var(--ink-3);line-height:1.65"><?= e($product['needs_to_run']) ?></p>
          <a class="small" style="font-weight:600;display:inline-block;margin-top:12px" href="/gear">Eye protection and the rest &rarr;</a>
        </div>
      <?php endif; ?>

      <?php if ($product['category'] === 'blaster' && $fps): ?>
        <div class="notice notice--olive" style="margin-top:16px">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--olive-dark)" stroke-width="1.9" aria-hidden="true"><path d="M12 3l8 3.5v5c0 4.6-3.3 8.4-8 9.5-4.7-1.1-8-4.9-8-9.5v-5z" stroke-linejoin="round"/><path d="M12 8v4.5M12 15.6v.1" stroke-linecap="round"/></svg>
          <p class="small" style="line-height:1.65">
            <?php if ((int) $product['fps_max'] > 230): ?>
              At <?= (int) $product['fps_max'] ?> FPS this sits above the usual 230 cap for indoor games &mdash; fine outdoors, so check the host&rsquo;s limit before you travel.
            <?php else: ?>
              At <?= e($fps) ?> this is inside the usual 230 indoor cap, but hosts set their own &mdash; check before you travel.
            <?php endif; ?>
            Full-seal eye protection on before it is loaded, and it travels concealed. <a href="/safety" style="color:var(--olive-dark);text-decoration:underline">Our standards in full</a>.
          </p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($product['description']): ?>
  <!-- The longest text on the page, so the full width of the page rather
       than the right-hand column. -->
  <section class="wrap section section--sm pdp__about">
    <div class="card card--pad-lg">
      <h2 class="h-kicker" style="margin-bottom:12px">About this one</h2>
      <div class="rt"><?= richtext((string) $product['description']) ?></div>
    </div>
  </section>
  <?php endif; ?>

  <!-- tabs -->
  <section class="wrap section" data-tabs="product">
    <div class="tabs" role="tablist" aria-label="Product detail">
      <button role="tab" type="button" data-tab="specs" aria-selected="true">Specifications</button>
      <button role="tab" type="button" data-tab="ship" aria-selected="false">Shipping &amp; service</button>
    </div>

    <div style="padding-top:28px">
      <section class="tabpanel" role="tabpanel" data-panel="specs" data-panel-group="product" aria-label="Specifications">
        <div class="row row--top" style="gap:40px">
          <table class="table table--spec col">
            <caption class="sr-only">Specifications</caption>
            <tbody>
              <?php
              $specs = [
                  'Brand'        => $product['brand'],
                  'Grade'        => grade_label($product['grade']),
                  'Type'         => type_label($product['type']),
                  'Gears'        => gears_label($product['gears']),
                  'Gearbox'      => $product['gearbox'],
                  'Chrono'       => $fps,
                  'Battery'      => $product['battery'],
                  'Magazine'     => $product['magazine'],
                  'Fits'         => $product['fits'],
                  'Sizes'        => $product['sizes'],
              ];
              foreach ($specs as $label => $value):
                  if (!$value) { continue; } ?>
                <tr><th scope="row"><?= e($label) ?></th><td><?= e($value) ?></td></tr>
              <?php endforeach; ?>
              <tr><th scope="row">In stock</th><td><?= $inStock ? (int) $product['stock_qty'] . ' on the shelf' : e($stockText) ?></td></tr>
            </tbody>
          </table>

          <div class="col" style="min-width:0">
            <div class="notice notice--paper">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
              <p class="small">Gears and gearbox are listed separately on purpose. A nylon gearbox shell can hold metal gears, and the gears are what wear out first.</p>
            </div>
          </div>
        </div>
      </section>

      <section class="tabpanel" role="tabpanel" data-panel="ship" data-panel-group="product" aria-label="Shipping and service" hidden>
        <div class="grid grid--auto">
          <div class="card card--pad">
            <h3 class="h-card" style="margin-bottom:8px">Dispatch</h3>
            <p class="small muted">In plain, unmarked packaging, across India. In stock items go out within 24 hours on working days.</p>
          </div>
          <div class="card card--pad">
            <h3 class="h-card" style="margin-bottom:8px">Chrono-tested</h3>
            <p class="small muted">Every blaster is fired over a chrono before it is boxed, and the figure on the label is the one it did here.</p>
          </div>
          <div class="card card--pad">
            <h3 class="h-card" style="margin-bottom:8px">If it arrives damaged</h3>
            <p class="small muted">We replace shipping damage on an uncut unboxing video. All sales are otherwise final, and we do not offer warranties. <a href="/policy">The policy in full &rarr;</a></p>
          </div>
        </div>
      </section>
    </div>
  </section>

  <?php if ($related): ?>
  <!-- cross-sell -->
  <section class="wrap section section--flush-top" style="padding-bottom:64px">
    <div class="row row--between row--center" style="margin-bottom:20px">
      <h2 class="h-sect">More on the <?= e(strtolower($shelfName)) ?> shelf</h2>
      <a class="small" style="font-weight:600" href="<?= e($shelf) ?>">See all &rarr;</a>
    </div>
    <div class="grid grid--auto">
      <?php foreach ($related as $r): ?><?= product_card($r) ?><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
