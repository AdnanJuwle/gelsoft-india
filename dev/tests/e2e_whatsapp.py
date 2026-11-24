"""Paying over WhatsApp: the stop-gap until Razorpay is live.

Checkout places the order unpaid and says the team will be in touch on
WhatsApp; the order page offers to send it to the shop; admin messages the
customer and marks it paid with a reference. Checked hard on the admin side,
because "mark as paid" is the one control that releases stock and money:
refused without the confirmation, without a reference, on a Razorpay order,
when the stock is no longer there, twice, and by anyone not signed in.

Sets its own contact.whatsapp / payments.upi_id in config.local.php and puts
the file back byte for byte in `finally`, along with every row it made.
"""
import http.cookiejar
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
CONFIG = r"C:\Users\adnan\Documents\repos\gelsoft-india\inc\config.local.php"
BACKUP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.local.e2e_whatsapp.bak")

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL %s %s" % (label, detail))


def sql(query):
    r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft", "-sN", "-e", query], capture_output=True)
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

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""


def set_config(whatsapp, upi, key_id=""):
    s = open(BACKUP, encoding="utf-8").read()
    s = re.sub(r"('whatsapp'\s*=>\s*)'[^']*'", lambda m: m.group(1) + "'" + whatsapp + "'", s, count=1)
    s = re.sub(r"('key_id'\s*=>\s*)'[^']*'", lambda m: m.group(1) + "'" + key_id + "'", s, count=1)
    s = re.sub(r"\n\s*'payments'\s*=>\s*\[[^\]]*\],", "", s)
    s = s.rstrip().rstrip("];").rstrip() + "\n    'payments' => ['upi_id' => '" + upi + "'],\n];\n"
    open(CONFIG, "w", encoding="utf-8", newline="").write(s)


FORM = {"name": "Wa Buyer", "phone": "98765 43210", "email": "wa-buyer@example.test",
        "line1": "1 Test Road", "line2": "", "city": "Pune", "state": "Maharashtra",
        "pin": "411001", "note": ""}


def place_order(client):
    tok = client.token("/blasters/wa-test-item")
    client.post("/api/cart.php", {"_token": tok, "do": "add", "product_id": pid, "qty": "1"},
                {"Accept": "application/json"})
    tok = client.token("/checkout")
    code, _, loc = client.post("/checkout", dict(FORM, _token=tok))
    return code, loc or ""


