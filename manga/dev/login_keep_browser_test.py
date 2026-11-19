#!/usr/bin/env python3
"""The owner's login survives being opened from another site or app (a real browser).

Up to 0.15.4 the login cookie was SameSite=Strict. A browser holds such a cookie back when the admin is opened from another
site or app, or when a phone restores its tabs after the browser was closed; the cookie-less request then got a new empty
session, and its cookie (gone when the browser closes) overwrote the 30-day login. The owner had to log in again.

Logs in with Chromium on 127.0.0.1, then follows a link to the admin from another site (localhost) and checks that the
admin opens and the cookie still lasts 30 days, and that the LOG's top page opened the same way shows the owner's view at once.
Usage: python manga/dev/login_keep_browser_test.py
"""
import argparse, json, secrets, socket, sys, time
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5470)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
from playwright.sync_api import sync_playwright
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)
PASSWORD = 'login-keep-test-password'


def run():
    c = h.Client(args.port)
    r = c.get('/admin/index.php')
    c.post('/admin/index.php', {'csrf': h.csrf_of(r.text), 'setup_key': h.setup_key(args.port), 'password': PASSWORD, 'password2': PASSWORD, 'home': 'log'})
    site = f'http://127.0.0.1:{args.port}'
    other = f'http://localhost:{args.port}'   # another site to the browser (not the same host)
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        ctx = browser.new_context()
        page = ctx.new_page()
        def login_cookie():
            return next((c for c in ctx.cookies(site) if c['name'] == 'nm_admin'), None)
        def lasts_30_days():
            ck = login_cookie()
            return ck is not None and 29.9 < (ck['expires'] - time.time()) / 86400 <= 30.01
        def is_admin():
            return 'name="password"' not in page.content() and 'login.php' not in page.url
        page.goto(site + '/admin/login.php')
        page.fill('input[name=password]', PASSWORD)
        with page.expect_navigation(): page.press('input[name=password]', 'Enter')
        check('ログインすると管理画面が開き、Cookie は 30 日残る', is_admin() and lasts_30_days())

        page.goto(other + '/')
        page.evaluate("u => { const a = document.createElement('a'); a.href = u; a.id = 'nm-test-away'; a.textContent = 'admin'; document.body.prepend(a); }", site + '/admin/index.php?p=log')
        with page.expect_navigation(): page.click('#nm-test-away')
        check('ほかのサイトのリンクから開いても、ログインしたまま管理画面が開く', is_admin())
        check('そのあともログインの Cookie は 30 日のまま（ブラウザーを閉じても消えない）', lasts_30_days())
        page.goto(site + '/admin/index.php?p=log')
        check('同じサイトから開き直しても、ログインしたまま', is_admin())

        # The LOG's top page from another site: the owner's view (writing box, 管理ページ link) without reloading.
        page.goto(other + '/')
        page.evaluate("u => { const a = document.createElement('a'); a.href = u; a.id = 'nm-test-top'; a.textContent = 'top'; document.body.prepend(a); }", site + '/')
        with page.expect_navigation(): page.click('#nm-test-top')
        html = page.content()
        check('ほかのサイトのリンクからLOGのトップを開くと、再読み込みしなくても持ち主の画面（管理ページのリンク）', 'log-login-link' in html and '管理ページ' in html)
        browser.close()

    # After logging out: a button back to the top page of a public LOG, none for a private Memo (its top is Not Found).
    def log_in_and_out(private=False):
        o = h.Client(args.port)
        r = o.get('/admin/login.php')
        o.post('/admin/login.php', {'csrf': h.csrf_of(r.text), 'password': PASSWORD})
        csrf = h.csrf_of(o.get('/admin/index.php').text)
        if private is not None: o.post('/admin/index.php', {'csrf': csrf, 'do': 'log_preferences', 'visibility': 'private' if private else 'public', 'posts_per_page': '10'})
        return o.post('/admin/index.php', {'csrf': csrf, 'do': 'logout'}).text
    out = log_in_and_out(None)
    check('ログアウトしたあと、公開LOGのトップページへ戻るボタン', 'ログアウトしました' in out and '<a class="btn primary" href="../">トップページへ戻る</a>' in out)
    out = log_in_and_out(True)
    check('非公開（Memo）ならトップページへ戻るボタンは出さない', 'ログアウトしました' in out and 'トップページへ戻る' not in out)
    log_in_and_out(False)


def main():
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('login-keep-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, args.port)
    try:
        run()
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
