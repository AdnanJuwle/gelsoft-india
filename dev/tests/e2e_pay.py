"""Phase 4: cart, checkout, and the webhook that decides money is real.

Razorpay's key_id is empty locally, so checkout stops at a pending order
instead of calling out. The webhook is then driven directly, which is exactly
what Razorpay would do.
"""
import hashlib
import hmac
import http.cookiejar
import json
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
WEBHOOK_SECRET = "devwebhook_local_only"
KEY_SECRET = "devsecret_local_only"


def sql(query):
    r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft", "-sN", "-e", query],
                       capture_output=True)
    return r.stdout.decode("utf-8", "replace").strip()


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
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

    def get(self, p):
        return self._do(urllib.request.Request(BASE + p))

    def post(self, p, d, headers=None):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        for k, v in (headers or {}).items():
            r.add_header(k, v)
        return self._do(r)

    def post_raw(self, p, body: bytes, headers):
        r = urllib.request.Request(BASE + p, data=body, method="POST")
        r.add_header("Content-Type", "application/json")
        for k, v in headers.items():
            r.add_header(k, v)
        return self._do(r)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


# A known product to buy, with a known price and stock.
sql("DELETE FROM products WHERE slug = 'pay-test-item'")
sql("INSERT INTO products (slug, category, name, grade, gears, price_paise, stock_qty, status) "
    "VALUES ('pay-test-item','blaster','Pay Test Blaster','mid','metal',250000,3,'published')")
pid = sql("SELECT id FROM products WHERE slug='pay-test-item'")
print(f"product {pid}, 3 in stock at 2,500\n")

print("--- the cart ---")
c = C()
tok = c.token("/blasters/pay-test-item")
code, html, _ = c.post("/api/cart.php",
                       {"_token": tok, "do": "add", "product_id": pid, "qty": "2"},
                       {"Accept": "application/json"})
data = json.loads(html)
check("adding to the cart works", data.get("ok") and data.get("count") == 2, html[:120])

code, html, _ = c.post("/api/cart.php",
                       {"_token": tok, "do": "add", "product_id": pid, "qty": "9"},
                       {"Accept": "application/json"})
data = json.loads(html)
check("the cart is capped at what is in stock", data.get("count") == 3, html[:160])

code, html, _ = c.get("/cart")
check("the cart page prices it from the database", "7,500" in html, "")

code, html, _ = c.post("/api/cart.php", {"do": "add", "product_id": pid},
                       {"Accept": "application/json"})
check("no CSRF token is refused", code == 419, f"code={code}")

print("\n--- checkout ---")
tok = c.token("/checkout")
good = {
    "_token": tok, "name": "Test Buyer", "email": "pay.test@example.com",
    "phone": "9876543210", "line1": "12 Test Road", "line2": "",
    "city": "Pune", "state": "Maharashtra", "pin": "411001", "note": "",
}

bad = dict(good, pin="000000")
code, html, _ = c.post("/checkout", bad)
check("a bad PIN is refused", "six digits" in html, f"code={code}")

bad = dict(good, phone="12345")
bad["_token"] = c.token("/checkout")
code, html, _ = c.post("/checkout", bad)
check("a bad phone number is refused", "Indian mobile" in html)

bad = dict(good, state="Narnia")
bad["_token"] = c.token("/checkout")
code, html, _ = c.post("/checkout", bad)
check("a state not on the list is refused", "Pick a state" in html)

good["_token"] = c.token("/checkout")
code, html, loc = c.post("/checkout", good)
check("a valid checkout creates the order", code == 302 and "/order?ref=" in (loc or ""),
      f"code={code} loc={loc}")

ref = re.search(r"ref=([^&]+)", loc or "")
ref = urllib.parse.unquote(ref.group(1)) if ref else ""
check("the order has a reference", ref.startswith("GS-"), ref)

row = sql(f"SELECT status, total_paise, user_id FROM orders WHERE reference='{ref}'").split("\t")
check("it is pending, not paid", row[0] == "pending", row)
# 7,500 is over the 5,000 free-shipping threshold, so shipping is 0.
check("the total was computed server-side", row[1] == "750000",
      f"{row[1]} (7,500, free shipping over 5,000)")

stock = sql(f"SELECT stock_qty FROM products WHERE id={pid}")
check("stock is untouched while unpaid", stock == "3", stock)

