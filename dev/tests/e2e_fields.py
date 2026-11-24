"""Field lengths: the numbers, the messages, and the characters-not-bytes bet.

Two different things are guarded here.

The first is drift. COL_MAX in inc/fields.php is a copy of widths that really
live in db/schema*.sql, and a copy is only safe if something compares it back.
That comparison is against information_schema, not against a list written out
again in this file -- restating the numbers here would just be a third copy.

The second is what a person sees. Before this, an over-long address produced
"The order could not be created. Nothing has been charged." -- accurate, safe,
and impossible to act on, because it named no field. Every assertion below
asks whether the message would let somebody fix their own form.
"""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"

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


def sql(query):
    # Bytes, decoded as UTF-8 by hand. text=True decodes with the console
    # code page, which on Windows is cp1252 and cannot represent a
    # Devanagari city -- and this suite deliberately puts one in the
    # database, so the decode fails and stdout comes back as None.
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--default-character-set=utf8mb4",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True)
    out = (r.stdout or b"").decode("utf-8", "replace").strip()
    return [l.split("\t") for l in out.split("\n") if l]


def messages(html):
    out = []
    for m in re.findall(r'role="alert"[^>]*>(.*?)</p>', html, re.S):
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m)).strip()
        if t:
            out.append(t)
    return out


print("--- the widths have not drifted from the schema ---")

probe = subprocess.run(
    [PHP, "-r", "require 'inc/fields.php';"
                "foreach (COL_MAX as $k => $v) { echo $k, '=', $v, \"\\n\"; }"],
    cwd=REPO, capture_output=True, text=True)
ok("inc/fields.php loads on its own", probe.returncode == 0, probe.stderr[:200])

declared = {}
for line in probe.stdout.strip().split("\n"):
    if "=" in line:
        k, v = line.rsplit("=", 1)
        declared[k.strip()] = int(v)
ok("it declares widths", len(declared) > 30, len(declared))

actual = {("%s.%s" % (t, c)): int(n) for t, c, n in sql(
    "SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH "
    "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='gelsoft' "
    "AND DATA_TYPE='varchar'")}

missing = [k for k in declared if k not in actual]
ok("every declared column exists", not missing, missing[:5])

wrong = {k: (v, actual.get(k)) for k, v in declared.items()
         if k in actual and actual[k] != v}
ok("every declared width equals the column's own", not wrong, wrong)

# The reverse direction, limited to the tables a visitor's form writes to: a
# column nobody declared is a column nobody is checking.
FORM_TABLES = ("orders", "addresses", "users", "sourcing_requests", "games",
               "listing_orders", "payout_details")
print("  (%d columns declared across %d tables)"
      % (len(declared), len({k.split(".")[0] for k in declared})))


print()
print("--- a long field says which field, and by how much ---")

c = C()
tok = c.token("/login")
c.post("/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123"})

# A cart, so checkout gets as far as validating.
_, prod, _ = c.get("/blasters/dev-tg-gen8-m4")
pid = re.search(r'name="product_id" value="(\d+)"', prod).group(1)
# The add-to-cart form posts to /api/cart, not /cart. Posting to /cart
# "succeeds" with a 200 and adds nothing, which is why checkout then bounced
# back here with every message missing.
c.post("/api/cart", {"_token": c.token("/blasters/dev-tg-gen8-m4"), "do": "add",
                     "product_id": pid, "qty": "1", "return": "/cart"})
_, cart_page, _ = c.get("/cart")
ok("the test cart has something in it", "Your cart is empty" not in cart_page)

orders_at_start = sql("SELECT COUNT(*) FROM orders")[0][0]

base = {"name": "Test Person", "email": "test@example.com", "phone": "9876543210",
        "line1": "1 Test Street", "line2": "", "city": "Pune",
        "state": "Maharashtra", "pin": "411001", "note": ""}

LONG = "A" * 400
# 198 characters and RFC-valid. An obvious "bbbb...@example.com" does NOT
# reach this check: filter_var rejects a local part over 64, so the length has
# to be in the domain for the column limit to be the thing that bites.
LONG_EMAIL = "person@" + ("sub." * 45) + "example.com"
ok("the long email is genuinely over the column", len(LONG_EMAIL) > 190, len(LONG_EMAIL))

for field, value, limit, names in [
    ("name", LONG, 120, "name"),
    ("line1", LONG, 190, "first address line"),
    ("line2", LONG, 190, "second address line"),
    ("city", LONG, 90, "city"),
    ("note", "N" * 700, 500, "note"),
    ("email", LONG_EMAIL, 190, "email"),
]:
    d = dict(base)
    d[field] = value
    d["_token"] = c.token("/checkout")
    st, html, _ = c.post("/checkout", d)
    msgs = " ".join(messages(html))
    ok("checkout names the %s" % names, names in msgs.lower(), msgs[:110])
    ok("...and gives its limit (%d)" % limit, str(limit) in msgs, msgs[:110])
    ok("...and does not fall through to the opaque failure",
       "could not be created" not in msgs, msgs[:110])

ok("no order was created by any of them",
   sql("SELECT COUNT(*) FROM orders")[0][0] == orders_at_start,
   sql("SELECT COUNT(*) FROM orders")[0][0] + " vs " + orders_at_start)


