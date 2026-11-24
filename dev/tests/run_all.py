"""Run every suite, and stop the throttle turning one failure into twelve.

Three times today a full run came back with failures that were not
regressions. The shape each time: something earlier logs a run of failed
sign-ins -- a suite exercising a refusal, a mutation making every sign-in
fail, a fresh-install test creating accounts -- and twenty of those inside
fifteen minutes trips auth_ip_blocked(). Every suite after that cannot sign
in, and reports its own checks as broken.

The throttle is doing exactly what it should. It is the runner that was wrong
to leave it set between unrelated suites, so login_attempts is cleared before
each one. It stays a real table with real contents DURING a suite, so
e2e_signup still measures what it measures.

Each suite gets its own timeout and a crash is reported rather than
swallowed: "no summary" is a result, not a gap in the table.
"""
import os
import subprocess
import sys
import time

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

SCRATCH = os.path.dirname(os.path.abspath(__file__))
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

SUITES = [
    "e2e.py", "e2e_msg.py", "e2e_market.py", "e2e_pay.py", "e2e_games2.py",
    "e2e_community.py", "e2e_admin.py", "e2e_delete.py", "e2e_staff.py",
    "e2e_reset.py", "e2e_post_urls.py", "e2e_offers.py", "e2e_schema.py",
    "e2e_seo.py", "e2e_fields.py", "e2e_signup.py", "e2e_time.py",
    "e2e_escrow.py", "e2e_outage.py", "e2e_xss.py", "e2e_uploads.py",
    "e2e_photos.py", "e2e_richtext.py", "e2e_stock.py", "e2e_whatsapp.py", "e2e_crash.py", "fresh_install.py",
]
EXTRA = ["e2e_links.py", "a11y_headings.py"]


def reset_throttle():
    subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft", "-e",
                    "DELETE FROM login_attempts;"
                    " UPDATE users SET failed_logins = 0, locked_until = NULL;"],
                   capture_output=True)


total = failed_total = 0
broken = []
started = time.time()

for name in SUITES:
    reset_throttle()
    try:
        r = subprocess.run([sys.executable, "-I", os.path.join(SCRATCH, name)],
                           cwd=SCRATCH, capture_output=True, text=True, timeout=300)
        out = r.stdout + r.stderr
    except subprocess.TimeoutExpired:
        print("  %-20s TIMED OUT" % name)
        broken.append(name + " (timed out)")
        continue

    line = ""
    for l in out.strip().split("\n"):
        if " passed, " in l and l.strip()[:1].isdigit():
            line = l.strip()
    if not line:
        print("  %-20s NO SUMMARY -- it crashed" % name)
        print("      " + "\n      ".join(out.strip().split("\n")[-3:]))
        broken.append(name + " (crashed)")
        continue

    p = int(line.split(" passed")[0])
    f = int(line.split("passed, ")[1].split(" failed")[0])
    total += p
    failed_total += f
    print("  %-20s %4d passed, %d failed" % (name, p, f))
    if f:
        broken.append(name)
        for l in out.split("\n"):
            if "FAIL" in l:
                print("      " + l.strip())

for name in EXTRA:
    reset_throttle()
    r = subprocess.run([sys.executable, "-I", os.path.join(SCRATCH, name)],
                       cwd=SCRATCH, capture_output=True, text=True, timeout=300)
    tail = [l for l in (r.stdout or "").strip().split("\n") if l.strip()]
    print("  %-20s %s" % (name, tail[-1].strip() if tail else "(no output)"))

reset_throttle()

print()
print("TOTAL: %d passed, %d failed across %d suites, in %.0fs"
      % (total, failed_total, len(SUITES), time.time() - started))
print("problems: %s" % (", ".join(broken) if broken else "none"))
sys.exit(1 if (failed_total or broken) else 0)
