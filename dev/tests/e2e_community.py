"""Phase 6: requests, the games board, account details, and restock alerts."""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"


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
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d, doseq=True).encode(),
                                   method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(r)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""

    def login(self, email, pw):
        t = self.token("/login")
        return self.post("/login", {"_token": t, "email": email, "password": pw, "next": "/account"})


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


def esc(v):
    return str(v).replace("'", "''")


# ---------------------------------------------------------------- setup
sql("DELETE FROM request_backers")
sql("DELETE FROM sourcing_requests WHERE model LIKE 'CT %'")
sql("DELETE FROM games WHERE title LIKE 'CT %'")
sql("DELETE FROM restock_alerts WHERE contact LIKE 'ct.%@example.com'")
sql("DELETE FROM users WHERE email LIKE 'ct.%@example.com'")

guest = C()
member = C()
t = member.token("/register")
member.post("/register", {"_token": t, "name": "CT Member", "email": "ct.member@example.com",
                          "password": "harbour lantern quill", "next": "/account"})
member.login("ct.member@example.com", "harbour lantern quill")

admin = C()
at = admin.token("/admin/login")
admin.post("/admin/login", {"_token": at, "email": "admin@gelsoft.in",
                            "password": "devpassword123", "next": "/admin/"})

print("--- requesting a model ---")
code, html, _ = guest.get("/request")
check("the request page opens to anyone", code == 200 and 'name="model"' in html)

base = {"brand": "Bohan", "model": "CT SR16 Special", "link": "",
        "grade": "Premium grade", "colour": "Black", "qty": "1",
        "budget": "15000-30000", "when": "soon", "gamedate": "",
        "name": "CT Guest", "city": "Pune", "state": "Maharashtra",
        "notes": "Happy to wait", "public": "1"}

t = guest.token("/request")
code, html, _ = guest.post("/request", dict(base, _token=t, contact="ct.guest@example.com"))
check("the acknowledgement is required", "a quote is not a commitment" in html)

t = guest.token("/request")
code, html, _ = guest.post("/request", dict(base, _token=t, contact="not an address",
                                            understood="1"))
check("a junk contact is refused", "cannot answer you" in html)

t = guest.token("/request")
code, html, _ = guest.post("/request", dict(base, _token=t, model="",
                                            contact="ct.guest@example.com", understood="1"))
check("a request with no model is refused", "which model" in html)

t = guest.token("/request")
code, html, _ = guest.post("/request", dict(base, _token=t, contact="ct.guest@example.com",
                                            understood="1"))
check("a complete request saves", "We have it" in html, html[:200])

rid = sql("SELECT id FROM sourcing_requests WHERE model='CT SR16 Special'")
check("it is in the database", rid != "", rid)
check("the budget band was stored in paise",
      sql(f"SELECT budget_min_paise FROM sourcing_requests WHERE id={rid}") == "1500000")
check("it starts as new", sql(f"SELECT status FROM sourcing_requests WHERE id={rid}") == "new")

code, html, _ = guest.get("/request")
check("it appears on the public board", "CT SR16 Special" in html)
check("...showing one person wants it", "1</strong> person wants" in html.replace("<strong>", "<strong>"))

print("\n--- backing somebody else's ---")
t = member.token("/request")
code, html, loc = member.post("/request", {"_token": t, "action": "back", "request_id": rid,
                                           "contact": "ct.member@example.com"})
check("backing it redirects", code == 302 and "backed=1" in (loc or ""), f"code={code}")
check("the backer is recorded",
      sql(f"SELECT COUNT(*) FROM request_backers WHERE request_id={rid}") == "1")

t = member.token("/request")
member.post("/request", {"_token": t, "action": "back", "request_id": rid,
                         "contact": "ct.member@example.com"})
check("backing twice does not count twice",
      sql(f"SELECT COUNT(*) FROM request_backers WHERE request_id={rid}") == "1")

code, html, _ = guest.get("/request")
check("the board shows two people now", "2</strong> people want" in html)

print("\n--- answering it ---")
t = admin.token("/admin/community")
code, html, _ = admin.post("/admin/community", {"_token": t, "action": "quote", "id": rid,
                                                "status": "quoted", "reply": "",
                                                "quote": "28000", "lead_time": "5-6 weeks"})
check("a quote without a reply is refused", "what you are telling them" in html)

t = admin.token("/admin/community")
code, html, loc = admin.post("/admin/community", {
    "_token": t, "action": "quote", "id": rid, "status": "quoted",
    "reply": "We can get it. Five to six weeks.", "quote": "28000", "lead_time": "5-6 weeks"})
check("quoting it saves", code == 302, f"code={code}")
row = sql(f"SELECT status, quote_paise, lead_time FROM sourcing_requests WHERE id={rid}").split("\t")
check("the quote is stored in paise", row == ["quoted", "2800000", "5-6 weeks"], row)

