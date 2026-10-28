#!/usr/bin/env python3
"""
Check that every place showing a version number agrees with the source (standard library only).

    python scripts/check_versions.py          -> lists every mismatch; exit 1 when there is one
    python scripts/check_versions.py --fix    -> rewrites the badges (scripts/cachebust.py) and
                                                 rebuilds the site (site/build.py), then checks again

The source of each number:
  NagiSwipe  the header of NagiSwipe-main.js (NagiSwipe-main.css must say the same)
  NagiManga  NM_VERSION in manga/server/lib/bootstrap.php (VERSION and the header of
             viewer/NagiManga.js must say the same); NagiLog ships with it, so it has the same number

Checked: the version badges and their alt text in the READMEs, site/top*.html and the
generated site pages. manga/dev/build_release.py runs this and refuses to build on a mismatch,
so a release never goes out with old badges (the post-commit hook skips its rewrite when a
README has uncommitted edits, which is how badges were left behind before).
"""
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "scripts"))
from cachebust import BADGE, BADGE_ALT, versions  # noqa: E402

READMES = ["README.md", "README.en.md", "manga/README.md", "manga/README.en.md",
           "manga/README-LOG.md", "manga/README-LOG.en.md", "site/top.html", "site/top.en.html"]
PAGES = ["index.html", "nagiswipe.html", "nagimanga.html", "nagilog.html", "guide.html",
         "index.en.html", "nagiswipe.en.html", "nagimanga.en.html", "nagilog.en.html", "guide.en.html"]
# The same patterns as cachebust.py, reading the number instead of replacing it ("&amp;" in HTML does not matter).
FOUND = [re.compile(BADGE.pattern.replace(r"[0-9]+\.[0-9]+\.[0-9]+", r"([0-9]+\.[0-9]+\.[0-9]+)")),
         re.compile(BADGE_ALT.pattern.replace(r"[0-9]+\.[0-9]+\.[0-9]+", r"([0-9]+\.[0-9]+\.[0-9]+)"))]


def problems():
    want = versions()
    out = []
    css = re.search(r"NagiSwipe v([0-9.]+)", (REPO / "NagiSwipe-main.css").read_text(encoding="utf-8"))
    if not css or css.group(1) != want["NagiSwipe"]:
        out.append(f"NagiSwipe-main.css: header says {css.group(1) if css else '?'}, NagiSwipe-main.js {want['NagiSwipe']}")
    viewer = (REPO / "manga/server/viewer/NagiManga.js").read_text(encoding="utf-8")
    for label, pat in (("VERSION", r"const VERSION = '([0-9.]+)';"), ("header", r"\* NagiManga v([0-9.]+)")):
        m = re.search(pat, viewer)
        if not m or m.group(1) != want["NagiManga"]:
            out.append(f"manga/server/viewer/NagiManga.js: {label} says {m.group(1) if m else '?'}, NM_VERSION {want['NagiManga']}")
    for name in READMES + PAGES:
        path = REPO / name
        if not path.is_file():
            continue
        seen = set()
        for n, line in enumerate(path.read_text(encoding="utf-8-sig").splitlines(), 1):
            for pat in FOUND:
                for m in pat.finditer(line):
                    seen.add(m.group(2))
                    if m.group(3) != want[m.group(2)]:
                        out.append(f"{name}:{n}: {m.group(2)} v{m.group(3)} (should be v{want[m.group(2)]})")
        # Every README and top page carries the NagiManga and NagiLog badges.
        if name in READMES:
            for product in ("NagiManga", "NagiLog"):
                if product not in seen:
                    out.append(f"{name}: no {product} badge")
    return out


def main(argv):
    if "--fix" in argv:
        subprocess.run([sys.executable, str(REPO / "scripts/cachebust.py"), *READMES], cwd=REPO, check=True, stdout=subprocess.DEVNULL)
        subprocess.run([sys.executable, str(REPO / "site/build.py")], cwd=REPO, check=True, stdout=subprocess.DEVNULL)
    found = problems()
    want = versions()
    if found:
        print("Version numbers that do not match the source:")
        for p in found:
            print("  " + p)
        print("Fix: python scripts/check_versions.py --fix  (badges and site), then commit")
        return 1
    print(f"versions OK: NagiSwipe v{want['NagiSwipe']} / NagiManga / NagiLog v{want['NagiManga']}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
