"""What does a visitor actually SEE when a field is longer than its column?

maxlength is a hint to the browser; a POST ignores it, and so does anyone
pasting a long address into a field that has no maxlength at all. sql_mode
here is STRICT_TRANS_TABLES, so the database refuses the row rather than
quietly cutting it.

The question is not whether the data is safe -- the inserts are in
transactions and roll back. It is whether the person is told anything they
can act on, or is left pressing a button that will never work.
"""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def _do(self, req):
        req.add_header("User-Agent", UA)
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

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


def shown(html):
    """Whatever the page is telling the person, in one line."""
    msgs = re.findall(r'role="alert"[^>]*>(.*?)</p>', html, re.S)
    msgs += re.findall(r'<li[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)</li>', html, re.S)
    msgs += re.findall(r'class="field__error"[^>]*>(.*?)<', html, re.S)
    out = []
    for m in msgs:
        t = re.sub(r"<[^>]+>", " ", m)
        t = re.sub(r"\s+", " ", t).strip()
        if t:
            out.append(t)
    return " | ".join(out)[:220] or "(no message on the page)"


c = C()
tok = c.token("/login")
c.post("/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123"})

LONG = "A" * 400
# 198 characters and RFC-valid: filter_var accepts it (it rejects a local
# part over 64, which is why an obvious "bbbb...@example.com" does not test
# this path -- the length has to be in the DOMAIN).
LONG_EMAIL = "person@" + ("sub." * 45) + "example.com"

print("=== checkout: over-long fields, with a real cart ===")
tok = c.token("/blasters/dev-tg-gen8-m4")
st, _, _ = c.post("/api/cart", {"_token": tok, "do": "add", "product_id": "1", "qty": "1"})
# the api may want json; fall back to the plain form post the page uses
_, prod, _ = c.get("/blasters/dev-tg-gen8-m4")
pid = re.search(r'name="product_id" value="(\d+)"', prod)
if pid:
    tok = c.token("/blasters/dev-tg-gen8-m4")
    c.post("/cart", {"_token": tok, "do": "add", "product_id": pid.group(1), "qty": "1",
                     "return": "/cart"})
_, cart, _ = c.get("/cart")
print("  cart has lines:", "Your cart is empty" not in cart)

base = {
    "name": "Test Person", "email": "test@example.com", "phone": "9876543210",
    "line1": "1 Test Street", "line2": "", "city": "Pune",
    "state": "Maharashtra", "pin": "411001", "note": "",
}

for field, value, why in [
    ("name", LONG, "ship_name is VARCHAR(120)"),
    ("line1", LONG, "ship_line1 is VARCHAR(190)"),
    ("line2", LONG, "ship_line2 is VARCHAR(190)"),
    ("city", LONG, "ship_city is VARCHAR(90)"),
    ("note", "N" * 700, "note is VARCHAR(500)"),
    ("email", LONG_EMAIL, "email is VARCHAR(190), and this one is valid"),
]:
    d = dict(base)
    d[field] = value
    d["_token"] = c.token("/checkout")
    st, html, loc = c.post("/checkout", d)
    print("  %-7s %-42s -> %s  %s" % (field, why, st, shown(html)))

print()
print("=== the marketplace address form (buy.php) ===")
_, mk, _ = c.get("/marketplace/dev-tg-gen8-used")
print("  listing page:", "200 ok" if mk else "?")

print()
print("=== request a model ===")
for field, value in [("notes", "N" * 700), ("model", LONG), ("contact", LONG)]:
    d = {"action": "new", "model": "Test model", "notes": "", "contact": "a@b.com",
         "where": "", "colour": ""}
    d[field] = value
    d["_token"] = c.token("/request")
    st, html, _ = c.post("/request", d)
    print("  %-8s -> %s  %s" % (field, st, shown(html)))

print()
print("=== account: saving an address ===")
for field, value in [("name", LONG), ("line1", LONG), ("city", LONG)]:
    d = {"action": "address", "address_id": "0", "name": "Test", "phone": "9876543210",
         "line1": "1 Test Street", "line2": "", "city": "Pune",
         "state": "Maharashtra", "pin": "411001", "is_default": "1"}
    d[field] = value
    d["_token"] = c.token("/account")
    st, html, _ = c.post("/account", d)
    print("  %-7s -> %s  %s" % (field, st, shown(html)))
