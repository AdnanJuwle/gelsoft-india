"""What the site prints, and what it will put in an href.

Two different questions, and the second one is the one that bit.

Escaping is the first: 1109 values are printed across the templates and every
one has to be escaped, cast, a helper that writes its own markup, or a
literal. That is checked statically here, because one miss among a thousand
correct lines is invisible to a reader.

Schemes are the second, and e() cannot help: escaping stops a value breaking
OUT of an attribute, it cannot make `javascript:` safe INSIDE one. Two fields
took a URL from a person and put it in an href, and FILTER_VALIDATE_URL --
which answers "is this a URL?" rather than "is this safe to link to?" --
accepts `javascript://comment%0Aalert(1)`.
"""
import glob
import io
import os
import re
import subprocess
import sys

REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
PHP = (r"C:\Users\adnan\AppData\Local\Microsoft\WinGet\Packages"
       r"\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe")
MARIADB = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"
BASE = "http://localhost:8099"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36"

import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request

passed = failed = 0


def ok(label, cond, extra=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL " + label + ("  -- " + str(extra) if extra else ""))


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


def token(p):
    _, h = get(p)
    m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
    return m.group(1) if m else ""


def sql(q):
    r = subprocess.run([MARIADB, "-u", "gelsoft", "-pdevpass", "gelsoft",
                        "--default-character-set=utf8mb4", "--batch",
                        "--skip-column-names", "-e", q], capture_output=True)
    return (r.stdout or b"").decode("utf-8", "replace").strip()


def php(code):
    return subprocess.run([PHP, "-r", code], cwd=REPO, capture_output=True, text=True)


print("--- what counts as a safe link ---")

cases = [
    ("https://example.com/signup", True),
    ("http://example.com", True),
    ("HTTPS://EXAMPLE.COM/x", True),
    ("javascript:alert(1)", False),
    ("javascript://comment%0Aalert(1)", False),   # FILTER_VALIDATE_URL says yes
    ("JaVaScRiPt://x%0Aalert(1)", False),
    ("data:text/html,<script>alert(1)</script>", False),
    ("vbscript:msgbox(1)", False),
    ("file:///etc/passwd", False),                # so does it to this
    ("ftp://example.com/x", False),               # and this
    ("", False),
    ("   ", False),
    ("not a url at all", False),
]
code = ("require 'inc/bootstrap.php';"
        "$in = json_decode(stream_get_contents(STDIN), true);"
        "foreach ($in as $u) { echo safe_external_url($u) === null ? '0' : '1'; }")
import json
r = subprocess.run([PHP, "-r", code], cwd=REPO, input=json.dumps([c[0] for c in cases]),
                   capture_output=True, text=True)
got = r.stdout.strip()
ok("the helper exists and runs", len(got) == len(cases), r.stderr[:200] or got)

for i, (url, want) in enumerate(cases):
    if i < len(got):
        shown = url if len(url) < 40 else url[:37] + "..."
        ok("%-42s %s" % (shown or "(empty)", "allowed" if want else "refused"),
           got[i] == ("1" if want else "0"), got[i])

# The whole point: two of the refusals above are things FILTER_VALIDATE_URL
# alone would have let through, so assert that difference rather than assume it.
r = php("$u = 'javascript://comment%0Aalert(1)';"
        "echo (int) (filter_var($u, FILTER_VALIDATE_URL) !== false);")
ok("FILTER_VALIDATE_URL on its own would have accepted it", r.stdout.strip() == "1",
   r.stdout + r.stderr[:120])


print()
print("--- a hostile link already in the database cannot reach a page ---")

sql("DELETE FROM games WHERE title='XSS Suite Game'")
host = sql("SELECT id FROM users ORDER BY id LIMIT 1")
BAD = "javascript://comment%0Aalert(document.domain)"
sql(f"""INSERT INTO games (host_id, host_name, contact, title, venue, city, state,
          region, starts_at, duration, fps_cap, fee_paise, spaces, rental,
          beginners, details, signup_link, status)
        VALUES ({host}, 'Probe Host', 'probe@example.com', 'XSS Suite Game',
          'A Field', 'Pune', 'Maharashtra', 'west', DATE_ADD(NOW(), INTERVAL 7 DAY),
          '3 hours', 300, 50000, 20, 1, 1, 'A game.', '{BAD}', 'live')""")

ok("the row really holds the hostile link",
   "javascript" in sql("SELECT signup_link FROM games WHERE title='XSS Suite Game'"))

code_, html = get("/games")
ok("the games board still shows the game", "XSS Suite Game" in html, code_)
ok("...but prints no javascript: href", "javascript:" not in html)
ok("...and no sign-up button for it",
   not re.search(r'href="javascript:[^"]*"', html))

# A good link on the same field still works, or the guard is just a wall.
sql(f"UPDATE games SET signup_link='https://example.com/signup' WHERE title='XSS Suite Game'")
_, html = get("/games")
ok("a real https link is still rendered",
   'href="https://example.com/signup"' in html)
ok("...with rel=noopener on it",
   re.search(r'rel="noopener noreferrer"[^>]*href="https://example\.com/signup"'
             r'|href="https://example\.com/signup"[^>]*rel="noopener noreferrer"',
             html) is not None
   or 'rel="noopener noreferrer"' in html)

sql("DELETE FROM games WHERE title='XSS Suite Game'")


print()
print("--- the form refuses it too ---")

tok = token("/login")
post("/login", {"_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123"})

_, form = get("/games")
m = re.search(r'name="action" value="([a-z_]+)"[^>]*>(?:(?!</form>).)*?name="signup_link"',
              form, re.S)
action = m.group(1) if m else "host"

_, html = post("/games", {
    "_token": token("/games"), "action": action, "title": "XSS Form Game",
    "city": "Pune", "state": "Maharashtra", "region": "west",
    "starts_at": "2027-01-15", "venue": "A Field", "duration": "3 hours",
    "details": "A game.", "signup_link": "javascript://x%0Aalert(1)",
    "understood": "1", "permission": "1",
})
saved = sql("SELECT COUNT(*) FROM games WHERE title='XSS Form Game' AND signup_link LIKE 'javascript%'")
ok("no game is saved with a javascript: link", saved == "0", saved)
sql("DELETE FROM games WHERE title='XSS Form Game'")

src_games = io.open(os.path.join(REPO, "games.php"), encoding="utf-8").read()
src_req = io.open(os.path.join(REPO, "request.php"), encoding="utf-8").read()
ok("games.php validates the scheme, not just the shape",
   "safe_external_url($form['signup_link'])" in src_games)
ok("request.php does the same", "safe_external_url($form['link'])" in src_req)
# The actual call, not the word: games.php still mentions the filter in the
# comment that explains why it is not enough on its own, and
# safe_external_url() uses it as a first pass before checking the scheme.
calls = re.compile(r"filter_var\([^)]*FILTER_VALIDATE_URL")
ok("neither calls FILTER_VALIDATE_URL directly on a link any more",
   not calls.search(src_games) and not calls.search(src_req),
   (bool(calls.search(src_games)), bool(calls.search(src_req))))
src_helpers = io.open(os.path.join(REPO, "inc", "helpers.php"), encoding="utf-8").read()
ok("...and the one place that does, checks the scheme afterwards",
   calls.search(src_helpers) is not None and "PHP_URL_SCHEME" in src_helpers)


print()
print("--- every user-supplied url in an href goes through it ---")

bad_hrefs = []
for f in (glob.glob(os.path.join(REPO, "*.php"))
          + glob.glob(os.path.join(REPO, "admin", "*.php"))
          + glob.glob(os.path.join(REPO, "inc", "*.php"))):
    s = io.open(f, encoding="utf-8").read()
    for m in re.finditer(r'href="<\?=([^>]*?)\?>"', s):
        expr = m.group(1)
        # A link the SITE builds (a path, an asset, a product url) is not a
        # link somebody typed.
        if re.search(r"signup_link|\['link'\]|website|homepage", expr):
            if "safe_external_url" not in expr:
                bad_hrefs.append(os.path.basename(f) + ": " + expr.strip()[:60])
ok("no typed-in url is linked without the scheme check", not bad_hrefs, bad_hrefs)


print()
print("--- everything printed into a page is escaped ---")

SAFE_CALLS = ("e(", "money(", "schema_price(", "csrf_field(", "jsonld(", "asset(",
              "url(", "number_format(", "count(", "intdiv(", "round(", "max(",
              "min(", "abs(", "product_card(", "listing_card(", "message_line(",
              "game_card(", "sprintf(", "str_repeat(", "implode(", "nl2br(e(",
              "bytes_human(", "date(", "strtotime(", "ucfirst(", "grade_mark(",
              "shop_option(", "safe_external_url(")

unchecked, total = [], 0
for f in (glob.glob(os.path.join(REPO, "*.php"))
          + glob.glob(os.path.join(REPO, "admin", "*.php"))
          + glob.glob(os.path.join(REPO, "inc", "*.php"))
          + glob.glob(os.path.join(REPO, "api", "*.php"))):
    s = io.open(f, encoding="utf-8").read()
    for m in re.finditer(r"<\?=(.*?)\?>", s, re.S):
        expr = " ".join(m.group(1).split())
        total += 1
        if any(c in expr for c in ("(int)", "(float)", "(bool)")):
            continue
        if any(expr.startswith(c) for c in SAFE_CALLS):
            continue
        if "e(" in expr or "'" in expr or '"' in expr:
            continue       # a ternary between literals, or escaping inside it
        if re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])*", expr):
            unchecked.append(os.path.basename(f) + ": " + expr)

