"""Phase 3: the admin actually saving a product, with a photo."""
import http.cookiejar
import io
import mimetypes
import os
import re
import struct
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

BASE = "http://localhost:8099"
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"


def sql(query):
    """Read a value the site does not show, and tidy up after the suite.

    Decoded as UTF-8 by hand rather than with text=True, which uses the
    console code page on Windows and chokes on anything outside it."""
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--default-character-set=utf8mb4",
                        "--batch", "--skip-column-names", "-e", query],
                       capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

    def _do(self, req):
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

    def get(self, p):
        return self._do(urllib.request.Request(BASE + p))

    def post(self, p, d):
        r = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type", "application/x-www-form-urlencoded")
        return self._do(r)

    def post_multipart(self, p, fields, files):
        boundary = uuid.uuid4().hex
        body = io.BytesIO()
        for k, v in fields:
            body.write(f"--{boundary}\r\n".encode())
            body.write(f'Content-Disposition: form-data; name="{k}"\r\n\r\n'.encode())
            body.write(str(v).encode() + b"\r\n")
        for k, name, data, ctype in files:
            body.write(f"--{boundary}\r\n".encode())
            body.write(f'Content-Disposition: form-data; name="{k}"; filename="{name}"\r\n'.encode())
            body.write(f"Content-Type: {ctype}\r\n\r\n".encode())
            body.write(data + b"\r\n")
        body.write(f"--{boundary}--\r\n".encode())
        req = urllib.request.Request(BASE + p, data=body.getvalue(), method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        return self._do(req)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""


def png(width=600, height=450, rgb=(70, 90, 60)):
    """A real PNG, built by hand so the test does not need an image on disk."""
    raw = b""
    row = bytes(rgb) * width
    for _ in range(height):
        raw += b"\x00" + row

    def chunk(tag, data):
        c = tag + data
        return struct.pack(">I", len(data)) + c + struct.pack(">I", zlib.crc32(c) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw, 6))
            + chunk(b"IEND", b""))


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


a = C()
tok = a.token("/admin/login")
a.post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in",
                        "password": "devpassword123", "next": "/admin/"})
code, html, _ = a.get("/admin/add-item")
check("the add form opens", code == 200 and 'name="price"' in html)

print("\n--- saving a product ---")
tok = a.token("/admin/add-item")
fields = [
    ("_token", tok), ("id", "0"), ("action", "save"),
    ("category", "blaster"), ("brand", "TestBrand"),
    ("name", "E2E Test Blaster"), ("summary", "Made by the test run."),
    ("description", "Line one.\n\nLine two."),
    ("grade", "premium"), ("type", "rifle"), ("gears", "metal"),
    ("gearbox", "V2 metal"), ("fps_min", "260"), ("fps_max", "280"),
    ("battery", "11.1V"), ("magazine", "Mid-cap"), ("needs_to_run", "Gel"),
    ("price", "15000"), ("was", "18000"), ("stock_qty", "4"),
    ("stock_label", "in"), ("status", "published"), ("featured", "1"),
    ("slug", ""),
]
code, html, loc = a.post_multipart("/admin/add-item", fields,
                                   [("photos[]", "shot.png", png(), "image/png")])
check("saving redirects", code == 302 and "edit=" in (loc or ""), f"code={code} {html[:200]}")

pid = re.search(r"edit=(\d+)", loc or "").group(1) if loc and "edit=" in loc else ""
check("got a product id", bool(pid))

# Read the slug back rather than assuming it: a second run of this test gets
# -2 appended, which is the de-duplicator doing its job.
_, editHtml, _ = a.get(f"/admin/add-item?edit={pid}")
slug = re.search(r'id="slug"[^>]*value="([^"]*)"', editHtml).group(1)

anon = C()
code, html, _ = anon.get(f"/blasters/{slug}")
check("the product page is live at its slug", code == 200 and "E2E Test Blaster" in html, f"code={code}")
check("the price shows in rupees", "15,000" in html, "")
check("the was-price shows", "18,000" in html)
check("the photo made it onto the page", "uploads/" in html and ".webp" in html)
check("the description keeps its paragraphs", "Line one." in html and "Line two." in html)

code, html, _ = anon.get("/blasters")
check("it appears on the shelf", "E2E Test Blaster" in html)

code, html, _ = anon.get("/blasters?grade%5B%5D=premium")
check("it is filterable by its grade", "E2E Test Blaster" in html)

