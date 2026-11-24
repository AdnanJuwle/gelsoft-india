# Security

What is in place, what is deliberately not, and what to check before the site
takes a rupee.

## In place

**Injection.** Every query goes through PDO with real placeholders and
`ATTR_EMULATE_PREPARES => false`, so values are never pasted into SQL. Column
and table names are literals in `inc/shop.php` and never come from a request;
filter values are checked against a whitelist (`SHOP_ENUMS`) before they are
bound. `LIKE` searches escape `%` and `_`, so searching for `%` does not match
the whole shelf.

**Output.** `e()` is `htmlspecialchars` with `ENT_QUOTES` and a UTF-8 charset,
and everything a person typed goes through it. Page bodies and FAQ answers are
stored and rendered as plain text — an admin cannot put markup, let alone a
script, onto a page by typing it.

**Content Security Policy.** `script-src 'self'` with no `'unsafe-inline'`.
There are no inline `<script>` blocks and no `on*=` attributes anywhere, which
is why the forms that are not wired yet are handled in `site.js` rather than by
an inline `onsubmit`. If an injection ever does land a tag on a page, the
policy is what stops it loading anything. Also `object-src 'none'`,
`base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`.

**Passwords.** `password_hash()` with `PASSWORD_DEFAULT` at cost 12, verified
with `password_verify()`. Nothing reversible, no MD5, no SHA-anything. A
sign-in attempt against an account that does not exist still runs a hash, so
the response time does not say which addresses are real.

**Uploads.** Every image is decoded and written out fresh rather than copied,
so EXIF, comment blocks, appended archives and injected script do not survive
the round trip; the type comes from reading the file, never from its name or
the Content-Type the browser sent. `uploads/.htaccess` is the second line
behind that. Verified by uploading a valid PNG with PHP appended to it: the
payload is there in the file going in and gone from the file coming out.

Without GD there is no round trip, so there is no upload. It used to fall back
to moving the file across unchanged, on the grounds that the earlier checks
had established it was a real image. They established that its HEADER is a
real image -- `getimagesize()` reads the first few bytes and says nothing
about what is appended after the image data. Dropping the one defence that
makes the paragraph above true was the wrong way to fail, so it refuses now
and logs what to switch on.

**How big a picture is in MEMORY.** `UPLOAD_MAX_BYTES` bounds the file and
says nothing about this: a flat-coloured 25000x25000 PNG is 1.7 MB on disk and
2.4 GB once decoded, and `getimagesize()` waves it through because it only
reads the header. `image_memory_budget_px()` works out what this host can
afford from its own `memory_limit`, and the check runs BEFORE GD is asked to
open anything -- so the pixels never have to exist.

It is not only an attack. A 50 MP phone photo is 191 MB as a bitmap, already
past a shared host's 128 MB, so without this a seller with a recent phone
uploading one honest picture got a fatal error and a white screen. They now
get a sentence telling them what size to scale to.

Each item has a fixed number of photos (`UPLOAD_PROFILES`), which
is a page-weight decision rather than a disk one but also means no single
account can fill the folder by resubmitting a form.

**Opening an order without an account.** A guest checkout has no account to
sign in to, so the receipt carries `?ref=GS-2026-0001&t=…`. The reference is
sequential and therefore guessable; the token is what actually guards the
page. It is an HMAC-SHA256 over the order's id, reference and creation time,
truncated to 128 bits, compared with `hash_equals()`. A signed-in owner and a
staff account do not need it.

It is keyed on `app.secret`, which exists for this and nothing else. It used
to be keyed on the Razorpay secret, which is a value that is MEANT to
change — going from test keys to live ones is a documented deployment step,
and a leaked key has to be rotated. Either would have invalidated the link in
every receipt already sent, and the customer would have discovered it by
clicking and getting a 404. Rotating `app.secret` still does that, which is
why `inc/config.example.php` and DEPLOY.md both say to generate it once and
keep a copy.

**Links somebody typed.** Two fields take a URL from a person and put it in
an `href`: a game's sign-up link, which goes on the public board, and a
sourcing request's reference link, which goes on the admin screen where a
staff session clicks it. Both are now checked with `safe_external_url()`,
which allows `http` and `https` and nothing else.

`filter_var($url, FILTER_VALIDATE_URL)` is not that check. It answers "is
this a URL?", and it accepts `javascript://comment%0Aalert(1)` -- the `//`
satisfies its idea of an authority, and `%0A` is a newline, which comments
out the rest of the line in JavaScript. It also accepts `file:` and `ftp:`.
Escaping does not help here either: `e()` stops a value breaking OUT of an
attribute, it cannot make a scheme safe INSIDE one. The CSP would stop the
execution, and that is worth having, but a link nobody should be able to save
is not something to leave to the last line of defence.

