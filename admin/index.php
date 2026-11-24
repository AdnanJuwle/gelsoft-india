<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/shop.php';
require dirname(__DIR__) . '/inc/cart.php';
require dirname(__DIR__) . '/inc/payments.php';
require dirname(__DIR__) . '/inc/orders.php';
require dirname(__DIR__) . '/inc/mail.php';
require dirname(__DIR__) . '/inc/listings.php';
require dirname(__DIR__) . '/inc/listing_sales.php';
require dirname(__DIR__) . '/inc/community.php';
require dirname(__DIR__) . '/inc/alerts.php';
require dirname(__DIR__) . '/inc/uploads.php';

// Read-only for now. The screens that write arrive in phase 3.
$stats = ['published' => 0, 'drafts' => 0, 'out' => 0, 'alerts' => 0, 'to_pack' => 0, 'revenue' => 0, 'queue' => 0, 'disputes' => 0, 'requests' => 0, 'games' => 0, 'admins' => 0];
$recent = $pages = $threads = $orders = [];
$usage  = uploads_usage();
if (db_ready()) {
    $stats['published'] = (int) (q1("SELECT COUNT(*) n FROM products WHERE status = 'published'")['n'] ?? 0);
    $stats['drafts']    = (int) (q1("SELECT COUNT(*) n FROM products WHERE status = 'draft'")['n'] ?? 0);
    $stats['out']       = (int) (q1("SELECT COUNT(*) n FROM products WHERE status = 'published' AND stock_qty = 0")['n'] ?? 0);
    $stats['alerts']    = (int) (q1('SELECT COUNT(*) n FROM restock_alerts WHERE notified_at IS NULL')['n'] ?? 0);
    $stats['to_pack']   = (int) (q1("SELECT COUNT(*) n FROM orders WHERE status = 'paid'")['n'] ?? 0);
    $stats['revenue']   = (int) (q1("SELECT COALESCE(SUM(total_paise),0) n FROM orders
                                     WHERE status IN ('paid','packed','shipped','delivered')
                                       AND created_at > (NOW() - INTERVAL 30 DAY)")['n'] ?? 0);
    $orders = q('SELECT * FROM orders ORDER BY created_at DESC LIMIT 15');
    $stats['queue'] = (int) (q1("SELECT COUNT(*) n FROM listings WHERE status = 'pending'")['n'] ?? 0);
    $stats['disputes'] = (int) (q1("SELECT COUNT(*) n FROM listing_orders WHERE status = 'disputed'")['n'] ?? 0);
    $stats['requests'] = (int) (q1("SELECT COUNT(*) n FROM sourcing_requests WHERE status IN ('new','quoting')")['n'] ?? 0);
    $stats['games']    = (int) (q1("SELECT COUNT(*) n FROM games WHERE status = 'pending'")['n'] ?? 0);
    $stats['admins']   = (int) (q1("SELECT COUNT(*) n FROM users WHERE role IN ('admin','owner')")['n'] ?? 0);
    $recent = q('SELECT id, slug, name, category, brand, grade, price_paise, stock_qty, stock_label, status
                 FROM products ORDER BY updated_at DESC LIMIT 20');
    $pages  = q('SELECT slug, title, updated_at FROM pages ORDER BY title');
    $threads = q("SELECT t.id, t.flagged, t.last_message_at,
                         COALESCE(l.title, g.title) AS listing,
                         CASE WHEN t.game_id IS NOT NULL THEN 'game' ELSE 'listing' END AS subject_kind,
                         b.name AS buyer, s.name AS seller,
                         (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id) AS msgs
                  FROM threads t
                  LEFT JOIN listings l ON l.id = t.listing_id
                  LEFT JOIN games g ON g.id = t.game_id
                  JOIN users b ON b.id = t.buyer_id
                  JOIN users s ON s.id = t.seller_id
                  ORDER BY t.flagged DESC, t.last_message_at DESC
                  LIMIT 30");
}

$page = ['title' => 'Admin'];
require dirname(__DIR__) . '/inc/admin_header.php';
?>

<div class="wrap admin-shell" data-tabs="admin">

  <h1 class="sr-only">Admin</h1>

  <nav class="admin-nav" aria-label="Admin sections" role="tablist">
    <button role="tab" type="button" data-tab="overview" aria-selected="true"><span>Overview</span></button>
    <button role="tab" type="button" data-tab="products" aria-selected="false"><span>Products &amp; stock</span></button>
    <button role="tab" type="button" data-tab="orders" aria-selected="false"><span>Orders</span><?php if ($stats['to_pack']): ?><span class="admin-nav__badge"><?= (int) $stats['to_pack'] ?></span><?php endif; ?></button>
    <button role="tab" type="button" data-tab="queue" aria-selected="false"><span>Marketplace</span><?php if ($stats['queue'] + $stats['disputes']): ?><span class="admin-nav__badge"><?= (int) ($stats['queue'] + $stats['disputes']) ?></span><?php endif; ?></button>
    <button role="tab" type="button" data-tab="community" aria-selected="false"><span>Requests &amp; games</span><?php if ($stats['requests'] + $stats['games']): ?><span class="admin-nav__badge"><?= (int) ($stats['requests'] + $stats['games']) ?></span><?php endif; ?></button>
    <button role="tab" type="button" data-tab="messages" aria-selected="false"><span>Conversations</span></button>
    <button role="tab" type="button" data-tab="policies" aria-selected="false"><span>Policy pages</span></button>
    <button role="tab" type="button" data-tab="staff" aria-selected="false"><span>Admins</span></button>
    <p class="admin-nav__note">Everything you change here is live on the site straight away. There is no staging copy.</p>
  </nav>

  <main class="admin-main">

    <!-- overview -->
    <section class="tabpanel" role="tabpanel" data-panel="overview" aria-label="Overview">
      <div class="admin-head">
        <div>
          <h2>Overview</h2>
          <p>What needs doing today, and the state of the shop.</p>
        </div>
      </div>

      <?php if (!db_ready()): ?>
        <div class="notice notice--paper" style="margin-bottom:20px">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.1" stroke-linecap="round"/></svg>
          <p class="small"><strong>No database.</strong> The shop is serving its empty state to everyone. Check the <code>db</code> block in <code>inc/config.php</code>.</p>
        </div>
      <?php endif; ?>

      <dl class="grid grid--auto-s">
        <div class="stat"><dt>Items published</dt><dd><?= $stats['published'] ?></dd>
          <p class="note"><?= $stats['published'] === 0 ? 'Shop shows a placeholder' : 'Live on the shop pages' ?></p></div>
        <div class="stat"><dt>Drafts</dt><dd><?= $stats['drafts'] ?></dd>
          <p class="note"><?= $stats['drafts'] === 0 ? 'Nothing half-written' : 'Not visible to anyone yet' ?></p></div>
        <div class="stat"><dt>Orders to pack</dt><dd><?= $stats['to_pack'] ?></dd>
          <p class="note"><?= $stats['to_pack'] === 0 ? 'Nothing waiting' : 'Paid and waiting on you' ?></p></div>
        <div class="stat"><dt>Last 30 days</dt><dd style="font-size:26px"><?= money($stats['revenue']) ?></dd>
          <p class="note">Paid orders</p></div>
        <div class="stat"><dt>Published, no stock</dt><dd><?= $stats['out'] ?></dd>
          <p class="note"><?= $stats['out'] === 0 ? 'Everything on the shelf is buyable' : 'Listed but cannot be bought' ?></p></div>
        <div class="stat"><dt>Restock alerts queued</dt><dd><?= $stats['alerts'] ?></dd>
          <p class="note"><?= $stats['alerts'] === 0 ? 'Nobody waiting' : 'People waiting to hear' ?></p></div>
        <div class="stat"><dt>Photos stored</dt><dd style="font-size:26px"><?= e(bytes_human($usage['bytes'])) ?></dd>
          <p class="note"><?= $usage['files'] ?> file<?= $usage['files'] === 1 ? '' : 's' ?><?php
            if ($usage['average'] > 400 * 1024): ?>, averaging <?= e(bytes_human($usage['average'])) ?>
              &mdash; high, check GD is installed<?php
            elseif ($usage['files']): ?>, averaging <?= e(bytes_human($usage['average'])) ?><?php
            endif; ?></p></div>
      </dl>

      <div class="row">
        <div class="card card--pad-lg col">
          <h2 class="h-kicker" style="margin-bottom:16px">Needs you</h2>
          <ul class="list list--rule" style="font-size:14.5px">
            <li style="justify-content:space-between">
              <span style="display:flex;gap:11px"><span aria-hidden="true" style="width:8px;height:8px;border-radius:50%;background:var(--ember);margin-top:7px;flex:0 0 auto"></span> Add your first items so the shop pages stop showing &ldquo;restocking soon&rdquo;.</span>
              <a class="btn btn--xs" href="/admin/add-item">Add items</a>
            </li>
            <li style="justify-content:space-between">
              <span style="display:flex;gap:11px"><span aria-hidden="true" style="width:8px;height:8px;border-radius:50%;background:var(--olive);margin-top:7px;flex:0 0 auto"></span> Check the FAQ reads the way you want before launch.</span>
              <a class="btn btn--xs" href="/admin/faq">Open FAQ</a>
            </li>
            <li style="justify-content:space-between;border-bottom:none;padding-bottom:0">
              <span style="display:flex;gap:11px"><span aria-hidden="true" style="width:8px;height:8px;border-radius:50%;background:var(--olive);margin-top:7px;flex:0 0 auto"></span> Put the first two or three games on the board yourself.</span>
              <button class="btn btn--xs" type="button" data-tab="games" role="tab" aria-selected="false">Open games</button>
            </li>
          </ul>
        </div>

        <div class="card card--pad-lg" style="flex:1 1 280px;min-width:260px">
          <h2 class="h-kicker" style="margin-bottom:16px">Shop status</h2>
          <p class="small" style="color:var(--ink-3);line-height:1.7">The shop, parts and gear pages are showing <strong>&ldquo;restocking soon&rdquo;</strong> because nothing is published yet. The moment you publish an item, the placeholder disappears and the grid takes over.</p>
          <a class="btn btn--dark btn--sm" href="/admin/add-item" style="margin-top:16px">Add the first item</a>
        </div>
      </div>
    </section>

    <!-- products -->
    <section class="tabpanel" role="tabpanel" data-panel="products" aria-label="Products and stock" hidden>
      <div class="admin-head">
        <div>
          <h2>Products &amp; stock</h2>
          <p>Everything on the shop pages. Edit stock in place; publishing is per item.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/admin/add-item">Add an item</a>
      </div>

      <div class="toolbar">
        <label class="sr-only" for="psearch">Search items</label>
        <input class="input" id="psearch" type="search" placeholder="Search items&hellip;">
        <label class="sr-only" for="pcat">Filter by category</label>
        <select class="select" id="pcat"><option>All categories</option><option>Blasters</option><option>Gear</option><option>Parts</option><option>Mags &amp; ammo</option></select>
        <label class="sr-only" for="pstat">Filter by status</label>
        <select class="select" id="pstat"><option>Any status</option><option>Published</option><option>Draft</option><option>Out of stock</option></select>
      </div>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Published items</caption>
          <thead>
            <tr>
              <th scope="col">Item</th><th scope="col">Brand</th><th scope="col">Grade</th>
              <th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Live</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recent): ?>
            <tr>
              <td colspan="7" style="padding:56px 24px;text-align:center">
                <h3 style="font-size:19px;font-weight:700;margin-bottom:8px">No items yet</h3>
                <p class="small muted" style="max-width:38em;margin:0 auto 20px">Add a blaster, a piece of gear or a part and it appears on the shop immediately. Brand is optional &mdash; plenty of what sells here has no brand worth printing.</p>
                <a class="btn btn--dark btn--sm" href="/admin/add-item">Add an item</a>
              </td>
            </tr>
            <?php else: foreach ($recent as $r): ?>
            <tr>
              <td>
                <a href="<?= e(shop_path($r['category']) . '/' . rawurlencode($r['slug'])) ?>"><?= e($r['name']) ?></a>
                <span class="tiny muted" style="display:block"><?= e($r['category']) ?></span>
              </td>
              <td><?= $r['brand'] ? e($r['brand']) : '<span class="muted">&mdash;</span>' ?></td>
              <td><?= $r['grade'] ? grade_mark($r['grade']) : '<span class="muted">&mdash;</span>' ?></td>
              <td><?= money((int) $r['price_paise']) ?></td>
              <td><?= (int) $r['stock_qty'] ?: '<span class="tag-warn">0</span>' ?></td>
              <td><?= $r['status'] === 'published' ? '<span class="tag-ok">Live</span>' : '<span class="tag-warn">' . e(ucfirst($r['status'])) . '</span>' ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn btn--xs" href="/admin/add-item?edit=<?= (int) $r['id'] ?>">Edit</a>
                <form method="post" action="/admin/add-item" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_item">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn--xs" type="submit" style="color:var(--bad)"
                          data-confirm="Delete this item and its photos? That cannot be undone.">Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- marketplace queue -->
    <section class="tabpanel" role="tabpanel" data-panel="orders" aria-label="Orders" hidden>
      <div class="admin-head">
        <div>
          <h2>Orders</h2>
          <p><strong><?= (int) $stats['to_pack'] ?></strong> paid and waiting to be packed.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/admin/orders">Open the orders screen</a>
      </div>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Recent orders</caption>
          <thead><tr><th scope="col">Reference</th><th scope="col">When</th><th scope="col">Who</th><th scope="col">Total</th><th scope="col">Status</th></tr></thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr><td colspan="5" style="padding:44px 24px;text-align:center">
                <p class="small muted" style="margin:0">No orders yet. They land here the moment one is paid.</p>
              </td></tr>
            <?php else: foreach ($orders as $o): ?>
              <tr>
                <td><a href="/admin/orders?id=<?= (int) $o['id'] ?>"><?= e($o['reference']) ?></a></td>
                <td class="tiny muted"><?= e(date('j M, H:i', strtotime((string) $o['created_at']))) ?></td>
                <td><?= e($o['ship_name']) ?></td>
                <td><?= money((int) $o['total_paise']) ?></td>
                <td><span class="<?= in_array($o['status'], ['paid','packed','shipped','delivered'], true) ? 'tag-ok' : 'tag-warn' ?>"><?= e(order_status_label((string) $o['status'])) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="tabpanel" role="tabpanel" data-panel="queue" aria-label="Marketplace" hidden>
      <div class="admin-head">
        <div>
          <h2>Marketplace</h2>
          <p><strong><?= (int) $stats['queue'] ?></strong> listing<?= $stats['queue'] === 1 ? '' : 's' ?> waiting for review<?= $stats['disputes'] ? ', and ' . (int) $stats['disputes'] . ' disputed sale' . ($stats['disputes'] === 1 ? '' : 's') : '' ?>.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/admin/marketplace">Open the marketplace screen</a>
      </div>

      <div class="card card--pad-lg">
        <p class="small muted">Reviewing every listing before it goes up is what makes the rules on the sell form real: photos that are actually theirs, no used eye protection, nothing repainted to pass as a real firearm. Approving is one click; sending one back asks for a reason, which the seller gets by email.</p>
        <p class="small muted" style="margin-top:12px">Held payments are released from the same screen, once the buyer has confirmed or the <?= SALE_CONFIRM_HOURS ?>-hour window has closed.</p>
      </div>
    </section>

    <section class="tabpanel" role="tabpanel" data-panel="community" aria-label="Requests and games" hidden>
      <div class="admin-head">
        <div>
          <h2>Requests &amp; games</h2>
          <p><strong><?= (int) $stats['requests'] ?></strong> sourcing request<?= $stats['requests'] === 1 ? '' : 's' ?> open,
             <strong><?= (int) $stats['games'] ?></strong> game<?= $stats['games'] === 1 ? '' : 's' ?> waiting,
             <strong><?= (int) $stats['alerts'] ?></strong> restock alert<?= $stats['alerts'] === 1 ? '' : 's' ?> queued.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/admin/community">Open the screen</a>
      </div>

      <div class="card card--pad-lg">
        <p class="small muted">Answering a sourcing request emails the person who asked <em>and</em> everyone who backed it, which is the point of the public board: eight people behind one model is what makes a low-volume import worth ordering.</p>
        <p class="small muted" style="margin-top:12px">Restock alerts go out from here too &mdash; one message each, then they come off the list, because that is what the form promised them.</p>
      </div>
    </section>

    <section class="tabpanel" role="tabpanel" data-panel="games" aria-label="Games in India" hidden>
      <div class="admin-head">
        <div>
          <h2>Games in India</h2>
          <p>Games submitted by hosts, and ones you add yourself.</p>
        </div>
        <a class="btn btn--sm" href="/games">View live page</a>
      </div>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Submitted games</caption>
          <thead>
            <tr><th scope="col">Game</th><th scope="col">City</th><th scope="col">Date</th><th scope="col">Cap</th><th scope="col">Action</th></tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="5" style="padding:48px 24px;text-align:center">
                <h3 style="font-size:19px;font-weight:700;margin-bottom:8px">No games submitted yet</h3>
                <p class="small muted" style="max-width:40em;margin:0 auto 20px">Hosts submit through the public page and land here for approval. You can also add one yourself &mdash; useful for the first few, before hosts know the board exists.</p>
                <button class="btn btn--dark btn--sm" type="button">Add a game</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- policies -->
    <section class="tabpanel" role="tabpanel" data-panel="messages" aria-label="Conversations" hidden>
      <div class="admin-head">
        <div>
          <h2>Conversations</h2>
          <p>Every buyer-and-seller thread on the site. This is why the rule is that it stays on the listing and not in DMs &mdash; when a sale goes wrong, there is something here to read.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">Marketplace conversations</caption>
          <thead>
            <tr><th scope="col">Listing</th><th scope="col">Buyer</th><th scope="col">Seller</th><th scope="col">Messages</th><th scope="col">Last</th><th scope="col">Open</th></tr>
          </thead>
          <tbody>
            <?php if (!$threads): ?>
              <tr><td colspan="6" style="padding:44px 24px;text-align:center">
                <p class="small muted" style="margin:0">No conversations yet. They start when a buyer messages a seller from a listing.</p>
              </td></tr>
            <?php else: foreach ($threads as $t): ?>
              <tr>
                <td><?= e((string) $t["listing"]) ?><?= $t["subject_kind"] === "game" ? " <span class=\"tiny muted\">game</span>" : "" ?><?= $t['flagged'] ? ' <span class="tag-warn">flagged</span>' : '' ?></td>
                <td><?= e($t['buyer']) ?></td>
                <td><?= e($t['seller']) ?></td>
                <td><?= (int) $t['msgs'] ?></td>
                <td class="tiny muted"><?= $t['last_message_at'] ? e(date('j M, H:i', strtotime((string) $t['last_message_at']))) : '&mdash;' ?></td>
                <td><a class="btn btn--xs" href="/admin/thread?id=<?= (int) $t['id'] ?>">Read</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="tabpanel" role="tabpanel" data-panel="policies" aria-label="Policy pages" hidden>
      <div class="admin-head">
        <div>
          <h2>Policy pages</h2>
          <p>Replacement, terms and privacy. Plain text in, page out.</p>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$pages): ?>
          <div class="rowline"><p class="small muted" style="margin:0">No pages in the database. Import <code>db/seed-content.sql</code>.</p></div>
        <?php else: foreach ($pages as $row): ?>
          <div class="rowline">
            <span class="title"><?= e($row['title']) ?></span>
            <span class="meta">edited <?= e(date('j M Y', strtotime((string) $row['updated_at']))) ?></span>
            <span class="actions">
              <a class="btn btn--xs" href="/admin/pages?slug=<?= e(urlencode($row['slug'])) ?>">Edit</a>
              <a class="btn btn--xs" href="/<?= e($row['slug']) ?>">View</a>
            </span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section class="tabpanel" role="tabpanel" data-panel="staff" aria-label="Admins" hidden>
      <div class="admin-head">
        <div>
          <h2>Admins</h2>
          <p><strong><?= (int) $stats['admins'] ?></strong> account<?= $stats['admins'] === 1 ? '' : 's' ?> can reach this panel.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="/admin/staff">Open the screen</a>
      </div>

      <div class="card card--pad-lg">
        <p class="small muted">An admin reads every conversation, changes prices and releases held marketplace money &mdash; so it is granted to an account the person made themselves, never by handing out a password. They register, you promote.</p>
        <p class="small muted" style="margin-top:12px">Nothing can remove the last admin, and nobody can remove their own access, because either one locks the panel and leaves phpMyAdmin as the only way back in.</p>
      </div>
    </section>

  </main>
</div>

<?php require dirname(__DIR__) . '/inc/admin_footer.php';
