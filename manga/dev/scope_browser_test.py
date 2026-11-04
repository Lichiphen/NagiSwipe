#!/usr/bin/env python3
"""NagiScope in real browsers (Chromium and WebKit): a reader scrolls a post, presses a link, starts the game in a page embed
([ページ埋込]) and reads a manga to the end; the events arrive at scope.php as the screens expect. Then the dashboard,
a work's screen and the settings are photographed in light and dark, wide and phone-sized (manga/dev/results/scope-shots/).
Usage: python manga/dev/scope_browser_test.py
"""
import http.server, json, re, secrets, socketserver, sys, threading, time
from pathlib import Path
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
from playwright.sync_api import sync_playwright

PORT, GAME_PORT = 5490, 5491
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
GAME = f'http://127.0.0.1:{GAME_PORT}/game.html'
SHOTS = base.RESULTS / 'scope-shots'
_request = h.Client.request
def _browser(self, method, path, body=None, headers=None, **kw):
    headers = dict(headers or {}); headers.setdefault('User-Agent', UA)
    return _request(self, method, path, body, headers, **kw)
h.Client.request = _browser
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)

GAME_HTML = b'''<!doctype html><html><head><meta charset="utf-8"><title>Test Game</title><meta property="og:title" content="Test Game"></head>
<body style="margin:0;background:#123;color:#fff;font:20px sans-serif"><button id="start" style="width:100%;height:100vh;font-size:30px">START</button>
<script>document.getElementById('start').addEventListener('click',function(){this.textContent='PLAYING';if(window.parent!==window)window.parent.postMessage('nagiscope:start','*');});</script></body></html>'''


# A game that says it started as soon as it loads (before the LOG page could be listening), without being touched.
AUTO_HTML = b'''<!doctype html><html><head><meta charset="utf-8"><title>Auto Game</title></head><body style="margin:0;background:#321">
<script>window.parent.postMessage('nagiscope:start','*');</script><p style="color:#fff">AUTO</p></body></html>'''


class Game(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200); self.send_header('Content-Type', 'text/html; charset=utf-8'); self.end_headers(); self.wfile.write(AUTO_HTML if 'auto' in self.path else GAME_HTML)
    def log_message(self, *a): pass


def raw_lines(site):
    out = []
    for f in sorted((site / 'data/scope/raw').glob('*.jsonl.php')):
        out += [json.loads(l) for l in f.read_text(encoding='utf-8').splitlines()[1:]]
    return out