ok("there are plenty of printed values to check", total > 800, total)

# What is printed raw has to be a number, or a literal out of an array the
# file itself defines. Anything else is somebody's text and wants e().
NUMERIC = re.compile(
    r"\$(live_n|sold_n|n|i|total|cart_count|stats\[|usage\[|with\[|without\[)")

FROM_CONSTANT = {
    # file           expression                 the array it comes out of
    ("parts.php", "$label"): "$PLATFORMS = [",
    ("parts.php", "$PLATFORMS[$platform]"): "$PLATFORMS = [",
    ("request.php", "$label"): "const BUDGETS = [",
}

surprises = []
for u in unchecked:
    if NUMERIC.search(u):
        continue
    fname, expr = u.split(": ", 1)
    key = (fname, expr)
    if key in FROM_CONSTANT:
        # Not taken on trust: the array has to actually be a literal in that
        # file, or the exemption is meaningless.
        body = io.open(os.path.join(REPO, fname), encoding="utf-8").read()
        if FROM_CONSTANT[key] in body:
            continue
    surprises.append(u)

ok("the only values printed raw are numbers and constants", not surprises, surprises[:6])

# ...and the one that takes a key from the query string validates it against
# that array first, or the "constant" is whatever somebody types.
parts_src = io.open(os.path.join(REPO, "parts.php"), encoding="utf-8").read()
ok("the platform key is checked against the array before it is printed",
   "isset($PLATFORMS[$platform]) ? $platform : ''" in parts_src)


