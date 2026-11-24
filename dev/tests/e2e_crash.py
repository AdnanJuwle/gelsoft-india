"""A failure on the live site must say what failed -- to staff, and only staff.

Found live: every Save on the admin order page answered a blank browser
"500 Internal Server Error". Production hides PHP errors (rightly), so the
cause was invisible. Now:
  - a refused order save shows the database's own message on the page;
  - anything else that throws on an /admin/ page shows the message to a
    signed-in admin, while a visitor anywhere gets a plain apology with no
    file names, paths or SQL.

Runs the site as PRODUCTION (env != local) for the duration, makes the
database refuse a save by shrinking one column, and drops a throwaway page
that throws. Everything is put back in `finally`.
"""
import os
import re
import shutil
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_whatsapp_lib import C, sql  # noqa: E402

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
CONFIG = os.path.join(REPO, "inc", "config.local.php")
BACKUP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.local.e2e_crash.bak")
CRASH_ADMIN = os.path.join(REPO, "admin", "zz-crash-test.php")
CRASH_PUBLIC = os.path.join(REPO, "zz-crash-test.php")
THROWER = "<?php\nrequire __DIR__ . '%s/inc/bootstrap.php';\nthrow new RuntimeException('crash-test-marker');\n"

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL %s %s" % (label, detail))


shutil.copyfile(CONFIG, BACKUP)
oid = None
try:
    s = open(BACKUP, encoding="utf-8").read()
    assert s.count("'env' => 'local'") == 1
    open(CONFIG, "w", encoding="utf-8", newline="").write(s.replace("'env' => 'local'", "'env' => 'production'"))
    open(CRASH_ADMIN, "w").write(THROWER % "/..")
    open(CRASH_PUBLIC, "w").write(THROWER % "")

    sql("DELETE FROM login_attempts")
    sql("INSERT INTO orders (reference, email, phone, ship_name, ship_line1, ship_city, ship_state, ship_pin, "
        "subtotal_paise, shipping_paise, total_paise, status, payment_provider, payment_id, paid_at) VALUES "
        "('GS-TEST-CRASH','crash@example.test','9876543210','Crash Test','1 Rd','Pune','Maharashtra','411001',"
        "100000,0,100000,'paid','whatsapp','UPI 412345678901',NOW())")
    oid = sql("SELECT id FROM orders WHERE reference='GS-TEST-CRASH'")

    adm = C()
    tok = adm.token("/admin/login")
    adm.post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123", "next": "/admin/"})

    print("--- an ordinary save still works ---")
    tok = adm.token("/admin/orders?id=%s" % oid)
    code, _, loc = adm.post("/admin/orders", {"_token": tok, "id": oid, "status": "packed",
                                              "courier": "Delhivery", "tracking": "", "admin_note": "fine"})
    check("paid -> packed saves and redirects", code == 302 and "saved=1" in (loc or ""), "%s %s" % (code, loc))
    check("...status is packed", sql("SELECT status FROM orders WHERE id=%s" % oid) == "packed")
    code, h, _ = adm.get(loc or "/")
    check("...and the page after it loads", code == 200 and "Saved." in h, str(code))

    print("\n--- the database refuses the save ---")
    sql("ALTER TABLE orders MODIFY admin_note VARCHAR(5) NULL")
    tok = adm.token("/admin/orders?id=%s" % oid)
    code, h, loc = adm.post("/admin/orders", {"_token": tok, "id": oid, "status": "shipped",
                                              "courier": "Delhivery", "tracking": "ABC123",
                                              "admin_note": "far too long for five"})
    check("no blank 500: the page answers", code == 200, "code=%s" % code)
    check("...saying it was not saved, with the database's reason",
          "Not saved. The database refused it:" in h and "admin_note" in h,
          re.sub(r"\s+", " ", h[h.find("Not saved"):h.find("Not saved") + 160]) if "Not saved" in h else "")
    check("...and nothing changed", sql("SELECT status FROM orders WHERE id=%s" % oid) == "packed")
    check("...and no dispatch email was attempted for a save that failed",
          sql("SELECT COUNT(*) FROM mail_log WHERE subject LIKE '%GS-TEST-CRASH%'") == "0")
    sql("ALTER TABLE orders MODIFY admin_note VARCHAR(500) NULL")

    print("\n--- anything else that throws ---")
    code, h, _ = adm.get("/admin/zz-crash-test.php")
    check("on an admin page, staff get a 500 WITH the message", code == 500 and "crash-test-marker" in h, str(code))
    check("...and the file name and line", "zz-crash-test.php line 3" in h)
    check("...but not the full server path", "repos" not in h and "\\" not in h.split("<pre")[1] if "<pre" in h else False)

    anon = C()
    code, h, _ = anon.get("/admin/zz-crash-test.php")
    check("the same admin page, not signed in: no message", code == 500 and "crash-test-marker" not in h, str(code))
    code, h, _ = adm.get("/zz-crash-test.php")
    check("a public page, even for staff: no message", code == 500 and "crash-test-marker" not in h and "try again" in h, str(code))
    code, h, _ = anon.get("/zz-crash-test.php")
    check("a public page for a visitor: a plain apology, nothing technical",
          code == 500 and "crash-test-marker" not in h and ".php" not in h and "try again" in h, str(code))
finally:
    shutil.copyfile(BACKUP, CONFIG)
    os.remove(BACKUP)
    for f in (CRASH_ADMIN, CRASH_PUBLIC):
        if os.path.exists(f):
            os.remove(f)
    sql("ALTER TABLE orders MODIFY admin_note VARCHAR(500) NULL")
    if oid:
        sql("DELETE FROM orders WHERE id=%s" % oid)

print("\n--- the live host's collation trap ---")
# A placeholder compared with a '' literal is "Illegal mix of collations" on
# the live server: that was every Save on the admin order page.
offenders = []
for root, _, files in os.walk(REPO):
    if ".git" in root or "reference" in root:
        continue
    for fn in files:
        if fn.endswith(".php"):
            for n, line in enumerate(open(os.path.join(root, fn), encoding="utf-8", errors="replace"), 1):
                code_part = line.split("//")[0]
                if re.search(r"(NULLIF|IFNULL|COALESCE|IF)\(\s*\?\s*,\s*\\?'", code_part) and not code_part.lstrip().startswith("*"):
                    offenders.append("%s:%d" % (fn, n))
check("no SQL compares a placeholder with a string literal", not offenders, offenders)
cfg_text = open(CONFIG, encoding="utf-8").read()
check("local runs simulate the live collation (db.simulate_collation is on)",
      "'simulate_collation' => 'utf8mb4_unicode_ci'" in cfg_text)

check("config, schema and throwaway pages all put back",
      not os.path.exists(CRASH_ADMIN) and not os.path.exists(CRASH_PUBLIC)
      and "varchar(500)" in sql("SHOW COLUMNS FROM orders LIKE 'admin_note'").lower())
print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
