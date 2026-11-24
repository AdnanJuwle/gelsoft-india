# Putting it on Hostinger

You have uploaded the files. These are the steps after that. Allow an hour the
first time.

Anywhere below that says `gelsoft.in`, use your own domain if it differs.

---

## 1. Check PHP is version 8.1 or newer

hPanel → **Advanced → PHP Configuration**.

Set the version to **8.2** (or 8.1+). On the **PHP options** tab:

| Setting | Value | Why |
|---|---|---|
| `display_errors` | **Off** | An error on screen prints your file paths and database name to whoever triggered it |
| `log_errors` | **On** | You still need to read them |
| `expose_php` | **Off** | Stops the server announcing its exact version |
| `upload_max_filesize` | **8M** | Product photos |
| `post_max_size` | **10M** | Must exceed the above |
| `max_execution_time` | 60 | Fine as-is |

Make sure these extensions are ticked: **pdo_mysql**, **mbstring**, **gd**,
**openssl**, **curl**, **fileinfo**. They usually are.

---

## 2. Create the database

hPanel → **Databases → Management**.

1. **Create a new MySQL database**. Hostinger prefixes both names with your
   account, so you will end up with something like `u123456789_gelsoft` and a
   user `u123456789_gelsoft`.
2. Let it generate the password, and **copy it somewhere now** — it is not
   shown again.
3. Write down all three: database name, username, password. The host stays
   `localhost`.

---

## 3. Load the tables

hPanel → **Databases → phpMyAdmin**, open your database, then the **Import**
tab. Import these files **in this order**, one at a time:

1. `db/schema.sql` — products, images, restock alerts
2. `db/schema-auth.sql` — accounts, listings, conversations, editable copy
3. `db/schema-orders.sql` — orders, addresses, payment events
4. `db/schema-marketplace.sql` — marketplace sales and their history
5. `db/schema-community.sql` — sourcing requests and the games board
6. `db/schema-resets.sql` — password reset links
7. `db/schema-owner.sql` — the owner role, above admin
8. `db/schema-seller-checks.sql` — how a seller earns the verified badge
9. `db/schema-games-2.sql` — game banners, and conversations about games
10. `db/schema-offers.sql` — offers a seller can accept or decline
11. `db/seed-content.sql` — the FAQ and the policy pages

**Do not import `db/dev-sample-data.sql`.** That is the local fixture with
invented prices.

After the last import you should see 25 tables. `schema-offers.sql` only adds
columns to `messages`, so it does not change that number.

**If an import fails halfway, just run it again.** All eleven files are
re-runnable: the tables use `CREATE TABLE IF NOT EXISTS`, the seed uses
`ON DUPLICATE KEY UPDATE`, and every `ALTER` in `schema-offers.sql` is
guarded. Verified by importing the whole set three times over -- still 25
tables, no errors. phpMyAdmin timing out on a big file is the normal way this
goes wrong, and the fix is to repeat the step, not to start the database
again.

Nothing in these files names a database or a user, so they do not care what
yours is called.

This whole sequence is tested, not just described: a virgin database, the
eleven imports, then the first owner created through `/admin/setup`, a product
put on the shelf, a customer registering and filling a cart, and checkout
opening. If you follow the steps and something does not look like that, it is
the server and not the files.

---

## 4. Create `inc/config.php` on the server

This file is deliberately not in the repo — it holds the database password and
the payment keys, so it is created once by hand and never committed.

hPanel → **Files → File Manager**, open `public_html/inc/`. You will see
`config.example.php` and no `config.php`. **Copy** the example, rename the copy
to `config.php`, then edit it:

> **`inc/config.php` is the one file you never upload and never overwrite.** It
> only exists on the server. A development machine uses `inc/config.local.php`
> instead, which is loaded in preference — so there is no `config.php` on a
> laptop that could go up and replace yours. If you ever see
> `config.local.php` on the server, delete it: it points at a database that
> does not exist there, and the symptom is confusing, because every page still
> renders and only the forms fail.

```php
'site_url' => 'https://gelsoft.in',
'env'      => 'production',

'app' => [
    'secret' => 'paste 64 hex characters here',
],

'db' => [
    'host'    => 'localhost',
    'name'    => 'u123456789_gelsoft',   // from step 2
    'user'    => 'u123456789_gelsoft',
    'pass'    => 'the password you copied',
    'charset' => 'utf8mb4',
],
```

