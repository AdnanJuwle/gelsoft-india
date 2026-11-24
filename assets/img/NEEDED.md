# Photos the site is waiting for

Nothing here is generated. Every spot that wants a photo shows a dashed
placeholder with its filename on it until the real photo exists, so the page
is honest about what is missing and you can see exactly where each shot goes.

There are two kinds of photo, and they go in two different places.

## 1. Product photos: through the admin panel, not this folder

Every product's photos are uploaded on its own page in the admin panel
(**Admin → Products → Add item / Edit → Add photos**). Nothing goes into
`assets/img/` for these, and the filename does not matter: the site renames
each one and re-encodes it to WebP, which also strips the phone's location
data out of it.

- **Format:** JPEG, PNG or WebP. Straight off the phone is fine.
- **Shape:** landscape **4:3**. The gallery is 4:3, so a portrait photo gets
  its top and bottom cut off.
- **Size:** at least **1600 × 1200**. Anything larger is scaled down to 1600
  wide, so bigger costs nothing.
- **Limits:** up to **5 MB** each, **6 per product**, at least 200 px.
  A 12 MP phone photo is fine. A 48 or 50 MP one ("high-resolution" mode) is
  refused, with a message saying how far to scale it: switch that mode off,
  or export at 4000 px on the long side.
- **Order:** the first photo is the one on the shop card. Make it the whole
  blaster, side on.
- **A good set:** whole blaster from the side · three-quarter view · gearbox
  or mag well · barrel and hider · what is in the box · the chrono reading.

Marketplace listings work the same way, but the **seller** uploads them on
`/sell` (up to 5). Nothing for you to do there.

## 2. Site photos: upload into `public_html/assets/img/`

These are fixed parts of the site. Upload each one into
`public_html/assets/img/` (hPanel → File Manager) with **exactly** the name
below, and it appears on the page by itself. No code to edit. To swap one for
a better photo, upload over it with the same name; to take one down, delete it
and the placeholder comes back.

**All ten are in place.** Every one is a flat drawing in the site's colours,
hand-drawn as SVG -- none is a photo, and none was generated. Replace any of
them with a real photo whenever you have one, by uploading over it with the
same name. `apple-touch-icon.png` is `favicon.svg` itself, rendered to the
PNG an iPhone needs (iOS will not use an SVG for a home-screen icon): redo it
whenever the favicon changes.

Names are **lower case**, and the extension is part of the name: `.jpg`, not
`.jpeg` or `.JPG`. The server is Linux, where `Hero-Blaster.JPG` is a
different file and will simply not be found.

| File | Where it shows | Export at | What it should be |
|---|---|---|---|
| `hero-blaster.jpg` | Homepage, the big panel at the top | 1600 × 900 | Your best photo. One blaster, three-quarter view, plain dark background. The panel is cropped taller than the photo on a laptop, so keep the blaster in the **middle** with room on the left and right. Also the share picture until `og-image.jpg` exists. |
| `aisle-blasters.jpg` | Homepage, "Shop the four aisles" | 800 × 450 | A blaster, flat lay or angled, light background |
| `aisle-gear.jpg` | Homepage, aisle card | 800 × 450 | Goggles and a mesh mask together |
| `aisle-parts.jpg` | Homepage, aisle card | 800 × 450 | A gearbox, or a parts flat lay |
| `aisle-request.jpg` | Homepage, aisle card | 800 × 450 | A packed box ready to ship |
| `safety-eyepro.jpg` | Safety page, card 1 | 1200 × 675 | Full-seal goggles, worn or on a bench |
| `safety-transport.jpg` | Safety page, card 2 | 1200 × 675 | A blaster going into a closed bag or case |
| `safety-ground.jpg` | Safety page, card 3 | 1200 × 675 | A private field or hosted site. **Nobody's face** |
| `og-image.jpg` | The picture when a link is shared on WhatsApp, Instagram, etc. | 1200 × 630 | The hero shot, or a few blasters together. Keep anything important away from the edges: apps crop it. |
| `apple-touch-icon.png` | iPhone home screen, when someone saves the site | 180 × 180 | The G mark from the tab icon on its dark square, flat, **PNG**. Low priority. |

The aisle cards are short and wide (about 5:2 on screen), so the 800 × 450
photo loses some top and bottom: keep the subject in the middle band.

## Rules of thumb

- **JPEG** for every site photo (`.jpg`); PNG only for the touch icon.
- Keep each site photo **under 300 KB**. Export at the size in the table, not
  at camera resolution: an unresized 4 MB photo is the slowest thing on the
  page by far. (Product photos are resized for you; these are not.)
- Shoot against something plain. The site is warm paper and graphite; busy
  backgrounds fight it.
- Daylight, no filters, no heavy colour grading.
- **Your own photos of your own stock.** Supplier or AliExpress listing
  photos are someone else's copyright and show someone else's unit; stock
  photography of other people's products is a licensing problem.
- No faces anyone could recognise, and no number plates.
