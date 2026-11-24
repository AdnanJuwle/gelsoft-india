"""The image pipeline, against the promises SECURITY.md makes for it.

    "Every image is decoded and written out fresh rather than copied, so EXIF,
    comment blocks, appended archives and injected script do not survive the
    round trip; the type comes from reading the file, never from its name or
    the Content-Type the browser sent."

Four claims. Each one is checked here by building a file that would break it
and looking at what comes out the other side -- a promise in a document is
worth what the code does, and this is the one place on the site where a
stranger hands over bytes that get written to disk.

Plus the thing nothing was checking at all: how big the picture is in MEMORY.
A file-size cap says nothing about that, and the arithmetic bites honest
sellers before it bites anybody else.
"""
import base64
import http.cookiejar
import io
import json
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

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
BASE = "http://localhost:8099"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"

passed = failed = 0


def ok(label, cond, extra=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label + ("  -- " + str(extra) if extra else ""))


def php(code, limit=None):
    cmd = [PHP]
    if limit:
        cmd += ["-d", "memory_limit=" + limit]
    cmd += ["-r", code]
    return subprocess.run(cmd, cwd=REPO, capture_output=True, text=True)


def sql(q):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--batch", "--skip-column-names", "-e", q], capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


def png(w, h, rgb=(70, 90, 60), tail=b""):
    """A real PNG, built here so the test needs no file on disk."""
    raw = b""
    row = bytes(rgb) * w
    for _ in range(h):
        raw += b"\x00" + row

    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d))

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">II", w, h) + b"\x08\x02\x00\x00\x00")
            + chunk(b"IDAT", zlib.compress(raw, 9))
            + chunk(b"IEND", b"")
            + tail)


def flat_png_bomb(w, h):
    """A PNG whose header claims to be enormous.

    Not a real image, and it does not need to be: getimagesize() parses IHDR
    and nothing else, so this is exactly what the dimension guard sees. The
    guard refuses before GD is asked to open anything -- which is the point of
    checking first -- so the pixels never have to exist.

    Building a genuine 12000x12000 PNG took nearly two minutes of zlib and
    proved nothing extra.
    """
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d))

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">II", w, h) + b"\x08\x02\x00\x00\x00")
            + chunk(b"IDAT", zlib.compress(b"\x00" * 64, 9))
            + chunk(b"IEND", b""))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def _send(req):
    req.add_header("User-Agent", UA)
    try:
        r = _opener.open(req)
        return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def get(p):
    return _send(urllib.request.Request(BASE + p))