**Generate `app.secret` once and never change it.** Over SSH:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

It signs the `?t=` link that lets a guest open their own order without an
account — the link in every receipt email. Change it and every one of those
links stops working, with nothing on the page to say why; the customer just
gets a 404. Leaving it empty falls back to the database password, which works,
but then rotating that password has the same effect.

(It used to be signed with the Razorpay secret, which was worse for exactly
this reason: switching from test keys to live ones in step 11 would have
killed every link issued before it.)

Leave the Razorpay block empty for now — nothing uses it until checkout exists.

Set `'contact' => ['email' => 'support@gelsoft.in', 'instagram' => 'yourhandle']`
to whatever is real. That one line feeds the policy page, the FAQ and the
footer.

**Now load `https://gelsoft.in`.** If the shop pages say "Restocking soon"
instead of erroring, the database is connected and you are nearly done.

---

## 5. Check the hidden files arrived

File Manager hides dotfiles until you turn on **Settings → Show hidden files**.
Four `.htaccess` files must be present, and two of them are what stand between
your database password and the internet:

| File | If it is missing |
|---|---|
| `public_html/.htaccess` | Clean URLs break — every page 404s |
| `public_html/inc/.htaccess` | **Anyone can fetch your database password** |
| `public_html/uploads/.htaccess` | **An uploaded file could be executed** |
| `public_html/admin/.htaccess` | Admin loses its second lock |
| `public_html/tools/.htaccess` | Command-line scripts become reachable |

If any are missing, upload the folder again as a **zip** and extract it in File
Manager — zips keep dotfiles, drag-and-drop often does not.

Then test it yourself: open `https://gelsoft.in/inc/config.php` in a browser.
You must get **403 Forbidden**. If you see PHP code or a blank page with your
password in the source, stop and fix it before doing anything else.

---

## 6. Switch on SSL

hPanel → **Security → SSL**. Install the free certificate for the domain and
wait for it to go active (a few minutes).

The `.htaccess` already forces http → https and drops `www`, so nothing else to
do. Once https is live the site also starts sending HSTS automatically.

---

## 7. Create the support mailbox

The site sends real email: order confirmations, dispatch notices, marketplace
updates, and the password reset link. PHP's own `mail()` goes out as the web
user from a shared IP and is frequently dropped on the floor, so the site
speaks SMTP from a mailbox on your own domain — which passes SPF and DKIM, and
is most of what keeps a receipt out of the spam folder.

hPanel → **Emails → Email Accounts**.

1. Create **support@gelsoft.in**. Set a password and copy it.
2. Open `inc/config.php` again and fill the `mail` block:

```php
'mail' => [
    'host'      => 'smtp.hostinger.com',
    'port'      => 465,
    'secure'    => 'ssl',
    'user'      => 'support@gelsoft.in',
    'pass'      => 'the mailbox password',
    'from_name' => 'Gelsoft India',
],
```

3. Test it: go to `https://gelsoft.in/forgot`, put in your own address, and see
   whether the link arrives. Check the spam folder too.

**Until `mail.pass` is filled in, nothing is emailed.** The site does not break
— an order still completes and a reset link is still created — but the message
is recorded in the `mail_log` table as failed and nobody receives it. If
something does not arrive, that table is the first place to look:

```sql
SELECT created_at, recipient, template, ok, error FROM mail_log ORDER BY id DESC LIMIT 20;
```

---

## 8. Create your admin account

Open **`https://gelsoft.in/admin/setup`** and fill in the form. That page only
works while no admin account exists — the moment you create one it closes
itself, so nobody who finds the URL later can add themselves.

Then sign in at `https://gelsoft.in/admin/login`.

> Do this the same hour the site goes up. Until an admin exists that page is
> open to whoever finds it, which is why step 9 below matters right now.

**To reset a password later, or add a second admin**, you need SSH. hPanel →
**Advanced → SSH Access**, then:

```bash
ssh -p 65002 u123456789@your-server-ip
```

Then:

```bash
cd ~/public_html
php tools/make-admin.php you@gelsoft.in "Your Name"
```

