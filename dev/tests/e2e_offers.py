"""Offers a seller can answer: accept, decline, expire, and what they buy."""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

SELLER_E, SELLER_PW = "off.seller@example.com", "seller long pass phrase"
BUYER_E, BUYER_PW = "off.buyer@example.com", "buyer long pass phrase"
OTHER_E, OTHER_PW = "off.other@example.com", "other long pass phrase"

ASK = 820000          # the listing's asking price, in paise
OFFER = 750000        # what the buyer offers


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
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
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


def register(email, pw, name):
    sql(f"DELETE FROM users WHERE email='{email}'")
    c = C()
    t = c.token("/register")
    c.post("/register", {"_token": t, "name": name, "email": email, "password": pw,
                         "next": "/account"})
    # Registering does not sign you in; it redirects to /login?registered=1.
    t = c.token("/login")
    c.post("/login", {"_token": t, "email": email, "password": pw, "next": "/account"})
    return c


def cleanup():
    sql("DELETE m FROM messages m JOIN threads t ON t.id = m.thread_id "
        "JOIN listings l ON l.id = t.listing_id WHERE l.slug = 'offer-test'")
    sql("DELETE t FROM threads t JOIN listings l ON l.id = t.listing_id "
        "WHERE l.slug = 'offer-test'")
    sql("DELETE lo FROM listing_orders lo JOIN listings l ON l.id = lo.listing_id "
        "WHERE l.slug = 'offer-test'")
    sql("DELETE FROM listings WHERE slug = 'offer-test'")
    sql(f"DELETE FROM users WHERE email IN ('{SELLER_E}','{BUYER_E}','{OTHER_E}')")


print("--- fixtures ---")
cleanup()
seller = register(SELLER_E, SELLER_PW, "Offer Seller")
buyer = register(BUYER_E, BUYER_PW, "Offer Buyer")
other = register(OTHER_E, OTHER_PW, "Nosy Other")

sid = sql(f"SELECT id FROM users WHERE email='{SELLER_E}'")
bid = sql(f"SELECT id FROM users WHERE email='{BUYER_E}'")
oid = sql(f"SELECT id FROM users WHERE email='{OTHER_E}'")

sql(f"INSERT INTO listings (slug,seller_id,status,title,price_paise,postage_paise,item_condition) "
    f"VALUES ('offer-test',{sid},'live','Offer Test Blaster',{ASK},0,'good')")
lid = sql("SELECT id FROM listings WHERE slug='offer-test'")
check("the listing is live", lid != "")

t = buyer.token(f"/listing?slug=offer-test")
code, html, loc = buyer.post("/listing/message", {"_token": t, "listing_id": lid})
tid = (loc or "").split("thread=")[-1]
check("the buyer opens a conversation", tid.isdigit(), f"{code} {loc}")

