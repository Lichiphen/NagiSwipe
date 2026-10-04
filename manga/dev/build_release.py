#!/usr/bin/env python3
"""
Build the release package nagimanga-vX.Y.Z.zip (standard library only).

    python dev/build_release.py          -> dev/results/nagimanga-vX.Y.Z.zip

Layout (the admin's self-update reads exactly this):
    nagimanga/...              the contents of server/ (data/ and plugins/ only with their guard files)
    optional/guest-mode.php    the guest mode plugin
    README.txt                 first steps

Attach the ZIP to a GitHub release of Lichiphen/NagiSwipe. Installed copies find it
through the releases API and check it against the SHA-256 GitHub shows for the asset.
"""
import hashlib
import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
MANGA = HERE.parent
SERVER = MANGA / "server"

README = """NagiManga v{version}

はじめて設置するとき
1. nagimanga フォルダの中身を、FTP でサーバーの好きな場所にアップロードします。
   .htaccess（. で始まるファイル）も必ず送ってください。
2. ブラウザで https://あなたのサイト/nagimanga/admin/ を開き、30 分以内にセットアップします。
3. 表示されたログイン URL をブックマークします。
4. ログインして、作品一覧に「data フォルダがインターネットから見える状態です」が出ていないことを確認します。
5. 設定の「公開URL」に設置先を入れます。log.php は付けません。

個人用LOG
・公開サイトは https://あなたのサイト/nagimanga/ です。
・「LOG・投稿」で、文章、画像、漫画、カテゴリ、ハッシュタグを投稿できます。
・既定パスワードはありません。初回に自分で設定します。
・メリット・デメリットと設置の注意点は、同梱の README-LOG.md にまとめています。
・.htaccess が使えない環境では、data を公開フォルダの外へ移すか、サーバー側でアクセスを拒否してください。

更新するとき
・v0.2.0 以降は、管理画面の「更新」から更新できます（新しいバージョンが出るとログイン時にお知らせします）。
・手動で更新する場合は、nagimanga フォルダの中身を上書きアップロードします。
  data フォルダはアップロードしないでください（作品と設定が消えます）。
・更新したら、管理画面の「設置用コード」でキャッシュバスター（?v= の値）を確認して貼り直してください。

optional/guest-mode.php は、デモなどで管理画面を閲覧のみで見せたいときだけ nagimanga/plugins/ に置きます。

詳しい説明: https://github.com/Lichiphen/NagiSwipe/blob/main/manga/README.md
デモ: https://notebook.lichiphen.com/nagimanga/demo.html
MIT License (c) 2026 Lichiphen
"""


def main():
    boot = (SERVER / "lib" / "bootstrap.php").read_text(encoding="utf-8")
    viewer = (SERVER / "viewer" / "NagiManga.js").read_text(encoding="utf-8")
    version = re.search(r"const NM_VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", boot).group(1)
    vv = re.search(r"const VERSION = '([0-9.]+)';", viewer).group(1)
    if vv != version:
        sys.exit(f"version mismatch: lib/bootstrap.php {version} / viewer/NagiManga.js {vv}")

    out = HERE / "results" / f"nagimanga-v{version}.zip"
    out.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(SERVER.rglob("*")):
            rel = f.relative_to(SERVER).as_posix()
            if not f.is_file() or rel == "lib/paths.php" or f.suffix in (".tmp", ".bak"):
                continue
            top = rel.split("/", 1)[0]
            if top in ("data", "plugins") and rel not in (f"{top}/.htaccess", f"{top}/index.html"):
                continue
            z.write(f, "nagimanga/" + rel)
        # Bundle the root gallery without keeping a second source copy in Git.
        for name in ("NagiSwipe-main.js", "NagiSwipe-main.css"):
            z.write(MANGA.parent / name, "nagimanga/viewer/" + name)
        z.write(MANGA / "plugins" / "guest-mode.php", "optional/guest-mode.php")
        for name in ("README.md", "README-LOG.md"):
            z.write(MANGA / name, name)
        for doc in sorted((MANGA / "docs").glob("*.md")):
            z.write(doc, "docs/" + doc.name)
        z.writestr("README.txt", ("﻿" + README.format(version=version)).replace("\n", "\r\n").encode("utf-8"))
    digest = hashlib.sha256(out.read_bytes()).hexdigest()
    print(f"{out}\nsha256:{digest}")


if __name__ == "__main__":
    main()
