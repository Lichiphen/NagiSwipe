#!/usr/bin/env python3
"""NagiScope (admin > NagiScope): off until turned on; once on, the LOG pages and the manga viewer report what readers do,
scope.php keeps only events about works, posts, pages, headings and embeds that exist, never for programs, the owner or
turned-away addresses; the dashboard, a work's and a post's screens, goals (counted back in time), the access list by IP,
the three IP settings, the page embed (game) checks, backup, turning off and deleting.
Usage: python manga/dev/scope_test.py
"""
import io, json, re, secrets, sys, time, zipfile
from pathlib import Path
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base

PORT = 5470
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
GAME = 'http://127.0.0.1:5479/game/index.html'
# Every client is a browser here: the admin session is tied to the browser, and a program's UA is never counted.
_request = h.Client.request
def _browser(self, method, path, body=None, headers=None, **kw):
    headers = dict(headers or {}); headers.setdefault('User-Agent', UA)
    return _request(self, method, path, body, headers, **kw)
h.Client.request = _browser
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def run(site):
    c, csrf, key = base.install(PORT)
    data_dir = site / 'data'
    raw_dir = data_dir / 'scope' / 'raw'
    post = lambda d, **kw: c.post('/admin/index.php', {'csrf': csrf, **d}, **kw)
    post({'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '10'})
    public = h.Client(PORT)
    beacon = lambda events, ua=UA, client=public: client.post('/scope.php', json.dumps(events, ensure_ascii=False).encode(), headers={'Content-Type': 'text/plain', 'User-Agent': ua})
    lines = lambda: [json.loads(l) for f in sorted(raw_dir.glob('*.jsonl.php')) for l in f.read_text(encoding='utf-8').splitlines()[1:]] if raw_dir.exists() else []

    body = '冒頭の文です。\n## はじめに\n' + 'あ' * 30 + '\n## 中盤の話\n' + 'い' * 30 + '\n[ページ埋込]' + GAME + '[/ページ埋込]\n## おわりに\nおしまい #テストタグ'
    pid = json.loads(post(base.payload(body=body)).text)['id']
    r = post({'do': 'create', 'title': '計測の漫画', 'direction': 'rtl'})
    wid = re.search(r'id=([A-Za-z0-9]{12})', r.getheader('Location') or '').group(1)
    for i in range(4):
        post({'do': 'upload', 'id': wid}, files={'page': (f'{i:03d}.png', h.png(60, 90, seed=i), 'image/png')}, headers={'X-NM-CSRF': csrf})

    # --- Off ------------------------------------------------------------------------------------------------
    works = c.get('/admin/index.php')
    check('OFF のあいだは作品一覧に「NagiScope を使ってみませんか？」', 'NagiScope を使ってみませんか？' in works.text and 'href="index.php?p=scope"' in works.text)
    check('ナビに NagiScope', '<span>NagiScope</span></a>' in works.text and 'href="index.php?p=scope"' in works.text)
    page = public.get('/?id=' + pid)
    check('OFF の記事ページは計測スクリプトを読まない', 'viewer/scope.js' not in page.text)
    check('OFF の漫画の目次は scope: 0', json.loads(public.get(f'/read.php?a=m&id={wid}').text)['scope'] == 0)
    r = beacon({'e': 'pv', 's': 'abcdefgh1234', 'k': 'p', 'i': pid})
    check('OFF のあいだ scope.php は何も記録しない', r.status == 204 and not lines())
    check('OFF の NagiScope 画面は始める画面（IP の選択つき）', 'name="ip" value="full"' in c.get('/admin/index.php?p=scope').text)

    # --- On (keep the address) -------------------------------------------------------------------------------
    r = post({'do': 'scope_enable', 'ip': 'full'})
    check('ON にできる', r.status == 303)
    check('ON にするとカードは消える', 'NagiScope を使ってみませんか？' not in c.get('/admin/index.php').text)
    page = public.get('/?id=' + pid)
    m = re.search(r'<script src="viewer/scope\.js\?v=[0-9a-f.]+" data-scope="([^"]+)"', page.text)
    check('ON の記事ページは計測スクリプト（版付きの URL）と記事の ID を持つ', m and json.loads(m.group(1).replace('&quot;', '"')) == {'k': 'p', 'i': pid})
    check('ログイン中の管理者が見る記事ページには計測スクリプトがない', 'viewer/scope.js' not in c.get('/?id=' + pid).text)
    check('ON の漫画の目次は scope: 1', json.loads(public.get(f'/read.php?a=m&id={wid}').text)['scope'] == 1)
    tag_page = public.get('/?tag=' + '%E3%83%86%E3%82%B9%E3%83%88%E3%82%BF%E3%82%B0')
    check('タグのページはタグ名を渡す', 'テストタグ' in (re.search(r'data-scope="([^"]+)"', tag_page.text) or [''])[0] if tag_page.status == 200 else False)

    s1, s2 = 'Sess0000Aaaa', 'Sess0000Bbbb'
    beacon([{'e': 'pv', 's': s1, 'k': 'p', 'i': pid, 'r': 'https://x.com/someone/status/1', 'tc': 0}])
    beacon({'e': 'lv', 's': s1, 'k': 'p', 'i': pid, 'y': 60, 'hi': 1, 'h': '中盤の話'})
    beacon({'e': 'lv', 's': s1, 'k': 'p', 'i': pid, 'y': 60, 'hi': 1, 'h': '本文にない見出し'})
    beacon({'e': 'ck', 's': s1, 'k': 'p', 'i': pid, 'u': 'https://hisukobari.itch.io/game'})
    for e in ('fv', 'fo', 'fl', 'fg', 'fs'): beacon({'e': e, 's': s1, 'k': 'p', 'i': pid, 'u': GAME})
    beacon({'e': 'ft', 's': s1, 'k': 'p', 'i': pid, 'u': GAME, 'ms': 95000})
    beacon({'e': 'fg', 's': s1, 'k': 'p', 'i': pid, 'u': 'https://evil.example/not-in-post'})
    beacon({'e': 'rd', 's': s1, 'k': 'p', 'i': pid})  # too early: does not count as read properly
    got = lines()
    kinds = [l['e'] for l in got]
    check('閲覧・読んだ位置・リンク・埋め込みの 6 段階を記録', kinds == ['pv', 'lv', 'ck', 'fv', 'fo', 'fl', 'fg', 'fs', 'ft', 'rd'])
    check('記事にない見出しと、記事にない埋め込みは記録しない', '本文にない見出し' not in json.dumps(got, ensure_ascii=False) and 'evil.example' not in json.dumps(got))
    check('「そのまま保存」では IP をそのまま記録', got[0].get('ip') == '127.0.0.1')
    check('来た場所は X、端末は PC、ブラウザーの種類だけを残す', got[0]['rc'] == 'x' and got[0]['d'] == 'pc' and got[0]['b'] == 'Chrome / Windows' and 'ua' not in got[0])
    raw_file = sorted(raw_dir.glob('*.jsonl.php'))[0]
    check('生のログは PHP で止まる先頭行つき（.htaccess が効かないサーバーでも中身を出さない）', raw_file.read_text(encoding='utf-8').startswith('<?php http_response_code(404); exit; __halt_compiler();'))
    check('生のログは Web から読めない', public.get('/data/scope/raw/' + raw_file.name).status in (403, 404))

    n = len(lines())
    beacon({'e': 'pv', 's': 'BotSession01', 'k': 'p', 'i': pid}, ua='Googlebot/2.1 (+http://www.google.com/bot.html) compatible crawler')
    beacon({'e': 'pv', 's': 'NoUaSession1', 'k': 'p', 'i': pid}, ua='')
    beacon({'e': 'pv', 's': 'OwnerSess001', 'k': 'p', 'i': pid}, client=c)
    beacon({'e': 'pv', 's': 'MissingPost1', 'k': 'p', 'i': '20990101000000'})
    beacon({'e': 'mo', 's': 'MissingWork1', 'k': 'm', 'i': 'AAAAAAAAAAAA'})
    beacon({'e': 'pv', 's': 'bad id!', 'k': 'p', 'i': pid})
    beacon({'e': 'zz', 's': 'BadEvent0001', 'k': 'p', 'i': pid})
    check('ロボット・UA なし・ログイン中の管理者・ない記事や作品・壊れた送信は記録しない', len(lines()) == n)

    # Manga: open, 4 pages, the end; a page past the end is refused.
    beacon([{'e': 'mo', 's': s2, 'k': 'm', 'i': wid, 'h': 'https://friend.example.net/blog/1', 'r': '', 'tc': 1}], ua=UA_PHONE)
    beacon([{'e': 'mp', 's': s2, 'k': 'm', 'i': wid, 'pg': 2, 'mx': 1, 'ls': 1, 'ms': 4000}], ua=UA_PHONE)
    beacon([{'e': 'mp', 's': s2, 'k': 'm', 'i': wid, 'pg': 9, 'mx': 9, 'ls': 9, 'ms': 4000}], ua=UA_PHONE)
    beacon([{'e': 'me', 's': s2, 'k': 'm', 'i': wid, 'sk': 0}, {'e': 'mp', 's': s2, 'k': 'm', 'i': wid, 'pg': 4, 'mx': 3, 'ls': 3, 'ms': 12000}], ua=UA_PHONE)
    manga = [l for l in lines() if l['k'] == 'm']
    check('漫画: 開いた・進み・読了を記録し、ないページ番号は記録しない', [l['e'] for l in manga] == ['mo', 'mp', 'me', 'mp'])
    check('漫画: ほかのサイトに置いた漫画は、そのサイトを来た場所にする・スマホ', manga[0]['rc'] == 'site' and manga[0]['rd'] == 'friend.example.net' and manga[0]['d'] == 'mobile')

    # Likes are recorded by like.php itself.
    public.post('/like.php', json.dumps({'id': pid, 'n': 2}).encode(), headers={'Content-Type': 'application/json', 'X-NagiLog': 'like', 'User-Agent': UA})
    check('いいねは like.php が記録する', [l['e'] for l in lines()][-1] == 'lk')

    # Lines that do not fit their visit: a manga visit's id used for a post's reading position, another visitor's id,
    # and an early "read properly" from a visitor who just opened the post. None may break the counting or reach goals.
    UA_FX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0'
    beacon([{'e': 'mo', 's': 'Mixed0000001', 'k': 'm', 'i': wid, 'h': '', 'r': ''}, {'e': 'lv', 's': 'Mixed0000001', 'k': 'p', 'i': pid, 'y': 10, 'hi': -1, 'h': ''}])
    beacon([{'e': 'pv', 's': 'Early0000001', 'k': 'p', 'i': pid}, {'e': 'rd', 's': 'Early0000001', 'k': 'p', 'i': pid}], ua=UA_FX)
    beacon({'e': 'lv', 's': 'NoSuchVisit1', 'k': 'p', 'i': pid, 'y': 99, 'hi': 2, 'h': 'おわりに'}, ua=UA_FX)
    beacon({'e': 'me', 's': 'NoSuchVisit2', 'k': 'm', 'i': wid, 'sk': 0}, ua=UA_FX)
    beacon([{'e': 'pv', 's': 'TooMany00001', 'k': 'l', 'i': ''}] * 6)
    check('1 回の送信は 5 件まで', not any(l['s'] == 'TooMany00001' for l in lines()))
    # Likes past the day's limit (100 a person) are not counted.
    public.post('/like.php', json.dumps({'id': pid, 'n': 100}).encode(), headers={'Content-Type': 'application/json', 'X-NagiLog': 'like'})
    n = len(lines())
    public.post('/like.php', json.dumps({'id': pid, 'n': 5}).encode(), headers={'Content-Type': 'application/json', 'X-NagiLog': 'like'})
    check('1日の上限を超えたいいねは記録しない・実際に足した数だけ記録する', len(lines()) == n and lines()[-1]['n'] == 98)

    # Read properly: the page must stay open long enough for its length (at least 10 seconds; 80% allowed).
    time.sleep(11)
    beacon({'e': 'rd', 's': s1, 'k': 'p', 'i': pid})

    # --- Goals (made afterwards, counted back) ---------------------------------------------------------------
    r = post({'do': 'scope_goal_save', 'name': 'itch.io へ行った', 'kind': 'click', 'how': 'contains', 'match': 'itch.io/'})
    check('リンクのゴールを作れる', r.status == 303)
    post({'do': 'scope_goal_save', 'name': 'ゲームを始めた', 'kind': 'game', 'target_game': ''})
    post({'do': 'scope_goal_save', 'name': '漫画を読み終えた', 'kind': 'end', 'target_item': 'm:' + wid})
    post({'do': 'scope_goal_save', 'name': '記事を読み終えた', 'kind': 'end', 'target_item': 'p:' + pid})
    post({'do': 'scope_goal_save', 'name': '無効', 'kind': 'page', 'target_item': 'p:20990101000000'})
    goals = c.get('/admin/index.php?p=scope&view=goals')
    check('ない記事を選んだゴールは作られない', '無効' not in goals.text.split('ゴールを作る')[0])
    results = re.findall(r'達成 <strong>(\d+)</strong> 人', goals.text)
    check('後から作ったゴールも、過去の記録から数える（リンク・ゲーム・漫画の読了・記事の読了）', results == ['1', '1', '1', '1'])
    check('達成した人がどこから来たか（X）', 'X（Twitter）' in goals.text)

    # --- Dashboard ---------------------------------------------------------------------------------------------
    dash = c.get('/admin/index.php?p=scope&site=l')
    data = json.loads(re.search(r'<script type="application/json" id="ns-data"[^>]*>(.*?)</script>', dash.text, re.S).group(1))
    year = json.loads(c.get('/admin/index.php?p=scope&view=period&site=l&tab=year').text)
    check('1年タブは開いたときに取りに行く（最初の画面には入れない）', 'year' not in data and len(year['data']['points']) == 52 and year['data']['visitors'] == data['month']['visitors'] and 'data-ns-period="year"' in year['html'])
    data['year'] = year['data']
    check('ダッシュボード: 点の数（24・30・52）', len(data['day']['points']) == 24 and len(data['month']['points']) == 30 and len(data['year']['points']) == 52)
    check('ダッシュボード: きちんと読んだ人 1 人（開いてすぐの「読んだ」は数えない）・いいね 100', data['month']['rate'] == '50.0%' and data['month']['likes'] == 100)
    check('ダッシュボード: 別の人の閲覧に紛れ込ませた記録・種類の違う記録でも集計が止まらない', data['month']['visitors'] == 2)
    check('ダッシュボード: 来た場所に X、埋め込みのゲームの起動を確認', 'X（Twitter）' in dash.text and '起動を確認' in dash.text and '遊んだ時間の平均: 1 分 35 秒' in dash.text)
    check('ダッシュボード: 「セッション」「直帰率」という言葉を使わない', 'セッション' not in dash.text and '直帰率' not in dash.text)
    dash_m = c.get('/admin/index.php?p=scope&site=m')
    dm = json.loads(re.search(r'id="ns-data"[^>]*>(.*?)</script>', dash_m.text, re.S).group(1))
    check('ダッシュボード: 漫画は 2 人・読まれたページ 4・最後まで読んだ人 50%（開いていない閲覧の「読み終えた」は数えない）', dm['month']['visitors'] == 2 and dm['month']['pages'] == 4 and dm['month']['rate'] == '50.0%')
    item = c.get(f'/admin/index.php?p=scope&view=item&key=m:{wid}')
    check('作品の画面: 何ページ目でやめたか（サムネイル付き）', '何ページ目でやめたか' in item.text and f'p=img&amp;id={wid}' in item.text and '100.0%' in item.text)
    item = c.get(f'/admin/index.php?p=scope&view=item&key=p:{pid}')
    check('記事の画面: 見出しの名前で「半分が離脱」（開いていない閲覧の位置は混ざらない）', '『中盤の話』の辺りで半分が離脱しています' in item.text and '>おわりに<' not in item.text)

    # --- Access list, escaping, deny ------------------------------------------------------------------------------
    beacon({'e': 'pv', 's': 'XssSession01', 'k': 'l', 'i': '', 'r': 'https://evil.example/<script>alert(1)</script>', 'q': '<img src=x onerror=alert(1)>'})
    access = c.get('/admin/index.php?p=scope&view=access&ip=127.0.0.1')
    check('アクセスの一覧: IP で絞り込める', access.status == 200 and '127.0.0.1' in access.text and '拒否リストに入れる' in access.text)
    check('訪問者が送った文字はエスケープして表示', '<script>alert(1)' not in access.text and '<img src=x' not in access.text)
    dash = c.get('/admin/index.php?p=scope&site=l')
    check('検索語もエスケープして表示', '<img src=x' not in dash.text and '&lt;img src=x onerror=alert(1)&gt;' in dash.text)
    post({'do': 'scope_deny', 'ip': '127.0.0.1'})
    check('拒否リストに入れたアドレスからは LOG・漫画が 404', public.get('/').status == 404 and public.get(f'/read.php?a=m&id={wid}').status == 404)
    post({'do': 'scope_settings', 'on': '1', 'ip': 'full', 'raw_months': '0', 'denied_ips': ''})
    check('拒否リストから外すと戻る', public.get('/').status == 200)

    # --- Hidden / no addresses ----------------------------------------------------------------------------------
    post({'do': 'scope_settings', 'on': '1', 'ip': 'partial', 'raw_months': '0', 'denied_ips': ''})
    beacon({'e': 'pv', 's': 'Partial00001', 'k': 'l', 'i': ''})
    check('「一部を伏せて保存」では 127.0.0.xxx', lines()[-1].get('ip') == '127.0.0.xxx')
    post({'do': 'scope_settings', 'on': '1', 'ip': 'none', 'raw_months': '0', 'denied_ips': ''})
    beacon({'e': 'pv', 's': 'NoIp00000001', 'k': 'l', 'i': ''})
    check('「保存しない」では IP を残さない', 'ip' not in lines()[-1] and len(lines()[-1]['v']) == 16)
    settings = c.get('/admin/index.php?p=scope&view=settings')
    check('設定: 容量・文例（日英）・GDPR の注記・データを消すボタン', '使っている容量' in settings.text and 'Access analytics' in settings.text and 'GDPR' in settings.text and 'scope_delete' in settings.text)

    # --- A visit that crosses midnight (Japan time): opened at 23:59:50, read properly at 0:00:30 -----------------------
    import datetime
    jst = datetime.timezone(datetime.timedelta(hours=9))
    midnight = int(datetime.datetime.now(jst).replace(hour=0, minute=0, second=0, microsecond=0).timestamp())
    today = datetime.datetime.now(jst).strftime('%Y-%m-%d')
    raw_now = sorted(raw_dir.glob('*.jsonl.php'))[-1]
    with open(raw_now, 'a', encoding='utf-8', newline='\n') as f:
        f.write(json.dumps({'t': midnight - 10, 'e': 'pv', 'v': 'aaaaaaaaaaaaaaaa', 's': 'Midnight0001', 'k': 'p', 'i': pid, 'rc': 'direct', 'd': 'pc', 'need': 10}) + '\n')
        f.write(json.dumps({'t': midnight + 30, 'e': 'rd', 'v': 'bbbbbbbbbbbbbbbb', 's': 'Midnight0001', 'k': 'p', 'i': pid}) + '\n')
    c.get('/admin/index.php?p=scope')
    daily = json.loads((data_dir / 'scope' / 'daily' / f'{today}.json.php').read_text(encoding='utf-8').split('\n', 1)[1])
    check('午前0時をまたいだ閲覧も「きちんと読んだ」に数え、今日の読者にも入れる', 'Midnight' in json.dumps(daily.get('_s', {})) and daily['_v'].get('bbbbbbbbbbbbbbbb', {}).get('it', {}).get('p:' + pid) == 1)

    # --- Backup ------------------------------------------------------------------------------------------------
    r = post({'do': 'scope_backup'})
    backup_noraw = r.body
    names = zipfile.ZipFile(io.BytesIO(r.body)).namelist() if r.status == 200 else []
    check('バックアップ: 生のログを含めない選択', any(n.startswith('daily/') for n in names) and not any(n.startswith('raw/') for n in names))
    r = post({'do': 'scope_backup', 'raw': '1'})
    backup = r.body
    check('バックアップ: 生のログも含められる', any(n.startswith('raw/') for n in zipfile.ZipFile(io.BytesIO(backup)).namelist()))

    # --- Off keeps the data; deleting asks first --------------------------------------------------------------------
    post({'do': 'scope_settings', 'ip': 'none', 'raw_months': '0', 'denied_ips': ''})
    n = len(lines())
    beacon({'e': 'pv', 's': 'OffAgain0001', 'k': 'l', 'i': ''})
    check('OFF に戻すと記録しない・データは残る', len(lines()) == n and n > 0 and 'viewer/scope.js' not in public.get('/?id=' + pid).text)
    month_before = json.loads(re.search(r'id="ns-data"[^>]*>(.*?)</script>', c.get('/admin/index.php?p=scope&site=l').text, re.S).group(1))['month']
    post({'do': 'scope_delete'})
    check('確認のチェックがないと消さない', len(lines()) == n)
    post({'do': 'scope_delete', 'sure': '1'})
    check('確認して消すと、生のログと集計が消える（ゴールは残る）', not lines() and not list((data_dir / 'scope' / 'daily').glob('*')) and (data_dir / 'scope' / 'goals.json.php').exists())
    r = post({'do': 'scope_restore', 'overwrite': '1'}, files={'backup': ('scope.zip', backup, 'application/zip')})
    check('バックアップから復元できる', r.status == 303 and len(lines()) > 0)
    month_after = json.loads(re.search(r'id="ns-data"[^>]*>(.*?)</script>', c.get('/admin/index.php?p=scope&site=l').text, re.S).group(1))['month']
    check('復元しても二重に数えない', [month_after[k] for k in ('visitors', 'pages', 'likes', 'rate')] == [month_before[k] for k in ('visitors', 'pages', 'likes', 'rate')])
    post({'do': 'scope_delete', 'sure': '1'})
    post({'do': 'scope_restore'}, files={'backup': ('noraw.zip', backup_noraw, 'application/zip')})
    post({'do': 'scope_settings', 'on': '1', 'ip': 'none', 'raw_months': '0', 'denied_ips': ''})
    day_before = json.loads(re.search(r'id="ns-data"[^>]*>(.*?)</script>', c.get('/admin/index.php?p=scope&site=l').text, re.S).group(1))['day']['visitors']
    beacon({'e': 'pv', 's': 'AfterRestore', 'k': 'l', 'i': ''}, ua='Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15')
    day_after = json.loads(re.search(r'id="ns-data"[^>]*>(.*?)</script>', c.get('/admin/index.php?p=scope&site=l').text, re.S).group(1))['day']['visitors']
    check('生のログなしのバックアップを空の状態へ復元しても、そのあとの記録を数える', day_after == day_before + 1 and day_before >= 1)
    bad = io.BytesIO(); z = zipfile.ZipFile(bad, 'w'); z.writestr('nagiscope-backup.json', '{}'); z.writestr('../config.php', '<?php'); z.writestr('raw/2026-01.jsonl.php', 'not guarded'); z.close()
    post({'do': 'scope_restore', 'overwrite': '1'}, files={'backup': ('evil.zip', bad.getvalue(), 'application/zip')})
    check('復元は決まった名前と形のファイルだけ', not (data_dir / 'scope' / 'raw' / '2026-01.jsonl.php').exists() and not (site / 'config.php').exists())

    # --- English admin: every NagiScope text is translated (only what readers wrote stays Japanese) ---------------
    post({'do': 'scope_settings', 'on': '1', 'ip': 'full', 'raw_months': '0', 'denied_ips': ''})
    post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'admin_lang', 'admin_lang': 'en'})
    c.get('/admin/index.php?p=scope')  # the notices of the forms above, written in Japanese, are shown once here
    left = set()
    for url in ['', '&site=l', '&view=goals', '&view=access', '&view=settings', f'&view=item&key=m:{wid}', f'&view=item&key=p:{pid}']:
        text = c.get('/admin/index.php?p=scope' + url).text
        text = re.sub(r'<script.*?</script>|<textarea.*?</textarea>|<option.*?</option>|<[^>]+>', ' ', text, flags=re.S)
        left |= set(re.findall(r'[぀-ヿ一-鿿][^\s]*', text))
    left -= {'計測の漫画', '冒頭の文です。', 'はじめに', '中盤の話', 'おわりに', '「計測の漫画」', '「冒頭の文です。」', '#テストタグ', 'テストタグ', 'へ行った', 'ゲームを始めた', '漫画を読み終えた', '記事を読み終えた', '冒頭の文です。」', '計測の漫画」'}
    check('英語の管理画面に日本語が残らない（読者が書いたものを除く）' + (' ' + ' '.join(sorted(left)) if left else ''), not left)
    post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'admin_lang', 'admin_lang': 'ja'})


def main():
    root = base.RESULTS / ('scope-test-' + secrets.token_hex(4))
    root.mkdir(parents=True)
    site = root / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, PORT)
    try:
        run(site)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__':
    main()
