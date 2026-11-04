#!/usr/bin/env python3
"""The admin menu after scrolling down (Chromium and WebKit): with the header out of sight a button slides in from the
top edge; it opens every menu entry, each with its icon, in a wide dialog in the middle of the screen. On the LOG pages
of the logged-in owner the button floats in without scrolling (PCs: top right; phones: bottom right). With a mouse
(PCs) pointing at the button, or at the header's 管理メニュー, drops the menu right below it without a click.
Phone and PC widths, light and dark; screenshots go to manga/dev/results/nav-fab-shots/.
Usage: python manga/dev/nav_fab_browser_test.py
"""
import secrets, sys
from pathlib import Path
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
from playwright.sync_api import sync_playwright

PORT = 5492
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
SHOTS = base.RESULTS / 'nav-fab-shots'
_request = h.Client.request
def _browser(self, method, path, body=None, headers=None, **kw):
    headers = dict(headers or {}); headers.setdefault('User-Agent', UA)
    return _request(self, method, path, body, headers, **kw)
h.Client.request = _browser
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def shown(page):
    return page.evaluate("(() => { const b = document.querySelector('.nav-fab'); if (!b) return null;"
                         " const s = getComputedStyle(b), r = b.getBoundingClientRect();"
                         " return s.visibility === 'visible' && r.top >= 0 && r.bottom <= innerHeight; })()")


DIALOG = ("(() => { const d = document.querySelector('.nav-dialog'); const r = d.getBoundingClientRect();"
          " const items = [...d.querySelectorAll('.nav-dialog-main > a, .nav-dialog-more a, .nav-dialog-more button')];"
          " return {open: d.open, l: r.left, r: document.documentElement.clientWidth - r.right, t: r.top, b: innerHeight - r.bottom, w: r.width,"
          " links: d.querySelectorAll('.nav-dialog-main > a').length, more: d.querySelectorAll('.nav-dialog-more a').length,"
          " logout: !!d.querySelector('form input[name=csrf]'), current: d.querySelector('.nav-dialog-main > a[aria-current]')?.textContent ?? null,"
          " icons: items.every(a => { const svg = a.firstElementChild, u = svg?.querySelector('use'); const ir = svg?.getBoundingClientRect(), tr = a.querySelector('span')?.getBoundingClientRect();"
          "   return svg?.tagName.toLowerCase() === 'svg' && u && u.getBBox().width > 4 && ir.width >= 16 && ir.right <= tr.left; }),"
          " scrollX: document.documentElement.scrollWidth > innerWidth}; })()")


def check_dialog(page, tag, current):
    box = page.evaluate(DIALOG)
    check(f'{tag}: ダイアログが開く', box['open'])
    check(f'{tag}: 横の真ん中 ({box["l"]:.0f}/{box["r"]:.0f})', abs(box['l'] - box['r']) <= 1.5)
    check(f'{tag}: 縦の真ん中 ({box["t"]:.0f}/{box["b"]:.0f})', abs(box['t'] - box['b']) <= 1.5)
    want = min(640, page.evaluate("document.documentElement.clientWidth") - 24)
    check(f'{tag}: 幅を狭めない ({box["w"]:.0f} / {want})', abs(box['w'] - want) <= 1.5)
    check(f'{tag}: 全部の項目が入る (主 {box["links"]}・ほか {box["more"]})', box['links'] == 4 and box['more'] == 3 and box['logout'])
    check(f'{tag}: どの項目も名前の左にアイコン', box['icons'])
    check(f'{tag}: いまの画面に印 ({box["current"]})', box['current'] == current)
    check(f'{tag}: 横にはみ出さない', not box['scrollX'])


DROP = ("(() => { const d = document.querySelector('.nav-drop'), f = document.querySelector('.nav-fab'); const r = d.getBoundingClientRect(), b = f.getBoundingClientRect();"
        " const items = [...d.querySelectorAll('.nav-dialog-main > a, .nav-dialog-more a, .nav-dialog-more button')];"
        " return {open: !d.hidden && getComputedStyle(d).display !== 'none', gap: r.top - b.bottom, right: Math.abs(r.right - b.right), inView: r.bottom <= innerHeight && r.left >= 0,"
        " links: d.querySelectorAll('.nav-dialog-main > a').length, more: d.querySelectorAll('.nav-dialog-more a').length, logout: !!d.querySelector('form input[name=csrf]'),"
        " current: d.querySelector('a[aria-current]')?.textContent ?? null, modal: document.querySelector('.nav-dialog').open,"
        " icons: items.every(a => { const svg = a.firstElementChild, u = svg?.querySelector('use'); const ir = svg?.getBoundingClientRect(), tr = a.querySelector('span')?.getBoundingClientRect();"
        "   return svg?.tagName.toLowerCase() === 'svg' && u && u.getBBox().width > 4 && ir.width >= 16 && ir.right <= tr.left; })}; })()")
