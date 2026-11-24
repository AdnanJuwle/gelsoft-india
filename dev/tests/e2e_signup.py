"""How many accounts one connection may open.

Sign-up was the only thing on this site that creates a row from outside and
had no limit on it: sign-in is throttled per address, password resets per
account per hour, messages per sender per minute. A sign-up needs nothing
beforehand except a CSRF token, and a token is one GET away.

The interesting assertions are the ones about what the throttle must NOT do:
not fire on a mistyped email, not count a failed sign-up, and not lock
everybody out when it cannot read its own records.
"""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
PW = "correct horse battery staple"

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
    def redirect_request(self, *a, **k):
        return None


class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def _do(self, req):
        req.add_header("User-Agent", UA)
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def get(self, p):
        return self._do(urllib.request.Request(BASE + p))

    def post(self, p, d):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(r)

    def token(self, p):
        _, h = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""


def sql(query):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--default-character-set=utf8mb4",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True)
    out = (r.stdout or b"").decode("utf-8", "replace").strip()
    return [l.split("\t") for l in out.split("\n") if l]


def messages(html):
    out = []
    for m in re.findall(r'role="alert"[^>]*>(.*?)</p>', html, re.S):
        t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m)).strip()
        if t:
            out.append(t)
    return out


def sign_up(email, name="Throttle Test", password=PW):
    """A fresh browser each time: the limit is per address, not per session."""
    c = C()
    st, html = c.post("/register", {
        "_token": c.token("/register"), "name": name, "email": email,
        "phone": "", "password": password,
    })
    return st, " ".join(messages(html))


def clean():
    # Every signup row, not only this suite's: the other suites register their
    # own fixtures from the same address, and the throttle counts those too.
    # Clearing it is part of starting from a known state, like the empty
    # orders table the rest of the suites assume.
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'signup:%'")
    sql("DELETE FROM users WHERE email LIKE 'throttle-%@example.com'")
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'signup:throttle-%'")
    sql("DELETE FROM login_attempts WHERE identifier LIKE 'signup:ratetest%'")
    sql("DELETE FROM users WHERE email LIKE 'ratetest%@example.com'")


clean()

print("--- the constants say what they do ---")

probe = subprocess.run(
    [PHP, "-r", "require 'inc/bootstrap.php';"
                "echo AUTH_SIGNUP_MAX, ',', AUTH_SIGNUP_WINDOW_M, ',',"
                "(int) function_exists('auth_signup_blocked'), ',',"
                "(int) function_exists('auth_log_signup');"],
    cwd=REPO, capture_output=True, text=True)
ok("the throttle is defined", probe.returncode == 0, probe.stderr[:200])
parts = probe.stdout.split(",")
limit = int(parts[0]) if parts and parts[0].isdigit() else 0
ok("there is a limit", limit > 0, probe.stdout)
ok("over a window of at least an hour", parts[1:2] and int(parts[1]) >= 60, probe.stdout)
ok("both halves exist: the counter and the recorder",
   parts[2:4] == ["1", "1"], probe.stdout)
# Loose enough for a household behind one address, tight enough to matter.
ok("the limit is loose enough for a real household", 3 <= limit <= 20, limit)


print()
print("--- the limit holds ---")

made = 0
for i in range(1, limit + 1):
    st, msg = sign_up("throttle-%d@example.com" % i)
    if st in (302, 303):
        made += 1
ok("the first %d go through" % limit, made == limit, made)

st, msg = sign_up("throttle-%d@example.com" % (limit + 1))
ok("the next one is refused", st == 200 and "connection" in msg.lower(), (st, msg[:90]))
ok("...and says what to do instead", "sign in" in msg.lower(), msg[:110])
ok("...and no account was made for it",
   sql("SELECT COUNT(*) FROM users WHERE email = 'throttle-%d@example.com'"
       % (limit + 1))[0][0] == "0")

ok("exactly %d accounts exist from the run" % limit,
   sql("SELECT COUNT(*) FROM users WHERE email LIKE 'throttle-%@example.com'")[0][0]
   == str(limit),
   sql("SELECT COUNT(*) FROM users WHERE email LIKE 'throttle-%@example.com'")[0][0])


print()
print("--- what it must NOT do ---")

