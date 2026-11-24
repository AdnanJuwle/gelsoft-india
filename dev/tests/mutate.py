"""Break each thing the suites claim to protect, and see who notices.

Today a regression test passed three times in a row against the very version
that had taken the live site down -- it edited a line that was not there, then
matched a comment instead of the statement, then checked a page that cannot
display the failure. Each time it was green and each time it was testing
nothing.

That is not a one-off. Four or five checks have turned out this way in a
single day, which means the honest question is not "do the suites pass" but
"can they fail". The only way to answer it is to break the thing on purpose
and watch.

Every mutation is a plausible regression -- a guard deleted, a window set to
nothing, a check reverted to what it was before -- not a syntax error. A
syntax error would be caught by anything.

Each file is hashed before, restored in a finally, and verified byte-for-byte
afterwards. If a restore ever fails this stops immediately rather than
carrying on over a broken tree.
"""
import hashlib
import io
import os
import subprocess
import sys

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
SCRATCH = os.path.dirname(os.path.abspath(__file__))
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

# name, file, find, replace, which suites ought to notice
MUTATIONS = [
    (
        "a thread can be opened about a listing nobody reviewed",
        "inc/messages.php",
        "    if ($listing['status'] !== 'live') {\n        return null;\n    }\n",
        "",
        ["e2e_msg.py"],
    ),
    (
        # The load-bearing one. Measured separately: dropping the PIN
        # while the lock stays changes nothing, so that is not a
        # regression and nothing should catch it. The lock is what makes
        # the read trustworthy.
        "releasing escrow stops locking the row it reads",
        "inc/listing_sales.php",
        # Four spaces: sale_release()'s read is at the top level of the
        # function. sale_mark_held()'s is inside a try at eight.
        "\n    $sale = q1('SELECT * FROM listing_orders WHERE id = ? FOR UPDATE', [$sale_id]);",
        "\n    $sale = q1('SELECT * FROM listing_orders WHERE id = ?', [$sale_id]);",
        ["e2e_escrow.py"],
    ),
    (
        # KNOWN NOT CAUGHT, and left here deliberately.
        #
        # Found by accident -- the escrow mutation above was landing here
        # instead, and nothing said a word. Chasing it produced a real fix
        # (sale_mark_held() now pins its write with AND status = 'pending',
        # which its two sibling functions already did), and that covers the
        # sequential case.
        #
        # The LOCK only matters under genuine concurrency, and I could not
        # provoke any: two PHP processes take long enough to start that they
        # serialise on their own, so a test written for it passes with the
        # lock and without. A blind spot that says so is worth more than a row
        # quietly deleted.
        "confirming a payment stops locking the sale it is confirming",
        "inc/listing_sales.php",
        "\n        $sale = q1('SELECT * FROM listing_orders WHERE id = ? FOR UPDATE', [$sale_id]);",
        "\n        $sale = q1('SELECT * FROM listing_orders WHERE id = ?', [$sale_id]);",
        ["e2e_market.py", "e2e_escrow.py"],
    ),
    (
        "stock is never put back",
        "inc/orders.php",
        "const ORDER_STOCK_HELD = ['paid', 'packed'];",
        "const ORDER_STOCK_HELD = [];",
        ["e2e_pay.py"],
    ),
    (
        "a listing is no longer reserved during checkout",
        "inc/listing_sales.php",
        "const SALE_CHECKOUT_MINUTES = 30;",
        "const SALE_CHECKOUT_MINUTES = 0;",
        ["e2e_escrow.py"],
    ),
    (
        "checkout stops checking field lengths",
        "checkout.php",
        "        $errors = array_merge($errors, length_errors($form, [",
        "        $errors = array_merge($errors, [] ?: length_errors([], [",
        ["e2e_fields.py"],
    ),
    (
        "sign-up is unthrottled again",
        "inc/auth.php",
        "const AUTH_SIGNUP_WINDOW_M = 60;",
        "const AUTH_SIGNUP_WINDOW_M = 0;",
        ["e2e_signup.py"],
    ),
    (
        "a javascript: link is accepted again",
        "inc/helpers.php",
        "    return ($scheme === 'http' || $scheme === 'https') ? $url : null;",
        "    return $url;",
        ["e2e_xss.py"],
    ),
    (
        "the order link is keyed on the payment secret again",
        "inc/orders.php",
        "(string) (cfg('app.secret') ?: cfg('db.pass'))), 0, 32);",
        "(string) (cfg('razorpay.key_secret') ?: cfg('db.pass'))), 0, 32);",
        ["e2e_pay.py"],
    ),
    (
        "an image of any size is opened",
        "inc/uploads.php",
        "    return max(1000000, min(UPLOAD_MAX_PIXELS, $px));",
        "    return PHP_INT_MAX;",
        ["e2e_uploads.py"],
    ),
    (
        "sign-in blames the password during an outage again",
        "login.php",
        "    } elseif (!db_ready()) {",
        "    } elseif (false) {",
        ["e2e_outage.py"],
    ),
]


