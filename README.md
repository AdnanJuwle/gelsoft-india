# Gelsoft India — gelsoft.in

Storefront, marketplace and admin pages for Gelsoft India. Static HTML, one
stylesheet, one script. No build step, no framework, no dependencies: open
`index.html` in a browser and the whole site works.

## Running it

```bash
cd gelsoft-india
python -m http.server 8080
```

Then open <http://localhost:8080>. Opening the files directly with
`file://` also works — nothing here needs a server.

## Layout

```
index.html            Home
blasters.html         Blasters, filterable by type / brand / grade / gears
gear.html             Eye protection, helmets, rigs, gloves, holsters, pads
parts.html            Internals, electricals, optics, mags and gel
product.html          Product page — template, filled from a published item
request.html          Request a model (sourcing) + the open-request board
marketplace.html      Second-hand listings
listing.html          Marketplace listing — template
sell.html             Create a listing
account.html          Customer account: orders, addresses, alerts, listings
faq.html              FAQ
safety.html           Safety standards
games.html            Games in India + host submission
policy.html           Replacement & refund policy
admin/index.html      Admin dashboard: stock, queue, requests, FAQ, games, policy
admin/add-item.html   Add an item to the shop
assets/css/site.css   Design tokens and every storefront component
assets/css/admin.css  Admin-only additions
assets/js/site.js     Nav, accordion, tabs, filters, steppers, previews
```

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

## Still to do

- Wire a backend: auth, cart and checkout, orders, payments (UPI), the admin
  writes, and the marketplace held-payment flow.
- Replace the inline SVG placeholders with real photography.
- Prices, chrono figures and fee rates are placeholders.
- Pull the repeated header and footer into partials once there is a build step
  or a template engine.
- Accessibility and performance pass; favicon and social images.