The check runs when saving AND when rendering, so a row written before it
existed, or edited straight in the database, cannot produce the href either.

**Rate limits.** Sign-in and the admin sign-in count failures per address;
password resets are four per account per hour; messages are capped per sender
per minute; a restock alert for the same contact and product is idempotent, so
asking twice is one row. Sign-up is five accounts per address per hour,
counted in `login_attempts` alongside the sign-ins but tagged `signup:` and
recorded as a success -- a sign-up is neither a failed sign-in nor something
`auth_ip_blocked()` should ever count, and conflating them would have let five
new accounts eat into the twenty bad passwords an address is allowed.

That limit is deliberately loose. One address can be a whole building behind
carrier-grade NAT, which is ordinary in India, and a throttle that cannot read
its own records returns false rather than locking everybody out.

**Field lengths.** `inc/fields.php` holds the column widths and
`length_errors()` checks a form against them before the INSERT, naming the
field and both numbers. sql_mode is `STRICT_TRANS_TABLES`, so an over-long
value was always refused rather than cut -- the data was never at risk. What
was at risk was the person, who got "The order could not be created" and no
way to know which of six fields to shorten. Lengths are counted in characters,
because that is what `VARCHAR(n)` counts in utf8mb4; counting bytes would
refuse a Devanagari address at a third of its real length.

**Config files.** `inc/config.php` holds the database password, the mailbox
password and the payment keys. It is gitignored, denied by `inc/.htaccess` and
again by name in the root `.htaccess`, and it exists only on the server. A
development machine uses `inc/config.local.php`, which `cfg()` loads in
preference — so a laptop has no `config.php` to upload over the live one. That
mistake has a nasty signature: every page renders, the shop looks fine, and
only the forms fail, because the empty-state pages do not need a database.

**Hosting a game needs an account.** A public board anyone can write to
anonymously is a board full of spam within a week, and a player who turns up to
a game that was never real has nobody to ask. The host's name and contact are
taken from the signed-in account rather than from the form, so the name on the
board and the person reachable through the site are the same person. Players
message a host through `threads`, so neither side hands over a phone number.

**Threads carry a listing or a game, never both.** Both columns are nullable,
and the two UNIQUE keys coexist because MySQL treats NULLs as distinct — each
key polices its own kind and ignores the other. Every query that joins a
subject uses a LEFT join; an INNER join silently drops game threads out of the
conversation list instead of failing, which is the worse outcome. An offer on a
game thread is refused in `message_send()`, not merely hidden in the UI,
because a hidden form is still a form somebody can post.

**Seller identity: deliberately not collected.** The marketplace asks sellers
for no government ID and stores none. Two reasons. UIDAI's position is that an
unlicensed private entity may not collect or keep Aadhaar copies at all, and it
is an offence under the Aadhaar Act 2016. And under the DPDP Act, holding any
ID document would make this site the custodian of the most sensitive thing a
person owns, with the notice, consent, security, breach-reporting and erasure
duties that go with it, and penalties far beyond what this business could
carry. None of it buys anything, because the protection that actually works is
already in place: the buyer's money is held until delivery is confirmed, and
with the inspection option the item passes through us. A seller who never gets
paid has nothing to take.

So `seller_checks` records checks WE PERFORMED — we reached them on their
phone, and the name their bank reports on their UPI id matches the account name
they gave (the bank did the identity work; we compare two strings). The note
column is 160 characters, too short to hold a document number or a link, and
that is on purpose. `users.seller_verified_at` is derived from those checks and
never set by hand, so the badge cannot drift from its evidence; undoing a check
removes the badge. A listing cannot be approved while its seller is unverified.

**Granting admin.** Admin is the highest privilege here — it reads every
conversation, changes prices and releases held marketplace money. So promoting
or removing one asks the signed-in admin for their own password again: a
borrowed or stolen session is not by itself enough to mint a second permanent
account. A failed confirmation is recorded in `admin_log` alongside the grants
themselves. The page never creates an account or sets anybody's password --
the person registers themselves and chooses their own, so an admin password is
never typed by somebody else or sent over a message. Two things are refused
outright: removing your own access, and removing the last admin. Either would
leave the panel unreachable from the browser with phpMyAdmin as the only way
back.

**Password resets.** The link is 32 bytes of `random_bytes`, and the database
stores only its SHA-256 — so a copy of the table is not a set of keys. It lasts
an hour, works once, and using it spends every other live link for that account,
so somebody who clicked "forgot" twice is not left holding a spare. Completing a
reset writes the password and spends the tokens in one transaction, destroys the
session, and emails the account a notice — which is how a person finds out if it
was not them. `/forgot` says the same sentence whether or not the address has an
account, and logs the attempt either way, so it is neither a way to discover
which addresses are real nor a way around the IP throttle. Four requests per
account per hour. `/reset` sends `referrer: no-referrer`, because the token is
in the URL.