def sql(q):
    subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft", "-e", q],
                   capture_output=True)


def digest(path):
    return hashlib.sha256(io.open(path, "rb").read()).hexdigest()


def run_suite(name):
    """True when the suite reports at least one failure."""
    try:
        r = subprocess.run([sys.executable, "-I", os.path.join(SCRATCH, name)],
                           cwd=SCRATCH, capture_output=True, text=True, timeout=180)
    except subprocess.TimeoutExpired:
        # A suite that cannot finish has noticed something, and it must not
        # take the harness down -- the restore still has to run.
        return True, "timed out, which counts as noticing"
    out = r.stdout + r.stderr
    for line in out.strip().split("\n"):
        if " passed, " in line and line.strip()[0].isdigit():
            failed = int(line.split("passed, ")[1].split(" failed")[0])
            return failed > 0, line.strip()
    return True, "no summary (crashed, which counts as noticing)"


print("checking the tree is clean before starting")
originals = {}
for _, path, _, _, _ in MUTATIONS:
    full = os.path.join(REPO, path)
    originals[path] = (digest(full), io.open(full, encoding="utf-8").read())
print("  %d files hashed\n" % len(originals))

unnoticed = []
for name, path, find, replace, suites in MUTATIONS:
    full = os.path.join(REPO, path)
    before_hash, before_text = originals[path]

    hits = before_text.count(find)
    if hits != 1:
        # Not "did it match" but "did it match the ONE place meant". A find
        # string that appears twice silently mutates whichever comes first,
        # and then reports the result against a suite that covers the other.
        # That happened, and it is why this check exists.
        print("  !! %-58s AMBIGUOUS (%d matches in %s)" % (name, hits, path))
        unnoticed.append((name, "%d matches, so the mutation is not pinned" % hits))
        continue

    try:
        io.open(full, "w", encoding="utf-8", newline="").write(
            before_text.replace(find, replace, 1))

        # Each broken run logs failed sign-ins, and twenty of those in fifteen
        # minutes trips the IP throttle and breaks every suite after it -- for
        # the wrong reason. Cleared between mutations.
        sql("DELETE FROM login_attempts; UPDATE users SET failed_logins=0, locked_until=NULL;")

        caught, detail = False, ""
        for suite in suites:
            c, d = run_suite(suite)
            if c:
                caught, detail = True, "%s: %s" % (suite, d)
                break
            detail = "%s: %s" % (suite, d)
    finally:
        io.open(full, "w", encoding="utf-8", newline="").write(before_text)
        assert digest(full) == before_hash, "RESTORE FAILED for " + path

    print("  %-58s %s" % (name, "caught" if caught else "NOT CAUGHT"))
    print("      %s" % detail)
    if not caught:
        unnoticed.append((name, detail))

print()
sql("DELETE FROM login_attempts; UPDATE users SET failed_logins=0, locked_until=NULL;")

for path, (h, _) in originals.items():
    assert digest(os.path.join(REPO, path)) == h, "file not restored: " + path
print("every file restored byte-for-byte")

print()
if unnoticed:
    print("%d mutation(s) nobody noticed:" % len(unnoticed))
    for name, detail in unnoticed:
        print("  - %s\n      %s" % (name, detail))
else:
    print("every mutation was caught")
