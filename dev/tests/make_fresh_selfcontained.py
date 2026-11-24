r"""fresh_install.py depended on a database I had created by hand.

I built gelsoft_fresh at the command line, ran the test against it, then
dropped it as part of tidying up -- and the next run reported 17 failures
against a database that no longer existed. The suites were fine and the config
was restored; the test was simply leaning on something outside itself.

A test of the INSTALL path that cannot perform the install is not much of a
test. It now drops, creates and imports the eleven files itself, exactly as
DEPLOY.md lists them, so the thing it is checking is the thing it does. It
also drops the database again at the end, so running it leaves nothing behind.
"""
import io
import os

p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fresh_install.py")
s = io.open(p, encoding="utf-8").read()

old = """original = io.open(CONFIG, encoding="utf-8").read()
original_md5 = hashlib.md5(original.encode()).hexdigest()
shutil.copy2(CONFIG, BACKUP)
"""

new = '''# --- build the database this is about to test -----------------------------
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
'''
assert s.count(old) == 1, "backup block not found"
s = s.replace(old, new, 1)

old = """finally:
    shutil.copy2(BACKUP, CONFIG)
    os.remove(BACKUP)
"""
new = """finally:
    shutil.copy2(BACKUP, CONFIG)
    os.remove(BACKUP)
    drop_fresh_database()
"""
assert s.count(old) == 1, "finally not found"
s = s.replace(old, new, 1)

old = '''ok("the backup is cleaned up", not os.path.exists(BACKUP))'''
new = '''ok("the backup is cleaned up", not os.path.exists(BACKUP))
ok("the test database is gone too",
   sql("SELECT COUNT(*) FROM information_schema.SCHEMATA "
       "WHERE SCHEMA_NAME='gelsoft_fresh'", db="gelsoft") == "0")'''
assert s.count(old) == 1, "backup assertion not found"
s = s.replace(old, new, 1)

io.open(p, "w", encoding="utf-8", newline="").write(s)
print("ok fresh_install.py")
