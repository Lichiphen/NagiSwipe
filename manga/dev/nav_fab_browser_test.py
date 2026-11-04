#!/usr/bin/env python3
"""The admin menu after scrolling down (Chromium and WebKit): with the header out of sight a button slides in from the
top edge; it opens every menu entry in a wide dialog in the middle of the screen. Phone and PC widths, light and dark;
screenshots go to manga/dev/results/nav-fab-shots/.
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
                page.click('.nav-fab')
                page.wait_for_timeout(450)
                box = page.evaluate("(() => { const d = document.querySelector('.nav-dialog'); const r = d.getBoundingClientRect();"
                                    " return {open: d.open, l: r.left, r: document.documentElement.clientWidth - r.right, t: r.top, b: innerHeight - r.bottom, w: r.width,"
                                    " links: d.querySelectorAll('.nav-dialog-main > a').length, more: d.querySelectorAll('.nav-dialog-more a').length,"
                                    " logout: !!d.querySelector('form input[name=csrf]'), current: d.querySelector('.nav-dialog-main > a[aria-current]')?.textContent,"
                                    " scrollX: document.documentElement.scrollWidth > innerWidth}; })()")
                check(f'{tag}: ダイアログが開く', box['open'])
                check(f'{tag}: 横の真ん中 ({box["l"]:.0f}/{box["r"]:.0f})', abs(box['l'] - box['r']) <= 1.5)
                check(f'{tag}: 縦の真ん中 ({box["t"]:.0f}/{box["b"]:.0f})', abs(box['t'] - box['b']) <= 1.5)
                want = min(640, page.evaluate("document.documentElement.clientWidth") - 24)
                check(f'{tag}: 幅を狭めない ({box["w"]:.0f} / {want})', abs(box['w'] - want) <= 1.5)
                check(f'{tag}: 全部の項目が入る (主 {box["links"]}・ほか {box["more"]})', box['links'] == 4 and box['more'] == 3 and box['logout'])
                check(f'{tag}: いまの画面に印', box['current'] == '設定')
                check(f'{tag}: 横にはみ出さない', not box['scrollX'])
                page.wait_for_timeout(200)
                page.screenshot(path=str(SHOTS / f'{engine}-{name}-{scheme}-dialog.png'))
                page.click('[data-nav-close]')
                page.wait_for_timeout(200)
                check(f'{tag}: 閉じるで閉じる', page.evaluate("!document.querySelector('.nav-dialog').open"))
                page.click('.nav-fab'); page.wait_for_timeout(300)
                page.mouse.click(5, size['height'] - 5); page.wait_for_timeout(200)
                check(f'{tag}: 外側を押しても閉じる', page.evaluate("!document.querySelector('.nav-dialog').open"))
                page.click('.nav-fab'); page.wait_for_timeout(300)
                page.click('.nav-dialog-main > a:has-text("NagiScope")')
                page.wait_for_load_state()
                check(f'{tag}: 項目で移動できる', 'p=scope' in page.url)
                page.evaluate('window.scrollTo(0, 0)'); page.wait_for_timeout(500)
                check(f'{tag}: 上へ戻るとボタンが消える', shown(page) is False)
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
