"""Render the six site illustrations to the exact JPGs the slots expect.

Drawn as SVG (hand-written shapes, nothing generated), screenshotted by
headless Chrome at the final pixel size, then turned into JPEG by PHP GD so
the file the server receives is a plain baseline JPEG.
"""
import os
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = r"C:\Users\adnan\Documents\repos\gelsoft-india\assets\img"
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"

BG = "#EEEAE1"

MESH = ('<pattern id="mesh" width="5" height="5" patternUnits="userSpaceOnUse">'
        '<path d="M0 0L5 5M5 0L0 5" stroke="#15171A" stroke-width="0.8" fill="none"/></pattern>')

GOGGLES = ('<path d="M0 22 Q45 12 90 22" fill="none" stroke="#4C5A3C" stroke-width="5"/>'
           '<rect x="8" y="6" width="74" height="34" rx="14" fill="#15171A"/>'
           '<path d="M16 12 H41 Q45 24 41 34 H22 Q12 34 14 22 Z" fill="#6E7D86"/>'
           '<path d="M74 12 H49 Q45 24 49 34 H68 Q78 34 76 22 Z" fill="#6E7D86"/>'
           '<path d="M45 30 Q42 36 45 40 Q48 36 45 30" fill="' + BG + '"/>')

ART = {
    # 200 x 112.5 canvas = 16:9. Aisle cards crop to roughly the middle
    # 200 x 80, so every aisle subject sits between y=16 and y=96.
    "aisle-blasters.jpg": (800, 450, """
<g transform="translate(14,26)" fill="#15171A">
<path d="M0 14 L30 10 L30 32 L6 40 L0 38 Z"/>
<rect x="28" y="14" width="24" height="9" rx="2"/>
<rect x="50" y="9" width="46" height="21" rx="2"/>
<rect x="50" y="4" width="86" height="5" rx="1"/>
<rect x="94" y="9" width="46" height="17" rx="2"/>
<rect x="139" y="15" width="20" height="5"/>
<rect x="158" y="12" width="13" height="11" rx="2"/>
<path d="M56 30 L66 30 L62 52 L52 51 Z"/>
<path d="M74 30 L87 30 L93 58 L80 61 Z"/>
<path d="M66 30 Q70 40 76 30" fill="none" stroke="#15171A" stroke-width="2"/>
<g fill="BG"><rect x="100" y="14" width="6" height="2"/><rect x="110" y="14" width="6" height="2"/><rect x="120" y="14" width="6" height="2"/><rect x="130" y="14" width="6" height="2"/></g>
<rect x="74" y="13" width="14" height="3" fill="#B14A24"/>
</g>"""),
    "aisle-gear.jpg": (800, 450, """
<g transform="translate(23,28) scale(0.88)">GOGGLES
<g transform="translate(96,0)">
<path d="M4 10 Q36 -2 68 10 L62 52 Q36 66 10 52 Z" fill="url(#mesh)" stroke="#15171A" stroke-width="4"/>
<path d="M0 14 L-6 30 M72 14 L78 30" stroke="#4C5A3C" stroke-width="4"/>
</g></g>"""),
    "aisle-parts.jpg": (800, 450, """
<g transform="translate(18,17)">
<rect x="0" y="12" width="92" height="46" rx="6" fill="#B9BCBE" stroke="#15171A" stroke-width="2"/>
<rect x="10" y="20" width="44" height="12" rx="2" fill="#C9A86A" stroke="#15171A" stroke-width="1.5"/>
<path d="M60 40 L70 40 L67 62 L58 60 Z" fill="#15171A"/>
<circle cx="130" cy="22" r="17" fill="none" stroke="#15171A" stroke-width="7" stroke-dasharray="4 3"/>
<circle cx="130" cy="22" r="13" fill="#5B6066"/>
<circle cx="130" cy="22" r="4" fill="BG"/>
<circle cx="146" cy="56" r="13" fill="none" stroke="#15171A" stroke-width="6" stroke-dasharray="3.5 2.5"/>
<circle cx="146" cy="56" r="10" fill="#5B6066"/>
<circle cx="146" cy="56" r="3" fill="BG"/>
<rect x="6" y="66" width="70" height="10" rx="2" fill="#15171A"/>
<g fill="BG"><rect x="36" y="68" width="3" height="6"/><rect x="43" y="68" width="3" height="6"/><rect x="50" y="68" width="3" height="6"/><rect x="57" y="68" width="3" height="6"/><rect x="64" y="68" width="3" height="6"/></g>
<rect x="76" y="64" width="12" height="14" rx="3" fill="#B14A24"/>
</g>"""),
    "safety-eyepro.jpg": (1200, 675, """
<g transform="translate(32,26) scale(1.5)">GOGGLES</g>"""),
    "safety-transport.jpg": (1200, 675, """
<path d="M72 40 Q100 16 128 40" fill="none" stroke="#15171A" stroke-width="4"/>
<rect x="22" y="38" width="156" height="52" rx="12" fill="#4C5A3C"/>
<path d="M32 48 H168" stroke="BG" stroke-width="2" stroke-dasharray="3 2"/>
<rect x="158" y="44" width="8" height="10" rx="2" fill="#15171A"/>
<rect x="36" y="62" width="40" height="18" rx="3" fill="#3B472E"/>"""),
    "safety-ground.jpg": (1200, 675, """
<rect x="10" y="74" width="180" height="30" rx="4" fill="#CFC6AE"/>
<rect x="27" y="58" width="4" height="18" fill="#5A4A36"/><circle cx="29" cy="50" r="13" fill="#4C5A3C"/>
<rect x="53" y="54" width="5" height="22" fill="#5A4A36"/><circle cx="55" cy="42" r="17" fill="#3E4A31"/>
<rect x="96" y="58" width="34" height="22" rx="2" fill="#15171A"/><path d="M96 69 H130" stroke="#4A4D52" stroke-width="2"/>
<rect x="142" y="60" width="16" height="20" rx="3" fill="#B14A24"/>
<path d="M10 68 H190" stroke="#8C887E" stroke-width="1"/><path d="M170 58 V74 M185 58 V74 M170 62 H185" stroke="#8C887E" stroke-width="2"/>"""),
}