print("\n--- the order page ---")
code, html, _ = c.get(f"/order?ref={urllib.parse.quote(ref)}")
check("the order page needs the token", code == 404, f"code={code}")

tok_order = sql(f"SELECT id, created_at FROM orders WHERE reference='{ref}'")
oid = tok_order.split("\t")[0]

# The thank-you link the checkout redirect carried.
t = re.search(r"t=([^&]+)", loc or "")
t = urllib.parse.unquote(t.group(1)) if t else ""
code, html, _ = c.get(f"/order?ref={urllib.parse.quote(ref)}&t={urllib.parse.quote(t)}")
check("with the token it opens", code == 200 and ref in html, f"code={code}")
# With no Razorpay keys the order is a WhatsApp-route one: the page says what
# happens next, and still that nothing has been charged.
check("it says it is placed, unpaid, and settled on WhatsApp",
      "Order placed" in html and "WhatsApp" in html and "Nothing has been charged" in html)

anon = C()
code, _, _ = anon.get(f"/order?ref={urllib.parse.quote(ref)}&t=wrongtoken")
check("a wrong token is refused", code == 404, f"code={code}")

print("\n--- the webhook is the only thing that marks it paid ---")
# Give the order a payment_order_id, as the real checkout would have.
rzp_order = "order_TEST" + oid
sql(f"UPDATE orders SET payment_order_id='{rzp_order}' WHERE id={oid}")


def webhook(event, amount, payment_id="pay_TEST1", event_id="evt_1", secret=WEBHOOK_SECRET):
    body = json.dumps({
        "event": event,
        "payload": {"payment": {"entity": {
            "id": payment_id, "order_id": rzp_order, "amount": amount, "status": "captured",
        }}},
    }).encode()
    sig = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    return C().post_raw("/api/webhook.php", body,
                        {"X-Razorpay-Signature": sig, "X-Razorpay-Event-Id": event_id})


code, html, _ = C().post_raw("/api/webhook.php", b'{"event":"payment.captured"}',
                             {"X-Razorpay-Signature": "deadbeef", "X-Razorpay-Event-Id": "evt_bad"})
check("an unsigned webhook is rejected", code == 401, f"code={code} {html[:80]}")

status = sql(f"SELECT status FROM orders WHERE id={oid}")
check("...and the order is still pending", status == "pending", status)

code, html, _ = webhook("payment.captured", 100, payment_id="pay_SHORT", event_id="evt_short")
check("an underpayment does not mark it paid", code == 200)
status = sql(f"SELECT status FROM orders WHERE id={oid}")
check("...it stays pending", status == "pending", status)
note = sql(f"SELECT admin_note FROM orders WHERE id={oid}")
check("...and it is flagged for a human", "Underpaid" in note, note)

code, html, _ = webhook("payment.captured", 750000, payment_id="pay_GOOD", event_id="evt_good")
check("a correctly signed capture is accepted", code == 200, f"code={code} {html[:80]}")

row = sql(f"SELECT status, payment_id FROM orders WHERE id={oid}").split("\t")
check("the order is now paid", row[0] == "paid", row)
check("the payment id is recorded", row[1] == "pay_GOOD", row)

stock = sql(f"SELECT stock_qty FROM products WHERE id={pid}")
check("stock came off when the money landed", stock == "0", f"3 - 3 = {stock}")

print("\n--- delivered twice, which Razorpay does ---")
code, html, _ = webhook("payment.captured", 750000, payment_id="pay_GOOD", event_id="evt_good")
check("the repeat is recognised", code == 200 and "already seen" in html, html[:80])
stock = sql(f"SELECT stock_qty FROM products WHERE id={pid}")
check("stock is not taken twice", stock == "0", stock)

code, html, _ = webhook("payment.captured", 750000, payment_id="pay_GOOD2", event_id="evt_other")
check("a different event on a paid order changes nothing", code == 200)
paid_count = sql(f"SELECT COUNT(*) FROM orders WHERE id={oid} AND status='paid'")
check("...it is still exactly one paid order", paid_count == "1", paid_count)

