# Making it a real website

Everything in this repo today is front end: HTML, one stylesheet, one script.
It looks finished and it does nothing. This file is the plan for the half that
is missing, written for the Hostinger plan you already have.

## What is actually missing

| Looks like it works | What really happens |
|---|---|
| Shop pages, filters, grades | Markup only. No products exist anywhere |
| "Add to cart" | A button with no handler. No cart, no checkout, no payment |
| Every form (request, sell, games, restock alerts, address) | `onsubmit="return false"` — nothing is sent or stored |
| Account page | A logged-in page for a user that does not exist. No sign-up, no sign-in, no session |
| Admin dashboard and Add an item | A front end for a database that is not there. Save buttons do nothing |
| Marketplace listings, held payment | No listings table, no escrow, no payout |
| FAQ / policy editing | The text is hard-coded in the HTML |

None of that is a flaw in the pages — it is the half you build next.

## The stack: PHP 8 + MySQL

Hostinger web plans run **PHP and MySQL/MariaDB on every tier**, with
phpMyAdmin, cron jobs, free SSL and email included. That is the stack to use.

Reasons, in order:

1. **Nothing gets rewritten.** Pages stay as they are. `shop.html` becomes
   `shop.php` and grows a loop over database rows. The CSS and JS do not change.
2. **No process to babysit.** PHP runs per request. Nothing to restart, no port,
   no build.
3. **Payments need a server anyway.** Razorpay order creation and signature
   verification must happen server-side with a secret key. PHP does that in
   twenty lines.
4. **It is on the plan you bought.** No upgrade, no VPS, no monthly SaaS bill.

Hostinger does now run **Node.js apps on the higher shared tiers** (they show as
"Web Apps" in hPanel, with git deploys), so Express + Postgres is possible if
you are on Business or Cloud. I would still not do it for v1: it means
re-rendering every page through a template engine for no gain on a site this
size. Revisit it when you want realtime chat or a mobile app sharing one API.

**Do not** put a headless SaaS backend (Supabase, Firebase) in front of this.
It is a second bill, a second place for your data, and you still need a server
for payment webhooks.

## Folder layout on the server

```
public_html/
  index.php            was index.html — now loops over products
  blasters.php  gear.php  parts.php  product.php
  marketplace.php  listing.php  sell.php
  account.php  login.php  register.php  logout.php
  cart.php  checkout.php  order-confirmation.php
  faq.php  safety.php  policy.php  games.php  request.php
  404.php  .htaccess  robots.txt  sitemap.php

  assets/              css, js, img — unchanged
  uploads/             product and listing photos (no PHP execution here)
  api/
    cart.php           add / update / remove, returns JSON
    checkout.php       creates a Razorpay order
    webhook.php        Razorpay calls this — the only thing that marks paid
    alerts.php  request.php  game.php  listing.php
  admin/
    index.php  add-item.php  login.php
    .htaccess          password protection stays as a second lock
  inc/
    config.php         DB credentials, keys — never committed
    db.php             one PDO connection
    auth.php           session, login, role check
    csrf.php  mail.php  images.php  helpers.php
    header.php  footer.php   the markup every page repeats today
```

### Escrow, and what a read is worth

`SALE_MOVES` in `inc/listing_sales.php` is the whole state machine: which
status may become which, and who is allowed to do it. Only a buyer says it
arrived or disputes it; only a seller sends it; only an admin settles
anything or releases the money. 46 of the 54 state pairs are not in the table
at all and are refused outright, admin included.

The rule that is easy to miss: **a write may not assume the row still looks
the way it did when you read it.** `sale_release()` used to read the sale,
decide on what it read -- is it disputed? has the buyer's 48 hours run out? --
and then write with

```
WHERE id = ? AND status <> 'released'
```

which does not pin the status it checked. A buyer raising a dispute between
the read and the write had it overwritten, and the seller was paid for an item
the buyer had just said arrived broken. The "settle it first" guard never
fired, because it had been evaluated against a stale read.

It now reads `FOR UPDATE` inside a transaction and pins the write to the
status it read. Both that function and `sale_move()` also check `rowCount()`:
a write that changed nothing must not write an audit row, send an email, or
tick the seller's completed-sale check. For a payout, that trail is exactly
what somebody consults to find out whether a seller was paid twice.

