"""Audit every visible form field on the storefront.

Two things a mobile shopper in India actually feels:

  autocomplete -- without it a password manager cannot fill the field and the
  browser cannot offer the address it already has, so every checkout is typed
  from scratch on a phone keyboard.

  inputmode -- a pincode or an amount typed on a QWERTY keyboard is six taps
  of hunting for the number row. inputmode="numeric" is one attribute.

Hidden inputs, submits, checkboxes and radios are skipped: none of them is
typed into.
"""
import glob
import io
import os
import re

os.chdir(r"C:\Users\adnan\Documents\repos\gelsoft-india")

SKIP_TYPES = {"hidden", "submit", "button", "checkbox", "radio", "file", "image", "reset"}

# field name -> the autocomplete token it should carry
EXPECT = {
    "email": "email",
    "name": "name",
    "phone": "tel",
    "line1": "address-line1",
    "line2": "address-line2",
    "city": "address-level2",
    "state": "address-level1",
    "pin": "postal-code",
    "pincode": "postal-code",
    "password": None,   # depends on new vs current; checked by hand below
}
NUMERIC = ("pin", "pincode", "qty", "quantity", "price", "amount", "otp")

files = sorted(glob.glob("*.php") + glob.glob("admin/*.php") + glob.glob("inc/*.php"))

rows = []
for f in files:
    s = io.open(f, encoding="utf-8").read()
    line_of = lambda i: s.count("\n", 0, i) + 1
    for m in re.finditer(r"<(input|textarea|select)\b[^>]*>", s, re.S):
        tag = m.group(0)
        kind = m.group(1)
        t = re.search(r'type="([a-z]+)"', tag)
        t = t.group(1) if t else ("text" if kind == "input" else kind)
        if t in SKIP_TYPES:
            continue
        nm = re.search(r'name="([^"]+)"', tag)
        nm = nm.group(1) if nm else ""
        rows.append({
            "file": f, "line": line_of(m.start()), "kind": kind, "type": t,
            "name": nm, "tag": tag,
            "ac": (re.search(r'autocomplete="([^"]*)"', tag) or [None, None])[1],
            "im": (re.search(r'inputmode="([^"]*)"', tag) or [None, None])[1],
            "id": (re.search(r'\bid="([^"]*)"', tag) or [None, None])[1],
        })

print("%d typed-into fields across %d files\n" % (rows.__len__(), len(files)))

print("=== missing autocomplete where a token clearly applies ===")
n = 0
for r in rows:
    base = r["name"].strip("[]").lower()
    want = EXPECT.get(base)
    if r["type"] == "email":
        want = want or "email"
    if r["type"] == "tel":
        want = want or "tel"
    if want and r["ac"] != want:
        n += 1
        print("  %s:%d  name=%-12s type=%-6s autocomplete=%s  want=%s"
              % (r["file"], r["line"], r["name"], r["type"], r["ac"], want))
print("  (none)" if not n else "")

print("=== password fields and their autocomplete ===")
for r in rows:
    if r["type"] == "password":
        print("  %s:%d  name=%-10s autocomplete=%s" % (r["file"], r["line"], r["name"], r["ac"]))

print()
print("=== numeric-looking fields without inputmode ===")
n = 0
for r in rows:
    base = r["name"].strip("[]").lower()
    if r["kind"] != "input":
        continue
    if any(k in base for k in NUMERIC) and not r["im"] and r["type"] not in ("number",):
        n += 1
        print("  %s:%d  name=%-12s type=%-6s" % (r["file"], r["line"], r["name"], r["type"]))
print("  (none)" if not n else "")

print()
print("=== fields with an id but no <label for>, or no id at all ===")
labels = {}
for f in files:
    s = io.open(f, encoding="utf-8").read()
    labels[f] = set(re.findall(r'<label[^>]*\bfor="([^"]+)"', s))
n = 0
for r in rows:
    if r["type"] == "search":
        continue  # often labelled by aria-label; checked separately
    if not r["id"]:
        n += 1
        print("  %s:%d  no id       name=%s" % (r["file"], r["line"], r["name"]))
    elif r["id"] not in labels[r["file"]]:
        # an id built from PHP (id="q-<?= ... ?>") cannot be matched statically
        if "<?" in r["id"]:
            continue
        if 'aria-label' in r["tag"]:
            continue
        n += 1
        print("  %s:%d  id=%-14s has no <label for>  name=%s"
              % (r["file"], r["line"], r["id"], r["name"]))
print("  (none)" if not n else "")
