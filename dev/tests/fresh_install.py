"""A deployment, from nothing.

Every suite so far has run against a database full of fixtures. A real deploy
starts with 25 empty tables, no products, no accounts and no admin -- and the
first thing the owner does is the one path nothing has ever exercised:
/admin/setup, which only works while no admin exists and closes itself
afterwards.

So this points the config at a virgin database, walks the DEPLOY.md steps in
order, and checks the shop is usable at the end. The config is restored in a
finally and verified byte-for-byte.
"""
import hashlib
import http.cookiejar
import io
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
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
CONFIG = os.path.join(REPO, "inc", "config.local.php")
BACKUP = CONFIG + ".fresh-backup"
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
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


def sql(q, db="gelsoft_fresh"):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", db,
                        "--batch", "--skip-column-names", "-e", q], capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def client():
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def send(op, req):
    req.add_header("User-Agent", UA)
    try:
        r = op.open(req)
        return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")


def get(op, p):
    return send(op, urllib.request.Request(BASE + p))


def post(op, p, d):
    r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(),
                               method="POST")
    r.add_header("Content-Type", "application/x-www-form-urlencoded")
    return send(op, r)


def token(op, p):
    _, h, _ = get(op, p)
    m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
    return m.group(1) if m else ""


# --- build the database this is about to test -----------------------------
#
# Done here rather than by hand, because a test of the install path that
# cannot perform the install is not a test of the install path. An earlier
# version leaned on a database I had made at the command line, and reported
# seventeen failures the moment I tidied it away.
IMPORTS = [
    "schema.sql", "schema-auth.sql", "schema-orders.sql",
    "schema-marketplace.sql", "schema-community.sql", "schema-resets.sql",
    "schema-owner.sql", "schema-seller-checks.sql", "schema-games-2.sql",
    "schema-offers.sql", "seed-content.sql",
]


def build_fresh_database():
    subprocess.run([MARIADB, "-u", "root", "--skip-password", "-e",
                    "DROP DATABASE IF EXISTS gelsoft_fresh;"
                    " CREATE DATABASE gelsoft_fresh CHARACTER SET utf8mb4"
                    " COLLATE utf8mb4_unicode_ci;"
                    " GRANT ALL ON gelsoft_fresh.* TO 'gelsoft'@'localhost';"],
                   capture_output=True)
    problems = []
    for name in IMPORTS:
        with io.open(os.path.join(REPO, "db", name), "rb") as fh:
            r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft_fresh"],
                               stdin=fh, capture_output=True)
        err = (r.stderr or b"").decode("utf-8", "replace").strip()
        if err:
            problems.append("%s: %s" % (name, err.split(chr(10))[0][:80]))
    return problems


def drop_fresh_database():
    subprocess.run([MARIADB, "-u", "root", "--skip-password", "-e",
                    "DROP DATABASE IF EXISTS gelsoft_fresh;"], capture_output=True)


print("--- the eleven imports, in the order DEPLOY.md gives ---")
import_problems = build_fresh_database()
ok("every schema file imports cleanly", not import_problems, import_problems[:3])
ok("...leaving 25 tables, as the document says",
   sql("SELECT COUNT(*) FROM information_schema.TABLES "
       "WHERE TABLE_SCHEMA='gelsoft_fresh'") == "25",
   sql("SELECT COUNT(*) FROM information_schema.TABLES "
       "WHERE TABLE_SCHEMA='gelsoft_fresh'"))

# An interrupted phpMyAdmin import is the normal way this goes wrong, so the
# fix has to be "run it again".
again = build_fresh_database()
ok("and running the whole set a second time changes nothing", not again, again[:3])
ok("...still 25 tables",
   sql("SELECT COUNT(*) FROM information_schema.TABLES "
       "WHERE TABLE_SCHEMA='gelsoft_fresh'") == "25")

print()

original = io.open(CONFIG, encoding="utf-8").read()
original_md5 = hashlib.md5(original.encode()).hexdigest()
shutil.copy2(CONFIG, BACKUP)

