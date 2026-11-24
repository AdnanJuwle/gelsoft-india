<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';

$f        = shop_filters('gear');
$result   = shop_products($f);
$products = $result['rows'];
$brands   = shop_brands('gear');

$countGroup = shop_counts($f, 'gear_group', 'p.gear_group');
$countBrand = shop_counts($f, 'brand', 'p.brand');

$GEAR_GROUPS = [
    'eye'   => 'Eye &amp; face',
    'head'  => 'Head',
    'body'  => 'Body',
    'hands' => 'Hands',
    'carry' => 'Carry &amp; holsters',
    'legs'  => 'Legs',
];

$page = [
    'title'     => 'Gear',
    'title_full' => 'Gel Blaster Gear: Goggles, Masks & Vests | Gelsoft India',
    'desc'      => 'Gel blaster gear for Indian heat: eye and face protection, helmets, chest rigs, gloves, holsters and pads.',
    'nav'       => 'gear',
    'canonical' => '/gear',
    'noindex'   => shop_filtered($f),
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Gear</span>
    </nav>
    <h1 class="h-page">Gear</h1>
    <p class="muted measure--wide" style="margin-top:12px">Eye and face protection, helmets, rigs, gloves, holsters and pads. Sized for the heat, because a vest that works in a European winter is unwearable here by eleven in the morning.</p>
  </div>

  <!-- eye protection -->
  <div class="wrap" style="padding-top:24px">
    <div class="card card--olive card--pad-lg row row--between row--center">
      <div class="row" style="flex:1 1 460px;min-width:0;gap:18px;flex-wrap:nowrap">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--olive-dark)" stroke-width="1.9" aria-hidden="true" style="flex:0 0 auto;margin-top:3px"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6z" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.6"/></svg>
        <div>
          <h2 style="font-size:20px;font-weight:700;margin-bottom:8px">Eye protection is the one thing you do not save money on</h2>
          <p class="small" style="color:var(--olive-dark);max-width:60em">A gel ball does not break skin and will still take an eye. Buy <strong>full-seal</strong> goggles rated to an impact standard &mdash; not sunglasses, not open shooting glasses, not a mesh mask on its own. Mesh is for the lower face. We will not sell you used eye protection, here or on the marketplace.</p>
        </div>
      </div>
      <a class="btn btn--olive btn--sm" href="/gear?gear_group%5B%5D=eye">Eye &amp; face only</a>
    </div>
  </div>

  <div class="wrap layout section">

    <form class="rail card card--pad" method="get" action="/gear" data-autofilter aria-label="Filters">
      <div class="row row--between" style="align-items:baseline;margin-bottom:20px">
        <h2 class="h-kicker">Filters</h2>
        <?php if (shop_filtered($f)): ?><a class="btn--link" href="/gear">Clear</a><?php endif; ?>
      </div>

      <fieldset class="fieldset">
        <legend>Category</legend>
        <?php foreach ($GEAR_GROUPS as $key => $label): ?>
          <?= shop_option($f, 'gear_group', $key, $label, $countGroup) ?>
        <?php endforeach; ?>
      </fieldset>

      <?php if ($brands): ?>
      <fieldset class="fieldset">
        <legend>Brand</legend>
        <?php foreach ($brands as $b): ?>
          <?= shop_option($f, 'brand', $b, e($b), $countBrand) ?>
        <?php endforeach; ?>
      </fieldset>
      <?php endif; ?>

      <fieldset class="fieldset">
        <legend>Availability</legend>
        <label class="checkline"><input type="checkbox" name="in_stock" value="1"<?= $f['in_stock'] ? ' checked' : '' ?>> <span class="name">In stock only</span></label>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Max price</legend>
        <label class="sr-only" for="price_max">Maximum price</label>
        <input id="price_max" name="price_max" type="range" min="300" max="6000" step="100" value="<?= e($f['price_max'] ?? 6000) ?>" data-price-range style="width:100%;accent-color:var(--ember)">
        <p class="tiny muted">&#8377;300 &ndash; <span data-price-out><?= $f['price_max'] !== null ? money($f['price_max'] * 100) : '&#8377;6,000' ?></span></p>
      </fieldset>

      <?php if ($f['sort'] !== 'featured'): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
      <noscript><button class="btn btn--dark btn--sm btn--block" type="submit">Apply filters</button></noscript>
    </form>

    <div class="results">
      <div class="row row--between row--center" style="margin-bottom:20px">
        <p class="small muted">
          <?php if ($result['total'] === 0): ?>
            <?= shop_filtered($f) ? 'Nothing matches those filters' : 'Nothing in stock yet' ?>
          <?php else: ?>
            <?= (int) $result['total'] ?> item<?= $result['total'] === 1 ? '' : 's' ?>
          <?php endif; ?>
        </p>
        <form class="row row--tight row--center" method="get" action="/gear" data-autofilter>
          <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') { continue; }
            foreach ((array) $v as $one): ?>
              <input type="hidden" name="<?= e($k) ?><?= is_array($v) ? '[]' : '' ?>" value="<?= e($one) ?>">
          <?php endforeach; endforeach; ?>
          <label class="small muted" for="sort">Sort</label>
          <select class="select" id="sort" name="sort" style="width:auto;min-height:44px">
            <option value="featured"<?= $f['sort'] === 'featured' ? ' selected' : '' ?>>Featured</option>
            <option value="low"<?= $f['sort'] === 'low' ? ' selected' : '' ?>>Price: low to high</option>
            <option value="high"<?= $f['sort'] === 'high' ? ' selected' : '' ?>>Price: high to low</option>
            <option value="new"<?= $f['sort'] === 'new' ? ' selected' : '' ?>>Newest first</option>
          </select>
          <noscript><button class="btn btn--xs" type="submit">Go</button></noscript>
        </form>
      </div>

      <?php if ($products): ?>
        <div class="grid grid--auto">
          <?php foreach ($products as $p): ?><?= product_card($p) ?><?php endforeach; ?>
        </div>
        <?php require __DIR__ . '/inc/pagination.php'; ?>

      <?php elseif (shop_filtered($f)): ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5" stroke-linecap="round"/></svg>
          </span>
          <h3>Nothing matches that</h3>
          <p>No gear on the shelf fits every filter at once. Widen one of them &mdash; the count beside each option shows what would be left.</p>
          <a class="btn btn--dark btn--sm" href="/gear">Clear filters</a>
        </div>

      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6z" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.6"/></svg>
          </span>
          <h3>Restocking soon</h3>
          <p>No gear on the shelf yet. Eye protection lands first &mdash; leave a number and we will message you the day it does.</p>
          <?php $alert_category = 'gear'; require __DIR__ . '/inc/alert-form.php'; ?>
        </div>
      <?php endif; ?>

      <div class="card card--pad-lg" style="margin-top:40px">
        <h3 class="h-card" style="margin-bottom:10px">Dressing for Indian heat</h3>
        <p class="small measure--wide" style="color:var(--ink-3)">A full plate carrier over a long-sleeve combat shirt is a two-hour game at best in April. For most of the year a chest rig over a breathable tee carries the same four mags with half the trapped heat, and anti-fog matters far more here than lens tint &mdash; a fogged goggle is the single most common reason people lift eye protection mid-game, which is exactly how injuries happen.</p>
        <a class="small" style="font-weight:600;display:inline-block;margin-top:12px" href="/safety">Our safety standards &rarr;</a>
      </div>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
