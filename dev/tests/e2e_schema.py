"""Structured data: the JSON-LD on product and listing pages.

The thing worth guarding is not that the markup exists -- it is that it keeps
agreeing with the page. A rich result that shows a price the page does not
charge, or a rating nobody gave, is worse than no rich result at all, and the
way that happens is quietly, months later, when a price field moves.
"""
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request

BASE = "http://localhost:8099"
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

passed = failed = 0


def ok(label, cond, extra=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label + ("  -- " + str(extra) if extra else ""))


def get(path):
    try:
        r = urllib.request.urlopen(BASE + path)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def blocks(html):
    return re.findall(
        r'<script type="application/ld\+json">(.*?)</script>', html, re.S)


def sql(q):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", q],
                       capture_output=True, text=True)
    return r.stdout.strip()


def php(code):
    return subprocess.run([PHP, "-r", code], cwd=REPO,
                          capture_output=True, text=True)


print("--- a product page ---")

slug = sql("SELECT slug FROM products WHERE status='published' AND stock_qty>0 "
           "AND category='blaster' ORDER BY id LIMIT 1")
paise = int(sql("SELECT price_paise FROM products WHERE slug='" + slug + "'"))

status, html = get("/blasters/" + slug)
ok("the page is served", status == 200, status)

bs = blocks(html)
ok("it carries two JSON-LD blocks", len(bs) == 2, len(bs))

parsed = []
for b in bs:
    try:
        parsed.append(json.loads(b))
    except Exception as e:
        parsed.append({"PARSE_ERROR": str(e)})
ok("both parse as JSON", all("PARSE_ERROR" not in g for g in parsed),
   [g.get("PARSE_ERROR") for g in parsed])

prod = next((g for g in parsed if g.get("@type") == "Product"), None)
crumb = next((g for g in parsed if g.get("@type") == "BreadcrumbList"), None)
ok("one is a Product", prod is not None)
ok("one is a BreadcrumbList", crumb is not None)

offer = (prod or {}).get("offers", {})
ok("the offer is priced in INR", offer.get("priceCurrency") == "INR",
   offer.get("priceCurrency"))

