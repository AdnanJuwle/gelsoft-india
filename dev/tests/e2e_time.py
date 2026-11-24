"""One clock, whatever the host is set to.

This app computes some deadlines in SQL and tests them in PHP (the marketplace
inspection window), and writes others in PHP and filters them in SQL (the
games board). Every one of those crossings is wrong by the gap between the two
clocks, and nothing in the code made them agree -- PHP was pinned to
Asia/Kolkata and MySQL was left to the host.

The reason this went unnoticed is the reason it needs a test: the development
machine is itself Asia/Calcutta, so the gap here is zero and the bug is
invisible. Measured against a session forced to UTC, a 48 hour window read
back as 42.5.

So the assertions below are mostly about the MECHANISM rather than the
arithmetic: the arithmetic is right on any machine where the clocks agree, and
the only thing worth guarding is that they are made to agree on purpose.
"""
import re
import subprocess
import sys

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")

passed = failed = 0


def ok(label, cond, extra=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label + ("  -- " + str(extra) if extra else ""))


def php(code):
    return subprocess.run([PHP, "-r", code], cwd=REPO, capture_output=True, text=True)


print("--- the connection carries a time zone ---")

r = php("require 'inc/bootstrap.php';"
        "$x = q1('SELECT @@session.time_zone tz, NOW() n');"
        "echo $x['tz'], '|', $x['n'], '|', date('Y-m-d H:i:s'), '|', date('P');")
ok("the probe ran", r.returncode == 0, r.stderr[:200])
parts = r.stdout.split("|")
ok("it returned four values", len(parts) == 4, r.stdout)

if len(parts) == 4:
    tz, mysql_now, php_now, offset = parts

    ok("the session time zone is set, not left to the host",
       tz not in ("SYSTEM", ""), tz)
    ok("...to PHP's own offset", tz == offset, tz + " vs " + offset)

    # A NAME would need the mysql.time_zone tables, which shared hosting
    # commonly leaves empty, and then every connection errors.
    ok("...as a numeric offset, not a zone name",
       re.fullmatch(r"[+-]\d{2}:\d{2}", tz) is not None, tz)

    # Compared as text first, then as a gap, because a five and a half hour
    # difference is the whole failure and a second of clock drift between two
    # subprocess calls is not.
    gap = php("echo abs(strtotime('%s') - strtotime('%s'));" % (mysql_now, php_now))
    gap = int(gap.stdout) if gap.stdout.strip().isdigit() else -1
    ok("MySQL and PHP now say the same thing", 0 <= gap <= 2,
       "%s vs %s (%ss apart)" % (mysql_now, php_now, gap))


print()
print("--- a deadline survives the crossing ---")

# listing_sales.php sets confirm_by with DATE_ADD(NOW(), INTERVAL n HOUR) and
# then admin/marketplace.php and listing_sales.php both test it with
# strtotime(...) > time(). That is SQL out, PHP in.
r = php("require 'inc/bootstrap.php';"
        "$d = q1('SELECT DATE_ADD(NOW(), INTERVAL 48 HOUR) d')['d'];"
        "printf('%.3f', (strtotime($d) - time()) / 3600);")
hours = float(r.stdout) if r.stdout.replace(".", "").replace("-", "").isdigit() else -1
ok("48 hours set in SQL is 48 hours measured in PHP",
   47.99 <= hours <= 48.01, "%.3f hours" % hours)

# games.php writes starts_at with date(), and inc/community.php filters it
# with NOW() in SQL. That is PHP out, SQL in.
r = php("require 'inc/bootstrap.php';"
        "$t = date('Y-m-d H:i:s', time() + 3600);"
        "$x = q1('SELECT (? > NOW()) future, TIMESTAMPDIFF(MINUTE, NOW(), ?) mins', [$t, $t]);"
        "echo $x['future'], '|', $x['mins'];")
parts = r.stdout.split("|")
ok("a time PHP calls an hour away is in the future to SQL",
   parts[0:1] == ["1"], r.stdout + r.stderr[:120])
ok("...and SQL agrees it is about sixty minutes",
   len(parts) > 1 and parts[1].lstrip("-").isdigit() and 59 <= int(parts[1]) <= 60,
   r.stdout)


print()
print("--- the real deadlines in the app ---")

# require_once, not require: listing_sales.php already pulls offers.php in
# that way, and a plain require afterwards re-includes it and fatals on the
# redeclared function. The app itself gets this right everywhere; this script
# did not.
r = php("require_once 'inc/bootstrap.php';"
        "require_once 'inc/listing_sales.php'; require_once 'inc/offers.php';"
        "echo SALE_CONFIRM_HOURS, '|', OFFER_HOLD_HOURS;")
