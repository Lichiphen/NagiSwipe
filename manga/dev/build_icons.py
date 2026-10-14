#!/usr/bin/env python3
"""Copy the link and share icons (manga/server/viewer/link-icons/*.svg) into the icon sprite viewer/icons/ui.svg.

    python manga/dev/build_icons.py           write the link-* symbols again
    python manga/dev/build_icons.py --check   only check that the sprite matches the files (exit 1 if not)

The other symbols of ui.svg are written by hand. Pages show an icon as <svg><use href="ui.svg#id"/></svg>
(nm_icon() in lib/bootstrap.php, icon() in the scripts), so the drawings are downloaded once and cached.
"""
import re
import sys
from pathlib import Path

SERVER = Path(__file__).resolve().parents[1] / 'server'
SPRITE = SERVER / 'viewer/icons/ui.svg'
ICONS = SERVER / 'viewer/link-icons'
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
KEEP = ('fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin')


def symbols():
    out = []
    for f in sorted(ICONS.glob('*.svg')):
        text = f.read_text(encoding='utf-8')
        root = re.search(r'<svg\b([^>]*)>(.*)</svg>', text, re.S)
        attrs = dict(re.findall(r'([a-z-]+)="([^"]*)"', root.group(1)))
        inner = re.sub(r'>\s+<', '><', re.sub(r'<!--.*?-->', '', root.group(2), flags=re.S)).strip()
        style = ''.join(f' {k}="{attrs[k]}"' for k in KEEP if k in attrs)
        out.append(f'<symbol id="link-{f.stem}" viewBox="{attrs.get("viewBox", "0 0 24 24")}"><g{style}>{inner}</g></symbol>')
    return out


def main():
    text = SPRITE.read_text(encoding='utf-8')
    lines = [l for l in text.split('\n') if not l.startswith('<symbol id="link-')]
    end = lines.index('</svg>')
    new = '\n'.join(lines[:end] + symbols() + lines[end:])
    if '--check' in sys.argv:
        ok = new == text
        print('ui.svg の link-* は link-icons と同じです' if ok else 'ui.svg が link-icons と違います。python manga/dev/build_icons.py で書き直してください')
        sys.exit(0 if ok else 1)
    SPRITE.write_text(new, encoding='utf-8', newline='\n')
    print('ui.svg を書き直しました')


if __name__ == '__main__':
    main()
