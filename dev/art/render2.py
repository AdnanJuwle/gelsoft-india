"""The remaining site images: hero (B, plus A as a spare), aisle-request,
og-image and apple-touch-icon. Hand-drawn SVG, rendered by headless Chrome at
the exact pixel size, converted by PHP GD. Same shapes and palette as the six
already on the site.
"""
import os
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = r"C:\Users\adnan\Documents\repos\gelsoft-india\assets\img"
SPARE = os.path.join(HERE, "spare")
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
os.makedirs(SPARE, exist_ok=True)

INK, PAPER, DARK, EMBER, OLIVE = "#15171A", "#F4F2ED", "#262A30", "#B14A24", "#4C5A3C"
CARD = "#EEEAE1"
LIGHT = "#D8D3C6"

RIFLE = ('<g fill="{c}"><path d="M0 14 L30 10 L30 32 L6 40 L0 38 Z"/><rect x="28" y="14" width="24" height="9" rx="2"/>'
         '<rect x="50" y="9" width="46" height="21" rx="2"/><rect x="50" y="4" width="86" height="5" rx="1"/>'
         '<rect x="94" y="9" width="46" height="17" rx="2"/><rect x="139" y="15" width="20" height="5"/>'
         '<rect x="158" y="12" width="13" height="11" rx="2"/><path d="M56 30 L66 30 L62 52 L52 51 Z"/>'
         '<path d="M74 30 L87 30 L93 58 L80 61 Z"/><path d="M66 30 Q70 40 76 30" fill="none" stroke="{c}" stroke-width="2"/>'
         '<g fill="{bg}"><rect x="100" y="14" width="6" height="2"/><rect x="110" y="14" width="6" height="2"/>'
         '<rect x="120" y="14" width="6" height="2"/><rect x="130" y="14" width="6" height="2"/></g>'
         '<rect x="74" y="13" width="14" height="3" fill="' + EMBER + '"/></g>')
PISTOL = ('<g fill="{c}"><rect x="0" y="0" width="64" height="12" rx="2"/><rect x="4" y="12" width="46" height="6"/>'
          '<path d="M8 18 L22 18 L19 42 L5 40 Z"/><path d="M22 18 Q28 28 34 18" fill="none" stroke="{c}" stroke-width="2"/>'
          '<rect x="40" y="3" width="18" height="2" fill="' + EMBER + '"/></g>')
GOGGLES = ('<path d="M0 22 Q45 12 90 22" fill="none" stroke="' + OLIVE + '" stroke-width="5"/>'
           '<rect x="8" y="6" width="74" height="34" rx="14" fill="#0E1012"/>'
           '<path d="M16 12 H41 Q45 24 41 34 H22 Q12 34 14 22 Z" fill="#6E7D86"/>'
           '<path d="M74 12 H49 Q45 24 49 34 H68 Q78 34 76 22 Z" fill="#6E7D86"/>'
           '<path d="M45 30 Q42 36 45 40 Q48 36 45 30" fill="{bg}"/>')

FONT = ("<style>@import url('https://fonts.googleapis.com/css2?family=Archivo:wght@500;800&display=block');"
        "text{font-family:Archivo,'Segoe UI',Arial,sans-serif}</style>")


def rifle(c, bg):
    return RIFLE.format(c=c, bg=bg)


