"""Escrow: who may move a sale, and what a write is allowed to assume.

This is the part of the site that holds somebody else's money, so the useful
assertions are the refusals. Three kinds:

  WHO -- the seller must not be able to release their own payout, the buyer
  must not be able to mark their own refund, and neither may do an admin's
  job. The SALE_MOVES table says who, and the table is checked here against
  what the code actually does rather than restated.

  WHEN -- a sale cannot jump states. Every pair that is not in the table has
  to be refused, which is 49 of the 56 possible pairs.

  WHAT A READ IS WORTH -- sale_release() used to read the sale, decide on what
  it read, and then write with a WHERE that did not pin that status. A buyer
  disputing in between had it overwritten and the seller was paid for an item
  the buyer had just said arrived broken.
"""
import os
import re
import subprocess
import sys

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

passed = failed = 0


def ok(label, cond, extra=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label + ("  -- " + str(extra) if extra else ""))


def sql(query):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--default-character-set=utf8mb4",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True)
    out = (r.stdout or b"").decode("utf-8", "replace").strip()
    return [l.split("\t") for l in out.split("\n") if l]


PRELUDE = ("require 'inc/bootstrap.php';"
           "require_once 'inc/listings.php';"
           "require_once 'inc/listing_sales.php';"
           "require_once 'inc/seller_checks.php';")


def php(code):
    return subprocess.run([PHP, "-r", PRELUDE + code], cwd=REPO,
                          capture_output=True, text=True)


def one(code):
    r = php(code)
    # sale_notify() prints nothing but error_log may; take the last line.
    out = [l for l in r.stdout.strip().split("\n") if l]
    return (out[-1] if out else ""), r.stderr


sql("DELETE FROM listing_orders WHERE reference LIKE 'ESC-%'")

listing_id, seller_id = sql(
    "SELECT id, seller_id FROM listings ORDER BY id LIMIT 1")[0]
buyer_id = sql(f"SELECT id FROM users WHERE id <> {seller_id} ORDER BY id LIMIT 1")[0][0]
admin_id = sql("SELECT id FROM users WHERE role IN ('admin','owner') ORDER BY id LIMIT 1")[0][0]

ok("there is a listing, a seller, a buyer and an admin",
   all([listing_id, seller_id, buyer_id, admin_id]),
   (listing_id, seller_id, buyer_id, admin_id))

MAKE = ("$id = null; $n = $argvn ?? 0;")


def make_sale(ref, status, confirm_by="NULL", inspected=0):
    sql(f"DELETE FROM listing_orders WHERE reference='{ref}'")
    sql(f"""INSERT INTO listing_orders
             (reference, listing_id, buyer_id, seller_id, price_paise, postage_paise,
              inspection_paise, total_paise, seller_fee_paise, handling_paise,
              payout_paise, inspected, status, ship_name, ship_phone, ship_line1,
              ship_city, ship_state, ship_pin, confirm_by)
            VALUES ('{ref}', {listing_id}, {buyer_id}, {seller_id}, 100000, 0, 0,
              100000, 8000, 2300, 89700, {inspected}, '{status}', 'Esc',
              '9876543210', '1 St', 'Pune', 'Maharashtra', '411001', {confirm_by})""")
    return int(sql(f"SELECT id FROM listing_orders WHERE reference='{ref}'")[0][0])


def status_of(sale_id):
    rows = sql(f"SELECT status FROM listing_orders WHERE id={sale_id}")
    return rows[0][0] if rows else "(gone)"


def events(sale_id):
    return int(sql(f"SELECT COUNT(*) FROM listing_order_events WHERE order_id={sale_id}")[0][0])


ACTORS = {
    "seller": f"['id' => {seller_id}, 'role' => 'customer']",
    "buyer":  f"['id' => {buyer_id}, 'role' => 'customer']",
    "admin":  f"q1(\"SELECT * FROM users WHERE id = {admin_id}\")",
    "stranger": "['id' => 999999, 'role' => 'customer']",
}


def move(sale_id, to, actor, note=""):
    out, err = one(f"$e = sale_move({sale_id}, '{to}', {ACTORS[actor]}, '{note}');"
                   f"echo $e === null ? 'OK' : $e;")
    return out, err


print()
print("--- the table in the code is the table being enforced ---")