`sale_create()` already did all of this correctly for offers -- it re-resolves
the accepted offer itself, spends it under a lock, and its comment names the
failure it prevents ("charging this buyer the asking price after showing them
the agreed one"). The release was the one place the pattern had not been
applied.

### What a conversation may be about

`thread_open()` refuses to start a NEW conversation about a listing that is
not `live`. Without it, a signed-in person could open one about any listing
id: a draft never submitted, one waiting for review, one an admin rejected
for breaking the rules. The thread view prints that listing's title and
asking price, so it leaked unreviewed content as well as bothering the seller
about something the site had taken down.

The status was already being read there and simply not used, and its twin
`thread_open_game()` had always refused anything not live.

An EXISTING conversation opens whatever the status is now, which is the part
worth keeping separate. A sale in flight moves the listing to `sold`, and
that is exactly when the two of them need to talk about the parcel and the
tracking; cutting them off at that moment would be worse than the bug.

### One blaster, one buyer

A marketplace listing is one physical object, so a sale reserves it:
`sale_create()` refuses while another buyer has a `pending` sale on it less
than `SALE_CHECKOUT_MINUTES` old. Bounded on purpose -- without the window,
one abandoned checkout would take a one-of-a-kind item off the market for
good, and nobody would ever notice.

The reservation is what makes the `FOR UPDATE` lock in that function mean
anything. It re-reads the listing's status, but nothing in that transaction
*changes* the status -- a listing only goes `sold` when the money clears, in
`sale_mark_held()` -- so before this, two buyers both passed the check and
both paid. Measured: two sales, both `held`, the same amount twice, and
nothing anywhere saying a thing was wrong.

A reservation cannot be airtight: the window expires, a webhook is slow,
somebody pays late. So `sale_mark_held()` checks whether another sale already
took the item, and if it did, records the payment anyway -- the money is real
and Razorpay has taken it -- then flags the sale with the reference that beat
it and shows that flag on the admin marketplace screen. Which buyer keeps the
blaster and which gets refunded is a person's decision, not a database's.

### Where the stock goes

Stock comes off at `paid`, not when the cart is filled — an abandoned checkout
must not hold stock nobody bought. It goes back through `order_close()`, and
only from the states where the goods are demonstrably still here:

| order was | cancelled or refunded | stock |
|---|---|---|
| `pending` | never took any | unchanged |
| `paid`, `packed` | off the shelf, not dispatched | **put back** |
| `shipped`, `delivered` | out in the world | unchanged, and the admin is told |

Nothing restored stock at all until this was found: there was exactly one
statement in the codebase that touched `stock_qty` and it was a subtraction,
so every cancellation cost a unit of sellable stock permanently. On a shop
whose `stock_qty` is usually between one and six, two refunds read as "sold
out" while the thing is on the shelf.

The dispatched case is deliberately left alone rather than guessed at.
Whether a shipped parcel comes back is a human fact; restoring it
automatically risks selling something you do not have, and taking money for a
thing you cannot send is worse than under-selling one you could. The admin
screen says so in as many words, so the operator adjusts it by hand when the
parcel returns.

`order_close()` is idempotent, which it has to be: an admin double-clicks, and
Razorpay sends `refund.created` **and** `refund.processed` for one refund. It
locks the row and does nothing if the status is already where it is going —
without that, the stock would go back twice.

### One clock

`inc/bootstrap.php` pins PHP to `Asia/Kolkata` and `inc/db.php` pins the MySQL
session to the same offset on connect. Both are needed: some deadlines are
computed in SQL and tested in PHP (the marketplace inspection window), others
are written by PHP and filtered in SQL (the games board), and each crossing is
wrong by whatever gap exists between the two.

Nothing used to pin MySQL, so it took the host's zone. Measured against a
session forced to UTC -- the ordinary shared-hosting default -- a 48 hour
window set with `DATE_ADD(NOW(), INTERVAL 48 HOUR)` read back as 42.5 hours in
PHP, and the buyer silently lost five and a half hours of their inspection.

It is set as a numeric offset rather than `'Asia/Kolkata'`, because the named
zones need the `mysql.time_zone` tables populated and shared hosting commonly
leaves them empty. An offset cannot follow daylight saving; India has none.

`inc/` and `uploads/` get a `.htaccess` with `Require all denied` /
`php_flag engine off` so nobody can fetch your config or execute an uploaded
file. Keep `config.php` out of git — commit `config.example.php` instead.

The repeated header and footer move into `inc/header.php` and `inc/footer.php`
on day one. That alone removes ~1,500 lines of duplication.

## Database

Start with these tables. MySQL 8 / MariaDB, `utf8mb4_unicode_ci`.

```sql
CREATE TABLE products (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(140) NOT NULL UNIQUE,
  category      ENUM('blaster','gear','part','ammo') NOT NULL,
  brand         VARCHAR(80) NULL,              -- optional, by design
  name          VARCHAR(160) NOT NULL,
  summary       VARCHAR(255) NULL,
  description   TEXT NULL,
  grade         ENUM('toy','mid','premium') NULL,
  type          ENUM('rifle','pistol','smg','bolt') NULL,
  gears         ENUM('metal','nylon','manual') NULL,
  gearbox       VARCHAR(80) NULL,
  fps_min       SMALLINT NULL,
  fps_max       SMALLINT NULL,
  battery       VARCHAR(120) NULL,
  magazine      VARCHAR(120) NULL,
  needs_to_run  VARCHAR(255) NULL,
  fits          VARCHAR(160) NULL,             -- parts: gen8, gen9, v2, any
  sizes         VARCHAR(80) NULL,              -- gear
  price_paise   INT NOT NULL,                  -- integers only. never floats
  stock_qty     INT NOT NULL DEFAULT 0,
  stock_label   ENUM('in','low','pre','soon') NOT NULL DEFAULT 'in',
  status        ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (category, status), INDEX (grade), INDEX (brand)
);

CREATE TABLE product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  alt VARCHAR(255) NULL,
  sort_order TINYINT DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  seller_verified_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,                            -- guest checkout allowed
  reference VARCHAR(20) NOT NULL UNIQUE,       -- GS-2026-0001, shown to people
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  ship_name VARCHAR(120), ship_line1 VARCHAR(190), ship_line2 VARCHAR(190),
  ship_city VARCHAR(90), ship_state VARCHAR(90), ship_pin VARCHAR(10),
  subtotal_paise INT NOT NULL,
  shipping_paise INT NOT NULL DEFAULT 0,
  total_paise INT NOT NULL,
  status ENUM('pending','paid','packed','shipped','delivered','cancelled')
         NOT NULL DEFAULT 'pending',
  payment_provider VARCHAR(20) NULL,
  payment_order_id VARCHAR(80) NULL,
  payment_id VARCHAR(80) NULL,
  tracking VARCHAR(120) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,                         -- keep the row if the product goes
  name_at_purchase VARCHAR(160) NOT NULL,      -- never re-read the live price
  price_paise INT NOT NULL,
  qty SMALLINT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
```

Then, in roughly this order of need: `addresses`, `restock_alerts`,
`sourcing_requests` + `request_backers`, `listings` + `listing_images` +
`offers` + `messages`, `games`, `faq_entries`, `pages` (policy text), and
`cart_items` if you want carts to survive a browser change.

**Money is an integer in paise.** `12500.00` as a float will eventually bill
someone ₹12,499.99.

## Auth

PHP sessions, nothing exotic.

- `password_hash($pw, PASSWORD_DEFAULT)` to store, `password_verify()` to check.
- `session_regenerate_id(true)` on login. Cookie flags: `Secure`, `HttpOnly`,
  `SameSite=Lax`.
- One `require_login()` and one `require_admin()` in `inc/auth.php`, called at
  the top of every page that needs it. Admin is `users.role = 'admin'` — no
  separate admin table.
- Keep the directory password on `/admin/` as well. Two locks on the room with
  the money in it is not paranoid.
- Rate-limit login: count failures per email and per IP, lock for a few minutes.

## Payments (Razorpay)

Razorpay is the usual choice in India, takes UPI, cards and netbanking, and has
a PHP SDK. Cashfree and PhonePe work the same way if you prefer them.

The flow, and why each step is where it is:

1. Browser posts the cart to `api/checkout.php`.
2. **Server** recalculates the total from the `products` table — never from the
   numbers the browser sent — and creates a Razorpay order with the secret key.
3. Browser opens Razorpay Checkout with the returned order id.
4. Razorpay calls **`api/webhook.php`** server-to-server. You verify the
   signature with your webhook secret, then set `orders.status = 'paid'`.
   Only this step marks an order paid. The browser redirect is a convenience;
   it can be faked, closed or lost on a dropped connection.
5. The thank-you page reads the order from the database.

Keys live in `inc/config.php`. Test mode first, with Razorpay's test UPI id.

## Marketplace held payment

The feature the copy promises: the buyer's money is held until they confirm.

- Buyer pays into **your** Razorpay account. `listing_orders.status = 'held'`.
- Seller ships, buyer confirms (or 48 hours pass) → status `released`.
- Payout to the seller's UPI. Do this **by hand from hPanel/Razorpay for the
  first months** — it is a handful of transfers a week and it keeps you out of
  the compliance work. Automate with Razorpay Route only once the volume hurts.
- Dispute inside the window → `held` becomes `refunding`, and you decide.

Write the state machine down before you write the code. Every bug in this
feature is someone's money.

### Two service tiers

A listing sells one of two ways, and `listings.service_tier` records which:

| | `direct` | `inspected` |
|---|---|---|
| Fee | 8% + 2% handling + ₹3 | **15%, nothing else** |
| Who checks it | nobody but the seller | you do — feeds, fires, chrono |
| Who ships it | the seller | you do, from your bench |
| The listing shows | ordinary | an **Inspected by us** mark |

Both rates live in `config.php` under `marketplace`, and the sell page and its
payout preview read them from there — the number is never typed into a page or
into `site.js`.

`inspected` adds states before the listing is even live, so the column alone is
not enough:

```
submitted → in_transit_to_us → received → testing
   → (fails) returned_to_seller
   → (passes) live → sold → held → delivered_by_us → released
```

Three things to settle before building it:

1. **It stays the seller's property throughout.** Record what arrived and when,
   with photographs, or an argument about a scratch has no evidence either way.
2. **What "fails" means**, written down before the first one fails. A chrono 20
   FPS under the claim is not the same as a gearbox that will not turn: one is
   a description you correct, the other goes back.
3. **Who pays the return courier.** The sell page currently says the seller
   does. Whatever you decide, the policy page has to say the same thing.

Storage, testing and delivery are real costs, so 15% is the price of a service
rather than a bigger cut of the same work. Expect to want a floor under it — a
₹3,000 blaster at 15% is ₹450, which does not cover a courier each way.

## Photos

The placeholders in `assets/img/NEEDED.md` are for the fixed pages — the hero,
the safety cards. Product and listing photos come from uploads instead:

- `admin/add-item.php` and `sell.php` accept uploads, validate by **actual
  image type** (`getimagesize`, not the filename), cap at ~5 MB, rename to a
  random string, and write into `uploads/` with PHP execution turned off.
  The reading half is already built: a row in `product_images` renders on the
  card and in the gallery, and a product with none shows the dashed placeholder.
- Resize on upload to two sizes (card ~800px, gallery ~1400px). GD is on by
  default; `imagewebp()` gives you WebP for free.
- Store the path in `product_images`; the page loops over them. The dashed
  placeholder renders only when a product has no image yet.

## Email

Hostinger gives you mailboxes on the domain. Use PHPMailer over SMTP
(`smtp.hostinger.com`, port 465, SSL) rather than `mail()` — it actually
arrives. Transactional only: order confirmation, dispatch with tracking,
password reset, restock alert, listing sold.

## Security checklist

- **Prepared statements everywhere.** PDO with real placeholders, never string
  concatenation. This is the one that loses the whole database.
- **Escape on output**: `htmlspecialchars()` on anything a person typed.
- **CSRF token** in every form that writes, checked server-side.
- Never trust prices, totals, item ids, roles or `user_id` from the browser.
- `uploads/` cannot execute PHP. `inc/` is not web-readable.
- Secrets in `config.php`, which is in `.gitignore`.
- Daily database backup — hPanel does it, but check that it actually runs, and
  download one copy somewhere else. Your only real disaster is a lost database.
- Log admin actions: who changed a price, who approved a listing.

## Build order

Each phase ends with something working on the live site.

**1. Plumbing (half a day).** `inc/` with config, PDO, header, footer. Convert
the pages to `.php` using the shared header and footer. Nothing changes
visually — that is the point.

**2. Products on the shop — done.** `db/schema.sql` creates `products`,
`product_images` and `restock_alerts`. `inc/shop.php` reads them; the three
shelves, the product page, the home page and `/search` all render database
rows. Filters are SQL, driven by the query string, so a filtered shelf is a URL
you can send someone. The empty state is real: it shows only when there is
genuinely nothing, and the whole site still serves if the database is down.

**3. Admin that writes (1–2 days).** Admin login, `add-item.php` saving a row
with photo upload, the stock table editing in place. From here you stop
touching the database by hand. The dashboard already *reads* — real counts, the
item list, and a plain warning when the database is unreachable — so this phase
is the write path and the sign-in, not the screens.

Two things to settle while building it: a **slug** is generated from the name
but stays editable, because changing a name must not break a shared link; and
`status` starts at `draft`, so a half-written item is never briefly live.

**4. Cart, checkout, payment (2–3 days).** Cart in the session, checkout form,
Razorpay in test mode, the webhook, order confirmation, order emails. Go live
only when a test order moves through `pending → paid` by webhook alone.

**5. Accounts (1–2 days).** Register, login, logout, password reset, addresses,
order history. The account page stops being a mock-up.

**6. The forms (1 day).** Restock alerts, sourcing requests with backing, game
submissions, the contact form — all into tables with admin screens.

**7. Marketplace (3–5 days).** Listings, seller verification, held payment,
offers and messages, the moderation queue. Do this last: it is the most code
and the most risk, and the shop earns money without it.

**8. Editable content.** FAQ and policy text from the database so the admin
screens that already exist actually save.

## Local development

Do not develop against the live database.

- **XAMPP** (Apache + PHP + MariaDB + phpMyAdmin) mirrors Hostinger closely.
- Or PHP's built-in server for quick work: `php -S localhost:8000` in the site
  folder, with MySQL from XAMPP.
- Keep `config.php` different locally and on the server — same file name,
  different credentials, never committed.
- Deploy by uploading changed files, or connect the GitHub repo in hPanel and
  let a push deploy it.
