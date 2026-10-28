#!/usr/bin/env python3
"""Access analytics (Settings > common): the usual tags are kept and reach the public LOG and the manga page with the
Content-Security-Policy they need; code that could harm visitors is refused with its reason (the screen shows it as a toast)
and changes nothing; the owner's own visits are not counted.

The harmful samples are put together when the test runs, so the file itself does not look like an attack to virus scanners.
Usage: python manga/dev/analytics_test.py
"""
import argparse, base64, hashlib, json, re, secrets, socket, sys
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5460)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)

GA4 = '''<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-TEST123');
</script>'''
MATOMO = '''<!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="//matomo.example.com/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '1']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<noscript><p><img src="//matomo.example.com/matomo.php?idsite=1&amp;rec=1" style="border:0;" alt="" /></p></noscript>
<!-- End Matomo Code -->'''
S = lambda *parts: '<' + 'script>' + ''.join(parts) + '</' + 'script>'
SRC = lambda url, more='': '<' + 'script src="' + url + '"' + more + '></' + 'script>'
HARMFUL = {
    'Cookie を読む': (S('var a=document', '.', 'cookie;'), 'Cookie'),
    'ほかのサイトへ飛ばす': (S('location', '.href=', "'https://example.net/'"), 'ほかのページへ移動'),
    'キー入力を見張る': (S('document.addEventListener(', "'key", "down'", ',function(e){})'), 'キー入力'),
    '難読化したコード': (S('var a=at', 'ob(', "'eA=='", ');'), '難読化'),
    'マイニング': (SRC('https://example.net/coin' + 'hive.min.js'), 'マイニング'),
    'iframe を差し込む': ('<' + 'iframe src="https://example.net/"></' + 'iframe>', 'iframe'),
    'イベント属性': (SRC('https://a.example.com/x.js', ' on' + 'load="x()"'), 'onload'),
    'http の読み込み': (SRC('http://a.example.com/x.js'), 'https://'),
}


def csp_of(res):
    return {d.split(' ', 1)[0]: d for d in (x.strip() for x in (res.getheader('Content-Security-Policy') or '').split(';')) if d}


def inline_hashes(html):
    return {"'sha256-" + base64.b64encode(hashlib.sha256(m.encode()).digest()).decode() + "'" for m in re.findall(r'<script>(.*?)</script>', html, re.S)}


