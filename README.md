# Gelsoft India — gelsoft.in

Storefront, marketplace and admin pages for Gelsoft India. PHP 8 and one
stylesheet, one script — no framework, no build step, no dependencies to
install. Pages are plain HTML with a shared header and footer; the database and
the forms arrive phase by phase, per [`BACKEND.md`](BACKEND.md).

## Running it

Needs PHP 8.1 or newer. `php -v` to check; if it is missing, install it
(Windows: `winget install PHP.PHP.8.3`, or XAMPP if you want MySQL too).

```bash
cd gelsoft-india
php -S localhost:8099 router.php
```

Then open <http://localhost:8099>. `router.php` stands in for the `.htaccess`
rewrite so `/blasters` serves `blasters.php` locally, the same as on the server.

First run only: `cp inc/config.example.php inc/config.php` and set
`'env' => 'local'`.

Without a database every page renders its empty state and nothing breaks, so
you can work on the site as it is. To work on the shop you want one:

```bash
# Windows: winget install MariaDB.Server   (or use XAMPP)
mysql -u root -e "CREATE DATABASE gelsoft CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root gelsoft < db/schema.sql
mysql -u root gelsoft < db/schema-auth.sql
mysql -u root gelsoft < db/schema-orders.sql
mysql -u root gelsoft < db/schema-marketplace.sql
mysql -u root gelsoft < db/schema-community.sql
mysql -u root gelsoft < db/schema-resets.sql
mysql -u root gelsoft < db/schema-owner.sql
mysql -u root gelsoft < db/schema-seller-checks.sql
mysql -u root gelsoft < db/schema-games-2.sql
mysql -u root gelsoft < db/schema-offers.sql
mysql -u root gelsoft < db/seed-content.sql
mysql -u root gelsoft < db/dev-sample-data.sql   # local fixture, see below
php tools/make-admin.php you@gelsoft.in "Your Name"
```

Then copy `inc/config.example.php` to **`inc/config.local.php`** and fill in the `db`
block. That name — not `config.php` — is what keeps a laptop config from ever
being uploaded over the live one.

**`db/dev-sample-data.sql` never goes near the live database.** The model names
in it are real, the prices and chrono figures are invented, and it exists only
so the shop can be built against a shelf that is not empty. The live shop is
filled from the admin panel, one real item at a time. Undo it with
`DELETE FROM products WHERE slug LIKE 'dev-%'`.

## Layout