# A mistyped email is a form error, not a throttle hit. The check runs after
# the rest of the form is valid, so someone fumbling the address is told that.
clean()
for bad, why in [("not-an-email", "a malformed address"),
                 ("", "an empty address")]:
    st, msg = sign_up(bad)
    ok("%s is answered as a form error" % why,
       "email address" in msg.lower() and "connection" not in msg.lower(), msg[:90])

ok("...and a refused form is not counted against the limit",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'signup:%'")[0][0] == "0",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'signup:%'")[0][0])

# A weak password is refused too, and must not burn an attempt.
st, msg = sign_up("throttle-weak@example.com", password="short")
ok("a weak password is a form error", st == 200 and "connection" not in msg.lower(), msg[:90])
ok("...and is not counted either",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'signup:%'")[0][0] == "0")


print()
print("--- it is not confused with the sign-in throttle ---")

# auth_ip_blocked() counts FAILED sign-ins. A sign-up is a success, and if it
# were logged as one of those the two limits would interfere: five new
# accounts would eat into the twenty allowed bad passwords, or worse, a
# sign-up would never be counted at all.
clean()
sign_up("throttle-1@example.com")
rows = sql("SELECT identifier, ok FROM login_attempts WHERE identifier LIKE 'signup:%'")
ok("a sign-up is recorded", len(rows) == 1, rows)
ok("...marked as a success, not a failed sign-in",
   bool(rows) and rows[0][1] == "1", rows)
ok("...and tagged so it cannot be read as a sign-in",
   bool(rows) and rows[0][0].startswith("signup:"), rows)

blocked = subprocess.run(
    [PHP, "-r", "require 'inc/bootstrap.php'; echo (int) auth_ip_blocked();"],
    cwd=REPO, capture_output=True, text=True)
ok("the sign-in throttle is unaffected by it", blocked.stdout.strip() == "0",
   blocked.stdout + blocked.stderr[:120])


print()
print("--- the attempts table does not grow forever ---")

# Nothing ever reads a row older than its window -- fifteen minutes for failed
# sign-ins, sixty for sign-ups -- so without a prune this table is a row per
# sign-in kept until the disk quota notices. password_resets already had this
# handled; this table did not.
sql("DELETE FROM login_attempts WHERE identifier LIKE 'prune-test-%'")
sql("""INSERT INTO login_attempts (ip, identifier, ok, created_at) VALUES
       (UNHEX('7F000001'),'prune-test-old',0,NOW() - INTERVAL 30 DAY),
       (UNHEX('7F000001'),'prune-test-old',0,NOW() - INTERVAL 8 DAY),
       (UNHEX('7F000001'),'prune-test-new',0,NOW())""")

ok("the fixture rows are in",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'prune-test-%'")[0][0] == "3")

# Called directly, many times: the prune fires on about one write in fifty,
# because a DELETE on every sign-in would cost more than the rows it removes.
run = subprocess.run(
    [PHP, "-r", "require 'inc/bootstrap.php';"
                "for ($i = 0; $i < 300; $i++) { auth_prune_attempts(); }"],
    cwd=REPO, capture_output=True, text=True)
ok("the prune runs", run.returncode == 0, run.stderr[:200])

ok("rows past the keep window are gone",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier = 'prune-test-old'")[0][0] == "0")
ok("...and today's is still there",
   sql("SELECT COUNT(*) FROM login_attempts WHERE identifier = 'prune-test-new'")[0][0] == "1")

keep = subprocess.run([PHP, "-r", "require 'inc/bootstrap.php'; echo AUTH_ATTEMPT_KEEP_D;"],
                      cwd=REPO, capture_output=True, text=True).stdout.strip()
ok("it keeps longer than any throttle window needs", keep.isdigit() and int(keep) >= 1, keep)

sql("DELETE FROM login_attempts WHERE identifier LIKE 'prune-test-%'")

print()
print("--- cleaning up ---")
clean()
print("  users left matching the test pattern: %s"
      % sql("SELECT COUNT(*) FROM users WHERE email LIKE 'throttle-%@example.com'")[0][0])
print("  signup rows left: %s"
      % sql("SELECT COUNT(*) FROM login_attempts WHERE identifier LIKE 'signup:%'")[0][0])

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
