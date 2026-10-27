#!/usr/bin/env python3
"""Passkey login (lib/passkey.php, admin/passkey.js's requests) on a fresh install.

A software authenticator made here with the cryptography package plays the passkey provider: it builds the
attestation object (fmt "none") and signs logins with an ES256 (P-256) or RS256 (RSA 2048) key, so lib/passkey.php is
checked against the WebAuthn rules, not against itself.
Usage: python manga/dev/passkey_test.py
"""
import argparse, base64, hashlib, hmac, html, json, re, secrets, socket, struct, sys, time, urllib.parse
from pathlib import Path
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
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
PASSWORD = 'log-test-password'
UP, UV, AT = 0x01, 0x04, 0x40


def b64u(raw): return base64.urlsafe_b64encode(raw).rstrip(b'=').decode()
def unb64u(text): return base64.urlsafe_b64decode(text + '=' * (-len(text) % 4))


def cbor(v):
    """Enough CBOR for attestation objects and COSE keys."""
    def head(major, n):
        if n < 24: return bytes([major << 5 | n])
        for info, size in ((24, 1), (25, 2), (26, 4), (27, 8)):
            if n < 1 << (8 * size): return bytes([major << 5 | info]) + n.to_bytes(size, 'big')
    if isinstance(v, bool): return bytes([0xF5 if v else 0xF4])
    if isinstance(v, int): return head(0, v) if v >= 0 else head(1, -1 - v)
    if isinstance(v, bytes): return head(2, len(v)) + v
    if isinstance(v, str): b = v.encode(); return head(3, len(b)) + b
    if isinstance(v, list): return head(4, len(v)) + b''.join(cbor(x) for x in v)
    if isinstance(v, dict): return head(5, len(v)) + b''.join(cbor(k) + cbor(x) for k, x in v.items())
    raise TypeError(v)


class Authenticator:
    """One passkey: its id, private key and signature counter (0 for synced passkeys such as iCloud and Proton Pass)."""
    def __init__(self, kind='es256', counting=False):
        self.kind, self.counting, self.count = kind, counting, 0
        self.id = secrets.token_bytes(32)
        self.key = ec.generate_private_key(ec.SECP256R1()) if kind == 'es256' else rsa.generate_private_key(public_exponent=65537, key_size=2048)
        self.handle = None

    def cose(self):
        pub = self.key.public_key().public_numbers()
        if self.kind == 'es256':
            return {1: 2, 3: -7, -1: 1, -2: pub.x.to_bytes(32, 'big'), -3: pub.y.to_bytes(32, 'big')}
        return {1: 3, 3: -257, -1: pub.n.to_bytes(256, 'big'), -2: pub.e.to_bytes(3, 'big')}

    def auth_data(self, rp, flags):
        if self.counting: self.count += 1
        return hashlib.sha256(rp.encode()).digest() + bytes([flags]) + struct.pack('>I', self.count)

    def create(self, options, origin, rp=None, flags=UP | UV | AT, ctype='webauthn.create', challenge=None):
        rp = rp or options['rp']['id']
        self.handle = options['user']['id']
        client = json.dumps({'type': ctype, 'challenge': challenge or options['challenge'], 'origin': origin, 'crossOrigin': False}).encode()
        data = self.auth_data(rp, flags) + bytes(16) + struct.pack('>H', len(self.id)) + self.id + cbor(self.cose())
        return {'clientDataJSON': b64u(client), 'attestationObject': b64u(cbor({'fmt': 'none', 'attStmt': {}, 'authData': data}))}

    def get(self, options, origin, rp=None, flags=UP | UV, ctype='webauthn.get', challenge=None, sign_with=None, handle=None):
        rp = rp or options['rpId']
        client = json.dumps({'type': ctype, 'challenge': challenge or options['challenge'], 'origin': origin, 'crossOrigin': False}).encode()
        data = self.auth_data(rp, flags)
        msg = data + hashlib.sha256(client).digest()
        key = sign_with or self.key
        sig = key.sign(msg, ec.ECDSA(hashes.SHA256())) if isinstance(key, ec.EllipticCurvePrivateKey) else key.sign(msg, padding.PKCS1v15(), hashes.SHA256())
        return {'id': b64u(self.id), 'clientDataJSON': b64u(client), 'authenticatorData': b64u(data), 'signature': b64u(sig), 'userHandle': handle if handle is not None else (self.handle or '')}


