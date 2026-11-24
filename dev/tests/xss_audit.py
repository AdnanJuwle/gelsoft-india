"""Every value this site prints into a page, and whether it is escaped.

e() is used almost everywhere, which is the point: one miss is a hole, and a
miss is invisible next to nine hundred correct lines. So this lists ALL of
them and sorts them into what cannot be dangerous and what has to be read.

Safe without escaping: an integer, a count, a value from money() or another
helper that builds its own markup, a literal from a constant array.
Everything else needs e() or a reason.
"""
import glob
import io
import os
import re

os.chdir(r"C:\Users\adnan\Documents\repos\gelsoft-india")

files = sorted(
    glob.glob("*.php") + glob.glob("admin/*.php") + glob.glob("inc/*.php")
    + glob.glob("api/*.php"))

# Things that produce markup on purpose, or that cannot carry a tag.
SAFE_CALLS = (
    "e(", "money(", "schema_price(", "csrf_field(", "jsonld(", "asset(", "url(",
    "number_format(", "count(", "intdiv(", "round(", "max(", "min(", "abs(",
    "product_card(", "listing_card(", "message_line(", "game_card(",
    "sprintf(", "str_repeat(", "implode(", "nl2br(e(", "bytes_human(",
    "date(", "strtotime(", "ucfirst(", "htmlspecialchars(",
)
SAFE_CASTS = ("(int)", "(float)", "(bool)")

rows = []
for f in files:
    s = io.open(f, encoding="utf-8").read()
    for m in re.finditer(r"<\?=(.*?)\?>", s, re.S):
        expr = m.group(1).strip()
        line = s.count("\n", 0, m.start()) + 1
        rows.append((f, line, expr))

print("%d values printed across %d files\n" % (len(rows), len(files)))


def classify(expr):
    e = expr.strip()
    if any(c in e for c in SAFE_CASTS):
        return "cast"
    # A ternary printing two string literals is markup the page wrote itself.
    if re.fullmatch(r"[^?]*\?\s*'[^']*'\s*:\s*'[^']*'", e):
        return "literal"
    if e.startswith("e(") or e.startswith("nl2br(e("):
        return "escaped"
    for c in SAFE_CALLS:
        if e.startswith(c):
            return "helper"
    if re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])*", e):
        return "bare"
    return "other"


groups = {}
for f, line, expr in rows:
    groups.setdefault(classify(expr), []).append((f, line, expr))

for kind in ("escaped", "helper", "cast", "literal"):
    print("  %-8s %d" % (kind, len(groups.get(kind, []))))

print()
print("=== bare variables printed straight into the page ===")
for f, line, expr in groups.get("bare", []):
    print("  %s:%d  %s" % (f, line, expr))

print()
print("=== anything else, read by hand ===")
for f, line, expr in groups.get("other", []):
    e = expr.replace("\n", " ")
    e = re.sub(r"\s+", " ", e)
    print("  %s:%d  %s" % (f, line, e[:100]))