mails = sql("SELECT COUNT(*) FROM mail_log WHERE template='request_quoted'")
check("the asker and the backer were both emailed", int(mails or 0) >= 2, mails)

print("\n--- listing a game ---")
soon = (date.today() + timedelta(days=21)).isoformat()
gbase = {"title": "CT Sunday Skirmish", "city": "Pune", "state": "Maharashtra",
         "region": "west", "starts_at": soon, "fps_cap": "230", "fee": "500",
         "venue": "The Yard", "details": "Eye protection provided. Rentals available.",
         "signup_link": "", "host_name": "CT Host", "rental": "1", "beginners": "1"}

# Hosting needs an account now. The anonymous attempt is asserted rather than
# skipped, because that gate is the point.
t = guest.token("/games")
code, html, _ = guest.post("/games", dict(gbase, _token=t, permission="1"))
check("a signed-out host is refused", "Sign in to put a game" in html, html[:160])
check("...and nothing was saved",
      sql("SELECT COUNT(*) FROM games WHERE title='CT Sunday Skirmish'") == "0")

t = member.token("/games")
code, html, _ = member.post("/games", dict(gbase, _token=t))
check("permission for the ground is required", "permission for the ground" in html)

t = member.token("/games")
code, html, _ = member.post("/games", dict(gbase, _token=t,
                                           starts_at="2020-01-01", permission="1"))
check("a date in the past is refused", "date has passed" in html)

t = member.token("/games")
code, html, _ = member.post("/games", dict(gbase, _token=t, permission="1"))
check("a complete submission saves", "In the queue" in html, html[:200])
check("the host is the signed-in account",
      sql("SELECT host_id FROM games WHERE title='CT Sunday Skirmish'")
      == sql("SELECT id FROM users WHERE email='ct.member@example.com'"))

gid = sql("SELECT id FROM games WHERE title='CT Sunday Skirmish'")
check("it waits for review", sql(f"SELECT status FROM games WHERE id={gid}") == "pending")

code, html, _ = guest.get("/games")
check("a pending game is not on the board", "CT Sunday Skirmish" not in html)

t = admin.token("/admin/community")
code, html, _ = admin.post("/admin/community", {"_token": t, "action": "game_reject",
                                                "id": gid, "note": ""})
check("sending a game back needs a reason", "Say why" in html)

t = admin.token("/admin/community")
code, html, loc = admin.post("/admin/community", {"_token": t, "action": "game_approve", "id": gid})
check("approving puts it on the board", sql(f"SELECT status FROM games WHERE id={gid}") == "live")

code, html, _ = guest.get("/games")
check("it is public now", "CT Sunday Skirmish" in html)
check("the FPS cap is shown, flagged as indoor-friendly", "230 FPS cap (indoor-friendly)" in html)
check("the entry fee is shown", "500" in html)

code, html, _ = guest.get("/games?region=west")
check("the region filter finds it", "CT Sunday Skirmish" in html)
code, html, _ = guest.get("/games?region=south")
check("...and another region does not", "CT Sunday Skirmish" not in html)

print("\n--- the account ---")
t = member.token("/account")
code, html, _ = member.post("/account", {"_token": t, "action": "address",
                                         "name": "CT Member", "phone": "9876500000",
                                         "line1": "4 Test Street", "city": "Pune",
                                         "state": "Maharashtra", "pin": "000000",
                                         "is_default": "1"})
check("a bad PIN is refused", "six digits" in html)

t = member.token("/account")
code, html, loc = member.post("/account", {"_token": t, "action": "address",
                                           "name": "CT Member", "phone": "9876500000",
                                           "line1": "4 Test Street", "city": "Pune",
                                           "state": "Maharashtra", "pin": "411001",
                                           "is_default": "1"})
check("an address saves", code == 302, f"code={code}")
uid = sql("SELECT id FROM users WHERE email='ct.member@example.com'")
check("it is theirs", sql(f"SELECT COUNT(*) FROM addresses WHERE user_id={uid}") == "1")

t = member.token("/account")
member.post("/account", {"_token": t, "action": "address",
                         "name": "CT Member", "phone": "9876500000",
                         "line1": "9 Other Road", "city": "Mumbai",
                         "state": "Maharashtra", "pin": "400001", "is_default": "1"})
check("a second address saves",
      sql(f"SELECT COUNT(*) FROM addresses WHERE user_id={uid}") == "2")
check("only one is the default",
      sql(f"SELECT COUNT(*) FROM addresses WHERE user_id={uid} AND is_default=1") == "1")

other = C()
t2 = other.token("/register")
other.post("/register", {"_token": t2, "name": "CT Other", "email": "ct.other@example.com",
                         "password": "harbour lantern quill", "next": "/account"})
