"""A site photo goes live by uploading the file, and only then.

Drops throwaway test images into assets/img/, fetches the pages, and removes
them again in `finally` -- the repo must end exactly as it started.
"""
import os
import re
import subprocess
import sys
import urllib.request

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
REPO = r"C:\Users\adnan\Documents\repos\gelsoft-india"
IMG = os.path.join(REPO, "assets", "img")
BASE = "http://localhost:8099"

passed = failed = 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print("  ok   " + label)
    else:
        failed += 1
        print("  FAIL %s %s" % (label, detail))


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=20) as r:
        return r.status, r.read().decode("utf-8", "replace")


def status(path):
    try:
        with urllib.request.urlopen(BASE + path, timeout=20) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def make(name, w, h, kind):
    fn = "imagejpeg" if kind == "jpg" else "imagepng"
    subprocess.run(["php", "-r", "$i=imagecreatetruecolor(%d,%d);%s($i,$argv[1]);" % (w, h, fn),
                    os.path.join(IMG, name)], check=True)


ALL = ["hero-blaster.jpg", "aisle-blasters.jpg", "aisle-gear.jpg", "aisle-parts.jpg",
       "aisle-request.jpg", "safety-eyepro.jpg", "safety-transport.jpg",
       "safety-ground.jpg", "og-image.jpg", "apple-touch-icon.png"]
# Real site images are set aside for the run and put back in `finally`, byte
# for byte, so the test sees an empty folder without ever losing a photo.
import shutil
ASIDE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "photos_set_aside")
os.makedirs(ASIDE, exist_ok=True)
pre_existing = [n for n in ALL if os.path.exists(os.path.join(IMG, n))]
for n in pre_existing:
    shutil.move(os.path.join(IMG, n), os.path.join(ASIDE, n))

made = []
try:
    print("--- nothing uploaded: placeholders, and no broken share picture ---")
    _, home = get("/")
    for n in ALL[:5]:
        check("home shows the %s placeholder" % n, '<span class="ph__name">%s</span>' % n in home)
    check("no og:image pointing at a file that is not there", "og:image" not in home)
    check("twitter card falls back to summary", 'name="twitter:card" content="summary"' in home)
    check("no apple-touch-icon link before the icon exists", "apple-touch-icon" not in home)
    _, safety = get("/safety")
    for n in ALL[5:8]:
        check("safety shows the %s placeholder" % n, '<span class="ph__name">%s</span>' % n in safety)

    print("\n--- upload one aisle photo, the share card and the touch icon ---")
    for n, w, h, k in [("aisle-gear.jpg", 800, 450, "jpg"), ("og-image.jpg", 1200, 630, "jpg"),
                       ("apple-touch-icon.png", 180, 180, "png"), ("safety-ground.jpg", 1200, 675, "jpg")]:
        make(n, w, h, k)
        made.append(n)
    _, home = get("/")
    m = re.search(r'<img class="ph-img" src="(/assets/img/aisle-gear\.jpg\?v=\d+)"', home)
    check("aisle-gear.jpg now shows as a photo, versioned", m is not None)
    check("...and its placeholder is gone", '<span class="ph__name">aisle-gear.jpg</span>' not in home)
    if m:
        check("...and the URL it names actually serves", status(m.group(1)) == 200)
    for n in ["hero-blaster.jpg", "aisle-blasters.jpg", "aisle-parts.jpg", "aisle-request.jpg"]:
        check("%s is still a placeholder" % n, '<span class="ph__name">%s</span>' % n in home)
    og = re.search(r'<meta property="og:image" content="([^"]+)"', home)
    check("og:image names og-image.jpg, absolute", og is not None and
          re.match(r"https?://[^/]+/assets/img/og-image\.jpg\?v=\d+$", og.group(1)) is not None,
          og.group(1) if og else "missing")
    check("twitter card is the large one", 'content="summary_large_image"' in home)
    check("apple-touch-icon is linked", re.search(r'rel="apple-touch-icon" href="/assets/img/apple-touch-icon\.png\?v=\d+"', home) is not None)
    _, safety = get("/safety")
    check("safety-ground.jpg shows on /safety", 'src="/assets/img/safety-ground.jpg?v=' in safety)
    check("...with real alt text", re.search(r'safety-ground\.jpg\?v=\d+" alt="[^"]+"', safety) is not None)
    check("other safety slots still placeholders",
          '<span class="ph__name">safety-eyepro.jpg</span>' in safety
          and '<span class="ph__name">safety-transport.jpg</span>' in safety)

    print("\n--- no og-image.jpg but a hero photo: the hero is the share picture ---")
    os.remove(os.path.join(IMG, "og-image.jpg"))
    made.remove("og-image.jpg")
    make("hero-blaster.jpg", 1600, 900, "jpg")
    made.append("hero-blaster.jpg")
    _, home = get("/")
    og = re.search(r'<meta property="og:image" content="([^"]+)"', home)
    check("og:image falls back to hero-blaster.jpg", og is not None and "/assets/img/hero-blaster.jpg?v=" in og.group(1))
    check("hero shows as a photo, loaded eagerly",
          re.search(r'src="/assets/img/hero-blaster\.jpg\?v=\d+" alt="[^"]+" width="1600" height="900" fetchpriority="high"', home) is not None)

    print("\n--- a page with its own picture keeps it ---")
    _, src = 0, open(os.path.join(REPO, "inc", "header.php"), encoding="utf-8").read()
    check("$page['image'] still wins over the site card", "$share_image = $page['image']\n    ?:" in src)
finally:
    for n in made:
        p = os.path.join(IMG, n)
        if os.path.exists(p):
            os.remove(p)
    for n in pre_existing:
        shutil.move(os.path.join(ASIDE, n), os.path.join(IMG, n))

left = [n for n in ALL if os.path.exists(os.path.join(IMG, n))]
check("test images all removed, real ones all back", sorted(left) == sorted(pre_existing),
      "now %s, expected %s" % (left, pre_existing))
print("\n%d passed, %d failed" % (passed, failed))
sys.exit(1 if failed else 0)
