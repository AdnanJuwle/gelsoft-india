"""Every internal link on every page, followed, as a signed-in admin.

This exists because /admin/ linked to /add-item rather than /admin/add-item
for weeks: the page rendered, the button looked right, and nothing failed
until somebody clicked it. Rendering a page is not evidence that its links go
anywhere.
"""
import html as htmllib
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
ADMIN, ADMIN_PW = "admin@gelsoft.in", "devpassword123"

# Pages to start from. Links found on these are followed one hop.
SEEDS = [
    "/", "/blasters", "/gear", "/parts", "/marketplace", "/search?q=gen8",
    "/request", "/games", "/faq", "/safety", "/terms", "/privacy",
    "/login", "/register", "/forgot", "/cart", "/sell",
    "/admin/", "/admin/add-item", "/admin/faq", "/admin/pages",
    "/admin/orders", "/admin/marketplace", "/admin/community", "/admin/staff",
]

# Signing out mid-crawl would break every page after it. Nothing else here is
# a GET that changes anything.
SKIP = {"/logout", "/admin/logout"}

# The admin dashboard lists every product with a link to its public page, and
# a DRAFT product's public page correctly 404s -- it is not published. Those
# links are therefore not checked: whether a given row happens to be a draft
# depends on what is in the database, so checking them makes this suite fail
# or pass depending on what ran before it, which is worse than not checking.
# Links to a product from anywhere PUBLIC are still followed.
SHOP = re.compile(r"^/(blasters|gear|parts)/.")


class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def get(self, p):
        try:
            r = self.op.open(urllib.request.Request(BASE + p))
            return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def post(self, p, d):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            rr = self.op.open(r)
            return rr.status, rr.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")


c = C()
_, page = c.get("/admin/login")
tok = re.search(r'name="_token" value="([a-f0-9]+)"', page).group(1)
c.post("/admin/login", {"_token": tok, "email": ADMIN, "password": ADMIN_PW})

code, page = c.get("/admin/")
if code != 200:
    print(f"could not sign in as admin (got {code}) -- is the fixture there?")
    sys.exit(1)

LINK = re.compile(r'(?:href|action)="(/[^"#]*)"')

# A tab button is not a link, so the crawl above cannot see it. It names a
# panel instead, and site.js hides every panel that does not match -- so a
# button naming a panel that does not exist blanks the whole page while the
# URL stays put. That is what "Open FAQ" did: data-tab="faq" with no
# data-panel="faq", left behind when the FAQ editor moved to its own page.
TAB = re.compile(r'data-tab="([a-z0-9_-]+)"')
PANEL = re.compile(r'data-panel="([a-z0-9_-]+)"')

seen, broken, checked, dead_tabs = set(), [], 0, []

for seed in SEEDS:
    code, page = c.get(seed)
    if code == 404:
        broken.append((seed, "(seed page)", 404))
        continue

    panels = set(PANEL.findall(page))
    for tab in sorted(set(TAB.findall(page))):
        if tab not in panels:
            dead_tabs.append((seed, tab))

    for raw in LINK.findall(page):
        link = htmllib.unescape(raw)
        path = link.split("?")[0].rstrip("/") or "/"
        if path in SKIP or link in seen:
            continue
        if seed.startswith("/admin/") and SHOP.match(path):
            continue
        seen.add(link)

        st, _ = c.get(link)
        checked += 1
        if st == 404:
            broken.append((seed, link, st))

print(f"followed {checked} distinct links across {len(SEEDS)} pages")
print(f"checked every tab button has a panel to show\n")

for where, link, st in broken:
    print(f"  BROKEN LINK  {link}  ({st})  linked from {where}")
for where, tab in dead_tabs:
    print(f"  DEAD TAB     data-tab=\"{tab}\" has no data-panel, on {where}")
    print(f"               clicking it hides every panel and blanks the page")

if broken or dead_tabs:
    print(f"\n{len(broken)} broken link(s), {len(dead_tabs)} dead tab(s)")
    sys.exit(1)

print("no broken internal links, no dead tabs")
