"""The stock wording never contradicts the buy button.

Found live: a blaster with stock on the shelf, "Shows as" set to Pre-order,
read "Pre-order - dispatched in 24 hours" beside a working Add to cart, and
told Google InStock. Every quantity x label combination is checked on the
product page, the shelf card and the structured data; the test product is
put back exactly in `finally`.
"""
import json
import re
import subprocess
import sys
import urllib.request

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
BASE = "http://localhost:8099"
SLUG = "dev-tg-gen8-m4"

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL %s %s" % (label, detail))


DB = '$pdo=new PDO("mysql:host=127.0.0.1;dbname=gelsoft;charset=utf8mb4","gelsoft","devpass");'


def php(code, stdin=None):
    return subprocess.run(["php", "-r", DB + code], input=stdin, capture_output=True, text=True, check=True).stdout


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=20) as r:
        return r.read().decode("utf-8")


orig = json.loads(php('echo json_encode($pdo->query("SELECT stock_qty, stock_label FROM products WHERE id=1")->fetch(PDO::FETCH_ASSOC));'))


def set_stock(qty, label):
    php('$d=json_decode(stream_get_contents(STDIN),true);'
        '$pdo->prepare("UPDATE products SET stock_qty=?, stock_label=? WHERE id=1")->execute($d);',
        json.dumps([qty, label]))


def card_stock(shelf):
    m = re.search(r'<a class="prod" href="/blasters/' + SLUG + r'">.*?<span class="prod__foot">.*?</span><span class="(tag-\w+)">([^<]*)</span>', shelf, re.S)
    return (m.group(1), m.group(2)) if m else None


# qty, label -> (words, buyable, schema)
MATRIX = [
    (5, "pre",  "In stock",         True,  "InStock"),
    (5, "soon", "In stock",         True,  "InStock"),
    (5, "in",   "In stock",         True,  "InStock"),
    (2, "low",  "Only 2 left",      True,  "InStock"),
    (0, "pre",  "Pre-order",        False, "PreOrder"),
    (0, "soon", "Restocking soon",  False, "OutOfStock"),
    (0, "in",   "Out of stock",     False, "OutOfStock"),
    (0, "low",  "Out of stock",     False, "OutOfStock"),
]

try:
    for qty, label, words, buyable, schema in MATRIX:
        print("--- qty %d, shows as %r ---" % (qty, label))
        set_stock(qty, label)
        page = get("/blasters/" + SLUG)
        chip = re.search(r'<span class="chip chip--pill[^"]*">([^<]*)<', page)
        chip = chip.group(1) if chip else ""
        check("product page says %r" % words, chip.startswith(words), chip)
        check("...'dispatched in 24 hours' only when it can be bought",
              ("dispatched in 24 hours" in chip) == buyable, chip)
        check("...Add to cart %s" % ("present" if buyable else "absent"),
              ("data-add-to-cart" in page) == buyable)
        check("...Google told %s" % schema, "https://schema.org/" + schema in page)
        c = card_stock(get("/blasters"))
        check("shelf card says %r" % words, c is not None and c[1] == words, str(c))
        check("...green only when it can be bought",
              c is not None and (c[0] == "tag-ok") == (words == "In stock"), str(c))
        if not buyable:
            check("...no 'Pre-order' next to a buy button anywhere", not ("Pre-order" in page and "data-add-to-cart" in page))
finally:
    set_stock(orig["stock_qty"], orig["stock_label"])

after = json.loads(php('echo json_encode($pdo->query("SELECT stock_qty, stock_label FROM products WHERE id=1")->fetch(PDO::FETCH_ASSOC));'))
check("test product put back exactly", after == orig, str(after))
print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
