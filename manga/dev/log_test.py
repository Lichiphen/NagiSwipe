#!/usr/bin/env python3
"""LOG HTTP integration checks, using isolated data and the existing test helpers."""
import concurrent.futures
import io
import json
import os
import re
import secrets
import shutil
import subprocess
import time
import zipfile
from pathlib import Path

import attack_test as helper

HERE = Path(__file__).resolve().parent
RESULTS = HERE / "results"
checks = []


def check(name, ok):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def start(site, port, raw=False):
    env = {k: v for k, v in os.environ.items() if k != 'NAGIMANGA_DATA'}
    process = subprocess.Popen(helper.php_cmd(port, not raw, site), env=env, stdout=subprocess.DEVNULL, stderr=open(site.parent / f"log-php-{port}.txt", "wb"))
    client = helper.Client(port)
    for _ in range(100):
        try:
            client.get("/admin/index.php")
            return process
        except OSError:
            time.sleep(.05)
    raise RuntimeError("PHP server did not start")


def install(port):
    c = helper.Client(port)
    r = c.get("/admin/index.php")
    r = c.post("/admin/index.php", {"csrf": helper.csrf_of(r.text), "password": "log-test-password", "password2": "log-test-password"})
    key = re.search(r"index.php\?k=([A-Za-z0-9]+)", r.text).group(1)
    r = c.get("/admin/index.php?k=" + key)
    c.post("/admin/index.php", {"k": key, "csrf": helper.csrf_of(r.text), "password": "log-test-password"})
    r = c.get("/admin/index.php?p=log")
    return c, helper.csrf_of(r.text), key


def payload(body="テストの記録", status="published", **extra):
    return {"do": "log_save", "nonce": secrets.token_hex(16), "body": body, "title": "", "status": status, "manga": "{}", **extra}


