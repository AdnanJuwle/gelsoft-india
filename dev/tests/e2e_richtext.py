"""Product description formatting: renders what it promises, and nothing typed
can reach the page as HTML.

Every case goes through the real inc/richtext.php (via rt_probe.php), and the
real product page is checked with a formatted description swapped in and the
original put back in `finally`.
"""
import json
import os
import re
import subprocess
import sys
import urllib.request

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL %s %s" % (label, detail))


CASES = {
    "bold": "The **TG DDM4 PDW** is tan.",
    "italic": "*Being tested.*",
    "heading": "# Big\n## Mid\n### Key Features",
    "list": "- one\n- two\n\n1. first\n2. second",
    "table": "| Specification | Details |\n|---|---|\n| Brand | TG |\n| FPS | **250** |",
    "para": "line one\nline two\n\nnew para",
    "hr": "above\n\n---\n\nbelow",
    "link": "[Safety](/safety) and [TG](https://example.com/x)",
    "asterisks": "6 × Screws, 2*3 mm, a lone * star",
    # --- hostile input ---
    "script": "<script>alert(1)</script>",
    "bold_img": "**<img src=x onerror=alert(1)>**",
    "js_link": "[click](javascript:alert(1))",
    "quote_link": '[x](https://a.com/"onmouseover="alert(1))',
    "data_link": "[x](data:text/html,<script>alert(1)</script>)",
    "table_xss": "| a | b |\n|---|---|\n| <svg onload=alert(1)> | ok |",
    "heading_xss": "### <iframe src=//evil>",
}

r = subprocess.run(["php", os.path.join(HERE, "rt_probe.php")], input=json.dumps(CASES),
                   capture_output=True, text=True, encoding="utf-8")
out = json.loads(r.stdout)
H = {k: v["html"] for k, v in out.items()}
P = {k: v["plain"] for k, v in out.items()}

print("--- formatting ---")
check("**bold** becomes <strong>", "<strong>TG DDM4 PDW</strong>" in H["bold"], H["bold"])
check("*italic* becomes <em>", "<em>Being tested.</em>" in H["italic"], H["italic"])
check("every heading level is an h3 (page owns h1/h2)",
      H["heading"].count("<h3>") == 3 and "<h1" not in H["heading"] and "<h2" not in H["heading"], H["heading"])
check("- items become a <ul>", "<ul><li>one</li><li>two</li></ul>" in H["list"], H["list"])
check("1. items become an <ol>", "<ol><li>first</li><li>second</li></ol>" in H["list"], H["list"])
check("table: header cells are th scope=col",
      '<th scope="col">Specification</th><th scope="col">Details</th>' in H["table"], H["table"])
check("table: rows are td, formatting inside cells works",
      "<td>Brand</td><td>TG</td>" in H["table"] and "<td><strong>250</strong></td>" in H["table"], H["table"])
check("table: the |---| rule is not printed", "---" not in H["table"])
check("single newline is a line break, blank line a paragraph",
      H["para"] == "<p>line one<br>\nline two</p>\n<p>new para</p>", repr(H["para"]))
check("--- is a dividing line", "<hr>" in H["hr"])
check("site link works", '<a href="/safety">Safety</a>' in H["link"], H["link"])
check("external link opens safely",
      '<a href="https://example.com/x" rel="noopener nofollow" target="_blank">TG</a>' in H["link"], H["link"])
check("stray asterisks are left as text", "<em>" not in H["asterisks"] and "2*3" in H["asterisks"], H["asterisks"])

print("\n--- nothing typed becomes HTML ---")
check("<script> is printed as text", "<script" not in H["script"] and "&lt;script&gt;" in H["script"], H["script"])
check("an <img onerror> inside bold stays text",
      "<img" not in H["bold_img"] and "<strong>&lt;img" in H["bold_img"], H["bold_img"])
