#!/usr/bin/env python3
"""Additional CSS editor in real browsers (Playwright: Chromium and WebKit, desktop and phone sizes).

    python manga/dev/log_css_browser_test.py

Checks the editor's own syntax check against the server's, typing, diagnostics, file choice, drop, size and
encoding limits, saving and reloading, the public page, the network (no editor files on other pages), the
catalog's version comparison and a phone-width layout. WebKit here is Playwright's WebKit on this PC: it is not
Safari on an iPhone, which has to be checked by hand.
"""
import json
import os
import re
import secrets
import subprocess
import sys
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import attack_test as h  # noqa: E402
import log_test as base  # noqa: E402

PORT = 5450
URL = f'http://{h.HOST}:{PORT}'
checks = []
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# The same texts go through admin/css-editor.js and lib/log-css.php; both must find the same errors.
CORPUS = [
    '', '.a{color:red}', '.a { color: red;\n.b { c: d }', '.a{}\n}', '.a { color red; }', 'color: red;', '.a{content:"abc\n}', ".a{content:'x}", '.a{} /* x',
    '.a{width:calc(1px + 2px;}', '{color:red}', '.a{background:url(a b.png)}', '.a{background:url( x.png )}', '.a{} .b', '@keyframes k { from { opacity: 0 } 50% { opacity:.5 } to { opacity: 1 } }',
    '.a{-webkit-line-clamp:2;display:-webkit-box}', '/* あいう */ .a{b c}', ':root{--x:{a:b}}', '.a{&:hover{color:blue}}', '@import url(x.css);\n.b{c:d}', '@media (max-width: 600px) { .a { b: c } }',
    '.a{:red}', '.a{b:c', '.a)', '.a{b:c)}', '.a[x{b:c}', '😀 .a{b c}', '.a{b:c}}}', '@font-face { font-family: X; src: url("x.woff2") }', '.a{b:"x\\\ny"}', '.a{b:u\\rl(x)}',
    '.a{b:url(x\\ y.png)}', '\\.a{b:c}', '.a{b:c} /**/ @charset "x";', '.a{b:1e3px;c:-.5em;d:+2}', '.a { color: red !important }', '.a,,.b{c:d}', '#id{b:c}', '.a{b:c;;}', ';;', '.a{;b:c}',
    '.a{b}', '.a{b:}', 'a:hover;', '@media screen{color:red}', '.a{--x:1;--y:{}}', '.a{b:c} @x', '<!-- .a{b:c} -->', '.a{\n  color: red;\n  @media (x) { color: blue }\n}',
]


def check(name, ok, detail=''):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}" + (f'\n    {detail}' if detail and not ok else ''), flush=True)


def php_errors(site):
    code = ("define('NAGIMANGA',true); require 'lib/bootstrap.php'; require 'lib/log.php'; $out=[]; foreach (json_decode(stream_get_contents(STDIN), true) as $css) "
            "$out[] = array_map(static fn($e) => [$e['line'], $e['column'], $e['message']], nl_css_errors($css)); echo json_encode($out, JSON_UNESCAPED_UNICODE);")
    cmd = h.php_cmd(0, False, site)
    cmd = cmd[:cmd.index('-S')] + ['-r', code]
    r = subprocess.run(cmd, input=json.dumps(CORPUS), capture_output=True, text=True, encoding='utf-8', cwd=site, check=True)
    return json.loads(r.stdout)


def catalog_with_versions(site):
    """Several versions in the history, for the comparison display."""
    p = site / 'lib/css-catalog.json'
    cat = json.loads(p.read_text(encoding='utf-8'))
    cat['history'] = [{'version': 'initial'},
                      {'version': '0.7.0', 'added': ['.log-test-seven']},
                      {'version': '0.8.0', 'removed': ['.log-test-gone'], 'renamed': [{'from': '.log-test-old', 'to': '.log-test-new'}]},
                      {'version': 'next', 'added': ['.log-profile-name']}]
    p.write_text(json.dumps(cat, ensure_ascii=False), encoding='utf-8')


def login(page, key):
    # Sessions belong to one browser (user agent), so each browser logs in itself.
    page.goto(f'{URL}/admin/index.php?k={key}')
    page.fill('input[name="password"]', 'log-test-password')
    with page.expect_navigation():
        page.press('input[name="password"]', 'Enter')


