#!/usr/bin/env python3
"""Page embeds ([ページ埋込]URL[/ページ埋込]) must not offer to open a target that is really a file download
(a program, installer or archive) inside a frame. When the card fetch finds such a target -- by its media type,
its Content-Disposition, or the file name at the end of the (redirected) address -- the cover becomes a plain
outside link with a warning, carries no data-frame-src, and embed.php refuses to frame it even with a valid
signature from an older page. A normal web page is still loadable as before.

Usage: python manga/dev/log_embed_guard_test.py
"""
import argparse, http.server, json, os, secrets, socket, subprocess, sys, threading, urllib.parse
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5471)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
import attack_test as h
import log_test as base

checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)

TARGET_PORT = args.port + 10
PAGE = ('<!doctype html><html><head><meta charset="utf-8"><title>ゲーム</title>'
        '<meta property="og:title" content="遊べるゲーム"></head><body>game</body></html>').encode()


class Target(http.server.BaseHTTPRequestHandler):
    """A site the owner might point a page embed at: one real page, the rest file downloads."""
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def send(self, status, body, ctype, extra=None):
        self.send_response(status); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(body)))
        for k, v in (extra or {}).items(): self.send_header(k, v)
        self.send_header('Connection', 'close'); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        if self.path == '/game.html': self.send(200, PAGE, 'text/html; charset=UTF-8')
        elif self.path == '/malware': self.send(200, b'MZ\x90\x00binary', 'application/octet-stream')
        elif self.path == '/installer.exe': self.send(200, PAGE, 'text/html; charset=UTF-8')  # a page, but the name is a program
        elif self.path == '/archive.zip': self.send(200, b'PK\x03\x04zip', 'application/zip')
        elif self.path == '/report': self.send(200, PAGE, 'text/html; charset=UTF-8', {'Content-Disposition': 'attachment; filename="report.pdf"'})
        else: self.send(404, b'nope', 'text/plain')


def php_eval(site, code):
    """Run PHP with the LOG stack loaded, keeping NAGIMANGA_CARD_ALLOW_LOCAL so it may fetch the local target."""
    prefix = "define('NAGIMANGA',true); require '" + (site / 'lib/bootstrap.php').as_posix() + "'; require '" + (site / 'lib/log.php').as_posix() + "'; "
    cmd = h.php_cmd(0, False, site); cmd = cmd[:cmd.index('-S')] + ['-r', prefix + code]
    env = dict(os.environ); env['NAGIMANGA_DATA'] = str(site / 'data'); env['NAGIMANGA_CARD_ALLOW_LOCAL'] = '1'
    r = subprocess.run(cmd, env=env, capture_output=True, text=True, encoding='utf-8')
    if not r.stdout.strip():
        raise RuntimeError(r.stderr)
    return json.loads(r.stdout)


def run(site):
    public = h.Client(args.port)
    base_url = f'http://127.0.0.1:{TARGET_PORT}'
    code = f"""
$b='{base_url}'; $dl=microtime(true)+8;
$urls=['safe'=>$b.'/game.html','type'=>$b.'/malware','ext'=>$b.'/installer.exe','zip'=>$b.'/archive.zip','disp'=>$b.'/report'];
$o=[];
foreach($urls as $k=>$u){{ $c=nl_card_fetch($u,$dl); $o[$k]=['ok'=>$c['ok']??null,'unsafe'=>!empty($c['unsafe'])]; }}
$lineDl='[ページ埋込]'.$urls['type'].'[/ページ埋込]';
$lineSafe='[ページ埋込]'.$urls['safe'].'[/ページ埋込]';
nl_cards_prepare($lineDl."\\n".$lineSafe);
$o['html_dl']=nl_frame_html(nl_frame_parse($lineDl));
$o['html_safe']=nl_frame_html(nl_frame_parse($lineSafe));
$o['sig_dl']=nl_frame_sig($urls['type']); $o['sig_safe']=nl_frame_sig($urls['safe']);
$o['url_dl']=$urls['type']; $o['url_safe']=$urls['safe'];
echo json_encode($o);
"""
    o = php_eval(site, code)

    check('普通のページ（HTML）は従来どおり安全扱い', o['safe'] == {'ok': True, 'unsafe': False})
    check('ダウンロード判定: Content-Type が octet-stream', o['type']['unsafe'] and not o['type']['ok'])
    check('ダウンロード判定: 拡張子が .exe（中身はHTMLでも名前で弾く）', o['ext']['unsafe'])
    check('ダウンロード判定: application/zip の書庫', o['zip']['unsafe'])
    check('ダウンロード判定: Content-Disposition が attachment', o['disp']['unsafe'])

    dl = o['html_dl']
    check('危険な埋込はページ内で開くカバーを出さない（data-frame-src が無い）', 'data-frame-src' not in dl and 'embed.php' not in dl)
    check('危険な埋込は「開けません」と注意を出し、外部リンクだけにする',
          'is-blocked' in dl and 'このページは開けません' in dl and '安全のため' in dl and 'target="_blank"' in dl)
    check('普通のページはこれまでどおりカバーから読み込める', 'data-frame-src="./embed.php?u=' in o['html_safe'])

    # embed.php hardening: a valid signature from an older page still cannot frame a download target.
    q = lambda u: urllib.parse.quote(u, safe='')
    r_dl = public.get(f"/embed.php?u={q(o['url_dl'])}&s={o['sig_dl']}")
    check('embed.php: 署名が正しくても危険な相手は 404', r_dl.status == 404)
    r_safe = public.get(f"/embed.php?u={q(o['url_safe'])}&s={o['sig_safe']}")
    check('embed.php: 普通のページは従来どおり枠に入れる（200）', r_safe.status == 200 and 'game.html' in r_safe.text)


def main():
    for port in (args.port, TARGET_PORT):
        with socket.socket() as sock: sock.bind((h.HOST, port))
    temp = ROOT / 'manga/dev/results' / ('embed-guard-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    server = http.server.ThreadingHTTPServer((h.HOST, TARGET_PORT), Target)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    os.environ['NAGIMANGA_CARD_ALLOW_LOCAL'] = '1'
    process = base.start(site, args.port)
    try:
        base.install(args.port)
        run(site)
    finally:
        process.terminate(); process.wait(timeout=10)
        server.shutdown()
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
