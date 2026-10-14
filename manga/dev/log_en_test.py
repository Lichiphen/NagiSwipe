#!/usr/bin/env python3
"""English public pages: with the admin language set to English, no Japanese UI text is left on the LOG.

Every public page kind is opened (top, post, locked post, fixed page, archives, search, 404, RSS, owner view),
and any Japanese character outside the posts' own English text fails the check, with the text around it.
Usage: python manga/dev/log_en_test.py
"""
import argparse, json, re, secrets, socket, sys
from pathlib import Path
from urllib.parse import quote
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5430)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
import attack_test as h
import log_test as base
checks = []
JP = re.compile(r'[぀-ヿ一-鿿！-～]')
def check(name, ok, detail=''):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}" + (f'\n    {detail}' if detail and not ok else ''), flush=True)


def japanese(html):
    """Japanese characters outside scripts' JSON data, with some text around each (the first few)."""
    text = re.sub(r'<script type="application/(?:ld\+)?json"[^>]*>.*?</script>', '', html, flags=re.S)
    # The editor's media mark 〔メディア1〕 is a token of the post text itself, not a label.
    text = text.replace('〔メディア', '〔')
    return [text[max(0, m.start() - 40):m.end() + 40].replace('\n', ' ') for m in list(JP.finditer(text))[:5]]


def run(c, csrf):
    pub = h.Client(args.port)
    def post(data, files=None, headers=None): return c.post('/admin/index.php', {'csrf': csrf, **data}, files=files, headers=headers)
    def save(body, status='published', **extra): return json.loads(post(base.payload(body, status, **extra)).text)
    json_head = {'Accept': 'application/json'}
    post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'admin_lang', 'admin_lang': 'en'}, headers=json_head)
    post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': 'log_design', 'title': 'Test LOG', 'name': 'Me', 'theme': 'light-blue', 'description': 'Notes and drawings.'}, headers=json_head)
    post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': 'log_preferences', 'visibility': 'public', 'posts_per_page': '2', 'pager_form': '1', 'pager': 'numbers', 'pager_status': '1', 'post_nav': '1'}, headers=json_head)
    image = json.loads(post({'do': 'log_upload'}, files={'image': ('a.png', h.png(64, 48, 3), 'image/png')}).text)['media']['id']
    plain = save(f'A walk\nThe sky was clear.\n[Image:{image}]\n#walk', new_categories='Diary')
    save('Red notes\nSomething for adults.', rating='r18')
    save(f'Soft picture\n[Image:{image}]', rating='sensitive')
    locked = save('Locked notes\nOnly for friends.', lock_form='1', lock='1', lock_password='friends-only')
    save('https://www.youtube.com/watch?v=dQw4w9WgXcQ\nA video I liked.')
    post({'do': 'log_pin', 'post_id': plain['id'], 'pin': '1'})
    post({'do': 'log_page_save', 'page_id': '', 'revision': '0', 'title': 'About', 'slug': 'about', 'body': '## Hello\nThis is me.', 'layout': 'sidebar', 'status': 'published'}, headers=json_head)
    month = plain['id'][:4] + '-' + plain['id'][4:6]
    pages = {
        'トップ': '/', '2ページ目': '/?page=2', '記事': '/?id=' + plain['id'], '鍵付きの記事': '/?id=' + locked['id'],
        '鍵の入力を間違えた記事': f'/?id={locked["id"]}&locked=wrong', '鍵の入力を続けて間違えた記事': f'/?id={locked["id"]}&locked=wait',
        '固定ページ': '/?pg=about', 'カテゴリ': '/?category=' + re.search(r'category=([a-f0-9]{12})', pub.get('/').text).group(1),
        'タグ': '/?tag=walk', '月': '/?month=' + month, '日': '/?date=' + month + '-' + plain['id'][6:8],
        '検索': '/?q=' + quote('sky'), '見つからない検索': '/?q=' + quote('nothing-here'), 'RSS': '/?feed=rss',
    }
    for name, path in pages.items():
        r = pub.get(path, headers={'Accept': 'text/html'})
        found = japanese(r.text)
        check(f'{name}に日本語の表示が残っていない', r.status == 200 and not found, f'{r.status} {found}')
    top = pub.get('/').text
    check('ページの言語が英語', '<html lang="en"' in top)
    check('サイドバーのアイコンの下に名前を表示', re.search(r'class="log-profile-icon[^"]*">.*?</div><p class="log-profile-name">Me</p>', top, re.S) is not None)
    panel = c.get('/admin/index.php?p=settings&section=log').text
    check('管理画面に名前の表示スイッチの英語の案内', 'Show the name under the icon' in panel and 'The name comes from “Name” in “LOG design and icon”.' in panel)
    data = re.search(r'<script type="application/json" id="nm-i18n">(.*?)</script>', top, re.S)
    check('スクリプト用の英語の文言だけを小さく入れる', data is not None and 0 < len(json.loads(data.group(1))) < 100)
    missing = pub.get('/no-such-page-here', headers={'Accept': 'text/html'})
    check('404ページも英語', missing.status == 404 and '<html lang="en"' in missing.text and not japanese(missing.text), str(japanese(missing.text)))
    # A manga work's own page (read.php?nagimanga=…) follows the same language.
    r = post({'do': 'create', 'title': 'Short comic', 'series': '', 'direction': 'rtl'})
    wid = re.search(r'id=([A-Za-z0-9]{12})', r.getheader('Location') or '').group(1)
    c.post('/admin/index.php', {'do': 'upload', 'id': wid, 'csrf': csrf}, files={'page': ('p1.png', h.png(60, 80, 1), 'image/png')}, headers={'X-NM-CSRF': csrf})
    work = pub.get('/read.php?nagimanga=' + wid, headers={'Accept': 'text/html'})
    check('作品ページも英語（漫画ビューアーの文言つき）', work.status == 200 and '<html lang="en"' in work.text and 'id="nm-i18n"' in work.text and not japanese(work.text), f'{work.status} {japanese(work.text)}')
    owner = c.get('/').text
    check('持ち主の見るトップ（投稿欄つき）にも日本語が残っていない', not japanese(owner), str(japanese(owner)))
    # Back to Japanese when the language is "auto" (a shared page cannot follow each browser).
    post({'do': 'settings_save_all', 'tab': 'common', 'sections[]': 'admin_lang', 'admin_lang': 'auto'}, headers=json_head)
    ja = pub.get('/', headers={'Accept-Language': 'en-US'}).text
    check('管理画面の言語が「自動」なら公開ページは日本語', '<html lang="ja"' in ja and 'すべての投稿' in ja and 'id="nm-i18n"' not in ja)


def main():
    with socket.socket() as sock: sock.bind((h.HOST, args.port))
    temp = ROOT / 'manga/dev/results' / ('en-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    process = base.start(site, args.port)
    try:
        c, csrf, _ = base.install(args.port)
        run(c, csrf)
    finally:
        process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
