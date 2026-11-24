<?php
/**
 * /search?q=… — one list across every shelf. The header search box posts here.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';

$f = shop_filters(['blaster', 'gear', 'part', 'ammo']);
$query = $f['q'];

$result   = $query === '' ? ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => 1] : shop_products($f);
$products = $result['rows'];

$page = [
    'title'   => $query !== '' ? 'Search: ' . $query : 'Search',
    'noindex' => true,
    'util'    => 'none',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap section">
    <nav class="breadcrumb" aria-label="Breadcrumb" style="margin-bottom:18px">
      <a href="/">Home</a><span>/</span><span class="here">Search</span>
    </nav>

    <h1 class="h-page">Search</h1>

    <form class="inline-form" method="get" action="/search" style="margin-top:20px;max-width:34em">
      <label class="sr-only" for="q-page">Search the store</label>
      <input class="input" id="q-page" name="q" type="search" value="<?= e($query) ?>" placeholder="Model, part, brand&hellip;" autofocus>
      <button class="btn btn--dark btn--sm" type="submit">Search</button>
    </form>

    <?php if ($query === ''): ?>
      <p class="muted" style="margin-top:28px">Type a model, a brand or a part &mdash; &ldquo;Gen 8&rdquo;, &ldquo;goggles&rdquo;, &ldquo;gel&rdquo;.</p>

    <?php elseif ($products): ?>
      <p class="small muted" style="margin:28px 0 20px">
        <?= (int) $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?> for &ldquo;<?= e($query) ?>&rdquo;
      </p>
      <div class="grid grid--auto">
        <?php foreach ($products as $p): ?><?= product_card($p) ?><?php endforeach; ?>
      </div>
      <?php require __DIR__ . '/inc/pagination.php'; ?>

    <?php else: ?>
      <div class="empty" style="margin-top:28px">
        <span class="empty__icon" aria-hidden="true">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5" stroke-linecap="round"/></svg>
        </span>
        <h3>Nothing for &ldquo;<?= e($query) ?>&rdquo;</h3>
        <p>Either we do not stock it yet, or it is spelled differently here. If it is something you want, ask us to source it &mdash; that is how we decide what to bring in.</p>
        <div class="row row--tight" style="justify-content:center;gap:20px">
          <a class="btn btn--dark btn--sm" href="/request">Request a model</a>
          <a class="small" style="font-weight:600" href="/marketplace">Try the marketplace &rarr;</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php require __DIR__ . '/inc/footer.php';
