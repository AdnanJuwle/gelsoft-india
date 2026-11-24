<?php
/**
 * Pager for a shelf. Expects $result from shop_products() and $f from
 * shop_filters(). Prints nothing when everything fits on one page.
 *
 * Each link carries the current filters, so paging does not silently reset
 * them — which is what happens when a pager is built from the path alone.
 */
if (($result['pages'] ?? 0) < 2) {
    return;
}

$page  = (int) $result['page'];
$pages = (int) $result['pages'];

$link = static function (int $n) {
    $query = $_GET;
    if ($n <= 1) {
        unset($query['page']);
    } else {
        $query['page'] = $n;
    }
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return $query === [] ? $path : $path . '?' . http_build_query($query);
};

// First, last, and two either side of where you are. Everything else is a gap.
$show = [1, $pages, $page, $page - 1, $page + 1, $page - 2, $page + 2];
$show = array_values(array_unique(array_filter($show, static fn($n) => $n >= 1 && $n <= $pages)));
sort($show);
?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?>
    <a class="pager__step" href="<?= e($link($page - 1)) ?>" rel="prev">&larr; Previous</a>
  <?php else: ?>
    <span class="pager__step pager__step--off">&larr; Previous</span>
  <?php endif; ?>

  <span class="pager__nums">
    <?php $last = 0; foreach ($show as $n): ?>
      <?php if ($last && $n > $last + 1): ?><span class="pager__gap">&hellip;</span><?php endif; ?>
      <?php if ($n === $page): ?>
        <span class="pager__num is-current" aria-current="page"><?= $n ?></span>
      <?php else: ?>
        <a class="pager__num" href="<?= e($link($n)) ?>"><?= $n ?></a>
      <?php endif; ?>
    <?php $last = $n; endforeach; ?>
  </span>

  <?php if ($page < $pages): ?>
    <a class="pager__step" href="<?= e($link($page + 1)) ?>" rel="next">Next &rarr;</a>
  <?php else: ?>
    <span class="pager__step pager__step--off">Next &rarr;</span>
  <?php endif; ?>
</nav>
