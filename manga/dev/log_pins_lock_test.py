#!/usr/bin/env python3
"""Clean URLs, pinned posts, password locks and the favicon set, on fresh data.

The first server runs with NAGIMANGA_PRETTY=1 (the rewrite rules of the shipped .htaccess);
the second serves the same data without them, as on a host that ignores .htaccess.
Usage: python manga/dev/log_pins_lock_test.py
"""
import argparse, io, json, os, re, secrets, socket, struct, sys, zlib
from urllib.parse import quote
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5420)
parser.add_argument('--plain-port', type=int, default=5422)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def rgba_png(w, h_, seed=0):
    """RGBA PNG: a coloured disc on a transparent square (alpha matters for icons)."""
    def chunk(t, data):
        c = struct.pack('>I', len(data)) + t + data
        return c + struct.pack('>I', zlib.crc32(t + data) & 0xFFFFFFFF)
    raw = bytearray()
    cx, cy, r = w / 2, h_ / 2, min(w, h_) * .45
    for y in range(h_):
        raw.append(0)
        for x in range(w):
            inside = (x - cx) ** 2 + (y - cy) ** 2 <= r * r
            raw.extend((40 + seed, 120, 220, 255) if inside else (0, 0, 0, 0))
    ihdr = struct.pack('>IIBBBBB', w, h_, 8, 6, 0, 0, 0)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', ihdr) + chunk(b'IDAT', zlib.compress(bytes(raw))) + chunk(b'IEND', b'')


def png_size(data):
    return struct.unpack('>II', data[16:24]) if data[:8] == b'\x89PNG\r\n\x1a\n' else None