other.login("ct.other@example.com", "harbour lantern quill")
aid = sql(f"SELECT id FROM addresses WHERE user_id={uid} ORDER BY id LIMIT 1")
t = other.token("/account")
other.post("/account", {"_token": t, "action": "address_delete", "address_id": aid})
check("somebody else cannot delete your address",
      sql(f"SELECT COUNT(*) FROM addresses WHERE id={aid}") == "1")

t = member.token("/account")
code, html, loc = member.post("/account", {"_token": t, "action": "details",
                                           "name": "CT Renamed", "email": "ct.member@example.com",
                                           "phone": "9876511111"})
check("details save", sql(f"SELECT name FROM users WHERE id={uid}") == "CT Renamed")

t = member.token("/account")
code, html, _ = member.post("/account", {"_token": t, "action": "details",
                                         "name": "CT Renamed", "email": "admin@gelsoft.in",
                                         "phone": ""})
check("taking somebody else's email is refused", "already an account" in html)

t = member.token("/account")
code, html, _ = member.post("/account", {"_token": t, "action": "password",
                                         "current": "wrong one here", "new": "another long phrase"})
check("a wrong current password is refused", "not your current password" in html)

t = member.token("/account")
code, html, _ = member.post("/account", {"_token": t, "action": "password",
                                         "current": "harbour lantern quill", "new": "short"})
check("a short new password is refused", "at least 10" in html)

t = member.token("/account")
code, html, loc = member.post("/account", {"_token": t, "action": "password",
                                           "current": "harbour lantern quill",
                                           "new": "cinder valley rope"})
check("the password changes", code == 302, f"code={code}")
again = C()
code, _, _ = again.login("ct.member@example.com", "cinder valley rope")
check("...and the new one works", code == 302)

t = member.token("/account")
code, html, _ = member.post("/account", {"_token": t, "action": "payout",
                                         "upi_id": "not a upi id", "account_name": "CT"})
check("a malformed UPI id is refused", "name@bank" in html)

t = member.token("/account")
member.post("/account", {"_token": t, "action": "payout",
                         "upi_id": "ctmember@okbank", "account_name": "CT Renamed"})
check("payout details save",
      sql(f"SELECT upi_id FROM payout_details WHERE user_id={uid}") == "ctmember@okbank")

print("\n--- restock alerts actually going out ---")
sql("DELETE FROM products WHERE slug='ct-alert-item'")
sql("INSERT INTO products (slug,category,name,grade,price_paise,stock_qty,status) "
    "VALUES ('ct-alert-item','blaster','CT Alert Blaster','mid',500000,0,'published')")
pid = sql("SELECT id FROM products WHERE slug='ct-alert-item'")

t = guest.token("/")
for who in ["ct.one@example.com", "ct.two@example.com"]:
    guest.post("/api/alerts.php", {"_token": t, "contact": who, "product_id": pid})
check("two people are waiting",
      sql(f"SELECT COUNT(*) FROM restock_alerts WHERE product_id={pid} AND notified_at IS NULL") == "2")

code, html, _ = admin.get("/admin/community")
check("the admin screen shows them waiting", "CT Alert Blaster" in html)
check("...and will not send while it is out of stock", "Not back yet" in html)

sql(f"UPDATE products SET stock_qty=4 WHERE id={pid}")
t = admin.token("/admin/community")
code, html, loc = admin.post("/admin/community", {"_token": t, "action": "send_alerts", "id": pid})
check("sending them works", code == 302 and "alerts-" in (loc or ""), f"loc={loc}")
check("everyone waiting was marked told",
      sql(f"SELECT COUNT(*) FROM restock_alerts WHERE product_id={pid} AND notified_at IS NULL") == "0")

t = admin.token("/admin/community")
code, html, loc = admin.post("/admin/community", {"_token": t, "action": "send_alerts", "id": pid})
check("pressing it again tells nobody twice", "alerts-0" in (loc or ""), f"loc={loc}")

print("\n--- who can reach the admin screen ---")
code, _, loc = member.get("/admin/community")
check("a customer cannot", code in (302, 403), f"code={code}")
code, _, loc = C().get("/admin/community")
check("a stranger cannot", code == 302 and "/admin/login" in (loc or ""))

print("\n--- cleaning up ---")
sql("DELETE FROM request_backers")
sql("DELETE FROM sourcing_requests WHERE model LIKE 'CT %'")
sql("DELETE FROM games WHERE title LIKE 'CT %'")
sql(f"DELETE FROM restock_alerts WHERE product_id={pid} OR contact LIKE 'ct.%@example.com'")
sql(f"DELETE FROM products WHERE id={pid}")
sql("DELETE FROM users WHERE email LIKE 'ct.%@example.com'")
sql("DELETE FROM mail_log WHERE recipient LIKE 'ct.%@example.com'")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