def run(c, csrf, key, site, raw_port, other, other_csrf):
    def post(data, files=None, token=csrf):
        return c.post("/admin/index.php", {"csrf": token, **data}, files=files)

    public = helper.Client(c.port)
    check("公開URLはファイル名のないルート", public.get('/').status == 200 and '/log.php' not in public.get('/').text)
    legacy = public.get('/log.php?id=20261004123033')
    check("以前のlog.phpリンクを新URLへ301転送", legacy.status == 301 and legacy.getheader('Location') == './?id=20261004123033')
    check("未ログインのLOG管理画面はログイン画面へ（中身は見せない）", public.get("/admin/index.php?p=log").status == 303 and public.get("/admin/index.php?p=log").getheader("Location") == "login.php")
    check("未ログインの保存は404", public.post("/admin/index.php", payload()).status == 404)
    check("CSRFのない投稿は404", post(payload(), token="wrong").status == 404)
    check("公開窓口へのPOSTは404", public.post("/", payload()).status == 404)
    check("本文が空なら拒否", post(payload(body=" ")).status == 422)
    check("本文の容量超過を拒否", post(payload(body="a" * 100001)).status == 422)
    check("空のLOGを表示", public.get("/").status == 200)
    check("NagiSwipeを標準で読み込む", "viewer/NagiSwipe-main.js" in public.get("/").text)
    check("開発サーバーでNagiSwipeを配信", public.get("/viewer/NagiSwipe-main.js").status == 200)
    check("標準デザインはライトブルー", 'data-log-theme="light-blue"' in public.get('/').text)
    login = helper.Client(c.port)
    entrance = login.get('/admin/login.php')
    check("公開LOGから秘密鍵を含めずログイン画面へ", entrance.status == 200 and key not in entrance.text and 'name="password"' in entrance.text)
    check("公開ログインもCSRFが必要", login.post('/admin/login.php', {'csrf': 'wrong', 'password': 'log-test-password'}).status == 404)
    entrance = login.get('/admin/login.php')
    bad = login.post('/admin/login.php', {'csrf': helper.csrf_of(entrance.text), 'password': 'wrong-password'})
    check("公開ログインはパスワードを照合", 'パスワードが違います' in bad.text)
    entrance = login.get('/admin/login.php')
    logged = login.post('/admin/login.php', {'csrf': helper.csrf_of(entrance.text), 'password': 'log-test-password'})
    check("アイコン付き入口からLOG管理画面へログイン", logged.status == 303 and logged.getheader('Location') == 'index.php?p=log' and 'data-log-editor' in login.get('/admin/index.php?p=log').text)

    p = payload(body='最初の記録\n**太字**\n<script>alert("x")</script>\nhttps://example.com/')
    saved = post(p)
    check("投稿を保存", saved.status == 200)
    pid = json.loads(saved.text)["id"]
    check("投稿番号は秒までの日時", re.fullmatch(r"[0-9]{14}(?:-[0-9]{2,6})?", pid) is not None)
    duplicate = post(p)
    check("二重送信で同じ投稿を増やさない", duplicate.status == 200 and json.loads(duplicate.text)["id"] == pid)
    r = public.get("/?id=" + pid)
    check("太字を表示", "<strong>太字</strong>" in r.text)
    check("投稿のHTMLを実行しない", "<script>alert" not in r.text and "&lt;script&gt;" in r.text)
    check("URLをリンクとして表示", 'href="https://example.com/"' in r.text)
    check("個別投稿のcanonicalとOGP", 'rel="canonical"' in r.text and 'property="og:image"' in r.text and pid in r.text)
    edit = c.get("/admin/index.php?p=log_edit&id=" + pid)
    revision = re.search(r'name="revision" value="([0-9]+)"', edit.text).group(1)
    update = payload(body="編集した本文", post_id=pid, revision=revision)
    r = post(update)
    check("編集しても投稿番号を維持", r.status == 200 and json.loads(r.text)["id"] == pid)
    check("編集の再送信も重複しない", post(update).status == 200)
    stale = payload(body="古いタブの本文", post_id=pid, revision=revision)
    check("別タブの古い編集を拒否", post(stale).status == 422)
    check("古い編集で本文が消えない", "編集した本文" in public.get("/?id=" + pid).text)
    def parallel_save(i):
        peer = helper.Client(c.port)
        peer.cookies = dict(c.cookies)
        return json.loads(peer.post('/admin/index.php', {'csrf': csrf, **payload(body=f'同時投稿 {i}')}).text)['id']
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        ids = list(pool.map(parallel_save, range(4)))
    check("同時に投稿しても番号が重ならない", len(set(ids)) == 4)

    image = post({"do": "log_upload"}, files={"image": ("photo.png", helper.png(80, 100, extra_after_iend=b"<?php echo 'payload'; ?>"), "image/png")})
    check("画像をアップロード", image.status == 200)
    media = json.loads(image.text)["media"]
    mid = media["id"]
    check("画像番号と本文タグを発行", media["tag"] == "[Image:" + mid + "]")
    check("画像URLはNagiSwipeが検出できる拡張子で終わる", re.search(r'\.(?:webp|jpg)$', media['url']) is not None)
    check("未使用画像を公開しない", public.get(media["url"].replace("index.php?p=log_image&", "/?")).status == 404)
    draft_payload = payload(body="下書き\n" + media["tag"], status="draft")
    did = json.loads(post(draft_payload).text)["id"]
    check("下書きを作る", did.startswith("d"))
    check("下書きは個別URLでも404", public.get("/?id=" + did).status == 404)
    check("下書きを一覧に出さない", "下書き" not in public.get("/").text)
    public_media_url = "/?media=" + mid + "&format=webp"
    check("下書きの画像も公開しない", public.get(public_media_url).status == 404)
    check("管理者は下書きの画像を読める", c.get("/admin/" + media["url"]).status == 200)
    dedit = c.get("/admin/index.php?p=log_edit&id=" + did)
    dver = re.search(r'name="revision" value="([0-9]+)"', dedit.text).group(1)
    pub_input = payload(body="公開した画像\n" + media["tag"], post_id=did, revision=dver)
    published = post(pub_input)
    image_pid = json.loads(published.text)["id"]
    check("初めての公開で日時番号を発行", not image_pid.startswith("d") and image_pid != did)
    check("公開後の下書き保存の再送信にも対応", post(pub_input).status == 200)
    check("公開画像を配信", public.get(public_media_url).status == 200)
    check("画像に埋めたPHPを落とす", b"payload" not in public.get(public_media_url).body)
    check("画像入り投稿のOGPを設定", "./?media=" + mid in public.get("/?id=" + image_pid).text)
    check("使用中の画像を削除しない", "公開した画像" in public.get("/?id=" + image_pid).text and post({"do": "log_media_delete", "media": mid, "revision": 1}).status == 303 and public.get(public_media_url).status == 200)
    check("SVGアップロードを拒否", post({"do": "log_upload"}, files={"image": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"></svg>', "image/svg+xml")}).status == 422)
    check("偽画像のアップロードを拒否", post({"do": "log_upload"}, files={"image": ("x.png", b"<?php echo 1; ?>", "image/png")}).status == 422)
    replacement = post({"do": "log_upload", "media": mid, "revision": 1}, files={"image": ("new.png", helper.png(120, 60, seed=8), "image/png")})
    new = json.loads(replacement.text)["media"]
    check("画像を同じ番号のまま差し替える", new["id"] == mid and new["revision"] == 2 and new["w"] == 120)
    check("差し替えで新しいキャッシュ番号", new["url"] != media["url"])
    check("古い差し替えを拒否", post({"do": "log_upload", "media": mid, "revision": 1}, files={"image": ("stale.png", helper.png(40, 40), "image/png")}).status == 422)
    check("画像一覧と差し替え画面を表示", "画像を差し替える" in c.get("/admin/index.php?p=log_media").text)
    head = public.request('HEAD', public_media_url)
    check("画像のHEAD要求は本文を送らない", head.status == 200 and not head.body)
    post({'do': 'log_media_alt', 'media': mid, 'revision': 2, 'alt': '差し替え後の絵'})
    check("画像の説明を投稿にも反映", 'alt="差し替え後の絵"' in public.get('/?id=' + image_pid).text)
    unused = json.loads(post({'do': 'log_upload'}, files={'image': ('unused.png', helper.png(20, 20), 'image/png')}).text)['media']
    post({'do': 'log_media_delete', 'media': unused['id'], 'revision': 1})
    check("未使用の画像は削除できる", c.get('/admin/' + unused['url']).status == 404)
    check("画像一覧を検索できる", len(json.loads(c.get('/admin/index.php?p=log_media_json&q=' + helper.urllib.parse.quote('差し替え後')).text)['items']) == 1)
    check("パストラバーサルを拒否", public.get("/?media=../../config.php").status == 404)
    check("不正な投稿番号を拒否", public.get("/?id=../../config").status == 404)

    for title in ("第1話", "第1話", "秘密の漫画"):
        created = post({"do": "create", "title": title, "direction": "rtl"})
        wid = re.search(r"id=([A-Za-z0-9]{12})", created.getheader("Location")).group(1)
        uploaded = post({"do": "upload", "id": wid}, files={"page": ("001.png", helper.png(80, 120), "image/png")})
        if uploaded.status != 200:
            raise RuntimeError(uploaded.text)
        if title == "秘密の漫画":
            post({"do": "setpw", "id": wid, "password": "secret"})
    manga_items = json.loads(c.get("/admin/index.php?p=log_manga_json").text)["items"]
    check("漫画一覧に表紙とタイトル", len(manga_items) == 3 and all(i["thumb"] and i["title"] for i in manga_items))
    same = [i for i in manga_items if i["title"] == "第1話"]
    check("同じタイトルの漫画も区別できる", same[0]["tag"] != same[1]["tag"])
    manga = same[0]
    mpid = json.loads(post(payload(body="漫画の記録\n読む前の文\n" + manga["tag"] + "\n読んだ後の文", manga=json.dumps({manga["tag"]: manga["id"]}))).text)["id"]
    r = public.get("/?id=" + mpid)
    rendered_body = r.text.split('<div class="log-body">', 1)[1]
    check("本文の好きな位置に漫画カード", rendered_body.index("読む前の文") < rendered_body.index('class="log-manga"') < rendered_body.index("読んだ後の文"))
    check("漫画カードから既存ビューアーを開く", 'data-nagimanga="' + manga["id"] + '"' in r.text)
    check("漫画だけの投稿のOGPは共通画像", 'property="og:image" content="http://127.0.0.1:' + str(c.port) + '/viewer/og.jpg"' in r.text)
    post({"do": "update", "id": manga["id"], "title": "改題した漫画", "direction": "rtl"})
    check("漫画の改題後もIDで読める", "改題した漫画" in public.get("/?id=" + mpid).text)
    post({'do': 'page_public', 'id': manga['id']})
    hidden_card = public.get('/?id=' + mpid)
    check("個別ページを非公開にした漫画の表紙も隠す", 'read.php?a=o&amp;id=' + manga['id'] not in hidden_card.text)
    post({'do': 'page_public', 'id': manga['id'], 'public': '1'})
    secret = next(i for i in manga_items if i["locked"])
    spid = json.loads(post(payload(body=secret["tag"], manga=json.dumps({secret["tag"]: secret["id"]}))).text)["id"]
    r = public.get("/?id=" + spid)
    check("限定公開漫画の表紙を漏らさない", "read.php?a=o&amp;id=" + secret["id"] not in r.text and "パスワードを入れて読む" in r.text)
    check("存在しない漫画タグを拒否", post(payload(body="[Manga手書きの作品]", manga="{}")).status == 422)
    check("見つからない画像タグを拒否", post(payload(body="[Image:0123456789abcdef]")).status == 422)

    title_line = '重複しないタイトル。一行の続きもタイトルです。'
    split_id = json.loads(post(payload(body=title_line + '\n本文の先頭。 **強調**')).text)['id']
    split_html = public.get('/?id=' + split_id).text
    body_html = re.search(r'<div class="log-body">(.*?)</div>', split_html, re.S).group(1)
    check("複数文でも1行目全体をタイトルにする", '<h1>' + title_line + '</h1>' in split_html)
    check("タイトル行は本文に重複させず2行目から表示", title_line not in body_html and '本文の先頭。' in body_html and '<strong>強調</strong>' in body_html)
    admin_cards = c.get('/admin/index.php?p=log').text
    check("管理一覧は本文を省いた短い投稿行で表示", title_line in admin_cards and 'log-list-row' in admin_cards and '<div class="log-body">本文の先頭。' not in admin_cards and '<table' not in admin_cards)
    preview = json.loads(post({**payload(body=title_line + '\nプレビューの本文'), 'do': 'log_preview'}).text)['html']
    check("プレビューもタイトルと本文を分ける", preview.count(title_line) == 1 and '<div class="log-body">プレビューの本文</div>' in preview)
    title_only = json.loads(post(payload(body='タイトルだけの投稿。')).text)['id']
    title_only_html = public.get('/?id=' + title_only).text
    check("1行だけの投稿はタイトルのみ表示", '<h1>タイトルだけの投稿。</h1>' in title_only_html and '<div class="log-body"></div>' in title_only_html)
    long_title = '長いタイトル' * 20
    long_id = json.loads(post(payload(body=long_title + '\n長い見出しの本文')).text)['id']
    check("長いタイトルの全文も投稿には残す", '<h1>' + long_title + '</h1>' in public.get('/?id=' + long_id).text)
    first_media_body = '1行目に画像を添える。 ' + media['tag'] + ' ' + manga['tag'] + '\n2行目の本文'
    first_media_id = json.loads(post(payload(body=first_media_body, manga=json.dumps({manga['tag']: manga['id']}))).text)['id']
    first_media_html = re.search(r'<div class="log-body">(.*?)</div>', public.get('/?id=' + first_media_id).text, re.S).group(1)
    check("タイトル行の画像・漫画タグを本文に残す", '1行目に画像を添える。' not in first_media_html and 'class="log-figure"' in first_media_html and 'class="log-manga"' in first_media_html and '2行目の本文' in first_media_html)
    check("編集欄はタイトルを含む入力原文を維持", first_media_body in c.get('/admin/index.php?p=log_edit&id=' + first_media_id).text)
    post({'do': 'log_delete', 'post_id': first_media_id, 'revision': 1})

    # Same-second IDs: a fixed clock fixture exercises the collision branch.
    code = "define('NAGIMANGA',true); require '" + (site / "lib/bootstrap.php").as_posix() + "'; require '" + (site / "lib/log.php").as_posix() + "'; $t=(new DateTimeImmutable('2026-10-04 12:30:33',new DateTimeZone('Asia/Tokyo')))->getTimestamp(); echo nl_new_id($t),' '; nl_write_record(nl_post_file('20261004123033'),['id'=>'20261004123033']); echo nl_new_id($t); unlink(nl_post_file('20261004123033'));"
    env = dict(os.environ, NAGIMANGA_DATA=str(site / "data"))
    args = helper.php_cmd(0, False, site)
    args = args[:args.index("-S")] + ["-r", code]
    fixed = subprocess.run(args, env=env, capture_output=True, text=True)
    check("同じ秒の番号は連番で衝突を回避", fixed.stdout == "20261004123033 20261004123033-02")

    prefix = "define('NAGIMANGA',true); require '" + (site / 'lib/bootstrap.php').as_posix() + "'; require '" + (site / 'lib/log.php').as_posix() + "'; "
    order_code = prefix + "$ids=['20261004123033-99','20261004123033-100','20261004123033-101']; foreach($ids as $id) nl_write_record(nl_post_file($id),['id'=>$id,'body'=>'#順番'.substr($id,15),'created'=>1,'updated'=>time()+10,'status'=>'published']); nl_rebuild_index(); echo json_encode([array_values(array_filter(array_column(nl_post_summaries(),'id'),fn($id)=>in_array($id,$ids,true))),nl_recent_hashtags(true,3)],JSON_UNESCAPED_UNICODE); foreach($ids as $id) unlink(nl_post_file($id)); nl_rebuild_index();"
    order = json.loads(subprocess.run(args[:args.index('-r')] + ['-r', order_code], env=env, capture_output=True, text=True, encoding='utf-8', check=True).stdout)
    check("同じ秒の100件目以降も新しい順に並ぶ", order[0] == ['20261004123033-101', '20261004123033-100', '20261004123033-99'])
    check("直近ハッシュタグも100件目以降の順序を維持", order[1] == ['順番101', '順番100', '順番99'])

    # .htaccess ignored: metadata is still guarded PHP rather than readable JSON.
    raw = helper.Client(raw_port)
    record = next((site / "data/log/posts").rglob(pid + ".php"))
    check("htaccessがなくても投稿の内部記録は404", raw.get("/" + record.relative_to(site).as_posix()).status == 404)
    check("htaccessがなくても画像の内部記録は404", all(raw.get("/" + f.relative_to(site).as_posix()).status == 404 for f in (site / "data/log/media").rglob("media.php")))
    shutil.copy(HERE.parent / "plugins/guest-mode.php", site / "plugins/guest-mode.php")
    guest = helper.Client(c.port)
    guest.get("/admin/index.php?guest")
    gr = guest.get("/admin/index.php")
    guest_csrf = helper.csrf_of(gr.text)
    check("ゲストに下書き画面を見せない", "本文を入力" not in guest.get("/admin/index.php?p=log").text and "LOGはゲスト" in guest.get("/admin/index.php?p=log").text or guest.get("/admin/index.php?p=log").status == 200 and "data-log-editor" not in guest.get("/admin/index.php?p=log").text)
    check("ゲストの画像一覧APIは404", guest.get("/admin/index.php?p=log_media_json").status == 404)
    check("ゲストのLOG保存は拒否", guest.post("/admin/index.php", {"csrf": guest_csrf, **payload()}).status in (403, 404))

    keep_draft = json.loads(post(payload(body='バックアップでも非公開の下書き', status='draft')).text)['id']
    settings = {'do': 'log_settings', 'title': 'テストLOG', 'name': '記録する人', 'description': '日々の記録'}
    settings_page = c.get('/admin/index.php?p=settings').text
    check("設定画面で6種類のデザインを選べる", len(re.findall(r'type="radio" name="theme"', settings_page)) == 6 and 'name="icon"' in settings_page)
    for theme in ('light-blue', 'light-sage', 'light-paper', 'dark-navy', 'dark-charcoal', 'dark-plum'):
        post({**settings, 'theme': theme})
        check('デザインを保存して表示: ' + theme, f'data-log-theme="{theme}"' in public.get('/').text)
    post({**settings, 'theme': 'unknown-theme'})
    check("6種類以外のデザインを拒否", 'data-log-theme="dark-plum"' in public.get('/').text and '6種類から' in c.get('/admin/index.php?p=settings').text)
    icon_save = post({**settings, 'theme': 'dark-plum'}, files={'icon': ('icon.png', helper.png(64, 64, extra_after_iend=b'<?php echo "icon-code"; ?>'), 'image/png')})
    icon_html = public.get('/').text
    icon_id = re.search(r'class="log-site-avatar" src="\./\?media=([a-f0-9]{16})', icon_html).group(1)
    icon_url = '/?media=' + icon_id + '&thumb=1'
    check("設定からアイコンをアップロードして公開", icon_save.status == 303 and public.get(icon_url).status == 200 and 'icon-code' not in public.get(icon_url).text)
    check("投稿とログインに共通アイコンを表示", re.search(r'class="log-avatar" src="\./\?media=' + icon_id, icon_html) is not None and icon_id in public.get('/admin/login.php').text)
    post({'do': 'log_media_delete', 'media': icon_id, 'revision': 1})
    check("アイコンの使用中画像は削除できない", public.get(icon_url).status == 200)
    post({**settings, 'theme': 'dark-plum'}, files={'og_image': ('share.png', helper.png(1200, 630), 'image/png')})
    og_html = public.get('/').text
    og_id = re.search(r'property="og:image" content="[^\"]*media=([a-f0-9]{16})', og_html).group(1)
    og_url = '/?media=' + og_id
    check("共通OGP画像を設定して公開", public.get(og_url).status == 200)
    check("画像のない記事では設定したOGPを使う", og_id in re.search(r'property="og:image" content="([^"]*)', public.get('/?id=' + pid).text).group(1))
    check("画像のある記事では最初の画像を優先", mid in re.search(r'property="og:image" content="([^"]*)', public.get('/?id=' + image_pid).text).group(1))
    check("個別記事はnoindexだがOGPを残す", public.get('/?id=' + pid).getheader('X-Robots-Tag') == 'noindex,follow' and 'content="noindex,follow"' in public.get('/?id=' + pid).text)
    categorized = post(payload(body='分類のある記録。\n#らくがき **#制作メモ** #らくがき\nhttps://example.com/#URL内はタグではない', new_categories='日記、制作'))
    category_pid = json.loads(categorized.text)['id']
    category_post = public.get('/?id=' + category_pid).text
    category_id = re.search(r'<nav class="log-categories"[^>]*><span class="log-categories-label">カテゴリ</span><a href="\./\?category=([a-f0-9]{12})"><svg class="log-cat-icon"[^>]*>.*?</svg><span>日記</span>', category_post).group(1)
    tag_query = helper.urllib.parse.quote('らくがき')
    check("投稿時に複数のカテゴリを作れる", '<span>日記</span></a>' in category_post and '<span>制作</span></a>' in category_post)
    check("日本語ハッシュタグを本文でリンクにする", './?tag=' + tag_query in category_post and '>#らくがき</a>' in category_post)
    check("太字の中もハッシュタグになる", '<strong><a class="log-hashtag"' in category_post)
    check("URL内のフラグメントはハッシュタグにしない", '>#URL内はタグではない</a>' not in category_post)
    second_cat = json.loads(post(payload(body='同じ分類の次の記録。 #らくがき', **{'categories[0]': category_id})).text)['id']
    category_page = public.get('/?category=' + category_id)
    check("既存のカテゴリを選べる", 'name="categories[]" value="' + category_id in c.get('/admin/index.php?p=log').text and second_cat in category_page.text)
    check("カテゴリとトップだけをインデックス対象にする", category_page.getheader('X-Robots-Tag') == 'index,follow' and public.get('/').getheader('X-Robots-Tag') == 'index,follow' and public.get('/?page=2').getheader('X-Robots-Tag') == 'noindex,follow')
    tag_page = public.get('/?tag=' + tag_query)
    check("ハッシュタグ別に公開投稿を絞る", category_pid in tag_page.text and second_cat in tag_page.text and tag_page.getheader('X-Robots-Tag') == 'noindex,follow')
    tax_revision = re.search(r'name="kind" value="category".*?name="revision" value="([0-9]+)"', c.get('/admin/index.php?p=log&view=taxonomy').text).group(1)
    post({'do': 'log_taxonomy_rename', 'kind': 'category', 'old': category_id, 'name': '日々の記録', 'revision': tax_revision})
    check("カテゴリを設定画面で改名してもリンク番号を維持", '日々の記録の記録' in public.get('/?category=' + category_id).text)
    tag_draft = json.loads(post(payload(body='非公開の分類メモ #らくがき', status='draft')).text)['id']
    tax_revision = re.search(r'name="kind" value="hashtag".*?name="revision" value="([0-9]+)"', c.get('/admin/index.php?p=log&view=taxonomy').text).group(1)
    post({'do': 'log_taxonomy_rename', 'kind': 'hashtag', 'old': 'らくがき', 'name': 'お絵描き', 'revision': tax_revision})
    check("ハッシュタグ改名を本文と下書きに反映", '>#お絵描き</a>' in public.get('/?id=' + category_pid).text and '#お絵描き' in c.get('/admin/index.php?p=log_edit&id=' + tag_draft).text)
    check("分類の古い設定画面からの上書きを拒否", post({'do': 'log_taxonomy_rename', 'kind': 'hashtag', 'old': 'お絵描き', 'name': '古い更新', 'revision': tax_revision}).status == 303 and '>#お絵描き</a>' in public.get('/?id=' + category_pid).text)
    numeric_category = '123456789012'
    numeric_code = prefix + "$t=nl_taxonomy(); $t['categories']['123456789012']='数字の分類'; nl_write_record(nl_root().'/taxonomy.php',$t);"
    subprocess.run(args[:args.index('-r')] + ['-r', numeric_code], env=env, check=True)
    numeric_post = json.loads(post(payload(body='数字だけの分類番号。', **{'categories[0]': numeric_category})).text)['id']
    numeric_page = public.get('/?category=' + numeric_category)
    check("数字だけのカテゴリIDでも絞り込みを表示", numeric_page.status == 200 and numeric_post in numeric_page.text and '</html>' in numeric_page.text)
    check("数字カテゴリの投稿・設定画面も最後まで表示", all('</html>' in c.get('/admin/index.php?p=' + page).text for page in ('log', 'settings')))
    legacy_category_code = prefix + "$p=nl_load_post('" + numeric_post + "'); $p['categories']=[123456789012]; nl_write_record(nl_post_file($p['id']),$p); $ix=nl_read_record(nl_root().'/index.php'); $ix['schema']=3; nl_write_record(nl_root().'/index.php',$ix);"
    subprocess.run(args[:args.index('-r')] + ['-r', legacy_category_code], env=env, check=True)
    check("旧形式の数値カテゴリを読み直して絞り込む", numeric_post in public.get('/?category=' + numeric_category).text and '</html>' in c.get('/admin/index.php?p=log_edit&id=' + numeric_post).text)
    backup = post({"do": "log_backup"})
    check("LOGバックアップを作る", backup.status == 200 and backup.body.startswith(b"PK"))
    archive = zipfile.ZipFile(io.BytesIO(backup.body))
    check("バックアップに投稿と画像を含める", any(n.startswith("posts/") for n in archive.namelist()) and any(n.startswith("media/") for n in archive.namelist()))
    check("バックアップに管理者の秘密を含めない", not any("config" in n for n in archive.namelist()) and "admin_hash" not in archive.read("backup.json").decode())
    restored = other.post("/admin/index.php", {"csrf": other_csrf, "do": "log_restore"}, files={"backup": ("log.zip", backup.body, "application/zip")})
    check("違う秘密鍵の設置先へ復元", restored.status == 303)
    check("デザインとアイコンも引っ越せる", 'data-log-theme="dark-plum"' in other.get('/').text and icon_id in other.get('/').text and other.get(icon_url).status == 200)
    check("カテゴリ・ハッシュタグ・OGPも復元できる", '日々の記録の記録' in other.get('/?category=' + category_id).text and '#お絵描き' in other.get('/?id=' + category_pid).text and other.get(og_url).status == 200)
    check("数字カテゴリを含むバックアップも復元", numeric_post in other.get('/?category=' + numeric_category).text and '</html>' in other.get('/admin/index.php?p=settings').text)
    check("復元先でも同じ投稿番号と画像タグ", "公開した画像" in other.get("/?id=" + image_pid).text and other.get(public_media_url).status == 200)
    check("復元先でも下書きを非公開にする", all(other.get("/?id=" + Path(n).stem).status == 404 for n in archive.namelist() if n.startswith("posts/d")))
    check("同じLOGを上書きなしで再復元", other.post("/admin/index.php", {"csrf": other_csrf, "do": "log_restore"}, files={"backup": ("log.zip", backup.body, "application/zip")}).status == 303)
    other_edit = other.get('/admin/index.php?p=log_edit&id=' + pid)
    over_ver = re.search(r'name="revision" value="([0-9]+)"', other_edit.text).group(1)
    other.post('/admin/index.php', {'csrf': other_csrf, **payload(body='復元先で変更', post_id=pid, revision=over_ver)})
    other.post('/admin/index.php', {'csrf': other_csrf, 'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('log.zip', backup.body, 'application/zip')})
    check("上書き復元で元の本文に戻せる", '編集した本文' in other.get('/?id=' + pid).text)
    check("下書きもバックアップから復元できる", keep_draft in other.get('/admin/index.php?p=log').text and other.get('/?id=' + keep_draft).status == 404)
    badzip = io.BytesIO()
    with zipfile.ZipFile(badzip, "w") as z:
        z.writestr("backup.json", archive.read("backup.json"))
        z.writestr("../../evil.php", "<?php echo 'evil';")
    post({"do": "log_restore"}, files={"backup": ("bad.zip", badzip.getvalue(), "application/zip")})
    check("LOG復元でZIPの外のパスを拒否", "LOG以外のファイル" in c.get("/admin/index.php?p=log").text)
    check("不正な復元で既存投稿を変えない", "公開した画像" in public.get("/?id=" + image_pid).text)
    index_file = site / 'data/log/index.php'
    index_file.unlink()
    post_count = len(list((site / 'data/log/posts').glob('*/*.php')))
    recovered_pages = ''.join(public.get('/?page=' + str(n)).text for n in range(1, 1 + (post_count + 9) // 10))
    check("一覧情報がなくても投稿原本から読める", '編集した本文' in recovered_pages and public.get('/?id=' + pid).status == 200)
    image_edit = c.get("/admin/index.php?p=log_edit&id=" + image_pid)
    image_revision = re.search(r'name="revision" value="([0-9]+)"', image_edit.text).group(1)
    post(payload(body="公開した画像\n" + media["tag"], status="draft", post_id=image_pid, revision=image_revision))
    check("公開を取り消すと投稿も画像も404", public.get("/?id=" + image_pid).status == 404 and public.get(public_media_url).status == 404)
    check("非公開化した画像をキャッシュで公開しない", public.get(public_media_url, headers={"If-None-Match": '"old"'}).status == 404)
    check("保存時に一覧情報を作り直す", index_file.is_file())
    for i in range(21):
        post(payload(body=f'一覧のページ送り {i}'))
    check("公開一覧を10件ずつ表示", public.get('/').text.count('<article class="log-post">') == 10 and '前の投稿' in public.get('/').text and public.get('/?page=2').status == 200)
    newest = [json.loads(post(payload(body=f'最新の記録{i}。\n2行目は本文です。', title='手動タイトルは使わない')).text)['id'] for i in range(4)]
    page = public.get('/').text
    check("最初の1行を投稿タイトルにする", '<h2><a href="./?id=' + newest[-1] + '">最新の記録3。</a></h2>' in page and '手動タイトルは使わない' not in page)
    latest = re.search(r'<ol class="log-latest">(.*?)</ol>', page, re.S).group(1)
    latest_ids = re.findall(r'href="\./\?id=([0-9a-z-]+)"', latest)
    check("最新ポストは公開された3件", latest_ids == list(reversed(newest[1:])) and keep_draft not in latest_ids)
    today = time.strftime('%Y-%m-%d', time.localtime())
    daily = public.get('/?date=' + today).text
    check("カレンダーの日を選んで投稿を絞れる", today + 'の記録' in daily and 'aria-current="date"' in daily and newest[-1] in daily)
    check("日付のページ送りも絞り込みを維持", '?date=' + today + '&amp;page=2' in daily)
    monthly = public.get('/?month=' + today[:7]).text
    check("カレンダーを月で送れる", today[:7] + 'の記録' in monthly and '前の月' in monthly and '次の月' in monthly)
    check("記録がない日も表示できる", 'この日の記録はありません' in public.get('/?date=2025-01-02').text)
    check("不正な日付や配列を拒否", public.get('/?date=2026-02-30').status == 404 and public.get('/?month=2026-13').status == 404 and public.get('/?date[]=2026-10-04').status == 404)
    check("日付別ページのcanonicalと索引設定", '?date=' + today in daily and 'content="noindex,follow"' in daily)
    check("サイドメニューに最終更新日を表示", '最終更新日</h2><time datetime=' in page)
    check("スマホ用のMENU付きハンバーガーメニューを用意", 'aria-controls="log-sidebar"' in page and 'M5 7h14M5 12h14M5 17h14' in page and '<span class="log-menu-label" aria-hidden="true">MENU</span>' in page and '◆</span>' not in page and 'viewer/log-menu.js' in page)
    post({**settings, 'theme': 'light-blue', 'remove_icon': '1'})
    check("アイコンを外すと頭文字に戻り画像も非公開", '<span class="log-site-avatar"' in public.get('/').text and public.get(icon_url).status == 404)
    def asset_key(path, html):
        return re.search(re.escape(path) + r'\?v=([a-z0-9.]+)', html).group(1)
    before = public.get('/').text
    for path in ('viewer/log.css', 'viewer/log-menu.js', 'viewer/log-mail.js'):
        original = (site / path).read_bytes()
        (site / path).write_bytes(original + b'\n/* cache-key fixture */\n')
        after = public.get('/').text
        check('ファイル変更でキャッシュバスターを更新: ' + path, asset_key(path, before) != asset_key(path, after))
        (site / path).write_bytes(original)
    manga_css = site / 'viewer/NagiManga.css'
    original = manga_css.read_bytes()
    manga_css.write_bytes(original + b'\n/* reader CSS fixture */\n')
    check("漫画CSSだけの変更でもJSとCSSのキャッシュキーが変わる", asset_key('viewer/NagiManga.js', before) != asset_key('viewer/NagiManga.js', public.get('/').text))
    manga_css.write_bytes(original)
    check("変更がなければキャッシュキーを維持", asset_key('viewer/log.css', before) == asset_key('viewer/log.css', public.get('/').text))
    for i in range(10):
        post(payload(body=f'最近のタグ確認{i}。 #最近{i}'))
    recent_html = re.search(r'<div class="log-recent-tags">(.*?)</div></div>', c.get('/admin/index.php?p=log').text, re.S).group(1)
    check("直近で使ったハッシュタグを8件表示", recent_html.count('data-hashtag=') == 8 and 'data-hashtag="最近9"' in recent_html and 'data-hashtag="最近0"' not in recent_html)
    check("画像収集ツールの画像アクセスを404にする", public.get(og_url, headers={'User-Agent': 'ImageScraper/1.0'}).status == 404 and public.get('/read.php?a=o&id=' + manga['id'], headers={'User-Agent': 'HTTrack/3.0'}).status == 404)
    check("AIによる文章閲覧を一律に拒否しない", public.get('/', headers={'User-Agent': 'GPTBot'}).status == 200)
    check("普通の画像閲覧と共有カードは維持", public.get(og_url, headers={'User-Agent': 'Mozilla/5.0'}).status == 200 and public.get(og_url, headers={'User-Agent': 'facebookexternalhit/1.1'}).status == 200)
    hole = public.get('/?id=missing', headers={'Accept': 'text/html'})
    check("ブラウザの404はブラックホール画面", hole.status == 404 and 'nm-blackhole' in hole.text and 'noindex, nofollow' in hole.getheader('X-Robots-Tag'))
    check("存在しないパスでもブラックホール404", public.get('/missing-page', headers={'Accept': 'text/html'}).status == 404 and 'nm-blackhole' in public.get('/missing-page', headers={'Accept': 'text/html'}).text)
    check("404はJavaScriptなしで動きを減らす設定に対応", 'prefers-reduced-motion' in public.get('/viewer/404.css').text and '<script' not in hole.text)
    post({'do': 'log_guard_settings', 'enabled': '1', 'burst': '10', 'minute': '60', 'agents': 'ImageScraper'})
    guard_code = "define('NAGIMANGA',true); require '" + (site / 'lib/bootstrap.php').as_posix() + "'; $file=NM_DATA.'/ratelimit/image-'.hash_hmac('sha256','127.0.0.1',nm_config()['secret']).'.php'; $s=['short'=>intdiv(time(),10),'minute'=>intdiv(time(),60),'burst'=>10,'count'=>60,'until'=>0]; nm_write_atomic($file,'<?php if(!defined(\"NAGIMANGA\")){http_response_code(404);exit;} return '.var_export($s,true).';');"
    subprocess.run(args[:args.index('-r')] + ['-r', guard_code], env=env, check=True)
    check("画像の大量取得を404で止める", public.get(og_url).status == 404)
    check("大量取得の拒否は漫画画像にも共用", public.get('/read.php?a=o&id=' + manga['id']).status == 404 and public.get('/').status == 200)
    post({'do': 'log_guard_settings', 'burst': '120', 'minute': '300', 'agents': 'ImageScraper'})
    check("設定でBOT対策を切り替えられる", public.get(og_url).status == 200)


def main():
    RESULTS.mkdir(exist_ok=True)
    root = RESULTS / ("log-test-" + secrets.token_hex(4))
    root.mkdir()
    site = root / "site"
    other_site = root / "other"
    shutil.copytree(HERE.parent / "server", site, ignore=shutil.ignore_patterns('paths.php'))
    shutil.copytree(HERE.parent / "server", other_site, ignore=shutil.ignore_patterns('paths.php'))
    processes = [start(site, 5194), start(site, 5195, raw=True), start(other_site, 5196)]
    try:
        c, csrf, key = install(5194)
        other, other_csrf, _ = install(5196)
        run(c, csrf, key, site, 5195, other, other_csrf)
    finally:
        for process in processes:
            process.terminate()
            process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    report = ["# LOG動作確認", "", f"{passed} / {len(checks)} passed", "", "| 結果 | 確認 |", "| --- | --- |"]
    report += [f"| {'PASS' if ok else 'FAIL'} | {name} |" for name, ok in checks]
    (RESULTS / "log-report.md").write_text("\n".join(report) + "\n", encoding="utf-8")
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == "__main__":
    main()