def run(site, c, csrf):
    pub = h.Client(args.port); plain = h.Client(args.plain_port)
    json_head = {'Accept': 'application/json'}
    base_url = f'http://{h.HOST}:{args.port}'
    def post(data, files=None, client=c, headers=None): return client.post('/admin/index.php', {'csrf': csrf, **data}, files=files, headers=headers)
    def save(body, status='published', **extra):
        r = post(base.payload(body, status, **extra))
        return json.loads(r.text)
    def page_save(**f):
        data = {'do': 'log_page_save', 'page_id': '', 'revision': '0', 'title': '', 'slug': '', 'body': '', 'layout': 'narrow', 'status': 'published'}
        data.update({k: str(v) for k, v in f.items()})
        return post(data, headers=json_head)
    def settings(section, data, files=None):
        return post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': section, **data}, files=files, headers=json_head)
    def article_ids(html):
        return re.findall(r'<article class="log-post[^"]*">.*?<h2><a href="\./([0-9]{14}(?:-[0-9]{2,6})?)"', html, re.S)

    # --- Clean URLs -----------------------------------------------------------------------------
    first = save('いちばん古い記事\n本文1')
    second = save('まんなかの記事\n本文2')
    third = save('いちばん新しい記事\n本文3')
    ids = [first['id'], second['id'], third['id']]
    check('保存の応答に記事のきれいなURLが入る', first.get('url') == '../' + first['id'])
    r = pub.get('/' + first['id'])
    check('記事はファイル名も?もないURLで開ける', r.status == 200 and f'<link rel="canonical" href="{base_url}/{first["id"]}">' in r.text)
    r = pub.get('/?id=' + first['id'])
    check('今までの ./?id= は新しいURLへ301で移る', r.status == 301 and r.getheader('Location') == f'{base_url}/{first["id"]}')
    r = pub.get('/index.php?id=' + first['id'])
    check('index.php?id= も新しいURLへ移る', r.status == 301 and r.getheader('Location') == f'{base_url}/{first["id"]}')
    r = pub.get('/log.php?id=' + first['id'])
    check('log.php?id= は1回で新しいURLへ移る', r.status == 301 and r.getheader('Location') == './' + first['id'])
    r = pub.get('/' + first['id'] + '/')
    check('末尾に / が付いたURLも新しいURLへ移る', r.status == 301 and r.getheader('Location') == f'{base_url}/{first["id"]}')
    top = pub.get('/').text
    check('一覧のリンクは ./<番号>（?id= を使わない）', f'href="./{third["id"]}"' in top and '?id=' not in top)
    check('存在しない番号は404', pub.get('/20000101000000').status == 404)
    feed = pub.get('/?feed=rss').text
    check('RSSのリンクもきれいなURL', f'<link>{base_url}/{third["id"]}</link>' in feed)
    r = page_save(title='プライバシーポリシー', slug='privacy', body='## 方針\n大切に扱います。')
    page_id = re.search(r'id=(pg[a-f0-9]{12})', json.loads(r.text)['redirect']).group(1)
    r = pub.get('/privacy')
    check('固定ページは ./privacy で開ける', r.status == 200 and '大切に扱います' in r.text and f'href="{base_url}/privacy"' in r.text)
    r = pub.get('/?pg=privacy')
    check('今までの ./?pg= は新しいURLへ301で移る', r.status == 301 and r.getheader('Location') == f'{base_url}/privacy')
    r = page_save(title='管理', slug='admin', body='x')
    check('フォルダー名と同じURL名は使えない', r.status == 422 and 'admin' in json.loads(r.text).get('error', ''))
    check('存在しない固定ページ名は404', pub.get('/no-such-page').status == 404)
    plain_top = plain.get('/').text
    check('書き換えのないサーバーでは今までの ./?id= を使う', f'href="./?id={third["id"]}"' in plain_top)
    check('書き換えのないサーバーでは ./?id= のまま開ける（移さない）', plain.get('/?id=' + first['id']).status == 200)

    # --- Pinned posts -------------------------------------------------------------------------------
    post({'do': 'log_pin', 'post_id': first['id'], 'pin': '1'})
    post({'do': 'log_pin', 'post_id': second['id'], 'pin': '1'})
    top = pub.get('/').text
    check('ピン留めした記事がトップの最初に、留めた順で並ぶ', article_ids(top)[:3] == [first['id'], second['id'], third['id']])
    check('ピン留めの印が付く', top.count('log-pin-badge') == 2 and 'is-pinned' in top)
    admin = c.get('/admin/index.php?p=log').text
    rows = re.findall(r'<li class="log-list-row( is-pinned)?">.*?p=log_edit&id=([0-9]{14}(?:-[0-9]{2,6})?)', admin, re.S)
    check('管理画面の一覧でもピン留めが最上部', [i for _, i in rows][:2] == [first['id'], second['id']] and rows[0][0] == ' is-pinned')
    panel = re.search(r'data-order-action="log_pin_order" data-revision="(\d+)"', admin)
    check('管理画面にピン留めの並べ替え欄が出る', panel is not None and admin.count('log-sort-card log-pin-row"') == 2)
    r = post({'do': 'log_pin_order', 'revision': panel.group(1), 'order': json.dumps([second['id'], first['id']])})
    check('並べ替えを保存できる', r.status == 200 and json.loads(r.text).get('ok'))
    check('並べ替えた順でトップに出る', article_ids(pub.get('/').text)[:2] == [second['id'], first['id']])
    r = post({'do': 'log_pin_order', 'revision': panel.group(1), 'order': json.dumps([first['id'], second['id']])})
    check('古い画面からの並べ替えは断る', r.status == 422)
    r = post({'do': 'log_pin_order', 'revision': str(int(panel.group(1)) + 1), 'order': json.dumps([first['id'], third['id']])})
    check('ピン留めしていない記事を混ぜた並べ替えは断る', r.status == 422)
    month = pub.get('/?month=' + first['id'][:4] + '-' + first['id'][4:6]).text
    check('月の一覧は日付順のまま（ピン留めはトップだけ）', article_ids(month)[:1] == [third['id']])
    draft = save('ピン留めした下書き\nまだ', 'draft')
    post({'do': 'log_pin', 'post_id': draft['id'], 'pin': '1'})
    check('ピン留めした下書きは公開ページに出ない', 'ピン留めした下書き' not in pub.get('/').text)
    published = save('ピン留めした下書き\n公開', 'published', post_id=draft['id'], revision='1')
    check('下書きを公開してもピン留めが続く（番号が変わっても）', article_ids(pub.get('/').text)[:3] == [second['id'], first['id'], published['id']])
    post({'do': 'log_pin', 'post_id': first['id'], 'pin': '0'})
    check('ピン留めを外すと日付の位置に戻る', article_ids(pub.get('/').text)[:2] == [second['id'], published['id']])
    edit = c.get('/admin/index.php?p=log_edit&id=' + second['id']).text
    check('編集画面にピン留めの切り替えがある', 'log-pin-panel' in edit and 'ピン留めを外す' in edit)
    rev = re.search(r'name="revision" value="(\d+)"', c.get('/admin/index.php?p=log_edit&id=' + published['id']).text).group(1)
    post({'do': 'log_delete', 'post_id': published['id'], 'revision': rev})
    pins = (site / 'data/log/pins.php').read_text(encoding='utf-8')
    check('削除した記事はピン留めから外れる', published['id'] not in pins and second['id'] in pins)

    # --- Locks ------------------------------------------------------------------------------------------
    r = post({'do': 'log_upload'}, files={'image': ('secret.png', h.png(64, 48, 9), 'image/png')})
    media = json.loads(r.text)['media']
    locked = save(f'鍵のかかった記事\n秘密の本文です\n[Image:{media["id"]}]', lock_form='1', lock='1', lock_password='open-sesame')
    lid = locked['id']
    record = (site / f'data/log/posts/{lid[:4]}/{lid}.php').read_text(encoding='utf-8')
    check('パスワードはハッシュで保存（平文で残さない）', "'hash' => '$2y$" in record or "'hash' => '$argon2" in record)
    check('パスワードそのものはファイルに書かない', 'open-sesame' not in record)
    r = pub.get('/' + lid)
    check('鍵付きの記事はタイトルと入力欄だけ', r.status == 200 and '鍵のかかった記事' in r.text and 'log-lock-form' in r.text and '秘密の本文' not in r.text and media['id'] not in r.text)
    form = re.search(r'<form class="log-lock-form".*?</form>', r.text, re.S).group(0)
    check('入力欄はパスワード欄ではなく、自動入力・保存を止める', 'type="password"' not in form and 'autocomplete="off"' in form and 'data-1p-ignore' in form)
    check('鍵付きの記事は検索エンジンに載せない', 'noindex' in (r.getheader('X-Robots-Tag') or ''))
    check('説明文に本文を出さない', 'パスワードで保護されています' in re.search(r'<meta name="description" content="([^"]*)"', r.text).group(1))
    top = pub.get('/').text
    check('一覧でも本文を出さない', '秘密の本文' not in top and 'パスワードを入れて読む' in top)
    check('画像は鍵を開けるまで404', pub.get(f'/?media={media["id"]}&v=1&format=image.webp').status == 404)
    check('検索で本文の言葉では見つからない', lid not in article_ids(pub.get('/?q=' + quote('秘密の本文')).text))
    check('タイトルでは見つかる', lid in article_ids(pub.get('/?q=' + quote('鍵のかかった')).text))
    check('RSSに本文を出さない', '秘密の本文' not in pub.get('/?feed=rss').text)
    og = re.search(r'<meta property="og:image" content="([^"]+)"', pub.get('/' + lid).text).group(1).replace('&amp;', '&')
    card = pub.get(og[len(base_url):])
    check('共有画像は最初の画像をぼかし、鍵の印を重ねたカード（画像そのものは出さない）', '?ogimage=' + lid in og and media['id'] not in og and card.status == 200 and card.getheader('Content-Type') in ('image/webp', 'image/jpeg'))
    reader = h.Client(args.port)
    unlock = lambda client, pw, headers=None: client.post('/', {'nl_unlock': '1', 'item': lid, 'lockkey': pw}, headers=headers or {'Sec-Fetch-Site': 'same-origin'})
    r = unlock(reader, 'wrong-word')
    check('違うパスワードは記事へ戻して知らせる', r.status == 303 and r.getheader('Location') == f'{base_url}/{lid}?locked=wrong' and 'nl_keys' not in reader.cookies)
    r = reader.get('/' + lid + '?locked=wrong')
    check('「パスワードが違います」と出す', 'パスワードが違います' in r.text and '秘密の本文' not in r.text)
    r = unlock(reader, 'open-sesame', {'Sec-Fetch-Site': 'cross-site'})
    check('ほかのサイトからの送信は受け付けない', r.status == 404 and 'nl_keys' not in reader.cookies)
    r = unlock(reader, 'open-sesame')
    cookie = r.getheader('Set-Cookie') or ''
    check('正しいパスワードで記事へ戻り、鍵の印をクッキーに残す', r.status == 303 and r.getheader('Location') == f'{base_url}/{lid}' and cookie.startswith('nl_keys=') and 'HttpOnly' in cookie and 'open-sesame' not in cookie)
    check('クッキーにパスワードもハッシュも入れない', '$2y$' not in cookie and 'open-sesame' not in reader.cookies.get('nl_keys', ''))
    r = reader.get('/' + lid)
    check('開いた人には本文と画像を表示', '秘密の本文' in r.text and media['id'] in r.text)
    r = reader.get(f'/?media={media["id"]}&v={media["revision"]}&format=image.webp')
    check('鍵で開いた画像はその人のブラウザーだけにキャッシュ', r.status == 200 and (r.getheader('Cache-Control') or '').startswith('private'))
    check('開いた人には一覧でも本文を表示', '秘密の本文' in reader.get('/').text)
    check('ほかの人にはまだ閉じたまま', '秘密の本文' not in pub.get('/' + lid).text)
    owner = c.get('/' + lid).text
    check('持ち主にはいつも本文と「鍵付き」の印', '秘密の本文' in owner and 'log-lock-badge' in owner)
    edit = c.get('/admin/index.php?p=log_edit&id=' + lid).text
    check('編集画面に今のパスワードを表示（持ち主だけ）', 'value="open-sesame"' in edit and 'data-lock-toggle checked' in edit)
    rev = re.search(r'name="revision" value="(\d+)"', edit).group(1)
    save(f'鍵のかかった記事\n秘密の本文です\n[Image:{media["id"]}]', post_id=lid, revision=rev)
    check('鍵の欄のない古い画面から保存しても鍵は外れない', 'log-lock-form' in pub.get('/' + lid).text)
    rev = str(int(rev) + 1)
    save(f'鍵のかかった記事\n秘密の本文です\n[Image:{media["id"]}]', post_id=lid, revision=rev, lock_form='1', lock='1', lock_password='new-word')
    check('パスワードを変えると、開いていた人も閉じる', '秘密の本文' not in reader.get('/' + lid).text)
    r = save('鍵だけで本文なし\nx', lock_form='1', lock='1', lock_password='')
    check('パスワードを空にして鍵をかけようとすると断る', 'error' in r)
    r = save('短い鍵\nx', lock_form='1', lock='1', lock_password='abc')
    check('3文字のパスワードは断る', 'error' in r)
    guesser = h.Client(args.port)
    for _ in range(5): unlock(guesser, 'nope')
    r = unlock(guesser, 'new-word')
    check('続けて5回間違えると、正しくても15分は受け付けない', r.getheader('Location', '').endswith('locked=wait') and 'nl_keys' not in guesser.cookies)
    # A locked fixed page.
    r = page_save(title='会員向け', slug='members', body='会員だけの本文', lock_form='1', lock='1', lock_password='members-only')
    mid = re.search(r'id=(pg[a-f0-9]{12})', json.loads(r.text)['redirect']).group(1)
    r = pub.get('/members')
    check('鍵付きの固定ページはタイトルと入力欄だけ', '会員向け' in r.text and 'log-lock-form' in r.text and '会員だけの本文' not in r.text)
    member = h.Client(args.port)
    member.post('/', {'nl_unlock': '1', 'item': mid, 'lockkey': 'members-only'}, headers={'Sec-Fetch-Site': 'same-origin'})
    check('正しいパスワードで固定ページも開く', '会員だけの本文' in member.get('/members').text)
    check('鍵付きの固定ページはサイトマップに載せない', '/members' not in pub.get('/?sitemap=xml').text)
    # Backup and restore keep the locks and the pins.
    backup = post({'do': 'log_backup'}).body
    post({'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('log.zip', backup, 'application/zip')})
    check('バックアップから戻しても鍵はかかったまま', 'log-lock-form' in pub.get('/' + lid).text and 'log-lock-form' in pub.get('/members').text)
    check('バックアップから戻してもピン留めは残る', article_ids(pub.get('/').text)[:1] == [second['id']])

    # --- Favicon ------------------------------------------------------------------------------------------
    design = {'title': 'テストLOG', 'name': 'わたし', 'theme': 'light-blue', 'description': ''}
    r = settings('log_design', design, files={'favicon': ('icon.png', rgba_png(600, 400), 'image/png')})
    check('ファビコンの画像を保存できる', r.status == 200 and json.loads(r.text).get('ok'))
    top = pub.get('/').text
    v = re.search(r'<link rel="icon" href="\./\?favicon=ico&amp;v=([a-f0-9]{12})"', top)
    check('ページにfavicon.ico・ホーム画面のアイコン・manifestを入れる', v is not None and 'rel="apple-touch-icon"' in top and 'rel="manifest"' in top)
    v = v.group(1) if v else ''
    ico = pub.get(f'/?favicon=ico&v={v}')
    count = struct.unpack('<HHH', ico.body[:6]) if len(ico.body) > 6 else None
    check('favicon.icoは16・32・48pxのPNG入り', ico.status == 200 and ico.getheader('Content-Type') == 'image/x-icon' and count == (0, 1, 3) and ico.body.count(b'\x89PNG') == 3)
    check('バージョン付きのURLは1年キャッシュ', 'immutable' in (ico.getheader('Cache-Control') or ''))
    check('古いバージョンのURLは毎回確かめる', pub.get('/?favicon=ico&v=000000000000').getheader('Cache-Control') == 'no-cache')
    sizes = {name: png_size(pub.get(f'/?favicon={name}&v={v}').body) for name in ('16', '32', '48', '180', '192', '512', 'apple')}
    check('各サイズを正方形で作る（横長の画像は余白を足す）', sizes == {'16': (16, 16), '32': (32, 32), '48': (48, 48), '180': (180, 180), '192': (192, 192), '512': (512, 512), 'apple': (180, 180)})
    manifest = json.loads(pub.get('/?favicon=manifest&v=x').text)
    check('manifestに192・512pxのアイコンとサイト名', [i['sizes'] for i in manifest['icons']] == ['192x192', '512x512'] and manifest['name'] == 'テストLOG')
    check('管理画面のタブにも同じファビコン', f'?favicon=ico&amp;v={v}' in c.get('/admin/index.php?p=log').text)
    check('設定画面に作ったサイズの見本が出る', 'log-favicon-preview' in c.get('/admin/index.php?p=settings&section=log').text)
    r = settings('log_design', dict(design, theme='dark-navy'))
    v2 = re.search(r'\?favicon=ico&amp;v=([a-f0-9]{12})', pub.get('/').text).group(1)
    check('デザインの色を変えると、iPhone用の背景も作り直す', v2 != v and png_size(pub.get(f'/?favicon=apple&v={v2}').body) == (180, 180))
    r = settings('log_design', design, files={'favicon': ('small.png', rgba_png(100, 100), 'image/png')})
    top = pub.get('/').text
    check('小さい画像では、引き伸ばすサイズを作らない', 'rel="icon"' in top and 'rel="manifest"' not in top and 'rel="apple-touch-icon"' not in top)
    check('設定画面で512px以上をすすめる', 'log-favicon-warn' in c.get('/admin/index.php?p=settings&section=log').text)
    r = settings('log_design', design, files={'favicon': ('icon.svg', b'<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"/>', 'image/svg+xml')})
    check('SVGは断る', r.status == 422 and 'SVG' in json.loads(r.text).get('error', ''))
    r = settings('log_design', design, files={'favicon': ('tiny.png', rgba_png(20, 20), 'image/png')})
    check('48px未満は断る', r.status == 422)
    settings('log_design', dict(design, remove_favicon='1'))
    check('ファビコンを外すと今までのアイコンに戻る', '?favicon=' not in pub.get('/').text and pub.get('/?favicon=ico&v=x').status == 404)


def main():
    for port in (args.port, args.plain_port):
        with socket.socket() as sock: sock.bind((h.HOST, port))
    temp = ROOT / 'manga/dev/results' / ('pins-lock-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    processes = []
    try:
        os.environ['NAGIMANGA_PRETTY'] = '1'
        processes.append(base.start(site, args.port))
        os.environ.pop('NAGIMANGA_PRETTY')
        processes.append(base.start(site, args.plain_port))
        c, csrf, _ = base.install(args.port)
        run(site, c, csrf)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