out, err = one("foreach (SALE_MOVES as $from => $tos) {"
               "  foreach ($tos as $to => $who) { echo $from, '>', $to, '=', $who, ';'; }"
               "} echo '';")
pairs = {}
for bit in out.rstrip(";").split(";"):
    if "=" in bit and ">" in bit:
        fromto, who = bit.split("=")
        pairs[tuple(fromto.split(">"))] = who
ok("SALE_MOVES was read", len(pairs) > 10, (len(pairs), err[:150]))
ok("only three kinds of actor appear in it",
   set(pairs.values()) <= {"admin", "buyer", "seller"}, set(pairs.values()))
ok("releasing is an admin's job only",
   pairs.get(("delivered", "released")) == "admin"
   and pairs.get(("disputed", "released")) == "admin", pairs.get(("delivered", "released")))
ok("only a buyer can say it arrived",
   pairs.get(("shipped", "delivered")) == "buyer")
ok("only a buyer can dispute",
   pairs.get(("shipped", "disputed")) == "buyer"
   and pairs.get(("delivered", "disputed")) == "buyer")
ok("a seller can only send it, never settle it",
   {t for (f, t), w in pairs.items() if w == "seller"} == {"to_us", "shipped"},
   {t for (f, t), w in pairs.items() if w == "seller"})


print()
print("--- the wrong person is refused ---")

WRONG = [
    ("shipped", "delivered", "seller", "a seller confirming their own delivery"),
    ("shipped", "disputed", "seller", "a seller raising the buyer's dispute"),
    ("delivered", "released", "seller", "a seller releasing their own payout"),
    ("delivered", "released", "buyer", "a buyer releasing the payout"),
    ("held", "shipped", "buyer", "a buyer marking it shipped"),
    ("shipped", "delivered", "stranger", "somebody with no part in it"),
    ("held", "refunded", "seller", "a seller refunding to end a dispute"),
]
for n, (frm, to, actor, why) in enumerate(WRONG):
    # reference is VARCHAR(20), so these are numbered rather than named.
    sid = make_sale(f"ESC-W{n}", frm)
    before = events(sid)
    out, err = move(sid, to, actor)
    ok(f"{why} is refused", out != "OK", out or err[:120])
    ok("...and the sale has not moved", status_of(sid) == frm, status_of(sid))
    ok("...and nothing was written to the trail", events(sid) == before, events(sid))
    sql(f"DELETE FROM listing_orders WHERE id={sid}")


print()
print("--- the right person is allowed ---")

RIGHT = [
    ("shipped", "delivered", "buyer"),
    ("shipped", "disputed", "buyer"),
    ("held", "shipped", "seller"),
    ("delivered", "released", "admin"),
]
for n, (frm, to, actor) in enumerate(RIGHT):
    sid = make_sale(f"ESC-R{n}", frm,
                    confirm_by="DATE_SUB(NOW(), INTERVAL 1 HOUR)" if frm == "delivered" else "NULL")
    before = events(sid)
    if to == "released":
        out, err = one(f"$e = sale_release({sid}, {ACTORS[actor]}, false);"
                       f"echo $e === null ? 'OK' : $e;")
    else:
        out, err = move(sid, to, actor)
    ok(f"the {actor} may go {frm} -> {to}", out == "OK", out or err[:150])
    ok("...and it did", status_of(sid) == to, status_of(sid))
    ok("...and it is in the trail once", events(sid) - before == 1, events(sid) - before)
    sql(f"DELETE FROM listing_orders WHERE id={sid}")


print()
print("--- a sale cannot skip a step ---")

states = ["held", "to_us", "inspecting", "failed", "shipped", "delivered", "disputed", "released"]
illegal = [(f, t) for f in states for t in states if (f, t) not in pairs and f != t]
ok("there are illegal pairs to check", len(illegal) > 30, len(illegal))

bad = []
sid = make_sale("ESC-JUMP", "held")
for frm, to in illegal:
    sql(f"UPDATE listing_orders SET status='{frm}' WHERE id={sid}")
    out, _ = move(sid, to, "admin")
    if out == "OK":
        bad.append(f"{frm}->{to}")
ok(f"all {len(illegal)} of them are refused, even for an admin", not bad, bad[:6])
sql(f"DELETE FROM listing_orders WHERE id={sid}")


