"""The sitemap, the tab icon, and the page that is edited but never shown.

The sitemap checks are mostly one idea: a sitemap is a PROMISE to a crawler,
and the ways it goes wrong are all the ways a promise and the site drift
apart. It lists a URL that 404s. It lists a URL robots.txt forbids. It lists
the same URL twice. It stops listing the stock because the stock changed and
the file did not. None of those shows up anywhere a person looks.

So every assertion here is a cross-check against something else that already
exists -- robots.txt, the database, the live response -- rather than against a
copy of the answer written into this file.
"""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.dom.minidom

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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def _do(self, req):
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace"), r.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers

    def get(self, p):
        return self._do(urllib.request.Request(BASE + p))

    def post(self, p, d):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(r)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""


def sql(query):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True, text=True)
    return [l for l in r.stdout.strip().split("\n") if l]


def php(code):
    return subprocess.run([PHP, "-r", code], cwd=REPO, capture_output=True, text=True)


c = C()

print("--- the sitemap is served and parses ---")

status, body, headers = c.get("/sitemap.xml")
ok("/sitemap.xml answers 200", status == 200, status)
ok("it is served as XML",
   "xml" in (headers.get("Content-Type") or ""), headers.get("Content-Type"))

doc = None
try:
    doc = xml.dom.minidom.parseString(body)
    ok("it is well-formed XML", True)
except Exception as e:
    ok("it is well-formed XML", False, e)

locs = [n.firstChild.data for n in doc.getElementsByTagName("loc")] if doc else []
ok("it has urls in it", len(locs) > 12, len(locs))
ok("the root element is a urlset",
   bool(doc) and doc.documentElement.tagName == "urlset",
   doc.documentElement.tagName if doc else None)
ok("it declares the sitemaps.org namespace",
   bool(doc) and doc.documentElement.getAttribute("xmlns")
   == "http://www.sitemaps.org/schemas/sitemap/0.9")

# A duplicate <loc> is an error in the format, and it is also the symptom that
# found the shadowed /policy row.
ok("no url appears twice", len(locs) == len(set(locs)),
   [u for u in locs if locs.count(u) > 1][:3])
ok("every url is absolute", all(u.startswith("http") for u in locs),
   [u for u in locs if not u.startswith("http")][:3])


print()
print("--- it does not contradict robots.txt ---")

_, robots, _ = c.get("/robots.txt")
disallowed = re.findall(r"(?mi)^\s*Disallow:\s*(\S+)", robots)
ok("robots.txt has rules to check against", len(disallowed) >= 5, disallowed)

paths = [urllib.parse.urlparse(u).path for u in locs]
clashes = [p for p in paths
           for d in disallowed
           if d != "/" and (p == d.rstrip("/") or p.startswith(d))]
ok("no sitemap url is one robots.txt forbids", not clashes, clashes[:5])

ok("robots.txt still points at the sitemap",
   "sitemap.xml" in robots.lower(), robots[:80])

# Found live: "Disallow: /uploads/" hid every product photo from Google --
# the Product data named images Googlebot was told it may not fetch.
for img in ["/uploads/tg-ddm4-pdw-3e365fc7696c.webp", "/assets/img/hero-blaster.jpg",
            "/assets/img/og-image.jpg"]:
    ok("Google may fetch %s" % img.rsplit("/", 1)[0] + "/",
       not [d for d in disallowed if d != "/" and img.startswith(d)], disallowed)


print()
print("--- it matches what is actually in the shop ---")

pub = set(sql("SELECT slug FROM products WHERE status='published'"))
unpub = set(sql("SELECT slug FROM products WHERE status<>'published'"))
live = set(sql("SELECT slug FROM listings WHERE status='live'"))
dead = set(sql("SELECT slug FROM listings WHERE status<>'live'"))

in_map = " ".join(paths)
ok("every published product is listed",
   all(("/" + s) in in_map for s in pub),
   [s for s in pub if ("/" + s) not in in_map][:3])
ok("no unpublished product is listed",
   not [s for s in unpub if ("/" + s) in in_map],
   [s for s in unpub if ("/" + s) in in_map][:3])
ok("every live listing is listed",
   all(("/marketplace/" + s) in in_map for s in live),
   [s for s in live if ("/marketplace/" + s) not in in_map][:3])
ok("no dead listing is listed",
   not [s for s in dead if ("/marketplace/" + s) in in_map],
   [s for s in dead if ("/marketplace/" + s) in in_map][:3])
ok("the shelves are all there",
   all(any(p == s for p in paths) for s in ("/blasters", "/gear", "/parts", "/marketplace")))
ok("the home page is there", "/" in paths)

# The promise itself: a crawler fetching these gets a page.
bad = []
for p in paths:
    st, _, _ = c.get(p)
    if st != 200:
        bad.append((p, st))
ok("every url in it answers 200", not bad, bad[:4])