parts = r.stdout.split("|")
ok("the two hold windows are defined", len(parts) == 2 and all(p.isdigit() for p in parts),
   r.stdout + r.stderr[:160])

if len(parts) == 2 and all(p.isdigit() for p in parts):
    for hrs, what in [(int(parts[0]), "the inspection window"),
                      (int(parts[1]), "an offer's hold")]:
        r = php("require 'inc/bootstrap.php';"
                "$d = q1('SELECT DATE_ADD(NOW(), INTERVAL %d HOUR) d')['d'];"
                "printf('%%.3f', (strtotime($d) - time()) / 3600);" % hrs)
        got = float(r.stdout) if r.stdout and r.stdout[0].isdigit() else -1
        ok("%s is really %d hours" % (what, hrs), abs(got - hrs) < 0.02,
           "%.3f" % got)


print()
print("--- and setting it can never take the site down ---")

# This is the one that mattered. The clock fix shipped without a try/catch,
# and on a host that refuses the statement the exception came out of db() --
# called by every page -- so db_ready() answered "no database" and a working
# site read as a credentials problem.
#
# Forced rather than asserted from the source: a catch in the wrong place
# would satisfy a grep and still bring the site down.

import http.cookiejar
import io
import os
import shutil
import urllib.error
import urllib.parse
import urllib.request

DB_PHP = os.path.join(REPO, "inc", "db.php")
BACKUP = DB_PHP + ".tz-backup"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"

_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def fetch(path):
    req = urllib.request.Request("http://localhost:8099" + path)
    req.add_header("User-Agent", UA)
    try:
        r = _opener.open(req)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except Exception as e:
        return 0, str(e)


original = io.open(DB_PHP, encoding="utf-8").read()
ok("db.php sets the session time zone at all", "SET time_zone" in original)
shutil.copy2(DB_PHP, BACKUP)

try:
    # Targeted at the exec CALL, which exists only in code.
    #
    # Two earlier versions of this got it wrong and both passed anyway. One
    # edited `$offset = date('P');`, which the broken version does not
    # contain. One replaced "SET time_zone", whose first occurrence is in the
    # comment above the statement -- so the file kept working and the test
    # was green against the version that took the site down.
    NEEDLE = '$pdo->exec("SET time_zone'
    ok("the statement is where this test thinks it is",
       original.count(NEEDLE) == 1, original.count(NEEDLE))

    broken = original.replace(NEEDLE, '$pdo->exec("SET no_such_variable', 1)
    assert broken != original, "could not force the failure"
    io.open(DB_PHP, "w", encoding="utf-8", newline="").write(broken)

    code, html = fetch("/blasters")
    ok("the shop still serves when the host refuses the time zone", code == 200, code)
    ok("...with products on it, not the empty state",
       re.search(r'href="/blasters/[a-z0-9-]+"', html) is not None,
       "fell back to the no-database page")

    # /admin/login prints "No database" on a plain GET when db_ready() is
    # false, so it is the page that can actually fail this. /login only says
    # anything on a POST, which made the same check there a green tick that
    # could never go red.
    code, html = fetch("/admin/login")
    ok("the admin sign-in still renders", code == 200, code)
    ok("...and does not say there is no database",
       "No database" not in html, "it reported no database")

finally:
    shutil.copy2(BACKUP, DB_PHP)
    os.remove(BACKUP)

ok("inc/db.php is back exactly as it was",
   io.open(DB_PHP, encoding="utf-8").read() == original)
ok("...and the backup is cleaned up", not os.path.exists(BACKUP))

code, html = fetch("/blasters")
ok("the shop is still fine afterwards",
   code == 200 and re.search(r'href="/blasters/[a-z0-9-]+"', html) is not None, code)


print()
print("--- it is set in one place ---")

src = open(REPO + r"\inc\db.php", encoding="utf-8").read()
ok("inc/db.php sets it on connect", "SET time_zone" in src)
ok("...from PHP's own offset rather than a literal", "date('P')" in src)

# Thirty correct call sites and a thirty-first that forgets is the failure
# mode this avoids; if anything starts setting it per query, say so loudly.
import glob
import os
others = []
for f in glob.glob(os.path.join(REPO, "*.php")) + glob.glob(os.path.join(REPO, "inc", "*.php")) \
        + glob.glob(os.path.join(REPO, "admin", "*.php")) + glob.glob(os.path.join(REPO, "api", "*.php")):
    if os.path.basename(f) == "db.php":
        continue
    if "SET time_zone" in open(f, encoding="utf-8").read():
        others.append(os.path.basename(f))
ok("and nowhere else sets it per query", not others, others)

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
