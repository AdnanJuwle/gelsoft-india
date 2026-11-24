<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';

// Gel is sold on the parts shelf, so this one covers two categories.
$f        = shop_filters(['part', 'ammo']);
$result   = shop_products($f);
$products = $result['rows'];

$countGroup = shop_counts($f, 'part_group', 'p.part_group');

$PART_GROUPS = [
    'internals'  => 'Internals',
    'electrical' => 'Electrical',
    'optics'     => 'Optics',
    'externals'  => 'Externals',
    'mags'       => 'Magazines',
    'gel'        => 'Gel &amp; ammo',
];

// The platform picker. 'any' parts stay visible whatever is chosen, because a
// charger fits everything and hiding it would be wrong.
$PLATFORMS = [
    'gen8'  => 'TG Gen 8',
    'gen9'  => 'TG Gen 9',
    'v2'    => 'V2 gearbox',
    'sr16'  => 'Bohan SR16 / SBL',
    'bolt'  => 'Jieying bolt-action',
    'handi' => 'Handi / toy grade',
];
$platform = (string) ($_GET['fits'] ?? '');
$platform = isset($PLATFORMS[$platform]) ? $platform : '';

if ($platform !== '') {
    $products = array_values(array_filter($products, static function (array $p) use ($platform) {
        $fits = strtolower((string) ($p['fits'] ?? ''));
        return $fits === '' || $fits === 'any' || str_contains($fits, $platform);
    }));
}

$page = [
    'title'     => 'Parts, upgrades & ammo',
    'title_full' => 'Gel Blaster Parts, Upgrades & Gel Ammo | Gelsoft India',
    'desc'      => 'Gel blaster internals, electricals, optics, foregrips, mags and gel ammo. Every part lists the platforms it fits.',
    'nav'       => 'parts',
    'canonical' => '/parts',
    'noindex'   => shop_filtered($f) || $platform !== '',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Parts, upgrades &amp; ammo</span>
    </nav>
    <h1 class="h-page">Parts, upgrades &amp; ammo</h1>
    <p class="muted measure--wide" style="margin-top:12px">Internals, electricals, optics, foregrips, suppressors, mags and gel. Every part lists the platforms it actually fits &mdash; set yours in the filters and the rest disappears.</p>
  </div>

  <div class="wrap layout section">

    <form class="rail card card--pad" method="get" action="/parts" data-autofilter aria-label="Sections">
      <div class="row row--between" style="align-items:baseline;margin-bottom:20px">
        <h2 class="h-kicker">Sections</h2>
        <?php if (shop_filtered($f) || $platform !== ''): ?><a class="btn--link" href="/parts">Clear</a><?php endif; ?>
      </div>

      <fieldset class="fieldset">
        <legend>Fits my platform</legend>
        <label class="sr-only" for="fits">My platform</label>
        <select class="select" id="fits" name="fits">
          <option value="">Everything</option>
          <?php foreach ($PLATFORMS as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $platform === $key ? ' selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <p class="field__hint">Universal parts &mdash; gel, chargers, rail accessories &mdash; stay visible whatever you pick.</p>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Section</legend>
        <?php foreach ($PART_GROUPS as $key => $label): ?>
          <?= shop_option($f, 'part_group', $key, $label, $countGroup) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Availability</legend>
        <label class="checkline"><input type="checkbox" name="in_stock" value="1"<?= $f['in_stock'] ? ' checked' : '' ?>> <span class="name">In stock only</span></label>
      </fieldset>

      <?php if ($f['sort'] !== 'featured'): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
      <noscript><button class="btn btn--dark btn--sm btn--block" type="submit">Apply</button></noscript>

      <div class="fieldset" id="service">
        <h3 class="field__label">Bench service</h3>
        <p class="tiny muted">Rather not open it yourself? We do it, chrono it afterwards, and tell you what we found.</p>
        <table class="table table--spec" style="margin-top:14px;font-size:13px">
          <caption class="sr-only">Bench service prices</caption>
          <tbody>
            <tr><th scope="row" style="padding-left:0;font-weight:500">Open, clean, regrease</th><td style="text-align:right;padding-right:0;font-weight:600">&#8377;600</td></tr>
            <tr><th scope="row" style="padding-left:0;font-weight:500">Full rebuild + wear parts</th><td style="text-align:right;padding-right:0;font-weight:600">&#8377;1,500</td></tr>
            <tr><th scope="row" style="padding-left:0;font-weight:500">Chrono and tune</th><td style="text-align:right;padding-right:0;font-weight:600">&#8377;400</td></tr>
            <tr><th scope="row" style="padding-left:0;font-weight:500">Motor or wiring swap</th><td style="text-align:right;padding-right:0;font-weight:600">&#8377;700</td></tr>
          </tbody>
        </table>
      </div>
    </form>

    <div class="results">
      <div class="row row--between row--center" style="margin-bottom:20px">
        <p class="small muted">
          <?php if (!$products): ?>
            <?= (shop_filtered($f) || $platform !== '') ? 'Nothing matches those filters' : 'Nothing in stock yet' ?>
          <?php else: ?>
            <?= count($products) ?> part<?= count($products) === 1 ? '' : 's' ?>
            <?php if ($platform !== ''): ?>that fit the <?= $PLATFORMS[$platform] ?><?php endif; ?>
          <?php endif; ?>
        </p>
        <form class="row row--tight row--center" method="get" action="/parts" data-autofilter>
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
        <?php if ($platform === ''): require __DIR__ . '/inc/pagination.php'; endif; ?>

      <?php elseif (shop_filtered($f) || $platform !== ''): ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5" stroke-linecap="round"/></svg>
          </span>
          <h3>Nothing matches that</h3>
          <p>No part on the shelf fits every filter at once<?php if ($platform !== ''): ?>, at least not for the <?= $PLATFORMS[$platform] ?><?php endif; ?>. Widen one of them, or tell us what you are after.</p>
          <div class="row row--tight" style="justify-content:center;gap:20px">
            <a class="btn btn--dark btn--sm" href="/parts">Show everything</a>
            <a class="small" style="font-weight:600" href="/request">Tell us what you need &rarr;</a>
          </div>
        </div>

      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><circle cx="10" cy="13" r="6"/><circle cx="18" cy="9" r="3.5"/></svg>
          </span>
          <h3>Restocking soon</h3>
          <p>No parts on the shelf yet. Tell us the platform you run and what keeps breaking &mdash; that is how we decide what to stock first.</p>
          <?php $alert_category = 'part'; require __DIR__ . '/inc/alert-form.php'; ?>
          <div class="row row--tight" style="justify-content:center">
            <a class="small" style="font-weight:600" href="/request">Tell us what you need &rarr;</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="card card--pad-lg" style="margin-top:40px">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:12px">Gel, soaked properly</h2>
        <div class="grid grid--auto-s" style="margin-top:6px">
          <div>
            <h3 class="small" style="font-weight:700;margin-bottom:8px">Size</h3>
            <p class="small muted">7&ndash;8 mm is the standard for everything we sell. Undersized gel double-feeds and jams; oversized gel will not chamber at all.</p>
          </div>
          <div>
            <h3 class="small" style="font-weight:700;margin-bottom:8px">Soak time</h3>
            <p class="small muted">Three to four hours in clean water, then drain. Left overnight they swell soft and mush inside the hop-up, which reads as a dying gearbox and is not one.</p>
          </div>
          <div>
            <h3 class="small" style="font-weight:700;margin-bottom:8px">Hardness</h3>
            <p class="small muted">Competition gel is harder and rounder, so it chronos consistently and flies straighter. It also stings more, which is why some hosts cap it.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