def run(site, pw):
    c, csrf, key = base.install(PORT)
    post = lambda d, **kw: c.post('/admin/index.php', {'csrf': csrf, **d}, **kw)
    post({'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '10'})
    post({'do': 'scope_enable', 'ip': 'full'})
    filler = lambda ch: '\n\n'.join([ch * 40] * 12)
    body = ('冒頭の文です。\n## はじめに\n' + filler('あ') + '\n## 中盤の話\n' + filler('い') + '\n\n[ページ埋込]' + GAME + '[/ページ埋込]\n\n## おわりに\n' + filler('う')
            + '\n\n[ゲームのページ](https://hisukobari.itch.io/test)')
    pid = json.loads(post(base.payload(body=body)).text)['id']
    auto = GAME.replace('game.html', 'auto.html')
    pid2 = json.loads(post(base.payload(body='自動で始まるゲーム\n\n[ページ埋込]' + auto + '[/ページ埋込]\n\nおしまい')).text)['id']
    r = post({'do': 'create', 'title': '計測の漫画', 'direction': 'rtl'})
    wid = re.search(r'id=([A-Za-z0-9]{12})', r.getheader('Location') or '').group(1)
    for i in range(5):
        post({'do': 'upload', 'id': wid}, files={'page': (f'{i:03d}.png', h.png(600, 900, seed=i), 'image/png')}, headers={'X-NM-CSRF': csrf})
    post({'do': 'scope_goal_save', 'name': 'ゲームを始めた', 'kind': 'game', 'target_game': ''})
    post({'do': 'scope_goal_save', 'name': 'itch.io へ行った', 'kind': 'click', 'how': 'contains', 'match': 'itch.io/'})

    for engine in ('chromium', 'webkit'):
        before = len(raw_lines(site))
        browser = getattr(pw, engine).launch()
        ctx = browser.new_context(user_agent=UA, viewport={'width': 1100, 'height': 800})
        page = ctx.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(f'http://127.0.0.1:{PORT}/?id={pid}')
        page.wait_for_timeout(500)
        for _ in range(12):
            page.mouse.wheel(0, 500); page.wait_for_timeout(120)
        page.locator('.log-frame-start').scroll_into_view_if_needed()
        page.wait_for_timeout(400)
        page.locator('.log-frame-start').click()
        page.wait_for_selector('.log-frame-stage iframe')
        page.wait_for_timeout(1500)
        page.frame_locator('.log-frame-stage iframe').frame_locator('iframe').locator('#start').click()
        page.wait_for_timeout(2500)
        # Leaving the page (a test browser drops beacons sent while it navigates away, so the event comes first).
        page.evaluate("dispatchEvent(new Event('pagehide'))")
        page.wait_for_timeout(500)
        page.locator('a[href="https://hisukobari.itch.io/test"]').click(button='middle')
        page.wait_for_timeout(300)
        page.goto('about:blank')
        page.wait_for_timeout(800)
        got = raw_lines(site)[before:]
        kinds = {l['e'] for l in got}
        check(f'{engine}: 記事の閲覧・読んだ位置・中クリックのリンクを記録', {'pv', 'lv', 'ck'} <= kinds and any(l['e'] == 'lv' and l.get('h') == 'おわりに' for l in got))
        check(f'{engine}: ページ埋込のゲーム: 表示→押した→読み込み→ゲームを触った→起動の合図→遊んだ時間', {'fv', 'fo', 'fl', 'fg', 'fs', 'ft'} <= kinds)
        check(f'{engine}: 読み込みを押しただけでは「ゲームを触った」にしない（押す前に fg がない）', [l['e'] for l in got if l['e'] in ('fo', 'fg')][:1] == ['fo'])
        check(f'{engine}: ページのスクリプトエラーなし', not errors)

        # A game that signals by itself at load: the signal is counted, "touched" is not.
        before = len(raw_lines(site))
        page = ctx.new_page()
        page.goto(f'http://127.0.0.1:{PORT}/?id={pid2}')
        page.locator('.log-frame-start').click()
        page.wait_for_selector('.log-frame-stage iframe')
        page.wait_for_timeout(2000)
        got = [l['e'] for l in raw_lines(site)[before:]]
        check(f'{engine}: 読み込みと同時の起動の合図も取りこぼさない・触っていなければ「触った」にしない', 'fs' in got and 'fg' not in got)
        page.close()

        # Manga: the work's own page, read to the end, closed.
        before = len(raw_lines(site))
        page = ctx.new_page()
        page.goto(f'http://127.0.0.1:{PORT}/read.php?nagimanga={wid}')
        # The work's own page opens the viewer by itself.
        page.wait_for_selector('.nm-viewer.nm-open')
        page.wait_for_timeout(800)
        for _ in range(8):
            page.keyboard.press('ArrowLeft'); page.wait_for_timeout(1200)
        page.keyboard.press('Escape')
        page.wait_for_timeout(800)
        got = [l for l in raw_lines(site)[before:] if l['k'] == 'm']
        ends = [l for l in got if l['e'] == 'me']
        last = [l for l in got if l['e'] == 'mp'][-1:]
        check(f'{engine}: 漫画: 開いた・最後まで読んだ（流し読みではない）・進みを記録', got[:1] and got[0]['e'] == 'mo' and ends and ends[0]['sk'] == 0 and last and last[0]['mx'] == 4 and last[0]['pg'] >= 5)
        browser.close()

    # Screens
    SHOTS.mkdir(parents=True, exist_ok=True)
    cookies = [{'name': k, 'value': v, 'domain': '127.0.0.1', 'path': '/'} for k, v in c.cookies.items()]
    for engine in ('chromium', 'webkit'):
        browser = getattr(pw, engine).launch()
        for scheme in ('light', 'dark'):
            for name, vp in (('wide', {'width': 1280, 'height': 900}), ('phone', {'width': 390, 'height': 844})):
                ctx = browser.new_context(user_agent=UA, viewport=vp, color_scheme=scheme, reduced_motion='reduce')
                ctx.add_cookies(cookies)
                page = ctx.new_page()
                errors = []
                page.on('pageerror', lambda e: errors.append(str(e)))
                for label, url in (('dashboard-log', '/admin/index.php?p=scope&site=l'), ('dashboard-manga', '/admin/index.php?p=scope&site=m'),
                                   ('work', f'/admin/index.php?p=scope&view=item&key=m:{wid}'), ('post', f'/admin/index.php?p=scope&view=item&key=p:{pid}'),
                                   ('goals', '/admin/index.php?p=scope&view=goals'), ('settings', '/admin/index.php?p=scope&view=settings')):
                    page.goto(f'http://127.0.0.1:{PORT}{url}')
                    page.wait_for_timeout(300)
                    over = page.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
                    if name == 'phone': check(f'{engine} {scheme} {label}: スマホ幅で横にはみ出さない', over <= 0)
                    page.screenshot(path=str(SHOTS / f'{engine}-{scheme}-{name}-{label}.png'), full_page=True)
                if scheme == 'light' and name == 'wide':
                    page.goto(f'http://127.0.0.1:{PORT}/admin/index.php?p=scope&site=l')
                    check(f'{engine}: グラフを SVG で描く（線と前の期間）', page.locator('.ns-chart svg .ns-line').count() == 1 and page.locator('.ns-chart svg .ns-line-before').count() == 1)
                    page.locator('[data-ns-tab="day"]').click()
                    check(f'{engine}: 「1日」タブで 24 点・ラベルが変わる', page.locator('.ns-chart svg .ns-hit').count() == 24 and '今日' in page.locator('[data-ns-label]').inner_text())
                    page.locator('[data-ns-tab="year"]').click()
                    # The admin's CSP has no 'unsafe-eval', so wait by looking rather than with wait_for_function.
                    for _ in range(80):
                        if page.locator('.ns-chart svg .ns-hit').count() == 52: break
                        page.wait_for_timeout(100)
                    check(f'{engine}: 「1年」タブは選んだときに読み込み、52 点', page.locator('.ns-chart svg .ns-hit').count() == 52 and page.locator('[data-ns-period="year"]').count() == 1)
                    check(f'{engine}: 来た人の数は 1 以上', int(page.locator('[data-ns-big]').inner_text().replace(',', '')) >= 1)
                check(f'{engine} {scheme} {name}: 管理画面のスクリプトエラーなし' + (' ' + ' | '.join(errors) if errors else ''), not errors)
                ctx.close()
        browser.close()


class _null:
    def __enter__(self): return self
    def __exit__(self, *a): return False


def main():
    root = base.RESULTS / ('scope-browser-' + secrets.token_hex(4))
    root.mkdir(parents=True)
    site = root / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    game = socketserver.ThreadingTCPServer(('127.0.0.1', GAME_PORT), Game)
    threading.Thread(target=game.serve_forever, daemon=True).start()
    process = base.start(site, PORT)
    try:
        with sync_playwright() as pw:
            run(site, pw)
    finally:
        process.terminate(); process.wait(timeout=10); game.shutdown()
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__':
    main()
