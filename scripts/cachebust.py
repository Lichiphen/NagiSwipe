#!/usr/bin/env python3
"""
Pin the jsDelivr URLs written in READMEs to a commit (standard library only).

    python scripts/cachebust.py README.md manga/README.md [other READMEs ...]

  https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@<anything>/NagiSwipe-main.js
      -> https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@<last commit of that file>/NagiSwipe-main.js

A commit URL serves exactly that version, and it changes whenever the file
changes, so copied URLs never keep an old file in a browser or on the CDN
(no ?query needed). The same for NagiSwipe-main.css.

NagiManga.js lives on each user's own server: README examples of it carry no
cache buster (the admin's 設置用コード page gives the real ?v= value), so a
leftover ?<hash> after .../viewer/NagiManga.js is removed. ?v=... is left alone.

scripts/post-commit.sh runs this after each commit and commits the README change.
"""
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
CDN = re.compile(r"(https://cdn\.jsdelivr\.net/gh/Lichiphen/NagiSwipe@)[^/\s`\"'<>)]+/(NagiSwipe-main\.(?:js|css))(\?[0-9A-Za-z._=-]*)?(?![0-9A-Za-z./_=-])")
SELF = re.compile(r"(viewer/NagiManga\.js)\?[0-9a-f]{7,40}(?![0-9A-Za-z./_=-])")


def last_commit(path):
    out = subprocess.run(["git", "-C", str(REPO), "log", "-1", "--format=%h", "--", path],
                         capture_output=True, text=True, check=True).stdout.strip()
    if not out:
        sys.exit(f"no commit touches {path}")
    return out


def main(files):
    hashes = {name: last_commit(name) for name in ("NagiSwipe-main.js", "NagiSwipe-main.css")}

    def pin(m):
        return f"{m.group(1)}{hashes[m.group(2)]}/{m.group(2)}"

    for f in files:
        p = Path(f)
        raw = p.read_bytes()
        bom = raw.startswith(b"\xef\xbb\xbf")
        text = raw.decode("utf-8-sig")
        new = SELF.sub(r"\1", CDN.sub(pin, text))
        if new != text:
            p.write_bytes((b"\xef\xbb\xbf" if bom else b"") + new.encode("utf-8"))
            print(f"{f}: updated")
    print(" ".join(f"{k}@{v}" for k, v in hashes.items()))


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])