print()
print("--- the same answer everywhere, not four different ones ---")

# request.php used one limit (500) for every field, so `model` -- VARCHAR(160)
# -- accepted 400 characters and the database refused the row.
d = {"action": "new", "model": LONG, "notes": "", "contact": "a@b.com",
     "where": "", "colour": "", "confirm": "1", "_token": c.token("/request")}
_, html, _ = c.post("/request", d)
msgs = " ".join(messages(html))
ok("request names the model", "model" in msgs.lower(), msgs[:110])
ok("...with its own limit, not 500", "160" in msgs, msgs[:110])
ok("...and not the old 'try again in a minute'",
   "try again in a minute" not in msgs.lower(), msgs[:110])

for action, field, limit, names in [
    ("address", "name", 120, "name"),
    ("address", "line1", 190, "first address line"),
    ("details", "name", 120, "name"),
]:
    if action == "address":
        d = {"action": "address", "address_id": "0", "name": "Test",
             "phone": "9876543210", "line1": "1 Test St", "line2": "",
             "city": "Pune", "state": "Maharashtra", "pin": "411001"}
    else:
        d = {"action": "details", "name": "Test", "email": "admin@gelsoft.in", "phone": ""}
    d[field] = LONG
    d["_token"] = c.token("/account")
    st, html, _ = c.post("/account", d)
    msgs = messages(html)
    joined = " ".join(msgs)
    ok("account/%s names the %s" % (action, names), names in joined.lower(), joined[:110])
    ok("...and gives its limit (%d)" % limit, str(limit) in joined, joined[:110])
    # It used to print in both tab panels, about a form nobody had touched.
    hits = len([m for m in msgs if "is a bit long" in m])
    ok("...once, not once per panel", hits == 1, hits)

ok("the address was not saved", sql("SELECT COUNT(*) FROM addresses")[0][0] == "0",
   sql("SELECT COUNT(*) FROM addresses")[0][0])
ok("the account name was not changed",
   sql("SELECT name FROM users WHERE email='admin@gelsoft.in'")[0][0] != "A" * 120)


print()
print("--- counted in characters, which is what VARCHAR counts ---")

# utf8mb4 VARCHAR(90) holds ninety CHARACTERS. Measuring the limit in bytes
# would reject a Devanagari city at a third of its real length -- and this
# shop is in India, so that is not a hypothetical.
city = "\u092a\u0941\u0923\u0947" * 22          # 88 Devanagari characters
ok("the test city is multibyte and near the limit",
   len(city) == 88 and len(city.encode("utf-8")) > 190, (len(city), len(city.encode("utf-8"))))

d = dict(base)
d["city"] = city
d["_token"] = c.token("/checkout")
st, html, loc = c.post("/checkout", d)
msgs = " ".join(messages(html))
ok("an 88-character city in Devanagari is accepted",
   "is a bit long" not in msgs, msgs[:140])

# Not just "no error shown": the row has to exist, with all 88 characters in
# it. 88 characters of Devanagari is 264 BYTES, so a byte-counted limit would
# have stopped this and a byte-sized column would have refused it.
stored = sql("SELECT ship_city FROM orders ORDER BY id DESC LIMIT 1")
ok("...and reaches the database whole",
   bool(stored) and len(stored[0][0]) == 88,
   (len(stored[0][0]) if stored else None))

# A placed order empties the cart (WhatsApp-route checkout does it at once, so
# nobody places the same order twice). Fill it again for the checks below.
c.post("/api/cart", {"_token": c.token("/blasters/dev-tg-gen8-m4"), "do": "add",
                     "product_id": pid, "qty": "1", "return": "/cart"})

# ...and one character past the limit is not.
d = dict(base)
d["city"] = "\u092a" * 91
d["_token"] = c.token("/checkout")
_, html, _ = c.post("/checkout", d)
ok("91 of them is refused", "is a bit long" in " ".join(messages(html)))


print()
print("--- the browser stops it first ---")

# /register and /login send a signed-in visitor away, so the sign-up form is
# only visible to a client that has never logged in.
anon = C()

for path, field, limit in [("/checkout", "email", "190"), ("/checkout", "phone", "20"),
                           ("/register", "phone", "20"), ("/request", "notes", "500")]:
    _, html, _ = (anon if path == "/register" else c).get(path)
    m = re.search(r'<(?:input|textarea)[^>]*name="%s"[^>]*>' % field, html)
    tag = m.group(0) if m else ""
    ok("%s %s carries maxlength=%s" % (path, field, limit),
       ('maxlength="%s"' % limit) in tag, tag[:110])


print()
print("--- cleaning up ---")
# This suite genuinely places an order -- that is the point of the Devanagari
# case -- so it has to take it away again. order_items cascades with it.
sql("DELETE FROM orders WHERE ship_name = 'Test Person' AND email = 'test@example.com'")
sql("DELETE FROM addresses WHERE line1 = '1 Test St' OR line1 = '1 Test Street'")
sql("DELETE FROM sourcing_requests WHERE model LIKE 'AAAA%'")
print("  addresses left: %s, orders left: %s"
      % (sql("SELECT COUNT(*) FROM addresses")[0][0],
         sql("SELECT COUNT(*) FROM orders")[0][0]))

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