for name, (w, h, body) in ART.items():
    body = body.replace("GOGGLES", GOGGLES).replace('"BG"', '"%s"' % BG)
    svg = ('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 200 112.5">'
           '<defs>%s</defs><rect width="200" height="112.5" fill="%s"/>%s</svg>') % (w, h, MESH, BG, body)
    base = os.path.join(HERE, name[:-4])
    open(base + ".svg", "w", encoding="utf-8").write(svg)
    html = ('<!doctype html><html><head><style>html,body{margin:0;padding:0;overflow:hidden;background:%s}'
            'svg{display:block}</style></head><body>%s</body></html>') % (BG, svg)
    open(base + ".html", "w", encoding="utf-8").write(html)
    png = base + ".png"
    if os.path.exists(png):
        os.remove(png)
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars",
                    "--force-device-scale-factor=1", "--window-size=%d,%d" % (w, h),
                    "--screenshot=" + png, "file:///" + (base + ".html").replace("\\", "/")],
                   capture_output=True, timeout=60)
    out = os.path.join(OUT, name)
    r = subprocess.run(["php", "-r",
                        '$i=imagecreatefrompng($argv[1]); imageinterlace($i,false);'
                        'imagejpeg($i,$argv[2],90); $s=getimagesize($argv[2]);'
                        'echo $s[0],"x",$s[1]," ",filesize($argv[2])," bytes";',
                        png, out], capture_output=True, text=True)
    print("%-22s %s %s" % (name, r.stdout, r.stderr.strip()))
