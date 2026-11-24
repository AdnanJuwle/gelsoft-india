"""Admin staff page: granting, removing, and the two lockouts it must refuse."""
import http.cookiejar
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://localhost:8099"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

ADMIN = "admin@gelsoft.in"
ADMIN_PW = "devpassword123"

A_EMAIL, A_PW = "staff.alice@example.com", "alice long pass phrase"
B_EMAIL, B_PW = "staff.bob@example.com", "bob long pass phrase"


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

    def token(self, p):
        _, h, _ = self.get(p)
        m = re.search(r'name="_token" value="([a-f0-9]+)"', h)
        return m.group(1) if m else ""

    def login(self, email, pw, where="/login"):
        t = self.token(where)
        return self.post(where, {"_token": t, "email": email, "password": pw, "next": "/account"})


passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  ok   {label}")
    else:
        failed += 1
        print(f"  FAIL {label} {detail}")


def role(email):
    return sql(f"SELECT role FROM users WHERE email='{email}'")


def register(email, pw, name):
    sql(f"DELETE FROM users WHERE email='{email}'")
    c = C()
    t = c.token("/register")
    c.post("/register", {"_token": t, "name": name, "email": email, "password": pw,
                         "next": "/account"})
    return c


print("--- fixtures ---")
register(A_EMAIL, A_PW, "Alice Staff")
register(B_EMAIL, B_PW, "Bob Staff")
check("two ordinary accounts exist", role(A_EMAIL) == "customer" and role(B_EMAIL) == "customer",
      f"{role(A_EMAIL)} / {role(B_EMAIL)}")
check("the fixture account is the owner", role(ADMIN) == "owner", role(ADMIN))

print("\n--- who may open it ---")
code, html, loc = C().get("/admin/staff")
check("a stranger is sent to the admin sign-in", code == 302 and "/admin/login" in (loc or ""),
      f"{code} {loc}")

cust = C()
cust.login(A_EMAIL, A_PW)
code, html, _ = cust.get("/admin/staff")
check("a signed-in customer gets 403, not a redirect loop", code == 403, f"code={code}")

adm = C()
st, _, _ = adm.login(ADMIN, ADMIN_PW, "/admin/login")
code, html, _ = adm.get("/admin/staff")
check("the owner gets the page", code == 200 and "Give someone access" in html, f"code={code}")
check("it lists the admin accounts", ADMIN in html)
check("the page finished rendering", "</body>" in html)


def promote(client, email, confirm, role="admin"):
    t = client.token("/admin/staff")
    return client.post("/admin/staff", {"_token": t, "action": "promote", "role": role,
                                        "email": email, "confirm": confirm})


def demote(client, uid, confirm):
    t = client.token("/admin/staff")
    return client.post("/admin/staff", {"_token": t, "action": "demote",
                                        "id": uid, "confirm": confirm})


print("\n--- granting ---")
code, html, _ = promote(adm, A_EMAIL, "not my password")
check("a wrong password is refused", "not your password" in html, html[:160])
check("...and nothing was granted", role(A_EMAIL) == "customer", role(A_EMAIL))
check("...and the failure is logged",
      int(sql("SELECT COUNT(*) FROM admin_log WHERE action='staff_confirm_failed'") or 0) >= 1)

code, html, loc = promote(adm, "ghost@example.com", ADMIN_PW)
check("an address with no account says so", "No account uses that address" in html)

code, html, loc = promote(adm, "not an address", ADMIN_PW)
check("junk is refused", "does not look like an email" in html)

code, html, loc = promote(adm, A_EMAIL, ADMIN_PW)
check("a real account is promoted", code == 302 and "done=promoted" in (loc or ""), f"{code} {loc}")
check("...the role actually changed", role(A_EMAIL) == "admin", role(A_EMAIL))
check("...and it is in the admin log",
      int(sql("SELECT COUNT(*) FROM admin_log WHERE action='staff_admin'") or 0) >= 1)

code, html, _ = promote(adm, A_EMAIL, ADMIN_PW)
check("promoting someone who already has it says so", "already has that access" in html)

print("\n--- the newly promoted admin can actually get in ---")
alice = C()
alice.login(A_EMAIL, A_PW, "/admin/login")
code, html, _ = alice.get("/admin/staff")
check("Alice reaches the panel with her own password", code == 200, f"code={code}")

print("\n--- removing ---")
aid = int(sql(f"SELECT id FROM users WHERE email='{A_EMAIL}'") or 0)
mid = int(sql(f"SELECT id FROM users WHERE email='{ADMIN}'") or 0)

code, html, _ = demote(adm, mid, ADMIN_PW)
check("you cannot remove yourself", "cannot remove your own" in html, html[:160])
check("...and you still have access", role(ADMIN) == "owner")

code, html, _ = demote(adm, aid, "wrong one")
check("removing needs your password too", "not your password" in html)
check("...and Alice is still an admin", role(A_EMAIL) == "admin")

bid = int(sql(f"SELECT id FROM users WHERE email='{B_EMAIL}'") or 0)
code, html, _ = demote(adm, bid, ADMIN_PW)
check("removing a non-admin says so", "does not have access" in html)

