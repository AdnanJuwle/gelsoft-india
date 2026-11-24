<?php
$page = [
    'title'   => 'Page not found',
    'noindex' => true,
    'main_class' => 'wrap section',
    'main_style' => 'padding:88px 24px',
];
require __DIR__ . '/inc/header.php';
?>
  <p class="eyebrow eyebrow--ember">404</p>
  <h1 class="h-page" style="margin-top:16px;max-width:18em">That page isn&rsquo;t here.</h1>
  <p class="lead measure" style="margin-top:16px">Either we moved it or the link was wrong. Nothing is lost &mdash; here is everything worth going to.</p>

  <div class="grid grid--auto" style="margin-top:36px;max-width:900px">
    <a class="card card--pad card--link" href="/blasters"><strong style="font-family:var(--display);font-size:17px">Blasters</strong><span class="small muted">By type, brand, grade and gears.</span></a>
    <a class="card card--pad card--link" href="/marketplace"><strong style="font-family:var(--display);font-size:17px">Marketplace</strong><span class="small muted">Second-hand kit from verified sellers.</span></a>
    <a class="card card--pad card--link" href="/games"><strong style="font-family:var(--display);font-size:17px">Games in India</strong><span class="small muted">Somewhere to actually use it.</span></a>
    <a class="card card--pad card--link" href="/faq"><strong style="font-family:var(--display);font-size:17px">FAQ</strong><span class="small muted">Legality, delivery, grades, payments.</span></a>
  </div>

  <a class="btn btn--primary" href="/index" style="margin-top:32px">Back to the shop</a>
<?php require __DIR__ . '/inc/footer.php';