def run(pw, browser_name, device, client, csrf, key, site, parity):
    label = f'{browser_name}{"・" + device if device else ""}'
    browser = getattr(pw, browser_name).launch()
    opts = dict(pw.devices[device]) if device else {'viewport': {'width': 1280, 'height': 900}}
    opts.update(locale='ja-JP')
    context = browser.new_context(**opts)
    page = context.new_page()
    login(page, key)
    requests = []
    page.on('request', lambda r: requests.append(r.url))
    dialogs = []
    page.on('dialog', lambda d: (dialogs.append(d.message), d.accept()))
    try:
        # Other pages never load the editor.
        for path in ('/admin/index.php?p=settings&section=log', '/admin/index.php?p=log', '/'):
            requests.clear()
            page.goto(URL + path)
            page.wait_for_load_state('networkidle')
            check(f'{label}：{path} では編集・検証のファイルを読み込まない', not [u for u in requests if 'css-editor' in u or 'css-catalog' in u], str([u for u in requests if 'css' in u]))
        page.goto(URL + '/admin/index.php?p=settings&section=log')
        page.click('a[href="index.php?p=css"]')
        page.wait_for_selector('.css-page.is-ready')
        check(f'{label}：設定のリンクから同じタブで編集ページへ', page.url.endswith('p=css') and len(context.pages) == 1)
        check(f'{label}：編集ページでは編集用のファイルを読み込む', any('css-editor.js' in u for u in requests))
        check(f'{label}：管理画面には追加CSSを読み込まない', page.locator('link[data-nl-custom-css]').count() == 0)

        if parity:
            js = page.evaluate('cases => cases.map(c => window.NLCssCheck(c).map(e => [e.line, e.column, e.message]))', CORPUS)
            php = php_errors(site)
            diff = [(CORPUS[i], js[i], php[i]) for i in range(len(CORPUS)) if js[i] != php[i]]
            check(f'{label}：画面の構文チェックとサーバーの構文チェックが{len(CORPUS)}件すべて一致', not diff, json.dumps(diff[:3], ensure_ascii=False))

        text = page.locator('[data-css-text]')
        text.click()
        page.keyboard.press('Control+A')
        page.keyboard.press('Delete')
        page.keyboard.type('.log-site-title { outline: 3px solid rgb(1, 2, 3); }\n.x { colr red; }\n')
        page.wait_for_timeout(500)
        items = page.locator('.css-diagnostic')
        check(f'{label}：入力のあと少し待って構文エラーを表示（行・列つき）', items.count() >= 1 and '2行6列' in items.first.inner_text(), items.first.inner_text() if items.count() else '')
        same = page.evaluate("""() => { const a = getComputedStyle(document.querySelector('[data-css-text]')), c = getComputedStyle(document.querySelector('[data-css-highlight]'));
            return [a.fontSize === c.fontSize, a.lineHeight === c.lineHeight, c.paddingLeft === '0px', c.backgroundColor === 'rgba(0, 0, 0, 0)']; }""")
        check(f'{label}：色分けの文字が入力欄の文字と同じ大きさ・位置（カーソルがずれない）', all(same), str(same))
        check(f'{label}：色分け（セレクター・プロパティ）と行番号', page.locator('.tk-sel').count() > 0 and page.locator('.tk-prop').count() > 0 and page.locator('.css-gutter .ln-error').count() == 1)
        items.first.locator('button').click()
        start = page.evaluate("document.querySelector('[data-css-text]').selectionStart")
        value = text.input_value()
        check(f'{label}：診断をクリックすると、その場所を選択', start == value.index('colr'))
        page.click('[data-css-save]')
        page.wait_for_timeout(300)
        check(f'{label}：構文エラーがあると保存しない', 'エラー' in page.inner_text('[data-css-status]') or '構文' in page.inner_text('[data-css-status]'))
        sel = value.index('colr red')
        page.evaluate(f"(() => {{ const t = document.querySelector('[data-css-text]'); t.focus(); t.setSelectionRange({sel + 4}, {sel + 4}); }})()")
        page.keyboard.type(':')
        page.wait_for_timeout(500)
        kinds = page.locator('.css-diagnostic').all_inner_texts()
        check(f'{label}：知らないプロパティは警告（エラーではない）', kinds and all(k.startswith('警告') for k in kinds) and any('colr' in k for k in kinds), str(kinds))
        page.click('[data-css-save]')
        page.wait_for_function("() => document.querySelector('[data-css-status]').dataset.kind === 'ok'")
        check(f'{label}：警告だけなら保存できる', '保存しました' in page.inner_text('[data-css-status]'))
        page.reload()
        page.wait_for_selector('.css-page.is-ready')
        check(f'{label}：再読み込みしても保存した内容', 'rgb(1, 2, 3)' in text.input_value() and 'colr: red' in text.input_value())

        pub = context.new_page()
        pub.goto(URL + '/')
        color = pub.evaluate("getComputedStyle(document.querySelector('.log-site-title')).outlineColor")
        check(f'{label}：公開ページに反映', color == 'rgb(1, 2, 3)', color)
        pub.close()

        # Files: choose, size limit, encoding, drop.
        tmp = site.parent / 'files'
        tmp.mkdir(exist_ok=True)
        good = tmp / 'theme.css'
        good.write_text('/* ファイルから読み込み */\n.log-site-title { outline: 3px solid rgb(4, 5, 6); }\n', encoding='utf-8')
        text.click(); page.keyboard.type(' ')
        dialogs.clear()
        page.set_input_files('[data-css-file]', str(good))
        page.wait_for_function("() => document.querySelector('[data-css-text]').value.includes('ファイルから')")
        check(f'{label}：ファイル選択で読み込み、未保存の編集があれば確認', dialogs and 'theme.css' in dialogs[0] and text.input_value().startswith('/* ファイルから'))
        big = tmp / 'big.css'
        big.write_bytes(b'/*' + b'a' * 262142 + b'*/')
        page.set_input_files('[data-css-file]', str(big))
        page.wait_for_timeout(300)
        check(f'{label}：256KiBを超えるファイルは読み込まない', '256KiB' in page.inner_text('[data-css-status]') and text.input_value().startswith('/* ファイルから'), page.inner_text('[data-css-status]') + ' | ' + text.input_value()[:40])
        sjis = tmp / 'sjis.css'
        sjis.write_bytes('/* 日本語 */ .a{b:c}'.encode('cp932'))
        page.set_input_files('[data-css-file]', str(sjis))
        page.wait_for_timeout(300)
        check(f'{label}：UTF-8でないファイルは読み込まない', 'UTF-8' in page.inner_text('[data-css-status]') and text.input_value().startswith('/* ファイルから'))
        dialogs.clear()
        page.evaluate("""() => {
            const file = new File(['/* ドロップ */\\n.log-site-title { outline: 3px solid rgb(7, 8, 9); }\\n'], 'drop.css', { type: 'text/css' });
            const dt = new DataTransfer(); dt.items.add(file);
            const zone = document.querySelector('[data-css-drop]');
            for (const type of ['dragenter', 'dragover', 'drop']) zone.dispatchEvent(new DragEvent(type, { dataTransfer: dt, bubbles: true, cancelable: true }));
        }""")
        page.wait_for_function("() => document.querySelector('[data-css-text]').value.includes('ドロップ')")
        check(f'{label}：ドラッグ＆ドロップで読み込み（未保存なら確認）', bool(dialogs) and 'drop.css' in dialogs[0])
        page.click('[data-css-save]')
        page.wait_for_function("() => document.querySelector('[data-css-status]').dataset.kind === 'ok'")
        page.reload()
        page.wait_for_selector('.css-page.is-ready')
        check(f'{label}：ドロップした内容を保存・再読み込み', 'rgb(7, 8, 9)' in text.input_value())

        # Another screen saves first.
        rev = int(page.get_attribute('[data-css-editor]', 'data-revision'))
        client.post('/admin/index.php', {'csrf': csrf, 'do': 'log_css_save', 'css': '.other{a:b}', 'revision': str(rev)}, headers={'Accept': 'application/json'})
        text.click(); page.keyboard.press('End'); page.keyboard.type('\n.mine { color: red; }')
        page.click('[data-css-save]')
        page.wait_for_selector('[data-css-conflict]')
        check(f'{label}：同時編集を知らせ、編集中の内容を残す', '.mine' in text.input_value() and page.locator('[data-css-overwrite]').is_visible())
        page.click('[data-css-overwrite]')
        page.wait_for_function("() => document.querySelector('[data-css-status]').dataset.kind === 'ok'")
        check(f'{label}：選べばこの画面の内容で上書き保存', '.mine' in client.get('/admin/index.php?p=css&download=1').text)

        # On / off.
        page.click('[data-css-enabled-button]')
        page.wait_for_function("() => document.querySelector('[data-css-enabled-form]').dataset.on === '0'")
        pub = context.new_page(); pub.goto(URL + '/')
        check(f'{label}：管理画面から適用を止められる', pub.locator('link[data-nl-custom-css]').count() == 0)
        page.click('[data-css-enabled-button]')
        page.wait_for_function("() => document.querySelector('[data-css-enabled-form]').dataset.on === '1'")
        pub.reload()
        check(f'{label}：もう一度適用できる', pub.locator('link[data-nl-custom-css]').count() == 1)
        pub.close()

        # Catalog: filtering and the changes since a chosen version.
        page.fill('[data-css-catalog-search]', 'profile-name')
        rows = page.locator('[data-css-catalog-row]:visible')
        check(f'{label}：一覧を絞り込める', rows.count() == 1 and '.log-profile-name' in rows.first.inner_text())
        page.select_option('[data-css-since]', '1')
        shown = page.locator('[data-css-history]:visible h3').all_inner_texts()
        check(f'{label}：v0.7.0 から見ると v0.8.0 と次のバージョンの変更を表示', shown == ['v0.8.0', '次のバージョン'], str(shown))
        page.select_option('[data-css-since]', '0')
        shown = page.locator('[data-css-history]:visible h3').all_inner_texts()
        check(f'{label}：初期一覧から見ると途中の版も含めて表示', shown == ['v0.7.0', 'v0.8.0', '次のバージョン'], str(shown))
        removed = page.locator('[data-css-history="2"]').inner_text()
        check(f'{label}：廃止・改名を表示', '廃止' in removed and '.log-test-gone' in removed and '.log-test-old → .log-test-new' in removed)

        # Width: the page fits a phone and the editor stays usable.
        overflow = page.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
        box = page.locator('.css-editor').bounding_box()
        check(f'{label}：横にはみ出さず、編集欄が使える大きさ', overflow <= 1 and box['width'] >= min(300, page.viewport_size['width'] - 60) and box['height'] >= 200, f'overflow {overflow} box {box}')
    finally:
        context.close()
        browser.close()


