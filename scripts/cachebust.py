#!/usr/bin/env python3
"""
Cache busters for the asset URLs written in READMEs (standard library only).

    python scripts/cachebust.py README.md manga/README.md [other READMEs ...]

Every URL that ends in NagiSwipe-main.js / NagiSwipe-main.css / viewer/NagiManga.js
gets "?<short commit hash>" of the last commit that changed that file, so a copied
URL always differs after an update (browsers and Tegalog then fetch the new file).
Left alone: example text such as https://〜/viewer/NagiManga.js, and ?v=... which is
the admin's own cache buster.

scripts/post-commit.sh runs this after each commit and commits the README change.
"""
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
ASSETS = {
    "NagiSwipe-main.js": "NagiSwipe-main.js",
    "NagiSwipe-main.css": "NagiSwipe-main.css",
    "viewer/NagiManga.js": "manga/server/viewer/NagiManga.js",
}
URL = re.compile(r"https?://[^\s)`\"'<>]*?(NagiSwipe-main\.js|NagiSwipe-main\.css|viewer/NagiManga\.js)(\?[0-9A-Za-z._=-]*)?(?![0-9A-Za-z./_=-])")


def last_commit(path):
    out = subprocess.run(["git", "-C", str(REPO), "log", "-1", "--format=%h", "--", path],
                         capture_output=True, text=True, check=True).stdout.strip()
    if not out:
        sys.exit(f"no commit touches {path}")
    return out


def main(files):
    hashes = {key: last_commit(path) for key, path in ASSETS.items()}

    def swap(m):
        url, asset, query = m.group(0), m.group(1), m.group(2) or ""
        if "〜" in url or query.startswith("?v="):
            return url
        return url[: len(url) - len(query)] + "?" + hashes[asset]

    changed = 0
    for f in files:
        p = Path(f)
        raw = p.read_bytes()
        bom = raw.startswith(b"\xef\xbb\xbf")
        text = raw.decode("utf-8-sig")
        new = URL.sub(swap, text)
        if new != text:
            p.write_bytes((b"\xef\xbb\xbf" if bom else b"") + new.encode("utf-8"))
            changed += 1
            print(f"{f}: updated")
    print(" ".join(f"{k}?{v}" for k, v in hashes.items()))
    return changed


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])
