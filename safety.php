<?php
$page = [
    'title'   => 'Safety standards',
    'desc'    => 'Eye protection, concealed transport, private ground. FPS caps, engagement distances, battery and gel handling, and what to do when something goes wrong.',
];
require __DIR__ . '/inc/header.php';
?>
  <section class="wrap" style="padding-top:44px">
    <p class="eyebrow eyebrow--olive">House standards</p>
    <h1 class="h-page" style="margin-top:16px;max-width:24em">The rules we expect, and most hosts in the country run something close.</h1>
    <p class="lead measure" style="margin-top:18px">This page is short on purpose. Three things are not negotiable, the numbers below are our recommended caps, and everything after that is what separates a good Sunday from an ambulance. Where a host publishes different numbers, theirs win &mdash; they know their field.</p>
  </section>

  <!-- non-negotiables -->
  <section class="wrap" style="padding-top:36px">
    <h2 class="h-kicker" style="margin-bottom:20px">Three things that are not negotiable</h2>
    <div class="grid grid--auto-l">

      <div class="card card--pad-lg" style="border-top:3px solid var(--ember)">
        <?php if ($photo = site_photo('safety-eyepro.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="Full-seal goggles" width="1200" height="675" loading="lazy" decoding="async" style="aspect-ratio:16/9;height:auto;margin-bottom:16px">
        <?php else: ?>
        <div class="ph ph--wide" style="margin-bottom:16px">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">safety-eyepro.jpg</span>
        </div>
        <?php endif; ?>
        <h3 style="font-size:19px;font-weight:700;margin-bottom:10px">Full-seal rated eye protection</h3>
        <p class="small" style="color:var(--ink-3);line-height:1.7">On before anyone loads, off only outside the play area. Not sunglasses, not open shooting glasses, not mesh on its own. Everyone present wears them, including spectators and whoever is filming.</p>
      </div>

      <div class="card card--pad-lg" style="border-top:3px solid var(--ember)">
        <?php if ($photo = site_photo('safety-transport.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="A closed carry bag" width="1200" height="675" loading="lazy" decoding="async" style="aspect-ratio:16/9;height:auto;margin-bottom:16px">
        <?php else: ?>
        <div class="ph ph--wide" style="margin-bottom:16px">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">safety-transport.jpg</span>
        </div>
        <?php endif; ?>
        <h3 style="font-size:19px;font-weight:700;margin-bottom:10px">It travels concealed</h3>
        <p class="small" style="color:var(--ink-3);line-height:1.7">Cased, unloaded, battery out, from your door to the game and back. Never slung over a shoulder in public, never out of the bag anywhere but the field. Openly carrying a replica is the one mistake that turns a hobby into a police matter.</p>
      </div>

      <div class="card card--pad-lg" style="border-top:3px solid var(--ember)">
        <?php if ($photo = site_photo('safety-ground.jpg')): ?>
        <img class="ph-img" src="<?= e($photo) ?>" alt="A fenced private play area" width="1200" height="675" loading="lazy" decoding="async" style="aspect-ratio:16/9;height:auto;margin-bottom:16px">
        <?php else: ?>
        <div class="ph ph--wide" style="margin-bottom:16px">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="8.5" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L16 12l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span class="ph__name">safety-ground.jpg</span>
        </div>
        <?php endif; ?>
        <h3 style="font-size:19px;font-weight:700;margin-bottom:10px">Private ground, with permission</h3>
        <p class="small" style="color:var(--ink-3);line-height:1.7">A hosted field, or private property where the owner has said yes. Not a park, not a terrace overlooking a road, not an empty plot nobody is watching. Nothing leaves the boundary &mdash; not a gel, not a muzzle pointed past it.</p>
      </div>
    </div>
  </section>

  <!-- caps -->
  <section class="wrap layout section">
    <div class="col">
      <h2 class="h-sect">Recommended caps and engagement distances</h2>
      <p class="muted measure--wide" style="margin-top:12px">Measured on 7&ndash;8 mm gel. A minimum engagement distance is the range below which you do not shoot at all &mdash; you call it and move on, or tap them out. Closer than that, a hit on an ear or a knuckle does real damage.</p>

      <div class="table-wrap" style="margin-top:24px">
        <table class="table">
          <caption class="sr-only">Recommended FPS caps and minimum engagement distances by game type</caption>
          <thead>
            <tr>
              <th scope="col">Game type</th>
              <th scope="col">FPS cap</th>
              <th scope="col">Min. engagement</th>
              <th scope="col">Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr><th scope="row" style="font-weight:600">Indoor / CQB</th><td style="font-family:var(--display);font-weight:700;font-size:16px">230</td><td style="font-weight:600">None &mdash; tap out</td><td class="muted">Tight corners, no room to back off. Semi-auto only is common indoors.</td></tr>
            <tr><th scope="row" style="font-weight:600">Semi-outdoor</th><td style="font-family:var(--display);font-weight:700;font-size:16px">260</td><td style="font-weight:600">5 m</td><td class="muted">Courtyards, warehouses, built-up ground with open lanes.</td></tr>
            <tr><th scope="row" style="font-weight:600">Outdoor / woodland</th><td style="font-family:var(--display);font-weight:700;font-size:16px">300</td><td style="font-weight:600">10 m</td><td class="muted">Long sight lines. Full auto fine; call hits honestly.</td></tr>
            <tr><th scope="row" style="font-weight:600">Bolt-action only</th><td style="font-family:var(--display);font-weight:700;font-size:16px">350</td><td style="font-weight:600">20 m</td><td class="muted">One shot per bolt pull, so the distance is the safeguard.</td></tr>
          </tbody>
        </table>
      </div>

      <p class="tiny muted" style="margin-top:14px">These are our house numbers, not a standard anyone issued. Your host&rsquo;s published caps always take priority, and a chrono at the gate decides the argument.</p>
    </div>

    <aside class="rail" style="max-width:360px">
      <div class="card card--pad-lg" style="background:var(--dark);border-color:var(--dark);color:var(--on-dark)">
        <h3 class="h-kicker" style="margin-bottom:14px;color:#fff">Chrono before you play</h3>
        <p class="small on-dark" style="line-height:1.7">Every blaster we ship is chrono-tested here and the figure is on the label &mdash; but gel hardness and a fresh battery both move it. If a host chronos at the gate, that reading is the one that counts.</p>
        <p class="small on-dark" style="line-height:1.7;margin-top:14px">Fitted a heavier spring since? Re-chrono it before you turn up, not in the car park while forty people wait.</p>
        <a class="btn btn--light btn--sm" href="/parts" style="margin-top:18px">Chrono and tune &mdash; &#8377;400</a>
      </div>
    </aside>
  </section>

  <!-- on the field -->
  <section class="wrap section section--flush-top">
    <h2 class="h-sect" style="margin-bottom:22px">On the field</h2>
    <div class="grid grid--auto-l">
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">Chrono at the gate</h3><p class="small" style="color:var(--ink-3);line-height:1.7">Everyone, every game day, on the host&rsquo;s chrono with the host&rsquo;s gel. A blaster that reads over the cap sits out or gets a lighter spring fitted.</p></div>
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">Call your hits</h3><p class="small" style="color:var(--ink-3);line-height:1.7">Shout it, raise a hand, walk out. Gel does not always leave a mark, so the whole game runs on people being honest about being hit.</p></div>
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">No blind firing</h3><p class="small" style="color:var(--ink-3);line-height:1.7">If you cannot see where the gel is going, you are not shooting at a player, you are shooting at a direction. Over a wall, round a corner, through foliage &mdash; look first.</p></div>
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">Dead players are out of play</h3><p class="small" style="color:var(--ink-3);line-height:1.7">Hand up, blaster down, straight to the respawn by the shortest safe route. Do not talk to live players, do not pass on positions.</p></div>
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">One person can stop the game</h3><p class="small" style="color:var(--ink-3);line-height:1.7">A marshal, or anyone who sees an injury, a lost goggle or a stranger walking in. Everyone stops, goggles stay on, nobody fires.</p></div>
      <div class="card card--pad"><h3 class="h-card" style="font-size:17px;margin-bottom:10px">Clear it before you leave</h3><p class="small" style="color:var(--ink-3);line-height:1.7">Mag out, battery out, fire into the trap or the ground, then bag it. A loaded blaster in a car boot is the one that goes off in a kitchen.</p></div>
    </div>
  </section>

  <!-- heat -->
  <section class="wrap section section--flush-top">
    <div class="card card--olive card--pad-lg">
      <h2 class="h-sect" style="font-size:26px">Heat is the injury nobody plans for</h2>
      <p style="color:var(--olive-dark);margin-top:12px;max-width:62em">Across most of India, for most of the year, the thing most likely to put someone on the ground is not a gel ball. It is forty minutes of running in a plate carrier at two in the afternoon.</p>
      <div class="grid grid--auto-s" style="margin-top:24px">
        <div><h3 class="small" style="font-weight:700;margin-bottom:8px;color:var(--olive-ink)">Play early or late</h3><p class="small" style="color:var(--olive-dark)">Morning games and evening games. Through April to June the middle of the day is not worth the risk.</p></div>
        <div><h3 class="small" style="font-weight:700;margin-bottom:8px;color:var(--olive-ink)">Water, not energy drinks</h3><p class="small" style="color:var(--olive-dark)">A litre per player per two hours as a floor, plus salt. Hosts should have spare water; players should not rely on that.</p></div>
        <div><h3 class="small" style="font-weight:700;margin-bottom:8px;color:var(--olive-ink)">Dress for the weather</h3><p class="small" style="color:var(--olive-dark)">A chest rig over a breathable tee carries the same mags as a plate carrier at half the trapped heat.</p></div>
        <div><h3 class="small" style="font-weight:700;margin-bottom:8px;color:var(--olive-ink)">Know the signs</h3><p class="small" style="color:var(--olive-dark)">Stopped sweating, confusion, stumbling, a headache that came on fast. Game stops, shade, water, cool them actively, call for help early.</p></div>
      </div>
    </div>
  </section>

  <!-- batteries + gel -->
  <section class="wrap section section--flush-top">
    <div class="row">
      <div class="panel" style="flex:1 1 340px;min-width:0">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:14px">Batteries</h2>
        <ul class="list">
          <li><span class="b" aria-hidden="true">&bull;</span> Charge on a balance charger, in a LiPo bag, on a hard surface. Never on a bed, never overnight, never unattended.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> A pack that is puffed, warm at rest, or has a damaged lead is finished. Dispose of it properly.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> Take the battery out before it goes in the bag. A trigger pressed inside a packed bag is how a gearbox eats itself.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> 11.1V on nylon gears strips teeth. The spec sheet on the product page says which you have.</li>
        </ul>
      </div>

      <div class="panel" style="flex:1 1 340px;min-width:0">
        <h2 class="h-sect" style="font-size:22px;margin-bottom:14px">Gel</h2>
        <ul class="list">
          <li><span class="b" aria-hidden="true">&bull;</span> Soak three to four hours in clean water, drain, use within a day or two. Not overnight.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> Keep soaked gel out of the sun and off the ground. Grit in a magazine ends up in the hop-up.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> It is not food and it is not a toy for children or pets. Unsoaked beads swell after being swallowed &mdash; keep the packet shut and out of reach.</li>
          <li><span class="b" aria-hidden="true">&bull;</span> Clear up after a game. A field that gets littered stops being a field you are allowed on.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- if something goes wrong -->
  <section class="wrap section section--flush-top">
    <div class="band pad-xl" style="border-radius:var(--r-lg)">
      <h2 class="h-sect" style="font-size:26px">If something goes wrong</h2>
      <div class="grid grid--auto-l" style="margin-top:22px">
        <div><h3 style="font-size:16px;font-weight:700;color:#fff;margin-bottom:9px">A hit near the eye</h3><p class="small on-dark" style="line-height:1.7">Call the game stopped. Do not rub it, do not rinse it with anything but clean water, do not let them drive themselves. Any blurred vision, pain or light sensitivity is a hospital trip the same hour.</p></div>
        <div><h3 style="font-size:16px;font-weight:700;color:#fff;margin-bottom:9px">A blaster that will not stop firing</h3><p class="small on-dark" style="line-height:1.7">Point it at the ground, away from everyone, and pull the battery. Do not try to clear it while it is live. It is a stuck trigger contact nine times out of ten.</p></div>
        <div><h3 style="font-size:16px;font-weight:700;color:#fff;margin-bottom:9px">Someone turns up with no eye protection</h3><p class="small on-dark" style="line-height:1.7">They do not play. Lend them a spare or sit them out. There is no version of this where a group of adults decides it is fine just this once.</p></div>
      </div>
    </div>
  </section>

  <!-- hosting -->
  <section class="wrap section section--flush-top" style="padding-bottom:64px">
    <div class="panel row row--between row--center">
      <div class="col--half">
        <h2 class="h-sect" style="font-size:24px">Hosting a game?</h2>
        <p class="muted" style="margin-top:10px;max-width:48em">Publish your caps, chrono at the gate, brief everyone before the first round, and have one person whose only job is to stop the game. List the game with us and we will send players your way.</p>
      </div>
      <a class="btn btn--dark" href="/games">Games in India</a>
    </div>
  </section>
<?php require __DIR__ . '/inc/footer.php';