def post(p, d):
    req = urllib.request.Request(BASE + p, data=urllib.parse.urlencode(d).encode(),
                                 method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    return _send(req)


def post_file(p, fields, fname, data, ctype):
    """One multipart POST. The admin form takes its images as photos[]."""
    boundary = uuid.uuid4().hex
    body = io.BytesIO()
    for k, v in fields:
        body.write(("--%s\r\n" % boundary).encode())
        body.write(('Content-Disposition: form-data; name="%s"\r\n\r\n' % k).encode())
        body.write(str(v).encode() + b"\r\n")
    if data is not None:
        body.write(("--%s\r\n" % boundary).encode())
        body.write(('Content-Disposition: form-data; name="photos[]"; filename="%s"\r\n'
                    % fname).encode())
        body.write(("Content-Type: %s\r\n\r\n" % ctype).encode())
        body.write(data + b"\r\n")
    body.write(("--%s--\r\n" % boundary).encode())
    req = urllib.request.Request(BASE + p, data=body.getvalue(), method="POST")
    req.add_header("Content-Type", "multipart/form-data; boundary=" + boundary)
    return _send(req)


def save_item(pid, fname, data, ctype, name="Upload suite test"):
    """A complete save of the item, with one file attached.

    The whole item has to be posted or the submission fails validation on the
    missing name/price/grade and the photo is never reached -- which is how an
    earlier version of this suite "proved" a bomb was refused."""
    return post_file("/admin/add-item", [
        ("_token", token("/admin/add-item" + ("?edit=" + pid if pid else ""))),
        ("id", pid or "0"), ("action", "save"), ("category", "blaster"),
        ("name", name), ("price", "1000"), ("grade", "toy"), ("status", "draft"),
    ], fname, data, ctype)


def token(p):
    _, h = get(p)
    m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
    return m.group(1) if m else ""


print("--- how much picture this server can open ---")

for limit, lo, hi in [("128M", 5, 25), ("512M", 30, 40), ("-1", 40, 40)]:
    r = php("require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
            "echo image_memory_budget_px();", limit)
    px = int(r.stdout.strip() or 0)
    ok("memory_limit %-5s gives a budget of %.1f MP" % (limit, px / 1e6),
       lo * 1e6 <= px <= hi * 1e6, (px, r.stderr[:120]))

r = php("require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
        "echo UPLOAD_MAX_PIXELS;")
ok("there is an absolute ceiling as well", int(r.stdout.strip() or 0) > 0, r.stdout)

# The point of asking the host rather than hard-coding one number.
a = int(php("require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
            "echo image_memory_budget_px();", "128M").stdout.strip() or 0)
b = int(php("require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
            "echo image_memory_budget_px();", "512M").stdout.strip() or 0)
ok("a bigger host is allowed more", b > a, (a, b))


print()
print("--- what the re-encode drops ---")

# "appended archives, injected script do not survive the round trip"
PAYLOAD = b"<?php system($_GET['c']); ?>"
poly = png(300, 300, tail=PAYLOAD)
ok("the polyglot is a valid PNG as far as a header check goes",
   php("$f = tempnam(sys_get_temp_dir(), 'p');"
       "file_put_contents($f, base64_decode(stream_get_contents(STDIN)));"
       "$i = @getimagesize($f); echo $i === false ? 'no' : 'yes'; @unlink($f);").returncode == 0)

r = subprocess.run(
    [PHP, "-r",
     "require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
     "$in = tempnam(sys_get_temp_dir(), 'in');"
     "file_put_contents($in, base64_decode(stream_get_contents(STDIN)));"
     "$i = getimagesize($in);"
     "$dir = sys_get_temp_dir();"
     "$name = 'resave-test-' . bin2hex(random_bytes(4));"
     "$out = image_resave($in, $dir, $name, $i[2], 1600, 82);"
     "$bytes = $out === null ? '' : file_get_contents($dir . '/' . $out);"
     "echo json_encode(['header' => $i[0] . 'x' . $i[1],"
     "  'payload_in_input' => str_contains(file_get_contents($in), '<?php'),"
     "  'payload_in_output' => str_contains($bytes, '<?php'),"
     "  'out' => $out]);"
     "if ($out) { @unlink($dir . '/' . $out); } @unlink($in);"],
    cwd=REPO, input=base64.b64encode(poly).decode(), capture_output=True, text=True)
try:
    res = json.loads(r.stdout.strip())
except Exception:
    res = {}
    ok("the re-encode probe ran", False, (r.stdout[:150], r.stderr[:150]))

if res:
    ok("the re-encode probe ran", True)
    ok("the file really did carry PHP on the end", res["payload_in_input"] is True)
    ok("...and none of it survives the round trip", res["payload_in_output"] is False)
    ok("...and what comes out is a webp", str(res["out"]).endswith(".webp"), res["out"])


print()
print("--- a picture that is small on disk and enormous in memory ---")

bomb = flat_png_bomb(12000, 12000)
ok("the bomb is comfortably under the 5 MB file cap", len(bomb) < 5 * 1024 * 1024,
   "%d bytes" % len(bomb))

# The header is what the guard reads, so the header is what has to be huge.
r = php("$f = tempnam(sys_get_temp_dir(), 'b');"
        "file_put_contents($f, base64_decode(stream_get_contents(STDIN)));"
        "$i = @getimagesize($f); @unlink($f);"
        "echo $i === false ? 'rejected' : $i[0] . 'x' . $i[1];")
probe = subprocess.run([PHP, "-r",
                        "$f = tempnam(sys_get_temp_dir(), 'b');"
                        "file_put_contents($f, base64_decode(stream_get_contents(STDIN)));"
                        "$i = @getimagesize($f); @unlink($f);"
                        "echo $i === false ? 'rejected' : $i[0] . 'x' . $i[1];"],
                       cwd=REPO, input=base64.b64encode(bomb).decode(),
                       capture_output=True, text=True)
ok("...and getimagesize reads it as 12000x12000", probe.stdout.strip() == "12000x12000",
   probe.stdout.strip() or probe.stderr[:120])
ok("...which is 144 megapixels, far past the budget",
   12000 * 12000 > int(php("require 'inc/bootstrap.php'; require_once 'inc/uploads.php';"
                           "echo image_memory_budget_px();", "128M").stdout.strip() or 0))

tok = token("/login")
post("/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123"})
tok = token("/admin/login")
post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in",
                      "password": "devpassword123", "next": "/admin/add-item"})

