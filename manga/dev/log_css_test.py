#!/usr/bin/env python3
"""Additional CSS: saving, delivery, safety, backups, updates and the catalog check, on fresh private data.

    python manga/dev/log_css_test.py

Servers: :5440 with dev/router.php (like Apache with .htaccess, LiteSpeed headers forced on),
:5441 without it (a host that ignores .htaccess), and a stand-in for GitHub on :5443 for the self-update.
"""
import hashlib
import io
import json
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
import urllib.parse
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import attack_test as h  # noqa: E402
import log_test as base  # noqa: E402

PORT, RAW, MOCK = 5440, 5441, 5443
MAX = 262144
checks = []
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')


def check(name, ok, detail=''):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}" + (f'\n    {detail}' if detail and not ok else ''), flush=True)


def start(site, port, router=True):
    env = {k: v for k, v in os.environ.items() if k != 'NAGIMANGA_DATA'}
    env.update(NAGIMANGA_LSCACHE_FORCE='1', NAGIMANGA_UPDATE_API=f'http://{h.HOST}:{MOCK}/releases', NAGIMANGA_UPDATE_DL=f'http://{h.HOST}:{MOCK}/dl/', NAGIMANGA_UPDATE_KEY=h.release_sign.public_key(h.UPDATE_SEED).hex())
    process = subprocess.Popen(h.php_cmd(port, router, site), env=env, stdout=subprocess.DEVNULL, stderr=open(site.parent / f'php-{port}.txt', 'wb'))
    for _ in range(100):
        try:
            h.Client(port).get('/admin/index.php')
            return process
        except OSError:
            time.sleep(.05)
    raise RuntimeError('PHP server did not start')


def chash(css):
    return hashlib.sha256(css.encode('utf-8')).hexdigest()[:16] if css else ''


