"""End-to-end check over HTTP: register, sign in, message, admin, and the
things that must NOT work."""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            NoRedirect(),
        )

    def get(self, path):
        try:
            r = self.op.open(BASE + path)
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

    def post(self, path, data):
        body = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(BASE + path, data=body, method="POST")
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        try:
            r = self.op.open(req)
            return r.status, r.read().decode("utf-8", "replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace"), e.headers.get("Location")

    def token(self, path):
        _, html, _ = self.get(path)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', html)
        return m.group(1) if m else ""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


print("\n--- registration and sign-in ---")
c = Client()
tok = c.token("/register")
check("register page gives a CSRF token", bool(tok))

email = "buyer.test@example.com"
code, html, loc = c.post("/register", {
    "_token": tok, "name": "Test Buyer", "email": email,
    "password": "correct horse battery", "next": "/account",
})
check("register redirects on success", code == 302 or "already an account" in html,
      f"code={code}")

# A second client signs in as that buyer.
b = Client()
tok = b.token("/login")
code, html, loc = b.post("/login", {
    "_token": tok, "email": email, "password": "correct horse battery", "next": "/account",
})
check("sign-in redirects", code == 302, f"code={code} {html[:120]}")

code, html, _ = b.get("/account")
check("account opens once signed in", code == 200 and "Test Buyer" in html)

print("\n--- things that must not work ---")
code, html, _ = b.post("/messages", {"thread_id": "1", "body": "no token"})
check("POST without a CSRF token is refused", code == 419, f"code={code}")

anon = Client()
code, _, loc = anon.get("/messages")
check("messages needs a sign-in", code == 302 and "/login" in (loc or ""))

code, _, loc = anon.get("/admin/")
check("admin needs a sign-in", code == 302 and "/admin/login" in (loc or ""))

code, _, loc = b.get("/admin/")
check("a customer cannot open admin", code == 403, f"code={code}")

tok = b.token("/account")
code, _, loc = b.post("/login", {"_token": tok, "email": email,
                                 "password": "correct horse battery",
                                 "next": "https://evil.example/pwn"})
check("sign-in cannot redirect off-site", "evil.example" not in (loc or ""), f"loc={loc}")

code, html, _ = anon.get("/search?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E")
check("a script tag in search is escaped", "<script>alert(1)" not in html)

code, html, _ = anon.get("/page.php?slug=../inc/config")
check("page slug cannot traverse", code == 404, f"code={code}")

code, html, _ = anon.get("/inc/config.php")
check("config is not served", code in (403, 404) or "db" not in html.lower(), f"code={code}")

print("\n--- admin ---")
a = Client()
tok = a.token("/admin/login")
code, html, loc = a.post("/admin/login", {
    "_token": tok, "email": "admin@gelsoft.in", "password": "devpassword123", "next": "/admin/",
})
check("admin signs in", code == 302, f"code={code} {html[:160]}")

code, html, _ = a.get("/admin/")
check("admin dashboard opens", code == 200 and "Items published" in html)

code, html, _ = a.get("/admin/faq")
check("FAQ editor lists questions", code == 200 and "Is gelsoft legal in India?" in html)

_, listing_html, _ = a.get("/admin/faq")
first_id = re.search(r'href="/admin/faq\?edit=(\d+)"', listing_html).group(1)
code, html, _ = a.get(f"/admin/faq?edit={first_id}")
check("FAQ edit loads that question", code == 200 and 'name="question"' in html and "Is gelsoft legal in India?" in html)

code, html, _ = a.get("/admin/pages?slug=privacy")

# Keep the real page. This edit used to stick, so the development site served
# "Edited by the end-to-end test." as its privacy policy for every run since.
# Read out of the form rather than the database, so this suite stays HTTP-only.
import html as _htmlmod
_m = re.search(r'name="title"[^>]*value="([^"]*)"', html)
_restore_title = _htmlmod.unescape(_m.group(1)) if _m else "Privacy"
_m = re.search(r'name="body"[^>]*>(.*?)</textarea>', html, re.S)
_restore_body = _htmlmod.unescape(_m.group(1)) if _m else ""
check("the editor shows the page it is about to change", _restore_body != "")
check("policy editor loads the chosen page", code == 200 and 'name="body"' in html and "Privacy" in html)

tok = a.token("/admin/pages?slug=privacy")
code, _, loc = a.post("/admin/pages", {
    "_token": tok, "slug": "privacy", "title": "Privacy",
    "body": "Edited by the end-to-end test.\n\nSecond paragraph.",
})
check("policy editor saves", code == 302 and "saved=1" in (loc or ""), f"loc={loc}")

code, html, _ = anon.get("/privacy")
check("the live page shows the edit", "Edited by the end-to-end test." in html)

# ...and put it back. Restored through the same editor the test just drove,
# so a round trip through the form is part of what is being checked -- and so
# nothing has to reproduce the policy text in this file.
tok = a.token("/admin/pages?slug=privacy")
a.post("/admin/pages", {"_token": tok, "slug": "privacy",
                        "title": _restore_title, "body": _restore_body})
_, _privacy_now, _ = anon.get("/privacy")
check("the privacy page is left as it was",
      "Edited by the end-to-end test." not in _privacy_now)

tok = a.token("/admin/faq")
code, _, loc = a.post("/admin/faq", {
    "_token": tok, "action": "save", "id": "0",
    "question": "Test question from the end-to-end run?",
    "answer": "Yes.", "published": "1",
})
check("FAQ add saves", code == 302, f"loc={loc}")

code, html, _ = anon.get("/faq")
check("the new question is live", "Test question from the end-to-end run?" in html)

_, html, _ = a.get("/admin/faq")
m = re.findall(r'href="/admin/faq\?edit=(\d+)"', html)
new_id = m[-1] if m else ""
tok = a.token("/admin/faq")
code, _, loc = a.post("/admin/faq", {"_token": tok, "action": "delete", "id": new_id})
check("FAQ remove deletes", code == 302 and "deleted=1" in (loc or ""), f"loc={loc}")

code, html, _ = anon.get("/faq")
check("the removed question is gone", "Test question from the end-to-end run?" not in html)

print("\n--- every page renders to the end ---")
# A page that dies after its content still answers 200 with most of its HTML,
# so checking for a string in it proves nothing. The footer is the last thing
# written, which makes </body> the honest test — this is how a missing
# require was found after it had passed every other check.
PAGES = ["/", "/blasters", "/gear", "/parts", "/marketplace", "/sell", "/request",
         "/faq", "/safety", "/games", "/policy", "/terms", "/privacy", "/search",
         "/login", "/register", "/cart", "/404"]

anon2 = Client()
broken = []
for path in PAGES:
    code, html, loc = anon2.get(path)
    if code == 302:
        continue                      # sign-in required: not a rendering fault
    if "</body>" not in html or "Fatal error" in html or "Warning:" in html:
        broken.append(path)

check("every public page reaches its footer", broken == [], f"broken: {broken}")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
