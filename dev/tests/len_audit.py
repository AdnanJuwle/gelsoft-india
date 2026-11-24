"""Does a form let someone type more than the column can hold?

sql_mode here is STRICT_TRANS_TABLES, so an over-length INSERT is an ERROR,
not a truncation. A field whose maxlength is larger than its column -- or that
has no maxlength at all -- is therefore a 500 waiting for whoever types a long
sentence, unless the PHP clamps it on the way past.

Three questions, in order of how much they matter:

  1. maxlength LARGER than the column  -> the browser permits what the
     database refuses.
  2. NO maxlength on a field backed by a bounded column -> same thing, with
     nothing stopping it at all.
  3. maxlength SMALLER than the column -> harmless, but usually means one of
     the two drifted.
"""
import glob
import io
import os
import re
import subprocess

os.chdir(r"C:\Users\adnan\Documents\repos\gelsoft-india")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"


def sql(query):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().split("\n") if l]


# column name -> {table: maxlen}; TEXT/MEDIUMTEXT are effectively unbounded
# for a form and are recorded as None.
cols = {}
for table, col, ctype, clen in sql(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, "
        "IFNULL(CHARACTER_MAXIMUM_LENGTH,0) FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='gelsoft' AND DATA_TYPE IN "
        "('varchar','char','text','tinytext','mediumtext','longtext')"):
    n = int(clen)
    cols.setdefault(col, {})[table] = n if ctype in ("varchar", "char") else None

files = sorted(glob.glob("*.php") + glob.glob("admin/*.php") + glob.glob("inc/*.php"))

fields = []
for f in files:
    s = io.open(f, encoding="utf-8").read()
    for m in re.finditer(r"<(input|textarea)\b((?:[^>]|\?>)*?)>", s, re.S):
        kind, attrs = m.group(1), m.group(2)
        t = re.search(r'type="([a-z]+)"', attrs)
        t = t.group(1) if t else ("text" if kind == "input" else "textarea")
        if t in ("hidden", "submit", "button", "checkbox", "radio", "file",
                 "image", "reset", "range", "number", "date", "color"):
            continue
        name = re.search(r'name="([A-Za-z0-9_]+)"', attrs)
        if not name:
            continue
        ml = re.search(r'maxlength="(\d+)"', attrs)
        fields.append({
            "file": f, "line": s.count("\n", 0, m.start()) + 1,
            "name": name.group(1), "type": t,
            "max": int(ml.group(1)) if ml else None,
        })

print("%d named, typed-into fields\n" % len(fields))

# Field names that are not column names at all (a search box, a password, a
# confirmation) have nothing to compare against.
over, missing, under, unknown = [], [], [], []
for fd in fields:
    where = cols.get(fd["name"])
    if not where:
        unknown.append(fd)
        continue
    bounded = {t: n for t, n in where.items() if n}
    if not bounded:
        continue                      # only TEXT columns: nothing to exceed
    smallest = min(bounded.values())
    tbl = min(bounded, key=lambda t: bounded[t])
    fd["col"] = "%s.%s(%d)" % (tbl, fd["name"], smallest)
    if fd["max"] is None:
        missing.append(fd)
    elif fd["max"] > smallest:
        over.append(fd)
    elif fd["max"] < smallest:
        under.append(fd)

print("=== 1. the browser permits more than the column holds ===")
for fd in over:
    print("  %s:%d  %-14s maxlength=%-5s %s"
          % (fd["file"], fd["line"], fd["name"], fd["max"], fd["col"]))
print("  (none)" if not over else "")

print("=== 2. no maxlength at all, on a bounded column ===")
for fd in missing:
    print("  %s:%d  %-14s type=%-9s %s"
          % (fd["file"], fd["line"], fd["name"], fd["type"], fd["col"]))
print("  (none)" if not missing else "")

print("=== 3. maxlength smaller than the column (drift, not a bug) ===")
for fd in under:
    print("  %s:%d  %-14s maxlength=%-5s %s"
          % (fd["file"], fd["line"], fd["name"], fd["max"], fd["col"]))
print("  (none)" if not under else "")

print()
print("=== fields whose name matches no column (checked by hand) ===")
print("  " + ", ".join(sorted({fd["name"] for fd in unknown})))