print("\n--- making an offer ---")
t = buyer.token(f"/messages?thread={tid}")
buyer.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer",
                         "offer": str(OFFER // 100), "body": ""})
off = sql(f"SELECT id FROM messages WHERE thread_id={tid} AND kind='offer'")
check("the offer is recorded", off != "")
check("...as pending", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "pending")
check("...with no expiry yet", sql(f"SELECT offer_expires_at IS NULL FROM messages WHERE id={off}") == "1")

print("\n--- only the seller may answer it ---")
t = buyer.token(f"/messages?thread={tid}")
code, html, _ = buyer.post("/messages", {"_token": t, "thread_id": tid,
                                         "offer_id": off, "decision": "accept"})
check("the buyer cannot accept their own offer", "Only the seller" in html, html[:160])
check("...and it is untouched", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "pending")

t = other.token("/messages")
code, html, _ = other.post("/messages", {"_token": t, "thread_id": tid,
                                         "offer_id": off, "decision": "accept"})
check("a stranger cannot accept it", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "pending")

print("\n--- the seller sees it ---")
code, html, _ = seller.get(f"/messages?thread={tid}")
check("the seller is shown the offer", "An offer to answer" in html)
check("...with the amount", "7,500" in html)
check("...and both buttons", 'value="accept"' in html and 'value="decline"' in html)
check("...and is told it does not reserve the item",
      "does not reserve" in html, "")
code, html, _ = buyer.get(f"/messages?thread={tid}")
check("the buyer is not shown the buttons", 'value="accept"' not in html)

print("\n--- accepting ---")
t = seller.token(f"/messages?thread={tid}")
code, html, loc = seller.post("/messages", {"_token": t, "thread_id": tid,
                                            "offer_id": off, "decision": "accept"})
check("accepting redirects back to the thread", code == 302 and f"thread={tid}" in (loc or ""),
      f"{code} {loc}")
check("...the offer is accepted", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "accepted")
# TIMESTAMPDIFF truncates, so a hold set 48h from now reads 47 or 48
# depending on whether the clock has ticked past the second. Both are the
# same hold; pinning it to one number fails at random.
_held = sql(f"SELECT TIMESTAMPDIFF(HOUR, NOW(), offer_expires_at) FROM messages WHERE id={off}")
check("...with a 48 hour hold", _held in ("47", "48"), f"got {_held}h")
check("...recorded against the seller",
      sql(f"SELECT offer_decided_by FROM messages WHERE id={off}") == sid)
check("...and written into the conversation",
      sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid} AND kind='offer_accepted'") == "1")

code, html, _ = seller.get(f"/messages?thread={tid}")
check("the seller no longer sees the buttons", 'value="accept"' not in html)
check("...and the thread says what happened", "accepted the offer" in html)

print("\n--- it cannot be answered twice ---")
t = seller.token(f"/messages?thread={tid}")
code, html, _ = seller.post("/messages", {"_token": t, "thread_id": tid,
                                          "offer_id": off, "decision": "decline"})
check("declining an accepted offer is refused", "already accepted" in html, html[:200])
check("...and it is still accepted", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "accepted")

print("\n--- what the buyer now sees ---")
code, html, _ = buyer.get(f"/messages?thread={tid}")
check("the buyer is told", "Your offer was accepted" in html)
check("...and given a way to buy", f"/buy?listing={lid}" in html)

code, html, _ = buyer.get("/listing?slug=offer-test")
check("the listing page says so too", "Your offer was accepted" in html)
code, html, _ = other.get("/listing?slug=offer-test")
check("...but only to that buyer", "Your offer was accepted" not in html)

print("\n--- the price that is actually charged ---")
code, html, _ = buyer.get(f"/buy?listing={lid}")
check("the buy page opens", code == 200, f"code={code}")
check("...at the agreed price", "7,500" in html)
check("...and says why", "Your accepted offer" in html)

code, html, _ = other.get(f"/buy?listing={lid}")
check("another buyer still sees the asking price", "8,200" in html)
check("...with no accepted-offer note", "Your accepted offer" not in html)

print("\n--- buying at the agreed price ---")
t = buyer.token(f"/buy?listing={lid}")
code, html, _ = buyer.post("/buy", {
    "_token": t, "listing_id": lid, "confirm": "1",
    "name": "Offer Buyer", "phone": "9000000011", "line1": "1 Test Road",
    "city": "Pune", "state": "Maharashtra", "pin": "411001"})
paid = sql(f"SELECT price_paise FROM listing_orders WHERE listing_id={lid}")
check("the order is created", paid != "", html[:200])
check("...at the OFFER price, not the asking price", paid == str(OFFER), f"got {paid}")
check("...and the offer is spent", sql(f"SELECT offer_status FROM messages WHERE id={off}") == "used")

seller_payout = sql(f"SELECT payout_paise FROM listing_orders WHERE listing_id={lid}")
# 7500 - 8% (600) - 2% (150) - 3 = 6747
check("...the seller's payout is worked out from the agreed price",
      seller_payout == "674700", f"got {seller_payout}")

print("\n--- a spent offer cannot be spent again ---")
sql(f"UPDATE listings SET status='live' WHERE id={lid}")
sql(f"DELETE FROM listing_orders WHERE listing_id={lid}")
t = buyer.token(f"/buy?listing={lid}")
code, html, _ = buyer.get(f"/buy?listing={lid}")
check("the buy page is back to the asking price", "8,200" in html and "Your accepted offer" not in html)

print("\n--- declining ---")
t = buyer.token(f"/messages?thread={tid}")
buyer.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer",
                         "offer": "7000", "body": ""})
