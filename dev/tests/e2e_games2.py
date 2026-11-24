"""Hosting needs an account; a game can carry a banner; players message the host."""
import http.cookiejar
import io
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
MYSQL = r"C:\Users\adnan\Documents\repos\gelsoft-india"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
ADMIN, ADMIN_PW = "admin@gelsoft.in", "devpassword123"

HOST_E, HOST_PW = "game.host@example.com", "host long pass phrase"
PLAYER_E, PLAYER_PW = "game.player@example.com", "player long pass phrase"


def sql(q):
    r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft", "-sN", "-e", q],
                       capture_output=True)
    return r.stdout.decode("utf-8", "replace").strip()


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
            body.write(f'Content-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for k, fn, data, ct in files:
            body.write(f"--{boundary}\r\n".encode())
            body.write(f'Content-Disposition: form-data; name="{k}"; filename="{fn}"\r\n'.encode())
            body.write(f"Content-Type: {ct}\r\n\r\n".encode())
            body.write(data)
            body.write(b"\r\n")
        body.write(f"--{boundary}--\r\n".encode())
        req = urllib.request.Request(BASE + p, data=body.getvalue(), method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        return self._do(req)

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""

    def login(self, email, pw, where="/login"):
        t = self.token(where)
        return self.post(where, {"_token": t, "email": email, "password": pw, "next": "/account"})


def png(w=1500, h=1000):
    raw = b"".join(b"\x00" + bytes(((x * 7) % 255, (y * 5) % 255, 90)) * 1 * 1
                   for y in range(h) for x in range(1))
    # a plain block image is enough; it only has to decode
    raw = b"".join(b"\x00" + bytes((60, 90, 120)) * w for _ in range(h))

    def chunk(tag, data):
        c = tag + data
        return struct.pack(">I", len(data)) + c + struct.pack(">I", zlib.crc32(c) & 0xFFFFFFFF)

    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw, 6)) + chunk(b"IEND", b""))


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


def register(email, pw, name):
    sql(f"DELETE FROM users WHERE email='{email}'")
    c = C()
    t = c.token("/register")
    c.post("/register", {"_token": t, "name": name, "email": email, "password": pw, "next": "/account"})
    c.login(email, pw)
    return c


print("--- fixtures ---")
sql("DELETE FROM games WHERE title LIKE 'G2 %'")
host = register(HOST_E, HOST_PW, "Arjun Host")
player = register(PLAYER_E, PLAYER_PW, "Neha Player")
hid = sql(f"SELECT id FROM users WHERE email='{HOST_E}'")
pid = sql(f"SELECT id FROM users WHERE email='{PLAYER_E}'")
check("both accounts exist", hid != "" and pid != "")
check("the host is signed in", host.get("/account")[0] == 200)

print("\n--- hosting needs an account ---")
code, html, _ = C().get("/games")
check("a stranger sees the board", code == 200)
check("...but not the form", 'name="title"' not in html or "Hosting needs an account" in html)
check("...and is told why", "Hosting needs an account" in html)

base = [("city", "Pune"), ("state", "MH"), ("region", "west"),
        ("starts_at", "2027-03-14T09:00"), ("fps_cap", "230"), ("fee", "500"),
        ("permission", "1")]

# ONE client, so its CSRF token matches its own session. With two the post is
# refused for the wrong reason and this proves nothing about the sign-in gate.
stranger = C()
t = stranger.token("/games")
code, html, _ = stranger.post("/games", dict(base + [("_token", t), ("title", "G2 Sneaky Game")]))
check("a stranger cannot post one", sql("SELECT COUNT(*) FROM games WHERE title='G2 Sneaky Game'") == "0")
check("...and is told to sign in", "Sign in to put a game" in html)

print("\n--- a host with an account ---")
t = host.token("/games")
code, html, _ = host.post_multipart(
    "/games", [("_token", t), ("title", "G2 Pune Skirmish")] + base, [])
gid = sql("SELECT id FROM games WHERE title='G2 Pune Skirmish'")
check("a signed-in host can submit", gid != "", html[:200])
check("...it is pending, not live", sql(f"SELECT status FROM games WHERE id={gid}") == "pending")
check("...the account is recorded as the host", sql(f"SELECT host_id FROM games WHERE id={gid}") == hid)
check("...the name comes from the account, not the form",
      sql(f"SELECT host_name FROM games WHERE id={gid}") == "Arjun Host")
check("...and so does the contact",
      sql(f"SELECT contact FROM games WHERE id={gid}") == HOST_E)

print("\n--- the banner ---")
t = host.token("/games")
code, html, _ = host.post_multipart(
    "/games", [("_token", t), ("title", "G2 Banner Game")] + base,
    [("banner", "ground.png", png(), "image/png")])