# The whole point: the machine-readable price must be the price charged.
expect = "%d.%02d" % (paise // 100, paise % 100)
ok("the price equals the database to the paisa", offer.get("price") == expect,
   str(offer.get("price")) + " vs " + expect)
ok("the price is a plain decimal, no symbol or grouping",
   re.fullmatch(r"\d+\.\d{2}", str(offer.get("price"))) is not None,
   offer.get("price"))

# ...and the price the shopper reads. money() prints a rupee sign and Indian
# digit grouping, so the comparison is on the digits alone.
shown = re.search(r'class="price price--lg">([^<]*)<', html)
shown_digits = re.sub(r"[^0-9]", "", shown.group(1)) if shown else ""
ok("the price equals the one printed on the page",
   shown_digits == str(paise // 100),
   shown_digits + " vs " + str(paise // 100))

ok("an in-stock product says InStock",
   offer.get("availability") == "https://schema.org/InStock",
   offer.get("availability"))
ok("a new product says NewCondition",
   offer.get("itemCondition") == "https://schema.org/NewCondition",
   offer.get("itemCondition"))
ok("the product url is absolute",
   str((prod or {}).get("url", "")).startswith("http"), (prod or {}).get("url"))

# Nothing invented. These two keys are how a site gets its rich results
# switched off, and there is no data behind either of them here.
dump_all = json.dumps(parsed)
ok("no aggregateRating is claimed", "aggregateRating" not in dump_all)
ok("no reviews are claimed", '"review"' not in dump_all)

items = (crumb or {}).get("itemListElement", [])
ok("the breadcrumb is numbered from one, in order",
   [i.get("position") for i in items] == list(range(1, len(items) + 1)),
   [i.get("position") for i in items])
ok("every breadcrumb item is an absolute url",
   bool(items) and all(str(i.get("item", "")).startswith("http") for i in items))
ok("the breadcrumb ends on this product",
   bool(items) and items[-1].get("item") == (prod or {}).get("url"),
   items[-1].get("item") if items else None)

# The trail has to match the one drawn on the page, or Google drops it.
drawn = re.search(r'<nav class="breadcrumb".*?</nav>', html, re.S)
pairs = re.findall(r'>([^<>]+)</a>|class="here">([^<]+)<',
                   drawn.group(0)) if drawn else []
drawn_names = [a or b for a, b in pairs]
ok("the trail has the same number of steps as the visible one",
   len(items) == len(drawn_names),
   str(len(items)) + " vs " + str(len(drawn_names)))


print()
print("--- a marketplace listing ---")

lslug = sql("SELECT slug FROM listings WHERE status='live' ORDER BY id LIMIT 1")
lpaise = int(sql("SELECT price_paise FROM listings WHERE slug='" + lslug + "'"))

status, html = get("/marketplace/" + lslug)
lbs = [json.loads(b) for b in blocks(html)]
lprod = next((g for g in lbs if g.get("@type") == "Product"), None)
ok("a live listing is marked up", lprod is not None)
ok("second-hand is marked Used or Refurbished",
   str((lprod or {}).get("itemCondition", "")).endswith(
       ("UsedCondition", "RefurbishedCondition")),
   (lprod or {}).get("itemCondition"))
ok("the listing price equals the database",
   (lprod or {}).get("offers", {}).get("price")
   == "%d.%02d" % (lpaise // 100, lpaise % 100),
   (lprod or {}).get("offers", {}).get("price"))

# The seller is a private individual. Their display name is on the page for a
# buyer to read; it is deliberately NOT handed to crawlers as structured data.
seller_name = sql("SELECT u.name FROM users u JOIN listings l "
                  "ON l.seller_id = u.id WHERE l.slug='" + lslug + "'")
dump = json.dumps(lbs)
ok("no seller is named in the structured data", '"seller"' not in dump)
ok("the seller's own name is not in the structured data",
   (not seller_name) or (seller_name not in dump), seller_name)


print()
print("--- a listing nobody can buy ---")

prev = sql("SELECT status FROM listings WHERE slug='" + lslug + "'")
sql("UPDATE listings SET status='withdrawn' WHERE slug='" + lslug + "'")
try:
    status, html = get("/marketplace/" + lslug)
    ok("a withdrawn listing carries no structured data",
       len(blocks(html)) == 0, len(blocks(html)))
    ok("...and is noindex", 'name="robots" content="noindex"' in html)
finally:
    sql("UPDATE listings SET status='" + prev + "' WHERE slug='" + lslug + "'")
ok("the listing was put back",
   sql("SELECT status FROM listings WHERE slug='" + lslug + "'") == prev)


print()
print("--- the encoder cannot be broken out of ---")

# A product name and a seller's description are attacker-controlled text
# sitting inside a <script> element. If the angle brackets are not escaped,
# a "</script>" inside a name ends the block early and the rest becomes
# markup the browser will happily run.
HOSTILE = "</script><img src=x onerror=alert(1)>" + chr(34) + "&" + chr(39)
probe = subprocess.run(
    [PHP, "-r", "require 'inc/bootstrap.php';"
                "$n = json_decode(stream_get_contents(STDIN));"
                "echo jsonld(['name' => $n]);"],
    cwd=REPO, input=json.dumps(HOSTILE), capture_output=True, text=True)
out = probe.stdout
ok("php ran", probe.returncode == 0, probe.stderr[:200])
inner = out[out.find(">") + 1:out.rfind("</script>")]
ok("no raw < survives into the block", "<" not in inner, inner[:90])
ok("no raw > survives into the block", ">" not in inner, inner[:90])
ok("no raw & survives into the block", "&" not in inner, inner[:90])
ok("there is exactly one closing script tag", out.count("</script>") == 1,
   out.count("</script>"))
ok("what is left is still valid JSON, unchanged",
   json.loads(inner).get("name") == HOSTILE, inner[:90])


print()
print("--- the helpers themselves ---")

probe = php("require 'inc/bootstrap.php';"
            "echo schema_price(0), '|', schema_price(5), '|',"
            "schema_price(1234567890), '|', abs_url('uploads/a.webp'), '|',"
            "abs_url('https://x.test/a.png'), '|', abs_url('/leading.jpg');")
parts = probe.stdout.split("|")
ok("the helper probe ran", len(parts) == 6, probe.stderr[:200] or probe.stdout)
if len(parts) == 6:
    ok("zero is 0.00", parts[0] == "0.00", parts[0])
    ok("five paise is 0.05", parts[1] == "0.05", parts[1])
    ok("a large price keeps two decimals and no grouping",
       parts[2] == "12345678.90", parts[2])
    ok("a relative path becomes absolute",
       parts[3].startswith("http") and parts[3].endswith("/uploads/a.webp"),
       parts[3])
    ok("an absolute url is left alone", parts[4] == "https://x.test/a.png",
       parts[4])
    ok("a leading slash does not double up", "//leading.jpg" not in parts[5],
       parts[5])

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