```
index.php             Home
blasters.php          Blasters, filterable by type / brand / grade / gears
gear.php              Eye protection, helmets, rigs, gloves, holsters, pads
parts.php             Internals, electricals, optics, mags and gel
product.php           One product — served as /blasters/<slug>, /gear/<slug>, …
search.php            One list across every shelf
page.php              A page out of the database — /terms, /privacy
login.php register.php logout.php   Customer accounts
forgot.php reset.php   Password reset, by emailed one-time link
game-message.php      Start a conversation with a game's host
messages.php          Your conversations, and one conversation
cart.php checkout.php order.php   Buying from the shop
buy.php sale.php       Buying a listing, and managing that sale
listing-message.php   POST target that opens a thread from a listing
request.php           Request a model (sourcing) + the open-request board
marketplace.php       Second-hand listings
listing.php           Marketplace listing — template
sell.php              Create a listing, and pick a service tier
account.php           Customer account: orders, addresses, alerts, listings
faq.php               FAQ
safety.php            Safety standards
games.php             Games in India + host submission
policy.php            Replacement & refund policy
404.php               Not found

inc/bootstrap.php     Config, session, error handling. Required by every page
inc/config.php        Secrets. Gitignored — copy config.example.php to make it
inc/helpers.php       cfg() e() money() url() asset() redirect()
inc/db.php            One lazy PDO connection, plus q() q1() exec_sql()
inc/csrf.php          Token for anything that writes
inc/auth.php          Sign-in, sessions, require_login() / require_admin()
inc/messages.php      Buyer-and-seller threads on a listing
inc/shop.php          Reading the catalogue: filters, counts, cards, labels
inc/cart.php          The session cart, priced from the database every time
inc/orders.php        Creating an order, and the one place it is marked paid
inc/payments.php      Razorpay, over plain HTTPS — no SDK, no Composer
inc/seller_checks.php Seller verification: checks we did, never documents
inc/uploads.php       Image uploads, re-encoded rather than copied.
                      UPLOAD_PROFILES sets size and count per kind
inc/mail.php          SMTP, and the order emails
inc/listings.php      Marketplace listings: filters, cards, the fee split
inc/listing_sales.php A sale, from paid to paid out. The state machine
inc/community.php     Sourcing requests and the games board
inc/alerts.php        Sending a restock alert, once, then marking it done
inc/pagination.php    The pager, carrying the current filters
inc/alert-form.php    "Tell me when it lands", used by every empty state
inc/header.php        <head>, util strip, header, nav — the $page contract
inc/footer.php        Footer and scripts
inc/admin_header.php  The admin bar, for pages under /admin/
inc/.htaccess         Denies the whole folder to the web

admin/login.php       Admin sign-in. The only admin page not behind a session
admin/staff.php       Who can reach the panel. Promote and remove admins
admin/index.php       Dashboard: stock, queue, conversations, FAQ, policy
admin/faq.php         FAQ editor — add, edit, reorder, publish, remove
admin/pages.php       Policy page editor
admin/thread.php      Read one conversation, read-only
admin/add-item.php    Add an item to the shop
admin/.htaccess       Where to switch on password protection for /admin/
tools/make-admin.php  Create the first admin. Run once, from a shell

api/alerts.php        Restock alerts — answers JSON or a plain form post
api/cart.php          Add, change, remove a cart line
api/webhook.php       Razorpay → us. The ONLY thing that marks an order paid
api/payment-return.php  The browser reporting back. Deliberately powerless
api/order-status.php  Has the webhook landed yet?
db/schema.sql         Products and stock. Safe to re-run
db/schema-auth.sql    Accounts, listings, conversations, editable copy
db/schema-orders.sql  Orders, addresses, payment events, the mail log
db/schema-marketplace.sql  Listing sales, their history, payout details
db/schema-community.sql    Sourcing requests, their backers, the games board
db/schema-resets.sql  Password reset links, stored as hashes
db/schema-owner.sql   The owner role, one level above admin
db/schema-seller-checks.sql  Seller verification, with no ID stored
db/schema-games-2.sql Game banners, and threads that carry a game
db/schema-offers.sql  Offer state: accepted, declined, expired, spent
db/seed-content.sql   The FAQ and policy text, moved into the database
db/dev-sample-data.sql  LOCAL fixture. Never run this on the live database
SECURITY.md           What is protected, what is not, what to check at launch
STATUS.md             What works end to end, and what only looks finished
DEPLOY.md             Getting it onto Hostinger, step by step
uploads/              Product and listing photos, with PHP execution off
router.php            Local dev only — Hostinger uses .htaccess instead
.htaccess             Routing, https, clean URLs, compression, caching, headers
robots.txt            Allows everything except /admin/, /api/, /account
sitemap.php           /sitemap.xml, generated: shelves, every published
                      product, every live listing, with lastmod
tools/                Command-line only, denied over the web
assets/css/site.css   Design tokens, every storefront component, the mobile pass
assets/css/admin.css  Admin-only additions
assets/js/site.js     Nav, accordion, tabs, filters, steppers, payout, previews
assets/img/NEEDED.md  The photos the site is waiting for, with sizes
```

## How a page is put together

Every page is a `$page` array, the header, the markup, the footer:

```php
<?php
$page = [
    'title' => 'Blasters',
    'desc'  => 'Gel blasters by type, brand, grade and gears.',
    'nav'   => 'blasters',
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap">…</div>
<?php require __DIR__ . '/inc/footer.php';
```

`inc/header.php` documents every key it accepts. The nav is one array in that
file, so adding a page to the menu is one line.

## Deploying to Hostinger

No build step, so it is a straight upload.

**[`DEPLOY.md`](DEPLOY.md) is the step-by-step version**, including the
database, the admin account and what to check afterwards. The short form:

1. hPanel > **File Manager**, open `public_html`, and delete the default
   `index.html` Hostinger puts there — along with the `.html` pages from the
   previous upload, which `index.php` and friends replace.
2. Upload **the contents** of this folder into `public_html` — not the folder
   itself. `index.php` must sit at `public_html/index.php`.
3. Make sure hidden files came across: `.htaccess` at the root, and the ones in
   `admin/`, `inc/` and `uploads/`. File Manager hides dotfiles until you turn
   on *Settings > Show hidden files*; uploading a zip and extracting it keeps
   them. **`inc/.htaccess` and `uploads/.htaccess` are not optional** — they are
   what stop anyone fetching your database password or running an upload.
4. Create `inc/config.php` on the server from `inc/config.example.php`, with
   `'env' => 'production'` and the real credentials. It is gitignored, so it
   never arrives with a git deploy — make it once, by hand.
5. hPanel > **PHP Configuration** — PHP 8.1 or newer.
6. hPanel > **SSL** — issue the free certificate. The `.htaccess` already
   redirects http to https and www to the bare domain.
7. Password-protect `/admin/`: hPanel > **Password Protect Directories**, point
   it at `public_html/admin`. The pages have no sign-in of their own yet.
8. Check that `https://gelsoft.in/blasters` works (clean URLs need mod_rewrite),
   that `/blasters.html` redirects to it, and that `https://gelsoft.in/nothing`
   shows the site's own 404 and not Hostinger's.

Deploying over FTP works the same way; point the client at `public_html` and
mirror the folder.

**[`STATUS.md`](STATUS.md) says exactly what is wired and what is not.** In
short: the shop reads from a database, and nobody can buy anything yet.

