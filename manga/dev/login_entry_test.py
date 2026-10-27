#!/usr/bin/env python3
"""The login entrance: "secret login URL only" (admin/login.php closed), choosing NagiLog or NagiManga on the login page,
the lockout settings (failures allowed, lock time) and the security log shown in Japan time.

Installs once with the setup page's "secret login URL only" box ticked (as a browser sends it), then switches the setting.
Usage: python manga/dev/login_entry_test.py
"""
import argparse, datetime, hashlib, json, re, secrets, socket, sys
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5450)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)
PASSWORD = 'entry-test-password'


def run(site):
    c = h.Client(args.port)
    r = c.get('/admin/index.php')
    check('セットアップ画面に「秘密のログイン URL からだけ」（最初からチェック）', re.search(r'<input type="checkbox" name="secret_login" value="1" checked>', r.text) is not None)
    r = c.post('/admin/index.php', {'csrf': h.csrf_of(r.text), 'setup_key': h.setup_key(args.port), 'password': PASSWORD, 'password2': PASSWORD, 'secret_login': '1'})
    key = re.search(r'index.php\?k=([A-Za-z0-9]+)', r.text).group(1)
    check('セットアップ完了の画面に NagiLog 用の URL も', f'index.php?k={key}&amp;p=log' in r.text and '「ログイン」のリンクは出ません' in r.text)
    check('config.php に login_entry = secret', "'login_entry' => 'secret'" in (site / 'data/config.php').read_text(encoding='utf-8'))

    # --- Secret URL only ---------------------------------------------------------------------------
    pub = h.Client(args.port)
    check('login.php は 404', h.is_404(pub.get('/admin/login.php')))
    check('URL の k= を削ると 404（ログイン画面へ案内しない）', h.is_404(pub.get('/admin/index.php')) and h.is_404(pub.get('/admin/index.php?p=log')))
    check('login.php へのパスワードの送信も 404', h.is_404(pub.post('/admin/login.php', {'csrf': 'x', 'password': PASSWORD})))
    check('LOGに「ログイン」のリンクを出さない', 'log-login-link' not in pub.get('/').text)

    # --- Choosing NagiLog or NagiManga -------------------------------------------------------------
    d = h.Client(args.port)
    page = d.get('/admin/index.php?k=' + key).text
    check('ログイン画面で開く画面を選べる（最初は NagiManga）', 'name="p" value="log" form="nm-login-form">' in page and 'name="p" value="manga" form="nm-login-form" checked>' in page)
    r = d.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(page), 'password': PASSWORD, 'p': 'log'})
    check('NagiLog を選ぶと LOG・投稿の画面へ', r.status == 303 and r.getheader('Location') == 'index.php?p=log')
    check('選んだ画面を覚える（cookie）', d.cookies.get('nm_land') == 'log')
    check('ログインのあと、LOGには「管理ページ」のリンク', 'log-login-link' in d.get('/').text)
    e = h.Client(args.port); e.cookies = {'nm_land': 'log'}
    check('次のログイン画面では NagiLog が選ばれている', 'name="p" value="log" form="nm-login-form" checked>' in e.get('/admin/index.php?k=' + key).text)
    check('NagiLog 用の URL（&p=log）では最初から NagiLog', 'name="p" value="log" form="nm-login-form" checked>' in h.Client(args.port).get(f'/admin/index.php?k={key}&p=log').text)
    f = h.Client(args.port)
    page = f.get(f'/admin/index.php?k={key}&p=log').text
    r = f.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(page), 'password': PASSWORD, 'p': 'manga'})
    check('NagiManga を選ぶと NagiManga の画面へ', r.status == 303 and r.getheader('Location') == 'index.php' and f.cookies.get('nm_land') == 'manga')

    # --- Settings: the public entrance again ----------------------------------------------------------
    settings = d.get('/admin/index.php?p=settings&section=common').text
    csrf = h.csrf_of(settings)
    check('設定に「ログインの入口」（今は秘密の URL だけ）と NagiLog 用の URL', 'name="login_entry" value="secret" checked>' in settings and f'index.php?k={key}&amp;p=log' in settings)
    check('ログイン後の画面には login.php へのリンクの印を付けない', 'data-public-login' not in settings)
    r = d.post('/admin/index.php', {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'login_entry', 'login_entry': 'public'}, headers={'Accept': 'application/json'})
    check('「LOGの「ログイン」からも入れる」に切り替えられる', r.status == 200 and json.loads(r.text).get('ok'))
    pub = h.Client(args.port)
    check('切り替えると login.php が開く', pub.get('/admin/login.php').status == 200)
    r = pub.get('/admin/index.php')
    check('切り替えると、k= のない管理画面は login.php へ案内', r.status == 303 and r.getheader('Location') == 'login.php')
    check('切り替えると LOG に「ログイン」のリンク', 'href="admin/login.php"' in pub.get('/').text)
    page = pub.get('/admin/login.php').text
    check('login.php では最初から NagiLog が選ばれている', 'name="p" value="log" form="nm-login-form" checked>' in page)
    r = pub.post('/admin/login.php', {'csrf': h.csrf_of(page), 'password': PASSWORD, 'p': 'manga'})
    check('login.php でも NagiManga を選べる', r.status == 303 and r.getheader('Location') == 'index.php')
    r = d.post('/admin/index.php', {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'login_entry', 'login_entry': 'other'}, headers={'Accept': 'application/json'})
    check('おかしな値は断る', r.status == 422)
    log = (site / 'data/logs/security.log.php').read_text(encoding='utf-8')
    check('切り替えをセキュリティログに記録', 'settings_login_entry_changed\tpublic' in log)

    # --- Security log in Japan time ---------------------------------------------------------------
    first = log.splitlines()[-1].split('\t')
    jst = datetime.datetime.fromisoformat(first[0]).astimezone(datetime.timezone(datetime.timedelta(hours=9))).strftime('%Y-%m-%d %H:%M:%S')
    page = d.get('/admin/index.php?p=settings&section=common').text
    check('セキュリティログは日本時間（JST）で表示', f'{jst} JST\t' in page and first[0] not in page)

    # --- Lockout settings ---------------------------------------------------------------------------
    settings = d.get('/admin/index.php?p=settings&section=common').text
    check('設定に「ログインの失敗とロック」（初期は 5 回・15 分）', '<option value="5" selected>5 回</option>' in settings and '<option value="900" selected>15 分</option>' in settings and '<option value="86400">24 時間</option>' in settings)
    save = lambda data: d.post('/admin/index.php', {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'login_guard', **data}, headers={'Accept': 'application/json'})
    check('範囲の外（2 回・10 分）は断る', save({'login_fail_max': '2', 'login_lock': '900'}).status == 422 and save({'login_fail_max': '5', 'login_lock': '600'}).status == 422)
    r = save({'login_fail_max': '3', 'login_lock': '3600'})
    check('3 回・1 時間に変えられる', r.status == 200 and json.loads(r.text).get('ok') and "'login_fail_max' => 3" in (site / 'data/config.php').read_text(encoding='utf-8'))
    x = h.Client(args.port)
    for i in range(3):
        page = x.get('/admin/index.php?k=' + key)
        r = x.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(page.text), 'password': 'wrong-password'})
    check('3 回間違えると、ログイン画面も 404', h.is_404(x.get('/admin/index.php?k=' + key)))
    ratelimit = site / 'data/ratelimit'
    bucket = lambda name: ratelimit / (hashlib.sha256(f'{name}|{h.HOST}'.encode()).hexdigest() + '.json')
    bucket('login').unlink()
    check('15 分の数え直しが過ぎても、1 時間のロックは続く', h.is_404(x.get('/admin/index.php?k=' + key)))
    log = (site / 'data/logs/security.log.php').read_text(encoding='utf-8')
    check('ロックしたことと設定の変更をセキュリティログに記録', 'login_locked\t60min' in log and 'settings_login_guard_changed\t3 / 60min' in log)
    bucket('login-lock').unlink()
    page = x.get('/admin/index.php?k=' + key)
    r = x.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(page.text), 'password': PASSWORD})
    check('ロックが明けると（data/ratelimit を消すと）ログインできる', r.status == 303)


def main():
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('entry-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, args.port)
    try:
        run(site)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