print("\n--- the browser's word is not enough ---")
sig = hmac.new(KEY_SECRET.encode(), f"{rzp_order}|pay_FAKE".encode(), hashlib.sha256).hexdigest()
code, html, _ = C().post("/api/payment-return.php", {
    "razorpay_order_id": rzp_order, "razorpay_payment_id": "pay_FAKE",
    "razorpay_signature": "notarealsignature",
})
check("an unsigned hand-back is rejected", code == 400, f"code={code} {html[:80]}")

code, html, _ = C().post("/api/payment-return.php", {
    "razorpay_order_id": rzp_order, "razorpay_payment_id": "pay_FAKE",
    "razorpay_signature": sig,
})
check("a signed hand-back is accepted", code == 200, f"code={code}")
row = sql(f"SELECT payment_id FROM orders WHERE id={oid}")
check("...but it did not overwrite the webhook's payment id", row == "pay_GOOD", row)

print("\n--- the order page after payment ---")
code, html, _ = c.get(f"/order?ref={urllib.parse.quote(ref)}&t={urllib.parse.quote(t)}")
check("it says paid", "Paid. Thank you." in html, "")
check("the total is shown", "7,500" in html)

print("\n--- the admin can move it, but not fake it ---")
a = C()
atok = a.token("/admin/login")
a.post("/admin/login", {"_token": atok, "email": "admin@gelsoft.in",
                        "password": "devpassword123", "next": "/admin/"})
code, html, _ = a.get("/admin/orders")
check("orders list shows it", code == 200 and ref in html, f"code={code}")

atok = a.token(f"/admin/orders?id={oid}")
code, html, loc = a.post("/admin/orders", {
    "_token": atok, "id": oid, "status": "paid", "tracking": "", "courier": "", "admin_note": "",
})
check("an admin cannot set 'paid' by hand", "not a status this screen can set" in html, f"code={code}")

atok = a.token(f"/admin/orders?id={oid}")
code, html, loc = a.post("/admin/orders", {
    "_token": atok, "id": oid, "status": "shipped",
    "tracking": "ABC123", "courier": "Delhivery", "admin_note": "",
})
check("an admin can ship it", code == 302, f"code={code}")
row = sql(f"SELECT status, tracking FROM orders WHERE id={oid}").split("\t")
check("the status and tracking saved", row == ["shipped", "ABC123"], row)

REPO_FOR_STOCK = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP_BIN = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
           r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")


def php(code):
    return subprocess.run([PHP_BIN, "-r", code], cwd=REPO_FOR_STOCK,
                          capture_output=True, text=True)


cust = C()
code, _, _ = cust.get("/admin/orders")
check("a stranger cannot open the orders screen", code in (302, 403), f"code={code}")

print("\n--- stock comes back when an order stops being a sale ---")

# There used to be exactly one statement in the codebase that touched
# stock_qty, and it was a subtraction. A cancelled order cost the shop a unit
# of sellable stock, permanently, and on a shop whose stock_qty is usually
# between one and six that reads as "sold out" while the thing is on the
# shelf.
#
# The assertions worth having are the ones about when it must NOT restore.


def stock_of(product_id):
    return int(sql(f"SELECT stock_qty FROM products WHERE id={product_id}"))


def make_order(product_id, qty, status, ref):
    sql(f"""INSERT INTO orders (reference, user_id, email, phone, ship_name,
              ship_line1, ship_city, ship_state, ship_pin, subtotal_paise,
              shipping_paise, total_paise, status, payment_provider)
            VALUES ('{ref}', NULL, 'stock@example.com', '9876543210', 'Stock',
              '1 Test St', 'Pune', 'Maharashtra', '411001', 100000, 0, 100000,
              'pending', 'razorpay')""")
    oid = int(sql(f"SELECT id FROM orders WHERE reference='{ref}'"))
    sql(f"""INSERT INTO order_items (order_id, product_id, name_at_purchase,
              slug_at_purchase, price_paise, qty)
            VALUES ({oid}, {product_id}, 'Stock item', 'stock-item', 100000, {qty})""")
    if status != "pending":
        php(f"require 'inc/bootstrap.php'; require_once 'inc/orders.php';"
            f"require_once 'inc/cart.php'; order_mark_paid({oid}, 'pay_stock_{oid}');")
        if status != "paid":
            sql(f"UPDATE orders SET status='{status}' WHERE id={oid}")
    return oid


