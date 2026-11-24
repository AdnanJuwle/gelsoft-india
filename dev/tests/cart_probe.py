"""What a hostile quantity does to the cart, and to the money.

The clamps read correctly -- max(1, min(CART_MAX_QTY, ...)) in both cart_add()
and cart_set() -- but the number that matters is the one the page shows and
the checkout would charge, so it is worth seeing rather than inferring.

The token comes from the PRODUCT page: an empty cart has no form on it, so
taking it from /cart gave a blank token and every post came back 419.
"""
import http.cookiejar
import sys

# The console here is cp1252 and the totals carry a rupee sign.
sys.stdout.reconfigure(encoding='utf-8', errors='replace')
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"


def sql(q):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", q], capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


op = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def send(req):
    req.add_header("User-Agent", UA)
    try:
        r = op.open(req)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def get(p):
    return send(urllib.request.Request(BASE + p))


def post(d):
    r = urllib.request.Request(BASE + "/api/cart.php",
                               data=urllib.parse.urlencode(d).encode(), method="POST")
    r.add_header("Content-Type", "application/x-www-form-urlencoded")
    r.add_header("Accept", "application/json")
    return send(r)


row = sql("SELECT id, slug, price_paise, stock_qty FROM products "
          "WHERE status='published' AND stock_qty > 0 ORDER BY id LIMIT 1").split("\t")
PID, SLUG, PRICE, STOCK = row
PAGE = "/blasters/" + SLUG


def token():
    _, h = get(PAGE)
    m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
    return m.group(1) if m else ""


def state():
    """(quantity in the cart, the line total as printed)."""
    _, html = get("/cart")
    q = re.search(r'option value="(\d+)" selected', html)
    t = re.search(r'class="cart-line__total price">([^<]*)', html)
    return (q.group(1) if q else "0"), (t.group(1).strip() if t else "\u2014")


def clear():
    post({"_token": token(), "do": "remove", "product_id": PID})


print("product %s (%s): %s paise each, %s in stock" % (PID, SLUG, PRICE, STOCK))
print("CART_MAX_QTY is 9, so nine is the most anyone may hold\n")

print("  %-12s %-9s %-12s %s" % ("sent", "in cart", "line total", "note"))
for sent, note in [
    ("1", "the honest case"),
    ("3", "a normal order"),
    ("-5", "negative"),
    ("0", "zero"),
    ("999999", "far past the cap"),
    ("9.9", "not an integer"),
    ("abc", "not a number"),
    ("1e9", "scientific notation"),
    ("0x10", "hex"),
]:
    clear()
    post({"_token": token(), "do": "add", "product_id": PID, "qty": sent})
    q, t = state()
    print("  %-12s %-9s %-12s %s" % (sent, q, t, note))

print("\n  through 'set', which clamps separately:")
for sent in ["-5", "0", "999999", "abc"]:
    clear()
    post({"_token": token(), "do": "add", "product_id": PID, "qty": "1"})
    post({"_token": token(), "do": "set", "product_id": PID, "qty": sent})
    q, t = state()
    print("    set %-10s -> %s in cart, %s" % (sent, q, t))

print("\n  adding twice, to get past the cap in two goes:")
clear()
for _ in range(4):
    post({"_token": token(), "do": "add", "product_id": PID, "qty": "9"})
q, t = state()
print("    four lots of nine -> %s in cart, %s" % (q, t))

print("\n  an item that went out of stock while it sat in the cart:")
clear()
post({"_token": token(), "do": "add", "product_id": PID, "qty": "3"})
sql("UPDATE products SET stock_qty = 0 WHERE id = " + PID)
_, html = get("/cart")
said = re.search(r"sold out|out of stock|no longer|only have", html, re.I)
print("    the cart says: %s" % (said.group(0) if said else "NOTHING"))
post({"_token": token(), "do": "set", "product_id": PID, "qty": "9"})
q, t = state()
print("    and setting nine gives: %s in cart, %s" % (q, t))
sql("UPDATE products SET stock_qty = %s WHERE id = %s" % (STOCK, PID))

print("\n  an item taken off sale while it sat in the cart:")
clear()
post({"_token": token(), "do": "add", "product_id": PID, "qty": "2"})
sql("UPDATE products SET status = 'draft' WHERE id = " + PID)
_, html = get("/cart")
said = re.search(r"no longer for sale|took it out|not for sale", html, re.I)
print("    the cart says: %s" % (said.group(0) if said else "NOTHING"))
sql("UPDATE products SET status = 'published' WHERE id = " + PID)

clear()
print("\n(cart emptied)")
