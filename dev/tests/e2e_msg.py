"""The messaging flow: buyer starts a thread from a listing, both sides talk,
an outsider cannot read it, and an admin can."""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"


def _sql(q):
    import subprocess
    r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft", "-sN", "-e", q],
                       capture_output=True)
    return r.stdout.decode("utf-8", "replace").strip()


# Look the fixture up rather than hard-coding an id that another suite may
# have recycled.
LISTING_ID = _sql("SELECT id FROM listings WHERE slug='dev-tg-gen8-used'") or "0"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def _do(self, req):
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

    def get(self, path):
        return self._do(urllib.request.Request(BASE + path))

    def post(self, path, data):
        req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode(), method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(req)

    def token(self, path):
        _, html, _ = self.get(path)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', html)
        return m.group(1) if m else ""

    def login(self, email, password):
        tok = self.token("/login")
        return self.post("/login", {"_token": tok, "email": email, "password": password, "next": "/account"})


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


print("\n--- buyer opens a conversation ---")
buyer = Client()
code, _, _ = buyer.login("buyer.test@example.com", "correct horse battery")
check("buyer signs in", code == 302)

tok = buyer.token("/marketplace/dev-tg-gen8-used")
code, html, loc = buyer.post("/listing/message", {"_token": tok, "listing_id": LISTING_ID})
check("messaging a listing opens a thread", code == 302 and "/messages?thread=" in (loc or ""), f"loc={loc}")

thread_id = re.search(r"thread=(\d+)", loc or "").group(1) if loc and "thread=" in loc else ""
check("got a thread id", bool(thread_id))

code, html, _ = buyer.get(f"/messages?thread={thread_id}")
check("the thread opens", code == 200 and "TG Gen8 with 3 hi-cap mags" in html)

tok = buyer.token(f"/messages?thread={thread_id}")
code, _, loc = buyer.post("/messages", {
    "_token": tok, "thread_id": thread_id, "kind": "message",
    "body": "Has the gearbox been opened? <b>bold</b>",
})
check("buyer sends a message", code == 302, f"loc={loc}")

code, html, _ = buyer.get(f"/messages?thread={thread_id}")
check("the message is in the thread", "Has the gearbox been opened?" in html)
check("markup in a message is escaped", "<b>bold</b>" not in html and "&lt;b&gt;bold" in html)

tok = buyer.token(f"/messages?thread={thread_id}")
code, _, _ = buyer.post("/messages", {
    "_token": tok, "thread_id": thread_id, "kind": "offer", "offer": "7000",
})
code, html, _ = buyer.get(f"/messages?thread={thread_id}")
check("an offer is recorded in the thread", "offered" in html and "7,000" in html)

print("\n--- the seller's side ---")
seller = Client()
code, _, _ = seller.login("seller.test@example.com", "seller pass phrase")
check("seller signs in", code == 302)

code, html, _ = seller.get("/messages")
check("seller sees the conversation", "Test Buyer" in html)

code, html, _ = seller.get(f"/messages?thread={thread_id}")
check("seller can read it", code == 200 and "Has the gearbox been opened?" in html)

tok = seller.token(f"/messages?thread={thread_id}")
code, _, _ = seller.post("/messages", {
    "_token": tok, "thread_id": thread_id, "kind": "message",
    "body": "Opened once for a regrease, nothing replaced.",
})
code, html, _ = buyer.get(f"/messages?thread={thread_id}")
check("buyer sees the reply", "Opened once for a regrease" in html)

tok = seller.token(f"/messages?thread={thread_id}")
code, _, _ = seller.post("/messages", {
    "_token": tok, "thread_id": thread_id, "kind": "offer", "offer": "9000",
})
code, html, _ = seller.get(f"/messages?thread={thread_id}")
check("a seller cannot make the buyer's offer", "9,000" not in html)

print("\n--- nobody else ---")
outsider = Client()
tok = outsider.token("/register")
outsider.post("/register", {"_token": tok, "name": "Nosy", "email": "nosy.test@example.com",
                            "password": "another long one", "next": "/account"})
outsider.login("nosy.test@example.com", "another long one")
code, html, _ = outsider.get(f"/messages?thread={thread_id}")
check("an outsider gets a 404, not the thread", code == 404 and "gearbox been opened" not in html, f"code={code}")

tok = outsider.token("/")
code, html, _ = outsider.post("/messages", {
    "_token": tok, "thread_id": thread_id, "kind": "message", "body": "butting in",
})
check("an outsider cannot post into it", "not yours" in html.lower(), f"code={code}")