DROP_OPEN = "!document.querySelector('.nav-drop').hidden"


def check_drop(page, tag, current, shot):
    """With a mouse: pointing at the button drops the menu below it; it stays while the pointer crosses into it."""
    page.mouse.move(5, 400); page.wait_for_timeout(350)
    page.hover('.nav-fab'); page.wait_for_timeout(350)
    box = page.evaluate(DROP)
    check(f'{tag}: カーソルを当てるだけで下にメニュー', box['open'] and not box['modal'])
    check(f'{tag}: ボタンのすぐ下・右端そろえ (間 {box["gap"]:.0f}・ずれ {box["right"]:.0f})', 0 <= box['gap'] <= 12 and box['right'] <= 2 and box['inView'])
    check(f'{tag}: 全部の項目 (主 {box["links"]}・ほか {box["more"]})', box['links'] == 4 and box['more'] == 3 and box['logout'])
    check(f'{tag}: どの項目も名前の左にアイコン', box['icons'])
    check(f'{tag}: いまの画面に印 ({box["current"]})', box['current'] == current)
    page.screenshot(path=str(SHOTS / shot))
    # The pointer moves down into the menu: it stays open.
    page.hover('.nav-drop .nav-dialog-more a'); page.wait_for_timeout(500)
    check(f'{tag}: メニューへ移る間も閉じない', page.evaluate(DROP_OPEN))
    page.mouse.move(5, 600); page.wait_for_timeout(500)
    check(f'{tag}: カーソルが離れると閉じる', not page.evaluate(DROP_OPEN))


