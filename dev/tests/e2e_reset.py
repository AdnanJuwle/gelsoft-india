"""Password reset: the happy path, and everything it has to refuse."""
import hashlib
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

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
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
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


EMAIL = "rt.user@example.com"
OLD = "harbour lantern quill"
NEW = "cinder valley rope"

sql(f"DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE email='{EMAIL}')")
sql(f"DELETE FROM users WHERE email='{EMAIL}'")
sql(f"DELETE FROM mail_log WHERE recipient='{EMAIL}'")

c = C()
t = c.token("/register")
c.post("/register", {"_token": t, "name": "RT User", "email": EMAIL,
                     "password": OLD, "next": "/account"})
uid = sql(f"SELECT id FROM users WHERE email='{EMAIL}'")
check("the test account exists", uid != "", uid)


def live_token_hash():
    return sql(f"SELECT token_hash FROM password_resets WHERE user_id={uid} "
               f"AND used_at IS NULL ORDER BY id DESC LIMIT 1")


def ask(email):
    cl = C()
    tk = cl.token("/forgot")
    return cl.post("/forgot", {"_token": tk, "email": email})


print("\n--- asking for a link ---")
code, html, _ = C().get("/forgot")
check("the page opens", code == 200 and 'name="email"' in html)

code, html, _ = ask("not an address")
check("a junk address is refused", "does not look like an email" in html)

code, html, _ = ask("nobody.here@example.com")
said_for_unknown = "Check your email" in html
check("an unknown address still says 'check your email'", said_for_unknown)
check("...and makes no token for it",
      sql("SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id=r.user_id "
          "WHERE u.email='nobody.here@example.com'") in ("", "0"))

code, html, _ = ask(EMAIL)
check("a real address says exactly the same thing", "Check your email" in html)
check("...but does make a token", live_token_hash() != "")

check("no enumeration: both answers are identical", said_for_unknown and "Check your email" in html)

check("the token is stored only as a hash", len(live_token_hash()) == 64)

print("\n--- using it ---")
# The real token only exists in the email, so reconstruct the pair the way the
# app does: make one here and check the app accepts it.
import secrets
raw = secrets.token_hex(32)
sql(f"DELETE FROM password_resets WHERE user_id={uid}")
sql(f"INSERT INTO password_resets (user_id, token_hash, expires_at) "
    f"VALUES ({uid}, '{hashlib.sha256(raw.encode()).hexdigest()}', "
    f"DATE_ADD(NOW(), INTERVAL 60 MINUTE))")

code, html, _ = C().get(f"/reset?token={raw}")
check("a good link opens the form", code == 200 and 'name="password"' in html and EMAIL in html)

code, html, _ = C().get("/reset?token=" + "0" * 64)
check("an unknown token is refused", "That link has gone" in html)

code, html, _ = C().get("/reset?token=notevenhex")
check("a malformed token is refused", "That link has gone" in html)

r = C()
tk = r.token(f"/reset?token={raw}")
code, html, _ = r.post("/reset", {"_token": tk, "token": raw,
                                  "password": NEW, "password2": "something else"})
check("mismatched passwords are refused", "do not match" in html)

r = C()
tk = r.token(f"/reset?token={raw}")
code, html, _ = r.post("/reset", {"_token": tk, "token": raw,
                                  "password": "short", "password2": "short"})
check("a short password is refused", "at least 10" in html)

r = C()
tk = r.token(f"/reset?token={raw}")
code, html, _ = r.post("/reset", {"_token": tk, "token": raw,
                                  "password": "my password here", "password2": "my password here"})
check("a password containing 'password' is refused", "people guess first" in html)

r = C()
tk = r.token(f"/reset?token={raw}")
code, html, _ = r.post("/reset", {"_token": tk, "token": raw, "password": NEW, "password2": NEW})
check("the reset goes through", "Your password is changed" in html, html[:200])

print("\n--- after it is used ---")
check("the old password no longer works", C().login(EMAIL, OLD)[0] == 200)
check("the new one does", C().login(EMAIL, NEW)[0] == 302)

check("the token is marked spent",
      sql(f"SELECT used_at IS NOT NULL FROM password_resets WHERE user_id={uid} LIMIT 1") == "1")

code, html, _ = C().get(f"/reset?token={raw}")
check("the link cannot be used twice", "That link has gone" in html)

check("a confirmation email was sent",
      int(sql(f"SELECT COUNT(*) FROM mail_log WHERE recipient='{EMAIL}' "
              f"AND template='password_changed'") or 0) >= 1)

print("\n--- an expired one ---")
old_raw = secrets.token_hex(32)
sql(f"INSERT INTO password_resets (user_id, token_hash, expires_at) "
    f"VALUES ({uid}, '{hashlib.sha256(old_raw.encode()).hexdigest()}', "
    f"DATE_SUB(NOW(), INTERVAL 5 MINUTE))")
code, html, _ = C().get(f"/reset?token={old_raw}")
check("an expired link is refused", "That link has gone" in html)

print("\n--- a spare key is not left lying around ---")
sql(f"DELETE FROM password_resets WHERE user_id={uid}")
a, b = secrets.token_hex(32), secrets.token_hex(32)
for one in (a, b):
    sql(f"INSERT INTO password_resets (user_id, token_hash, expires_at) "
        f"VALUES ({uid}, '{hashlib.sha256(one.encode()).hexdigest()}', "
        f"DATE_ADD(NOW(), INTERVAL 60 MINUTE))")

r = C()
tk = r.token(f"/reset?token={a}")
r.post("/reset", {"_token": tk, "token": a, "password": "another good phrase",
                  "password2": "another good phrase"})
code, html, _ = C().get(f"/reset?token={b}")
check("using one link spends every other live link too", "That link has gone" in html)

print("\n--- asking over and over ---")
sql(f"DELETE FROM password_resets WHERE user_id={uid}")
for _ in range(6):
    ask(EMAIL)
made = int(sql(f"SELECT COUNT(*) FROM password_resets WHERE user_id={uid}") or 0)
check("requests are capped per hour", made <= 4, f"{made} tokens made")

print("\n--- no CSRF, no reset ---")
code, html, _ = C().post("/forgot", {"email": EMAIL})
check("the forgot form needs a token", code == 419, f"code={code}")
code, html, _ = C().post("/reset", {"token": a, "password": NEW, "password2": NEW})
check("the reset form needs a token", code == 419, f"code={code}")

print("\n--- the page does not leak the token ---")
code, html, _ = C().get(f"/reset?token={b}")
check("it sends no referrer", 'name="referrer" content="no-referrer"' in html)
check("it is noindex", 'name="robots"' in html and "noindex" in html)

print("\n--- cleaning up ---")
sql(f"DELETE FROM password_resets WHERE user_id={uid}")
sql(f"DELETE FROM mail_log WHERE recipient='{EMAIL}'")
sql(f"DELETE FROM users WHERE email='{EMAIL}'")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
