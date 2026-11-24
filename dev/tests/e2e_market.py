"""Phase 7: list it, moderate it, buy it, hold the money, release it."""
import hashlib
import hmac
import http.cookiejar
import io
import json
import re
import struct
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
WEBHOOK_SECRET = "devwebhook_local_only"


def sql(q):
    r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft", "-sN", "-e", q],
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

    def post(self, p, d):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d, doseq=True).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(r)

    def post_multipart(self, p, fields, files):
        boundary = uuid.uuid4().hex
        body = io.BytesIO()
        for k, v in fields:
            body.write(f"--{boundary}\r\n".encode())
            body.write(f'Content-Disposition: form-data; name="{k}"\r\n\r\n'.encode())
            body.write(str(v).encode() + b"\r\n")
        for k, name, data, ctype in files:
            body.write(f"--{boundary}\r\n".encode())
            body.write(f'Content-Disposition: form-data; name="{k}"; filename="{name}"\r\n'.encode())
            body.write(f"Content-Type: {ctype}\r\n\r\n".encode())
            body.write(data + b"\r\n")
        body.write(f"--{boundary}--\r\n".encode())
        req = urllib.request.Request(BASE + p, data=body.getvalue(), method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        return self._do(req)

    def post_raw(self, p, body, headers):
        r = urllib.request.Request(BASE + p, data=body, method="POST")
        r.add_header("Content-Type", "application/json")
        for k, v in headers.items():
            r.add_header(k, v)
        return self._do(r)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""

    def login(self, email, pw):
        t = self.token("/login")
        return self.post("/login", {"_token": t, "email": email, "password": pw, "next": "/account"})


def png(w=600, h=450, rgb=(60, 70, 80)):
    raw = b"".join(b"\x00" + bytes(rgb) * w for _ in range(h))

    def chunk(tag, data):
        c = tag + data
        return struct.pack(">I", len(data)) + c + struct.pack(">I", zlib.crc32(c) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw, 6)) + chunk(b"IEND", b""))


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


# ---- accounts -------------------------------------------------------------
for email, name in [("mk.seller@example.com", "Mk Seller"), ("mk.buyer@example.com", "Mk Buyer")]:
    c = C()
    t = c.token("/register")
    c.post("/register", {"_token": t, "name": name, "email": email,
                         "password": "marsh thicket vellum", "next": "/account"})

seller = C(); seller.login("mk.seller@example.com", "marsh thicket vellum")
buyer = C();  buyer.login("mk.buyer@example.com", "marsh thicket vellum")
admin = C()
at = admin.token("/admin/login")
admin.post("/admin/login", {"_token": at, "email": "admin@gelsoft.in",
                            "password": "devpassword123", "next": "/admin/"})

print("--- listing something ---")
code, html, _ = seller.get("/sell")
check("the sell form opens for a signed-in user", code == 200 and 'name="title"' in html)

anon = C()
code, _, loc = anon.get("/sell")
check("it needs a sign-in", code == 302 and "/login" in (loc or ""))

base = [("category", "blaster"), ("brand", "TG"), ("grade", "mid"), ("gears", "metal"),
        ("gearbox", "Gen 8"), ("fps", "238"), ("bought_when", "March 2025"),
        ("item_condition", "good"), ("included", "3 mags, battery"),
        ("faults", "Scuff on the stock"), ("price", "8200"), ("postage", "180"),
        ("handover", "courier"), ("city", "Pune"), ("state", "Maharashtra"),
        ("accepts_offers", "1"), ("allow_inspection", "1"),
        ("description", "Used for one season, runs perfectly, gearbox opened once for a regrease.")]

t = seller.token("/sell")
code, html, _ = seller.post_multipart("/sell", [("_token", t), ("id", "0"), ("action", "save"),
                                                ("title", "No rules ticked")] + base, [])
check("the four rules are enforced", "Confirm that it is yours" in html)

rules = [("own_it", "1"), ("declared", "1"), ("not_realistic", "1"), ("no_eyepro", "1")]

t = seller.token("/sell")
code, html, _ = seller.post_multipart("/sell", [("_token", t), ("id", "0"), ("action", "save"),
                                                ("title", "No photo here")] + base + rules, [])
check("a listing with no photo is refused", "at least one photo" in html)

t = seller.token("/sell")
code, html, loc = seller.post_multipart(
    "/sell",
    [("_token", t), ("id", "0"), ("action", "save"), ("title", "Market Suite Test Blaster")] + base + rules,
    [("photos[]", "gun.png", png(), "image/png")])