mods = [n.firstChild.data for n in doc.getElementsByTagName("lastmod")] if doc else []
ok("there are lastmod dates", len(mods) > 0, len(mods))
ok("every lastmod is a plain W3C date",
   all(re.fullmatch(r"\d{4}-\d{2}-\d{2}", m) for m in mods),
   [m for m in mods if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", m)][:3])

# Google ignores both, and a number nobody reads can only be wrong.
ok("no priority is claimed", "<priority>" not in body)
ok("no changefreq is claimed", "<changefreq>" not in body)

# The static file it replaced must be gone, or router.php serves that instead
# and the difference is invisible.
import os
ok("the old static sitemap.xml is gone",
   not os.path.exists(os.path.join(REPO, "sitemap.xml")))


print()
print("--- a database page the site does not show ---")

probe = php("require 'inc/bootstrap.php';"
            "echo (int) page_shadowed('policy'), (int) page_shadowed('terms'),"
            "(int) page_shadowed('privacy'), (int) page_shadowed('../inc/config');")
ok("the helper probe ran", probe.returncode == 0, probe.stderr[:200])
ok("policy is shadowed by policy.php", probe.stdout[:1] == "1", probe.stdout)
ok("terms is not", probe.stdout[1:2] == "0", probe.stdout)
ok("privacy is not", probe.stdout[2:3] == "0", probe.stdout)
# A slug is database text; it must never become a path.
ok("a slug with a traversal in it is refused, not resolved",
   probe.stdout[3:4] == "0", probe.stdout)

tok = c.token("/login")
c.post("/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123"})
tok = c.token("/admin/login")
st, _, _ = c.post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in",
                                   "password": "devpassword123", "next": "/admin/pages"})
ok("signed in to admin", st in (302, 303), st)

_, shadow_page, _ = c.get("/admin/pages?slug=policy")
_, terms_page, _ = c.get("/admin/pages?slug=terms")

ok("the shadowed page is flagged", "This text is not on the site" in shadow_page)
ok("...with a warning, not a neutral note", "notice--ember" in shadow_page)
ok("...and the variant it uses exists in the stylesheet",
   ".notice--ember" in open(REPO + r"\assets\css\site.css", encoding="utf-8").read())
ok("...and it names the file that really answers", "policy.php" in shadow_page)
ok("it does not offer to 'View live page'", "View live page" not in shadow_page)
ok("a normal page still does", "View live page" in terms_page)
ok("a normal page is not flagged", "This text is not on the site" not in terms_page)

_, saved_shadow, _ = c.get("/admin/pages?slug=policy&saved=1")
_, saved_terms, _ = c.get("/admin/pages?slug=terms&saved=1")
ok("saving a shadowed page does not claim the site shows it",
   "The live page shows this now" not in saved_shadow)
ok("...and says where it did go", "Saved to the database" in saved_shadow)
ok("saving a real page still says it is live",
   "The live page shows this now" in saved_terms)


print()
print("--- the tab icon ---")

st, svg, headers = c.get("/assets/img/favicon.svg")
ok("the icon is served", st == 200, st)
ok("...as an SVG image",
   (headers.get("Content-Type") or "").startswith("image/svg+xml"),
   headers.get("Content-Type"))
try:
    xml.dom.minidom.parseString(svg)
    ok("...and is well-formed XML", True)
except Exception as e:
    ok("...and is well-formed XML", False, e)

ok("it carries both colour schemes", "prefers-color-scheme" in svg)
ok("it has no script in it", "<script" not in svg.lower())
# Not "no http:// appears in the file": xmlns is a namespace NAME that looks
# like a URL and is never fetched. What matters is whether anything is
# REFERENCED -- an external stylesheet or image in a favicon would be a
# request to somebody else on every page load.
refs = (re.findall(r'(?:xlink:href|href|src)\s*=\s*"([^"]+)"', svg)
        + re.findall(r"url\(\s*[\'\"]?(https?:[^)]+)", svg)
        + re.findall(r"@import[^;]+", svg))
ok("it references nothing from outside", not refs, refs[:3])

for path, what in [("/", "a storefront page"), ("/blasters", "a shelf"),
                   ("/faq", "an information page")]:
    _, html, _ = c.get(path)
    ok(what + " links to it",
       'rel="icon"' in html and "favicon.svg" in html, path)

ok("the admin pages link to it too",
   'rel="icon"' in shadow_page and "favicon.svg" in shadow_page)

# The version query is what lets a replacement reach people who have the old
# one cached for a year.
_, home, _ = c.get("/")
m = re.search(r'href="(/assets/img/favicon\.svg\?v=\d+)"', home)
ok("the link is versioned", m is not None,
   re.search(r'favicon[^"]*', home).group(0) if "favicon" in home else None)
if m:
    st, _, _ = c.get(m.group(1))
    ok("...and the versioned url still fetches it", st == 200, st)


print()
print("--- the seller's own city and state can be autofilled ---")

_, sell, _ = c.get("/sell")
ok("city asks for the address level the browser stores",
   'name="city"' in sell and 'autocomplete="address-level2"' in sell)
ok("state does too", 'autocomplete="address-level1"' in sell)

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