code, html, loc = demote(adm, aid, ADMIN_PW)
check("removing a real admin works", code == 302 and "done=removed" in (loc or ""), f"{code} {loc}")
check("...the role went back to customer", role(A_EMAIL) == "customer", role(A_EMAIL))
check("...and it is logged",
      int(sql("SELECT COUNT(*) FROM admin_log WHERE action='staff_remove'") or 0) >= 1)

print("\n--- Alice is locked out again ---")
alice2 = C()
alice2.login(A_EMAIL, A_PW, "/admin/login")
code, html, _ = alice2.get("/admin/staff")
check("a demoted admin can no longer open the panel", code in (302, 403), f"code={code}")

print("\n--- the last admin ---")
n = int(sql("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner')") or 0)
check("one account is left for this test", n == 1, f"{n} with access")
code, html, _ = adm.get("/admin/staff")
check("the page says there is nobody to remove", "nobody to remove" in html, "")
# Being the last admin AND being yourself coincide here, and self-removal is
# what the form actually hits. The last-admin guard proper is unreachable
# through the UI -- staff_guard.php exercises it at the model instead.
code, html, _ = demote(adm, mid, ADMIN_PW)
check("POSTing your own id straight at it is still refused",
      "cannot remove your own" in html)
check("...still the owner", role(ADMIN) == "owner")

print("\n--- no CSRF, no change ---")
code, html, _ = adm.post("/admin/staff", {"action": "promote", "email": A_EMAIL, "confirm": ADMIN_PW})
check("the form needs a CSRF token", code == 419, f"code={code}")
check("...and nothing changed", role(A_EMAIL) == "customer")

print("\n--- a customer cannot promote themselves ---")
t = cust.token("/admin/staff")
code, html, _ = cust.post("/admin/staff", {"_token": t or "x", "action": "promote",
                                           "email": A_EMAIL, "confirm": A_PW})
check("a customer POSTing straight at it is refused", code in (403, 419), f"code={code}")
check("...and is still a customer", role(A_EMAIL) == "customer")

print("\n--- an admin is not an owner ---")
# The whole point of the two roles: an admin who is compromised must not be
# able to lock the owner out, remove the other admins, or add one of their own.
sql(f"UPDATE users SET role='admin' WHERE email='{A_EMAIL}'")
adm2 = C()
adm2.login(A_EMAIL, A_PW, "/admin/login")

code, html, _ = adm2.get("/admin/")
check("an admin still reaches the panel", code == 200, f"code={code}")

code, html, _ = adm2.get("/admin/staff")
check("...and can read the staff list", code == 200 and A_EMAIL in html, f"code={code}")
check("...but is not offered the forms", "Give someone access" not in html)
check("...and is told why", "Only an owner" in html)

code, html, _ = promote(adm2, B_EMAIL, A_PW)
check("an admin cannot promote anyone", code == 403, f"code={code}")
check("...and nobody was promoted", role(B_EMAIL) == "customer", role(B_EMAIL))

code, html, _ = promote(adm2, A_EMAIL, A_PW, role="owner")
check("an admin cannot make themselves the owner", code == 403, f"code={code}")
check("...and is still only an admin", role(A_EMAIL) == "admin", role(A_EMAIL))

code, html, _ = demote(adm2, mid, A_PW)
check("an admin cannot remove the owner", code == 403, f"code={code}")
check("...the owner still has it", role(ADMIN) == "owner", role(ADMIN))

# An admin keeps every power the shop needs; only this screen is withheld.
for path in ("/admin/orders", "/admin/marketplace", "/admin/faq", "/admin/community"):
    code, _, _ = adm2.get(path)
    check(f"an admin still runs {path}", code == 200, f"code={code}")

print("\n--- a second owner ---")
promote(adm, A_EMAIL, ADMIN_PW, role="owner")
check("the owner can make a second owner", role(A_EMAIL) == "owner", role(A_EMAIL))

owner2 = C()
owner2.login(A_EMAIL, A_PW, "/admin/login")
code, html, _ = owner2.get("/admin/staff")
check("the second owner gets the forms", "Give someone access" in html)

# Deliberately: an owner CAN remove another owner. That is what makes it a
# real second key rather than a decoration -- and why the form warns first.
demote(owner2, mid, A_PW)
check("an owner can remove another owner", role(ADMIN) == "customer", role(ADMIN))

# Restore the fixture before the rest of the suite.
sql(f"UPDATE users SET role='owner' WHERE email='{ADMIN}'")
sql(f"UPDATE users SET role='customer' WHERE email='{A_EMAIL}'")
check("the fixture is restored", role(ADMIN) == "owner", role(ADMIN))

print("\n--- the dashboard tile ---")
code, html, _ = adm.get("/admin/")
check("the dashboard has an Admins tab", 'data-tab="staff"' in html)
check("...and it links to the screen", '/admin/staff' in html)

print("\n--- cleaning up ---")
sql(f"DELETE FROM users WHERE email IN ('{A_EMAIL}','{B_EMAIL}')")
sql("DELETE FROM admin_log WHERE action LIKE 'staff_%'")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
