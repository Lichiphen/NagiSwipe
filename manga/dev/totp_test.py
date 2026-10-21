#!/usr/bin/env python3
"""The two-step login (lib/totp.php, lib/qrcode.php) on a fresh install.

The QR code on the setup page is read back with OpenCV (cv2) and must give the same otpauth:// address;
the codes are made here with hmac (RFC 6238), so the server's own code is never trusted to check itself.
Usage: python manga/dev/totp_test.py
"""
import argparse, base64, hashlib, hmac, html, json, re, secrets, socket, struct, subprocess, sys, time, urllib.parse, zlib
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5440)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)
PASSWORD = 'log-test-password'
ISSUER = 'NagiSeries NagiLog'


def totp(secret, step):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    digest = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = digest[19] & 15
    return '%06d' % ((struct.unpack('>I', digest[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def server_code(secret, step):
    """The code lib/totp.php makes, run on its own with the PHP command line."""
    code = ("define('NAGIMANGA', 1); require 'manga/server/lib/totp.php'; echo nm_totp_code($argv[1], (int)$argv[2]);")
    return subprocess.run(['php', '-n', '-r', code, '--', secret, str(step)], cwd=ROOT, capture_output=True, text=True).stdout


def qr_text(page):
    """Draw the SVG's modules into an image and let OpenCV read it."""
    import numpy as np, cv2
    svg = re.search(r'<svg class="nm-qr".*?</svg>', page, re.S).group(0)
    box = int(re.search(r'viewBox="0 0 (\d+) ', svg).group(1))
    img = np.full((box, box), 255, np.uint8)
    for x, y in re.findall(r'M(\d+) (\d+)h1v1h-1z', svg): img[int(y), int(x)] = 0
    img = cv2.resize(img, None, fx=8, fy=8, interpolation=cv2.INTER_NEAREST)
    for det in (cv2.QRCodeDetectorAruco(), cv2.QRCodeDetector()):
        text, *_ = det.detectAndDecode(img)
        if text: return text
    return ''


def square_png(side=256):
    """An opaque square PNG for the LOG's favicon."""
    def chunk(t, data):
        c = struct.pack('>I', len(data)) + t + data
        return c + struct.pack('>I', zlib.crc32(t + data) & 0xFFFFFFFF)
    raw = b''.join(b'\x00' + bytes((40, 120, 200)) * side for _ in range(side))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', side, side, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


def login(port, key):
    c = h.Client(port)
    r = c.get('/admin/index.php?k=' + key)
    return c, c.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'password': PASSWORD})


def run(c, csrf, key):
    def post(data, client=c, token=None):
        return client.post('/admin/index.php', {'csrf': token or csrf, **data})
    def flash(client=c):
        return client.get('/admin/index.php?p=twofactor').text
    def step(): return int(time.time()) // 30
    other, _ = login(args.port, key)
    check('ほかの端末もログインしている', other.get('/admin/index.php?p=settings').status == 200)

    # --- Codes: RFC 6238 test vector and the same codes as Google Authenticator's algorithm ---------------
    rfc = base64.b32encode(b'12345678901234567890').decode()
    check('RFC 6238 の試験値（T=59 → 287082）と同じコード', server_code(rfc, 59 // 30) == '287082' and totp(rfc, 1) == '287082')
    check('RFC 6238 の試験値（T=1111111109 → 081804）', server_code(rfc, 1111111109 // 30) == '081804')
    sample = base64.b32encode(secrets.token_bytes(20)).decode()
    check('ランダムな鍵でも、HMAC-SHA1・6桁・30秒の標準どおりのコード', all(server_code(sample, s) == totp(sample, s) for s in (0, 1, 56789012, int(time.time()) // 30)))

    # --- Setup page ------------------------------------------------------------------------
    settings = c.get('/admin/index.php?p=settings&section=common').text
    check('設定の「共通・安全」に二段階認証の欄', 'id="twofactor"' in settings and 'index.php?p=twofactor' in settings and 'オフです' in settings)
    page = c.get('/admin/index.php?p=twofactor').text
    check('設定画面にQRコード（SVG）', '<svg class="nm-qr"' in page and 'role="img"' in page)
    check('QRコードの登録商標の表記', 'QRコードは株式会社デンソーウェーブの登録商標です。' in page)
    uri = html.unescape(re.search(r'<a class="btn" href="(otpauth://[^"]+)"', page).group(1))
    check('スマホ用に otpauth:// のボタン', uri.startswith('otpauth://totp/'))
    label = urllib.parse.urlsplit(uri).path.lstrip('/')
    check('ラベルは「NagiSeries NagiLog:ホスト名」（空白は%20、+は使わない）', label == f'NagiSeries%20NagiLog:{h.HOST}' and '+' not in uri)
    read = qr_text(page)
    check('QRコードを読むと同じ otpauth:// のアドレス', read == uri)
    q = urllib.parse.parse_qs(urllib.parse.urlsplit(uri).query)
    secret = q['secret'][0]
    check('鍵は160ビット（Base32で32文字）', re.fullmatch(r'[A-Z2-7]{32}', secret) is not None and q['issuer'] == [ISSUER] and q['digits'] == ['6'] and q['period'] == ['30'])
    image = q.get('image', [''])[0]
    check('ファビコンがないときは NagiManga のアイコン（版付きの絶対URL）を image= で渡す', re.fullmatch(rf'http://{re.escape(h.HOST)}:{args.port}/viewer/favicon\.svg\?v=[0-9a-f]+', image) is not None)
    check('渡したアイコンは開ける', c.get(urllib.parse.urlsplit(image).path + '?' + urllib.parse.urlsplit(image).query).status == 200)
    check('Google Authenticator の形式：ラベルの発行元と issuer= が同じ', urllib.parse.unquote(label.split(':', 1)[0]) == q['issuer'][0] and urllib.parse.urlsplit(uri).netloc == 'totp')
    check('設定画面に登録名・アカウント名とアイコンを表示', f'<strong>{ISSUER}</strong>' in page and f'<strong>{h.HOST}</strong>' in page and '<img src="../viewer/favicon.svg?v=' in page)
    check('手入力用に鍵を4文字ずつ表示', ' '.join(secret[i:i + 4] for i in range(0, 32, 4)) in page)
    check('入力欄は数字キーボードと one-time-code', 'inputmode="numeric" autocomplete="one-time-code"' in page)
    check('開き直しても同じ鍵（読み込み済みのアプリが使える）', secret in c.get('/admin/index.php?p=twofactor').text)
    r = c.post('/admin/index.php', {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'log', 'sections[]': 'log_design', 'title': 'テストLOG', 'name': 'わたし', 'theme': 'light-blue', 'description': ''},
               files={'favicon': ('icon.png', square_png(512), 'image/png')}, headers={'Accept': 'application/json'})
    page = c.get('/admin/index.php?p=twofactor').text
    image = urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(re.search(r'href="(otpauth://[^"]+)"', page).group(1))).query).get('image', [''])[0]
    m = re.fullmatch(rf'http://{re.escape(h.HOST)}:{args.port}/\?favicon=(\d+)&v=([0-9a-f]{{12}})', image)
    check('LOGのファビコンを設定すると、いちばん大きいPNGを image= で渡す', r.status == 200 and m is not None and m.group(1) == '512')
    got = c.get(f'/?favicon={m.group(1)}&v={m.group(2)}') if m else None
    check('渡したファビコンはPNGとして開ける', got is not None and got.status == 200 and got.getheader('Content-Type') == 'image/png')
    check('設定画面のアイコンもLOGのファビコン', m is not None and f'<img src="../?favicon={m.group(1)}&amp;v={m.group(2)}"' in page)

    # --- Turning it on ---------------------------------------------------------------------
    r = post({'do': 'totp_enable', 'current': PASSWORD, 'otp': '000000' if totp(secret, step()) != '000000' else '111111'})
    check('違う確認コードではオンにならない', r.status == 303 and '確認コードが違います' in flash() and 'オフです' in c.get('/admin/index.php?p=settings&section=common').text)
    r = post({'do': 'totp_enable', 'current': 'wrong-password', 'otp': totp(secret, step())})
    check('パスワードが違うとオンにならない', '管理者パスワードが違います' in flash())
    now = step()
    r = post({'do': 'totp_enable', 'current': PASSWORD, 'otp': totp(secret, now)})
    page = c.get('/admin/index.php?p=twofactor').text
    codes = re.findall(r'\b[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}\b', re.search(r'<pre class="nm-recovery-codes">(.*?)</pre>', page, re.S).group(1)) if 'nm-recovery-codes' in page else []
    check('正しいコードでオンになり、リカバリーコードを8個表示', r.status == 303 and len(codes) == 8 and 'から使っています' in page)
    check('リカバリーコードは一度だけ表示', 'nm-recovery-codes' not in c.get('/admin/index.php?p=twofactor').text)
    check('オンにした端末はログインしたまま', c.get('/admin/index.php?p=settings').status == 200)
    check('ほかの端末はログアウトされる', other.get('/admin/index.php?p=settings').status != 200)
    config = (site / 'data/config.php').read_text(encoding='utf-8')
    check('保存するのは鍵だけで、発行元の名前は保存しない（名前を変えても登録済みのコードは壊れない）', ISSUER not in config and 'NagiManga:' not in config)
    check('config.php に鍵をそのまま保存しない', secret not in config and "'totp'" in config and not any(x in config for x in codes))

    # --- Logging in ----------------------------------------------------------------------------
    d, r = login(args.port, key)
    check('パスワードのあとに確認コードの欄', r.status == 200 and 'name="otp"' in r.text and 'name="password"' not in r.text)
    check('コードを入れるまで管理画面は開かない', d.get('/admin/index.php?p=settings').status != 200)
    r = d.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': '12345'})
    check('桁の違うコードは断る', '確認コードが違います' in r.text and 'name="otp"' in r.text)
    r = d.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': totp(secret, now)})
    check('オンにしたときのコードはもう使えない（使い回し防止）', '確認コードが違います' in r.text)
    nxt = step() + 1
    wide = totp(secret, nxt).translate(str.maketrans('0123456789', '０１２３４５６７８９'))
    r = d.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': wide[:3] + ' ' + wide[3:]})
    check('全角数字・空白入りのコードでもログインできる（iPhoneの日本語キーボード）', r.status == 303 and d.get('/admin/index.php?p=settings').status == 200)

    e, r = login(args.port, key)
    r = e.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': totp(secret, nxt)})
    check('同じコードで二度目のログインはできない', '確認コードが違います' in r.text)
    r = e.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': codes[0].upper()})
    check('リカバリーコードでログインできる', r.status == 303 and e.get('/admin/index.php?p=settings').status == 200)
    check('リカバリーコードは残り7個', '残りのリカバリーコード：7 個' in e.get('/admin/index.php?p=twofactor').text)
    f, r = login(args.port, key)
    r = f.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': codes[0]})
    check('使ったリカバリーコードは二度と使えない', '確認コードが違います' in r.text)

    g = h.Client(args.port)
    r = g.get('/admin/index.php?k=' + key)
    r = g.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': totp(secret, step())})
    check('パスワードを飛ばしてコードだけ送ってもログインできない', '時間が過ぎました' in r.text and g.get('/admin/index.php?p=settings').status != 200)
    log = (site / 'data/logs/security.log').read_text(encoding='utf-8')
    check('セキュリティログに記録', all(x in log for x in ('totp_enabled', 'login_password_ok', 'totp_failed', 'login_ok_recovery', 'totp_recovery_used')))

    # --- New recovery codes, turning it off ---------------------------------------------------------
    r = post({'do': 'totp_recovery', 'current': PASSWORD, 'otp': codes[1]})
    page = c.get('/admin/index.php?p=twofactor').text
    fresh = re.findall(r'\b[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}\b', page)
    check('リカバリーコードを作り直せる', len(set(fresh)) == 8 and not set(fresh) & set(codes))
    c2, r = login(args.port, key)
    r = c2.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': codes[2]})
    check('作り直す前のリカバリーコードは使えない', '確認コードが違います' in r.text)
    r = post({'do': 'totp_disable', 'current': PASSWORD, 'otp': fresh[0]})
    check('リカバリーコードで二段階認証をやめられる', r.status == 303 and 'オフです' in c.get('/admin/index.php?p=settings&section=common').text)
    _, r = login(args.port, key)
    check('やめたあとはパスワードだけでログイン', r.status == 303)

    # --- Long site addresses (the image= field makes the QR code bigger) ----------------------------
    def with_base(path):
        post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'general', 'base_url': f'https://{h.HOST}/' + path, 'image_quality': '90', 'max_upload_mb': '30'})
        page = c.get('/admin/index.php?p=twofactor').text
        return page, html.unescape(re.search(r'href="(otpauth://[^"]+)"', page).group(1))
    page, uri = with_base('nagimanga/' + 'long-folder-name/' * 8)
    check('長いアドレスでもQRコードを出し、読むと同じアドレス（画像の欄つき）', len(uri) > 300 and 'image=' in uri and qr_text(page) == uri)
    page, uri = with_base('あ' * 150)
    check('QRコードに入らないほど長いときは、画像の欄を外してQRコードを出す', 'image=' not in uri and '<svg class="nm-qr"' in page and qr_text(page) == uri)
    post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'general', 'base_url': '', 'image_quality': '90', 'max_upload_mb': '30'})

    # --- Lockout ------------------------------------------------------------------------------------
    page = c.get('/admin/index.php?p=twofactor').text
    secret = urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(re.search(r'href="(otpauth://[^"]+)"', page).group(1))).query)['secret'][0]
    check('やめたあとは新しい鍵', secret not in config)
    post({'do': 'totp_enable', 'current': PASSWORD, 'otp': totp(secret, step())})
    x, r = login(args.port, key)
    for _ in range(5):
        r = x.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(r.text), 'otp': '000000' if totp(secret, step()) != '000000' else '999999'})
        if r.status == 404: break
    check('確認コードを何度も間違えると404（パスワードと同じロックアウト）', h.is_404(r))


def main():
    global site
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('totp-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, args.port)
    try:
        c, csrf, key = base.install(args.port)
        run(c, csrf, key)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