def run(site, c, csrf):
    data = site / 'data'
    store = data / 'log/custom-css'
    pub = h.Client(PORT)
    raw = h.Client(RAW)
    J = {'Accept': 'application/json'}

    def post(fields, client=c, token=csrf, headers=J):
        return client.post('/admin/index.php', {'csrf': token, **fields}, headers=headers)

    def save(css, revision=None, **kw):
        r = post({'do': 'log_css_save', 'css': css, 'revision': str(state()['revision'] if revision is None else revision)}, **kw)
        return r, (json.loads(r.text) if r.getheader('Content-Type', '').startswith('application/json') else {})

    def state():
        p = store / 'state.php'
        if not p.exists():
            return {'revision': 0, 'enabled': True, 'hash': '', 'previous': None}
        text = p.read_text(encoding='utf-8')
        get = lambda k: re.search(r"'" + k + r"' => (.*?),\n", text).group(1)
        prev = get('previous')
        return {'revision': int(get('revision')), 'enabled': get('enabled') == 'true', 'hash': get('hash').strip("'"), 'previous': None if prev == 'NULL' else prev.strip("'")}

    def files():
        return {p.name: p.read_bytes() for p in store.glob('*')} if store.exists() else {}

    def link(html):
        m = re.search(r'<link rel="stylesheet" href="\./\?css=([a-f0-9]{16})" data-nl-custom-css>', html)
        return m.group(1) if m else None

    # --- The pages ----------------------------------------------------------------------------
    settings = c.get('/admin/index.php?p=settings&section=log').text
    check('設定のLOGタブに「追加CSSを編集」のリンク（同じタブで開く）', 'href="index.php?p=css"' in settings and 'target=' not in re.search(r'<a class="btn" href="index.php\?p=css"[^>]*>', settings).group(0))
    check('設定画面には編集・検証のスクリプトを読み込まない', 'css-editor' not in settings)
    editor = c.get('/admin/index.php?p=css')
    check('専用ページに編集用のJS・CSSを版付きで読み込む', editor.status == 200 and re.search(r'css-editor\.js\?v=[a-f0-9]{12}', editor.text) and re.search(r'css-editor\.css\?v=[a-f0-9]{12}', editor.text))
    check('専用ページにファイル選択・ドロップ・保存・ダウンロード・復元・オンオフ', all(x in editor.text for x in ('data-css-file', 'data-css-drop', 'data-css-save', 'data-css-download', 'data-css-restore', 'data-css-enabled-form', 'accept=".css,text/css"')))
    check('専用ページのCSPは管理画面と同じ（緩めない）', editor.getheader('Content-Security-Policy') == c.get('/admin/index.php?p=log').getheader('Content-Security-Policy') and "'unsafe" not in editor.getheader('Content-Security-Policy'))
    top = pub.get('/').text
    check('公開ページは編集・検証のファイルを読み込まない', 'css-editor' not in top and 'css-catalog' not in top)
    check('保存前は公開ページに追加CSSのリンクがない', link(top) is None)

    # --- Save, deliver, apply only to public pages ------------------------------------------------
    css1 = '/* 日本語のコメント：見出しの色 */\n.log-site-title { outline: 3px solid rgb(1, 2, 3); }\n.log-profile-name::after { content: "☆"; }\n'
    r, body = save(css1)
    check('保存すると revision が増え、内容のハッシュを返す', r.status == 200 and body.get('ok') and body['revision'] == 1 and body['hash'] == chash(css1), r.text[:300])
    check('purgeヘッダー（LiteSpeed Cacheの無効化）を送る', 'tag=nagilog' in (r.getheader('X-LiteSpeed-Purge') or ''))
    check('保存先は data/log/custom-css のハッシュ名（アップロード名を使わない）', (store / f'{chash(css1)}.css').read_text(encoding='utf-8') == css1 and state()['hash'] == chash(css1))
    page = pub.get('/')
    html = page.text
    check('公開ページにハッシュ付きの<link>', link(html) == chash(css1))
    order = [html.find('viewer/log.css'), html.find('data-nl-custom-css')]
    check('本体・テーマのCSS（log.css）のあとに読み込む', 0 < order[0] < order[1])
    check('公開ページのCSPはそのまま（style-src self）', "style-src 'self' 'unsafe-inline'" in page.getheader('Content-Security-Policy') and "font-src" not in page.getheader('Content-Security-Policy'))
    served = pub.get(f'/?css={chash(css1)}')
    check('./?css= で text/css として配信（nosniff）', served.status == 200 and served.getheader('Content-Type') == 'text/css; charset=UTF-8' and served.getheader('X-Content-Type-Options') == 'nosniff')
    check('配信内容は保存したUTF-8そのまま（日本語コメント）', served.body == css1.encode('utf-8'))
    check('ハッシュ付きURLは長くキャッシュ（immutable）・LiteSpeedではページとして保存しない', 'immutable' in served.getheader('Cache-Control') and served.getheader('X-LiteSpeed-Cache-Control') == 'no-cache')
    old = pub.get('/?css=0123456789abcdef')
    check('古い・違うハッシュでは今のCSSを短いキャッシュで返す', old.status == 200 and old.getheader('Cache-Control') == 'no-cache' and old.body == css1.encode('utf-8'))
    for path in ('/?css=../../config', '/?css=..%2F..%2Fdata%2Fconfig.php', '/?css=state'):
        r = pub.get(path)
        check(f'パス風の値でも保存先以外を読まない（{path}）', r.status == 200 and r.body == css1.encode('utf-8') and b'admin_hash' not in r.body)
    check('検索結果のページにも適用', link(pub.get('/?q=x').text) == chash(css1))
    for path in ('/admin/index.php?p=log', '/admin/index.php?p=settings', '/admin/index.php?p=css', '/admin/index.php'):
        check(f'管理画面には適用しない（{path}）', '?css=' not in c.get(path).text and 'data-nl-custom-css' not in c.get(path).text)
    check('data/ の中のCSSは外から見えない（.htaccessが効く場合）', pub.get(f'/data/log/custom-css/{chash(css1)}.css').status in (403, 404))
    st = raw.get('/data/log/custom-css/state.php')
    check('.htaccessが効かないサーバーでも状態ファイルは中身を出さない', st.status == 404 and 'revision' not in st.text)
    raw_css = raw.get(f'/data/log/custom-css/{chash(css1)}.css')
    check('保存したCSSはPHPとして実行されない（.css のまま）', raw_css.status != 200 or raw_css.body == css1.encode('utf-8'))
    r, body = save('/* <?php echo "PWNED"; ?> */\n.a{b:c}')
    served = pub.get(f'/?css={body.get("hash", "")}')
    check('<?php を含むCSSも文字として配信（実行しない）', r.status == 200 and served.body.startswith(b'/* <?php echo') and b'PWNED"; ?>' in served.body)
    save(css1)

    # --- Diagnostics on the server ----------------------------------------------------------------
    before = (state(), files())
    r, body = save('.a { color: red;\n.b { c: d }\n')
    d = body.get('diagnostics') or [{}]
    check('構文エラーは422で行・列・理由を返す', r.status == 422 and d[0].get('line') == 1 and d[0].get('column') == 4 and '閉じていません' in d[0].get('message', ''), r.text[:300])
    check('構文エラーでは保存せず、公開中のCSSを残す', (state(), files()) == before and link(pub.get('/').text) == chash(css1))
    for name, css in [('閉じていない文字列', '.a{content:"x\n}'), ('閉じていないコメント', '.a{} /* x'), ('「:」のない宣言', '.a{color red}'), ('外の宣言', 'color:red;'), ('余分な }', '.a{}}'), ('セレクターなし', '{a:b}'), ('url の空白', '.a{background:url(a b.png)}')]:
        r, body = save(css)
        check(f'構文エラーを拒否：{name}', r.status == 422 and body.get('diagnostics') and state() == before[0])
    warnings_only = ':root { --brand: { a: b }; }\n.a { colr: red; -webkit-line-clamp: 2; color: color-mix(in oklch, red, blue); }\n@container card (min-width: 400px) { .b:has(> img) { color: red } }\n@starting-style { .c { opacity: 0 } }\n.d { &:hover { color: blue } > .e { color: red } }\n@layer base;\n'
    r, body = save(warnings_only)
    check('未知のプロパティ・最新CSS・CSS変数・接頭辞・入れ子は保存できる（警告は画面だけ）', r.status == 200 and body.get('ok'), r.text[:300])

    # --- Size, empty, encoding --------------------------------------------------------------------
    exact = '/*' + 'a' * (MAX - 4) + '*/'
    r, body = save(exact)
    check('ちょうど256KiBは保存できる', r.status == 200 and body.get('size') == MAX)
    r, body = save('/*' + 'a' * (MAX - 3) + '*/')
    check('256KiBを1バイト超えると拒否', r.status == 422 and '256KiB' in body.get('error', ''))
    r, body = save('/*' + 'あ' * (MAX // 3) + '*/')
    check('上限はUTF-8のバイト数で数える（日本語）', r.status == 422)
    r, body = save('﻿.a {\r\n  color: red;\r\n}\r\n')
    check('BOMを外し、改行をLFにそろえる', r.status == 200 and (store / f'{body["hash"]}.css').read_bytes() == b'.a {\n  color: red;\n}\n')
    r = c.post('/admin/index.php', urllib.parse.urlencode({'csrf': csrf, 'do': 'log_css_save', 'revision': str(state()['revision']), 'css': b'.a{content:"\xff\xfe"}'}).encode(), headers={**J, 'Content-Type': 'application/x-www-form-urlencoded'})
    check('UTF-8でない内容は拒否', r.status == 422 and 'UTF-8' in json.loads(r.text)['error'])
    r, body = save('.a{b:c}\x07')
    check('制御文字は拒否', r.status == 422)
    r, body = save('')
    check('空のCSSも保存でき、公開ページのリンクと配信がなくなる', r.status == 200 and body['hash'] == '' and link(pub.get('/').text) is None and pub.get('/?css=0123456789abcdef').status == 404)
    r, body = save(css1)
    check('空から戻すと再び適用', link(pub.get('/').text) == chash(css1))

    # --- Auth, guests, CSRF -------------------------------------------------------------------------
    snap = (state(), files())
    anon = h.Client(PORT)
    r = anon.post('/admin/index.php', {'do': 'log_css_save', 'css': '.x{a:b}', 'revision': '0'})
    check('未ログインの保存は受け付けない', (state(), files()) == snap and '"ok"' not in r.text)
    check('未ログインでは編集ページを出さない', 'data-css-editor' not in anon.get('/admin/index.php?p=css').text)
    check('未ログインではダウンロードできない', css1 not in anon.get('/admin/index.php?p=css&download=1').text)
    r = post({'do': 'log_css_save', 'css': '.x{a:b}', 'revision': str(state()['revision'])}, token='0' * 64)
    check('CSRFトークンが違うと404で何もしない', h.is_404(r) and (state(), files()) == snap)
    r = c.post('/admin/index.php', {'do': 'log_css_save', 'css': '.x{a:b}', 'revision': str(state()['revision'])}, headers=J)
    check('CSRFトークンなしは404', h.is_404(r) and (state(), files()) == snap)
    plugin = site / 'plugins/guest-mode.php'
    plugin.write_text((HERE.parent / 'plugins/guest-mode.php').read_text(encoding='utf-8'), encoding='utf-8')
    g = h.Client(PORT)
    g.get('/admin/index.php?guest')
    gpage = g.get('/admin/index.php?p=css')
    gcsrf = h.csrf_of(g.get('/admin/index.php').text)
    check('ゲストには編集ページを出さない', gpage.status == 200 and 'data-css-editor' not in gpage.text)
    for do in ('log_css_save', 'log_css_enabled', 'log_css_restore'):
        g.post('/admin/index.php', {'csrf': gcsrf, 'do': do, 'css': '.x{a:b}', 'revision': str(state()['revision']), 'enabled': '0'}, headers=J)
    check('ゲストの保存・オンオフ・復元は拒否', (state(), files()) == snap)
    check('ゲストはダウンロードできない', css1 not in g.get('/admin/index.php?p=css&download=1').text)
    plugin.unlink()

    # --- Conflicts, failures, restore, on/off, download -------------------------------------------
    rev = state()['revision']
    r1, b1 = save('.first{a:b}', rev)
    r2, b2 = save('.second{a:b}', rev)
    check('同時編集：古いrevisionの保存は409で、先の保存を残す', r1.status == 200 and r2.status == 409 and b2.get('conflict') and b2.get('revision') == rev + 1 and state()['hash'] == chash('.first{a:b}'))
    r3, b3 = save('.second{a:b}', b2['revision'])
    check('同時編集：最新のrevisionを指定すれば上書きできる', r3.status == 200 and state()['hash'] == chash('.second{a:b}'))
    snap = (state(), files())
    broken = '.fail{a:b}'
    (store / f'{chash(broken)}.css').mkdir()
    r, body = save(broken)
    check('書き込みに失敗しても直前の有効なCSSを失わない', r.status == 500 and 'error' in body and state() == snap[0] and pub.get(f'/?css={snap[0]["hash"]}').body == b'.second{a:b}')
    (store / f'{chash(broken)}.css').rmdir()
    save('.one{a:b}'); save('.two{a:b}')
    r = post({'do': 'log_css_restore', 'revision': str(state()['revision'])})
    body = json.loads(r.text)
    check('直前の保存に戻せる（今の内容が直前の保存になる）', r.status == 200 and body['css'] == '.one{a:b}' and state()['hash'] == chash('.one{a:b}') and state()['previous'] == chash('.two{a:b}'))
    r = post({'do': 'log_css_restore', 'revision': str(state()['revision'])})
    check('もう一度戻すと入れ替わる', json.loads(r.text)['css'] == '.two{a:b}')
    r = post({'do': 'log_css_restore', 'revision': '0'})
    check('復元も古いrevisionなら409', r.status == 409)
    check('保存するのは使用中と直前の2つだけ', sorted(p.name for p in store.glob('*.css')) == sorted([f'{chash(".one{a:b}")}.css', f'{chash(".two{a:b}")}.css']))
    r = post({'do': 'log_css_enabled', 'enabled': '0'})
    check('適用を止めると公開ページから外れ、配信も404', r.status == 200 and json.loads(r.text)['enabled'] is False and link(pub.get('/').text) is None and pub.get(f'/?css={state()["hash"]}').status == 404 and 'tag=nagilog' in (r.getheader('X-LiteSpeed-Purge') or ''))
    check('止めても保存内容とrevisionはそのまま', state()['hash'] == chash('.two{a:b}') and (store / f'{chash(".two{a:b}")}.css').exists())
    post({'do': 'log_css_enabled', 'enabled': '1'})
    check('もう一度適用できる', link(pub.get('/').text) == chash('.two{a:b}'))

    # --- Per list layout (mini blog / tiles) -----------------------------------------------------
    page = c.get('/admin/index.php?p=css').text
    check('一覧の形ごとの適用欄（初めは両方オン・今の設定に印）', 'data-css-layouts-form data-current="stream"' in page and page.count('name="layouts[]"') == 2 and len(re.findall(r'value="(?:stream|grid)" checked', page)) == 2)

    def display(layout):
        return post({'do': 'log_display_settings', 'layout': layout, 'related_by': 'both', 'related_order': 'random'}, headers={})
    r = post({'do': 'log_css_layouts', 'layouts[]': 'grid'})
    body = json.loads(r.text)
    check('ミニブログだけ外すと、ミニブログの公開ページから外れ配信も404', r.status == 200 and body['layouts'] == {'stream': False, 'grid': True} and body['active'] is False
          and link(pub.get('/').text) is None and pub.get(f'/?css={state()["hash"]}').status == 404 and 'tag=nagilog' in (r.getheader('X-LiteSpeed-Purge') or ''))
    check('外しても保存内容・オンオフ・revisionはそのまま', state()['hash'] == chash('.two{a:b}') and state()['enabled'] is True)
    check('編集ページに「今の一覧の形では読み込みません」', '今の一覧の形では読み込みません' in c.get('/admin/index.php?p=css').text)
    display('grid')
    check('一覧の形をタイルにすると読み込む', link(pub.get('/').text) == chash('.two{a:b}'))
    post({'do': 'log_css_layouts'})
    check('両方外すとタイルでも読み込まない', link(pub.get('/').text) is None)
    display('stream')
    r = post({'do': 'log_css_layouts', 'layouts[]': 'stream'})
    check('ミニブログに戻して付け直すと読み込む', json.loads(r.text)['active'] is True and link(pub.get('/').text) == chash('.two{a:b}'))
    r = post({'do': 'log_css_layouts', 'layouts[]': '../x'})
    check('知らない形の値は無視（両方オフになるだけ）', r.status == 200 and json.loads(r.text)['layouts'] == {'stream': False, 'grid': False})
    save('.layout-kept{a:b}')
    check('CSSを保存しても一覧の形ごとの設定は変わらない', '\'stream\' => false' in (store / 'state.php').read_text(encoding='utf-8'))
    post({'do': 'log_css_layouts', 'layouts[]': 'stream'})
    save('.two{a:b}')
    check('戻した後のCSSを配信', link(pub.get('/').text) == chash('.two{a:b}'))
    dl = c.get('/admin/index.php?p=css&download=1')
    check('保存したCSSをダウンロード（text/css・添付）', dl.status == 200 and dl.getheader('Content-Type') == 'text/css; charset=UTF-8' and 'attachment' in dl.getheader('Content-Disposition') and dl.body == b'.two{a:b}')
    r = post({'do': 'log_css_save', 'css': '.a{b c}', 'revision': str(state()['revision'])}, headers={})
    page = c.get('/admin/index.php?p=css').text
    check('スクリプトなしの保存失敗：内容を消さずに再表示し、行と列を出す', r.status == 303 and '.a{b c}' in page and 'data-draft' in page and '1行4列' in page)
    check('再表示した内容は一度だけ', 'data-draft' not in c.get('/admin/index.php?p=css').text)
    r = post({'do': 'log_css_save', 'css': '.nojs{a:b}', 'revision': str(state()['revision'])}, headers={})
    check('スクリプトなしでも保存できる', r.status == 303 and state()['hash'] == chash('.nojs{a:b}'))

    # --- Cache keys --------------------------------------------------------------------------------
    h1 = link(pub.get('/').text)
    save('.cache{a:b}')
    h2 = link(pub.get('/').text)
    check('内容を変えるとURLのハッシュも変わる（ブラウザーのキャッシュに残らない）', h1 != h2 and h2 == chash('.cache{a:b}'))
    p2 = pub.get('/')
    check('公開ページはLiteSpeedでキャッシュ対象のまま（タグ付き）', p2.getheader('X-LiteSpeed-Tag') == 'nagilog')

    # --- Backup and restore ------------------------------------------------------------------------
    save('/* バックアップ */\n.backup{a:b}\n')
    post({'do': 'log_css_enabled', 'enabled': '0'})
    zipped = c.post('/admin/index.php', {'csrf': csrf, 'do': 'log_backup'}).body
    with zipfile.ZipFile(io.BytesIO(zipped)) as z:
        mark = json.loads(z.read('backup.json'))
    check('LOGのバックアップに追加CSSと適用の設定が入る', mark.get('custom_css') == {'enabled': False, 'css': '/* バックアップ */\n.backup{a:b}\n', 'layouts': {'stream': True, 'grid': False}})

    def edited(fn):
        out = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(zipped)) as src, zipfile.ZipFile(out, 'w') as dst:
            for n in src.namelist():
                b = src.read(n)
                if n == 'backup.json':
                    m = json.loads(b); fn(m); b = json.dumps(m, ensure_ascii=False).encode()
                dst.writestr(n, b)
        return out.getvalue()

    def restore(blob, overwrite):
        return c.post('/admin/index.php', {'csrf': csrf, 'do': 'log_restore', **({'overwrite': '1'} if overwrite else {})}, files={'backup': ('b.zip', blob, 'application/zip')})

    post({'do': 'log_css_enabled', 'enabled': '1'})
    save('.after{a:b}')
    restore(zipped, False)
    check('上書きしない復元では、今の追加CSSを残す', state()['hash'] == chash('.after{a:b}') and state()['enabled'] is True)
    restore(zipped, True)
    check('上書きの復元で、追加CSSと適用の設定が戻る', state()['hash'] == chash('/* バックアップ */\n.backup{a:b}\n') and state()['enabled'] is False)
    post({'do': 'log_css_enabled', 'enabled': '1'})
    save('.kept{a:b}')
    snap = state()
    restore(edited(lambda m: m.pop('custom_css')), True)
    check('追加CSSのない旧バックアップは、上書きでも今の追加CSSを変えない', state() == snap)
    r = restore(edited(lambda m: m.__setitem__('custom_css', {'enabled': True, 'css': '.a{'})), True)
    check('壊れた追加CSSを含むバックアップは復元しない', state() == snap and '追加CSSの設定が壊れています' in c.get('/admin/index.php?p=log').text)
    r = restore(edited(lambda m: m.__setitem__('custom_css', {'enabled': 'yes', 'css': ''})), True)
    check('形式の違う追加CSSを含むバックアップは復元しない', state() == snap)

    # --- Updates: automatic, undo, upload over the files -----------------------------------------
    h.MOCK_PORT = MOCK
    srv = h.start_mock()
    try:
        before = files()
        h.publish('9.9.9', h.make_package('9.9.9', marker='/* css-update-test */'))
        post({'do': 'update_check'}, headers={})
        post({'do': 'update_apply'}, headers={})
        upd = c.get('/admin/index.php?p=update').text
        check('自動更新が成功する（テストの前提）', 'NagiManga 9.9.9' in upd, ' / '.join(re.findall(r'class="flash[^"]*">([^<]*)', upd)) or re.sub(r'<[^>]+>', ' ', upd)[-600:])
        check('自動更新のあとも追加CSSは1バイトも変わらない', files() == before and link(pub.get('/').text) == chash('.kept{a:b}'))
        m = re.search(r'name="backup" value="([^"]+)"', upd)
        post({'do': 'update_rollback', 'backup': m.group(1) if m else ''}, headers={})
        check('更新の取り消しのあとも追加CSSは変わらない', bool(m) and files() == before and pub.get(f'/?css={chash(".kept{a:b}")}').body == b'.kept{a:b}')
    finally:
        srv.shutdown()
    pristine = HERE.parent / 'server'
    for f in pristine.rglob('*'):
        rel = f.relative_to(pristine)
        if f.is_file() and rel.parts[0] not in ('data', 'plugins') and rel.as_posix() != 'lib/paths.php':
            (site / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(f, site / rel)
    check('FTPで本体を上書きしても追加CSSは変わらない', files() == before and link(pub.get('/').text) == chash('.kept{a:b}'))
    pkg = h.make_package('9.9.9')
    with zipfile.ZipFile(io.BytesIO(pkg)) as z:
        names = z.namelist()
    check('更新ファイルに利用者のCSSや保存先を上書きするファイルがない', not [n for n in names if 'custom-css' in n or n.startswith('nagimanga/data/log')])

    # --- Release ZIP (built from a copy whose catalog carries a version number) --------------------
    tmp = site.parent / 'release'
    shutil.copytree(HERE.parent, tmp / 'manga', ignore=shutil.ignore_patterns('results', 'data', '__pycache__'))
    for name in ('NagiSwipe-main.js', 'NagiSwipe-main.css'):
        shutil.copyfile(HERE.parents[1] / name, tmp / name)
    cat_path = tmp / 'manga/server/lib/css-catalog.json'
    version = re.search(r"const NM_VERSION = '([0-9.]+)'", (tmp / 'manga/server/lib/bootstrap.php').read_text(encoding='utf-8')).group(1)
    released = cat_path.read_text(encoding='utf-8').replace('"next"', f'"{version}"')
    # Before a release the coming version is "next": the ZIP is refused.
    # A patch release may add no names, so mark the newest version the list has (this one when it is there).
    mark = version if f'"{version}"' in released else json.loads(released)['history'][-1]['version']
    cat_path.write_text(released.replace(f'"{mark}"', '"next"'), encoding='utf-8')
    r = subprocess.run([sys.executable, str(tmp / 'manga/dev/build_release.py')], capture_output=True, text=True, encoding='utf-8', cwd=tmp)
    check('一覧に "next" が残っている間は配布ZIPを作らない', r.returncode != 0 and 'next' in r.stdout, r.stdout[-300:])
    # As at a release: the version number goes in and the README copy of the list is written again.
    cat_path.write_text(released, encoding='utf-8')
    subprocess.run([sys.executable, str(tmp / 'manga/dev/css_catalog_check.py'), '--write-docs'], capture_output=True, cwd=tmp)
    (tmp / 'manga/server/data/log/custom-css').mkdir(parents=True, exist_ok=True)
    (tmp / 'manga/server/data/log/custom-css/0123456789abcdef.css').write_text('.mine{a:b}', encoding='utf-8')
    r = subprocess.run([sys.executable, str(tmp / 'manga/dev/build_release.py')], capture_output=True, text=True, encoding='utf-8', cwd=tmp)
    out = tmp / f'manga/dev/results/nagimanga-v{version}.zip'
    names = zipfile.ZipFile(out).namelist() if out.exists() else []
    check('配布ZIPを作れて、編集画面のファイルと一覧を含む', r.returncode == 0 and all(f'nagimanga/{n}' in names for n in ('admin/css-editor.js', 'admin/css-editor.css', 'lib/log-css.php', 'lib/css-catalog.json')), r.stdout[-300:] + r.stderr[-300:])
    check('配布ZIPに利用者のCSS・保存先が入らない', not [n for n in names if 'custom-css' in n or n.startswith('nagimanga/data/log')])


def catalog_checks(tmp):
    script = HERE / 'css_catalog_check.py'
    cat = json.loads((HERE.parent / 'server/lib/css-catalog.json').read_text(encoding='utf-8'))

    def run_check(catalog, base_cat=None, *extra):
        p = tmp / ('cat-' + secrets.token_hex(3) + '.json')
        p.write_text(json.dumps(catalog, ensure_ascii=False), encoding='utf-8')
        args = [sys.executable, str(script), '--catalog', str(p)]
        if base_cat is not None:
            b = tmp / ('base-' + secrets.token_hex(3) + '.json')
            b.write_text(json.dumps(base_cat, ensure_ascii=False), encoding='utf-8')
            args += ['--base-file', str(b)]
        r = subprocess.run(args + list(extra), capture_output=True, text=True, encoding='utf-8')
        return r.returncode, r.stdout

    code, out = run_check(cat, cat)
    check('一覧のチェック：今の一覧は問題なし', code == 0, out[-400:])
    c2 = json.loads(json.dumps(cat)); c2['internal'].remove('log-veil')
    code, out = run_check(c2)
    check('一覧のチェック：CSSにあるクラスが一覧にないと失敗', code == 1 and 'log-veil' in out)
    c3 = json.loads(json.dumps(cat)); c3['internal'].append('log-no-such-thing')
    code, out = run_check(c3)
    check('一覧のチェック：もうないクラスが一覧に残ると失敗', code == 1 and 'log-no-such-thing' in out)
    # A base version with one more public selector; the new catalog adds a later version (9.9.9) after the real history.
    old = json.loads(json.dumps(cat))
    old['selectors'].append({'name': '.log-old-box', 'purpose': {'ja': '古い枠', 'en': 'Old box'}, 'pages': ['all'], 'example': '.log-old-box{}', 'since': 'initial'})
    new = json.loads(json.dumps(cat))
    # Names waiting for the next release (since "next") count as added in 9.9.9 here.
    coming = [n for h in cat['history'] if h['version'] == 'next' for n in h.get('added', [])]
    later = {'version': '9.9.9', **({'added': coming} if coming else {})}
    new['history'] = [h for h in cat['history'] if h['version'] != 'next'] + [later]
    for e in new['selectors']:
        if e['since'] == 'next': e['since'] = '9.9.9'
    code, out = run_check(new, old)
    check('一覧のチェック：公開セレクターが履歴なしで消えると失敗', code == 1 and '.log-old-box' in out)
    new['history'][-1] = {**later, 'removed': ['.log-old-box']}
    code, out = run_check(new, old)
    check('一覧のチェック：removed を書けば通る', code == 0, out[-400:])
    new['history'][-1] = {**later, 'renamed': [{'from': '.log-old-box', 'to': '.log-widget'}]}
    code, out = run_check(new, old)
    check('一覧のチェック：renamed でも通る', code == 0, out[-400:])
    new2 = json.loads(json.dumps(new)); new2['history'][0]['removed'] = ['.log-old-box']; new2['history'][-1] = {'version': '9.9.9'}
    code, out = run_check(new2, old)
    check('一覧のチェック：比較元にある古い版へ書いた履歴では通らない', code == 1)
    c4 = json.loads(json.dumps(cat)); c4['history'].append({'version': '9.9.9'}); c4['selectors'][0]['since'] = '9.9.9'
    code, out = run_check(c4)
    check('一覧のチェック：追加版を変えて履歴の added がないと失敗', code == 1 and 'added' in out)
    c5 = json.loads(json.dumps(cat)); c5['history'].append({'version': 'next', 'added': ['.log-site']}); c5['selectors'][1]['since'] = 'next'
    code, out = run_check(c5, None, '--release')
    check('一覧のチェック：--release では "next" を拒否', code == 1 and 'next' in out)


def main():
    h.RESULTS.mkdir(exist_ok=True)
    root = h.RESULTS / ('css-test-' + secrets.token_hex(4))
    site = root / 'site'
    h.copy_server(site, HERE.parent / 'server')
    processes = [start(site, PORT), start(site, RAW, router=False)]
    try:
        c, csrf, _ = base.install(PORT)
        run(site, c, csrf)
    finally:
        for p in processes:
            p.terminate(); p.wait(timeout=10)
    catalog_checks(root)
    passed = sum(ok for _, ok in checks)
    print(f'\n{passed} / {len(checks)} passed')
    (root / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__':
    main()
