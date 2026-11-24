"""What the site does when the database is gone.

Nobody tests this and everybody eventually needs it: a shared host restarts
MySQL, a connection limit is hit, a password is rotated. The site is built to
survive it -- db_ready() is checked all over -- but "survives" was never
measured, and one page was surviving by saying something false.

This suite points the development config at a database that does not exist,
measures, and puts it back. The restore is in a finally, and the last
assertions check that it really happened: a test that can leave the
development environment broken is worse than no test.
"""
import hashlib
import http.cookiejar
import io
import os
import re
import shutil
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
CONFIG = os.path.join(REPO, "inc", "config.local.php")
BACKUP = CONFIG + ".outage-backup"
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
    """A redirect is an answer here, not something to chase."""

    def redirect_request(self, *a, **k):
        return None


_jar = http.cookiejar.CookieJar()
_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(_jar), NoRedirect())


def _send(req):
    req.add_header("User-Agent", UA)
    try:
        r = _opener.open(req)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def get(path):
    return _send(urllib.request.Request(BASE + path))


def post(path, data, headers=None):
    req = urllib.request.Request(
        BASE + path, data=urllib.parse.urlencode(data).encode(), method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    return _send(req)


def token(path):
    # The token belongs to the session, so it has to be fetched through the
    # same cookie jar that will post it.
    _, html = get(path)
    m = re.search(r'name="_token" value="([a-f0-9]+)"', html)
    return m.group(1) if m else ""


def alerts(html):
    out = []
    for m in re.findall(r'role="alert"[^>]*>(.*?)</p>', html, re.S):
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m)).strip()
        if t:
            out.append(t)
    return " | ".join(out)


original = io.open(CONFIG, encoding="utf-8").read()
original_md5 = hashlib.md5(original.encode()).hexdigest()
shutil.copy2(CONFIG, BACKUP)

print("--- the sentence lives in one place ---")

src_auth = io.open(os.path.join(REPO, "inc", "auth.php"), encoding="utf-8").read()
ok("inc/auth.php defines it", "const AUTH_DB_DOWN" in src_auth)

for page in ["login.php", "forgot.php", "reset.php", os.path.join("admin", "login.php")]:
    s = io.open(os.path.join(REPO, page), encoding="utf-8").read()
    ok("%s uses it rather than its own wording" % page.replace("\\", "/"),
       "AUTH_DB_DOWN" in s)
    ok("...and checks db_ready() to decide",
       "!db_ready()" in s, page)

try:
    # --- break it -----------------------------------------------------------
    broken = original.replace("'name'    => 'gelsoft'",
                              "'name'    => 'gelsoft_outage_probe'", 1)
    assert broken != original, "could not find the database name to break"
    io.open(CONFIG, "w", encoding="utf-8", newline="").write(broken)

    print()
    print("--- nothing 500s ---")

    # A visitor should never meet a stack trace or a blank page. 302 is the
    # sign-in redirect on the pages that need an account.
    pages = ["/", "/blasters", "/gear", "/parts", "/marketplace", "/games",
             "/faq", "/safety", "/policy", "/request", "/cart", "/search",
             "/login", "/register", "/sitemap.xml"]
    bad = []
    for p in pages:
        code, _ = get(p)
        if code != 200:
            bad.append((p, code))
    ok("every public page still answers 200", not bad, bad[:5])

    for p in ["/account", "/sell", "/checkout"]:
        code, _ = get(p)
        ok("%s still redirects rather than erroring" % p, code in (302, 303), code)

    print()
    print("--- and the shop says something ---")

    _, html = get("/blasters")
    ok("a shelf offers its empty state", "Restocking soon" in html)
    _, html = get("/marketplace")
    ok("the marketplace offers its own", "Nothing listed yet" in html)

    code, body = post("/api/alerts.php",
                      {"_token": token("/blasters"), "contact": "x@example.com",
                       "category": "blaster"},
                      {"Accept": "application/json"})
    ok("the alerts API answers 503, not 200", code == 503, code)
    ok("...and says to try again", "try again" in body.lower(), body[:90])

    print()
    print("--- and sign-in does not blame the person ---")

    # This is the one that was wrong: auth_attempt() returns null both for bad
    # credentials and for "could not ask", and both callers printed the same
    # sentence. A customer then goes to reset a password that was fine.
    _, html = post("/login", {"_token": token("/login"),
                              "email": "admin@gelsoft.in",
                              "password": "devpassword123"})
    msg = alerts(html)
    ok("/login does not claim the password is wrong",
       "do not match" not in msg, msg[:120])
    ok("...it says the database is unreachable",
       "cannot reach the accounts database" in msg, msg[:120])
    ok("...and that the person typed nothing wrong",
       "nothing is wrong with what you typed" in msg.lower(), msg[:120])

    _, html = post("/admin/login", {"_token": token("/admin/login"),
                                    "email": "admin@gelsoft.in",
                                    "password": "devpassword123",
                                    "next": "/admin/"})
    msg = alerts(html)
    ok("/admin/login does not either", "do not match" not in msg, msg[:120])
    ok("...so the owner can tell an outage from a forgotten password",
       "cannot reach the accounts database" in msg, msg[:120])

    _, html = post("/forgot", {"_token": token("/forgot"), "email": "admin@gelsoft.in"})
    msg = alerts(html)
    ok("/forgot does not promise an email it cannot send",
       "Check your email" not in html or "cannot reach" in msg, msg[:120])
    ok("...it says why instead", "cannot reach the accounts database" in msg, msg[:120])

finally:
    # --- put it back, whatever happened above -------------------------------
    shutil.copy2(BACKUP, CONFIG)
    os.remove(BACKUP)

print()
print("--- and the development environment is as it was ---")

now = io.open(CONFIG, encoding="utf-8").read()
ok("inc/config.local.php is byte-for-byte what it was",
   hashlib.md5(now.encode()).hexdigest() == original_md5)
ok("the backup file was cleaned up", not os.path.exists(BACKUP))

code, html = get("/blasters")
# Not "Restocking soon is absent" -- that phrase is in the restock-alert form
# too. A link to an actual product is the thing that proves the database is
# answering again.
ok("the shop is serving products again",
   code == 200 and re.search(r'href="/blasters/[a-z0-9-]+"', html) is not None,
   code)

_, html = post("/login", {"_token": token("/login"),
                          "email": "admin@gelsoft.in", "password": "wrong-on-purpose"})
msg = alerts(html)
ok("a genuinely wrong password still says so",
   "do not match" in msg, msg[:120])

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
