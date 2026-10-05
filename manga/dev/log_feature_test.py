#!/usr/bin/env python3
"""LOG likes, tiles, related posts, search engine rules, sitemap and RSS (HTTP checks on an isolated copy)."""
import io
import json
import re
import secrets
import shutil
import urllib.parse
import zipfile
from pathlib import Path

import attack_test as helper
from log_test import HERE, RESULTS, install, payload, start

checks = []


def check(name, ok):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def run(c, csrf, port):
    def post(data):
        return c.post("/admin/index.php", {"csrf": csrf, **data})

    def settings(do, **fields):
        return post({"do": do, **fields})

    def save(body, **extra):
        r = post(payload(body, **extra))
        return json.loads(r.text)["id"]

    public = helper.Client(port)
    # Ids made in the same second share a prefix (…00 and …00-02), so match whole links.
    has = lambda text, pid: ('?id=' + pid + '"') in text or ('?id=' + pid + '<') in text
    related = lambda text: text.split('class="log-related"')[1].split('</section>')[0] if 'class="log-related"' in text else ''
    # Posts: two share a category and a hashtag, one stands alone; a description with tags and URLs.
    pid_a = save("最初の記録\nここが説明の文章です。#共通タグ\nhttps://example.com/page\n**太字**も文章です。", new_categories="日記")
    cat = re.search(r'name="categories\[\]" value="([a-f0-9]{12})"', c.get("/admin/index.php?p=log").text).group(1)
    pid_b = save("二番目の記録\n関連する本文 #共通タグ", **{"categories[]": cat})
    pid_c = save("ひとりの記録\n関係のない本文")
    pid_d = save("同じカテゴリの記録\n本文", **{"categories[]": cat})
    pid_e = save("タグだけ同じ記録\n本文 #共通タグ")

    page = public.get("/?id=" + pid_a)
    check("説明文はタグ・URL・太字記号を除いた文章だけ", 'name="description" content="ここが説明の文章です。 太字も文章です。"' in page.text)
    check("いいねボタンがShareの左にある", page.text.find('class="log-share-btn log-like"') != -1 and page.text.find('class="log-share-btn log-like"') < page.text.find('log-share-open'))
    check("関連記事は同じタグ・カテゴリの記事だけ", has(related(page.text), pid_b) and not has(related(page.text), pid_c))
    check("ミニブログでは個別記事はnoindex", page.getheader('X-Robots-Tag') == 'noindex,follow')
    ld = json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>', page.text).group(1))
    names = [i["name"] for i in ld["itemListElement"]]
    check("記事のパンくず: サイト › カテゴリ › 記事（構造化データ）", ld["@type"] == "BreadcrumbList" and len(names) == 3 and names[1:] == ["日記", "最初の記録"] and ld["itemListElement"][2]["item"].endswith("/?id=" + pid_a))
    crumbs = page.text.split('class="log-breadcrumb"')[1].split('</nav>')[0]
    check("記事のパンくず: 家のSVG・区切りのSVG・今のページ", 'log-crumb-home' in crumbs and crumbs.count('log-crumb-sep') == 2 and 'aria-current="page"' in crumbs)
    sha = re.search(r"script-src [^;]*'sha256-([^']+)'", page.getheader('Content-Security-Policy'))
    check("構造化データはCSPのハッシュで許可", sha is not None)
    check("トップの1ページ目にはパンくずなし", 'log-breadcrumb' not in public.get("/").text)
    day = public.get("/?date=" + re.search(r'datetime="(\d{4}-\d{2}-\d{2})', page.text).group(1)).text
    check("日付のパンくずは月を通る", '?month=' in day.split('class="log-breadcrumb"')[1].split('</nav>')[0])
    check("RSSの案内タグがある", 'type="application/rss+xml"' in page.text)

    # Likes
    api = lambda ids: json.loads(public.get("/like.php?ids=" + ids).text)
    like = lambda pid, n, headers=None: public.post("/like.php", json.dumps({"id": pid, "n": n}).encode(), headers=headers if headers is not None else {"Content-Type": "application/json", "X-NagiLog": "like"})
    check("いいねの初期値は0で、1日の残りは100", api(pid_a)["likes"][pid_a] == 0 and api(pid_a)["left"][pid_a] == 100)
    r = like(pid_a, 10)
    check("いいねを10足せる", r.status == 200 and json.loads(r.text)["count"] == 10)
    r = like(pid_a, 100)
    check("1人1日1記事100までに切る", json.loads(r.text)["count"] == 100 and json.loads(r.text)["left"] == 0)
    check("上限後はもう増えない", json.loads(like(pid_a, 1).text)["count"] == 100)
    check("別の記事は別に数える", json.loads(like(pid_b, 3).text)["count"] == 3)
    as_device = lambda n, ua: like(pid_c, n, {"Content-Type": "application/json", "X-NagiLog": "like", "User-Agent": ua})
    check("同じ回線でも端末が違えば別に100まで", json.loads(as_device(100, "device-1").text)["left"] == 0 and json.loads(as_device(100, "device-2").text)["count"] == 200)
    for i in range(3, 7): as_device(100, f"device-{i}")
    r = json.loads(as_device(100, "device-7").text)
    check("同じ回線全体では1記事1日500まで", r["count"] == 500 and r["left"] == 0)
    check("専用ヘッダーのない送信は404", like(pid_a, 1, {"Content-Type": "application/json"}).status == 404)
    check("別サイトからの送信は404", like(pid_b, 1, {"Content-Type": "application/json", "X-NagiLog": "like", "Origin": "https://evil.example"}).status == 404)
    check("下書きや存在しない記事は404", like("d" + "0" * 16, 1).status == 404 and like("20990101000000", 1).status == 404)
    check("101以上の一括は404", like(pid_c, 101).status == 404)
    check("いいねAPIはキャッシュさせない", public.get("/like.php?ids=" + pid_a).getheader('Cache-Control') == 'no-store')

    edit = c.get("/admin/index.php?p=log_edit&id=" + pid_a)
    check("編集画面にいいねの数", 'id="log-likes"' in edit.text and '<b>100</b>' in edit.text)
    settings("log_like_set", post_id=pid_a, count="1234")
    check("編集画面でいいねの数を変えられる", api(pid_a)["likes"][pid_a] == 1234)
    check("管理の一覧にいいねの数", '1,234' in c.get("/admin/index.php?p=log").text)
    settings("log_like_set", post_id=pid_a, count="1234", reset="1")
    check("編集画面でいいねを削除できる", api(pid_a)["likes"][pid_a] == 0)
    settings("log_like_set", post_id=pid_a, count="7")

    # No "likes" field: the checkbox was turned off.
    settings("log_display_settings", layout="stream", related_by="both", related_order="updated", related="1")
    page = public.get("/?id=" + pid_a)
    check("いいねオフでボタンなし", 'log-like"' not in page.text and public.get("/like.php?ids=" + pid_a).status == 404)
    check("更新順の関連記事はランダム用スクリプトを読まない", 'log-related.js' not in page.text and 'data-related-random' not in page.text)
    settings("log_display_settings", layout="stream", related_by="category", related_order="updated", likes="1", related="1")
    check("カテゴリだけの関連はタグだけ同じ記事を出さない", related(public.get("/?id=" + pid_e).text) == '')
    page = public.get("/?id=" + pid_b)
    check("カテゴリだけの関連で同じカテゴリの記事を出す", has(related(page.text), pid_d))
    settings("log_display_settings", layout="stream", related_by="both", related_order="updated", likes="1")
    check("関連記事をオフにできる", related(public.get("/?id=" + pid_b).text) == '')

    # Tiles
    settings("log_display_settings", layout="grid", related_by="both", related_order="random", likes="1", related="1")
    top = public.get("/")
    check("タイル表示はタイトル・日付・サムネイル枠", 'class="log-tiles"' in top.text and 'log-tile-title' in top.text and 'log-post-meta' not in top.text)
    check("タイルはどれも同じ大きさ（大きい枠なし）", 'is-featured' not in top.text and 'LATEST' not in top.text)
    check("新しい記事のタイルにNEWの印", '<span class="log-tile-badge" data-new-until="' in top.text and '>NEW</span>' in top.text and 'viewer/log-new.js?v=' in top.text)
    check("タイルの一覧ではShare行を出さない", 'log-share.js' not in top.text)
    cat_page = public.get("/?category=" + cat)
    check("カテゴリの一覧もタイル", 'class="log-tiles"' in cat_page.text and 'is-featured' not in cat_page.text)
    settings("log_display_settings", layout="grid", related_by="both", related_order="random", likes="1", related="1", new_days="3", new_label=" 新 ")
    top = public.get("/")
    check("NEWの文字を変えられる", '>新</span>' in top.text and '>NEW</span>' not in top.text)
    settings("log_display_settings", layout="stream", related_by="both", related_order="random", likes="1", related="1")
    stream = public.get("/").text
    check("ミニブログでは記事の左上に印", '<article class="log-post"><span class="log-post-new" data-new-until="' in stream)
    check("記事のページには印を付けない", 'log-post-new' not in public.get("/?id=" + pid_a).text)
    settings("log_display_settings", layout="grid", related_by="both", related_order="random", likes="1", related="1", new_days="0", new_label="")
    top = public.get("/")
    check("0日で印を付けない", 'data-new-until' not in top.text and 'log-new.js' not in top.text)
    settings("log_display_settings", layout="grid", related_by="both", related_order="random", likes="1", related="1", new_days="7", new_label="")
    check("空の文字はNEWに戻る", '>NEW</span>' in public.get("/").text)
    check("タイル: トップとカテゴリはindex", top.getheader('X-Robots-Tag') == 'index,follow' and cat_page.getheader('X-Robots-Tag') == 'index,follow')
    check("タイル: 個別記事はindex", public.get("/?id=" + pid_a).getheader('X-Robots-Tag') == 'index,follow')
    check("タイル: タグ・検索はnoindex", public.get("/?tag=%E5%85%B1%E9%80%9A%E3%82%BF%E3%82%B0").getheader('X-Robots-Tag') == 'noindex,follow' and public.get("/?q=%E8%A8%98%E9%8C%B2").getheader('X-Robots-Tag') == 'noindex,follow')
    page = public.get("/?id=" + pid_b)
    check("ランダムの関連記事は候補とスクリプトを出す", 'data-related-random' not in page.text or 'log-related.js' in page.text)

    # Sitemap
    sm = public.get("/?sitemap=xml")
    urls = re.findall(r"<loc>([^<]+)</loc>", sm.text)
    check("サイトマップ: タイルではトップ・カテゴリ・記事", sm.status == 200 and len(urls) == 1 + 1 + 5 and '<priority>1.0</priority>' in sm.text and '<priority>0.7</priority>' in sm.text and '<priority>0.5</priority>' in sm.text)
    check("サイトマップ: 変更がなければ304", public.get("/?sitemap=xml", headers={"If-None-Match": sm.getheader('ETag')}).status == 304)
    settings("log_display_settings", layout="stream", related_by="both", related_order="random", likes="1", related="1")
    sm = public.get("/?sitemap=xml")
    check("サイトマップ: ミニブログでは記事を載せない", '?id=' not in sm.text and '<priority>1.0</priority>' in sm.text)

    # RSS
    feed = public.get("/?feed=rss")
    check("RSSはRSS 2.0で新しい記事から", feed.getheader('Content-Type').startswith('application/rss+xml') and feed.text.index('?id=' + pid_d + '<') < feed.text.index('?id=' + pid_a + '<'))
    check("RSSは説明文だけで本文全体を出さない", '<description>ここが説明の文章です。 太字も文章です。</description>' in feed.text and 'example.com/page' not in feed.text)
    cat_feed = public.get("/?feed=rss&category=" + cat)
    check("カテゴリのRSS", has(cat_feed.text, pid_b) and not has(cat_feed.text, pid_c))
    tag_feed = public.get("/?feed=rss&tag=%E5%85%B1%E9%80%9A%E3%82%BF%E3%82%B0")
    check("ハッシュタグのRSS", has(tag_feed.text, pid_a) and has(tag_feed.text, pid_b) and not has(tag_feed.text, pid_d))
    check("RSSは検索に出さない", feed.getheader('X-Robots-Tag') == 'noindex')
    check("存在しないカテゴリのRSSは404", public.get("/?feed=rss&category=000000000000").status == 404)
    settings_page = c.get("/admin/index.php?p=settings&section=log")
    check("設定にRSSのURL作成とコピー", 'data-rss-builder' in settings_page.text and 'feed=rss&amp;category=' + cat in settings_page.text and 'js-copy' in settings_page.text)
    check("設定に目次の枠", 'data-settings-toc' in settings_page.text)

    # The settings screen: one form and one save button for every tab; nothing is saved unless every changed block passes.
    form = settings_page.text.split('data-settings-form')[1].split('</form>')[0] if 'data-settings-form' in settings_page.text else ''
    check("設定は1つのフォームで、保存ボタンは1つ", settings_page.text.count('data-settings-form') == 1 and re.findall(r'<button(?![^>]*type="button")(?![^>]*form=")[^>]*>', form) == ['<button class="btn primary settings-save" data-settings-save>']
          and all(f'name="sections[]" value="{k}"' in form for k in ("log_preferences", "log_display", "log_design", "log_seo", "log_footer", "log_guard", "access", "general", "page", "advanced"))
          and 'フッターを保存' not in settings_page.text and 'サイドバーを保存' not in settings_page.text)
    check("パスワードとログインURLの変更は別のフォーム", 'id="settings-password"' in settings_page.text and 'form="settings-password"' in form and 'id="settings-regen-key"' in settings_page.text and 'name="current"' not in settings_page.text.split('id="settings-password"')[1])

    def save_all(fields, as_json=True):
        body = urllib.parse.urlencode([("csrf", csrf), ("do", "settings_save_all")] + fields).encode()
        headers = {"Content-Type": "application/x-www-form-urlencoded", **({"Accept": "application/json"} if as_json else {})}
        return c.post("/admin/index.php", body, headers=headers)

    stored = lambda: (Path(c.data_dir) / "log/settings.php").read_text(encoding="utf-8") + (Path(c.data_dir) / "config.php").read_text(encoding="utf-8")
    before = stored()
    footer = [("sections[]", "log_footer"), ("footer_text", "まとめて保存した表記"), ("show_footer", "1")]
    general = [("sections[]", "general"), ("base_url", ""), ("image_quality", "88"), ("max_upload_mb", "30")]
    r = save_all(footer + general + [("sections[]", "access"), ("allowed_ips", "not-an-ip"), ("allowed_origins", "")])
    check("1つでも誤りがあれば、ほかのブロックも保存しない", r.status == 422 and json.loads(r.text)["section"] == "access" and "管理画面を開ける場所" in json.loads(r.text)["error"] and stored() == before)
    r = save_all(footer + [("sections[]", "log_sidebar"), ("sidebar_items", "[]"), ("sidebar_revision", "0")])
    check("サイドバーの誤りでも、ほかのブロックを保存しない", r.status == 422 and json.loads(r.text)["section"] == "log_sidebar" and stored() == before)
    r = save_all(footer + general + [("sections[]", "log_seo"), ("search_engines", "allow")])
    check("変えたブロックをまとめて保存できる", r.status == 200 and json.loads(r.text)["ok"] and "まとめて保存した表記" in public.get("/").text and "'image_quality' => 88" in stored())
    r = save_all([("tab", "common"), ("sections[]", "log_footer"), ("footer_text", "JavaScriptなしの送信"), ("show_footer", "1")], as_json=False)
    check("JavaScriptなしでも保存して、開いていたタブに戻る", r.status == 303 and r.getheader("Location").endswith("p=settings&section=common") and "JavaScriptなしの送信" in public.get("/").text)
    check("保存したブロックを知らせる", "設定を保存しました（サイト下部の表記）" in c.get("/admin/index.php?p=settings&section=common").text)

    # Search engines turned away
    settings("log_seo_settings", search_engines="block")
    check("載せない設定: 全ページnoindex,nofollow", all(public.get(u).getheader('X-Robots-Tag') == 'noindex,nofollow' for u in ("/", "/?id=" + pid_a, "/?category=" + cat)))
    check("載せない設定: サイトマップは404", public.get("/?sitemap=xml").status == 404)
    settings("log_seo_settings", search_engines="allow")
    check("載せる設定に戻せる", public.get("/").getheader('X-Robots-Tag') == 'index,follow')

    # Backup keeps likes and the new settings
    settings("log_display_settings", layout="grid", related_by="tag", related_order="updated", likes="1", related="1")
    r = c.post("/admin/index.php", {"do": "log_backup", "csrf": csrf})
    mark = json.loads(zipfile.ZipFile(io.BytesIO(r.body)).read("backup.json"))
    check("バックアップにいいねと表示設定", mark["likes"].get(pid_a) == 7 and mark["settings"]["layout"] == "grid" and mark["settings"]["related_by"] == "tag")

    # 画像一覧: search, filters, page size, and returning to the same search after saving
    up = lambda name: json.loads(c.post("/admin/index.php", {"csrf": csrf, "do": "log_upload"}, files={"image": (name, helper.png(60, 40), "image/png")}).text)["media"]
    used_img, spare_img = up("夕焼けの空.png"), up("予備の画像.png")
    save("画像を使う記録\n" + used_img["tag"])
    cards = lambda path: re.findall(r'data-media-card="([0-9a-f]+)"', c.get("/admin/index.php?p=log_media" + path).text)
    check("画像一覧を説明で探せる", cards("&q=%E5%A4%95%E7%84%BC%E3%81%91") == [used_img["id"]])
    check("画像一覧を使っている記事のタイトルで探せる", cards("&q=%E7%94%BB%E5%83%8F%E3%82%92%E4%BD%BF%E3%81%86") == [used_img["id"]])
    check("画像一覧を未使用だけに絞れる", spare_img["id"] in cards("&use=unused") and used_img["id"] not in cards("&use=unused"))
    page = c.get("/admin/index.php?p=log_media&per_page=1").text
    check("画像一覧の表示件数を変えられ、ログイン中は覚える", len(re.findall(r'data-media-card=', page)) == 1 and len(cards("")) == 1 and 'index.php?p=log_media&amp;page=2' in page)
    c.get("/admin/index.php?p=log_media&per_page=40")
    check("画像一覧に使っている記事への編集リンク", 'class="log-media-used"' in c.get("/admin/index.php?p=log_media&q=%E5%A4%95%E7%84%BC%E3%81%91").text)
    r = post({"do": "log_media_alt", "media": spare_img["id"], "revision": str(spare_img["revision"]), "alt": "予備の画像", "rating": "", "back": "q=%E4%BA%88%E5%82%99&use=unused&page=1&evil=1"})
    check("画像の説明を保存すると、同じ検索に戻る", r.status == 303 and r.getheader("Location") == "index.php?p=log_media&q=%E4%BA%88%E5%82%99&use=unused")

    # Deleting a post removes its count
    rev = re.search(r'name="revision" value="(\d+)"', c.get("/admin/index.php?p=log_edit&id=" + pid_a).text).group(1)
    post({"do": "log_delete", "post_id": pid_a, "revision": rev})
    settings("log_display_settings", layout="grid", related_by="both", related_order="random", likes="1", related="1")
    check("記事を消すといいねも消える", pid_a not in json.loads(public.get("/like.php?ids=" + pid_b).text)["likes"] and f"'{pid_a}'" not in (Path(c.data_dir) / "log/likes.php").read_text(encoding="utf-8") and f" {pid_a} =>" not in (Path(c.data_dir) / "log/likes.php").read_text(encoding="utf-8"))


def main():
    RESULTS.mkdir(exist_ok=True)
    root = RESULTS / ("log-feature-test-" + secrets.token_hex(4))
    root.mkdir()
    site = root / "site"
    shutil.copytree(HERE.parent / "server", site, ignore=shutil.ignore_patterns('paths.php'))
    process = start(site, 5198)
    try:
        c, csrf, _ = install(5198)
        c.data_dir = site / "data"
        run(c, csrf, 5198)
    finally:
        process.terminate()
        process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == "__main__":
    main()