check("a complete listing saves", code == 302 and "sent=1" in (loc or ""), f"code={code} {html[:200]}")

lid = sql("SELECT id FROM listings WHERE title='Market Suite Test Blaster' ORDER BY id DESC LIMIT 1")
slug = sql(f"SELECT slug FROM listings WHERE id={lid}")
status = sql(f"SELECT status FROM listings WHERE id={lid}")
check("it goes to pending, not straight live", status == "pending", status)

print("\n--- the photo cap ---")

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"


def photos(listing_id):
    return int(sql(f"SELECT COUNT(*) FROM listing_images WHERE listing_id={listing_id}") or 0)


check("the listing has its one photo", photos(lid) == 1, str(photos(lid)))

# Eight more at once. Five is the cap, one is already there, so four land.
t = seller.token("/sell")
code, html, loc = seller.post_multipart(
    "/sell",
    [("_token", t), ("id", lid), ("action", "save"), ("title", "Market Suite Test Blaster")] + base + rules,
    [("photos[]", f"extra{i}.png", png(), "image/png") for i in range(8)])
check("the cap stops at five", photos(lid) == 5, f"{photos(lid)} photos")
check("and it says why", "holds 5 photos" in html, html[:160])

# The important half: sending the form AGAIN must not add another batch.
t = seller.token("/sell")
code, html, loc = seller.post_multipart(
    "/sell",
    [("_token", t), ("id", lid), ("action", "save"), ("title", "Market Suite Test Blaster")] + base + rules,
    [("photos[]", "sneak.png", png(), "image/png")])
check("resubmitting cannot add a sixth", photos(lid) == 5, f"{photos(lid)} photos")

# A listing photo must be re-encoded, not stored as sent.
import os
p = sql(f"SELECT path FROM listing_images WHERE listing_id={lid} LIMIT 1")
check("the photo was re-encoded to webp", p.endswith(".webp"), p)
check("...and the file is on disk", os.path.isfile(os.path.join(REPO, *p.split("/"))), p)

code, html, _ = anon.get("/marketplace")
check("a pending listing is not on the marketplace", "Market Suite Test Blaster" not in html)

code, html, _ = anon.get(f"/marketplace/{slug}")
check("a stranger cannot open it either", code == 404, f"code={code}")

code, html, _ = seller.get(f"/marketplace/{slug}")
check("the seller can see their own", code == 200 and "Waiting for review" in html)

print("\n--- moderation ---")
code, html, _ = admin.get("/admin/marketplace")
check("it is in the admin queue", code == 200 and "Market Suite Test Blaster" in html)

t = admin.token("/admin/marketplace")
code, html, _ = admin.post("/admin/marketplace", {"_token": t, "action": "reject", "id": lid, "note": ""})
check("sending one back needs a reason", "Say why" in html)

t = admin.token("/admin/marketplace")
code, html, loc = admin.post("/admin/marketplace", {"_token": t, "action": "reject", "id": lid,
                                                    "note": "The second photo is a stock image."})
check("sending it back works", code == 302, f"code={code}")
check("...and the reason is stored",
      sql(f"SELECT reject_note FROM listings WHERE id={lid}") == "The second photo is a stock image.")

code, html, _ = seller.get(f"/sell?edit={lid}")
check("the seller is told why", "stock image" in html)

t = seller.token(f"/sell?edit={lid}")
code, html, loc = seller.post_multipart(
    "/sell", [("_token", t), ("id", lid), ("action", "save"),
              ("title", "Market Suite Test Blaster")] + base + rules, [])
check("fixing it puts it back in the queue",
      sql(f"SELECT status FROM listings WHERE id={lid}") == "pending")

# Nothing goes live behind an unverified seller, so the checks come first --
# and the refusal is worth asserting, because it is the gate.
sid_seller = sql(f"SELECT seller_id FROM listings WHERE id={lid}")
sql(f"DELETE FROM seller_checks WHERE user_id={sid_seller}")
sql(f"UPDATE users SET seller_verified_at = NULL WHERE id={sid_seller}")

t = admin.token("/admin/marketplace")
code, html, loc = admin.post("/admin/marketplace", {"_token": t, "action": "approve", "id": lid})
check("an unverified seller cannot be approved", "not verified yet" in html, html[:160])
check("...so it stays pending", sql(f"SELECT status FROM listings WHERE id={lid}") == "pending")