It asks for the password twice and never takes it as an argument, because an
argument lands in your shell history and in the process list.

---

**Two levels of access.** The first account you make is the **owner**. An admin
runs the shop &mdash; orders, listings, the FAQ, games, conversations &mdash;
but cannot change who has access. Only an owner can. That way one compromised
admin account cannot lock you out, remove your other admins, or quietly add one
of its own. `tools/make-admin.php` over SSH always makes an owner, because it is
the rescue hatch and an admin could not undo its own lockout.

**To add a second admin later**, send them to `https://gelsoft.in/register` to
make an ordinary account with a password they choose, then promote it from
**Admin → Admins** (`/admin/staff`). You confirm with your own password, and
the change is recorded in `admin_log`. Nothing there can remove the last admin
or your own access, so you cannot lock yourself out with it.

---

## 9. Put the directory password back on `/admin/`

hPanel → **Advanced → Password Protect Directories**, point it at
`public_html/admin`, set a username and password.

You now have two locks: the server's, then the site's own sign-in. Both is
right for the screen that changes prices and reads people's conversations.

---

## 10. Add your products

**`/admin/add-item`.** Fill the form, attach photos, set the status to
Published, save. It is live immediately.

Worth knowing:

- The price is in **rupees** on the form; it is stored as paise. You never
  deal with paise.
- A **draft** is invisible to everyone, so you can write an item over several
  sittings.
- Photos are re-encoded to WebP, which both keeps the page quick and strips
  anything hidden inside the original file. The first photo is the card image.
- **Six photos per item, five per marketplace listing.** The limit is about the
  page, not the disk: most of this site is read on mobile data, and a gallery
  is the heaviest thing on the screen. Shop photos are kept at 1600px, listing
  photos at 1200px, because nobody examines the grain on a second-hand blaster.
  Change either in `UPLOAD_PROFILES` in `inc/uploads.php`.
- The dashboard shows what `uploads/` is using. If the average photo is over
  400 KB it says so &mdash; that normally means the **gd** extension is off and
  files are being stored as sent rather than re-encoded.
- `uploads/` must be writable (755). If a photo fails to save, that is the
  first thing to check.
- Leave the URL blank and it is made from the name. Changing it later breaks
  any link already shared.

---

## 11. Switch payments on

The checkout works without this — it saves the order as unpaid and says so. To
actually take money:

1. **Razorpay account** at razorpay.com, and complete their KYC. Allow a few
   days: they verify a business before releasing live keys.
2. **Dashboard → Settings → API Keys → Generate.** You get a key id
   (`rzp_test_…` or `rzp_live_…`) and a secret, shown once.
3. Put both in `inc/config.php` under `razorpay`.
4. **Dashboard → Settings → Webhooks → Add New Webhook:**
   - URL: `https://gelsoft.in/api/webhook`

     **Without the `.php`.** The `.htaccess` sends `/api/webhook.php` to the
     clean URL with a 301, and a browser or a webhook sender repeats a 301 as a
     **GET** with the body thrown away &mdash; so Razorpay's notification would
     arrive empty and no order would ever be marked paid. The rule now skips
     POSTs so either address works, but give it the clean one.
   - Secret: invent one, long and random
   - Events: **`payment.captured`** and **`payment.failed`**
5. Put that same secret in `inc/config.php` as `webhook_secret`.

**The webhook is not optional.** It is the only thing that marks an order
paid — the browser coming back from the payment screen deliberately cannot,
because a browser can be closed, replayed, or told to say anything. Without the
webhook every order sits unpaid forever, however much money arrives.

Test with the test keys first: buy something using Razorpay's test card
`4111 1111 1111 1111`, any future expiry, any CVV. The order should go from
Awaiting payment to Paid within a few seconds without you touching it, and the
stock should drop. If it stays pending, the webhook is not reaching you — check
the URL in the dashboard and that the two secrets match exactly.

### Email

Order confirmations and dispatch emails go out over SMTP from your Hostinger
mailbox. hPanel → **Emails → Email Accounts**, create `support@gelsoft.in`,
then put its password in `inc/config.php` under `mail`.

Mail from your own domain passes SPF and DKIM, which is most of what keeps a
receipt out of someone's spam folder. Until the password is set, orders still
work and nothing is emailed. Every attempt is recorded in the `mail_log` table
either way, so "I never got the confirmation" is a question you can answer.

