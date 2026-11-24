import io, os, re, glob, collections

os.chdir(r"C:\Users\adnan\Documents\repos\gelsoft-india")
files = sorted(glob.glob("*.php")) + sorted(glob.glob("admin/*.php")) + sorted(glob.glob("inc/*.php"))

pat = re.compile(r'style="([^"]*)"')
rows = []
for f in files:
    if f.endswith("config.php"):
        continue
    for i, line in enumerate(io.open(f, encoding="utf-8"), 1):
        for m in pat.finditer(line):
            rows.append((f, i, m.group(1), line.strip()))

print("total:", len(rows))

def props(css):
    out = {}
    for part in css.split(";"):
        if ":" in part:
            k, v = part.split(":", 1)
            out[k.strip()] = v.strip()
    return out

cats = collections.defaultdict(list)
for f, i, css, line in rows:
    p = props(css)
    # 1. colour changed without its partner
    has_bg = any(k in p for k in ("background", "background-color"))
    has_fg = "color" in p
    if has_bg and not has_fg:
        cats["bg without color"].append((f, i, css))
    if has_fg and not has_bg:
        # only risky if the colour is light-on-unknown; flag all for review
        cats["color without bg"].append((f, i, css))
    # 2. fixed px width that cannot shrink
    for k in ("width", "min-width", "flex-basis"):
        v = p.get(k, "")
        m = re.match(r"^(\d+)px$", v)
        if m and int(m.group(1)) > 150 and k != "min-width":
            cats["fixed width >150px"].append((f, i, css))
        if k == "min-width" and m and int(m.group(1)) > 0:
            cats["min-width in px"].append((f, i, css))
    # 3. grid columns hard-coded
    if "grid-template-columns" in p:
        cats["grid-template-columns"].append((f, i, p["grid-template-columns"]))
    # 4. flex row that cannot wrap
    if p.get("display") == "flex" and "flex-wrap" not in p and p.get("flex-direction") != "column":
        cats["display:flex no wrap"].append((f, i, css))
    # 5. nowrap
    if p.get("white-space") == "nowrap":
        cats["white-space:nowrap"].append((f, i, css))
    # 6. positioning
    if p.get("position") in ("absolute", "fixed"):
        cats["position abs/fixed"].append((f, i, css))

for k in sorted(cats, key=lambda k: -len(cats[k])):
    print()
    print("### %s  (%d)" % (k, len(cats[k])))
    for f, i, css in cats[k][:40]:
        print("   %s:%s  %s" % (f, i, css))
