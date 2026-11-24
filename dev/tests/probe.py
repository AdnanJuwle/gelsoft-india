import http.cookiejar, re, urllib.error, urllib.parse, urllib.request
BASE = "http://localhost:8099"

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class C:
    def __init__(self):
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    def _do(self, req):
        try:
            r = self.op.open(req); return r.status, r.read().decode("utf-8","replace"), r.headers.get("Location")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8","replace"), e.headers.get("Location")
    def get(self, p): return self._do(urllib.request.Request(BASE+p))
    def post(self, p, d):
        r = urllib.request.Request(BASE+p, data=urllib.parse.urlencode(d).encode(), method="POST")
        r.add_header("Content-Type","application/x-www-form-urlencoded"); return self._do(r)
    def token(self, p):
        _, h, _ = self.get(p); m = re.search(r'name="_token" value="([a-f0-9]+)"', h); return m.group(1) if m else ""

o = C()
tok = o.token("/login")
o.post("/login", {"_token": tok, "email": "nosy.test@example.com", "password": "another long one", "next": "/account"})
tok = o.token("/")
print("token found:", bool(tok))
code, html, loc = o.post("/messages", {"_token": tok, "thread_id": "1", "kind": "message", "body": "butting in"})
print("code:", code, "loc:", loc)
m = re.findall(r'notice--paper.*?</p>', html, re.S)
print("says:", re.sub(r"\s+", " ", m[0])[:200] if m else html[:200])