print("\n--- admin can read it ---")
admin = Client()
tok = admin.token("/admin/login")
admin.post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in",
                            "password": "devpassword123", "next": "/admin/"})
code, html, _ = admin.get("/admin/")
check("conversations show on the dashboard", "Test Buyer" in html and "Test Seller" in html)

code, html, _ = admin.get(f"/admin/thread?id={thread_id}")
check("admin reads the thread", code == 200 and "Has the gearbox been opened?" in html)
check("admin view is read-only", 'name="body"' not in html)

print("\n--- what a conversation may be started about ---")

# thread_open() read the listing's status and never used it, so a signed-in
# person could open a thread about any id at all: a draft never submitted, one
# waiting for review, one an admin rejected. The thread then prints that
# listing's title and asking price, so it leaked unreviewed content as well as
# bothering the seller about something the site had taken down.
#
# Its twin, thread_open_game(), has always refused anything that is not live.

listing_id = _sql("SELECT listing_id FROM threads WHERE id = " + str(thread_id)) \
    or _sql("SELECT id FROM listings ORDER BY id LIMIT 1")
listing_was = _sql(f"SELECT status FROM listings WHERE id = {listing_id}")
check("there is a listing to test against", str(listing_id).strip().isdigit(), listing_id)


def try_open(status):
    """Start a NEW conversation while the listing is in this state.

    The token comes from /marketplace because `/listing?id=N` is a redirect
    target rather than a rendered page -- taking it from there gave an empty
    token, and the resulting 419 looked exactly like a refusal."""
    _sql(f"DELETE FROM threads WHERE listing_id = {listing_id} "
         f"AND buyer_id = (SELECT id FROM users WHERE email = 'buyer.test@example.com')")
    _sql(f"UPDATE listings SET status = '{status}' WHERE id = {listing_id}")
    tok = buyer.token("/account")
    code, _, loc = buyer.post("/listing/message",
                              {"_token": tok, "listing_id": listing_id})
    return code, (loc or "")


code, loc = try_open("live")
check("a live listing can be messaged about", "thread=" in loc, (code, loc))

for status in ("draft", "pending", "rejected", "withdrawn", "sold"):
    code, loc = try_open(status)
    # Asserted by SHAPE, not by absence: a refusal is a redirect back to the
    # marketplace. "no thread= in the location" is also true of a 419, which
    # is how five of these passed while never running.
    check(f"a {status} listing cannot",
          code in (302, 303) and "/marketplace" in loc and "thread=" not in loc,
          (code, loc))

# ...but an existing conversation must survive the listing selling. That is
# exactly when the two of them need to talk about the parcel and the tracking,
# and cutting them off then would be worse than the bug.
_sql(f"UPDATE listings SET status = 'live' WHERE id = {listing_id}")
tok = buyer.token("/account")
_, _, loc = buyer.post("/listing/message", {"_token": tok, "listing_id": listing_id})
live_thread = re.search(r"thread=(\d+)", loc or "")
check("a conversation opens while it is live", live_thread is not None, loc)

if live_thread:
    _sql(f"UPDATE listings SET status = 'sold' WHERE id = {listing_id}")
    tok = buyer.token("/account")
    _, _, loc2 = buyer.post("/listing/message", {"_token": tok, "listing_id": listing_id})
    again = re.search(r"thread=(\d+)", loc2 or "")
    check("...and is still reachable once it sells",
          again is not None and again.group(1) == live_thread.group(1),
          (loc2, live_thread.group(1)))
    code, html, _ = buyer.get("/messages?thread=" + live_thread.group(1))
    check("...and still opens", code == 200, code)
    _sql(f"DELETE FROM threads WHERE id = {live_thread.group(1)}")

# A listing id that does not exist is a redirect, not an error.
tok = buyer.token("/account")
code, _, loc = buyer.post("/listing/message", {"_token": tok, "listing_id": "99999999"})
check("an unknown listing sends you back to the marketplace",
      code in (302, 303) and "/marketplace" in (loc or ""), (code, loc))

_sql(f"UPDATE listings SET status = '{listing_was}' WHERE id = {listing_id}")
check("the listing was put back",
      _sql(f"SELECT status FROM listings WHERE id = {listing_id}") == listing_was)

print("\n--- cleaning up ---")
# This suite used to leave its thread behind. That is how it collided with a
# fixture added by hand, and once offers carried a status the leftovers piled
# up one per run, each marked superseded by the next. Messages cascade with
# the thread, so one delete clears the lot.
_sql(f"DELETE FROM threads WHERE id = {thread_id}")
print(f"  removed thread {thread_id} and its messages")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
