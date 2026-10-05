#!/usr/bin/env python3
"""LOG content warnings (センシティブ / R-18 / R-18G): saving, what readers get, share cards, search engines and backups."""
import io
import json
import re
import secrets
import shutil
import zipfile
from pathlib import Path

import attack_test as helper
from log_test import HERE, RESULTS, install, payload, start

ROOT = HERE.parents[1]
checks = []


def check(name, ok):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def run(c, csrf, port):
    def post(data, files=None):
        return c.post("/admin/index.php", {"csrf": csrf, **data}, files=files)

    def upload(name):
        r = post({"do": "log_upload"}, files={"image": (name, (ROOT / "img" / name).read_bytes(), "image/png")})
        return json.loads(r.text)["media"]

    def save(body, **extra):
        r = post(payload(body, **extra))
        return r, (json.loads(r.text).get("id") if r.status == 200 else None)

    public = helper.Client(port)
    a, b, shared = upload("Shimeshime.png"), upload("Fu-n.png"), upload("Kyururun.png")
    check("新しい画像は閲覧注意なし", a.get("rating", "") == "")
    _, plain = save("ふつうの記録\n本文だけ")

    # センシティブ: the picture is veiled, the text stays, and the link waits until the veil opens.
    _, sid = save("海の絵\n" + a["tag"] + "\n本文はそのまま読めます。", rating="sensitive", warning="肌の露出があります", media_ratings=json.dumps({a["id"]: "sensitive"}))
    page = public.get("/?id=" + sid).text
    veil = page.split('<details class="log-veil log-veil-image"')[1].split('</details>')[0] if 'log-veil-image' in page else ''
    check("センシティブは画像をぼかしのカバーで包む", 'data-veil="sensitive"' in veil and '肌の露出があります' in veil and '押して表示' in veil)
    check("センシティブの本文は折りたたまない", '本文はそのまま読めます。' in page and 'log-veil-post' not in page)
    check("覆われた画像は拡大リンクを持たない（data-veil-hrefで待つ）", 'data-veil-href="./?media=' + a["id"] in veil and 'href="./?media=' + a["id"] not in veil.replace('data-veil-href', ''))
    check("閲覧注意のある記事は自分の画像をOGPに使わない", ('media=' + a["id"]) not in re.search(r'<meta property="og:image" content="([^"]*)"', page).group(1))
    check("閲覧注意のある記事でlog-veil.jsを読む", 'viewer/log-veil.js?v=' in page)
    media_file = lambda mid: next(f for f in (Path(c.data_dir) / "log/media").glob("*/media.php") if "'id' => '" + mid + "'" in f.read_text(encoding="utf-8"))
    check("画像の閲覧注意を画像に保存", "'rating' => 'sensitive'" in media_file(a["id"]).read_text(encoding="utf-8"))

    # 記事だけに付けた注意も、その記事の画像をぼかす。
    _, oid = save("記事だけ注意\n" + b["tag"], rating="sensitive")
    check("記事の注意だけでも画像をぼかす", 'log-veil-image' in public.get("/?id=" + oid).text)

    # R-18: the body folds, the description is replaced, and search engines are turned away.
    _, rid = save("大人向け\n" + shared["tag"] + "\n折りたたまれる本文", rating="r18")
    page = public.get("/?id=" + rid)
    check("R-18は本文を折りたたむ", 'class="log-veil log-veil-post" data-veil="r18"' in page.text and page.text.find('折りたたまれる本文') > page.text.find('log-veil-post'))
    check("R-18の説明文は本文を出さない", 'name="description" content="閲覧注意（R-18）の記事です。"' in page.text)
    check("R-18の記事はnoindex", 'noindex' in page.getheader('X-Robots-Tag'))
    check("折りたたんだ本文の画像も拡大リンクを待つ", 'data-veil-href="./?media=' + shared["id"] in page.text)

    # 画像だけR-18G: 記事全体がR-18Gとして折りたたまれ、タイルにも出る。
    _, gid = save("画像だけ注意\n" + shared["tag"], media_ratings=json.dumps({shared["id"]: "r18g"}))
    page = public.get("/?id=" + gid).text
    check("画像の注意が記事より強いと、記事をその注意で折りたたむ", 'log-veil-post" data-veil="r18g"' in page)
    check("共有画像の注意は、同じ画像を使うほかの記事にも反映", 'data-veil="r18"' in public.get("/?id=" + rid).text)
    post({"do": "log_display_settings", "layout": "grid", "related_by": "both", "related_order": "random", "likes": "1", "related": "1"})
    top = public.get("/").text
    tile = lambda pid: top.split('href="./?id=' + pid + '"')[1].split('</li>')[0] if ('href="./?id=' + pid + '"') in top else ''
    check("タイルは閲覧注意の記事をぼかしてラベルを出す", 'is-veiled" data-veil="r18g"' in tile(gid) and 'log-tile-veil' in tile(gid) and 'R-18G' in tile(gid))
    check("注意のない記事のタイルはそのまま", tile(plain) != '' and 'is-veiled' not in tile(plain))
    sitemap = public.get("/?sitemap=xml").text
    check("R-18の記事はサイトマップに載せない", ('?id=' + rid + '<') not in sitemap and ('?id=' + sid + '<') in sitemap)
    feed = public.get("/?feed=rss").text
    check("RSSの説明文も本文を出さない", '折りたたまれる本文' not in feed and '閲覧注意（R-18）の記事です。' in feed)

    # Input checks
    r, _ = save("不正\n本文", rating="adult")
    check("知らない区分は保存しない", r.status == 422)
    r, _ = save("不正な画像\n" + a["tag"], media_ratings=json.dumps({a["id"]: "x"}))
    check("知らない画像の区分は保存しない", r.status == 422)
    _, nid = save("ほかの画像は変えない\n" + a["tag"], media_ratings=json.dumps({b["id"]: ""}))
    check("本文にない画像の区分は変えない", 'log-veil-image' in public.get("/?id=" + oid).text)
    long_warning = "あ" * 60
    _, wid = save("長い注意\n本文", rating="sensitive", warning=long_warning)
    check("注意書きは40文字まで", "'warning' => '" + "あ" * 40 + "'" in (Path(c.data_dir) / "log/posts" / wid[:4] / (wid + ".php")).read_text(encoding="utf-8"))

    # The owner sees a note instead of the veil; the editor carries the ratings of the pictures in the post.
    edit = c.get("/admin/index.php?p=log_edit&id=" + sid).text
    check("編集画面で記事の区分を選択済み", 'name="rating" value="sensitive" checked' in edit and 'value="肌の露出があります"' in edit)
    check("編集画面に本文の画像の区分を渡す", '&quot;rating&quot;:&quot;sensitive&quot;' in edit)
    check("注意書きの定型文を出す", 'data-warning-preset="流血表現があります"' in edit and 'data-warning-preset="グロテスクな表現があります"' in edit)
    preview = json.loads(post({**payload("プレビュー\n" + b["tag"], rating="", media_ratings=json.dumps({b["id"]: "r18"})), "do": "log_preview"}).text)["html"]
    check("プレビューは保存前の画像の区分でメモを出す", 'log-veil-admin" data-veil="r18"' in preview and 'log-figure-badge' in preview)

    # 画像一覧からも変えられる
    media_page = c.get("/admin/index.php?p=log_media").text
    rev = re.search(r'data-media-card="' + b["id"] + r'" data-revision="(\d+)"', media_page).group(1)
    post({"do": "log_media_alt", "media": b["id"], "revision": rev, "alt": "説明", "rating": "r18g"})
    check("画像一覧で付けた区分が記事に出る", 'log-veil-post" data-veil="r18g"' in public.get("/?id=" + oid).text)

    # Backups keep the ratings and the note.
    r = c.post("/admin/index.php", {"do": "log_backup", "csrf": csrf})
    z = zipfile.ZipFile(io.BytesIO(r.body))
    saved = json.loads(z.read("posts/" + sid + ".json")) if ("posts/" + sid + ".json") in z.namelist() else next(json.loads(z.read(n)) for n in z.namelist() if n.endswith(sid + ".json"))
    media_json = json.loads(z.read("media/" + b["id"] + "/media.json"))
    check("バックアップに記事の区分と注意書き", saved.get("rating") == "sensitive" and saved.get("warning") == "肌の露出があります")
    check("バックアップに画像の区分", media_json.get("rating") == "r18g")
    files = {"backup": ("log.zip", r.body, "application/zip")}
    post({"do": "log_restore", "overwrite": "1"}, files=files)
    check("復元後も区分が残る", 'data-veil="sensitive"' in public.get("/?id=" + sid).text and 'log-veil-post" data-veil="r18g"' in public.get("/?id=" + oid).text)


def main():
    RESULTS.mkdir(exist_ok=True)
    root = RESULTS / ("log-veil-test-" + secrets.token_hex(4))
    root.mkdir()
    site = root / "site"
    shutil.copytree(HERE.parent / "server", site, ignore=shutil.ignore_patterns('paths.php'))
    process = start(site, 5196)
    try:
        c, csrf, _ = install(5196)
        c.data_dir = site / "data"
        run(c, csrf, 5196)
    finally:
        process.terminate()
        process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == "__main__":
    main()