def run(pw):
    c, csrf, key = base.install(PORT)
    cookies = [{'name': k, 'value': v, 'domain': '127.0.0.1', 'path': '/'} for k, v in c.cookies.items()]
    SHOTS.mkdir(parents=True, exist_ok=True)
    for engine in ('chromium', 'webkit'):
        browser = getattr(pw, engine).launch()
        for name, size in (('phone', {'width': 390, 'height': 760}), ('pc', {'width': 1280, 'height': 800})):
            for scheme in ('light', 'dark'):
                ctx = browser.new_context(user_agent=UA, viewport=size, color_scheme=scheme, has_touch=name == 'phone', is_mobile=name == 'phone' and engine == 'chromium')
                ctx.add_cookies(cookies)
                page = ctx.new_page()
                errors = []
                page.on('pageerror', lambda e: errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{PORT}/admin/index.php?p=settings')
                page.wait_for_timeout(400)
                tag = f'{engine} {name} {scheme}'
                check(f'{tag}: 上にいる間はボタンを出さない', shown(page) is False)
                page.evaluate('window.scrollTo(0, 1500)')
                page.wait_for_timeout(600)
                check(f'{tag}: 下へ動かすとボタンが上から入る', shown(page) is True)
                if scheme == 'light': page.screenshot(path=str(SHOTS / f'{engine}-{name}-button.png'))
                if name == 'pc':
                    check_drop(page, tag, '設定', f'{engine}-{name}-{scheme}-drop.png')
                    page.hover('.nav-fab'); page.wait_for_timeout(300)
                    page.click('.nav-drop .nav-dialog-main > a:has-text("NagiScope")')
                else:
                    page.click('.nav-fab')
                    page.wait_for_timeout(450)
                    check_dialog(page, tag, '設定')
                    page.wait_for_timeout(200)
                    page.screenshot(path=str(SHOTS / f'{engine}-{name}-{scheme}-dialog.png'))
                    page.click('[data-nav-close]')
                    page.wait_for_timeout(200)
                    check(f'{tag}: 閉じるで閉じる', page.evaluate("!document.querySelector('.nav-dialog').open"))
                    page.click('.nav-fab'); page.wait_for_timeout(300)
                    page.mouse.click(5, size['height'] - 5); page.wait_for_timeout(200)
                    check(f'{tag}: 外側を押しても閉じる', page.evaluate("!document.querySelector('.nav-dialog').open"))
                    page.click('.nav-fab'); page.wait_for_timeout(300)
                    page.click('dialog .nav-dialog-main > a:has-text("NagiScope")')
                page.wait_for_load_state()
                check(f'{tag}: 項目で移動できる', 'p=scope' in page.url)
                page.evaluate('window.scrollTo(0, 0)'); page.wait_for_timeout(500)
                check(f'{tag}: 上へ戻るとボタンが消える', shown(page) is False)
                hdr = page.evaluate("[...document.querySelectorAll('.workspace-nav > a, .nav-more > summary')].every(a => a.querySelector('svg.nm-menu-icon use'))")
                check(f'{tag}: 上の見出しのメニューにもアイコン', hdr)
                if name == 'pc':
                    # The header's 管理メニュー opens by pointing, and stays while the pointer goes down into it.
                    page.hover('.nav-more > summary'); page.wait_for_timeout(300)
                    check(f'{tag}: 見出しの管理メニューもカーソルで開く', page.evaluate("document.querySelector('.nav-more').open"))
                    page.hover('.nav-more > div a'); page.wait_for_timeout(500)
                    check(f'{tag}: 見出しのメニューへ移る間も閉じない', page.evaluate("document.querySelector('.nav-more').open"))
                    page.screenshot(path=str(SHOTS / f'{engine}-{name}-{scheme}-header-hover.png'))
                    page.mouse.move(600, 600); page.wait_for_timeout(500)
                    check(f'{tag}: 見出しのメニューはカーソルが離れると閉じる', not page.evaluate("document.querySelector('.nav-more').open"))

                # The LOG page of the logged-in owner: the button is there without scrolling.
                ltag = f'{tag} LOG'
                page.goto(f'http://127.0.0.1:{PORT}/')
                page.wait_for_timeout(1100)
                check(f'{ltag}: スクロールせずにボタンが出る', page.evaluate('scrollY') == 0 and shown(page) is True)
                pos = page.evaluate("(() => { const r = document.querySelector('.nav-fab').getBoundingClientRect(), m = document.querySelector('.log-menu-toggle')?.getBoundingClientRect();"
                                    " const hit = m && m.width && !(r.right <= m.left || r.left >= m.right || r.bottom <= m.top || r.top >= m.bottom);"
                                    " return {top: r.top, bottom: innerHeight - r.bottom, right: document.documentElement.clientWidth - r.right, hit: !!hit}; })()")
                if name == 'pc':
                    check(f'{ltag}: PC は右上 ({pos["top"]:.0f}, {pos["right"]:.0f})', pos['top'] < 30 and pos['right'] < 40)
                else:
                    check(f'{ltag}: スマホは右下 ({pos["bottom"]:.0f}, {pos["right"]:.0f})', 60 < pos['bottom'] < 160 and pos['right'] < 40)
                check(f'{ltag}: MENU ボタンと重ならない', not pos['hit'])
                page.screenshot(path=str(SHOTS / f'{engine}-{name}-{scheme}-log.png'))
                if name == 'pc':
                    check_drop(page, ltag, None, f'{engine}-{name}-{scheme}-log-drop.png')
                    page.hover('.nav-fab'); page.wait_for_timeout(300)
                    page.click('.nav-drop .nav-dialog-main > a:has-text("LOG")')
                else:
                    page.click('.nav-fab'); page.wait_for_timeout(450)
                    check_dialog(page, ltag, None)
                    page.wait_for_timeout(200)
                    page.screenshot(path=str(SHOTS / f'{engine}-{name}-{scheme}-log-dialog.png'))
                    page.click('dialog .nav-dialog-main > a:has-text("LOG")')
                page.wait_for_load_state()
                check(f'{ltag}: 管理画面へ移動できる', 'admin/index.php?p=log' in page.url)
                check(f'{tag}: スクリプトエラーなし' + (' ' + ' | '.join(errors) if errors else ''), not errors)
                ctx.close()
        browser.close()


def main():
    root = base.RESULTS / ('nav-fab-' + secrets.token_hex(4))
    root.mkdir(parents=True)
    site = root / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, PORT)
    try:
        with sync_playwright() as pw:
            run(pw)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__':
    main()