## 12. Final checks

Open each of these and confirm:

- [ ] `https://gelsoft.in` loads over https, with a padlock
- [ ] `https://gelsoft.in/blasters` shows your products (clean URL works)
- [ ] `https://gelsoft.in/blasters.html` redirects to `/blasters`
- [ ] `https://gelsoft.in/nothing-here` shows the site's own 404
- [ ] `https://gelsoft.in/inc/config.php` returns **403**
- [ ] `https://gelsoft.in/uploads/` does not list files
- [ ] `https://gelsoft.in/admin/` asks for the directory password, then the sign-in
- [ ] A restock alert submits and the row appears in `restock_alerts`
- [ ] `/admin/setup` says "Already set up" once you have made your account
- [ ] An item added from `/admin/add-item` appears on its shelf, with its photo
- [ ] A test order moves from Awaiting payment to **Paid on its own**, by
      webhook, with nobody touching it
- [ ] That order's stock came off, exactly once
- [ ] The confirmation email arrived
- [ ] `/forgot` emails a link, the link sets a new password, and the same
      link then refuses to work a second time
- [ ] `/admin/staff` lists you, and refuses to remove your own access
- [ ] `/admin/staff` shows you as **Owner**, not Admin
- [ ] No `dbcheck-*.php` or other diagnostic left in `public_html`
- [ ] `https://gelsoft.in/SECURITY.md` returns **403**, not the file
- [ ] `https://gelsoft.in/router.php` returns **403** (it is dev-only)
- [ ] A pending listing refuses to go live until its seller has both checks
- [ ] On a phone: the menu opens, products come before the filters
- [ ] `https://gelsoft.in/sitemap.xml` is the GENERATED one. Do not go by
      how many URLs it lists: with nothing in stock it shows twelve, which
      is correct, and it grows by itself as products and listings go live.
      Go by what is in it. `/terms` and `/privacy` with a date beside them
      means the generated one is answering. `<priority>` tags, or `/account`
      and `/sell` in the list, mean an old static `sitemap.xml` is being
      served instead — delete `public_html/sitemap.xml`
- [ ] Never upload a `sitemap.xml` file. The sitemap is `sitemap.php`,
      served at that address by `.htaccess`
- [ ] The tab shows the G icon rather than a blank page symbol
- [ ] `inc/config.php` has a real `app.secret` in it, and you have kept a copy
      somewhere safe — it is not recoverable, and regenerating it breaks the
      order link in every receipt already sent
- [ ] A receipt's "view your order" link still opens the order AFTER you
      switch Razorpay to live keys in step 11

---

## Updating it later

Upload the changed files over the old ones. Two things to remember:

- **Never overwrite `inc/config.php`.** It is the one file that differs between
  your machine and the server.
- CSS and JS carry `?v=<file timestamp>`, so a changed stylesheet reaches
  people immediately. No cache clearing needed.

If you connect the GitHub repo in hPanel → **Advanced → Git**, a push deploys
automatically. `inc/config.php` is gitignored, so it survives — that is exactly
why it is gitignored.

---

## Housekeeping: photos nothing points at

Deleting a product or a listing deletes its photos with it, so in normal use
this folder looks after itself. What it cannot cover is an upload that lands
and whose database row never does — a failed save, a row removed by hand, a
restore from a backup older than the photo. On a shared plan the disk is the
thing that runs out, and one of these costs exactly as much as a photo that is
on the site.

Over SSH, the same way as `make-admin.php`:

```
php tools/orphan-uploads.php            # list them, delete nothing
php tools/orphan-uploads.php --delete   # remove them
```

It leaves anything uploaded in the last 24 hours alone, because a photo is
written to disk a moment before the row that points at it exists, and for that
moment a perfectly good upload is indistinguishable from an orphan. Run the
listing version first and read it. **Admin → the dashboard already shows what
the folder is using**, so you will see the number climb before it matters.

---

## Backups

hPanel → **Files → Backups**. Hostinger takes them automatically.

Once a month, download one and actually restore it somewhere — a backup nobody
has restored is a guess. The database is the part you cannot rebuild: the files
are in git, your orders and conversations are not.