print("\n--- editing it ---")
tok = a.token(f"/admin/add-item?edit={pid}")
code, html, _ = a.get(f"/admin/add-item?edit={pid}")
check("the edit form is pre-filled", 'value="E2E Test Blaster"' in html and 'value="15000"' in html)

fields = [(k, v) for k, v in fields if k not in ("_token", "id", "name", "price", "status", "slug")]
fields += [("_token", tok), ("id", pid), ("name", "E2E Test Blaster v2"),
           ("price", "13500"), ("status", "draft"), ("slug", slug)]
code, html, loc = a.post_multipart("/admin/add-item", fields, [])
check("the edit saves", code == 302, f"code={code} {html[:200]}")

code, html, _ = anon.get(f"/blasters/{slug}")
check("a draft disappears from the shop", code == 404, f"code={code}")

print("\n--- what must be refused ---")
tok = a.token("/admin/add-item")
code, html, _ = a.post_multipart("/admin/add-item", [
    ("_token", tok), ("id", "0"), ("action", "save"), ("category", "blaster"),
    ("name", "No price here"), ("price", ""), ("grade", "toy"), ("status", "draft"),
], [])
check("a missing price is refused", "Put a price in" in html, f"code={code}")

tok = a.token("/admin/add-item")
code, html, _ = a.post_multipart("/admin/add-item", [
    ("_token", tok), ("id", "0"), ("action", "save"), ("category", "blaster"),
    ("name", "No grade"), ("price", "100"), ("grade", ""), ("status", "draft"),
], [])
check("a blaster with no grade is refused", "need a grade" in html)

tok = a.token("/admin/add-item")
code, html, _ = a.post_multipart("/admin/add-item", [
    ("_token", tok), ("id", "0"), ("action", "save"), ("category", "blaster"),
    ("name", "Bad file"), ("price", "100"), ("grade", "toy"), ("status", "draft"),
], [("photos[]", "evil.php", b"<?php echo 'pwned'; ?>", "image/png")])
check("a PHP file dressed as a PNG is refused",
      "not a JPEG, PNG or WebP" in html, f"code={code}")

tok = a.token("/admin/add-item")
code, html, _ = a.post_multipart("/admin/add-item", [
    ("_token", tok), ("id", "0"), ("action", "save"), ("category", "blaster"),
    ("name", "Bad enum"), ("price", "100"), ("grade", "toy"),
    ("status", "published; DROP TABLE products"), ("gears", "titanium"),
], [])
check("an unknown status or gears falls back instead of erroring",
      code in (200, 302), f"code={code}")

# Not just "it did not error" -- WHICH way it fell. $pick() sends an
# unrecognised status to 'draft', so a junk submission cannot publish
# something; a fallback of 'published' would pass the check above too.
row = sql("SELECT status, IFNULL(NULLIF(gears,''),'(empty)') FROM products "
          "WHERE name = 'Bad enum' ORDER BY id DESC LIMIT 1")
check("...to draft, so nothing is published by accident",
      row.split("\t")[0] == "draft" if row else False, row)
check("...and an unknown gears value is simply dropped",
      row.split("\t")[-1] == "(empty)" if row else False, row)
check("the injected SQL was never a status",
      "DROP" not in (row or ""), row)

code, html, _ = anon.get("/blasters")
check("the products table survived", "6 blasters" in html or "blasters on the shelf" in html)

print("\n--- a customer cannot ---")
cust = C()
tok = cust.token("/login")
cust.post("/login", {"_token": tok, "email": "buyer.test@example.com",
                     "password": "correct horse battery", "next": "/account"})
code, _, _ = cust.get("/admin/add-item")
check("a customer gets 403 on the add form", code == 403, f"code={code}")

print("\n--- cleaning up ---")
# Every product this suite has ever made, not just this run's. Three per run
# had been accumulating in the shop's own table since it was written.
for name in ("E2E Test Blaster", "E2E Test Blaster v2", "Bad enum", "Bad file"):
    sql(f"DELETE FROM product_images WHERE product_id IN "
        f"(SELECT id FROM products WHERE name = '{name}')")
    sql(f"DELETE FROM products WHERE name = '{name}'")
left = sql("SELECT COUNT(*) FROM products WHERE name IN "
           "('E2E Test Blaster','E2E Test Blaster v2','Bad enum','Bad file')")
print(f"  products left from this suite: {left}")
check("it leaves nothing behind", left == "0", left)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
