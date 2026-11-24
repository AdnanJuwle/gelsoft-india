<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';

$f        = shop_filters('blaster');
$result   = shop_products($f);
$products = $result['rows'];
$brands   = shop_brands('blaster');

$countType  = shop_counts($f, 'type', 'p.type');
$countGrade = shop_counts($f, 'grade', 'p.grade');
$countGears = shop_counts($f, 'gears', 'p.gears');
$countBrand = shop_counts($f, 'brand', 'p.brand');

$page = [
    'title'   => 'Blasters',
    'title_full' => 'Buy Gel Blasters Online in India | Gelsoft India',
    'desc'    => 'Gel blasters by type, brand, grade and gears, shipped across India. Every one is chrono-tested before it ships, and the figure on the label is the one it did here.',
    'nav'     => 'blasters',
    // A filtered shelf is a slice of the same shelf, not a page of its own.
    'canonical' => '/blasters',
    'noindex' => shop_filtered($f),
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap" style="padding-top:28px">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Blasters</span>
    </nav>
    <h1 class="h-page">Blasters</h1>
    <p class="muted measure--wide" style="margin-top:12px">Every blaster is chrono-tested before it ships, and the figure on the label is the one it did here &mdash; not the number on the box.</p>
  </div>

  <!-- grade key -->
  <div class="wrap" style="padding-top:24px">
    <div class="card card--pad row row--center grades-bar" style="gap:20px 32px;padding:20px 24px">
      <h2 class="h-kicker">Grades</h2>
      <div class="grade grade--toy">
        <span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span>
        <span style="display:flex;flex-direction:column"><strong class="small">Toy grade</strong><span class="tiny muted">Nylon gears, sealed internals.</span></span>
      </div>
      <div class="grade grade--mid">
        <span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span>
        <span style="display:flex;flex-direction:column"><strong class="small">Mid hobby grade</strong><span class="tiny muted">V2 / Gen 8 / Gen 9. Spares on the shelf.</span></span>
      </div>
      <div class="grade grade--premium">
        <span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span>
        <span style="display:flex;flex-direction:column"><strong class="small">Premium grade</strong><span class="tiny muted">Metal internals, consistent chrono.</span></span>
      </div>
      <a class="small" style="font-weight:600" href="/faq">Full explainer &rarr;</a>
    </div>
  </div>

  <div class="wrap layout section">

    <!-- filters: a GET form, so a filtered shelf is a URL you can send someone -->
    <form class="rail card card--pad" method="get" action="/blasters" data-autofilter aria-label="Filters">
      <div class="row row--between" style="align-items:baseline;margin-bottom:20px">
        <h2 class="h-kicker">Filters</h2>
        <?php if (shop_filtered($f)): ?><a class="btn--link" href="/blasters">Clear</a><?php endif; ?>
      </div>

      <fieldset class="fieldset">
        <legend>Type</legend>
        <?php foreach (SHOP_ENUMS['type'] as $t): ?>
          <?= shop_option($f, 'type', $t, e(type_label($t)), $countType) ?>
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
        <legend>Grade</legend>
        <?php foreach (SHOP_ENUMS['grade'] as $g): ?>
          <?= shop_option($f, 'grade', $g, grade_mark($g), $countGrade) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Gears</legend>
        <p class="field__hint" style="margin:0 0 8px">The gears, not the gearbox &mdash; a nylon shell can hold metal gears.</p>
        <?php foreach (SHOP_ENUMS['gears'] as $g): ?>
          <?= shop_option($f, 'gears', $g, e(gears_label($g)), $countGears) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Chrono (FPS)</legend>
        <div class="row row--tight row--center">
          <label class="sr-only" for="fps_min">Minimum FPS</label>
          <input class="input" id="fps_min" name="fps_min" type="number" min="0" max="1000" placeholder="150" value="<?= e($f['fps_min']) ?>" style="min-height:44px">
          <span class="muted">&ndash;</span>
          <label class="sr-only" for="fps_max">Maximum FPS</label>
          <input class="input" id="fps_max" name="fps_max" type="number" min="0" max="1000" placeholder="320" value="<?= e($f['fps_max']) ?>" style="min-height:44px">
        </div>
        <p class="field__hint">Most indoor games cap at 230. Check the host&rsquo;s limit.</p>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Availability</legend>
        <label class="checkline"><input type="checkbox" name="in_stock" value="1"<?= $f['in_stock'] ? ' checked' : '' ?>> <span class="name">In stock only</span></label>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Max price</legend>
        <label class="sr-only" for="price_max">Maximum price</label>
        <input id="price_max" name="price_max" type="range" min="2000" max="45000" step="500" value="<?= e($f['price_max'] ?? 45000) ?>" data-price-range style="width:100%;accent-color:var(--ember)">
        <p class="tiny muted">&#8377;2,000 &ndash; <span data-price-out><?= $f['price_max'] !== null ? money($f['price_max'] * 100) : '&#8377;45,000' ?></span></p>
      </fieldset>

      <?php if ($f['sort'] !== 'featured'): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
      <noscript><button class="btn btn--dark btn--sm btn--block" type="submit">Apply filters</button></noscript>
    </form>

    <!-- results -->
    <div class="results">
      <div class="row row--between row--center" style="margin-bottom:20px">
        <p class="small muted">
          <?php if ($result['total'] === 0): ?>
            <?= shop_filtered($f) ? 'Nothing matches those filters' : 'Nothing in stock yet' ?>
          <?php else: ?>
            <?= (int) $result['total'] ?> blaster<?= $result['total'] === 1 ? '' : 's' ?>
            <?= shop_filtered($f) ? 'match' . ($result['total'] === 1 ? 'es' : '') : 'on the shelf' ?>
          <?php endif; ?>
        </p>
        <form class="row row--tight row--center" method="get" action="/blasters" data-autofilter>
          <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') { continue; }
            foreach ((array) $v as $one): ?>
              <input type="hidden" name="<?= e($k) ?><?= is_array($v) ? '[]' : '' ?>" value="<?= e($one) ?>">
          <?php endforeach; endforeach; ?>
          <label class="small muted" for="sort">Sort</label>
          <select class="select" id="sort" name="sort" style="width:auto;min-height:44px">
            <option value="featured"<?= $f['sort'] === 'featured' ? ' selected' : '' ?>>Featured</option>
            <option value="low"<?= $f['sort'] === 'low' ? ' selected' : '' ?>>Price: low to high</option>
            <option value="high"<?= $f['sort'] === 'high' ? ' selected' : '' ?>>Price: high to low</option>
            <option value="fps"<?= $f['sort'] === 'fps' ? ' selected' : '' ?>>Chrono: high to low</option>
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
          <p>No blaster on the shelf fits every filter at once. Widen one of them &mdash; the counts beside each option show what would be left.</p>
          <div class="row row--tight" style="justify-content:center;gap:20px">
            <a class="btn btn--dark btn--sm" href="/blasters">Clear filters</a>
            <a class="small" style="font-weight:600" href="/request">Request a specific model &rarr;</a>
          </div>
        </div>

      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 8h16l-1.5 11.5H5.5z" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" stroke-linecap="round"/></svg>
          </span>
          <h3>Restocking soon</h3>
          <p>Nothing is on the shelf right now. Leave a number and we will message you the day the first batch lands &mdash; one message, nothing else.</p>
          <?php $alert_category = 'blaster'; require __DIR__ . '/inc/alert-form.php'; ?>
          <div class="row row--tight" style="justify-content:center;gap:20px">
            <a class="small" style="font-weight:600" href="/request">Request a specific model &rarr;</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="row" style="margin-top:40px">
        <div class="card card--pad card--olive" style="flex:1 1 300px;min-width:0">
          <h3 class="h-card" style="margin-bottom:8px">Not listed anywhere in India?</h3>
          <p class="small" style="color:var(--olive-dark)">Give us the brand and the pattern. We quote it with a real lead time, or tell you honestly that we can&rsquo;t get it.</p>
          <a class="btn btn--olive btn--sm" href="/request" style="margin-top:16px">Request a model</a>
        </div>
        <div class="card card--pad" style="flex:1 1 300px;min-width:0;background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
          <h3 class="h-card" style="margin-bottom:8px;color:#fff">Try second-hand</h3>
          <p class="small on-dark">Used blasters from verified sellers, with payment held until the parcel lands.</p>
          <a class="btn btn--light btn--sm" href="/marketplace" style="margin-top:16px">Open marketplace</a>
        </div>
      </div>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