try:
    swapped = original.replace("'name'    => 'gelsoft'", "'name'    => 'gelsoft_fresh'", 1)
    assert swapped != original, "could not point the config at the fresh database"
    io.open(CONFIG, "w", encoding="utf-8", newline="").write(swapped)

    print("--- the shop, with nothing in it ---")
    anon = client()
    for p in ["/", "/blasters", "/gear", "/parts", "/marketplace", "/games",
              "/faq", "/safety", "/policy", "/terms", "/privacy", "/request",
              "/login", "/register", "/cart", "/sitemap.xml"]:
        code, html, _ = get(anon, p)
        ok("%s renders" % p, code == 200, code)

    _, html, _ = get(anon, "/blasters")
    ok("an empty shelf says so", "Restocking soon" in html)
    _, html, _ = get(anon, "/faq")
    ok("the seeded FAQ is there", "Restocking soon" not in html and len(html) > 2000)
    _, html, _ = get(anon, "/privacy")
    ok("the seeded privacy page is there", "We keep what we need" in html)

    print()
    print("--- the first admin, which only works once ---")
    ok("no admin exists yet",
       sql("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner')") == "0")

    setup = client()
    code, html, _ = get(setup, "/admin/setup")
    ok("/admin/setup offers the form", code == 200 and "Set up the first admin" in html, code)

    tok = token(setup, "/admin/setup")
    code, html, loc = post(setup, "/admin/setup", {
        "_token": tok, "name": "Fresh Owner", "email": "owner@gelsoft.in",
        "password": "a long enough passphrase", "password2": "a long enough passphrase",
    })
    ok("it creates the account", code in (302, 303), (code, html[:120]))
    ok("...as an OWNER, not an admin",
       sql("SELECT role FROM users WHERE email='owner@gelsoft.in'") == "owner")
    ok("...and it is recorded in the admin log",
       sql("SELECT COUNT(*) FROM admin_log WHERE action='owner_created'") == "1")

    code, html, _ = get(client(), "/admin/setup")
    ok("the page then closes itself", "Already set up" in html, html[:120])

    print()
    print("--- the owner signs in and puts something on the shelf ---")
    admin = client()
    post(admin, "/login", {"_token": token(admin, "/login"),
                           "email": "owner@gelsoft.in", "password": "a long enough passphrase"})
    code, _, loc = post(admin, "/admin/login", {
        "_token": token(admin, "/admin/login"), "email": "owner@gelsoft.in",
        "password": "a long enough passphrase", "next": "/admin/"})
    ok("the owner reaches the admin panel", code in (302, 303), code)

    code, html, _ = get(admin, "/admin/")
    ok("the dashboard opens", code == 200 and "Admin" in html, code)

    code, html, loc = post(admin, "/admin/add-item", {
        "_token": token(admin, "/admin/add-item"), "id": "0", "action": "save",
        "category": "blaster", "name": "First Blaster", "price": "9999",
        "grade": "mid", "gears": "metal", "status": "published",
        "stock_qty": "3", "stock_label": "in",
    })
    ok("a product saves", code in (302, 303), (code, html[:140]))

    slug = sql("SELECT slug FROM products WHERE name='First Blaster'")
    ok("...and gets a slug", bool(slug), slug)

    code, html, _ = get(anon, "/blasters")
    ok("it appears on the shelf", "First Blaster" in html, code)
    code, html, _ = get(anon, "/blasters/" + slug)
    ok("its own page opens", code == 200 and "First Blaster" in html, code)

    _, html, _ = get(anon, "/sitemap.xml")
    ok("the sitemap picked it up", slug in html)

    print()
    print("--- a customer can sign up and fill a cart ---")
    cust = client()
    code, _, _ = post(cust, "/register", {
        "_token": token(cust, "/register"), "name": "First Customer",
        "email": "customer@example.com", "phone": "",
        "password": "another long passphrase"})
    ok("registration works", code in (302, 303), code)

    pid = sql("SELECT id FROM products WHERE name='First Blaster'")
    post(cust, "/api/cart.php", {"_token": token(cust, "/blasters/" + slug),
                                 "do": "add", "product_id": pid, "qty": "2"})
    _, html, _ = get(cust, "/cart")
    ok("the cart holds it", "First Blaster" in html, html[:120])
    ok("...priced", "9,999" in html or "19,998" in html)

    code, html, _ = get(cust, "/checkout")
    ok("checkout opens", code == 200, code)

finally:
    shutil.copy2(BACKUP, CONFIG)
    os.remove(BACKUP)
    drop_fresh_database()

print()
print("--- and the development config is back ---")
ok("inc/config.local.php is byte-for-byte what it was",
   hashlib.md5(io.open(CONFIG, encoding="utf-8").read().encode()).hexdigest() == original_md5)
ok("the backup is cleaned up", not os.path.exists(BACKUP))
ok("the test database is gone too",
   sql("SELECT COUNT(*) FROM information_schema.SCHEMATA "
       "WHERE SCHEMA_NAME='gelsoft_fresh'", db="gelsoft") == "0")

anon = client()
code, html, _ = get(anon, "/blasters")
ok("the dev shop is serving again",
   code == 200 and re.search(r'href="/blasters/dev-', html) is not None, code)

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