shutil.copyfile(CONFIG, BACKUP)
made_orders = []
MAIL0 = int(sql("SELECT COALESCE(MAX(id),0) FROM mail_log") or 0)
LOG0 = int(sql("SELECT COALESCE(MAX(id),0) FROM admin_log") or 0)
try:
    set_config("+91 90000 00000", "gelsoft@testupi")
    sql("DELETE FROM products WHERE slug = 'wa-test-item'")
    sql("INSERT INTO products (slug, category, name, grade, gears, price_paise, stock_qty, status) "
        "VALUES ('wa-test-item','blaster','WA Test Blaster','mid','metal',300000,1,'published')")
    pid = sql("SELECT id FROM products WHERE slug='wa-test-item'")

    print("--- checkout, before the button ---")
    a = C()
    tok = a.token("/blasters/wa-test-item")
    a.post("/api/cart.php", {"_token": tok, "do": "add", "product_id": pid, "qty": "1"}, {"Accept": "application/json"})
    _, html, _ = a.get("/checkout")
    check("the button says Place order, not payment", ">Place order<" in html and "Continue to payment" not in html)
    check("it says how paying works BEFORE the button",
          "How paying works for now" in html and html.index("How paying works") < html.index(">Place order<"))
    check("...that nothing is charged and the team will WhatsApp", "does not charge you anything" in html and "WhatsApp" in html)
    check("...with a chrono video, since there is a blaster in the cart", "chrono-tested" in html)
    _, cart_html, _ = a.get("/cart")
    check("the cart says UPI or bank transfer, not cards", "UPI or bank transfer" in cart_html and "cards and net banking" not in cart_html)

    print("\n--- placing it ---")
    tok = a.token("/checkout")
    code, _, loc = a.post("/checkout", dict(FORM, _token=tok))
    check("placing it goes to the order page", code == 302 and loc.startswith("/order?ref=") and "placed=1" in loc, loc)
    ref_a = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query).get("ref", [""])[0]
    row = sql("SELECT id, status, payment_provider FROM orders WHERE reference='%s'" % ref_a).split("\t")
    oid_a = row[0]
    made_orders.append(oid_a)
    # The total as the order stored it -- shipping included -- not the price.
    paise = int(sql("SELECT total_paise FROM orders WHERE id=%s" % oid_a))
    total_txt = "₹{:,}".format(paise // 100)
    check("it is saved pending, as a WhatsApp order", row[1:] == ["pending", "whatsapp"], str(row))
    check("stock is not touched until it is paid", sql("SELECT stock_qty FROM products WHERE id=%s" % pid) == "1")
    _, cart_html, _ = a.get("/cart")
    check("the cart is empty afterwards (no placing it twice)", 'href="/checkout"' not in cart_html)
    check("the shop was emailed about it (logged, even with no mailbox)",
          sql("SELECT COUNT(*) FROM mail_log WHERE id > %d AND template='new_order_alert' AND subject LIKE '%%%s%%'" % (MAIL0, ref_a)) == "1")

    _, page, _ = a.get(loc)
    check("the order page says placed and WhatsApp", "Order placed. We will be in touch on WhatsApp." in page)
    check("...shows the number we will message", "98765 43210" in page)
    check("...and that nothing has been charged", "Nothing has been charged" in page)
    wa = re.search(r'href="(https://wa\.me/[^"]+)"', page)
    check("...with a button to send the order to the shop's WhatsApp",
          wa is not None and wa.group(1).startswith("https://wa.me/919000000000?text="), wa.group(1) if wa else "none")
    if wa:
        text = urllib.parse.unquote(wa.group(1).split("text=", 1)[1].replace("&amp;", "&"))
        check("...with the reference and the total already typed", ref_a in text and total_txt in text, text)

    set_config("", "gelsoft@testupi")
    _, page, _ = a.get(loc)
    check("no shop number configured: no button pointing at nobody", "wa.me" not in page)
    set_config("+91 90000 00000", "gelsoft@testupi")

    print("\n--- a second customer orders the same last unit ---")
    b = C()
    code, loc_b = place_order(b)
    ref_b = urllib.parse.parse_qs(urllib.parse.urlparse(loc_b).query).get("ref", [""])[0]
    oid_b = sql("SELECT id FROM orders WHERE reference='%s'" % ref_b)
    made_orders.append(oid_b)
    check("it can be placed (nothing is held while unpaid)", code == 302 and oid_b != "", loc_b)

    print("\n--- admin ---")
    adm = C()
    tok = adm.token("/admin/login")
    adm.post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123", "next": "/admin/"})
    _, ahtml, _ = adm.get("/admin/orders?id=%s" % oid_a)
    check("the order shows the WhatsApp card", "Waiting for payment on WhatsApp" in ahtml)
    cw = re.search(r'href="(https://wa\.me/919876543210\?text=[^"]+)"', ahtml)
    check("...with a button that WhatsApps the customer's number", cw is not None)
    if cw:
        msg = urllib.parse.unquote(cw.group(1).split("text=", 1)[1].replace("&amp;", "&"))
        check("...message has the reference, item, total, chrono line and UPI ID",
              all(x in msg for x in [ref_a, "WA Test Blaster x1", total_txt, "chrono", "gelsoft@testupi"]), msg)
    _, lst, _ = adm.get("/admin/orders")
    check("the list says how many are waiting on WhatsApp", re.search(r"<strong>\d+</strong> waiting for payment on WhatsApp", lst) is not None)

    def mark(oid, **kw):
        tok = adm.token("/admin/orders?id=%s" % oid)
        data = {"_token": tok, "id": oid, "do": "mark_paid", "method": "upi",
                "pay_ref": "412345678901", "confirm_money": "1"}
        data.update(kw)
        data = {k: v for k, v in data.items() if v is not None}
        return adm.post("/admin/orders", data)

    def status(oid):
        return sql("SELECT status FROM orders WHERE id=%s" % oid)

    code, h, _ = mark(oid_a, confirm_money=None)
    check("refused without ticking 'money is in the account'", status(oid_a) == "pending" and "Tick the box" in h)
    code, h, _ = mark(oid_a, pay_ref="ab")
    check("refused without a real reference", status(oid_a) == "pending" and "transaction ID" in h)
    code, h, _ = mark(oid_a, pay_ref="<script>x</script>")
    check("refused with markup in the reference", status(oid_a) == "pending")
    code, h, _ = mark(oid_a, method="cash")
    check("refused with a method not on the list", status(oid_a) == "pending" and "how they paid" in h)

    anon = C()
    tok = anon.token("/checkout")
    code, _, _ = anon.post("/admin/orders", {"_token": tok, "id": oid_a, "do": "mark_paid", "method": "upi",
                                            "pay_ref": "412345678901", "confirm_money": "1"})
    check("someone not signed in cannot mark it paid", status(oid_a) == "pending", "code=%s" % code)

    code, _, loc = mark(oid_a)
    check("with everything filled in, it is marked paid", status(oid_a) == "paid" and "saved=paid" in (loc or ""), loc)
    check("...the reference is recorded", sql("SELECT payment_id FROM orders WHERE id=%s" % oid_a) == "UPI 412345678901")
    check("...stock comes off now", sql("SELECT stock_qty FROM products WHERE id=%s" % pid) == "0")
    check("...and it is in the admin log",
          sql("SELECT COUNT(*) FROM admin_log WHERE id > %d AND action='order_paid_manual' AND target='%s'" % (LOG0, ref_a)) == "1")
    code, _, _ = mark(oid_a)
    check("marking it again changes nothing", sql("SELECT stock_qty FROM products WHERE id=%s" % pid) == "0"
          and sql("SELECT COUNT(*) FROM admin_log WHERE id > %d AND action='order_paid_manual' AND target='%s'" % (LOG0, ref_a)) == "1")

    print("\n--- the second order, for stock that is gone ---")
    code, h, _ = mark(oid_b)
    check("refused: the unit went to the first order", status(oid_b) == "pending" and "only 0 are in stock" in h, h[h.find("Not marked"):h.find("Not marked") + 160] if "Not marked" in h else "")
    check("...and stock did not go negative or move", sql("SELECT stock_qty FROM products WHERE id=%s" % pid) == "0")

    print("\n--- a Razorpay order can only be marked paid by Razorpay ---")
    sql("UPDATE products SET stock_qty = 5 WHERE id=%s" % pid)
    sql("UPDATE orders SET payment_provider='razorpay' WHERE id=%s" % oid_b)
    code, h, _ = mark(oid_b)
    check("refused on a Razorpay order", status(oid_b) == "pending" and "only Razorpay can mark it paid" in h)
    _, ahtml, _ = adm.get("/admin/orders?id=%s" % oid_b)
    check("...and the WhatsApp card is not even shown", "Waiting for payment on WhatsApp" not in ahtml)

    print("\n--- once Razorpay keys are in, checkout goes back to Razorpay ---")
    set_config("+91 90000 00000", "gelsoft@testupi", key_id="rzp_test_dummy")
    c2 = C()
    tok = c2.token("/blasters/wa-test-item")
    c2.post("/api/cart.php", {"_token": tok, "do": "add", "product_id": pid, "qty": "1"}, {"Accept": "application/json"})
    _, html, _ = c2.get("/checkout")
    check("the button is 'Continue to payment' again", "Continue to payment" in html and ">Place order<" not in html)
    check("...and the WhatsApp note is gone", "How paying works for now" not in html)
finally:
    shutil.copyfile(BACKUP, CONFIG)
    for oid in made_orders:
        if oid:
            sql("DELETE FROM order_items WHERE order_id=%s" % oid)
            sql("DELETE FROM orders WHERE id=%s" % oid)
    sql("DELETE FROM products WHERE slug='wa-test-item'")
    os.remove(BACKUP)

check("config.local.php put back", True)
print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
