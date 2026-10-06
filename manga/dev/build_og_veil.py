#!/usr/bin/env python3
"""Build the content warning labels laid over blurred share images (OGP).

The design is SVG (manga/dev/og-veil/*.svg, written here) set in M PLUS Rounded 1c (SIL Open Font License).
Chromium draws each one as a transparent 1200x630 PNG in manga/server/viewer/og-veil/, which the server lays
over the blurred picture with GD and saves as WebP (lib/log-og.php). The server needs no font or FreeType.

Usage: python manga/dev/build_og_veil.py      (downloads the font into manga/dev/results/fonts on first run)
"""
import base64, shutil, urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
FONTS = ROOT / 'manga/dev/results/fonts'
SOURCE = ROOT / 'manga/dev/og-veil'
OUT = ROOT / 'manga/server/viewer/og-veil'
FONT_URL = 'https://github.com/google/fonts/raw/main/ofl/mplusrounded1c/'
LABELS = {
    # rating: (name, note, accent on dark)
    'sensitive': ('センシティブ', 'センシティブな内容を含みます', '#f6b04f'),
    'r18': ('R-18', '18歳未満の方は閲覧できません', '#ff6f8e'),
    'r18g': ('R-18G', '刺激の強い表現を含みます', '#b98bff'),
}


def font(name):
    path = FONTS / name
    if not path.is_file():
        FONTS.mkdir(parents=True, exist_ok=True)
        with urllib.request.urlopen(FONT_URL + name) as r, open(path, 'wb') as f: shutil.copyfileobj(r, f)
    return path


def svg(rating, name, note, accent, embed=True):
    faces = ''
    if embed:
        for weight, file in ((700, 'MPLUSRounded1c-Bold.ttf'), (800, 'MPLUSRounded1c-ExtraBold.ttf')):
            data = base64.b64encode(font(file).read_bytes()).decode()
            faces += f"@font-face{{font-family:'M PLUS Rounded 1c';font-weight:{weight};src:url(data:font/ttf;base64,{data}) format('truetype');}}"
    size = 92 if len(name) <= 5 else 78
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <defs>
    <style>{faces} text {{ font-family:'M PLUS Rounded 1c',sans-serif; fill:#fff; text-anchor:middle; }}</style>
    <linearGradient id="shade" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0b1424" stop-opacity=".18"/><stop offset="1" stop-color="#0b1424" stop-opacity=".52"/></linearGradient>
    <linearGradient id="glass" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".2"/><stop offset="1" stop-color="#fff" stop-opacity=".08"/></linearGradient>
    <filter id="lift" x="-20%" y="-20%" width="140%" height="160%"><feDropShadow dx="0" dy="14" stdDeviation="22" flood-color="#050a14" flood-opacity=".35"/></filter>
    <filter id="ink" x="-10%" y="-30%" width="120%" height="160%"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#050a14" flood-opacity=".45"/></filter>
  </defs>
  <!-- The server draws this shade itself (nl_og_shade), so the label file only holds the card. -->
  <rect width="1200" height="630" fill="url(#shade)" class="shade"/>
  <g filter="url(#lift)">
    <rect x="270" y="150" width="660" height="330" rx="40" fill="url(#glass)" stroke="#fff" stroke-opacity=".42" stroke-width="2"/>
  </g>
  <circle cx="600" cy="232" r="46" fill="{accent}"/>
  <circle cx="600" cy="232" r="56" fill="none" stroke="{accent}" stroke-opacity=".4" stroke-width="4"/>
  <g transform="translate(572 204) scale(2.333)" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10.3 4.2a2 2 0 0 1 3.4 0l7.6 13.1a2 2 0 0 1-1.7 3H4.4a2 2 0 0 1-1.7-3Z"/><path d="M12 9.5v4.2M12 16.9v.1"/>
  </g>
  <text x="600" y="{360 if size == 92 else 352}" font-size="{size}" font-weight="800" letter-spacing="3" filter="url(#ink)">{name}</text>
  <text x="600" y="430" font-size="31" font-weight="700" fill-opacity=".92" letter-spacing="1" filter="url(#ink)">{note}</text>
  <rect x="528" y="500" width="144" height="40" rx="20" fill="#0b1424" fill-opacity=".38" stroke="#fff" stroke-opacity=".3"/>
  <text x="600" y="528" font-size="20" font-weight="700" letter-spacing="4" fill-opacity=".9">閲覧注意</text>
</svg>'''


def main():
    SOURCE.mkdir(parents=True, exist_ok=True); OUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={'width': 1200, 'height': 630})
        for rating, (name, note, accent) in LABELS.items():
            # The kept source names the font instead of carrying it (the label PNG is what ships).
            (SOURCE / f'{rating}.svg').write_text(svg(rating, name, note, accent, embed=False), encoding='utf-8')
            page.set_content('<!doctype html><html><body style="margin:0;background:transparent"><style>.shade{display:none}</style>' + svg(rating, name, note, accent) + '</body></html>')
            page.evaluate('document.fonts.ready')
            page.wait_for_timeout(200)
            # Only the card and its shadow: x 230-970, y 110-560 of the 1200x630 image (CARD in lib/log-og.php).
            page.screenshot(path=str(OUT / f'{rating}.png'), omit_background=True, clip={'x': 230, 'y': 110, 'width': 740, 'height': 450})
            print('wrote', OUT / f'{rating}.png')
        browser.close()
    # Credit for the glyphs drawn into the labels.
    license = (FONTS / 'base-ofl.txt')
    if not license.is_file():
        with urllib.request.urlopen('https://raw.githubusercontent.com/google/fonts/main/ofl/notosansjp/OFL.txt') as r: license.write_bytes(r.read())
    body = license.read_text(encoding='utf-8').split('\n', 1)[1]
    (OUT / 'LICENSE-font.txt').write_text('The labels in this folder are drawn with M PLUS Rounded 1c (https://fonts.google.com/specimen/M+PLUS+Rounded+1c).\n\nCopyright 2016 The Rounded M+ Project Authors.\n' + body, encoding='utf-8')


if __name__ == '__main__':
    main()
