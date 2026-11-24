"""The functions the XSS sweep waves through, and whether they earn it.

e2e_xss.py treats a list of helpers as safe to print without e(), on the
grounds that they build their own markup and escape inside. That reasoning was
never checked. A helper returning a database field raw is invisible to the
sweep BY DESIGN, because it stops looking the moment it sees the name.

The first version of this flagged nine things and eight were noise -- fields
that sit inside e() further along the same line. The dangerous shape is
narrower than "a field appears": it is a field CONCATENATED into a string
without passing through anything. That is what this looks for.
"""
import glob
import io
import os
import re

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"

HELPERS = ["message_line", "product_card", "listing_card", "grade_mark",
           "shop_option", "offer_line_suffix", "stock_note", "fps_label",
           "gears_label", "type_label", "condition_label"]

# A field glued straight onto a string, either side. `'<b>' . $p['name']` or
# `$p['name'] . '</b>'`. Anything wrapped -- e(...), money(...), a cast --
# does not match, because then the thing next to the dot is a ')' or a space
# after the call, not the field itself.
GLUED = re.compile(r"""(?:['"]\s*\.\s*(\$[a-z_]+\[['"][a-z_]+['"]\]))"""
                   r"""|(?:(\$[a-z_]+\[['"][a-z_]+['"]\])\s*\.\s*['"])""")

# ...and a bare return of one.
RETURNED = re.compile(r"return\s+(?:\(string\)\s*)?(\$[a-z_]+\[['\"][a-z_]+['\"]\])"
                      r"|=>\s*(?:\(string\)\s*)?(\$[a-z_]+\[['\"][a-z_]+['\"]\])\s*,")

bodies = {}
for f in glob.glob(os.path.join(REPO, "inc", "*.php")):
    s = io.open(f, encoding="utf-8").read()
    for fn in HELPERS:
        m = re.search(r"\nfunction " + fn + r"\s*\(", s)
        if not m:
            continue
        rest = s[m.start() + 1:]
        nxt = rest.find("\nfunction ")
        bodies[fn] = (os.path.basename(f), rest[:nxt] if nxt > 0 else rest)

print("checking %d helpers\n" % len(bodies))

problems = []
for fn in HELPERS:
    if fn not in bodies:
        continue
    where, body = bodies[fn]
    hits = []
    for m in GLUED.finditer(body):
        hits.append(m.group(1) or m.group(2))
    for m in RETURNED.finditer(body):
        hits.append((m.group(1) or m.group(2)) + "  (returned bare)")

    print("  %-18s %-16s %s" % (fn, where, "clean" if not hits else "RAW: " + ", ".join(hits)))
    if hits:
        problems.append((fn, where, hits))

print()
if problems:
    print("%d helper(s) hand a database field out unescaped:" % len(problems))
    for fn, where, hits in problems:
        print("  %s (%s): %s" % (fn, where, ", ".join(hits)))
    print()
    print("Whether that is a hole depends on the CALL SITE:")
    for fn, _, _ in problems:
        for f in (glob.glob(os.path.join(REPO, "*.php"))
                  + glob.glob(os.path.join(REPO, "admin", "*.php"))
                  + glob.glob(os.path.join(REPO, "inc", "*.php"))):
            s = io.open(f, encoding="utf-8").read()
            for m in re.finditer(r"<\?=([^?]*" + fn + r"\([^?]*)\?>", s):
                expr = " ".join(m.group(1).split())
                safe = expr.strip().startswith("e(")
                print("  %-28s %s  %s" % (os.path.basename(f), expr[:60],
                                          "escaped here" if safe else "NOT ESCAPED"))
else:
    print("every whitelisted helper escapes what it prints")