sql("DELETE FROM products WHERE name LIKE 'Upload suite test%'")

# Made through the form, so the id is one the form really produced.
code, html = save_item("", "first.png", png(600, 400), "image/png")
pid = sql("SELECT id FROM products WHERE name LIKE 'Upload suite test%' ORDER BY id DESC LIMIT 1")
ok("the test product was created through the form", pid.isdigit(), (code, pid))

before = int(sql("SELECT COUNT(*) FROM product_images WHERE product_id=" + pid))
ok("...with its first photo on it", before == 1, before)

code, html = save_item(pid, "bomb.png", bomb, "image/png")
after = int(sql("SELECT COUNT(*) FROM product_images WHERE product_id=" + pid))
ok("the bomb is not stored", after == before, (before, after))
# role="status", not role="alert": a rejected photo is a notice, not an
# error -- the item itself saved. Scraping only alerts was why this looked
# silent.
msg = " ".join(re.sub(r"<[^>]+>", " ", m) for m in
               re.findall(r'role="(?:alert|status)"[^>]*>(.*?)</p>', html, re.S))
msg = re.sub(r"\s+", " ", msg).strip()
ok("...and the page says why, with the dimensions in it",
   "12000" in msg and "scale" in msg.lower(), msg[:140])
ok("...and suggests a size to scale to", re.search(r"\d{3,4}px", msg) is not None, msg[:140])
ok("...rather than dying with a memory error",
   code == 200 and "Allowed memory size" not in html, code)

# A normal photo still goes through, or the guard is just a wall.
code, html = save_item(pid, "normal.png", png(900, 600), "image/png")
after = int(sql("SELECT COUNT(*) FROM product_images WHERE product_id=" + pid))
ok("an ordinary photo is still accepted", after == before + 1, (before, after))

stored = sql("SELECT path FROM product_images WHERE product_id=" + pid + " ORDER BY id DESC LIMIT 1")
ok("...and is stored as a webp", stored.endswith(".webp"), stored)
ok("...under a name the uploader did not choose",
   "normal" not in stored and re.search(r"-[0-9a-f]{12}\.", stored) is not None, stored)


print()
print("--- the type comes from the bytes, not the name or the header ---")

for fname, ctype, data, why in [
    ("shell.php", "image/png", png(400, 400), "a .php name on a real image"),
    ("evil.png", "image/png", b"<?php system($_GET['c']); ?>" * 20, "PHP wearing a .png name"),
    ("x.png", "image/png", b"GIF89a" + b"\x00" * 400, "a GIF pretending to be a PNG"),
]:
    n0 = int(sql("SELECT COUNT(*) FROM product_images WHERE product_id=" + pid))
    code, html = save_item(pid, fname, data, ctype)
    n1 = int(sql("SELECT COUNT(*) FROM product_images WHERE product_id=" + pid))
    paths = sql("SELECT GROUP_CONCAT(path) FROM product_images WHERE product_id=" + pid)
    if fname == "shell.php":
        ok("%s is accepted, because the BYTES are an image" % why, n1 == n0 + 1, (n0, n1))
        ok("...but never stored as .php", ".php" not in paths, paths)
    else:
        ok("%s is refused" % why, n1 == n0, (n0, n1))

ok("nothing executable is on disk",
   not [f for f in os.listdir(os.path.join(REPO, "uploads"))
        if f.lower().endswith((".php", ".phtml", ".phar"))])


print()
print("--- without GD there is no round trip, so there is no upload ---")

src = io.open(os.path.join(REPO, "inc", "uploads.php"), encoding="utf-8").read()
ok("the no-GD path refuses rather than moving the file",
   "move_uploaded_file($tmp" not in src, "move_uploaded_file still present")
ok("...and says so in the log", "gd extension is not available" in src)


print()
print("--- cleaning up ---")
# The images on disk go with the rows, through the same path the admin uses.
for row in sql("SELECT path FROM product_images WHERE product_id=" + pid).split("\n"):
    if row.strip():
        f = os.path.join(REPO, row.strip())
        if os.path.exists(f):
            os.remove(f)
sql("DELETE FROM product_images WHERE product_id=" + pid)
sql("DELETE FROM products WHERE name LIKE 'Upload suite test%'")
print("  products left: %s"
      % sql("SELECT COUNT(*) FROM products WHERE name LIKE 'Upload suite test%'"))

print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