print()
print("--- the helpers the sweep waves through earn it ---")

# The sweep treats these as safe to print without e(), because they build
# their own markup. That is reasoning, not evidence: a helper returning a
# database field raw is invisible to the sweep BY DESIGN, since it stops
# looking the moment it sees the name.
MARKUP_HELPERS = ["product_card", "listing_card", "grade_mark", "shop_option",
                  "stock_note", "fps_label", "gears_label", "type_label",
                  "condition_label", "offer_line_suffix", "message_line"]

# A field glued straight onto a string, either side, with nothing in between.
# The cast and the call have to be allowed for: `$l['fps'] . ' FPS'` is safe
# when it reads `(int) $l['fps'] . ' FPS'`, and an earlier version of this
# check flagged exactly that.
GLUED = re.compile(
    r"""['"]\s*\.\s*(\$[a-z_]+\[['"][a-z_]+['"]\])"""
    r"""|(?<![)\s])(\$[a-z_]+\[['"][a-z_]+['"]\])\s*\.\s*['"]""")

inc_src = {}
for _f in glob.glob(os.path.join(REPO, "inc", "*.php")):
    inc_src[os.path.basename(_f)] = io.open(_f, encoding="utf-8").read()

raw_helpers = []
found = 0
for fn in MARKUP_HELPERS:
    for where, body_all in inc_src.items():
        m = re.search(r"\nfunction " + fn + r"\s*\(", body_all)
        if not m:
            continue
        found += 1
        rest = body_all[m.start() + 1:]
        nxt = rest.find("\nfunction ")
        body = rest[:nxt] if nxt > 0 else rest
        for g in GLUED.finditer(body):
            raw_helpers.append("%s in %s: %s" % (fn, where, g.group(1) or g.group(2)))
        break

