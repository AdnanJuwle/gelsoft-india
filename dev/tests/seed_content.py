"""Lift the FAQ and the policy text out of the pages and into the database,
so the admin screens have something real to edit. Run once."""
import io
import os
import re
import subprocess
import sys

ROOT = r"C:\Users\adnan\Documents\repos\gelsoft-india"
MYSQL = r"C:\Program Files\MariaDB 13.0\bin\mariadb.exe"

faq = io.open(os.path.join(ROOT, "faq.php"), encoding="utf-8").read()

items = re.findall(
    r'<button class="acc__q"[^>]*>(.*?)\s*<span class="acc__icon".*?</button></h2>\s*'
    r'<div class="acc__a"[^>]*>(.*?)</div>',
    faq, re.S)

print(f"found {len(items)} questions")


def clean(html):
    html = re.sub(r"</p>\s*<p>", "\n\n", html)
    html = re.sub(r"</?p>", "", html)
    html = re.sub(r"<a [^>]*>(.*?)</a>", r"\1", html, flags=re.S)
    html = re.sub(r"<[^>]+>", "", html)
    repl = {
        "&mdash;": "\u2014", "&ndash;": "\u2013", "&rsquo;": "\u2019",
        "&lsquo;": "\u2018", "&ldquo;": "\u201c", "&rdquo;": "\u201d",
        "&#8377;": "\u20b9", "&amp;": "&", "&nbsp;": " ", "&middot;": "\u00b7",
        "&hellip;": "\u2026",
    }
    for k, v in repl.items():
        html = html.replace(k, v)
    return re.sub(r"\n{3,}", "\n\n", html).strip()


rows = []
for i, (q, a) in enumerate(items, start=1):
    rows.append((clean(q), clean(a), i * 10))

# The policy page, as plain text the admin can edit.
policy = """All sales are final. We do not accept returns for refunds or exchanges.

We replace items damaged during shipment on submission of an uncut unboxing video. Every item is inspected for function and quality before dispatch.

We do not offer warranties or guarantees on our products.

To start a replacement, email support@gelsoft.in or message gelsoftindia on Instagram. Returns without prior authorisation will not be accepted."""

terms = """Gelsoft India sells gel blasters, protective gear and parts within India.

Owning a gel blaster is legal here. Displaying, brandishing or carrying one in public is not. By buying from us you agree to transport it concealed and to use it only on private ground with the owner's permission.

Prices are in Indian rupees and include GST where it applies. We may change a price at any time before an order is placed.

Marketplace listings are between the buyer and the seller. We hold the payment, review listings before they go live, and step in when a sale goes wrong, but we are not the seller."""

privacy = """We keep what we need to send you an order and to answer you when you ask: your name, email address, phone number and delivery address.

Payments go through our payment provider. Card and UPI details are entered on their page and never reach our server.

We do not sell your details to anyone, and we do not send marketing you did not ask for. A restock alert is one message about the thing you asked about.

To have your account and its history deleted, email support@gelsoft.in from the address on the account."""

sql = ["SET NAMES utf8mb4;", "DELETE FROM faq_entries;"]


def q(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"


for question, answer, order in rows:
    sql.append(
        f"INSERT INTO faq_entries (question, answer, sort_order, published) "
        f"VALUES ({q(question)}, {q(answer)}, {order}, 1);")

for slug, title, body in [
    ("policy", "Replacement & refund policy", policy),
    ("terms", "Terms", terms),
    ("privacy", "Privacy", privacy),
]:
    sql.append(
        f"INSERT INTO pages (slug, title, body) VALUES ({q(slug)}, {q(title)}, {q(body)}) "
        f"ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body);")

script = "\n".join(sql)
out = os.path.join(ROOT, "db", "seed-content.sql")
io.open(out, "w", encoding="utf-8", newline="\n").write(
    "-- Generated from the page copy: the FAQ and the policy pages, moved into\n"
    "-- the database so the admin panel can edit them. Safe to re-run; it\n"
    "-- replaces every FAQ entry.\n\n" + script + "\n")
print("wrote db/seed-content.sql")

r = subprocess.run([MYSQL, "-u", "gelsoft", "-pdevpass", "gelsoft"],
                   input=script.encode("utf-8"), capture_output=True)
err = r.stderr.decode(errors="replace")
print(err.replace("Warning: Using a password on the command line interface can be insecure.", "").strip() or "loaded ok")
