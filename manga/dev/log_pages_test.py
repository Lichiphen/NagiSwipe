#!/usr/bin/env python3
"""Fixed pages, top menu, title logo, description switch, SVG pictures and standalone uploads, on fresh private data.

Usage: python manga/dev/log_pages_test.py [--svg PATH]   (PATH: an extra SVG that must pass, e.g. a banner)
"""
import argparse, io, json, re, secrets, shutil, socket, sys, zipfile
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5410)
parser.add_argument('--other-port', type=int, default=5412)
parser.add_argument('--svg', action='append', default=[])
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)

BANNER = b'''<?xml version="1.0" encoding="UTF-8"?>
<!-- made in an editor -->
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" width="200" height="40" viewBox="0 0 200 40" role="img" aria-labelledby="t">
  <title id="t">Banner</title>
  <metadata><rdf>editor data</rdf></metadata>
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#bfe9ff"/><stop offset="1" stop-color="#f6fcff"/></linearGradient>
    <filter id="soft"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="#1c4f8a" flood-opacity=".2"/></filter>
    <clipPath id="frame"><rect width="200" height="40"/></clipPath>
    <style>.wave { fill: url(#sky); }</style>
  </defs>
  <g clip-path="url(#frame)" inkscape:label="layer">
    <rect width="200" height="40" fill="url(#sky)"/>
    <path class="wave" d="M0 33 C22 29 35 36 59 33 V40 H0Z" filter="url(#soft)"/>
    <use xlink:href="#frame"/>
    <a href="https://example.com/"><circle cx="190" cy="7" r="5"/></a>
    <text x="10" y="25" font-size="14">NagiLog</text>
  </g>
</svg>'''
# Each of these must be refused (name, bytes, file name).
EVIL = [
    ('script要素', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>', 'a.svg'),
    ('onload属性', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="alert(1)"/>', 'a.svg'),
    ('大文字のONCLICK', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect ONCLICK="alert(1)" width="5" height="5"/></svg>', 'a.svg'),
    ('foreignObjectのHTML', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(1)"/></div></foreignObject></svg>', 'a.svg'),
    ('javascript:リンク', b'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10"><a xlink:href="javascript:alert(1)"><rect width="5" height="5"/></a></svg>', 'a.svg'),
    ('空白入りのjava script:', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><a href="java&#x09;script:alert(1)"><rect width="5" height="5"/></a></svg>', 'a.svg'),
    ('外部画像image', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image href="https://evil.example/x.png" width="5" height="5"/></svg>', 'a.svg'),
    ('外部ファイルのuse', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><use href="https://evil.example/s.svg#a"/></svg>', 'a.svg'),
    ('styleの@import', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><style>@import url(https://evil.example/a.css);</style></svg>', 'a.svg'),
    ('styleの外部url()', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect style="fill:url(https://evil.example/p.svg#g)" width="5" height="5"/></svg>', 'a.svg'),
    ('CSSエスケープ', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><style>rect{fill:u\\72l(https://evil.example/)}</style></svg>', 'a.svg'),
    ('DOCTYPEとENTITY', b'<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text>&x;</text></svg>', 'a.svg'),
    ('xml-stylesheet処理命令', b'<?xml version="1.0"?><?xml-stylesheet href="https://evil.example/a.css"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>', 'a.svg'),
    ('hrefを書き換えるanimate', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><a href="#x"><animate attributeName="href" values="javascript:alert(1)"/><rect width="5" height="5"/></a></svg>', 'a.svg'),
    ('setでイベント', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><set attributeName="onmouseover" to="alert(1)"/></svg>', 'a.svg'),
    ('HTML名前空間のiframe', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://evil.example/"/></svg>', 'a.svg'),
    ('data:text/htmlのfeImage', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><filter id="f"><feImage href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/></filter></svg>', 'a.svg'),
    ('PNGの名前のSVG', b'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>', 'photo.png'),
    ('SVGの名前のHTML', b'<html><body><script>alert(1)</script></body></html>', 'a.svg'),
    ('大きさのないSVG', b'<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5"/></svg>', 'a.svg'),
    ('圧縮SVGZ', b'\x1f\x8b\x08\x00' + b'\x00' * 20, 'a.svgz'),
]


def run(site, other_site, c, csrf, other, ot):
    pub = h.Client(args.port); opub = h.Client(args.other_port)
    json_head = {'Accept': 'application/json'}
    def post(data, files=None, client=c, token=csrf, headers=None): return client.post('/admin/index.php', {'csrf': token, **data}, files=files, headers=headers)
    def upload(data, name='banner.svg', ctype='image/svg+xml', client=c, token=csrf):
        return post({'do': 'log_upload'}, files={'image': (name, data, ctype)}, client=client, token=token)
    def settings(section, data):
        return post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': section, **data}, headers=json_head)
    def topmenu_rev(): return int(re.search(r'data-topmenu-manager[^>]*data-revision="(\d+)"', c.get('/admin/index.php?p=settings&section=log').text).group(1))
    def save_menu(items):
        return settings('log_topmenu', {'topmenu_items': json.dumps(items, ensure_ascii=False), 'topmenu_revision': str(topmenu_rev())})
    def page_save(**f):
        data = {'do': 'log_page_save', 'page_id': '', 'revision': '0', 'title': '', 'slug': '', 'body': '', 'layout': 'narrow', 'status': 'published'}
        data.update({k: str(v) for k, v in f.items()})
        return post(data, headers=json_head)

    # --- SVG ---
    r = upload(BANNER)
    check('安全なSVGを画像としてアップロードできる', r.status == 200)
    svg = json.loads(r.text)['media']
    check('SVGは.svgで保存し、大きさはwidth・heightから', svg['f'].endswith('.svg') and (svg['w'], svg['h']) == (200, 40))
    served = c.get('/admin/index.php?p=log_image&media=' + svg['id'])
    check('SVGはimage/svg+xmlで配信', served.status == 200 and served.getheader('Content-Type') == 'image/svg+xml')
    body = served.text
    check('書き出したSVGに描画はすべて残る', all(x in body for x in ('linearGradient', 'feDropShadow', 'clipPath', 'url(#sky)', 'xlink:href="#frame"', '<circle', 'NagiLog', '<style>')))
    check('編集ソフトのデータ・コメント・リンクは取り除く', 'metadata' not in body and 'inkscape' not in body and '<!--' not in body and '<a ' not in body and 'example.com' not in body)
    for path in args.svg:
        data = Path(path).read_bytes()
        r = upload(data, Path(path).name)
        ok = r.status == 200
        m = json.loads(r.text)['media'] if ok else {}
        check('指定のSVGが通る: ' + Path(path).name + ('' if ok else ' → ' + r.text[:200]), ok)
        if ok:
            out = c.get('/admin/index.php?p=log_image&media=' + m['id']).text
            tags = lambda t: sorted(re.findall(r'<([A-Za-z][\w:-]*)', t))
            check('指定のSVGの要素がすべて残る: ' + Path(path).name, tags(out) == tags(re.sub(rb'<\?xml[^>]*>', b'', data).decode('utf-8')))
    for name, data, fname in EVIL:
        r = upload(data, fname, 'image/svg+xml' if fname.endswith(('.svg', '.svgz')) else 'image/png')
        check('危険なSVGを拒否: ' + name, r.status == 422 and 'error' in json.loads(r.text))
    check('拒否の理由を表示（script）', 'script' in upload(EVIL[0][1]).text)

    # --- Standalone pictures: private until something uses them ---
    r = upload(h.png(40, 30), 'alone.png', 'image/png')
    alone = json.loads(r.text)['media']
    check('画像だけを追加しても、使うまで公開ページでは404', pub.get('/?media=' + alone['id']).status == 404 and c.get('/?media=' + alone['id']).status == 200)
    media_page = c.get('/admin/index.php?p=log_media').text
    check('画像一覧に「画像だけを追加」とURL・Markdownのコピー', 'data-media-upload' in media_page and 'data-copy-text="./?media=' + alone['id'] in media_page and '![' in media_page and 'image/svg+xml' in media_page and 'log-pages.js' in media_page)

    # --- Title logo and description ---
    r = post({'do': 'log_settings', 'title': 'ロゴのLOG', 'description': '紹介文の説明です', 'name': '監査者', 'theme': 'light-blue', 'description_form': '1'}, files={'logo': ('banner.svg', BANNER, 'image/svg+xml')})
    top = pub.get('/').text
    logo = re.search(r'<h1 class="log-site-title has-logo"><a href="\./"><img class="log-site-logo" src="\./\?media=([a-f0-9]{16})[^"]*" width="200" height="40" alt="ロゴのLOG"></a></h1>', top)
    check('ロゴはh1の中のリンク画像、altはサイト名', logo is not None)
    check('ロゴのSVGは公開され、CSPでサンドボックス化', logo is not None and pub.get('/?media=' + logo.group(1)).status == 200 and 'sandbox' in pub.get('/?media=' + logo.group(1)).getheader('Content-Security-Policy'))
    check('紹介文オフ: 画面に出さず、meta descriptionには残す', 'log-site-description' not in top and '<meta name="description" content="紹介文の説明です">' in top)
    check('一覧のh1は1つだけ（隠しh1は出さない）', top.count('<h1') == 1)
    post({'do': 'log_settings', 'title': 'ロゴのLOG', 'description': '紹介文の説明です', 'name': '監査者', 'theme': 'light-blue', 'description_form': '1', 'show_description': '1'})
    top = pub.get('/').text
    check('紹介文オン: サイト名の下に表示', '<p class="log-site-description">紹介文の説明です</p>' in top)
    pid = json.loads(post(base.payload(body='記事のタイトル\n本文です')).text)['id']
    single = pub.get('/?id=' + pid).text
    check('記事のページではサイト名はp、記事タイトルがh1', '<p class="log-site-title has-logo">' in single and single.count('<h1') == 1 and '<h1>記事のタイトル</h1>' in single)
    r = post({'do': 'log_media_delete', 'media': logo.group(1), 'revision': '1'})
    check('ロゴの画像は削除できない', c.get('/?media=' + logo.group(1)).status == 200)
    post({'do': 'log_settings', 'title': 'ロゴのLOG', 'description': '紹介文の説明です', 'name': '監査者', 'theme': 'light-blue'}, files={'og_image': ('og.svg', BANNER, 'image/svg+xml')})
    check('共通OGPにSVGは使えない', 'og:image" content="http' in pub.get('/').text and '/viewer/log-og.png' in pub.get('/').text)
    check('description_formなしの古いフォームは紹介文の表示を変えない', 'log-site-description' in pub.get('/').text)

    # --- Fixed pages ---
    md = ('## はじめに\n本文の**太字**と*斜体*、`code`。\n改行も残る\n\n### 集める情報\n- 項目A\n- 項目B\n  - 入れ子\n1. 一つ目\n2. 二つ目\n\n'
          '| 項目 | 内容 |\n| --- | :-: |\n| 名前 | 任意 |\n\n> 引用です\n\n---\n[問い合わせ](mailto:me@example.com) と [トップ](./) と https://example.com/a\n'
          '![絵](./?media=' + alone['id'] + '&format=image.webp)\n<script>alert(1)</script>\n[危険](javascript:alert(1))\n```\n<b>code</b>\n```')
    r = page_save(title='プライバシーポリシー', slug='privacy', body=md, layout='narrow')
    check('固定ページを保存（JSONでリダイレクト先）', r.status == 200 and 'p=log_page&id=pg' in json.loads(r.text)['redirect'])
    page = pub.get('/?pg=privacy')
    t = page.text
    check('固定ページを ./?pg=URL名 で公開', page.status == 200 and '<title>プライバシーポリシー｜ロゴのLOG</title>' in t and 'class="log-site log-layout-narrow"' in t)
    check('固定ページの見出しはh1、サイト名はp', '<h1>プライバシーポリシー</h1>' in t and '<p class="log-site-title' in t and t.count('<h1') == 1)
    check('Markdown: 見出し・強調・コード・改行', '<h2 id="はじめに">はじめに</h2>' in t and '<strong>太字</strong>' in t and '<em>斜体</em>' in t and '<code>code</code>' in t and 'code</code>。<br>改行も残る' in t and '<h3 id="集める情報">' in t)
    check('Markdown: 入れ子のリストと番号付きリスト', '<ul><li>項目A</li><li>項目B<ul><li>入れ子</li></ul></li></ul>' in t and '<ol><li>一つ目</li><li>二つ目</li></ol>' in t)
    check('Markdown: 表・引用・区切り線', '<div class="log-md-table"><table><thead><tr><th>項目</th><th class="is-center">内容</th>' in t and '<blockquote><p>引用です</p></blockquote>' in t and '<hr>' in t)
    check('Markdown: mailto・サイト内・URLのリンク', 'href="mailto:me@example.com"' in t and '<a href="./">トップ</a>' in t and '<a href="https://example.com/a" rel="noopener noreferrer">' in t)
    check('Markdown: HTMLは文字として表示し、javascript:はリンクにしない', '<script>alert' not in t and '&lt;script&gt;alert(1)&lt;/script&gt;' in t and 'javascript:' not in t.split('<main')[1].split('href=')[0] and 'href="javascript' not in t and '&lt;b&gt;code&lt;/b&gt;' in t)
    check('Markdownの画像はNagiSwipe用リンク付きで、使うと公開される', 'class="imagelink log-md-image"' in t and pub.get('/?media=' + alone['id']).status == 200)
    check('固定ページの説明文はMarkdownの記号なし', re.search(r'<meta name="description" content="はじめに 本文の太字と斜体、code。', t) is not None)
    check('固定ページのcanonicalとパンくず', '<link rel="canonical" href="http://127.0.0.1:' + str(args.port) + '/?pg=privacy">' in t and 'aria-label="パンくずリスト"' in t)
    check('存在しない固定ページは404', pub.get('/?pg=nothing').status == 404)
    r = page_save(title='別', slug='privacy', body='x')
    check('URL名の重複を拒否', r.status == 422 and 'URL名' in json.loads(r.text)['error'])
    r = page_save(title='別', slug='Bad Slug!', body='x')
    check('URL名の形式を確認', r.status == 422)
    r = page_save(title='利用規約', slug='terms', body='## 第1条\n規約', layout='wide', status='draft')
    terms = re.search(r'id=(pg[a-f0-9]{12})', json.loads(r.text)['redirect']).group(1)
    check('下書きの固定ページは読者に404、ログイン中は表示', pub.get('/?pg=terms').status == 404 and c.get('/?pg=terms').status == 200 and '下書き' in c.get('/?pg=terms').text)
    r = page_save(page_id=terms, revision='1', title='利用規約', slug='terms', body='## 第1条\n規約', layout='wide', status='published')
    check('幅広1カラムで公開', 'class="log-site log-layout-wide"' in pub.get('/?pg=terms').text)
    r = page_save(page_id=terms, revision='1', title='古い', slug='terms', body='x', layout='wide', status='published')
    check('古いrevisionの保存を拒否', r.status == 422 and '別の画面' in json.loads(r.text)['error'])
    sitemap = pub.get('/?sitemap=xml').text
    check('サイトマップに公開中の固定ページ', '/?pg=privacy</loc>' in sitemap and '/?pg=terms</loc>' in sitemap)
    lst = c.get('/admin/index.php?p=log_pages').text
    check('管理画面に固定ページの一覧とタブ', 'プライバシーポリシー' in lst and '利用規約' in lst and 'href="index.php?p=log_pages" aria-current="page"' in lst)
    edit = c.get('/admin/index.php?p=log_page&id=' + terms).text
    check('編集画面: 3種類の幅・Markdownの道具・プレビュー', all('value="' + k + '"' in edit for k in ('sidebar', 'wide', 'narrow')) and 'data-md="heading"' in edit and 'data-md-preview' in edit and 'data-md-upload' in edit)
    r = post({'do': 'log_page_preview', 'title': 'T', 'body': '## 見出し\n![絵](./?media=' + alone['id'] + ')'}, headers=json_head)
    check('プレビューは管理画面用の画像URLで描く', r.status == 200 and 'index.php?p=log_image&amp;media=' + alone['id'] in json.loads(r.text)['html'])
    r = post({'do': 'log_media_delete', 'media': alone['id'], 'revision': '1'})
    check('固定ページで使う画像は削除できない', c.get('/admin/index.php?p=log_image&media=' + alone['id']).status == 200)

    # --- Top menu ---
    settings_page = c.get('/admin/index.php?p=settings&section=log').text
    cands = json.loads(__import__('html').unescape(re.search(r'data-candidates="([^"]*)"', settings_page).group(1)))
    check('設定画面にトップメニューと、固定ページ・カテゴリの候補', 'data-topmenu-manager' in settings_page and {'t': 'プライバシーポリシー', 'u': './?pg=privacy', 'k': '固定ページ'} in cands)
    check('トップメニューが空なら公開ページに出さない', 'log-topmenu' not in pub.get('/').text)
    items = [{'id': 'tm-' + secrets.token_hex(6), 'label': 'ホーム', 'url': './', 'enabled': True},
             {'id': 'tm-' + secrets.token_hex(6), 'label': 'プライバシー', 'url': './?pg=privacy', 'enabled': True},
             {'id': 'tm-' + secrets.token_hex(6), 'label': '外部サイト', 'url': 'https://example.org/', 'enabled': True},
             {'id': 'tm-' + secrets.token_hex(6), 'label': '隠す項目', 'url': './?pg=terms', 'enabled': False}]
    r = save_menu(items)
    check('トップメニューを設定画面から保存', r.status == 200 and json.loads(r.text)['ok'])
    top = pub.get('/').text
    nav = re.search(r'<nav class="log-topmenu".*?</nav>', top, re.S)
    check('公開ページにトップメニュー（オフの項目は出さない）', nav is not None and 'ホーム' in nav.group(0) and '隠す項目' not in nav.group(0) and 'viewer/log-topmenu.js?v=' in top)
    check('トップメニューはヘッダーの直後', top.index('</header>') < top.index('<nav class="log-topmenu"') < top.index('log-site-grid'))
    check('今のページに aria-current', '<a href="./" aria-current="page">ホーム</a>' in nav.group(0) and 'aria-current' in re.search(r'<nav class="log-topmenu".*?</nav>', pub.get('/?pg=privacy').text, re.S).group(0).split('プライバシー')[0][-40:])
    check('外部リンクには rel="noopener noreferrer"', '<a href="https://example.org/" rel="noopener noreferrer">外部サイト</a>' in nav.group(0))
    bad = [dict(items[0], url='javascript:alert(1)')]
    r = save_menu(bad)
    check('javascript:のリンクを拒否し保存しない', r.status == 422 and 'ホーム' in pub.get('/').text and 'javascript' not in pub.get('/').text)
    r = settings('log_topmenu', {'topmenu_items': json.dumps(items), 'topmenu_revision': '0'})
    check('古いrevisionのトップメニューを拒否', r.status == 422)
    r = save_menu([dict(items[0], label='x' * 41)])
    check('表示名41文字を拒否', r.status == 422)
    check('トップメニューの表示順を保存', pub.get('/').text.index('>ホーム<') < pub.get('/').text.index('>プライバシー<') < pub.get('/').text.index('>外部サイト<'))

    # --- Share images (OGP) ---
    def pic(seed):
        return json.loads(upload(h.png(300, 200, seed), f'p{seed}.png', 'image/png').text)['media']
    p1, p2 = pic(1), pic(2)
    def og_of(body, **extra):
        pid = json.loads(post(base.payload(body=body, **extra)).text)['id']
        page = pub.get('/?id=' + pid).text
        return pid, __import__('html').unescape(re.search(r'og:image" content="([^"]+)"', page).group(1)), page
    pid, og, page = og_of(f'混在\n[Image:{p1["id"]}]\n[Image:{p2["id"]}]', media_ratings=json.dumps({p1['id']: 'sensitive'}))
    check('センシティブ画像があっても、注意なしの最初の画像をOGPにする', og.startswith('http://127.0.0.1:' + str(args.port) + '/?media=' + p2['id']) and '/./' not in og and '<meta property="og:image:width" content="300">' in page)
    pid, og, page = og_of(f'全部センシティブ\n[Image:{p1["id"]}]\n[Image:{p2["id"]}]', media_ratings=json.dumps({p1['id']: 'sensitive', p2['id']: 'sensitive'}))
    m = re.search(r'/\?ogimage=([^&]+)&v=([a-f0-9]{16})&format=image\.(webp|jpg)', og)
    check('注意つきの画像しかない記事は、ぼかしたOGP画像のURL（1200×630）', m is not None and m.group(1) == pid and '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">' in page)
    r = pub.get(og.split(str(args.port), 1)[1])
    check('ぼかしたOGP画像をWebPで作って配信', r.status == 200 and r.getheader('Content-Type') == 'image/webp' and r.body[:4] == b'RIFF' and r.body[8:12] == b'WEBP')
    made = list((site / 'data/log/og').glob(pid + '-*.webp'))
    r2 = pub.get(og.split(str(args.port), 1)[1])
    check('作った画像は保存して使い回す', len(made) == 1 and r2.body == r.body and 'max-age=2592000' in r2.getheader('Cache-Control'))
    check('元の画像はOGP画像の中にそのまま入らない（ぼかし）', p1['id'] not in r.text and len(r.body) < 200000)
    check('注意なしの画像がある記事のOGP画像URLは404', pub.get('/?ogimage=' + json.loads(post(base.payload(body=f'普通\n[Image:{pic(3)["id"]}]')).text)['id']).status == 404)
    _, og18, _ = og_of(f'R18\n[Image:{p2["id"]}]', rating='r18')
    check('R-18の記事もぼかしたOGP画像', 'ogimage=' in og18 and pub.get(og18.split(str(args.port), 1)[1]).status == 200)
    _, ogtext, _ = og_of('文字だけ\n本文', rating='r18')
    check('画像のない記事は共通のOGP画像', '/viewer/log-og.png?v=' in ogtext)
    post({'do': 'log_delete', 'post_id': pid, 'revision': '1'})
    check('記事を削除すると作ったOGP画像も消す', not list((site / 'data/log/og').glob(pid + '-*')))

    # --- Footer, loading sign, editors ---
    top = pub.get('/').text
    check('フッターに家のアイコンとHOME（標準）', re.search(r'<footer class="log-site-footer"><nav class="log-footer-nav" aria-label="フッターのリンク"><ul><li><a class="log-footer-home" href="\./" aria-current="page"><svg[^>]*>.*?</svg><span>HOME</span></a></li>', top) is not None)
    check('ページ移動の読み込み表示を読み込む', 'viewer/log-loading.js?v=' in top)
    sp = c.get('/admin/index.php?p=settings&section=log').text
    rev = int(re.search(r'data-settings-section="log_footmenu"[^>]*data-revision="(\d+)"', sp).group(1))
    fitems = [{'id': 'tm-' + secrets.token_hex(6), 'label': '利用規約', 'url': './?pg=terms', 'enabled': True}, {'id': 'tm-' + secrets.token_hex(6), 'label': 'プライバシー', 'url': './?pg=privacy', 'enabled': True}]
    r = settings('log_footmenu', {'footmenu_items': json.dumps(fitems, ensure_ascii=False), 'footmenu_revision': str(rev), 'footer_home_form': '1'})
    foot = re.search(r'<footer class="log-site-footer">.*?</footer>', pub.get('/?pg=privacy').text, re.S)
    check('フッターのリンクを保存（HOMEはオフにできる）', r.status == 200 and foot is not None and 'log-footer-home' not in foot.group(0) and '<a href="./?pg=terms">利用規約</a>' in foot.group(0) and '<a href="./?pg=privacy" aria-current="page">プライバシー</a>' in foot.group(0))
    r = settings('log_footmenu', {'footmenu_items': json.dumps(fitems, ensure_ascii=False), 'footmenu_revision': str(rev + 1), 'footer_home_form': '1', 'footer_home': '1'})
    check('HOMEを戻す', 'log-footer-home' in pub.get('/').text)
    edit = c.get('/admin/index.php?p=log_page&id=' + terms).text
    check('固定ページ編集: 画像一覧から選ぶモーダルと画像の列', 'data-md-pick' in edit and 'data-md-picker' in edit and 'data-md-images' in edit)
    check('設定画面にフッターのリンクの編集', 'data-settings-section="log_footmenu"' in sp and 'name="footer_home"' in sp and 'viewer/log-loading.js' in sp)

    # --- Backup and restore ---
    backup = post({'do': 'log_backup'}).body
    names = zipfile.ZipFile(io.BytesIO(backup)).namelist()
    check('バックアップに固定ページとSVG', any(n.startswith('pages/pg') for n in names) and any(n.endswith('.svg') for n in names))
    mark = json.loads(zipfile.ZipFile(io.BytesIO(backup)).read('backup.json'))
    check('バックアップにトップメニュー・ロゴ・紹介文の設定', len(mark['topmenu']['items']) == 4 and mark['settings']['logo'] == logo.group(1) and mark['settings']['show_description'] is True)
    check('バックアップにフッターのリンクとHOMEの設定', len(mark['footmenu']['items']) == 2 and mark['settings']['footer_home'] is True)
    r = post({'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('log.zip', backup, 'application/zip')}, client=other, token=ot)
    ot_top = opub.get('/').text
    check('別の設置へ復元: ロゴ・トップメニュー・固定ページ', 'class="log-site-title has-logo"' in ot_top and 'log-topmenu' in ot_top and opub.get('/?pg=privacy').status == 200 and opub.get('/?pg=terms').status == 200)
    check('復元先でもSVGは安全なまま配信', 'sandbox' in (opub.get('/?media=' + logo.group(1)).getheader('Content-Security-Policy') or ''))
    def tamper(edit):
        out = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(backup)) as src, zipfile.ZipFile(out, 'w') as dst:
            for n in src.namelist():
                data = src.read(n)
                data = edit(n, data)
                if data is not None: dst.writestr(n, data)
        return out.getvalue()
    svg_name = next(n for n in names if n.endswith('.svg') and '/t_' not in n)
    evil_zip = tamper(lambda n, d: EVIL[0][1] if n == svg_name else d)
    before = sorted(p.name for p in (other_site / 'data/log').rglob('*'))
    post({'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('evil.zip', evil_zip, 'application/zip')}, client=other, token=ot)
    check('scriptを仕込んだSVG入りのバックアップは復元しない', sorted(p.name for p in (other_site / 'data/log').rglob('*')) == before)
    bad_menu = tamper(lambda n, d: json.dumps(dict(json.loads(d), topmenu={'items': [dict(items[0], url='javascript:x')]})).encode() if n == 'backup.json' else d)
    r = post({'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('evil.zip', bad_menu, 'application/zip')}, client=other, token=ot)
    check('不正なトップメニューのバックアップは復元しない', 'javascript' not in opub.get('/').text)

    # --- Delete a page ---
    post({'do': 'log_page_delete', 'page_id': terms, 'revision': '2'})
    check('固定ページを削除すると404', pub.get('/?pg=terms').status == 404)


def main():
    for port in (args.port, args.other_port):
        with socket.socket() as sock: sock.bind((h.HOST, port))
    temp = ROOT / 'manga/dev/results' / ('pages-test-' + secrets.token_hex(4)); site = temp / 'site'; other_site = temp / 'other'
    for path in (site, other_site): shutil.copytree(ROOT / 'manga/server', path, ignore=shutil.ignore_patterns('paths.php', 'install.lock', 'sessions', 'asset-versions.php'))
    processes = []
    try:
        processes.extend((base.start(site, args.port), base.start(other_site, args.other_port)))
        c, csrf, _ = base.install(args.port); other, ot, _ = base.install(args.other_port)
        run(site, other_site, c, csrf, other, ot)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