def close_order(order_id, to):
    r = php(f"require 'inc/bootstrap.php'; require_once 'inc/orders.php';"
            f"require_once 'inc/cart.php';"
            f"$r = order_close({order_id}, '{to}');"
            f"echo (int) $r['changed'], ',', $r['restored'], ',', (int) $r['dispatched'];")
    return r.stdout.strip().split(",")


sp = int(sql("SELECT id FROM products WHERE status='published' AND stock_qty > 2 ORDER BY id LIMIT 1"))
sp_start = stock_of(sp)
check("there is a product to test against", sp_start > 2, sp_start)

for n, (frm, to, qty, restores, why) in enumerate([
    ("paid", "cancelled", 2, True, "taken off the shelf but not dispatched"),
    ("packed", "refunded", 2, True, "boxed and still here"),
    ("pending", "cancelled", 2, False, "an abandoned checkout never took any"),
    ("shipped", "refunded", 2, False, "the parcel is out in the world"),
]):
    ref = f"ST-E2E-{n}"
    sql(f"DELETE FROM orders WHERE reference='{ref}'")
    oid = make_order(sp, qty, frm, ref)
    changed, restored, dispatched = close_order(oid, to)

    check(f"{frm} -> {to}: the status moves", changed == "1", changed)
    if restores:
        check(f"...{why}, so {qty} go back", restored == str(qty), restored)
        check("...and the shelf is level again", stock_of(sp) == sp_start, stock_of(sp))
    else:
        check(f"...{why}, so nothing is invented", restored == "0", restored)

    if frm == "shipped":
        # Not a silent shortfall: the admin screen says so, because whether
        # the parcel comes back is a human fact and not a database one.
        check("...and it reports that it had shipped", dispatched == "1", dispatched)
        admin_src = open(REPO_FOR_STOCK + r"\admin\orders.php", encoding="utf-8").read()
        check("...which the admin screen tells the operator",
              "nothing was added back to stock" in admin_src)

    sql(f"DELETE FROM orders WHERE id={oid}")
    sql(f"UPDATE products SET stock_qty={sp_start} WHERE id={sp}")

# Razorpay sends refund.created AND refund.processed for one refund, so this
# path runs twice for the same money.
sql("DELETE FROM orders WHERE reference='ST-E2E-TWICE'")
oid = make_order(sp, 2, "paid", "ST-E2E-TWICE")
first = close_order(oid, "refunded")
after_one = stock_of(sp)
second = close_order(oid, "refunded")
after_two = stock_of(sp)
check("the first refund restores", first[1] == "2", first)
check("the second one changes nothing", second[0] == "0" and second[1] == "0", second)
check("...so the stock is not restored twice", after_one == after_two == sp_start,
      f"{after_one} then {after_two}, started {sp_start}")

sql(f"DELETE FROM orders WHERE id={oid}")
sql(f"UPDATE products SET stock_qty={sp_start} WHERE id={sp}")
sql("DELETE FROM orders WHERE reference LIKE 'ST-E2E-%'")
check("the probe left nothing behind",
      sql("SELECT COUNT(*) FROM orders WHERE reference LIKE 'ST-E2E-%'") == "0")

print("\n--- and the screen says so ---")

# The stock moved correctly long before this did. redirect() ends the
# request, so the message the handler had built was thrown away and the page
# printed a hard-coded "Saved." -- which is also why "the dispatch email has
# gone out" had never once been shown. Found by driving the screen, not the
# function.
src = open(REPO_FOR_STOCK + r"\admin\orders.php", encoding="utf-8").read()
check("the handler no longer builds a message it discards", "$notice" not in src)
check("the outcome travels through the redirect", "&saved=' . $saved" in src)

sql("DELETE FROM orders WHERE reference='NOTICE-E2E'")
sql("""INSERT INTO orders (reference, user_id, email, phone, ship_name, ship_line1,
         ship_city, ship_state, ship_pin, subtotal_paise, shipping_paise,
         total_paise, status, payment_provider)
       VALUES ('NOTICE-E2E', NULL, 'n@example.com', '9876543210', 'N', '1 St',
         'Pune', 'Maharashtra', '411001', 100, 0, 100, 'cancelled', 'razorpay')""")