print()
print("--- a decision made on a stale read must not land ---")

# The bug: sale_release() read the status, checked "is it disputed?" against
# that, then wrote with `WHERE status <> 'released'`. A dispute arriving in
# between was overwritten and the seller was paid.
sid = make_sale("ESC-RACE", "delivered", confirm_by="DATE_SUB(NOW(), INTERVAL 1 HOUR)")
read = status_of(sid)
move(sid, "disputed", "buyer", "It arrived broken.")
ok("the buyer's dispute is recorded", status_of(sid) == "disputed", status_of(sid))

ok("...so the dispute survives", status_of(sid) == "disputed", status_of(sid))

# What used to be here was three checks that never ran the function: a UPDATE
# this file issued itself (a fact about MySQL), and two greps for strings that
# also appear in sale_move(). A mutation reverting the fix exactly was not
# noticed by any of them.
#
# Two processes instead. One takes the row lock, waits, disputes the sale and
# commits; meanwhile sale_release() is called in earnest. That is the
# interleaving that paid a seller over a dispute raised seconds earlier.
import subprocess as _sp
import time as _time

sql("DELETE FROM listing_orders WHERE reference = 'ESC-RACE2'")
race_id = make_sale("ESC-RACE2", "delivered",
                    confirm_by="DATE_SUB(NOW(), INTERVAL 1 HOUR)")

holder = _sp.Popen([PHP, os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                      "race_holder.php"), str(race_id), "2"],
                   cwd=REPO, stdout=_sp.PIPE, stderr=_sp.PIPE)
_time.sleep(0.6)          # let it take the lock before the release starts

answer, _err = one(f"$e = sale_release({race_id}, {ACTORS['admin']}, false);"
                   f"echo $e === null ? 'RELEASED' : $e;")
holder.wait(timeout=20)

ok("a release that collides with a dispute does not pay the seller",
   status_of(race_id) == "disputed", status_of(race_id))
# The MESSAGE matters, not just the outcome: without the lock this answers
# with an exception instead, which is a different kind of broken.
ok("...and the admin is told why, in words",
   "disputed" in answer.lower() and "settle it first" in answer.lower(),
   answer[:120])
ok("...and no payout is in the trail",
   "released" not in sql(f"SELECT IFNULL(GROUP_CONCAT(became),'') "
                         f"FROM listing_order_events WHERE order_id = {race_id}"))

sql(f"DELETE FROM listing_orders WHERE id = {race_id}")

src = open(os.path.join(REPO, "inc", "listing_sales.php"), encoding="utf-8").read()
release_body = src[src.index("function sale_release"):]
release_body = release_body[:release_body.index("\nfunction ")]
ok("the release itself reads the row FOR UPDATE",
   "FOR UPDATE" in release_body)
ok("...and sale_move checks what actually changed",
   "$changed = exec_sql" in src[src.index("function sale_move"):src.index("function sale_release")])

print()
print("--- the same payment confirmed twice at once ---")

# Razorpay delivers an event more than once, and sale_mark_held() says it is
# idempotent.
#
# Honest about what this shows: it starts two of them together and they
# serialise on their own, because two PHP processes take long enough to boot
# that no real contention happens. It passes with the row lock and without
# it. So it is NOT a test of the lock -- it is a test that two deliveries of
# one event leave one transition, one listing off the market and one row in
# the trail, which is the promise that matters.
#
# What makes that promise hold without depending on timing is the
# `AND status = 'pending'` on the UPDATE, which sale_move() and
# sale_release() already had and this one did not.
sql("DELETE FROM listing_orders WHERE reference = 'ESC-TWICE2'")
race2 = make_sale("ESC-TWICE2", "held")
sql(f"UPDATE listing_orders SET status = 'pending', payment_id = NULL WHERE id = {race2}")
sql(f"UPDATE listings SET status = 'live', sold_at = NULL WHERE id = {listing_id}")
before_events = events(race2)

CALL = (f"require 'inc/bootstrap.php';"
        f"require_once 'inc/listings.php';"
        f"require_once 'inc/listing_sales.php';"
        f"require_once 'inc/seller_checks.php';"
        f"echo (int) sale_mark_held({race2}, 'pay_same_event');")