off2 = sql(f"SELECT id FROM messages WHERE thread_id={tid} AND kind='offer' "
           f"AND offer_status='pending'")
check("a second offer can be made", off2 != "" and off2 != off)

t = seller.token(f"/messages?thread={tid}")
code, html, loc = seller.post("/messages", {"_token": t, "thread_id": tid,
                                            "offer_id": off2, "decision": "decline"})
check("declining works", code == 302, f"{code}")
check("...it is declined", sql(f"SELECT offer_status FROM messages WHERE id={off2}") == "declined")
check("...with no expiry set", sql(f"SELECT offer_expires_at IS NULL FROM messages WHERE id={off2}") == "1")
check("...and it is in the conversation",
      sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid} AND kind='offer_declined'") == "1")
code, html, _ = buyer.get(f"/buy?listing={lid}")
check("a declined offer does not change the price", "8,200" in html)

print("\n--- a newer offer replaces the one before it ---")
t = buyer.token(f"/messages?thread={tid}")
buyer.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer", "offer": "7100", "body": ""})
t = buyer.token(f"/messages?thread={tid}")
buyer.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer", "offer": "7200", "body": ""})
pend = sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid} AND kind='offer' "
           f"AND offer_status='pending'")
check("only one offer is left waiting", pend == "1", f"got {pend}")
check("...the older one is marked superseded",
      sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid} AND offer_status='superseded'") == "1")
newest = sql(f"SELECT offer_paise FROM messages WHERE thread_id={tid} AND kind='offer' "
             f"AND offer_status='pending'")
check("...and it is the newest", newest == "720000", f"got {newest}")

print("\n--- an expired offer buys nothing ---")
off3 = sql(f"SELECT id FROM messages WHERE thread_id={tid} AND kind='offer' AND offer_status='pending'")
t = seller.token(f"/messages?thread={tid}")
seller.post("/messages", {"_token": t, "thread_id": tid, "offer_id": off3, "decision": "accept"})
check("the newest offer is accepted", sql(f"SELECT offer_status FROM messages WHERE id={off3}") == "accepted")
code, html, _ = buyer.get(f"/buy?listing={lid}")
check("...and the buy page honours it", "7,200" in html)

sql(f"UPDATE messages SET offer_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id={off3}")
code, html, _ = buyer.get(f"/buy?listing={lid}")
check("once the hold runs out the price is back to the asking price",
      "8,200" in html and "Your accepted offer" not in html)

code, html, _ = buyer.get(f"/messages?thread={tid}")
check("...and the thread stops claiming it is accepted", "Your offer was accepted" not in html)
check("...it is relabelled expired", sql(f"SELECT offer_status FROM messages WHERE id={off3}") == "expired")

print("\n--- an offer on a listing that is no longer for sale ---")
t = buyer.token(f"/messages?thread={tid}")
buyer.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer", "offer": "7300", "body": ""})
off4 = sql(f"SELECT id FROM messages WHERE thread_id={tid} AND kind='offer' AND offer_status='pending'")
sql(f"UPDATE listings SET status='withdrawn' WHERE id={lid}")
t = seller.token(f"/messages?thread={tid}")
code, html, _ = seller.post("/messages", {"_token": t, "thread_id": tid,
                                          "offer_id": off4, "decision": "accept"})
check("accepting on a withdrawn listing is refused", "not on the marketplace" in html, html[:200])
check("...and it stays pending", sql(f"SELECT offer_status FROM messages WHERE id={off4}") == "pending")
sql(f"UPDATE listings SET status='live' WHERE id={lid}")

print("\n--- the inbox ---")
code, html, _ = seller.get("/messages")
check("an offer shows its amount in the list", "offered" in html and "7,300" in html, "")

print("\n--- cleaning up ---")
cleanup()

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
