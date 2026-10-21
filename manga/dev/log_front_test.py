#!/usr/bin/env python3
"""A fixed page as the top page and the posts under ./blog (lib/log-front.php), on fresh data.

The first server runs with NAGIMANGA_PRETTY=1 (the rewrite rules of the shipped .htaccess);
the second serves the same data without them (./?pg=blog).
Usage: python manga/dev/log_front_test.py
"""
import argparse, json, os, re, secrets, socket, sys
from pathlib import Path
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5430)
parser.add_argument('--plain-port', type=int, default=5432)
args = parser.parse_args()
ROOT = next(p for p in Path(__file__).resolve().parents if (p / 'manga/server').is_dir())
sys.path.insert(0, str(ROOT / 'manga/dev'))
import attack_test as h
import log_test as base
checks = []
def check(name, ok):
    checks.append((name, bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def run(site, c, csrf):
    pub = h.Client(args.port); plain = h.Client(args.plain_port)
    json_head = {'Accept': 'application/json'}
    url = f'http://{h.HOST}:{args.port}'
    plain_url = f'http://{h.HOST}:{args.plain_port}'
    def post(data, files=None, client=c, headers=None): return client.post('/admin/index.php', {'csrf': csrf, **data}, files=files, headers=headers)
    def save(body, **extra): return json.loads(post(base.payload(body, **extra)).text)
    def page_save(**f):
        data = {'do': 'log_page_save', 'page_id': '', 'revision': '0', 'title': '', 'slug': '', 'body': '', 'layout': 'narrow', 'status': 'published'}
        data.update({k: str(v) for k, v in f.items()})
        return post(data, headers=json_head)
    def page_id(r): return re.search(r'id=(pg[a-f0-9]{12})', json.loads(r.text)['redirect']).group(1)
    def settings(section, data):
        return post({'do': 'settings_save_all', 'tab': 'log', 'sections[]': section, **data}, headers=json_head)
    def front(**f):
        data = {'front': '1', 'front_page': about, 'blog_slug': 'blog', 'blog_label': '', 'blog_topmenu': '1', 'blog_footer': '1'}
        data.update({k: str(v) for k, v in f.items()})
        return settings('log_front', {k: v for k, v in data.items() if v != ''})
    def main_of(html): return re.search(r'<main class="log-site-main".*?</main>', html, re.S).group(0)
    def topmenu(html):
        m = re.search(r'<nav class="log-topmenu".*?</nav>', html, re.S)
        return m.group(0) if m else ''
    def footer(html):
        m = re.search(r'<footer class="log-site-footer">.*?</footer>', html, re.S)
        return m.group(0) if m else ''

    first = save('いちばん古い記事\n本文1 #らくがき', new_categories='日記')
    second = save('まんなかの記事\n本文2')
    third = save('いちばん新しい記事\n本文3')
    cat = re.search(r'\?category=([a-f0-9]{12})', pub.get('/' + first['id']).text).group(1)
    settings('log_preferences', {'visibility': 'public', 'posts_per_page': '2', 'pager_form': '1', 'pager': 'numbers', 'pager_status': '1', 'post_nav': '1'})
    about = page_id(page_save(title='ようこそ', slug='about', body='## はじめに\nこのサイトの案内です。'))
    page_save(title='プライバシー', slug='privacy', body='大切に扱います。')

    # --- Off (the default) ---------------------------------------------------------------------
    top = pub.get('/').text
    check('オフのときはトップに投稿の一覧', 'いちばん新しい記事' in top and 'このサイトの案内' not in top)
    check('オフのときは ./blog は404', pub.get('/blog').status == 404)
    panel = c.get('/admin/index.php?p=settings&section=log').text
    check('設定画面に「トップページとブログ」のオン・オフ', 'data-settings-section="log_front"' in panel and 'name="front"' in panel and 'role="switch"' in panel and '>ようこそ</option>' in panel)

    # --- Wrong settings are refused ---------------------------------------------------------------
    r = front(front_page='')
    check('固定ページを選ばずにオンにはできない', r.status == 422 and 'トップページとブログ' in json.loads(r.text).get('error', ''))
    r = front(blog_slug='privacy')
    check('固定ページと同じURL名はブログに使えない', r.status == 422 and 'privacy' in json.loads(r.text).get('error', ''))
    r = front(blog_slug='admin')
    check('LOGのフォルダー名はブログに使えない', r.status == 422)
    r = front(blog_slug='Blog Page')
    check('英小文字・数字・ハイフン以外のURL名は断る', r.status == 422)
    check('断ったときはオフのまま', 'いちばん新しい記事' in pub.get('/').text)

    # --- On --------------------------------------------------------------------------------------------
    r = front()
    check('オンにして保存できる', r.status == 200 and json.loads(r.text).get('ok'))
    r = pub.get('/')
    top = r.text
    check('トップに選んだ固定ページ', r.status == 200 and 'このサイトの案内です。' in top and '<h1>ようこそ</h1>' in top)
    check('トップに投稿は出さない', 'いちばん新しい記事' not in main_of(top))
    check('トップのcanonicalはフォルダーのURL', f'<link rel="canonical" href="{url}/">' in top)
    check('トップのtitleはサイト名だけ', re.search(r'<title>わたしのLOG</title>', top) is not None)
    check('トップにパンくずリストは出さない', 'log-breadcrumb' not in top)
    r = pub.get('/about')
    check('トップにした固定ページの元のURLはトップへ301', r.status == 301 and r.getheader('Location') == url + '/')
    r = pub.get('/?pg=about')
    check('./?pg=about もトップへ移る', r.status == 301 and r.getheader('Location') == url + '/')
    check('ほかの固定ページはそのまま', pub.get('/privacy').status == 200)

    r = pub.get('/blog')
    blog = r.text
    check('./blog に投稿の一覧', r.status == 200 and 'いちばん新しい記事' in blog and 'まんなかの記事' in blog and 'このサイトの案内' not in blog)
    check('ブログのcanonical', f'<link rel="canonical" href="{url}/blog">' in blog)
    check('ブログのtitleは「ブログ｜サイト名」', '<title>ブログ｜わたしのLOG</title>' in blog)
    check('ブログのパンくずはHOME・ブログ', re.search(r'<nav class="log-breadcrumb".*?<a class="log-crumb" href="\./"[^>]*>.*?HOME.*?<li aria-current="page">.*?ブログ', blog, re.S) is not None)
    check('一覧のh1はサイト名', '<h1 class="log-site-title' in blog)
    check('ページ送りは ./blog?page=2', 'href="./blog?page=2"' in blog)
    check('検索欄の送り先は ./blog', 'action="./blog"' in blog)
    check('サイドバーのカテゴリとタグは ./blog?…', f'href="./blog?category={cat}"' in blog and 'href="./blog?tag=%E3%82%89%E3%81%8F%E3%81%8C%E3%81%8D"' in blog)
    check('カレンダーも ./blog?…', 'href="./blog?month=' in blog and 'href="./blog?date=' in blog)
    check('「すべての投稿」は ./blog', 'class="log-all-link" href="./blog"' in blog)
    r = pub.get('/blog?page=2')
    check('2ページ目', r.status == 200 and 'いちばん古い記事' in r.text and 'href="./blog"' in r.text and f'<link rel="canonical" href="{url}/blog?page=2">' in r.text)
    r = pub.get('/blog/')
    check('./blog/ は ./blog へ301', r.status == 301 and r.getheader('Location') == url + '/blog')
    r = pub.get('/blog/?page=2')
    check('./blog/?page=2 は ./blog?page=2 へ', r.status == 301 and r.getheader('Location') == url + '/blog?page=2')
    r = pub.get('/?category=' + cat)
    check('トップの ./?category= はブログへ301', r.status == 301 and r.getheader('Location') == f'{url}/blog?category={cat}')
    r = pub.get('/?page=2')
    check('トップの ./?page=2 はブログへ', r.status == 301 and r.getheader('Location') == url + '/blog?page=2')
    r = pub.get('/?q=' + '%E8%A8%98%E4%BA%8B')
    check('検索もブログへ', r.status == 301 and r.getheader('Location') == url + '/blog?q=%E8%A8%98%E4%BA%8B')
    r = pub.get(f'/blog?category={cat}')
    check('ブログのカテゴリ一覧', r.status == 200 and 'いちばん古い記事' in r.text and 'まんなかの記事' not in main_of(r.text))
    check('カテゴリのパンくずはHOME・ブログ・日記', re.search(r'href="\./"[^>]*>.*?HOME.*?href="\./blog"[^>]*>.*?ブログ.*?aria-current="page">.*?日記', r.text, re.S) is not None)
    single = pub.get('/' + first['id']).text
    check('記事のパンくずもブログを通る', re.search(r'href="\./blog"[^>]*>.*?ブログ.*?href="\./blog\?category=' + cat, single, re.S) is not None)
    check('記事のカテゴリとハッシュタグのリンクも ./blog?…', f'href="./blog?category={cat}"' in single and 'class="log-hashtag" href="./blog?tag=' in single)
    check('記事の下の「すべての投稿」も ./blog', 'class="log-all-chip" href="./blog"' in single)
    check('サイト名のリンクはトップ（固定ページ）', '<a href="./"' in single)

    # --- Menus ------------------------------------------------------------------------------------------
    check('トップメニューの先頭にブログ', re.search(r'<ul class="log-topmenu-links"><li><a href="\./blog">ブログ</a>', top) is not None)
    check('ブログを開くとメニューのブログが現在地', '<a href="./blog" aria-current="page">ブログ</a>' in topmenu(blog))
    check('トップでは現在地にしない', 'aria-current' not in topmenu(top))
    check('フッターはHOMEの次にブログ', re.search(r'class="log-footer-home" href="\./" aria-current="page">.*?</li><li><a href="\./blog">ブログ</a>', footer(top), re.S) is not None)
    check('カテゴリの一覧ではブログを現在地にしない', 'aria-current' not in topmenu(pub.get(f'/blog?category={cat}').text))
    r = front(blog_label='日々の記録', blog_topmenu='')
    top = pub.get('/').text
    check('チェックを外すとトップメニューに出さない', 'href="./blog"' not in topmenu(top))
    check('表示名を変えるとフッターとパンくずも変わる', '<a href="./blog">日々の記録</a>' in footer(top) and '日々の記録' in pub.get('/blog').text.split('log-breadcrumb')[1][:2000])
    editor = c.get('/admin/index.php?p=settings&section=log').text
    cands = json.loads(__import__('html').unescape(re.search(r'data-topmenu-manager[^>]*data-candidates="([^"]*)"', editor).group(1)))
    check('メニューの候補にブログ、カテゴリは ./blog?…', {'t': '日々の記録', 'u': './blog', 'k': 'ブログ（すべての投稿）'} in cands and any(x['u'] == f'./blog?category={cat}' for x in cands) and {'t': 'ようこそ', 'u': './', 'k': '固定ページ'} in cands)
    front()

    # --- Pages, feeds and the sitemap ---------------------------------------------------------------
    r = page_save(title='ブログ', slug='blog', body='x')
    check('ブログのURL名は固定ページに使えない', r.status == 422 and 'blog' in json.loads(r.text).get('error', ''))
    sitemap = pub.get('/?sitemap=xml').text
    check('サイトマップにトップとブログ、カテゴリはブログの下', f'<loc>{url}/</loc>' in sitemap and f'<loc>{url}/blog</loc>' in sitemap and f'<loc>{url}/blog?category={cat}</loc>' in sitemap)
    check('トップにした固定ページは元のURLで載せない', f'<loc>{url}/about</loc>' not in sitemap and f'<loc>{url}/privacy</loc>' in sitemap)
    feed = pub.get('/?feed=rss').text
    check('RSSのリンクはブログ', f'<link>{url}/blog</link>' in feed and third['id'] in feed)
    pages = c.get('/admin/index.php?p=log_pages').text
    check('管理画面の「ページを見る」はトップのURL', 'class="btn log-list-view" href=".././"' in pages or 'class="btn log-list-view" href="../"' in pages)

    # --- Without clean URLs -------------------------------------------------------------------------
    ptop = plain.get('/').text
    check('書き換えのないサーバーでもトップに固定ページ', 'このサイトの案内です。' in ptop)
    check('リンクは ./?pg=blog', 'href="./?pg=blog"' in ptop)
    r = plain.get('/?pg=blog')
    check('./?pg=blog が投稿の一覧', r.status == 200 and 'いちばん新しい記事' in r.text)
    check('一覧のリンクは ./?pg=blog&…', f'href="./?pg=blog&amp;category={cat}"' in r.text and 'href="./?pg=blog&amp;page=2"' in r.text)
    check('検索欄は ./?pg=blog と隠し欄のpg', 'action="./?pg=blog"><input type="hidden" name="pg" value="blog">' in r.text)
    check('./?pg=blog&page=2 も開ける（移さない）', plain.get('/?pg=blog&page=2').status == 200)
    r = plain.get('/?category=' + cat)
    check('トップの ./?category= は ./?pg=blog&category= へ', r.status == 301 and r.getheader('Location') == f'{plain_url}/?pg=blog&category={cat}')

    # --- A draft or locked top page -----------------------------------------------------------------------
    rev = re.search(r'name="revision" value="(\d+)"', c.get('/admin/index.php?p=log_page&id=' + about).text).group(1)
    page_save(page_id=about, revision=rev, title='ようこそ', slug='about', body='## はじめに\nこのサイトの案内です。', status='draft')
    r = pub.get('/')
    check('下書きのあいだ、読者はトップからブログへ', r.status == 301 and r.getheader('Location') == url + '/blog')
    check('ログイン中は下書きのトップを見られる', 'このサイトの案内です。' in c.get('/').text)
    check('設定画面に下書きの注意', '下書きです' in c.get('/admin/index.php?p=settings&section=log').text)
    rev = str(int(rev) + 1)
    page_save(page_id=about, revision=rev, title='ようこそ', slug='about', body='秘密の案内', status='published', lock_form='1', lock='1', lock_password='open-sesame')
    r = pub.get('/')
    check('鍵付きのトップは入力欄だけ', r.status == 200 and 'log-lock-form' in r.text and '秘密の案内' not in r.text)
    r = pub.post('/', {'nl_unlock': '1', 'item': about, 'lockkey': 'wrong'}, headers={'Origin': url})
    check('鍵の答えはトップへ戻る', r.status == 303 and r.getheader('Location') == url + '/?locked=wrong')
    r = pub.post('/', {'nl_unlock': '1', 'item': about, 'lockkey': 'open-sesame'}, headers={'Origin': url})
    check('正しいパスワードでトップが開く', '秘密の案内' in pub.get('/').text)

    # --- Off again --------------------------------------------------------------------------------------------
    settings('log_front', {'front_page': about, 'blog_slug': 'blog', 'blog_topmenu': '1', 'blog_footer': '1'})
    top = pub.get('/').text
    check('オフに戻すとトップは投稿の一覧', 'いちばん新しい記事' in top and 'href="./blog"' not in top)
    check('オフのときは ./about がそのまま開く', pub.get('/about').status == 200)
    r = pub.get('/blog')
    check('オフにしたあとの ./blog はトップへ', r.status == 301 and r.getheader('Location') == url + '/')
    r = pub.get(f'/blog?category={cat}')
    check('オフにしたあとの ./blog?category= はトップの一覧へ', r.status == 301 and r.getheader('Location') == f'{url}/?category={cat}')
    check('オフでも設定は残る', 'value="blog"' in c.get('/admin/index.php?p=settings&section=log').text)


def main():
    for port in (args.port, args.plain_port):
        with socket.socket() as sock: sock.bind((h.HOST, port))
    temp = ROOT / 'manga/dev/results' / ('front-test-' + secrets.token_hex(4)); site = temp / 'site'
    h.copy_server(site, ROOT / 'manga/server')
    processes = []
    try:
        os.environ['NAGIMANGA_PRETTY'] = '1'
        processes.append(base.start(site, args.port))
        os.environ.pop('NAGIMANGA_PRETTY')
        processes.append(base.start(site, args.plain_port))
        c, csrf, _ = base.install(args.port)
        run(site, c, csrf)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed = sum(ok for _, ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp / 'report.json').write_text(json.dumps({'passed': passed, 'total': len(checks), 'checks': checks}, ensure_ascii=False, indent=2), encoding='utf-8')
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == '__main__': main()