**Brute force.** Five failures locks an account for fifteen minutes; twenty
failures from one address in fifteen minutes blocks that address. Attempts are
recorded in `login_attempts` by IP and by identifier, because an attacker
spreads across both. The client's address is `REMOTE_ADDR` only —
`X-Forwarded-For` is sent by the client and would hand anyone an unlimited
supply of addresses.

**Sessions.** `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the request is
https. The id is regenerated on sign-in, so a session id someone already knows
is not the one that ends up signed in. The session stores a user id and never a
role: `current_user()` re-reads the role each request, which costs one query
and means a tampered session cannot promote itself.

**CSRF.** One token per session, checked on every write with `hash_equals`.
Sign-out is a POST, because a GET that signs you out can be fired by an `<img>`
tag on any other site.

**Authorisation.** `require_login()` and `require_admin()` at the top of every
page that needs them, and the check is repeated inside the thing being
protected: `message_send()` re-checks that the sender is in the thread rather
than trusting the page that called it. An endpoint that trusts its caller is an
endpoint anyone can call directly.

**Redirects.** `safe_path()` drops the host, so `?next=https://evil.example`
cannot leave the site. Anything with `..` in it, or characters a real path here
never has, becomes `/`.

**File paths.** `uploads/` has `php_flag engine off` and denies every
executable extension; `inc/` and `tools/` are denied outright. `inc/config.php`
holds the database password and the payment keys and is gitignored.

**Errors.** `display_errors` is off unless `env` is `local`; everything is
logged instead. A stack trace on screen tells an attacker your paths, your
database name and your PHP version.

**Audit.** `admin_log` records who did what, with their address and the time.

**What people send us.** Sourcing requests, game submissions and restock
alerts all come from anyone, signed in or not, so each one is CSRF-checked,
length-capped, and validated on the server: a contact has to be a real email
address or an Indian mobile number before it is stored, and a URL has to parse
before it is ever rendered as a link. Games are reviewed before the board shows
them. Backing a request is de-duplicated by a unique key rather than a count,
so the number that decides whether to order stock cannot be clicked up. On the
account page every write carries the signed-in user's id in the WHERE clause,
so changing an id in a form edits nothing — tested. A password change
regenerates the session, which signs out anything else holding the old one.

**The marketplace.** Who may move a sale from one state to the next is a table
in `inc/listing_sales.php`, checked server-side on every move, so a page cannot
invent a transition by posting a different status: a buyer cannot mark a sale
shipped, a seller cannot skip an inspection the buyer paid for, and only an
admin releases money. The payout is computed from the listing row, never from
the form, and the buyer's inspection fee never touches the seller's side. A
listing is only visible while live, to its own seller, or to an admin. Nothing
is released until the buyer has confirmed or the 48-hour window has closed —
an admin overriding that has to do it deliberately, and it is recorded with
their name in `listing_order_events`.

**Payments.** The amount charged is recalculated from the database at every
step and never read from the browser. An order is marked paid by exactly one
function, called from exactly one place: the webhook, after an HMAC check over
the raw request body against a shared secret. The browser's return from the
payment screen is signature-checked too, but all it can do is record a payment
id and choose which page to show — it cannot move an order to paid. Webhooks
are de-duplicated on Razorpay's event id behind a unique key, so a repeated
delivery cannot take stock or send a receipt twice; a payment for less than the
order total is refused and flagged for a human rather than accepted. Stock
comes off inside the same transaction that marks the order paid, with the
product rows locked, so two people buying the last one cannot both succeed. An
admin can move an order along but cannot mark it paid — only the provider's
word does that. Razorpay's script is permitted by the content security policy
on the checkout page alone.

## Not in place yet

These matter before the site takes money:

- **Email verification.** An account is usable the moment it is created.
- **Two-factor on admin.** The panel changes prices and reads conversations.
- ~~Payment webhook verification~~ — built and tested; see below.
- **Upload scanning.** The rules are written; the upload code is phase 3.
- **Backups you have tested.** hPanel takes them. Restore one somewhere else
  and see if it actually works, before you need it to.

## Before launch

1. `inc/config.php` on the server has `'env' => 'production'`.
2. The free SSL certificate is issued, and HSTS is being sent (the header goes
   out automatically once the request is https).
3. `/admin/` has the hPanel directory password on it, as well as the sign-in.
4. The first admin was made with `php tools/make-admin.php`, not by hand in
   phpMyAdmin with a password you typed into a SQL box.
5. `php.ini`: `expose_php = Off`, `display_errors = Off`, `log_errors = On`.
6. Check that `https://gelsoft.in/inc/config.php` returns 403, not your
   database password. Check `https://gelsoft.in/uploads/` does not list files.
7. Razorpay keys are the live ones, and the webhook secret matches the one in
   the dashboard.
