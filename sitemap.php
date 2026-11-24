<?php
/**
 * GET /sitemap.xml — the list of pages worth crawling, generated per request.
 *
 * It used to be a hand-written sitemap.xml with twelve URLs on it: the
 * shelves, the information pages, and nothing else. So the entire inventory
 * was missing. Every blaster, every piece of gear, every live marketplace
 * listing: none of them was in it, which is the one part of this site where a
 * sitemap earns anything, because a product page is deep (three clicks from
 * the home page, behind filters) and a listing is short-lived.
 *
 * Generated, not maintained, for the obvious reason: a static file goes stale
 * the moment stock changes, and nobody remembers to edit XML after adding a
 * product.
 *
 * The URLs come from product_url() and listing_url() -- the same functions the
 * pages themselves link with. A sitemap that builds its own URLs out of string
 * concatenation is a sitemap that will eventually advertise a 404, and the one
 * way to be sure it cannot is to not have a second copy of the rule.
 *
 * What is deliberately NOT here:
 *
 *   /account, which the old file listed at priority 0.3 while robots.txt
 *   disallowed it. Telling a crawler to fetch a URL you have also told it not
 *   to fetch is a contradiction Search Console reports back as an error, and
 *   the sitemap was the wrong half: an account page has nothing to index.
 *
 *   /cart, /checkout, /search, /login, /register: same reasoning, and all of
 *   them are already disallowed.
 *
 *   /sell, which the hand-written file did list and which has never been a
 *   public page: require_login() bounces every signed-out visitor, and a
 *   crawler is always signed out, so what it offered for indexing was a
 *   302 to /login. Every page already links to it from the strip above the
 *   header, so a person still finds it. If it ever grows a public half --
 *   what selling here costs, what the fees are, what gets a listing
 *   rejected -- that half belongs in this list and the form does not.
 *
 *   <priority> and <changefreq>. Google has said for years that it ignores
 *   both, and a number nobody reads is a number that can only be wrong.
 *   <lastmod> it does use, and that one the database actually knows.
 */
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shop.php';
require __DIR__ . '/inc/listings.php';

header('Content-Type: application/xml; charset=UTF-8');

// A sitemap may hold 50,000 URLs. Nothing here will approach that for a long
// while, but an unbounded query against a growing table is how a page that
// has always been fast stops being fast without anyone changing it.
const SITEMAP_MAX = 45000;

/** The pages that exist whatever is in the database. */
$urls = [];
foreach ([
    '/',
    '/blasters',
    '/gear',
    '/parts',
    '/marketplace',
    '/request',
    '/games',
    '/faq',
    '/safety',
    '/policy',
] as $path) {
    $urls[] = [$path, null];
}

/*
 * Everything the database knows, if it is reachable. db_ready() rather than a
 * bare query: a sitemap is fetched by a robot, not a person, and answering it
 * with a 500 during a database hiccup is worse than answering with the static
 * pages alone -- Google retries a 500 but it also stops trusting the file.
 */
if (db_ready()) {
    /*
     * The pages kept in the database: /terms and /privacy.
     *
     * page_shadowed() is what keeps /policy out. There is a 'policy' row in
     * that table AND a hand-built policy.php, and the file is what the URL
     * serves -- so listing the row would have put /policy in this sitemap
     * twice, once from the static list above and once from a copy nothing
     * reads. Duplicate <loc> entries are an error in the sitemap; the reason
     * for them was the more interesting half.
     */
    foreach (q('SELECT slug, updated_at FROM pages ORDER BY slug') as $row) {
        if (page_shadowed((string) $row['slug'])) {
            continue;
        }
        $urls[] = ['/' . $row['slug'], $row['updated_at']];
    }

    // Published stock. Archived and draft items are left out: an archived
    // product still answers 200 so that an old link keeps working, but it is
    // not something to invite a crawler to.
    foreach (q(
        'SELECT slug, category, updated_at FROM products
          WHERE status = ? ORDER BY id LIMIT ' . SITEMAP_MAX,
        ['published']
    ) as $row) {
        $urls[] = [product_url($row), $row['updated_at']];
    }

    // Live listings only. A sold or withdrawn one is noindex, and the
    // structured data comes off it for the same reason.
    foreach (q(
        'SELECT slug, updated_at FROM listings
          WHERE status = ? ORDER BY id LIMIT ' . SITEMAP_MAX,
        ['live']
    ) as $row) {
        $urls[] = [listing_url($row), $row['updated_at']];
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";

foreach (array_slice($urls, 0, SITEMAP_MAX) as [$path, $modified]) {
    // The date alone, not the time. A sitemap date is W3C datetime, and a
    // naive "Y-m-d\TH:i:s" carries no offset, so it reads as UTC whatever it
    // really is. inc/db.php now pins the connection to the same zone as PHP,
    // so a full timestamp COULD be emitted correctly -- but the day is all a
    // crawler acts on, and a date cannot be wrong by five and a half hours.
    $lastmod = '';
    if ($modified) {
        $t = strtotime((string) $modified);
        if ($t) {
            $lastmod = '<lastmod>' . date('Y-m-d', $t) . '</lastmod>';
        }
    }
    echo '  <url><loc>', e(abs_url($path)), '</loc>', $lastmod, "</url>\n";
}

echo '</urlset>', "\n";
