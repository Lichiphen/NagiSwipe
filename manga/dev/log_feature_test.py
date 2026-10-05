#!/usr/bin/env python3
"""LOG likes, tiles, related posts, search engine rules, sitemap and RSS (HTTP checks on an isolated copy)."""
import io
import json
import re
import secrets
import shutil
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
    check("トップ1ページ目の最新記事を大きく出す", 'log-tile is-featured' in top.text)
    check("タイルの一覧ではShare行を出さない", 'log-share.js' not in top.text)
    cat_page = public.get("/?category=" + cat)
    check("カテゴリの一覧もタイルで大きい枠なし", 'class="log-tiles"' in cat_page.text and 'is-featured' not in cat_page.text)
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
