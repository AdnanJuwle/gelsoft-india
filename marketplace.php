<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';

$f      = listing_filters();
$result = listings_query($f);

$countCondition = listing_counts($f, 'item_condition', 'l.item_condition');
$countGrade     = listing_counts($f, 'grade', 'l.grade');
$countGears     = listing_counts($f, 'gears', 'l.gears');

$page = [
    'title'     => 'Marketplace',
    'title_full' => 'Used Gel Blasters for Sale in India | Gelsoft Marketplace',
    'desc'      => 'Buy and sell used gel blasters, parts and gear in India. Verified sellers, payment held until you confirm delivery, 48 hours to inspect.',
    'nav'       => 'marketplace',
    'util'      => 'marketplace',
    'canonical' => '/marketplace',
    'noindex'   => listing_filtered($f),
];
require __DIR__ . '/inc/header.php';
?>

  <!-- masthead -->
  <section class="band">
    <div class="wrap" style="padding:56px 24px 48px">
      <p class="eyebrow eyebrow--soft">Marketplace</p>
      <h1 class="h-page" style="color:#fff;margin-top:16px;max-width:24em">The blaster in your cupboard is somebody&rsquo;s first one.</h1>
      <p class="on-dark" style="font-size:16.5px;margin-top:18px;max-width:42em">Most people here are selling one or two things, not running a shop. Sellers are verified, we hold your payment until you confirm the parcel arrived, and you get 48 hours to inspect it &mdash; so a first-time seller is as safe to buy from as a regular.</p>

      <form class="card card--dark row row--center" style="margin-top:32px;padding:14px;gap:12px" method="get" action="/marketplace">
        <div class="row row--center" style="flex:999 1 280px;min-width:0;gap:10px;background:var(--dark);border:1px solid var(--dark-line-2);border-radius:10px;padding:0 14px;min-height:52px;flex-wrap:nowrap">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--on-dark-3)" stroke-width="1.9" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21" stroke-linecap="round"/></svg>
          <label class="sr-only" for="mq">Search listings</label>
          <input class="input--bare" id="mq" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="Search listings — model, part, bundle&hellip;" style="color:var(--on-dark);width:100%">
        </div>
        <label class="sr-only" for="mcond">Condition</label>
        <select class="select" id="mcond" name="item_condition[]" style="flex:1 1 170px;width:auto;min-height:52px;background:var(--dark);border-color:var(--dark-line-2);color:var(--on-dark)">
          <option value="">Any condition</option>
          <?php foreach (LISTING_ENUMS['item_condition'] as $c): ?>
            <option value="<?= e($c) ?>"<?= in_array($c, $f['item_condition'], true) ? ' selected' : '' ?>><?= e(condition_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--primary" type="submit" style="flex:0 0 auto">Search</button>
      </form>

      <p class="small" style="color:var(--on-dark-3);margin-top:16px">Blasters, parts, mags and gear &mdash; listed by players, not shops</p>
    </div>
  </section>

  <!-- trust -->
  <section class="band band--card">
    <div class="wrap section section--sm">
      <div class="grid grid--auto-l">
        <div class="row" style="gap:16px;flex-wrap:nowrap">
          <span class="avatar" style="background:var(--olive-tint);border:1px solid var(--olive-line);color:var(--olive-dark);width:40px;height:40px;font-size:16px">1</span>
          <div>
            <h2 class="h-card" style="font-size:17px;margin-bottom:7px">Sellers are verified</h2>
            <p class="small muted">ID and a matching address before a first listing goes live. Two minutes, once &mdash; and a banned account cannot come back under a new name.</p>
          </div>
        </div>
        <div class="row" style="gap:16px;flex-wrap:nowrap">
          <span class="avatar" style="background:var(--olive-tint);border:1px solid var(--olive-line);color:var(--olive-dark);width:40px;height:40px;font-size:16px">2</span>
          <div>
            <h2 class="h-card" style="font-size:17px;margin-bottom:7px">We hold the payment</h2>
            <p class="small muted">Your money sits with us, not the seller, until you confirm the parcel arrived. No bank transfers to strangers.</p>
          </div>
        </div>
        <div class="row" style="gap:16px;flex-wrap:nowrap">
          <span class="avatar" style="background:var(--olive-tint);border:1px solid var(--olive-line);color:var(--olive-dark);width:40px;height:40px;font-size:16px">3</span>
          <div>
            <h2 class="h-card" style="font-size:17px;margin-bottom:7px">48 hours to inspect</h2>
            <p class="small muted">Open it, run it, check the internals. Not as described? Say so inside 48 hours and the payment never completes.</p>
          </div>
        </div>
      </div>

      <div class="notice notice--olive" style="margin-top:24px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--olive)" stroke-width="1.9" aria-hidden="true"><path d="M12 3l7.5 3.4v5c0 4.3-3 8.2-7.5 9.6-4.5-1.4-7.5-5.3-7.5-9.6v-5z" stroke-linejoin="round"/><path d="M9 12l2.2 2.2L15.5 10" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <p class="small"><strong>You can have it checked before it reaches you.</strong> On most listings there is an <span class="inspected"><svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Inspect it for me</span> option at checkout, for <?= (int) cfg('marketplace.inspection_fee_pct', 8) ?>% of the price. The seller posts it to us instead of to you, we test that it feeds and fires, chrono it and photograph what we find, then send it on. If it is not what the listing said, the sale is cancelled and you are not charged for any of it.</p>
      </div>
    </div>
  </section>

  <div class="wrap layout section">

    <form class="rail card card--pad" method="get" action="/marketplace" data-autofilter aria-label="Listing filters">
      <div class="row row--between" style="align-items:baseline;margin-bottom:20px">
        <h2 class="h-kicker">Filters</h2>
        <?php if (listing_filtered($f)): ?><a class="btn--link" href="/marketplace">Clear</a><?php endif; ?>
      </div>

      <?php if ($f['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($f['q']) ?>"><?php endif; ?>

      <fieldset class="fieldset">
        <legend>Condition</legend>
        <?php foreach (LISTING_ENUMS['item_condition'] as $c): ?>
          <?= listing_option($f, 'item_condition', $c, e(condition_label($c)), $countCondition) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Grade</legend>
        <?php foreach (LISTING_ENUMS['grade'] as $g): ?>
          <?= listing_option($f, 'grade', $g, grade_mark($g), $countGrade) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Gears</legend>
        <?php foreach (LISTING_ENUMS['gears'] as $g): ?>
          <?= listing_option($f, 'gears', $g, e(gears_label($g)), $countGears) ?>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Handover</legend>
        <p class="field__hint" style="margin:0 0 10px">No filter for how many times someone has sold &mdash; every payment is held, so a first-time seller is as safe to buy from as a regular.</p>
        <label class="checkline"><input type="checkbox" name="inspectable" value="1"<?= $f['inspectable'] ? ' checked' : '' ?>> <span class="name">Inspection available</span></label>
        <label class="checkline"><input type="checkbox" name="offers" value="1"<?= $f['offers'] ? ' checked' : '' ?>> <span class="name">Accepts offers</span></label>
      </fieldset>

      <fieldset class="fieldset">
        <legend>Max price</legend>
        <label class="sr-only" for="price_max">Maximum price</label>
        <input id="price_max" name="price_max" type="range" min="200" max="40000" step="200"
               value="<?= e($f['price_max'] ?? 40000) ?>" data-price-range style="width:100%;accent-color:var(--ember)">
        <p class="tiny muted">&#8377;200 &ndash; <span data-price-out><?= $f['price_max'] !== null ? money($f['price_max'] * 100) : '&#8377;40,000' ?></span></p>
      </fieldset>

      <?php if ($f['sort'] !== 'new'): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
      <noscript><button class="btn btn--dark btn--sm btn--block" type="submit">Apply filters</button></noscript>
    </form>

    <div class="results">
      <div class="row row--between row--center" style="margin-bottom:20px">
        <p class="small muted">
          <?php if ($result['total'] === 0): ?>
            <?= listing_filtered($f) ? 'Nothing matches those filters' : 'Nothing listed yet' ?>
          <?php else: ?>
            <?= (int) $result['total'] ?> listing<?= $result['total'] === 1 ? '' : 's' ?>
          <?php endif; ?>
        </p>
        <form class="row row--tight row--center" method="get" action="/marketplace" data-autofilter>
          <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') { continue; }
            foreach ((array) $v as $one): ?>
              <input type="hidden" name="<?= e($k) ?><?= is_array($v) ? '[]' : '' ?>" value="<?= e($one) ?>">
          <?php endforeach; endforeach; ?>
          <label class="small muted" for="msort">Sort</label>
          <select class="select" id="msort" name="sort" style="width:auto;min-height:44px">
            <option value="new"<?= $f['sort'] === 'new' ? ' selected' : '' ?>>Newest first</option>
            <option value="low"<?= $f['sort'] === 'low' ? ' selected' : '' ?>>Price: low to high</option>
            <option value="high"<?= $f['sort'] === 'high' ? ' selected' : '' ?>>Price: high to low</option>
          </select>
          <noscript><button class="btn btn--xs" type="submit">Go</button></noscript>
        </form>
      </div>

      <?php if ($result['rows']): ?>
        <div class="grid grid--auto-l">
          <?php foreach ($result['rows'] as $l): ?><?= listing_card($l) ?><?php endforeach; ?>
        </div>
        <?php require __DIR__ . '/inc/pagination.php'; ?>

      <?php elseif (listing_filtered($f)): ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5" stroke-linecap="round"/></svg>
          </span>
          <h3>Nothing matches that</h3>
          <p>No listing fits every filter at once. Widen one &mdash; the count beside each option shows what would be left.</p>
          <a class="btn btn--dark btn--sm" href="/marketplace">Clear filters</a>
        </div>

      <?php else: ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 9h16l-1.2 10H5.2z" stroke-linejoin="round"/><path d="M7 9l2-4h6l2 4" stroke-linejoin="round"/></svg>
          </span>
          <h3>Nothing listed yet</h3>
          <p>The marketplace opens the moment someone lists something. If you have a blaster, a spare mag or a gearbox sitting in a cupboard, you can be the first &mdash; it is free, and it takes about two minutes.</p>
          <div class="row row--tight" style="justify-content:center">
            <a class="btn btn--primary btn--sm" href="/sell">List one thing</a>
            <a class="btn btn--sm" href="/blasters">Shop new instead</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- selling -->
  <section class="band band--olive">
    <div class="wrap section section--sm">
      <div class="row row--between row--top">
        <div class="col--half">
          <h2 class="h-sect">Selling one blaster? That is exactly who this is for.</h2>
          <p style="margin-top:14px;max-width:42em">No shop, no GST number, no selling history. Photos, a price and an honest condition note &mdash; we suggest a range from what similar gear actually sold for, and most people here list one thing and never come back. That is fine.</p>
          <ul class="list" style="margin-top:18px;color:var(--olive-ink)">
            <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Free to list, and nothing to pay if it does not sell</li>
            <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Payout to UPI the working day after the buyer confirms</li>
            <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2.2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Held payment protects you too &mdash; no chasing a stranger for money</li>
          </ul>
          <a class="btn btn--olive" href="/sell" style="margin-top:24px">List one thing</a>
        </div>

        <div class="card card--pad-lg" style="flex:1 1 340px;min-width:0;border-color:var(--olive-line)">
          <h3 class="h-kicker" style="margin-bottom:16px">What can&rsquo;t be listed</h3>
          <ul class="list">
            <li><span class="x" aria-hidden="true">&times;</span> Anything repainted or modified to pass as a real firearm</li>
            <li><span class="x" aria-hidden="true">&times;</span> Internal upgrades left out of the description &mdash; put the chrono figure in writing</li>
            <li><span class="x" aria-hidden="true">&times;</span> Used eye protection &mdash; a scratched lens is a hospital trip</li>
            <li><span class="x" aria-hidden="true">&times;</span> Anything you do not have in hand and photograph yourself</li>
          </ul>
        </div>
      </div>
    </div>
  </section>
<?php require __DIR__ . '/inc/footer.php';
