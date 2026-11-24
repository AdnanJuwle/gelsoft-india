# What works, and what does not

Honest state of the site, checked against the running code rather than the
plan. Updated 12 November 2026.

## Works end to end

You can do these today, on a server with the database loaded.

| | Where |
|---|---|
| Browse blasters, gear and parts from the database | `/blasters` `/gear` `/parts` |
| Filter and sort — real SQL, bookmarkable URLs, counts per option | the filter rails |
| Search across every shelf | `/search` |
| A product page per item, with specs, stock and related items | `/blasters/<slug>` |
| Leave a restock alert (saves, de-duplicates, validates) | every empty shelf, `/` |
| Create an account, sign in, sign out | `/register` `/login` |
| **Reset a forgotten password by email** | `/forgot` → `/reset?token=…` |
| **Add and remove admins, owner vs admin** | `/admin/staff` |
| **Verify a seller without collecting ID** | `/admin/marketplace#sellers` |
| **Remove products, games and listings** | the three admin screens |
| Admin sign-in, with lockout and IP throttling | `/admin/login` |
| Admin dashboard with real counts and the item list | `/admin/` |
| Edit the FAQ — add, edit, reorder, publish, remove | `/admin/faq` |
| Edit the policy pages, live | `/admin/pages` |
| `/terms` and `/privacy` rendered from the database | both |
| Message a seller about a listing; make an offer in the thread | `/messages` |
| Admin reads any conversation | `/admin/thread` |
| **Add and edit shop items, with photos** | `/admin/add-item` |
| **Create the first admin from the browser** | `/admin/setup` |
| **Cart, checkout and payment** | `/cart` `/checkout` |
| **Orders: packing, tracking, dispatch email** | `/admin/orders` |
| **Order history and tracking** | `/account`, `/order?ref=…` |
| **Marketplace: browse, filter, list, moderate** | `/marketplace` `/sell` `/admin/marketplace` |
| **Buying a listing, with the money held** | `/marketplace/<slug>` → `/sale?ref=…` |
| **The inspection option, buyer-paid** | on any listing that allows it |
| **Request a model, and back someone else's** | `/request` |
| **Games board, host submissions, region filter** | `/games` |
| **Hosting needs an account; one banner per game** | `/games` |
| **Message a game's host** | `/games` → `/messages` |
| **Addresses, details, password, payout UPI** | `/account` |
| **Restock alerts actually sent** | `/admin/community` |

## Looks finished, does nothing

Everything on the site renders and every form saves; these are the gaps
behind it.

| | What is missing |
|---|---|
| **Admin stock editing in the list** | The dashboard table is read-only; edit an item to change its stock |
| **Email verification** | An account works the moment it is created |
| **Razorpay keys** | The code is done and tested. Until real keys are in `inc/config.php` a checkout saves the order as unpaid and says so |

## So: is it usable?

**As a shop window, yes.** Put real products in and people can browse them,
filter them, read a proper spec sheet and leave their number for a restock.
That is a real site and it is worth having up.

**As a shop, yes** — once your Razorpay keys are in. Someone can fill a cart,
check out, pay by UPI or card and get a receipt; you see the order, mark it
shipped, and the tracking number emails itself to them.

**As a marketplace, yes.** Someone can list a thing, you review it, a buyer
pays, the money sits with us, the seller ships, the buyer confirms, and you
release the payout. The inspection option works end to end. The one manual step
is the payout transfer itself, which is deliberate.

The order that gets you to taking money fastest:

1. ~~**Phase 3** — admin saves products and photos.~~ **Done.**
2. ~~**Phase 4** — cart, checkout, Razorpay, order emails.~~ **Done.** What is
   left is not code: open a Razorpay account, paste the keys in, and run one
   test order end to end.
3. ~~**Phase 7** — the marketplace browse and sell flow.~~ **Done.**
4. ~~**Phase 6** — the remaining forms, and restock alert emails.~~ **Done.**

Every form on the site now posts somewhere and is checked server-side. What is
left is not a phase: a password reset, email verification, and the photos.

Nothing above is blocked. See [`BACKEND.md`](BACKEND.md) for what each phase
involves and [`SECURITY.md`](SECURITY.md) for what is protected.
