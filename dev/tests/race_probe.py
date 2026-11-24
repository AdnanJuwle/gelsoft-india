"""The escrow race, run as two processes instead of asserted from the source.

The suite's existing checks for this do not touch the application. One runs
its own UPDATE and confirms MySQL honours a WHERE clause -- true whatever
sale_release() does. Two grep the source for strings that also appear in
sale_move(), so deleting them from sale_release() leaves both green. A
mutation that reverted the fix exactly was not noticed.

So: one process takes the sale's row lock, waits, disputes it and commits,
while another calls sale_release() in earnest. That is the interleaving that
paid a seller over a dispute raised seconds earlier.

With the fix, the release blocks on the lock, re-reads 'disputed' and refuses.
Without FOR UPDATE it reads 'delivered' straight away, passes the guard that
should have stopped it, and writes.
"""
import os
import subprocess
import sys
import time

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
SCRATCH = os.path.dirname(os.path.abspath(__file__))
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

PRELUDE = ("require 'inc/bootstrap.php';"
           "require_once 'inc/listings.php';"
           "require_once 'inc/listing_sales.php';"
           "require_once 'inc/seller_checks.php';")


def sql(q):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", q],
                       capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


def php(code):
    return subprocess.run([PHP, "-r", PRELUDE + code], cwd=REPO,
                          capture_output=True, text=True)


listing_id, seller_id = sql("SELECT id, seller_id FROM listings ORDER BY id LIMIT 1").split("\t")
buyer_id = sql(f"SELECT id FROM users WHERE id <> {seller_id} ORDER BY id LIMIT 1")
admin_id = sql("SELECT id FROM users WHERE role IN ('admin','owner') ORDER BY id LIMIT 1")

sql("DELETE FROM listing_orders WHERE reference = 'RACE-1'")
sql(f"""INSERT INTO listing_orders
         (reference, listing_id, buyer_id, seller_id, price_paise, postage_paise,
          inspection_paise, total_paise, seller_fee_paise, handling_paise,
          payout_paise, inspected, status, ship_name, ship_phone, ship_line1,
          ship_city, ship_state, ship_pin, confirm_by)
        VALUES ('RACE-1', {listing_id}, {buyer_id}, {seller_id}, 100000, 0, 0,
          100000, 8000, 2300, 89700, 0, 'delivered', 'Race', '9876543210',
          '1 St', 'Pune', 'Maharashtra', '411001',
          DATE_SUB(NOW(), INTERVAL 1 HOUR))""")
sid = sql("SELECT id FROM listing_orders WHERE reference = 'RACE-1'")

print("sale %s is delivered, and the buyer's 48 hours have run out" % sid)
print("so an admin is entitled to release it\n")

# The buyer's dispute, holding the row lock for two seconds first.
holder = subprocess.Popen([PHP, SCRATCH + r"\race_holder.php", sid, "2"],
                          cwd=REPO, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
time.sleep(0.6)      # let it take the lock before the release starts

print("  buyer is disputing it right now (holding the row)")
t0 = time.time()
r = php(f"$e = sale_release({sid}, q1('SELECT * FROM users WHERE id = {admin_id}'), false);"
        "echo $e === null ? 'RELEASED' : $e;")
waited = time.time() - t0
holder.wait(timeout=20)

out = [l for l in r.stdout.strip().split("\n") if l]
answer = out[-1] if out else "(no answer) " + r.stderr[:120]

print("  admin's release waited %.1fs for the lock" % waited)
print("  and answered: %s" % answer)
print("  the sale is now: %s" % sql(f"SELECT status FROM listing_orders WHERE id = {sid}"))

paid = sql(f"SELECT status FROM listing_orders WHERE id = {sid}") == "released"
print()
print("  %s" % ("THE SELLER WAS PAID OVER THE DISPUTE"
                if paid else "the dispute stood; nobody was paid"))

sql(f"DELETE FROM listing_orders WHERE id = {sid}")
sys.exit(1 if paid else 0)