# name -> (w, h, viewBox, background, body, out_dir, fmt)
ART = {
    # 310 x 174.4 = 16:9. On the homepage the panel is cropped a little at
    # the sides and the "Alert me" card covers the bottom-right corner, so
    # everything sits up and left of that corner.
    "hero-blaster.jpg": (1600, 900, "0 0 310 174.375", DARK,
        '<g transform="translate(58,26) scale(1.15)">' + rifle(LIGHT, DARK) + '</g>'
        '<g transform="translate(88,106) scale(1.05)">' + PISTOL.format(c=LIGHT) + '</g>'
        '<g transform="translate(170,102) scale(0.85)">' + GOGGLES.format(bg=DARK) + '</g>', OUT, "jpg"),
    "hero-blaster-option-A.jpg": (1600, 900, "0 0 310 174.375", DARK,
        '<g transform="translate(36,44) scale(1.38)">' + rifle(LIGHT, DARK) + '</g>', SPARE, "jpg"),
    # A plain, unmarked parcel -- the site promises plain packaging, so no
    # writing on it. Kept in the middle band the aisle card shows.
    "aisle-request.jpg": (800, 450, "0 0 200 112.5", CARD,
        '<ellipse cx="101" cy="93" rx="52" ry="7" fill="#DDD6C7"/>'
        '<path d="M62 46 L100 32 L140 46 L102 60 Z" fill="#D9B98A"/>'
        '<path d="M62 46 L102 60 L102 96 L62 82 Z" fill="#C49A62"/>'
        '<path d="M102 60 L140 46 L140 82 L102 96 Z" fill="#B0864F"/>'
        '<path d="M80 39.4 L120 53.4 L120 53.4" stroke="#E9D4AE" stroke-width="5" fill="none"/>'
        '<path d="M120 53 L120 89" stroke="#CDAE7D" stroke-width="5"/>'
        '<path d="M72 61 L92 68 L92 78 L72 71 Z" fill="' + PAPER + '"/>'
        '<path d="M75 65 L89 70 M75 69 L85 72.5" stroke="#9A968C" stroke-width="1"/>'
        '<path d="M146 76 L160 70 M150 86 L168 79" stroke="' + EMBER + '" stroke-width="2.5" stroke-linecap="round"/>', OUT, "jpg"),
    # 1200 x 630. WhatsApp often shows a square crop from the middle, so the
    # wordmark and the blaster both fit inside the central 630 x 630.
    "og-image.jpg": (1200, 630, "0 0 1200 630", DARK,
        FONT +
        '<text x="600" y="168" text-anchor="middle" fill="' + PAPER + '" font-size="112" font-weight="800" letter-spacing="7">GELSOFT</text>'
        '<text x="600" y="214" text-anchor="middle" fill="#A9A69E" font-size="26" font-weight="500" letter-spacing="9">INDIA</text>'
        '<g transform="translate(330,262) scale(3.16)">' + rifle(LIGHT, DARK) + '</g>'
        '<rect x="570" y="482" width="60" height="5" fill="' + EMBER + '"/>'
        '<text x="600" y="548" text-anchor="middle" fill="' + LIGHT + '" font-size="34" font-weight="500">Gel blasters, gear and parts. Shipped across India.</text>',
        OUT, "jpg"),
}

for name, (w, h, vb, bg, body, out_dir, fmt) in ART.items():
    svg = ('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="%s">'
           '<rect width="100%%" height="100%%" fill="%s"/>%s</svg>') % (w, h, vb, bg, body)
    base = os.path.join(HERE, name.rsplit(".", 1)[0])
    open(base + ".svg", "w", encoding="utf-8").write(svg)
    html = ('<!doctype html><html><head><style>html,body{margin:0;padding:0;overflow:hidden;background:%s}'
            'svg{display:block}</style></head><body>%s</body></html>') % (bg, svg)
    open(base + ".html", "w", encoding="utf-8").write(html)
    png = base + ".png"
    if os.path.exists(png):
        os.remove(png)
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars",
                    "--force-device-scale-factor=1", "--window-size=%d,%d" % (w, h),
                    "--virtual-time-budget=8000",
                    "--screenshot=" + png, "file:///" + (base + ".html").replace("\\", "/")],
                   capture_output=True, timeout=90)
    out = os.path.join(out_dir, name)
    conv = ('$i=imagecreatefrompng($argv[1]);'
            + ('imageinterlace($i,false);imagejpeg($i,$argv[2],90);' if fmt == "jpg"
               else 'imagesavealpha($i,false);imagepng($i,$argv[2],9);')
            + '$s=getimagesize($argv[2]);echo $s[0],"x",$s[1]," ",filesize($argv[2])," bytes";')
    r = subprocess.run(["php", "-r", conv, png, out], capture_output=True, text=True)
    print("%-28s %s %s" % (name, r.stdout, r.stderr.strip()))
