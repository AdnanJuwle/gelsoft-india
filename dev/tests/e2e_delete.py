"""Removing things: products, games, listings — and what must refuse to go."""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
ADMIN, ADMIN_PW = "admin@gelsoft.in", "devpassword123"


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


adm = C()
t = adm.token("/admin/login")
adm.post("/admin/login", {"_token": t, "email": ADMIN, "password": ADMIN_PW})
code, _, _ = adm.get("/admin/")
if code != 200:
    print("could not sign in as admin")
    sys.exit(1)


def act(path, fields):
    t = adm.token(path)
    return adm.post(path, {"_token": t, **fields})


print("--- a product ---")
sql("DELETE FROM products WHERE slug='del-test'")
sql("INSERT INTO products (slug,name,category,price_paise,status) "
    "VALUES ('del-test','Delete Test','blaster',250000,'draft')")
pid = sql("SELECT id FROM products WHERE slug='del-test'")
check("the fixture exists", pid != "")

code, html, _ = adm.get("/admin/")
check("the dashboard offers Delete on a product row", f'value="{pid}"' in html and "delete_item" in html)

code, html, loc = act("/admin/add-item", {"action": "delete_item", "id": pid})
check("deleting from the dashboard works", code == 302 and "deleted=1" in (loc or ""), f"{code} {loc}")
check("...the product is gone", sql("SELECT COUNT(*) FROM products WHERE slug='del-test'") == "0")

print("\n--- a game ---")
sql("DELETE FROM games WHERE title='Delete Test Game'")
sql("INSERT INTO games (host_name,contact,title,city,starts_at,status) "
    "VALUES ('Host','h@example.com','Delete Test Game','Pune',"
    "DATE_ADD(NOW(), INTERVAL 20 DAY),'live')")
gid = sql("SELECT id FROM games WHERE title='Delete Test Game'")
check("the fixture is on the board", gid != "")

code, html, _ = adm.get("/admin/community")
check("admin now lists live games", "On the board now" in html)
check("...including this one", "Delete Test Game" in html)

code, html, loc = act("/admin/community", {"action": "game_cancel", "id": gid})
check("taking it down works", code == 302 and "game-cancelled" in (loc or ""), f"{code} {loc}")
check("...the record is kept", sql(f"SELECT status FROM games WHERE id={gid}") == "cancelled")

code, html, loc = act("/admin/community", {"action": "game_delete", "id": gid})
check("deleting works", code == 302 and "game-deleted" in (loc or ""), f"{code} {loc}")
check("...it is gone", sql(f"SELECT COUNT(*) FROM games WHERE id={gid}") == "0")

print("\n--- a listing ---")
seller = sql("SELECT id FROM users WHERE role='customer' ORDER BY id LIMIT 1")
if not seller:
    sql("INSERT INTO users (name,email,password_hash) VALUES ('Del Seller','del.seller@example.com','x')")
    seller = sql("SELECT id FROM users WHERE email='del.seller@example.com'")

sql("DELETE FROM listings WHERE slug='del-listing'")
sql(f"INSERT INTO listings (slug,seller_id,status,title,price_paise,item_condition) "
    f"VALUES ('del-listing',{seller},'live','Delete Test Listing',500000,'good')")
lid = sql("SELECT id FROM listings WHERE slug='del-listing'")
check("the listing is live", lid != "")

code, html, _ = adm.get("/admin/marketplace")
check("admin now lists live listings", "On the marketplace now" in html)
check("...including this one", "Delete Test Listing" in html)

code, html, loc = act("/admin/marketplace", {"action": "listing_withdraw", "id": lid})
check("withdrawing works", code == 302 and "withdrawn" in (loc or ""), f"{code} {loc}")
check("...everything is kept", sql(f"SELECT status FROM listings WHERE id={lid}") == "withdrawn")

code, html, loc = act("/admin/marketplace", {"action": "listing_delete", "id": lid})
check("deleting an unsold listing works", code == 302 and "deleted" in (loc or ""), f"{code} {loc}")
check("...it is gone", sql(f"SELECT COUNT(*) FROM listings WHERE id={lid}") == "0")

print("\n--- a listing somebody bought ---")
sql("DELETE FROM listings WHERE slug='sold-listing'")
sql(f"INSERT INTO listings (slug,seller_id,status,title,price_paise,item_condition) "
    f"VALUES ('sold-listing',{seller},'sold','Sold Test Listing',600000,'good')")