procs = [_sp.Popen([PHP, "-r", CALL], cwd=REPO, stdout=_sp.PIPE, stderr=_sp.PIPE)
         for _ in range(2)]
results = []
for pr in procs:
    out, _ = pr.communicate(timeout=30)
    results.append(out.decode("utf-8", "replace").strip()[-1:])

ok("both deliveries ran", len(results) == 2, results)
ok("exactly one of them took the sale",
   sorted(results) == ["0", "1"], results)
ok("...and it is held, once", status_of(race2) == "held", status_of(race2))
ok("...the listing came off the market",
   sql(f"SELECT status FROM listings WHERE id = {listing_id}")[0][0] == "sold")
ok("...and the trail records one payment, not two",
   events(race2) - before_events == 1, events(race2) - before_events)

# The statement-level guarantee, which does not rely on two processes
# overlapping: a second confirmation of an already-held sale changes nothing.
second, _ = one(f"echo (int) sale_mark_held({race2}, 'pay_again');")
ok("confirming an already-held sale does nothing at all", second == "0", second)
ok("...and still only one row in the trail",
   events(race2) - before_events == 1, events(race2) - before_events)

src_held = open(os.path.join(REPO, "inc", "listing_sales.php"), encoding="utf-8").read()
held_body = src_held[src_held.index("function sale_mark_held"):]
held_body = held_body[:held_body.index("\nfunction ")]

# The STATEMENT, not the file and not the comment above it. An earlier version
# of this searched the whole function body for "AND status = 'pending'" -- and
# the comment explaining the guard contains that string, so deleting it from
# the SQL left the check green.
stmt = held_body[held_body.index("UPDATE listing_orders SET status = 'held'"):]
stmt = stmt[:stmt.index(";")]
ok("...because the write itself pins the status it read",
   "AND status = 'pending'" in stmt, stmt.replace("\n", " ")[:110])

sql(f"DELETE FROM listing_orders WHERE id = {race2}")
sql(f"UPDATE listings SET status = 'live', sold_at = NULL WHERE id = {listing_id}")

print()
print("--- releasing the same payout twice ---")

sid2 = make_sale("ESC-TWICE", "delivered", confirm_by="DATE_SUB(NOW(), INTERVAL 1 HOUR)")
before = events(sid2)
out1, _ = one(f"$e = sale_release({sid2}, {ACTORS['admin']}, false); echo $e === null ? 'OK' : $e;")
out2, _ = one(f"$e = sale_release({sid2}, {ACTORS['admin']}, false); echo $e === null ? 'OK' : $e;")
ok("the first release goes through", out1 == "OK", out1)
ok("the second is refused", out2 != "OK", out2)
ok("...and the payout appears in the trail exactly once",
   events(sid2) - before == 1, events(sid2) - before)

print()
print("--- one blaster, one buyer ---")

# A second-hand listing is one object. sale_create() locked the listing and
# re-checked its status, but nothing inside that transaction CHANGED the
# status -- a listing does not go 'sold' until the money clears -- so two
# buyers both passed the check and both paid. Measured before the fix:
# two sales, both 'held', Rs 8,200 each, and nothing saying anything was wrong.

LISTING = int(listing_id)
buyer2 = sql(f"SELECT id FROM users WHERE id NOT IN ({seller_id}, {buyer_id}) "
             f"ORDER BY id LIMIT 1")
buyer2 = buyer2[0][0] if buyer2 else None
ok("there is a second buyer to test with", buyer2 is not None)

SHIP = ("['name' => 'Buyer', 'phone' => '9876543210', 'line1' => '1 St',"
        " 'line2' => '', 'city' => 'Pune', 'state' => 'Maharashtra', 'pin' => '411001']")


def reset_listing():
    sql(f"DELETE FROM listing_orders WHERE listing_id = {LISTING}")
    sql(f"UPDATE listings SET status='live', sold_at=NULL WHERE id = {LISTING}")


def start_sale(buyer):
    out, err = one(
        f"$l = q1('SELECT * FROM listings WHERE id = {LISTING}');"
        f"$r = sale_create($l, {buyer}, {SHIP}, false);"
        f"echo isset($r['sale']) ? $r['sale']['reference'] : 'NO:' . $r['error'];")
    return out, err