**The shop reads from a database now; nothing else does yet.** Products, the
product pages, search and restock alerts are real. Still missing: the admin
cannot write, there is no sign-in, no cart and no payment.
[`BACKEND.md`](BACKEND.md) is the plan for the rest, phase by phase — phases 1
and 2 are done.

So the deploy has two extra steps: create the database in hPanel > **Databases
> MySQL**, import `db/schema.sql` through phpMyAdmin, and put the credentials
in `inc/config.php` on the server. Until you do, every page serves its empty
state rather than an error.

## Images

**Nothing on this site is a generated image.** Every spot that wants a photo
shows a dashed placeholder with the filename and the size written on it. The
full shot list is in [`assets/img/NEEDED.md`](assets/img/NEEDED.md), with the
one-line swap that turns a placeholder into an `<img>`.

The only graphics shipped are interface icons — hand-written SVG strokes for
arrows, ticks, the cart, the menu — the CSS grade mark, and
`assets/img/favicon.svg`, which is the wordmark's G drawn in two tags of
text. Nothing was generated; the favicon is the header's own letterform in
the site's own ink and paper, and it is there to be replaced the moment
there is a real mark. There is still no logo file.

## The design system

Everything is driven by the tokens at the top of `site.css`.

- **Ground** — warm paper `--paper` with graphite ink `--ink`.
- **Two accents only** — a muted ember `--ember` and a field olive `--olive`,
  both low chroma. No neon, no gradient washes.
- **Type** — Archivo for headings, Inter for body, both from Google Fonts.
- **Grade mark** — the 1/2/3 bar mark is `.grade.grade--toy|mid|premium`.
  It repeats on every card, filter and spec sheet.
- **Gears chip** — `.chip--metal|nylon|manual`. Gears and gearbox are always
  two separate fields; a nylon gearbox shell can hold metal gears.

## Catalogue, listings and games are empty

Nothing is hard-coded as stock. The shop pages, the marketplace, the games
board and the request board all render their empty state ("Restocking soon",
"Nothing listed yet") until real data exists. Items are added from
`admin/add-item.html`.

Two pages keep sample content on purpose, as layout templates for a real
record: `product.html` and `listing.html`.

## Interactions

`site.js` wires behaviour by data attribute, so markup stays declarative:

| Attribute | What it does |
|---|---|
| `data-nav-toggle` / `data-nav` | Mobile nav |
| `data-accordion="single"` | FAQ accordion, one panel open |
| `data-tabs` + `data-tab` / `data-panel` | Tab groups (account, admin, product) |
| `data-filter-scope` + `data-key` / `data-item` | Filter rails; updates count and empty state |
| `data-stepper` + `data-step` | Quantity, with a live line total |
| `data-press-group` | Swatches and gallery thumbs |
| `data-controls` + `data-when` | Show a block for one select value |
| `data-preview` | Live card preview in the admin item form |

## House rules baked into the copy

These are deliberate and should survive edits:

- No warranties or guarantees. All sales final. Shipping damage is replaced on
  an **uncut unboxing video**. See `policy.html`.
- No age gate or ID-on-delivery copy.
- No orange muzzle-marker claims.
- Conduct rules: eye protection before anyone loads, concealed transport,
  private ground with the owner's permission.
- Grades are **Toy / Mid hobby / Premium**.
- Gears are **metal**, never "steel".
- Marketplace fees: the **seller** pays 8% + handling whatever happens. A
  **buyer** may pay a further 8% to have us inspect the item before it reaches
  them, which never changes what the seller receives. Both rates come from
  `config.php`, never from a number typed into a page.
- Every listing is reviewed before it goes live, and sending one back asks
  for a reason the seller is emailed. That is what makes the four rules on the
  sell form real rather than decorative. Games are reviewed the same way.
- A restock alert is **one message, then the row is marked done**. That promise
  is printed on every form that collects an address, so the code enforces it:
  the row is marked before the mail goes out, and the send button cannot fire
  twice at the same person.
- Contact details come from `config.php` too: `support@gelsoft.in`.

## Still to do

- A password reset, and email verification on sign-up.
- The photos from `assets/img/NEEDED.md`.
- Razorpay keys. The checkout is built and tested; until the keys are in
  `inc/config.php` it saves an order as unpaid and says so.
- The sell form, the request form and the games form still submit nowhere;
  they say so when you try, rather than pretending.
- No email verification yet: an account is usable the moment it is created.
  See [`SECURITY.md`](SECURITY.md). (Password reset is built and tested.)
- Drop in the photos from `assets/img/NEEDED.md`.
- Prices and chrono figures on the template pages are placeholders.
- `product.php` and `listing.php` keep sample content on purpose, as the layout
  for a real record.
- `og-image.jpg` and `apple-touch-icon.png` once you have a mark — the tab
  icon is done, these two are pictures. See `assets/img/NEEDED.md`.
- Accessibility and performance pass.