noid = int(sql("SELECT id FROM orders WHERE reference='NOTICE-E2E'"))
sql(f"""INSERT INTO order_items (order_id, product_id, name_at_purchase,
          slug_at_purchase, price_paise, qty)
        VALUES ({noid}, {sp}, 'Notice item', 'notice-item', 100, 2)""")


def notice_for(code):
    _, html, _ = a.get(f"/admin/orders?id={noid}&saved={code}")
    m = re.search(r'role="status"><span class="small">([^<]*)', html)
    return (m.group(1) if m else ""), html


msg, _ = notice_for("restored-2")
check("a restock is reported with its count", "2 back in stock" in msg, msg)

msg, _ = notice_for("gone")
check("a dispatched parcel is reported as not restored",
      "nothing was added back" in msg, msg)

msg, _ = notice_for("dispatched")
check("the dispatch email is finally mentioned", "dispatch email" in msg, msg)

# ?saved= is a query parameter, so the message is rebuilt from a whitelist.
msg, html = notice_for("restored-999999")
check("a number from the url cannot exceed what the order held",
      msg.strip() == "Saved. 2 back in stock.", msg)

msg, html = notice_for(urllib.parse.quote("<script>alert(1)</script>"))
check("an unknown code falls back to plain Saved", msg.strip() == "Saved.", msg)
check("...with nothing injected into the page", "script>alert" not in html)

sql(f"DELETE FROM orders WHERE id={noid}")

print("\n--- the order link outlives the payment keys ---")

# order_token() signs the ?t= in every receipt email, so a guest can open
# their own order without an account. It used to be keyed on
# razorpay.key_secret -- a value that is MEANT to change: switching from test
# keys to live ones is a step in DEPLOY.md, and a leaked key has to be
# rotated. Either killed every link already sent, and the customer found out
# by clicking it.
REPO = REPO_FOR_STOCK

r = php("require 'inc/bootstrap.php'; require_once 'inc/orders.php';"
        "$o = ['id' => 7, 'reference' => 'GS-2026-0007',"
        "      'created_at' => '2026-01-01 00:00:00'];"
        "echo order_token($o), '|',"
        # the same token recomputed from each candidate key, so the test can
        # say WHICH one it is derived from rather than just that it changed
        "substr(hash_hmac('sha256', 'order:7:GS-2026-0007:2026-01-01 00:00:00',"
        "  (string) cfg('razorpay.key_secret')), 0, 32), '|',"
        "substr(hash_hmac('sha256', 'order:7:GS-2026-0007:2026-01-01 00:00:00',"
        "  (string) (cfg('app.secret') ?: cfg('db.pass'))), 0, 32), '|',"
        "(int) (cfg('razorpay.key_secret') !== '');")
parts = r.stdout.split("|")
check("the token probe ran", len(parts) == 4, r.stderr[:200] or r.stdout)

if len(parts) == 4:
    actual, from_rzp, from_app, rzp_set = parts
    check("the dev config does have a Razorpay secret set", rzp_set == "1", rzp_set)
    check("the order token is NOT derived from it", actual != from_rzp, actual)
    check("it is derived from app.secret", actual == from_app, actual + " vs " + from_app)
    check("it is 128 bits of hex", len(actual) == 32 and all(
        c in "0123456789abcdef" for c in actual), actual)

# config.example.php has to carry the key, or a fresh install silently falls
# back to the database password and nobody ever knows there was a choice.
example = open(REPO + r"\inc\config.example.php", encoding="utf-8").read()
check("config.example.php offers app.secret", "'secret'" in example and "'app'" in example)
check("...and says not to change it", "never change it" in example.lower())

deploy = open(REPO + r"\DEPLOY.md", encoding="utf-8").read()
check("DEPLOY.md says how to generate one", "random_bytes(32)" in deploy)

check("the token is still compared in constant time",
      "hash_equals(order_token(" in open(REPO + r"\inc\orders.php", encoding="utf-8").read())

print("\n--- cleaning up ---")
sql(f"DELETE FROM orders WHERE id={oid}")
# ...and any left by an earlier run. Deleting the test PRODUCT only nulls
# product_id on the item rows; the order itself survived, so they were piling
# up one per run. Five had accumulated before anyone counted them.
sql("DELETE FROM orders WHERE email='pay.test@example.com'")
sql(f"DELETE FROM products WHERE id={pid}")
sql("DELETE FROM payment_events WHERE event_id LIKE 'evt_%'")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