def hold(ref, payment):
    sid = sql(f"SELECT id FROM listing_orders WHERE reference = '{ref}'")[0][0]
    out, _ = one(f"echo (int) sale_mark_held({sid}, '{payment}');")
    return sid, out


if buyer2:
    reset_listing()
    a, err = start_sale(buyer_id)
    ok("the first buyer gets a sale", a.startswith("MK-"), a or err[:150])

    b, _ = start_sale(buyer2)
    ok("the second is refused while the first is at the payment screen",
       b.startswith("NO:"), b)
    ok("...and told it may come back", "comes back" in b, b)
    ok("...so only one sale exists",
       sql(f"SELECT COUNT(*) FROM listing_orders WHERE listing_id = {LISTING}")[0][0] == "1")

    # The same person returning to their own checkout is not a second buyer.
    again, _ = start_sale(buyer_id)
    ok("the same buyer may still start again", again.startswith("MK-"), again)

    # A reservation with no end would hold a one-of-a-kind item for good.
    reset_listing()
    a, _ = start_sale(buyer_id)
    sql(f"UPDATE listing_orders SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) "
        f"WHERE reference = '{a}'")
    b, _ = start_sale(buyer2)
    ok("an abandoned checkout releases the item", b.startswith("MK-"), b)

    window = php("echo SALE_CHECKOUT_MINUTES;").stdout.strip()
    ok("the window is a real number of minutes",
       window.isdigit() and 5 <= int(window) <= 120, window)

    print()
    print("--- and a payment that clears late is flagged, not swallowed ---")

    # The reservation makes this rare, not impossible: the window expires, a
    # webhook is slow, somebody pays late. The money is real, so it has to be
    # recorded -- but the shop must not behave as though nothing happened.
    reset_listing()
    a, _ = start_sale(buyer_id)
    sql(f"UPDATE listing_orders SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) "
        f"WHERE reference = '{a}'")
    b, _ = start_sale(buyer2)
    ok("two sales now exist on one listing", a.startswith("MK-") and b.startswith("MK-"))

    sid_a, held_a = hold(a, "pay_first")
    ok("the first payment is held", held_a == "1", held_a)
    sold_at = sql(f"SELECT sold_at FROM listings WHERE id = {LISTING}")[0][0]
    ok("...and the listing is off the market",
       sql(f"SELECT status FROM listings WHERE id = {LISTING}")[0][0] == "sold")

    sid_b, held_b = hold(b, "pay_second")
    ok("the second payment is still recorded, because the money is real",
       held_b == "1", held_b)
    note = sql(f"SELECT IFNULL(admin_note,'') FROM listing_orders WHERE id = {sid_b}")
    note = note[0][0] if note else ""
    ok("...but the sale is flagged", "already sold" in note, note[:90])
    ok("...naming the sale that took the item", a in note, note[:90])
    ok("...and saying a refund is owed", "refund" in note.lower(), note[:90])

    # A sentinel, because sql() drops empty lines -- an empty admin_note comes
    # back as no rows at all, not as a row holding "".
    ok("the first sale is not flagged",
       sql(f"SELECT IFNULL(NULLIF(admin_note,''),'(none)') "
           f"FROM listing_orders WHERE id = {sid_a}")[0][0] == "(none)")
    ok("the moment the item was taken is not rewritten",
       sql(f"SELECT sold_at FROM listings WHERE id = {LISTING}")[0][0] == sold_at,
       sql(f"SELECT sold_at FROM listings WHERE id = {LISTING}")[0][0] + " vs " + sold_at)

    trail = sql(f"SELECT note FROM listing_order_events WHERE order_id = {sid_b} "
                f"ORDER BY id DESC LIMIT 1")
    ok("...and the trail says so too",
       bool(trail) and "already took this item" in trail[0][0], trail)

    src = open(REPO + r"\admin\marketplace.php", encoding="utf-8").read()
    ok("the admin screen prints the flag in the row",
       "$s['admin_note']" in src)

    reset_listing()

print()
print("--- cleaning up ---")
sql("DELETE FROM listing_orders WHERE reference LIKE 'ESC-%'")
left = sql("SELECT COUNT(*) FROM listing_orders WHERE reference LIKE 'ESC-%'")[0][0]
print(f"  sales left: {left}")

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
