"""favicon.svg, rendered to the PNGs that cannot be SVG.

  apple-touch-icon.png  180 x 180  iPhone home screen (iOS will not read SVG)
  favicon-192.png       192 x 192  Google's result icon (a multiple of 48, as
                                   Google asks) and the Organization logo

The site's own favicon.svg, not a redrawing of it. Adjustments only:
square corners (iOS rounds them itself, and shows black where it cuts a
rounded PNG); the light-mode colours pinned, because headless Chrome reports
dark mode and favicon.svg swaps colours for it; and the Archivo font loaded,
because the G is text.
"""
import os
import re
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
FAV = r"C:\Users\adnan\Documents\repos\gelsoft-india\assets\img\favicon.svg"
IMG = r"C:\Users\adnan\Documents\repos\gelsoft-india\assets\img"
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"

src = open(FAV, encoding="utf-8").read()
src = re.sub(r"<!--.*?-->", "", src, flags=re.S)
assert src.count('viewBox="0 0 32 32"') == 1

for size, name in [(180, "apple-touch-icon.png"), (192, "favicon-192.png")]:
    svg = src.replace('viewBox="0 0 32 32"', 'width="%d" height="%d" viewBox="0 0 32 32"' % (size, size), 1)
    base = os.path.join(HERE, name[:-4])
    html = ("<!doctype html><html><head><style>"
            "@import url('https://fonts.googleapis.com/css2?family=Archivo:wght@800&display=block');"
            "html,body{margin:0;padding:0;overflow:hidden;background:#15171A}svg{display:block}rect{rx:0;ry:0}"
            ".bg{fill:#15171A!important}.fg{fill:#F4F2ED!important}"
            "</style></head><body>" + svg + "</body></html>")
    open(base + ".html", "w", encoding="utf-8").write(html)
    png = base + ".png"
    if os.path.exists(png):
        os.remove(png)
    subprocess.run([CHROME, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
                    "--window-size=%d,%d" % (size, size), "--virtual-time-budget=8000", "--screenshot=" + png,
                    "file:///" + (base + ".html").replace(os.sep, "/")], capture_output=True, timeout=90)
    out = os.path.join(IMG, name)
    r = subprocess.run(["php", "-r", '$i=imagecreatefrompng($argv[1]);imagesavealpha($i,false);imagepng($i,$argv[2],9);'
                        '$s=getimagesize($argv[2]);echo $s[0],"x",$s[1]," ",filesize($argv[2])," bytes";', png, out],
                       capture_output=True, text=True)
    print(name, "(from favicon.svg)", r.stdout, r.stderr.strip())