def english(pw, key):
    browser = pw.chromium.launch()
    context = browser.new_context(locale='en-US')
    page = context.new_page()
    login(page, key)
    page.goto(URL + '/admin/index.php?p=css')
    page.wait_for_selector('.css-page.is-ready')
    text = page.locator('[data-css-text]')
    text.click(); page.keyboard.press('Control+A'); page.keyboard.press('Delete'); page.keyboard.type('.a { colr red; }')
    page.wait_for_timeout(500)
    body = page.inner_text('body')
    check('英語：編集ページ・診断・一覧の見出しが英語', 'Additional CSS' in body and 'Line 1, column 6' in body and 'Classes and elements for customizing' in body and not re.search(r'[ぁ-んァ-ン]', page.inner_text('.css-page')), body[:400])
    context.close(); browser.close()


def main():
    h.RESULTS.mkdir(exist_ok=True)
    root = h.RESULTS / ('css-browser-' + secrets.token_hex(4))
    site = root / 'site'
    h.copy_server(site, HERE.parent / 'server')
    catalog_with_versions(site)
    env = {k: v for k, v in os.environ.items() if k != 'NAGIMANGA_DATA'}
    process = subprocess.Popen(h.php_cmd(PORT, True, site), env=env, stdout=subprocess.DEVNULL, stderr=open(root / 'php.txt', 'wb'))
    try:
        for _ in range(100):
            try:
                h.Client(PORT).get('/admin/index.php'); break
            except OSError:
                time.sleep(.05)
        client, csrf, key = base.install(PORT)
        with sync_playwright() as pw:
            run(pw, 'chromium', None, client, csrf, key, site, True)
            run(pw, 'chromium', 'Pixel 7', client, csrf, key, site, False)
            run(pw, 'webkit', None, client, csrf, key, site, True)
            run(pw, 'webkit', 'iPhone 13', client, csrf, key, site, False)
            english(pw, key)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f'\n{passed} / {len(checks)} passed')
    (root / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__':
    main()