def totp(secret, step):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    digest = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = digest[19] & 15
    return '%06d' % ((struct.unpack('>I', digest[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def run(c, csrf, key):
    origin = f'http://{h.HOST}:{args.port}'
    def post(data, client=c):
        return client.post('/admin/index.php', {'csrf': csrf, **data}, headers={'Accept': 'application/json'})
    def options(password=PASSWORD):
        r = post({'do': 'passkey_options', 'current': password})
        return r, (json.loads(r.text).get('options') if r.status == 200 else None)
    def add(auth, name='', **kw):
        _, o = options()
        return post({'do': 'passkey_add', 'name': name, **auth.create(o, origin, **kw)})
    def login_page(client=None):
        client = client or h.Client(args.port)
        return client, client.get('/admin/index.php?k=' + key)
    def passkey_login(auth, client=None, land=None, **kw):
        client, page = login_page(client)
        token = h.csrf_of(page.text)
        extra = {'p': land} if land else {}
        r = client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'options', **extra})
        o = json.loads(r.text)['options']
        r = client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'verify', **extra, **auth.get(o, origin, **kw)})
        return client, r
    def logged_in(client): return client.get('/admin/index.php?p=settings').status == 200
    def ok_json(r): return r.status == 200 and json.loads(r.text).get('ok') is True

    # --- Before any passkey -------------------------------------------------------------------
    settings = c.get('/admin/index.php?p=settings&section=common').text
    check('設定の「共通・安全」にパスキーの欄', 'id="passkeys"' in settings and 'index.php?p=passkeys' in settings)
    page = c.get('/admin/index.php?p=passkeys').text
    form = re.search(r'<form class="form" data-passkey-register.*?</form>', page, re.S).group(0)
    check('名前の欄はパスワードの後ろで、パスワード管理アプリの自動入力から外す（Proton Pass などが名前を消さない）',
          form.index('name="current"') < form.index('name="passkey_label"') and 'autocomplete="username" readonly hidden' in form
          and re.search(r'name="passkey_label"[^>]*autocomplete="off"[^>]*data-protonpass-ignore="true"', form) is not None)
    check('パスキーの画面に登録フォームと passkey.js', 'data-passkey-register' in page and 'passkey.js?v=' in page and 'name="current"' in page)
    check('HTTPS でないアドレスでは注意を出す', 'HTTPS のアドレス' in page)
    _, lp = login_page()
    check('パスキーがないうちはログイン画面にボタンを出さない', 'data-passkey-login' not in lp.text and 'passkey.js' not in lp.text)

    # --- Adding --------------------------------------------------------------------------------
    r, _ = options('wrong-password')
    check('パスワードが違うと登録を始められない', r.status == 403 and '管理者パスワードが違います' in json.loads(r.text)['error'])
    a = Authenticator('es256')
    r = post({'do': 'passkey_add', 'name': 'x', **a.create({'challenge': b64u(b'x' * 32), 'rp': {'id': h.HOST}, 'user': {'id': 'u'}}, origin)})
    check('パスワードを確かめる前（チャレンジなし）の登録は断る', r.status == 422)
    r, o = options()
    check('登録用のオプション：rp・ユーザー・ES256/RS256・本人確認必須・attestation none', r.status == 200 and o['rp'] == {'id': h.HOST, 'name': 'NagiSeries NagiLog'}
          and [p['alg'] for p in o['pubKeyCredParams']] == [-7, -257] and o['authenticatorSelection']['userVerification'] == 'required'
          and o['authenticatorSelection']['residentKey'] == 'required' and o['attestation'] == 'none' and len(unb64u(o['challenge'])) == 32 and len(unb64u(o['user']['id'])) == 32)
    r = post({'do': 'passkey_add', 'name': 'iPhone', **a.create(o, 'https://evil.example')})
    check('別のオリジンで作ったパスキーは登録しない', r.status == 422)
    for label, kw in (('別のドメイン（rpId）', {'rp': 'evil.example'}), ('本人確認（UV）なし', {'flags': UP | AT}), ('種類が webauthn.get', {'ctype': 'webauthn.get'})):
        r = add(a, 'iPhone', **kw)
        check(f'{label}のパスキーは登録しない', r.status == 422)
    r = add(a, 'iPhone')
    check('ES256 のパスキーを登録できる', ok_json(r) and json.loads(r.text)['redirect'] == 'index.php?p=passkeys')
    page = c.get('/admin/index.php?p=passkeys').text
    check('一覧に名前と「まだ使っていません」', '<td>iPhone</td>' in page and 'まだ使っていません' in page and 'パスキー「iPhone」を登録しました' in page)
    _, o = options()
    check('2個目の登録では、登録済みのパスキーを excludeCredentials で伝える', [x['id'] for x in o['excludeCredentials']] == [b64u(a.id)])
    r = post({'do': 'passkey_add', 'name': 'again', **a.create(o, origin)})
    check('同じパスキーは二度登録しない', r.status == 422)
    r_ = Authenticator('rsa', counting=True)
    r = add(r_, '')
    check('RS256（Windows Hello など）のパスキーも登録でき、名前を空にすると「パスキー 2」', ok_json(r) and '<td>パスキー 2</td>' in c.get('/admin/index.php?p=passkeys').text)
    config = (site / 'data/config.php').read_text(encoding='utf-8')
    check('config.php には公開鍵だけを保存', "'passkeys'" in config and 'BEGIN PUBLIC KEY' in config and 'PRIVATE' not in config)

    # --- Logging in -----------------------------------------------------------------------------
    d, lp = login_page()
    check('ログイン画面に「パスキーでログイン」と passkey.js', 'data-passkey-login' in lp.text and 'passkey.js?v=' in lp.text and 'name="password"' in lp.text)
    r = d.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(lp.text), 'passkey': 'options'})
    o = json.loads(r.text)['options']
    check('ログイン用のオプション：このホスト・本人確認必須・鍵の一覧は渡さない', o['rpId'] == h.HOST and o['userVerification'] == 'required' and o['allowCredentials'] == [])
    d, r = passkey_login(a)
    check('ES256 のパスキーでログインできる', ok_json(r) and json.loads(r.text)['redirect'] == 'index.php' and logged_in(d))
    f, r = passkey_login(a, land='log')
    check('ログイン画面で NagiLog を選ぶと、パスキーのあと LOG・投稿の画面へ', ok_json(r) and json.loads(r.text)['redirect'] == 'index.php?p=log' and logged_in(f))
    e, r = passkey_login(r_)
    check('RS256 のパスキーでもログインできる', ok_json(r) and logged_in(e))
    page = c.get('/admin/index.php?p=passkeys').text
    check('最後に使った日が入る', 'まだ使っていません' not in page)

    fails = 0
    def bad(label, auth=a, **kw):
        nonlocal fails
        client, r = passkey_login(auth, **kw)
        check(f'{label}ではログインできない', r.status == 403 and not logged_in(client))
        fails += 1
        if fails % 4 == 0: passkey_login(a)  # a success clears the per-address lockout count
    client, page = login_page(); token = h.csrf_of(page.text)
    o = json.loads(client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'options'}).text)['options']
    r = client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'verify', **a.get(o, 'https://evil.example')})
    check('別のオリジンではログインできない', r.status == 403 and not logged_in(client)); fails += 1
    bad('別のドメインの rpId', rp='evil.example')
    bad('本人確認（UV）なし', flags=UP)
    bad('種類が webauthn.create', ctype='webauthn.create')
    bad('ほかの鍵での署名', sign_with=ec.generate_private_key(ec.SECP256R1()))
    bad('違うチャレンジ', challenge=b64u(secrets.token_bytes(32)))
    bad('登録していないパスキー', auth=Authenticator('es256'))
    bad('別のユーザーハンドル', handle=b64u(secrets.token_bytes(32)))
    # The same signed answer twice: the challenge is used once
    client, page = login_page(); token = h.csrf_of(page.text)
    o = json.loads(client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'options'}).text)['options']
    answer = a.get(o, origin)
    first = client.post('/admin/index.php', {'k': key, 'csrf': token, 'passkey': 'verify', **answer})
    other = h.Client(args.port); _, p2 = login_page(other)
    again = other.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(p2.text), 'passkey': 'verify', **answer})
    check('同じ答えの使い回し（チャレンジは一度きり）はできない', ok_json(first) and again.status == 403 and not logged_in(other))
    # Signature counter going back = a copied key
    r_.count = 0
    client, r = passkey_login(r_)
    check('署名カウンターが戻ったパスキー（複製の疑い）は断る', r.status == 403 and not logged_in(client))
    passkey_login(a)
    log = (site / 'data/logs/security.log.php').read_text(encoding='utf-8')
    check('セキュリティログに記録', all(x in log for x in ('passkey_added', 'login_ok_passkey', 'passkey_used', 'passkey_failed', 'passkey_counter')))

    # --- With the two-step login on, a passkey still logs in alone ---------------------------------
    page = c.get('/admin/index.php?p=twofactor').text
    secret = urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(re.search(r'href="(otpauth://[^"]+)"', page).group(1))).query)['secret'][0]
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'totp_enable', 'current': PASSWORD, 'otp': totp(secret, int(time.time()) // 30)})
    check('二段階認証をオンにした', 'から使っています' in c.get('/admin/index.php?p=twofactor').text)
    f, lp = login_page()
    r = f.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(lp.text), 'password': PASSWORD})
    check('パスワードでは、これまでどおり確認コードを聞く', 'name="otp"' in r.text and not logged_in(f))
    g, r = passkey_login(a)
    check('パスキーなら確認コードなしでログインできる', ok_json(r) and logged_in(g))

    # --- Public login (login.php) -------------------------------------------------------------
    pub = h.Client(args.port)
    page = pub.get('/admin/login.php')
    if page.status == 200:
        token = h.csrf_of(page.text)
        o = json.loads(pub.post('/admin/login.php', {'csrf': token, 'passkey': 'options'}).text)['options']
        r = pub.post('/admin/login.php', {'csrf': token, 'passkey': 'verify', **a.get(o, origin)})
        check('LOGの「ログイン」（login.php）からもパスキーでログインでき、LOGの管理へ', ok_json(r) and json.loads(r.text)['redirect'] == 'index.php?p=log' and logged_in(pub))
    else:
        check('LOGの「ログイン」（login.php）が開く', False)

    # --- The site-wide limit (40 failures an hour from everywhere) does not stop a passkey ------------------
    busy =site / 'data/ratelimit' / (hashlib.sha256(b'login-all|all').hexdigest() + '.json')
    busy.parent.mkdir(exist_ok=True)
    busy.write_text(json.dumps([int(time.time())] * 40), encoding='utf-8')
    f, lp = login_page()
    r = f.post('/admin/index.php', {'k': key, 'csrf': h.csrf_of(lp.text), 'password': PASSWORD})
    check('全体の上限の間、印のないブラウザーはパスワードでは入れない', 'しばらく' in r.text and not logged_in(f))
    g, r = passkey_login(a)
    check('全体の上限の間も、パスキーならログインできる', ok_json(r) and logged_in(g))
    busy.unlink()

    # --- Removing --------------------------------------------------------------------------------
    r = c.post('/admin/index.php', {'csrf': csrf, 'do': 'passkey_delete', 'id': b64u(r_.id), 'current': 'wrong-password'})
    check('パスワードが違うと削除しない', r.status == 303 and '<td>パスキー 2</td>' in c.get('/admin/index.php?p=passkeys').text)
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'passkey_delete', 'id': b64u(r_.id), 'current': PASSWORD})
    page = c.get('/admin/index.php?p=passkeys').text
    check('パスキーを削除できる', '<td>パスキー 2</td>' not in page and '<td>iPhone</td>' in page)
    client, r = passkey_login(r_)
    check('削除したパスキーではログインできない', r.status == 403 and not logged_in(client))
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'passkey_delete', 'id': b64u(a.id), 'current': PASSWORD})
    _, lp = login_page()
    check('すべて削除するとログイン画面のボタンも消える', 'data-passkey-login' not in lp.text)

    # --- Lockout -----------------------------------------------------------------------------------
    for _ in range(6):
        client, r = passkey_login(a)
        if r.status == 404: break
    check('失敗が続くと、パスワードと同じロックアウト（404）', h.is_404(r))


def main():
    global site
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('passkey-test-' + secrets.token_hex(4)); site = temp / 'site'
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
