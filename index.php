<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';

// The newest four blasters, or nothing, which is what the empty state is for.
$latest = db_ready()
    ? q("SELECT p.*, (
             SELECT i.path FROM product_images i
             WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1
         ) AS image
         FROM products p
         WHERE p.status = 'published' AND p.category = 'blaster'
         ORDER BY p.featured DESC, p.created_at DESC
         LIMIT 4")
    : [];

$page = [
    // "Gel blasters" first: it is what people type. The brand on its own
    // is a generic word -- gel blasters are called gelsoft in Australia --
    // so it cannot carry the title by itself.
    'title_full' => 'Gel Blasters, Gear & Parts in India | Gelsoft India',

    /*
     * Who this site is, for Google.
     *
     * The WebSite `name` on the homepage is what Google prints above a result
     * as the site's name. "Gelsoft" on its own is a generic term, so without
     * this it may show the bare domain or guess. The logo is the favicon
     * rendered to a 192px PNG, because Google wants a raster image of at
     * least 112px there, not an SVG; it is only named once the file exists.
     */
    'schema' => [
        [
            '@context'      => 'https://schema.org',
            '@type'         => 'WebSite',
            'name'          => 'Gelsoft India',
            'alternateName' => ['Gelsoft', 'gelsoft.in'],
            'url'           => abs_url('/'),
        ],
        array_filter([
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => 'Gelsoft India',
            'url'      => abs_url('/'),
            'logo'     => site_photo('favicon-192.png') ? abs_url('assets/img/favicon-192.png') : null,
            'email'    => (string) cfg('contact.email') ?: null,
            'sameAs'   => cfg('contact.instagram')
                ? ['https://www.instagram.com/' . rawurlencode((string) cfg('contact.instagram'))]
                : null,
        ]),
    ],
    'desc'    => 'Gel blasters listed by grade, gears and a real chrono figure. Gear, internals and gel for all of them, plus a marketplace for second-hand kit.',
];
require __DIR__ . '/inc/header.php';
?>

  <!-- hero -->
  <section class="wrap hero">
    <div class="col--half">
      <p class="eyebrow eyebrow--ember">Blasters &middot; gear &middot; parts &middot; shipped across India</p>
      <h1 class="h-hero" style="margin-top:20px">Field-ready kit,<br>without the guesswork.</h1>
      <p class="lead measure" style="margin-top:24px">Gel blasters listed by grade, gears and a real chrono figure. Gear, internals and gel for all of them, plus a marketplace for second-hand kit.</p>

      <div class="row row--tight" style="margin-top:32px">
        <a class="btn btn--primary" href="/blasters">Shop blasters</a>
        <a class="btn" href="/marketplace">Browse marketplace</a>
      </div>

      <ul class="list list--inline" style="margin-top:40px">
        <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Chrono-tested before dispatch</li>
        <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Spares and servicing in-house</li>
        <li><svg width="17" height="17" viewBox="0 0 20 20" fill="none" stroke="var(--olive)" stroke-width="2" aria-hidden="true"><path d="M4 10.5l4 4 8-9" stroke-linecap="round" stroke-linejoin="round"/></svg> Plain, unmarked packaging</li>
      </ul>
    </div>

    <div class="col--half">
      <div class="hero__art" style="padding:0;overflow:hidden">
        <!-- photo slot — see assets/img/NEEDED.md -->
        <?php if ($photo = site_photo('hero-blaster.jpg')): ?>
        <img class="ph-img hero__photo" src="<?= e($photo) ?>" alt="A gel blaster rifle, a pistol and full-seal goggles" width="1600" height="900" fetchpriority="high" decoding="async">
        <?php else: ?>
        <div class="ph ph--wide ph--dark" style="border-radius:18px;min-height:360px">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">hero-blaster.jpg</span>
          <span class="ph__meta">1600 &times; 900 &mdash; one blaster, three-quarter view, plain dark background</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="hero__card">
        <span style="display:flex;flex-direction:column;gap:4px">
          <?php if ($latest): ?>
            <strong style="font-family:var(--display);font-size:15px">Stock lands in batches</strong>
            <span class="tiny muted">One message when a model you want is back.</span>
          <?php else: ?>
            <strong style="font-family:var(--display);font-size:15px">First stock lands shortly</strong>
            <span class="tiny muted">One message when it does. Nothing else.</span>
          <?php endif; ?>
        </span>
        <a class="btn btn--dark btn--sm" href="#alerts">Alert me</a>
      </div>
    </div>
  </section>

  <!-- grades -->
  <section class="band band--card">
    <div class="wrap section section--sm">
      <div class="row row--between row--end" style="margin-bottom:24px">
        <h2 class="h-sect">Three grades</h2>
        <a class="small" style="font-weight:600" href="/blasters">Shop by grade &rarr;</a>
      </div>

      <div class="grid grid--auto-l">
        <a class="card card--pad card--soft card--link" href="/blasters">
          <span class="grade grade--toy"><span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span><strong style="font-family:var(--display);font-size:19px">Toy grade</strong></span>
          <p class="muted">Ready to play out of the box. Nylon gears, sealed internals.</p>
        </a>
        <a class="card card--pad card--soft card--link" href="/blasters">
          <span class="grade grade--mid"><span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span><strong style="font-family:var(--display);font-size:19px">Mid hobby grade</strong></span>
          <p class="muted">V2, Gen 8 or Gen 9 gearbox. Spares on our shelf.</p>
        </a>
        <a class="card card--pad card--soft card--link" href="/blasters">
          <span class="grade grade--premium"><span class="grade__bars" aria-hidden="true"><i></i><i></i><i></i></span><strong style="font-family:var(--display);font-size:19px">Premium grade</strong></span>
          <p class="muted">Metal internals and a chrono that holds all day.</p>
        </a>
      </div>
    </div>
  </section>

  <!-- aisles -->
  <section class="wrap section section--flush-top" style="padding-top:64px">
    <h2 class="h-sect" style="margin-bottom:26px">Shop the four aisles</h2>

    <div class="grid grid--auto">
      <a class="card card--pad card--link" href="/blasters">
        <?php if ($photo = site_photo('aisle-blasters.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="" width="800" height="450" loading="lazy" decoding="async" style="height:96px">
        <?php else: ?>
        <div class="ph" style="aspect-ratio:auto;height:96px;padding:10px">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">aisle-blasters.jpg</span>
        </div>
        <?php endif; ?>
        <strong style="font-family:var(--display);font-size:17px">Blasters</strong>
        <span class="small muted">Pistols, rifles, SMGs and bolt-action, by grade and chrono.</span>
      </a>

      <a class="card card--pad card--link" href="/gear">
        <?php if ($photo = site_photo('aisle-gear.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="" width="800" height="450" loading="lazy" decoding="async" style="height:96px">
        <?php else: ?>
        <div class="ph" style="aspect-ratio:auto;height:96px;padding:10px">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">aisle-gear.jpg</span>
        </div>
        <?php endif; ?>
        <strong style="font-family:var(--display);font-size:17px">Gear</strong>
        <span class="small muted">Eye protection, helmets, vests, gloves, holsters, pads.</span>
      </a>

      <a class="card card--pad card--link" href="/parts">
        <?php if ($photo = site_photo('aisle-parts.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="" width="800" height="450" loading="lazy" decoding="async" style="height:96px">
        <?php else: ?>
        <div class="ph" style="aspect-ratio:auto;height:96px;padding:10px">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">aisle-parts.jpg</span>
        </div>
        <?php endif; ?>
        <strong style="font-family:var(--display);font-size:17px">Parts &amp; upgrades</strong>
        <span class="small muted">Internals, electricals, optics, foregrips, mags and gel.</span>
      </a>

      <a class="card card--pad card--link" href="/request">
        <?php if ($photo = site_photo('aisle-request.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="" width="800" height="450" loading="lazy" decoding="async" style="height:96px">
        <?php else: ?>
        <div class="ph" style="aspect-ratio:auto;height:96px;padding:10px">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">aisle-request.jpg</span>
        </div>
        <?php endif; ?>
        <strong style="font-family:var(--display);font-size:17px">Request a model</strong>
        <span class="small muted">Not stocked in India? Tell us what to source and we quote it.</span>
      </a>
    </div>
  </section>

  <!-- new on the rack -->
  <section class="wrap section">
    <div class="row row--between row--end" style="margin-bottom:26px">
      <h2 class="h-sect">New on the rack</h2>
      <a class="small" style="font-weight:600" href="/blasters">All blasters &rarr;</a>
    </div>

    <?php if ($latest): ?>
      <div class="grid grid--auto">
        <?php foreach ($latest as $p): ?><?= product_card($p) ?><?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty" style="text-align:left">
        <div class="row row--between row--center">
          <div class="col--half">
            <h3 style="font-size:24px;font-weight:700">Restocking soon</h3>
            <p class="muted" style="margin:10px 0 0;max-width:46em">Nothing is on the shelf yet. The first batch is on its way &mdash; leave a number and you get one message the day it lands.</p>
          </div>
          <div class="row row--tight">
            <a class="btn btn--dark" href="#alerts">Set a restock alert</a>
            <a class="btn" href="/request">Request a model</a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <!-- games -->
  <section class="band">
    <div class="wrap section">
      <div class="row row--between row--end" style="margin-bottom:30px">
        <div class="col--half">
          <p class="eyebrow eyebrow--soft">Games in India</p>
          <h2 class="h-sect" style="font-size:clamp(28px,3.4vw,36px);margin-top:14px">Somewhere to actually use it.</h2>
          <p class="on-dark" style="margin-top:16px;max-width:36em">Hosts post their games here with the FPS cap and the fee up front &mdash; listed whether or not they bought anything from us.</p>
        </div>
        <a class="btn btn--primary" href="/games">See all games</a>
      </div>

      <div class="empty empty--dark empty--sm" style="text-align:left">
        <div class="row row--between row--center">
          <p class="on-dark" style="flex:1 1 380px;min-width:0;margin:0">No games on the board yet. If you run games, yours can be the first &mdash; it is free, and we send players your way.</p>
          <a class="btn btn--light btn--sm" href="/games#host">List your game</a>
        </div>
      </div>
    </div>
  </section>

  <!-- marketplace -->
  <section class="band band--olive">
    <div class="wrap section">
      <div class="row row--between row--end" style="margin-bottom:28px">
        <div class="col--half">
          <p class="eyebrow eyebrow--olive">Marketplace</p>
          <h2 class="h-sect" style="font-size:clamp(28px,3.4vw,36px);margin-top:14px">The blaster in your cupboard is somebody&rsquo;s first one.</h2>
          <p style="margin-top:16px;max-width:36em">Most people here are selling one or two things, not running a shop. Free to list, and we hold the payment until the parcel lands.</p>
        </div>
        <div class="row row--tight">
          <a class="btn btn--olive" href="/marketplace">Browse listings</a>
          <a class="btn" style="border-color:var(--olive);color:var(--olive-ink)" href="/sell">List one thing</a>
        </div>
      </div>

      <div class="card pad-xl" style="border-style:dashed;border-color:var(--olive-line)">
        <div class="row row--between row--center">
          <p style="flex:1 1 380px;min-width:0;margin:0">Nothing listed yet. Whoever posts first gets the whole marketplace looking at it.</p>
          <a class="btn btn--olive btn--sm" href="/sell">List one thing</a>
        </div>
      </div>
    </div>
  </section>

  <!-- safety -->
  <section style="border-bottom:1px solid var(--line-2)">
    <div class="wrap row row--between row--center" style="padding:40px 24px">
      <div class="row" style="flex:1 1 520px;min-width:0;gap:16px;flex-wrap:nowrap">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--olive-dark)" stroke-width="1.9" aria-hidden="true" style="flex:0 0 auto;margin-top:2px"><path d="M12 3l8 3.5v5c0 4.6-3.3 8.4-8 9.5-4.7-1.1-8-4.9-8-9.5v-5z" stroke-linejoin="round"/><path d="M9 12l2.2 2.2L15.5 10" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div>
          <h3 class="h-card" style="margin-bottom:6px">Eye protection on, and out of sight in public</h3>
          <p class="small" style="color:var(--ink-3);max-width:60em">Full-seal rated goggles before anyone loads. Carry it concealed in a bag or case &mdash; never openly in public &mdash; and play on private ground with the owner&rsquo;s permission.</p>
        </div>
      </div>
      <a class="btn btn--dark btn--sm" href="/safety">Safety standards</a>
    </div>
  </section>

  <!-- guides -->
  <section class="wrap section">
    <h2 class="h-sect" style="margin-bottom:26px">First time out?</h2>
    <div class="grid grid--auto-l">
      <a class="card card--pad-lg card--link" href="/blasters">
        <span class="eyebrow eyebrow--ember" style="font-size:11.5px">Starter kits</span>
        <h3 style="font-size:20px;font-weight:700;line-height:1.25">What a first loadout actually costs</h3>
        <p class="small muted">A blaster, full-seal goggles, a spare mag, a battery and gel. We bundle the five and show what you can skip for now.</p>
        <span class="small" style="margin-top:auto;padding-top:8px;font-weight:600;color:var(--ember)">See starter kits &rarr;</span>
      </a>
      <a class="card card--pad-lg card--link" href="/games">
        <span class="eyebrow eyebrow--ember" style="font-size:11.5px">Where to play</span>
        <h3 style="font-size:20px;font-weight:700;line-height:1.25">Games being hosted near you</h3>
        <p class="small muted">Hosted games across the country, with the FPS cap, the fee and whether rentals exist for someone turning up with nothing.</p>
        <span class="small" style="margin-top:auto;padding-top:8px;font-weight:600;color:var(--ember)">Find a game &rarr;</span>
      </a>
      <a class="card card--pad-lg card--link" href="/marketplace">
        <span class="eyebrow eyebrow--ember" style="font-size:11.5px">Buying used</span>
        <h3 style="font-size:20px;font-weight:700;line-height:1.25">How to check a second-hand blaster</h3>
        <p class="small muted">What to ask before you pay, what a fair price looks like, and which faults are cheap to fix.</p>
        <span class="small" style="margin-top:auto;padding-top:8px;font-weight:600;color:var(--ember)">Read the checklist &rarr;</span>
      </a>
    </div>
  </section>

  <!-- restock alerts -->
  <section class="wrap section section--flush-top" id="alerts" style="padding-bottom:64px">
    <div class="panel row row--between row--center" style="padding:40px">
      <div class="col--half">
        <h2 style="font-size:26px;font-weight:700">Restock alerts, not a newsletter</h2>
        <p class="muted" style="margin-top:10px;max-width:44em">Stock lands in batches and goes in days. Tell us the model you are waiting on and you get one message when it is on the shelf.</p>
      </div>
      <div style="flex:1 1 320px;min-width:0"><?php require __DIR__ . '/inc/alert-form.php'; ?></div>
    </div>
  </section>

<?php require __DIR__ . '/inc/footer.php';