check("javascript: link is not a link", "<a " not in H["js_link"], H["js_link"])
check("data: link is not a link", "<a " not in H["data_link"], H["data_link"])
m = re.search(r'<a href="([^"]*)"', H["quote_link"])
check("a quote in a URL cannot break out of href",
      "onmouseover=\"" not in H["quote_link"] and (m is None or '"' not in m.group(1)), H["quote_link"])
check("tags in a table cell stay text", "<svg" not in H["table_xss"], H["table_xss"])
check("tags in a heading stay text", "<iframe" not in H["heading_xss"], H["heading_xss"])
tags = set()
for h in H.values():
    tags |= set(re.findall(r"<([a-z0-9]+)", h))
check("only the tags richtext writes itself ever appear",
      tags <= {"p", "br", "h3", "ul", "ol", "li", "strong", "em", "a", "hr", "div", "table", "thead",
               "tbody", "tr", "th", "td"}, str(sorted(tags)))

print("\n--- plain text for Google and share cards ---")
check("plain: no markers left in the table",
      "|" not in P["table"] and "---" not in P["table"] and "Brand, TG" in P["table"], P["table"])
check("plain: headings lose their hashes", "#" not in P["heading"] and "Key Features" in P["heading"], P["heading"])
check("plain: bold loses its asterisks", P["bold"] == "The TG DDM4 PDW is tan.", P["bold"])
check("plain: links keep their words only", P["link"] == "Safety and TG", P["link"])
check("plain: no tags survive", "<" not in P["script"], P["script"])

print("\n--- on the real product page ---")
PHP = ('$pdo=new PDO("mysql:host=127.0.0.1;dbname=gelsoft;charset=utf8mb4","gelsoft","devpass");'
       '$s=$pdo->prepare("SELECT description, summary FROM products WHERE id=1");$s->execute();'
       'echo json_encode($s->fetch(PDO::FETCH_ASSOC));')
orig = json.loads(subprocess.run(["php", "-r", PHP], capture_output=True, text=True).stdout)


def set_desc(desc, summary):
    subprocess.run(["php", "-r",
                    '$pdo=new PDO("mysql:host=127.0.0.1;dbname=gelsoft;charset=utf8mb4","gelsoft","devpass");'
                    '$d=json_decode(stream_get_contents(STDIN),true);'
                    '$pdo->prepare("UPDATE products SET description=?, summary=? WHERE id=1")->execute([$d[0],$d[1]]);'],
                   input=json.dumps([desc, summary]), text=True, check=True)


try:
    set_desc(open(os.path.join(HERE, "ddm4.md"), encoding="utf-8").read() + "\n\n<script>alert(1)</script>", "")
    with urllib.request.urlopen("http://localhost:8099/blasters/dev-tg-gen8-m4", timeout=20) as resp:
        page = resp.read().decode("utf-8")
    about = page.split('class="rt"', 1)[1].split("</section>", 1)[0] if 'class="rt"' in page else ""
    check("the description renders inside .rt", bool(about))
    check("...with its headings as h3", about.count("<h3>") == 5, str(about.count("<h3>")))
    check("...its table", '<div class="rt-table"><table class="table">' in about)
    check("...and no raw markers", "**" not in about and "|---" not in about and "### " not in about)
    check("...and the script printed as text, not run",
          "&lt;script&gt;alert(1)&lt;/script&gt;" in about and "<script>alert(1)" not in page)
    meta = re.search(r'<meta name="description" content="([^"]*)"', page)
    check("with no summary, the search snippet is plain words",
          meta is not None and "#" not in meta.group(1) and "**" not in meta.group(1)
          and meta.group(1).startswith("TG DDM4 PDW Electric Gel Blaster"), meta.group(1) if meta else "none")
    ld = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', page, re.S)]
    prod = [x for x in ld if x.get("@type") == "Product"]
    check("structured data description is plain words too",
          prod and "|" not in prod[0].get("description", "") and "**" not in prod[0].get("description", ""))
finally:
    set_desc(orig["description"], orig["summary"])

after = json.loads(subprocess.run(["php", "-r", PHP], capture_output=True, text=True).stdout)
check("test product put back exactly", after == orig)

print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