sid = sql("SELECT id FROM listings WHERE slug='sold-listing'")
buyer = sql(f"SELECT id FROM users WHERE id<>{seller} ORDER BY id LIMIT 1")
sql(f"INSERT INTO listing_orders (reference,listing_id,buyer_id,seller_id,status,"
    f"price_paise,seller_fee_paise,handling_paise,payout_paise,total_paise,"
    f"ship_name,ship_phone,ship_line1,ship_city,ship_state,ship_pin) "
    f"VALUES ('GS-DEL-1',{sid},{buyer},{seller},'released',600000,48000,12300,539700,600000,"
    f"'Buyer','9000000000','1 Road','Pune','MH','411001')")

code, html, loc = act("/admin/marketplace", {"action": "listing_delete", "id": sid})
check("deleting a sold listing is refused", "cannot be deleted" in html, html[:200])
check("...and it is still there", sql(f"SELECT COUNT(*) FROM listings WHERE id={sid}") == "1")
check("the screen says so instead of offering it", "cannot delete" in adm.get("/admin/marketplace")[1])

print("\n--- a listing cannot go live behind an unverified seller ---")
sql("DELETE FROM listings WHERE slug='gate-listing'")
sql("DELETE FROM users WHERE email='gate.seller@example.com'")
sql("INSERT INTO users (name,email,password_hash,phone) "
    "VALUES ('Gate Seller','gate.seller@example.com','x','9000000002')")
gsel = sql("SELECT id FROM users WHERE email='gate.seller@example.com'")
sql(f"INSERT INTO listings (slug,seller_id,status,title,price_paise,item_condition) "
    f"VALUES ('gate-listing',{gsel},'pending','Gate Test Listing',700000,'good')")
glid = sql("SELECT id FROM listings WHERE slug='gate-listing'")

code, html, loc = act("/admin/marketplace", {"action": "approve", "id": glid})
check("approving an unverified seller is refused", "not verified yet" in html, html[:200])
check("...and it is still pending", sql(f"SELECT status FROM listings WHERE id={glid}") == "pending")
check("...and the message names what is missing",
      "Phone reached" in html and "Payout name matches" in html)

sql(f"INSERT INTO seller_checks (user_id,kind,note) VALUES ({gsel},'phone','rang them')")
code, html, loc = act("/admin/marketplace", {"action": "approve", "id": glid})
check("one check is still not enough", "not verified yet" in html)

sql(f"INSERT INTO seller_checks (user_id,kind,note) VALUES ({gsel},'payout','UPI name matches')")
sql(f"UPDATE users SET seller_verified_at = NOW() WHERE id = {gsel}")
code, html, loc = act("/admin/marketplace", {"action": "approve", "id": glid})
check("with both done it goes live", code == 302 and "approved" in (loc or ""), f"{code} {loc}")
check("...and it really is live", sql(f"SELECT status FROM listings WHERE id={glid}") == "live")

sql(f"DELETE FROM listings WHERE id={glid}")
sql(f"DELETE FROM users WHERE id={gsel}")

print("\n--- not for a customer ---")
sql("DELETE FROM users WHERE email='del.nobody@example.com'")
cust = C()
t2 = cust.token("/register")
cust.post("/register", {"_token": t2, "name": "Nobody", "email": "del.nobody@example.com",
                        "password": "a long enough phrase", "next": "/account"})
# Registering does NOT sign you in -- it redirects to /login?registered=1. Without
# this the test would only prove a logged-OUT request is refused, which is a
# different and much weaker claim.
t3 = cust.token("/login")
cust.post("/login", {"_token": t3, "email": "del.nobody@example.com",
                     "password": "a long enough phrase", "next": "/account"})
check("the customer is signed in", cust.get("/account")[0] == 200)
tk = cust.token("/admin/marketplace")
code, html, _ = cust.post("/admin/marketplace", {"_token": tk or "x", "action": "listing_delete", "id": sid})
check("a customer cannot delete a listing", code in (403, 419), f"code={code}")
check("...it survives", sql(f"SELECT COUNT(*) FROM listings WHERE id={sid}") == "1")

print("\n--- cleaning up ---")
sql(f"DELETE FROM listing_orders WHERE reference='GS-DEL-1'")
sql("DELETE FROM listings WHERE slug IN ('del-listing','sold-listing')")
sql("DELETE FROM users WHERE email IN ('del.nobody@example.com','del.seller@example.com')")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