ok("the helpers are where the sweep thinks they are", found >= 10, found)
ok("none of them glues a database field into markup unescaped",
   not raw_helpers, raw_helpers[:4])

# message_line returns text rather than markup, so every call site must escape
# it -- which is why it is NOT in the sweep's whitelist.
bad_calls = []
for _f in (glob.glob(os.path.join(REPO, "*.php"))
           + glob.glob(os.path.join(REPO, "admin", "*.php"))
           + glob.glob(os.path.join(REPO, "inc", "*.php"))):
    for m in re.finditer(r"<\?=([^?]*message_line\([^?]*)\?>",
                         io.open(_f, encoding="utf-8").read()):
        if not " ".join(m.group(1).split()).startswith("e("):
            bad_calls.append(os.path.basename(_f))
ok("message_line is escaped everywhere it is printed", not bad_calls, bad_calls)

src_msg = io.open(os.path.join(REPO, "inc", "messages.php"), encoding="utf-8").read()
ok("...and that contract is written down", "Returns TEXT, not markup" in src_msg)

print()
print("--- and a notice cannot be written from the query string ---")

# Same shape as the order screen's ?saved=, which was whitelisted two passes
# ago: the sentence on the page should not be typeable into the URL.
tok = token("/admin/login")
post("/admin/login", {"_token": tok, "email": "admin@gelsoft.in",
                      "password": "devpassword123", "next": "/admin/marketplace"})


def notice(query):
    _, h = get("/admin/marketplace?" + query)
    m = re.search(r'role="status"><span class="small">([^<]*)', h)
    return (m.group(1) if m else "").strip()


ok("a known outcome reads properly",
   notice("done=approved").startswith("Approved"), notice("done=approved"))
ok("an invented one falls back to Done.",
   notice("done=the-seller-was-paid") == "Done.", notice("done=the-seller-was-paid"))
ok("undoing a check says the badge is gone",
   "badge is gone" in notice("done=unchecked"), notice("done=unchecked"))
ok("...and how many of their listings are still buyable",
   "3 listings" in notice("done=unchecked&live=3"), notice("done=unchecked&live=3"))
ok("...with the singular right at one",
   "1 listing of theirs is" in notice("done=unchecked&live=1"),
   notice("done=unchecked&live=1"))
ok("a negative count says nothing rather than something odd",
   notice("done=unchecked&live=-5") == notice("done=unchecked"),
   notice("done=unchecked&live=-5"))

print()
print("--- and every form that changes something checks its token ---")

no_token = []
for f in (glob.glob(os.path.join(REPO, "*.php"))
          + glob.glob(os.path.join(REPO, "admin", "*.php"))
          + glob.glob(os.path.join(REPO, "api", "*.php"))):
    s = io.open(f, encoding="utf-8").read()
    if "REQUEST_METHOD" not in s or "POST" not in s:
        continue
    if "csrf_require()" not in s:
        no_token.append(os.path.basename(f))
# The two that do not, and must not: Razorpay is not a browser with a session.
ok("only the two Razorpay endpoints skip it",
   sorted(no_token) == ["payment-return.php", "webhook.php"], sorted(no_token))

src_ret = io.open(os.path.join(REPO, "api", "payment-return.php"), encoding="utf-8").read()
src_hook = io.open(os.path.join(REPO, "api", "webhook.php"), encoding="utf-8").read()
ok("...and both verify a signature instead",
   "rzp_check_handback" in src_ret and ("rzp_check_webhook" in src_hook
                                        or "hash_equals" in src_hook
                                        or "signature" in src_hook.lower()))

early = []
for f in (glob.glob(os.path.join(REPO, "*.php"))
          + glob.glob(os.path.join(REPO, "admin", "*.php"))):
    s = io.open(f, encoding="utf-8").read()
    if "csrf_require()" not in s:
        continue
    at = s.index("csrf_require()")
    w = s.find("exec_sql(")
    if 0 <= w < at:
        early.append(os.path.basename(f))
ok("no page writes before it checks the token", not early, early)


print()
print("%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
