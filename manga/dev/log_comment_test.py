#!/usr/bin/env python3
"""LOG comments: switches, the public endpoint (comment.php), approval, the owner's notice, icons, locks, deletion and backups, on fresh private data."""
import argparse, base64, json, os, re, secrets, shutil, socket, subprocess, sys, urllib.parse
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5412)
parser.add_argument('--other-port', type=int, default=5414)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
import attack_test as h
import log_test as base
checks = []
def check(name, ok, detail=''):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}" + ('' if ok or not detail else f'  ({str(detail)[:300]})'), flush=True)
def php(site, code):
    prefix = "define('NAGIMANGA',true); require '" + (site / 'lib/bootstrap.php').as_posix() + "'; require '" + (site / 'lib/log.php').as_posix() + "'; "
    cmd = h.php_cmd(0, False, site); cmd = cmd[:cmd.index('-S')] + ['-r', prefix + code]
    r = subprocess.run(cmd, env=dict(os.environ, NAGIMANGA_DATA=str(site / 'data')), capture_output=True, text=True, encoding='utf-8', check=True)
    if r.stderr: raise RuntimeError(r.stderr)
    return json.loads(r.stdout) if r.stdout else None
KEY = 'a' * 32
SVG_OK = b'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="18" fill="none" stroke="#f0a"/></svg>'
def png_with_text(w, h):
    """A plain PNG with a text chunk that must not survive the conversion."""
    import struct, zlib
    chunk = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    raw = b''.join(bytes([0]) + bytes([200, 40, 120, 255]) * w for _ in range(h))
    return bytes.fromhex('89504e470d0a1a0a') + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + chunk(b'tEXt', b'Comment' + bytes([0]) + b'SECRET') + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')
SVG_BAD = b'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><script>alert(1)</script><circle cx="24" cy="24" r="18"/></svg>'

NL_WAIT_NET, NL_STAMP_NET = 5, 20