bid = sql("SELECT id FROM games WHERE title='G2 Banner Game'")
check("a game with a banner saves", bid != "", html[:200])
bpath = sql(f"SELECT banner FROM games WHERE id={bid}")
check("the banner is stored as a path", bpath.startswith("uploads/"), bpath)
check("...re-encoded to webp", bpath.endswith(".webp"), bpath)

import os
full = os.path.join(r"C:\Users\adnan\Documents\repos\gelsoft-india", *bpath.split("/"))
check("...and the file is on disk", os.path.isfile(full), full)
if os.path.isfile(full):
    import struct as _s
    with open(full, "rb") as fh:
        head = fh.read(32)
    check("...it really is a WebP", head[:4] == b"RIFF" and head[8:12] == b"WEBP")
    check("...and it is small", os.path.getsize(full) < 200_000, f"{os.path.getsize(full)} bytes")

print("\n--- the board shows it ---")
sql(f"UPDATE games SET status='live' WHERE id IN ({gid},{bid})")
code, html, _ = C().get("/games")
check("a live game is on the board", "G2 Banner Game" in html)
check("...with its banner", bpath in html, bpath)
check("a game with no banner still renders", "G2 Pune Skirmish" in html)

print("\n--- messaging the host ---")
code, html, _ = player.get("/games")
check("a player is offered the button", "Message the host" in html)
check("...and the host is not offered it on their own game",
      "This is yours" in host.get("/games")[1])

t = player.token("/games")
code, html, loc = player.post("/game/message", {"_token": t, "game_id": gid})
check("opening a conversation works", code == 302 and "/messages?thread=" in (loc or ""), f"{code} {loc}")
tid = (loc or "").split("thread=")[-1]
check("the thread is attached to the game", sql(f"SELECT game_id FROM threads WHERE id={tid}") == gid)
check("...with no listing", sql(f"SELECT listing_id IS NULL FROM threads WHERE id={tid}") == "1")
check("...the host is the other party", sql(f"SELECT seller_id FROM threads WHERE id={tid}") == hid)

code, html, _ = player.get(f"/messages?thread={tid}")
check("the conversation opens", code == 200 and "G2 Pune Skirmish" in html, f"code={code}")
check("...and names the roles properly", "you are the player" in html, "")
check("...with no offer box", "Make an offer" not in html)
check("the page finished rendering", "</body>" in html)

t = player.token(f"/messages?thread={tid}")
code, html, _ = player.post("/messages", {"_token": t, "thread_id": tid,
                                          "body": "Is eye protection provided?"})
check("a message sends", sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid}") == "1")

code, html, _ = host.get(f"/messages?thread={tid}")
check("the host sees it", code == 200 and "eye protection" in html)
check("...as the host", "you are the host" in html)

print("\n--- what must be refused ---")
t = player.token(f"/messages?thread={tid}")
code, html, _ = player.post("/messages", {"_token": t, "thread_id": tid, "kind": "offer",
                                          "offer": "500", "body": ""})
check("an offer on a game is refused", "cannot make an offer on a game" in html, html[:160])
check("...and nothing was recorded",
      sql(f"SELECT COUNT(*) FROM messages WHERE thread_id={tid} AND kind='offer'") == "0")

t = host.token("/games")
code, html, loc = host.post("/game/message", {"_token": t, "game_id": gid})
check("a host cannot message themself", (loc or "") == "/games", f"{loc}")

pend = sql("SELECT id FROM games WHERE title='G2 Pune Skirmish'")
sql(f"UPDATE games SET status='pending' WHERE id={pend}")
t = player.token("/games")
code, html, loc = player.post("/game/message", {"_token": t, "game_id": pend})
check("a game not on the board cannot be messaged", (loc or "") == "/games", f"{loc}")
sql(f"UPDATE games SET status='live' WHERE id={pend}")

code, html, _ = C().post("/game/message", {"game_id": gid})
check("a stranger is sent to sign in", code == 302, f"code={code}")

print("\n--- the listing side still works ---")
code, html, _ = player.get("/messages")
check("the conversation list opens", code == 200)
check("...and shows the game thread", "G2 Pune Skirmish" in html)

print("\n--- deleting a game frees its banner ---")
adm = C()
t = adm.token("/admin/login")
adm.post("/admin/login", {"_token": t, "email": ADMIN, "password": ADMIN_PW})
t = adm.token("/admin/community")
adm.post("/admin/community", {"_token": t, "action": "game_delete", "id": bid})
check("the game is gone", sql(f"SELECT COUNT(*) FROM games WHERE id={bid}") == "0")
check("...and so is the banner file", not os.path.isfile(full), full)

print("\n--- cleaning up ---")
sql("DELETE FROM games WHERE title LIKE 'G2 %'")
sql(f"DELETE FROM users WHERE email IN ('{HOST_E}','{PLAYER_E}')")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
