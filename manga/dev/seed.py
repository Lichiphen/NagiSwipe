#!/usr/bin/env python3
"""
Development only: set up the local dev server (dev/serve.cmd, port 5190) and
add sample works. Credentials go to dev/data/dev-credentials.txt (git-ignored).

    python dev/seed.py
"""
import json
import re
import secrets
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import attack_test as t  # reuse the HTTP client

HERE = Path(__file__).resolve().parent
DATA = HERE / "data"
CRED = DATA / "dev-credentials.txt"
t.PORT = 5190


def main():
    adm = t.Client(5190)
    if CRED.exists():
        key, pw = CRED.read_text(encoding="utf-8").split()[:2]
    else:
        (DATA / "install.lock").unlink(missing_ok=True)
        pw = secrets.token_urlsafe(16)
        r = adm.get("/admin/index.php")
        r = adm.post("/admin/index.php", {"csrf": t.csrf_of(r.text), "password": pw, "password2": pw, "lock_ip": "1"})
        key = re.search(r"index\.php\?k=([A-Za-z0-9]{24})", r.text).group(1)
        CRED.write_text(f"{key}\n{pw}\n", encoding="utf-8")
        adm = t.Client(5190)

    r = adm.get(f"/admin/index.php?k={key}")
    adm.post("/admin/index.php", {"csrf": t.csrf_of(r.text), "k": key, "password": pw})
    csrf = t.csrf_of(adm.get("/admin/index.php").text)

    pages_dir = HERE / "results" / "pages"
    if pages_dir.exists():
        shutil.rmtree(pages_dir)
    php = shutil.which("php")
    ext = str(Path(php).parent / "ext")
    subprocess.run([php, "-n", "-d", f"extension_dir={ext}", "-d", "extension=gd", str(HERE / "make_pages.php"),
                    str(pages_dir), "13", "7"], check=True)

    def create(title, direction, series):
        r = adm.post("/admin/index.php", {"do": "create", "title": title, "series": series, "direction": direction, "csrf": csrf})
        return re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location")).group(1)

    def upload(wid, files):
        for f in files:
            adm.post("/admin/index.php", {"do": "upload", "id": wid, "csrf": csrf},
                     files={"page": (f.name, f.read_bytes(), "image/png")})
        adm.post("/admin/index.php", {"do": "sort_name", "id": wid, "csrf": csrf})

    files = sorted(pages_dir.glob("*.png"))
    a = create("見本 第1話（右から左）", "rtl", "見本シリーズ")
    upload(a, files)
    b = create("見本 第2話（パスワード付き）", "rtl", "見本シリーズ")
    upload(b, files[:6])
    reader_pw = "yomu-" + secrets.token_hex(2)
    adm.post("/admin/index.php", {"do": "setpw", "id": b, "password": reader_pw, "csrf": csrf})
    with CRED.open("a", encoding="utf-8") as fh:
        fh.write(f"reader-password {b} {reader_pw}\n")
    (HERE / "results" / "seed.json").write_text(json.dumps({"public": a, "locked": b}), encoding="utf-8")

    ep = "/read.php"
    js = "/viewer/NagiManga.js"
    demo = f"""<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>NagiManga demo</title>
<style>body{{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:2}}</style></head><body>
<h1>NagiManga 埋め込みデモ</h1>
<p>ブログ記事の本文のつもり。下のリンクをクリックするとビューアーが開きます。</p>
<ul>
<li><a href="#" data-nagimanga="{a}" data-endpoint="{ep}" data-direction="rtl" data-view="auto" data-cover="1">第1話を読む（右から左・見開き）</a></li>
<li><a href="#" data-nagimanga="{a}" data-endpoint="{ep}" data-direction="ltr" data-view="single">第1話を読む（左から右・1ページずつ）</a></li>
<li><a href="#" data-nagimanga="{a}" data-endpoint="{ep}" data-direction="vertical">第1話を読む（縦読み）</a></li>
<li><a href="#" data-nagimanga="{a}" data-endpoint="{ep}" data-direction="vertical" data-vertical="webtoon">第1話を読む（縦読み・ウェブトゥーン）</a></li>
<li><a href="#" data-nagimanga="{b}" data-endpoint="{ep}" data-direction="rtl">第2話を読む（パスワード付き）</a></li>
</ul>
<p style="height:1200px">（スクロール用の余白）</p>
<script src="{js}" defer></script></body></html>"""
    (HERE / "results" / "demo.html").write_text(demo, encoding="utf-8")
    print(json.dumps({"public": a, "locked": b}))


if __name__ == "__main__":
    main()