def run(site, other_site, c, csrf, other, ot):
    pub = h.Client(args.port)
    def post(data, files=None, client=c, token=csrf, headers=None): return client.post('/admin/index.php', {'csrf': token, **data}, files=files, headers=headers)
    def settings(pairs, client=c, token=csrf):
        body = urllib.parse.urlencode([('csrf', token), ('do', 'settings_save_all'), ('tab', 'log'), ('sections[]', 'log_comments')] + pairs).encode()
        return client.post('/admin/index.php', body, headers={'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'})
    icons_all = [('comment_icons[]', k) for k in ('star', 'moon', 'drop', 'leaf', 'flower', 'cloud', 'heart', 'spark')]
    def new_post(body, extra=None):
        before = {p['id'] for p in php(site, 'echo json_encode(nl_post_summaries(false));')}
        r = post(base.payload(body=body, **(extra or {})))
        if r.status not in (200, 303): raise RuntimeError(f'save {r.status} {r.text[:200]}')
        return next(p['id'] for p in php(site, 'echo json_encode(nl_post_summaries(false));') if p['id'] not in before)
    def load(pid): return php(site, f"echo json_encode(nl_load_post('{pid}'));")
    def get(pid, key=''): return pub.get('/comment.php?id=' + pid, headers={'X-NagiLog-Key': key} if key else {})
    tickets = {}
    def ticket(pid, age=10):
        # A ticket as the page would hold it after a person looked for a while (made here, so the test need not wait).
        if age != 10 or pid not in tickets: t = php(site, f"echo json_encode(nl_comment_ticket('{pid}', time() - {age}));")
        else: return tickets[pid]
        if age == 10: tickets[pid] = t
        return t
    quizzes = {}
    def quiz(t):
        if t not in quizzes: quizzes[t] = php(site, f"echo json_encode(nl_comment_quiz('{t}'));")
        return quizzes[t]
    def send(pid, stamp='', text='', icon='star', key=KEY, headers=None, client=pub, **more):
        hd = {'Content-Type': 'application/json', 'X-NagiLog': 'comment'} if headers is None else headers
        more.setdefault('ticket', ticket(pid))
        # A person answers the sum shown with the ticket (only a text needs it).
        if 'quiz' not in more and isinstance(more['ticket'], str) and more['ticket']: more['quiz'] = str(sum(quiz(more['ticket'])))
        return client.post('/comment.php', json.dumps({'id': pid, 'token': key, 'icon': icon, 'stamp': stamp, 'text': text, **more}).encode(), headers=hd)
    def calm(where=site):
        for f in (where / 'data/ratelimit').glob('*'): f.unlink()
    def index(): return php(site, 'echo json_encode(nl_comments_index_read());')

    old = new_post('コメント機能の前の記事')
    check('初期状態はコメントOFF（設定・窓口・投稿画面）', php(site, "echo json_encode(nl_settings()['comments']);") is False and h.is_404(get(old)) and 'data-panel="comments"' not in c.get('/admin/index.php?p=log').text)
    r = settings([('comments', '1'), ('comment_text', '1'), ('comment_stamps', '👍 😊 🎉 ❤️ 👨‍👩‍👧')] + icons_all)
    s = php(site, 'echo json_encode(nl_settings());')
    check('設定でON（絵文字の結合文字もスタンプにできる）', r.status == 200 and s['comments'] is True and s['comment_stamps'] == ['👍', '😊', '🎉', '❤️', '👨‍👩‍👧'], r.text[:200])
    check('絵文字でないスタンプは拒否', settings([('comments', '1'), ('comment_text', '1'), ('comment_stamps', '👍 <b>')] + icons_all).status == 422 and php(site, "echo json_encode(nl_settings()['comment_stamps']);")[0] == '👍')
    check('アイコンを全部外すと拒否', settings([('comments', '1'), ('comment_text', '1'), ('comment_stamps', '👍')]).status == 422)
    check('投稿画面にコメント欄のスイッチが出る', 'data-panel="comments"' in c.get('/admin/index.php?p=log').text and 'name="comments_form"' in c.get('/admin/index.php?p=log').text)
    check('前からある記事はOFFのまま', load(old).get('comments', False) is False and h.is_404(get(old)))
    pid = new_post('コメントのある記事')
    check('新しい記事は最初からON', load(pid)['comments'] is True)
    page = pub.get('/?id=' + pid).text
    check('記事のページにコメント欄と専用のCSS・JSを読み込む', 'data-comments="' + pid + '"' in page and re.search(r'data-comments-count>\d+</span><a class="log-comments-write" href="#comments-form"', page) and 'id="comments-form"' in page and 'viewer/log-comment.css?v=' in page and 'viewer/log-comment.js?v=' in page)
    top = pub.get('/').text
    check('一覧のページではコメントのCSS・JSを読み込まない', 'log-comment.css' not in top and 'log-comment.js' not in top)
    g = get(pid); d = json.loads(g.text) if g.status == 200 else {}
    check('窓口がアイコン・スタンプ・設定を返す', g.status == 200 and len(d.get('icons', [])) == 8 and d.get('stamps') == s['comment_stamps'] and d.get('text') is True and d.get('items') == [] and g.getheader('Cache-Control') == 'no-store')
    check('X-NagiLog ヘッダーのない書き込みは404', h.is_404(send(pid, '👍', headers={'Content-Type': 'application/json'})))
    check('別サイトからの書き込みは404', h.is_404(send(pid, '👍', headers={'Content-Type': 'application/json', 'X-NagiLog': 'comment', 'Origin': 'https://evil.example'})))
    check('一覧にないスタンプ・アイコン・合言葉は拒否', send(pid, '💩').status == 422 and send(pid, '👍', icon='nope').status == 422 and send(pid, '👍', key='short').status == 422)
    # Refused writes count towards the limit too: start the next ones from zero.
    calm()
    check('空のコメントは拒否', json.loads(send(pid).text).get('error') == 'empty')
    # Bots: the ticket, the hidden field, addresses.
    calm()
    err = lambda r: json.loads(r.text).get('error')
    check('窓口が書き込み券を渡す', re.fullmatch(r'\d{10}\.[a-f0-9]{24}', json.loads(get(pid).text).get('ticket', '')) is not None)
    check('券なし・作りたての券・ほかの記事の券は fast', err(send(pid, '👍', ticket='')) == 'fast' and err(send(pid, '👍', ticket=ticket(pid, 0))) == 'fast' and err(send(pid, '👍', ticket=ticket(old))) == 'fast')
    calm()
    r = send(pid, '👍', ticket=ticket(pid, 7 * 3600))
    check('古い券は old と新しい券', err(r) == 'old' and re.fullmatch(r'\d{10}\.[a-f0-9]{24}', json.loads(r.text).get('ticket', '')) is not None)
    r = json.loads(r.text)
    check('古い券には新しい足し算が付く', r.get('quiz') == quiz(r.get('ticket', '')), str(r))
    g = json.loads(get(pid).text)
    check('窓口が券から作った1けたの足し算を渡す', g.get('quiz') == quiz(g['ticket']) and all(1 <= n <= 9 for n in g['quiz']), str(g.get('quiz')))
    check('隠し欄に何か入っていたら spam', err(send(pid, '👍', website='http://x')) == 'spam')
    calm()
    check('文章や名前にURLがあれば拒否（全角も）', err(send(pid, text='見てね https://spam.example')) == 'url' and err(send(pid, text='ｃｈｅａｐ．ｃｏｍ')) == 'url' and err(send(pid, '👍', name='spam.xyz')) == 'url')
    calm()
    check('URLでない「Node.js」「v1.14」は通る', send(pid, text='Node.jsで動くv1.14、楽しみです').status == 200)
    calm()
    tid = json.loads(get(pid, KEY).text)['items'][-1]['id']
    post({'do': 'log_comments_moderate', 'action': 'delete', 'items': json.dumps([[pid, tid]])})
    check('長すぎる文章は拒否', json.loads(send(pid, text='あ' * 301).text).get('error') == 'long')
    calm()
    r = send(pid, '🎉'); stamp = json.loads(r.text).get('item', {})
    check('スタンプだけならすぐに表示', r.status == 200 and stamp.get('wait') is False and stamp.get('stamp') == '🎉' and len(stamp.get('uid', '')) == 8)
    check('IDは合言葉から決まり、IPが変わっても同じ', stamp.get('uid') == php(site, f"echo json_encode(nl_comment_uid('{KEY}'));") and stamp.get('uid') != php(site, "echo json_encode(nl_comment_uid('" + 'b' * 32 + "'));"))
    r = send(pid, '', '<script>alert(1)</script>\nこんにちは'); text = json.loads(r.text).get('item', {})
    check('文章は承認待ちで保存', r.status == 200 and text.get('wait') is True)
    others = json.loads(get(pid).text)['items']; mine = json.loads(get(pid, KEY).text)['items']
    check('承認待ちはほかの人に見えず、本人には見える', [x['id'] for x in others] == [stamp['id']] and [x['id'] for x in mine] == [stamp['id'], text['id']])
    check('ほかの人の合言葉では承認待ちが見えない', [x['id'] for x in json.loads(get(pid, 'c' * 32).text)['items']] == [stamp['id']])
    i = index()
    check('数とお知らせを記録', i['n'][pid] == {'ok': 1, 'wait': 1} and i['new'] == 1 and php(site, 'echo json_encode(nl_comments_notice());') == 2)
    send(pid, '👍'); send(pid, '😊')
    check('同じ回線から1分に4件を超えると busy', json.loads(send(pid, '❤️').text).get('error') == 'busy')
    calm()
    top = pub.get('/').text
    check('ミニブログの一覧で、Shareの横にコメントのボタンと表示中の件数', 'class="log-share-btn log-comment-link" href="./?id=' + pid + '#comments" data-comments-for="' + pid + '"' in top and re.search(r'data-comments-for="' + pid + r'"[^>]*>.*?<span>3</span>', top) and 'data-comments-for="' + old + '"' not in top and 'viewer/log-share.css' in top)
    nav = c.get('/admin/index.php?p=log').text
    check('管理メニューにベルと件数', 'p=log_comments' in nav and 'nm-menu-count' in nav and 'nav-fab-count' in nav)
    check('投稿一覧に件数と承認待ちのバッジ', 'log-list-comments is-waiting' in nav)
    admin = c.get('/admin/index.php?p=log_comments').text
    check('コメント一覧は文章をエスケープして表示', '&lt;script&gt;alert(1)&lt;/script&gt;<br>\nこんにちは' in admin and '<script>alert(1)' not in admin and '◆' + stamp['uid'][:6] in admin)
    check('一覧を開くと新着は既読、承認待ちは残る', index()['new'] == 0 and php(site, 'echo json_encode(nl_comments_notice());') == 1)
    pair = json.dumps([[pid, text['id']]])
    r = post({'do': 'log_comments_moderate', 'action': 'ok', 'items': pair})
    check('承認するとほかの人にも見える', r.status == 303 and text['id'] in [x['id'] for x in json.loads(get(pid).text)['items']] and php(site, 'echo json_encode(nl_comments_notice());') == 0)
    post({'do': 'log_comments_moderate', 'action': 'delete', 'items': json.dumps([[pid, stamp['id']]])})
    check('削除すると消える', stamp['id'] not in [x['id'] for x in json.loads(get(pid).text)['items']] and index()['n'][pid]['ok'] == 3)
    check('壊れた指定の操作は拒否', post({'do': 'log_comments_moderate', 'action': 'ok', 'items': '[["../x","1"]]'}).status == 303 and index()['n'][pid]['ok'] == 3)
    # Replies (one level deep) and names.
    rid = new_post('返信の記事')
    calm()
    item = lambda r: json.loads(r.text).get('item') or json.loads(r.text)
    ids = lambda key='': {x['id']: x for x in json.loads(get(rid, key).text)['items']}
    top = item(send(rid, '👍'))
    r1 = item(send(rid, '🎉', key='b' * 32, re=top['id']))
    r2 = item(send(rid, '😊', key='c' * 32, re=r1['id']))
    check('返信は親の下へ（返信への返信も同じ流れ・1段まで）', r1.get('to') == top['id'] and r1.get('re') == top['id'] and r2.get('to') == top['id'] and r2.get('re') == r1['id'] and top.get('to') == '' and r2.get('wait') is False, [r1, r2])
    calm()
    waiting = item(send(rid, text='承認待ちの文', key='d' * 32))
    check('返信先がない・承認待ちのコメントへは返信できない', item(send(rid, '👍', re='0123456789')).get('error') == 'gone' and item(send(rid, '👍', re=waiting['id'])).get('error') == 'gone' and item(send(rid, '👍', re='../x')).get('error') == 'form')
    calm()
    n1 = item(send(rid, '👍', key='b' * 32, name='  なぎ  '))
    check('名前付きはスタンプだけでも承認待ち（ほかの人に見えない）', n1.get('wait') is True and n1.get('name') == 'なぎ' and n1['id'] not in ids() and n1['id'] in ids('b' * 32))
    post({'do': 'log_comments_moderate', 'action': 'ok', 'items': json.dumps([[rid, n1['id']]])})
    n2 = item(send(rid, '🎉', key='b' * 32, name='なぎ'))
    n3 = item(send(rid, '🎉', key='e' * 32, name='なぎ'))
    check('承認した名前は次からすぐ表示、同じ名前でも別の人は承認待ち', n1['id'] in ids() and n2.get('wait') is False and n3.get('wait') is True and n3.get('uid') != n2.get('uid'), [n2, n3])
    calm()
    check('長すぎる名前は拒否・◆は名前に使えない', item(send(rid, '👍', name='あ' * 17)).get('error') == 'long' and item(send(rid, '👍', key='f' * 32, name='◆偽')).get('name') == '偽')
    calm()
    r = post({'do': 'log_comments_reply', 'target': rid, 're': r2['id'], 'text': 'ありがとう<b>!</b>', 'show': ''})
    mine = [x for x in ids().values() if x.get('own')]
    check('管理人の返信はすぐ表示・管理人の印・同じ流れへ', r.status == 303 and len(mine) == 1 and mine[0]['uid'] == '' and mine[0]['to'] == top['id'] and mine[0]['re'] == r2['id'] and mine[0]['text'] == 'ありがとう<b>!</b>', r.text[:200])
    g = json.loads(get(rid).text)
    check('窓口が管理人の名前と名前の上限を返す', g.get('owner', {}).get('name') == php(site, "echo json_encode(nl_settings()['name']);") and g.get('name_max') == 16)
    post({'do': 'log_comments_reply', 'target': rid, 're': n3['id'], 'text': 'ようこそ', 'show': ''})
    check('承認待ちに管理人が返信すると、そのコメントも承認', ids()[n3['id']]['wait'] is False)
    check('管理人の返信は空や長すぎる文・知らない返信先を拒否', post({'do': 'log_comments_reply', 'target': rid, 're': r2['id'], 'text': ' '}).status == 303 and post({'do': 'log_comments_reply', 'target': rid, 're': '0123456789', 'text': 'x'}).status == 303 and len([x for x in ids().values() if x.get('own')]) == 2)
    admin = c.get('/admin/index.php?p=log_comments').text
    check('コメント一覧に名前・印・返信先・管理人・返信フォーム', 'なぎ</b> <b>◆' + n2['uid'][:6] in admin and '管理人' in admin and '↳ ' in admin and 'name="do" value="log_comments_reply"' in admin)
    before = set(ids())
    post({'do': 'log_comments_moderate', 'action': 'delete', 'items': json.dumps([[rid, top['id']]])})
    after = set(ids())
    check('親を削除すると返信もまとめて消える', not ({top['id'], r1['id'], r2['id']} | {m['id'] for m in mine}) & after and {n1['id'], n2['id'], n3['id']} <= after and index()['n'][rid]['ok'] == len(after), [len(before), len(after)])
    calm()
    reply_keep = item(send(rid, '😊', key='c' * 32, re=n2['id']))
    calm()
    # The post's own switch.
    p = load(pid)
    post({**base.payload(body=p['body']), 'post_id': pid, 'revision': str(p['revision']), 'comments_form': '1'})
    check('記事ごとにOFFにできる（コメントは残す）', load(pid)['comments'] is False and h.is_404(get(pid)) and 'data-comments=' not in pub.get('/?id=' + pid).text and index()['n'][pid]['ok'] == 3)
    p = load(pid)
    post({**base.payload(body=p['body']), 'post_id': pid, 'revision': str(p['revision']), 'comments_form': '1', 'comments': '1'})
    p = load(pid)
    post({**base.payload(body=p['body']), 'post_id': pid, 'revision': str(p['revision'])})
    check('スイッチのない保存では記事の設定を保つ', load(pid)['comments'] is True and get(pid).status == 200)
    locked = new_post('鍵付きの記事', {'lock_form': '1', 'lock': '1', 'lock_password': 'open-sesame'})
    check('鍵付きの記事は、鍵のない人には窓口も404', h.is_404(get(locked)) and h.is_404(send(locked, '👍')) and 'data-comments=' not in pub.get('/?id=' + locked).text)
    check('ログイン中の管理者は鍵付きの記事のコメントも読める', c.get('/comment.php?id=' + locked).status == 200 and 'data-comments="' + locked + '"' in c.get('/?id=' + locked).text)
    r = post({'do': 'log_page_save', 'page_id': '', 'revision': '0', 'title': 'ゲストブック', 'slug': 'guestbook', 'body': 'ひとことどうぞ', 'layout': 'narrow', 'status': 'published'})
    gid = php(site, "echo json_encode(array_values(array_map(fn($p) => $p['id'], nl_pages())));")[0]
    check('固定ページも最初からON・スイッチあり', php(site, f"echo json_encode(nl_load_page('{gid}')['comments']);") is True and 'name="comments_form"' in c.get('/admin/index.php?p=log_page&id=' + gid).text)
    check('固定ページにも書き込める', send(gid, '👍').status == 200 and 'data-comments="' + gid + '"' in pub.get('/?pg=guestbook').text)
    # Icons the owner adds and deletes in the settings (SVG, or a picture drawn again as a 128px PNG).
    def icons_save(files=None, delete=None):
        data = {'do': 'settings_save_all', 'tab': 'log', 'sections[]': 'log_comments', 'comments': '1', 'comment_text': '1', 'comment_stamps': '👍 😊 🎉 ❤️ 👨‍👩‍👧', 'comment_icons[]': 'star'}
        if delete: data['comment_icon_delete[]'] = delete
        return post(data, files=files or {}, headers={'Accept': 'application/json'})
    def added(): return [k['key'] for k in json.loads(get(pid).text)['icons'] if k['key'].startswith('u')]
    r = icons_save({'comment_icon_files[]': ('丸.svg', SVG_OK, 'image/svg+xml')})
    key = (added() or [''])[0]
    served = pub.get('/comment.php?icon=' + key)
    check('設定でアイコン（SVG）を追加でき、すぐ選べて、画像としてだけ配信', r.status == 200 and key != '' and served.status == 200 and served.getheader('Content-Type') == 'image/svg+xml' and 'sandbox' in (served.getheader('Content-Security-Policy') or '') and 'stroke="#f0a"' in served.text and any(k['name'] == '丸' for k in json.loads(get(pid).text)['icons']), r.text[:200])
    check('コメント一覧の画面にはアイコンの管理を出さない', 'comment_icon_files' not in c.get('/admin/index.php?p=log_comments').text and 'comment_icon_files' in c.get('/admin/index.php?p=settings&section=log').text)
    r = icons_save({'comment_icon_files[]': ('bad.svg', SVG_BAD, 'image/svg+xml')})
    check('スクリプト入りのSVGは拒否', r.status == 422 and len(php(site, 'echo json_encode(nl_comment_icons_custom());')) == 1)
    check('画像でないファイルは拒否', icons_save({'comment_icon_files[]': ('x.png', b'not a picture', 'image/png')}).status == 422)
    r = icons_save({'comment_icon_files[]': ('photo.png', png_with_text(300, 150), 'image/png')})
    png = next((k for k in php(site, 'echo json_encode(nl_comment_icons_custom());') if k != key), '')
    served = pub.get('/comment.php?icon=' + png)
    check('PNGも追加でき、128pxに描き直して余計なデータを残さない', r.status == 200 and served.status == 200 and served.getheader('Content-Type') == 'image/png' and served.body[16:24] == bytes.fromhex('0000008000000080') and b'SECRET' not in served.body and png in added())
    check('追加したアイコンで書き込める', send(pid, '👍', icon=png).status == 200)
    check('知らないアイコンの配信は404', h.is_404(pub.get('/comment.php?icon=u000000000000')) and h.is_404(pub.get('/comment.php?icon=../config')))
    # Hardening after the red-team review: hidden characters, emoji joiners, one line's share of the queue and of a post.
    j = lambda r: json.loads(r.text)
    q1, q2, q3, q4 = (new_post(f'bot対策の確認{n}') for n in range(4))
    def url_err(**kw):
        calm(); return err(send(q1, **kw))
    hidden = ['\u00ad', '\u2060', '\ufeff', '\u034f', '\u180e', '\u3164', '\ufe0f', '\u2061', '\u2800', '\u200d']
    check('見えない文字を挟んだURLも拒否（文章・名前）', all(url_err(text='spam.c' + ch + 'om') == 'url' for ch in hidden) and url_err(stamp='👍', name='x.c\u00adom') == 'url')
    check('似た形の点・斜線のURLも拒否', url_err(text='https:∕∕example․com') == 'url' and url_err(text='example﹒net') == 'url')
    check('ai・tech・blog・page・art のドメインも拒否', all(url_err(text=f'see foo.{t}') == 'url' for t in ('ai', 'tech', 'blog', 'page', 'art')))
    check('Node.js などは引き続き通る（拒否されない）', url_err(text='Node.jsとv1.14.2、No.1') != 'url')
    calm()
    a = sum(quiz(ticket(q1)))
    check('文章で答えなし・違う答え・数字でない答えは quiz', err(send(q1, text='こんにちは', quiz='')) == 'quiz' and err(send(q1, text='こんにちは', quiz=str(a + 1))) == 'quiz' and err(send(q1, text='こんにちは', quiz='abc')) == 'quiz')
    calm()
    check('スタンプだけなら答えなしで通る', send(q1, '👍', quiz='').status == 200)
    calm()
    full = str(a).translate(str.maketrans('0123456789', '０１２３４５６７８９'))
    r = json.loads(send(q1, text='全角で答えました', quiz=' ' + full + '　').text)
    check('全角数字・前後の空白の答えも通る', 'item' in r, str(r)[:200])
    # Not left waiting: the per-line limit is tested on its own further down.
    if 'item' in r: post({'do': 'log_comments_moderate', 'action': 'delete', 'items': json.dumps([[q1, r['item']['id']]])})
    calm()
    check('見えない文字だけの文章＋スタンプは答えなしで通る', send(q1, '👍', text='​⠀ ', quiz='').status == 200)
    calm()
    calm()
    keep = '家族 👨\u200d👩\u200d👧 ❤️ は\u3099 e\u0301'
    r = j(send(q1, text=keep))
    check('絵文字のZWJ・異体字セレクター・結合文字を保存時に保つ', r.get('item', {}).get('text') == keep, str(r)[:200])
    calm()
    check('見えない文字だけの文章は empty', err(send(q1, text='\u00ad\u2060\u2800 \u3000')) == 'empty')
    calm()
    r = j(send(q1, '👍', text='\u2800\u00ad', name='\u2800\u3164'))
    check('スタンプ＋見えない文字だけの文章・名前は、スタンプとして即表示', r.get('item', {}).get('wait') is False and r['item'].get('text') == '' and r['item'].get('name') == '', str(r)[:200])
    net = php(site, f"echo json_encode(nl_comments_read('{q1}')[0]['net']);")
    have = php(site, f"echo json_encode(nl_comment_net_waiting('', [], nl_comments_index_read(), '{net}'));")
    need = NL_WAIT_NET - have
    mine = []
    for n in range(need):
        calm(); r = j(send(q1 if n % 2 else q2, text=f'承認待ち{n}'))
        if 'item' in r: mine.append((q1 if n % 2 else q2, r['item']['id']))
    calm()
    check('同じ回線の承認待ちはサイト全体で5件まで（承認待ちのない記事でも held）', need >= 1 and len(mine) == need and err(send(q3, text='6件目')) == 'held' and err(send(q3, '👍', name='新しい名前')) == 'held', f'have={have} need={need} mine={len(mine)}')
    post({'do': 'log_comments_moderate', 'action': 'ok', 'items': json.dumps([list(mine[0])])})
    calm()
    r = j(send(q3, text='承認で空いた枠'))
    check('承認すると枠が空く', r.get('item', {}).get('wait') is True, str(r)[:200])
    calm()
    check('承認の要らないスタンプは巻き込まれない', j(send(q3, '👍')).get('item', {}).get('wait') is False)
    post({'do': 'log_comments_moderate', 'action': 'delete', 'items': json.dumps([list(x) for x in mine[1:]] + [[q3, r['item']['id']]])})
    res = []
    for n in range(NL_STAMP_NET + 1):
        if n % 4 == 0: calm()
        res.append(err(send(q4, '👍', key=f'{n:032x}')) or 'ok')
    calm()
    check('1つの記事に残せるスタンプは同じ回線から20件まで（合言葉を変えても）', res[:NL_STAMP_NET] == ['ok'] * NL_STAMP_NET and res[-1] == 'many' and err(send(q3, '😊')) is None, ' '.join(res))
    purge = php(site, "$f = nl_root() . '/comments-purge.txt'; @unlink($f); nl_comment_purge(); $a = file_get_contents($f); nl_comment_purge(); $b = file_get_contents($f); nl_comment_purge(false); $c = file_get_contents($f); file_put_contents($f, (time() - 31) . '|1'); nl_comment_purge(false); $d = file_get_contents($f); echo json_encode([$a, $b, $c, $d]);")
    check('キャッシュの削除は30秒に1回まで、残りは次の窓口で', purge[0].endswith('|0') and purge[1].endswith('|1') and purge[2] == purge[1] and purge[3].endswith('|0') and purge[3] != purge[2], str(purge))
    # Backup and restore into another site.
    zipped = post({'do': 'log_backup'}).body
    r = other.post('/admin/index.php', {'csrf': ot, 'do': 'log_restore', 'overwrite': '1'}, files={'backup': ('log.zip', zipped, 'application/zip')})
    moved = php(other_site, f"echo json_encode([nl_comments_read('{pid}'), nl_settings()['comments'], array_keys(nl_comment_icons_custom()), nl_load_post('{pid}')['comments']]);")
    check('バックアップから復元（コメント・設定・アイコン・記事のスイッチ）', r.status == 303 and len(moved[0]) == 4 and moved[1] is True and sorted(moved[2]) == sorted([key, png]) and moved[3] is True, r.text[:200])
    back = {x['id']: x for x in php(other_site, f"echo json_encode(nl_comments_read('{rid}'));")}
    check('復元で名前・返信先・管理人の返信も戻る', back.get(reply_keep['id'], {}).get('to') == n2['id'] and back.get(n2['id'], {}).get('name') == 'なぎ' and any(x.get('own') for x in back.values()))
    # Deleting the post and the page.
    p = load(pid)
    post({'do': 'log_delete', 'post_id': pid, 'revision': str(p['revision'])})
    check('記事を消すとコメントも消える', not (site / f'data/log/comments/{pid}.php').exists() and pid not in index()['n'])
    pg = php(site, f"echo json_encode(nl_load_page('{gid}'));")
    post({'do': 'log_page_delete', 'page_id': gid, 'revision': str(pg['revision'])})
    check('固定ページを消すとコメントも消える', not (site / f'data/log/comments/{gid}.php').exists())
    # A private Memo.
    post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': 'log_preferences', 'visibility': 'private', 'posts_per_page': '10'})
    check('自分専用Memoでは窓口も404', h.is_404(pub.get('/comment.php?id=' + old)))

def main():
    for port in (args.port, args.other_port):
        with socket.socket() as sock: sock.bind((h.HOST, port))
    temp = ROOT / 'manga/dev/results' / ('comment-test-' + secrets.token_hex(4)); site = temp / 'site'; other_site = temp / 'other'
    for path in (site, other_site): h.copy_server(path, ROOT / 'manga/server')
    processes = []
    try:
        processes.extend((base.start(site, args.port), base.start(other_site, args.other_port)))
        c, csrf, _ = base.install(args.port); other, ot, _ = base.install(args.other_port)
        run(site, other_site, c, csrf, other, ot)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    if passed == len(checks): shutil.rmtree(temp, ignore_errors=True)
    raise SystemExit(0 if passed == len(checks) else 1)
if __name__ == '__main__': main()