for kind, note in (("phone", "answered"), ("payout", "UPI name matches")):
    t = admin.token("/admin/marketplace")
    admin.post("/admin/marketplace", {"_token": t, "action": "seller_check",
                                      "id": sid_seller, "kind": kind, "note": note})
check("the two checks verify the seller",
      sql(f"SELECT seller_verified_at IS NOT NULL FROM users WHERE id={sid_seller}") == "1")

t = admin.token("/admin/marketplace")
code, html, loc = admin.post("/admin/marketplace", {"_token": t, "action": "approve", "id": lid})
check("approving puts it live", sql(f"SELECT status FROM listings WHERE id={lid}") == "live")

code, html, _ = anon.get("/marketplace")
check("it is on the marketplace now", "Market Suite Test Blaster" in html)

code, html, _ = anon.get(f"/marketplace/{slug}")
check("the listing page opens", code == 200 and "Scuff on the stock" in html)
check("the fees are shown as a buyer option", "Have us inspect it first" in html)

print("\n--- buying it ---")
t = seller.token("/sell")
code, html, _ = seller.post("/buy", {"_token": t, "listing_id": lid, "confirm": "1",
                                     "name": "Mk Seller", "phone": "9876543210",
                                     "line1": "1 Road", "city": "Pune",
                                     "state": "Maharashtra", "pin": "411001"})
check("you cannot buy your own listing", "your own listing" in html, f"code={code}")

t = buyer.token(f"/marketplace/{slug}")
code, html, _ = buyer.post("/buy", {"_token": t, "listing_id": lid, "confirm": "1",
                                    "inspected": "1", "name": "Mk Buyer", "phone": "12345",
                                    "line1": "9 Lane", "city": "Mumbai",
                                    "state": "Maharashtra", "pin": "400001"})
check("a bad phone number is refused", "Indian mobile" in html)

t = buyer.token(f"/marketplace/{slug}")
code, html, loc = buyer.post("/buy", {"_token": t, "listing_id": lid, "confirm": "1",
                                      "inspected": "1", "name": "Mk Buyer", "phone": "9876500000",
                                      "line1": "9 Lane", "city": "Mumbai",
                                      "state": "Maharashtra", "pin": "400001"})
check("buying creates a pending sale", code in (200, 302), f"code={code}")

ref = sql("SELECT reference FROM listing_orders ORDER BY id DESC LIMIT 1")
sid = sql(f"SELECT id FROM listing_orders WHERE reference='{ref}'")
check("the sale has a reference", ref.startswith("MK-"), ref)

row = sql(f"SELECT price_paise, inspection_paise, total_paise, payout_paise, seller_fee_paise, status "
          f"FROM listing_orders WHERE id={sid}").split("\t")
check("the price came from the listing", row[0] == "820000", row)
check("the buyer's inspection fee is 8%", row[1] == "65600", f"{row[1]} (8% of 820000)")
check("the buyer's total adds postage and inspection", row[2] == "903600",
      f"{row[2]} (820000 + 18000 + 65600)")
check("the seller's fee is 8%", row[4] == "65600", row)
check("the seller's payout ignores the inspection", row[3] == "737700",
      f"{row[3]} (820000 - 65600 fee - 16700 handling)")
check("it starts pending", row[5] == "pending", row)
check("the listing is still live until it is paid",
      sql(f"SELECT status FROM listings WHERE id={lid}") == "live")

print("\n--- the webhook holds the money ---")
rzp_order = "order_MK" + sid
sql(f"UPDATE listing_orders SET payment_order_id='{rzp_order}' WHERE id={sid}")


def webhook(event, amount, pid="pay_MK1", eid="evt_mk1"):
    body = json.dumps({"event": event, "payload": {"payment": {"entity": {
        "id": pid, "order_id": rzp_order, "amount": amount}}}}).encode()
    sig = hmac.new(WEBHOOK_SECRET.encode(), body, hashlib.sha256).hexdigest()
    return C().post_raw("/api/webhook.php", body,
                        {"X-Razorpay-Signature": sig, "X-Razorpay-Event-Id": eid})


code, html, _ = C().post_raw("/api/webhook.php", b'{"event":"payment.captured"}',
                             {"X-Razorpay-Signature": "nope", "X-Razorpay-Event-Id": "evt_mkbad"})