def run(site):
    c, csrf, key = base.install(args.port)
    page = c.get('/admin/index.php?p=settings&section=common')
    check('設定の「共通・安全」にアクセス解析の欄', 'id="analytics"' in page.text and 'name="analytics"' in page.text and 'data-settings-section="analytics"' in page.text)
    csrf = h.csrf_of(page.text)
    def save(code, json_reply=True):
        return c.post('/admin/index.php', {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'analytics', 'analytics': code}, headers={'Accept': 'application/json'} if json_reply else {})
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '10'})
    public = h.Client(args.port)

    r = save(GA4.replace('\n', '\r\n'))
    check('Google アナリティクスのタグを保存できる', r.status == 200 and json.loads(r.text)['ok'])
    r = public.get('/')
    csp = csp_of(r)
    check('公開LOGに gtag.js の読み込みと設定のコード', '<script src="https://www.googletagmanager.com/gtag/js?id=G-TEST123" async></script>' in r.text and "gtag('config', 'G-TEST123');" in r.text)
    check('コメントは出さない', 'Google tag (gtag.js)' not in r.text)
    check('CSP の script-src に Google のサイトとインラインのハッシュ（改行は LF で計算）', 'https://www.googletagmanager.com' in csp['script-src'] and all(x in csp['script-src'] for x in inline_hashes(r.text)) and '\r' not in r.text.split('<script>', 1)[1].split('</script>', 1)[0])
    check('CSP の connect-src・img-src に計測の送り先', 'https://*.google-analytics.com' in csp['connect-src'] and 'https://*.google-analytics.com' in csp['img-src'])
    check('ほかの指定は変わらない（default-src・object-src・frame-ancestors）', csp['default-src'] == "default-src 'self'" and csp['object-src'] == "object-src 'none'" and csp['frame-ancestors'] == "frame-ancestors 'none'")
    owner = c.get('/')
    check('ログイン中の自分が見る LOG には入れない', 'googletagmanager' not in owner.text and 'googletagmanager' not in (owner.getheader('Content-Security-Policy') or ''))
    page = c.get('/admin/index.php?p=settings&section=common')
    check('設定画面に保存したコードと許可しているサイト', 'G-TEST123' in page.text and '今許可しているサイト' in page.text)
    check('管理画面にはタグを入れない', '<script src="https://www.googletagmanager.com' not in page.text and 'googletagmanager' not in (page.getheader('Content-Security-Policy') or ''))

    r = save(MATOMO)
    check('Matomo のタグを保存できる', r.status == 200 and json.loads(r.text)['ok'])
    r = public.get('/')
    csp = csp_of(r)
    check('Matomo のサーバーを script-src・connect-src・img-src に', all('https://matomo.example.com' in csp[k] for k in ('script-src', 'connect-src', 'img-src')))
    check('noscript の部分は入れない', 'matomo.php?idsite' not in r.text and "_paq.push(['trackPageView']);" in r.text)
    check('Google のサイトはもう許可しない', 'googletagmanager' not in csp['script-src'])

    # The manga page (read.php?nagimanga=…) carries the same tag.
    created = c.post('/admin/index.php', {'csrf': csrf, 'do': 'create', 'title': '計測テスト', 'direction': 'rtl'})
    wid = re.search(r'id=([A-Za-z0-9]{12})', created.getheader('Location')).group(1)
    c.post('/admin/index.php', {'csrf': csrf, 'do': 'upload', 'id': wid}, files={'page': ('001.png', h.png(80, 120), 'image/png')})
    r = public.get('/read.php?nagimanga=' + wid)
    csp = csp_of(r)
    check('漫画の作品ページにもタグと CSP', r.status == 200 and "_paq.push(['trackPageView']);" in r.text and 'https://matomo.example.com' in csp['script-src']
          and all(x in csp['script-src'] for x in inline_hashes(r.text)) and 'https://matomo.example.com' in csp['connect-src'])

    for name, (code, word) in HARMFUL.items():
        r = save(code)
        data = json.loads(r.text)
        check(f'害のあるコードを拒否: {name}（理由つきで、どの設定も保存しない）', r.status == 422 and not data['ok'] and data['section'] == 'analytics' and word in data['error'] and '「アクセス解析」' in data['error'])
    check('拒否したあとも前のタグのまま', "_paq.push(['trackPageView']);" in public.get('/').text and 'example.net' not in (site / 'data/config.php').read_text(encoding='utf-8'))
    check('JavaScript なしの保存でも拒否してメッセージ', save(HARMFUL['Cookie を読む'][0], False).status == 303
          and 'Cookie を読み書きするコードは入れられません' in c.get('/admin/index.php?p=settings&section=common').text)

    # A tag written into config.php by hand that does not pass (or one an older version kept) never reaches the page.
    cfg = site / 'data/config.php'
    text = cfg.read_text(encoding='utf-8')
    cfg.write_text(re.sub(r"'analytics' => '(?:[^'\\]|\\.)*'", lambda m: "'analytics' => '" + HARMFUL['Cookie を読む'][0] + "'", text), encoding='utf-8')
    r = public.get('/')
    check('設定ファイルを直接書き換えた害のあるコードも出さない', r.status == 200 and 'cookie' not in r.text and 'matomo' not in csp_of(r)['script-src'])
    cfg.write_text(text, encoding='utf-8')

    # The SEO tab: search engines and sitemap, ownership checks and analytics together.
    page = c.get('/admin/index.php?p=settings&section=seo')
    seo = page.text.split('data-settings-panel="seo"', 1)[1].split('data-settings-panel=', 1)[0]
    check('「SEO・アクセス解析」のタブに、検索エンジン・所有権の確認・アクセス解析をまとめる', 'data-settings-tab="seo" aria-current="page"' in page.text
          and all(x in seo for x in ('id="log-seo"', 'id="search-verify"', 'id="analytics"')) and 'id="analytics"' not in page.text.split('data-settings-panel="common"', 1)[1])
    def verify(**fields):
        body = {'csrf': csrf, 'do': 'settings_save_all', 'tab': 'seo', 'sections[]': 'search_verify', 'verify_google': '', 'verify_bing': ''}
        body.update(fields)
        return c.post('/admin/index.php', body, headers={'Accept': 'application/json'})
    g = 'AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789-ab'
    r = verify(verify_google='<meta name="google-site-verification" content="' + g + '" />', verify_bing='0123456789ABCDEF0123456789ABCDEF')
    head = public.get('/').text.split('</head>', 1)[0]
    check('Search Console の meta タグ（貼ったタグから確認コードを取り出す）と Bing のコードを保存して head に出す', json.loads(r.text)['ok']
          and f'<meta name="google-site-verification" content="{g}">' in head and '<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">' in head)
    r = verify(verify_google='google-site-verification=' + g)
    check('DNS の TXT レコードの形（google-site-verification=…）でも受け付ける', json.loads(r.text)['ok'] and 'msvalidate.01' not in public.get('/').text)
    r = verify(verify_google='<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">')
    data = json.loads(r.text)
    check('ほかのサービスの meta タグは理由つきで拒否', r.status == 422 and data['section'] == 'search_verify' and 'Google Search Console の meta タグではありません' in data['error'])
    r = verify(verify_bing='"><' + 'script>x</' + 'script>')
    check('形の合わない確認コードは拒否して、前のコードのまま', r.status == 422 and '確認コードが正しくありません' in json.loads(r.text)['error'] and g in public.get('/').text)
    verify()
    check('空欄で保存すると meta タグを出さない', 'google-site-verification' not in public.get('/').text)

    r = save('')
    check('空欄で保存すると使わない', json.loads(r.text)['ok'] and 'matomo' not in public.get('/').text and 'matomo' not in (public.get('/').getheader('Content-Security-Policy') or ''))
    log = (site / 'data/logs/security.log.php').read_text(encoding='utf-8')
    check('変更をセキュリティログに記録', 'settings_analytics_changed\thttps://www.googletagmanager.com' in log and 'settings_analytics_changed\toff' in log)


def main():
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('analytics-test-' + secrets.token_hex(4)); site = temp / 'site'
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
