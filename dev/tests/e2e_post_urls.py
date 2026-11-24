"""No POST may target a URL that .htaccess redirects.

This exists because "add to cart" was broken on the live site and worked
perfectly here. The forms posted to /api/cart.php; .htaccess 301s that to
/api/cart; and a browser repeats a 301 as a GET with the body dropped. The
endpoint then answered "Use POST."

It could not be caught by the other suites because the PHP dev server reads
router.php, which has no redirect rules at all -- so the one environment that
breaks is the one never exercised. Hence a static check of the rules against
the markup, rather than a request.

The same bug was waiting on Razorpay's webhook, where it would have cost
orders rather than a click.
"""
import pathlib
import re
import sys

REPO = pathlib.Path(r"C:\Users\adnan\Documents\repos\gelsoft-india")

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


ht = (REPO / ".htaccess").read_text(encoding="utf-8")

print("--- the rule that caused it ---")
block = re.search(r"# One URL per page.*?RewriteRule \^ /%1 \[L,R=301,NE\]", ht, re.S)
check("the canonical-URL rule is still there", block is not None)
if block:
    check("...and it now refuses to fire on a POST",
          "RewriteCond %{REQUEST_METHOD} !=POST" in block.group(0),
          "a 301 would turn the POST into a GET")

print("\n--- nothing posts to a redirected URL ---")
FORM = re.compile(r'<form[^>]*\bmethod=["\']post["\'][^>]*>', re.I)
ACTION = re.compile(r'\baction=["\']([^"\']+)["\']', re.I)

bad = []
for php in sorted(REPO.rglob("*.php")):
    if ".git" in php.parts:
        continue
    text = php.read_text(encoding="utf-8", errors="replace")
    for tag in FORM.findall(text):
        m = ACTION.search(tag)
        if m and m.group(1).split("?")[0].endswith(".php"):
            bad.append(f"{php.relative_to(REPO)}: action={m.group(1)}")

for b in bad:
    print(f"       {b}")
check("no POST form targets a .php URL", not bad, f"{len(bad)} form(s)")

print("\n--- nor does any POST from JavaScript ---")
jsbad = []
for js in sorted((REPO / "assets" / "js").rglob("*.js")):
    text = js.read_text(encoding="utf-8", errors="replace")
    # fetch('...') followed within a few lines by method: 'POST'
    for m in re.finditer(r"""fetch\(\s*['"]([^'"]+\.php[^'"]*)['"]\s*,(.{0,200}?)\)""", text, re.S):
        if "POST" in m.group(2).upper():
            jsbad.append(f"{js.relative_to(REPO)}: {m.group(1)}")

for b in jsbad:
    print(f"       {b}")
check("no fetch POSTs to a .php URL", not jsbad, f"{len(jsbad)} call(s)")

print("\n--- the webhook address we tell people to configure ---")
deploy = (REPO / "DEPLOY.md").read_text(encoding="utf-8")
m = re.search(r"URL: `(https://[^`]*webhook[^`]*)`", deploy)
check("DEPLOY.md gives a webhook URL", m is not None)
if m:
    check("...and it is the clean one, not .php", not m.group(1).endswith(".php"), m.group(1))

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