check("an unsigned webhook is rejected", code == 401, f"code={code}")

code, html, _ = webhook("payment.captured", 100, pid="pay_SHORT", eid="evt_mkshort")
check("an underpayment does not hold it", sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "pending")

code, html, _ = webhook("payment.captured", 903600)
check("a correct capture holds the money",
      sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "held", code)
check("the listing comes off the market",
      sql(f"SELECT status FROM listings WHERE id={lid}") == "sold")

code, html, _ = webhook("payment.captured", 903600)
check("a repeat delivery changes nothing", "already seen" in html, html[:60])

print("\n--- through the inspection ---")
# The buyer is not offered this, so there is no token on their view of the
# sale — take one from a page they can load, to prove the server refuses it
# on ownership and not only on CSRF.
t = buyer.token("/")
code, html, _ = buyer.post("/sale", {"_token": t, "ref": ref, "to": "shipped"})
check("the buyer cannot say it shipped",
      sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "held",
      sql(f"SELECT status FROM listing_orders WHERE id={sid}"))
check("...and is told why", "not yours to do" in html, html[:160])

t = seller.token(f"/sale?ref={ref}")
code, html, _ = seller.post("/sale", {"_token": t, "ref": ref, "to": "shipped"})
check("the seller cannot skip the inspection", "goes to us first" in html, html[:200])

t = seller.token(f"/sale?ref={ref}")
code, html, loc = seller.post("/sale", {"_token": t, "ref": ref, "to": "to_us"})
check("the seller sends it to us", sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "to_us")

t = admin.token(f"/sale?ref={ref}")
admin.post("/sale", {"_token": t, "ref": ref, "to": "inspecting", "note": "Feeds and fires, 241 FPS."})
check("we put it on the bench", sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "inspecting")

t = admin.token(f"/sale?ref={ref}")
admin.post("/sale", {"_token": t, "ref": ref, "to": "shipped"})
check("we send it on", sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "shipped")

print("\n--- releasing the money ---")
t = admin.token("/admin/marketplace")
code, html, _ = admin.post("/admin/marketplace", {"_token": t, "action": "release", "sale_id": sid})
check("nothing is released before delivery", "Nothing is released until the buyer has it" in html,
      html[:200])

t = buyer.token(f"/sale?ref={ref}")
buyer.post("/sale", {"_token": t, "ref": ref, "to": "delivered"})
check("the buyer confirms it arrived",
      sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "delivered")
check("...which starts the dispute window",
      sql(f"SELECT confirm_by IS NOT NULL FROM listing_orders WHERE id={sid}") == "1")

t = admin.token("/admin/marketplace")
code, html, _ = admin.post("/admin/marketplace", {"_token": t, "action": "release", "sale_id": sid})
check("the window is respected", "still has until" in html, html[:200])

t = admin.token("/admin/marketplace")
code, html, loc = admin.post("/admin/marketplace", {"_token": t, "action": "release",
                                                    "sale_id": sid, "force": "1"})
check("an admin can release deliberately",
      sql(f"SELECT status FROM listing_orders WHERE id={sid}") == "released", f"code={code}")

t = admin.token("/admin/marketplace")
code, html, _ = admin.post("/admin/marketplace", {"_token": t, "action": "release", "sale_id": sid})
check("releasing twice is refused", "already paid out" in html)

print("\n--- who can see what ---")
nosy = C()
tn = nosy.token("/register")
nosy.post("/register", {"_token": tn, "name": "Nosy Mk", "email": "mk.nosy@example.com",
                        "password": "copper kettle moss", "next": "/account"})
nosy.login("mk.nosy@example.com", "copper kettle moss")
code, html, _ = nosy.get(f"/sale?ref={ref}")
check("a stranger cannot read a sale", code == 404, f"code={code}")

code, html, _ = nosy.get("/admin/marketplace")
check("a stranger cannot moderate", code in (302, 403), f"code={code}")

code, html, _ = seller.get("/account")
check("the seller sees it in their account", "Market Suite Test Blaster" in html)
check("...with the payout, not the buyer's total", "7,377" in html, "")

print("\n--- cleaning up ---")
sql(f"DELETE FROM listing_orders WHERE listing_id = {lid}")
sql(f"DELETE FROM listings WHERE id = {lid}")
sql("DELETE FROM payment_events WHERE event_id LIKE 'evt_mk%'")
sql("DELETE FROM users WHERE email LIKE 'mk.%@example.com'")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
