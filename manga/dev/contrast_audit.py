#!/usr/bin/env python3
"""
WCAG 2.2 AA colour contrast of the LOG and the admin, in all six themes, on an isolated copy.

    python manga/dev/contrast_audit.py            (needs Playwright for Python and Google Chrome)

Builds its own site with 40 posts (pictures, a manga, a category, a hashtag, the three content
warnings, a draft) so the pager has 10 pages, then measures in every theme, at PC and phone width:
  - every page: top, page 5 and the last page, category, hashtag, search, posts, 404, login,
    the LOG admin screens, the settings tabs, and the NagiMANGA admin in light and dark;
  - the three pager types (numbers with the page-number box, newer/older only, "more"),
    the mini blog and the tiles;
  - opened states: phone menu, share, content warnings, messages, bulk delete, the dialogs;
  - hover, and the keyboard focus ring of every control reached with Tab.
Text must reach 4.5:1 (large text 3:1); field borders, icons and focus rings 3:1.
Exit code 1 lists each problem with the pages and themes where it appears.
"""
import json
import re
import secrets
import shutil
import sys
from collections import defaultdict
from pathlib import Path

import attack_test as helper
from log_test import HERE, RESULTS, install, payload, start

PORT = 5402
BASE = f'http://127.0.0.1:{PORT}'
THEMES = ['light-blue', 'light-sage', 'light-paper', 'dark-navy', 'dark-charcoal', 'dark-plum']
WIDTHS = [1280, 390]
CHECK = (HERE / 'contrast_audit.js').read_text(encoding='utf-8')
PASSWORD = 'log-test-password'  # the local fixture from log_test.install
problems = defaultdict(set)
# Colours are judged at rest: no fades or colour transitions half way (the page's CSP is bypassed only to add this rule).
STILL = "document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = '*,*::before,*::after,::backdrop { transition:none !important; animation:none !important; }'; document.head.append(s); });"


def context(browser, **kw):
    ctx = browser.new_context(bypass_csp=True, reduced_motion='reduce', **kw)
    ctx.add_init_script(STILL)
    return ctx


def seed(c, csrf):
    """Posts enough for 10 pages of 4, and one of everything that has its own colours."""
    def post(data, files=None): return c.post('/admin/index.php', {'csrf': csrf, **data}, files=files)
    post({'do': 'log_settings', 'title': 'コントラスト確認', 'description': '6つのテーマを確かめるためのLOGです。', 'name': '確認する人', 'theme': 'light-blue'})
    images = [json.loads(post({'do': 'log_upload'}, files={'image': (f'p{i}.png', helper.png(320 + i * 40, 240), 'image/png')}).text)['media'] for i in range(3)]
    work = re.search(r'id=([A-Za-z0-9]{12})', post({'do': 'create', 'title': '確認の漫画', 'direction': 'rtl'}).getheader('Location')).group(1)
    for i in range(2): post({'do': 'upload', 'id': work}, files={'page': (f'{i}.png', helper.png(300, 420), 'image/png')})
    post(payload('分類のある記録\nカテゴリの確認です。', new_categories='日記'))
    cat = re.search(r'name="categories\[\]" value="([a-f0-9]{12})"', c.get('/admin/index.php?p=log').text).group(1)
    ids = {}
    for i in range(40):
        body = f'{i + 1}件目の記録\n本文の文章です。https://example.com/page のようなリンクも入ります。 #確認タグ'
        extra = {}
        if i % 5 == 0: body += '\n' + images[i % 3]['tag']
        if i % 3 == 0: extra['categories[]'] = cat
        if i == 7:
            body += '\n[Manga確認の漫画]'; extra['manga'] = json.dumps({'[Manga確認の漫画]': work})
        if i in (11, 12, 13): extra.update(rating=('sensitive', 'r18', 'r18g')[i - 11], warning='確認用の注意書き')
        if i == 15: extra['media_ratings'] = json.dumps({images[0]['id']: 'r18'}); body += '\n' + images[0]['tag']
        ids[i] = json.loads(post(payload(body, **extra)).text)['id']
    post(payload('書きかけの記録', status='draft'))
    return post, cat, ids


def check_page(page, label):
    for theme in THEMES:
        page.evaluate('t => document.documentElement.dataset.logTheme = t', theme)
        page.wait_for_timeout(60)
        for issue in page.evaluate(CHECK, 'page'):
            problems[issue].add(f'{label} [{theme}]')


def check_focus(page, path, label, presses):
    for theme in THEMES:
        page.goto(BASE + path); page.wait_for_load_state('networkidle')
        page.evaluate('t => document.documentElement.dataset.logTheme = t', theme)
        for _ in range(presses):
            page.keyboard.press('Tab')
            result = page.evaluate(CHECK, 'focus')
            if result and result != 'ok': problems[result].add(f'{label} [{theme}]')


def visit(page, path, label=None):
    page.goto(BASE + path); page.wait_for_load_state('networkidle')
    check_page(page, label or path)


def login(page):
    page.goto(BASE + '/admin/login.php')
    page.fill('input[type=password]', PASSWORD); page.press('input[type=password]', 'Enter')
    page.wait_for_load_state('networkidle')


def run(browser, post, cat, ids):
    tag = '/?tag=%E7%A2%BA%E8%AA%8D%E3%82%BF%E3%82%B0'
    post({'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '4', 'pager_form': '1', 'pager': 'numbers', 'pager_status': '1', 'post_nav': '1'})
    for pager in ('numbers', 'simple', 'more'):
        for layout in ('stream', 'grid'):
            post({'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '4', 'pager_form': '1', 'pager': pager, 'pager_status': '1', 'post_nav': '1'})
            post({'do': 'log_display_settings', 'layout': layout, 'related_by': 'both', 'related_order': 'updated', 'likes': '1', 'related': '1', 'new_days': '30', 'new_label': 'NEW'})
            for width in WIDTHS:
                ctx = context(browser, viewport={'width': width, 'height': 900}, is_mobile=width < 600, has_touch=width < 600)
                page = ctx.new_page()
                for path in ('/', '/?page=5', '/?page=10', '/?category=' + cat, tag):
                    visit(page, path, f'{pager}/{layout}/{width} {path}')
                    # The pager must really be there, or its colours were never measured.
                    expect = {'numbers': ['.log-pager-num', '.log-pager-jump input'], 'simple': ['.log-pager-step'], 'more': ['[data-pager-more]']}[pager]
                    for sel in (expect if path == '/' else ['.log-pager']):
                        if not any(el.is_visible() for el in page.query_selector_all(sel)):
                            problems[f'MISSING {sel}'].add(f'{pager}/{layout}/{width} {path}')
                    if pager == 'more' and path == '/' and page.query_selector('[data-pager-more]'):
                        page.click('[data-pager-more]'); page.wait_for_timeout(500)
                        check_page(page, f'{pager}/{layout}/{width} / after "more"')
                ctx.close()
    post({'do': 'log_preferences', 'visibility': 'public', 'posts_per_page': '4', 'pager_form': '1', 'pager': 'numbers', 'pager_status': '1', 'post_nav': '1'})
    post({'do': 'log_display_settings', 'layout': 'stream', 'related_by': 'both', 'related_order': 'updated', 'likes': '1', 'related': '1', 'new_days': '30', 'new_label': 'NEW'})

    for width in WIDTHS:
        ctx = context(browser, viewport={'width': width, 'height': 900}, is_mobile=width < 600, has_touch=width < 600)
        page = ctx.new_page()
        # Readers: posts (picture, manga, warnings, neighbours), search, 404, the login screen.
        for i in (0, 7, 11, 12, 13, 15, 39):
            visit(page, f'/?id={ids[i]}', f'{width} post {i + 1}')
            for opener in ('.log-veil > summary', '.log-share-open'):
                el = page.query_selector(opener)
                if el and el.is_visible():
                    el.click(); page.wait_for_timeout(300); check_page(page, f'{width} post {i + 1} opened {opener}')
                    page.keyboard.press('Escape'); page.wait_for_timeout(150)
        visit(page, '/?q=%E8%A8%98%E9%8C%B2', f'{width} search')
        visit(page, '/?id=00000000000000', f'{width} 404')
        visit(page, '/admin/login.php', f'{width} login')
        if width < 600:
            page.goto(BASE + '/'); page.click('.log-menu-toggle'); page.wait_for_timeout(300); check_page(page, 'phone menu')
        # The admin, logged in.
        login(page)
        for path in ('index.php?p=log', 'index.php?p=log&per_page=4&page=3', 'index.php?p=log&q=%E8%A8%98%E9%8C%B2', 'index.php?p=log&view=taxonomy',
                     'index.php?p=log_media', f'index.php?p=log_edit&id={ids[11]}', 'index.php?p=settings&section=log', 'index.php?p=settings&section=common', 'index.php?p=settings&section=manga'):
            visit(page, '/admin/' + path, f'{width} admin/{path}')
        visit(page, '/', f'{width} / logged in (editor)')
        page.goto(BASE + '/admin/index.php?p=log'); page.click('[data-bulk-toggle]'); check_page(page, f'{width} bulk delete bar')
        page.goto(BASE + '/admin/index.php?p=settings&section=log'); page.fill('[name=footer_text]', '変更の確認')
        check_page(page, f'{width} settings with changes')
        page.click('.workspace-nav a[href="index.php?p=log"]'); page.wait_for_selector('.settings-leave-dialog[open]')
        check_page(page, f'{width} leave dialog'); page.click('[data-leave="stay"]')
        page.request.post(BASE + '/admin/index.php', form={'csrf': page.eval_on_selector('input[name=csrf]', 'e => e.value'), 'do': 'settings_save_all', 'sections[]': 'access', 'allowed_ips': 'not-an-ip', 'allowed_origins': '', 'tab': 'common'})
        page.goto(BASE + '/admin/index.php?p=settings&section=common'); check_page(page, f'{width} error message')
        page.goto(BASE + f'/admin/index.php?p=log_edit&id={ids[3]}'); page.click('button[name=status][value=published]')
        page.wait_for_selector('.log-saved-dialog[open]'); check_page(page, f'{width} saved dialog')
        if width < 600:
            page.goto(BASE + '/admin/index.php?p=settings&section=log'); page.click('.settings-toc-fab'); check_page(page, 'settings contents dialog')
        ctx.close()

    # NagiMANGA admin screens follow the system light / dark setting, not the LOG theme.
    for scheme in ('light', 'dark'):
        ctx = context(browser, viewport={'width': 1280, 'height': 900}, color_scheme=scheme)
        page = ctx.new_page(); login(page)
        page.goto(BASE + '/admin/index.php')
        work = page.eval_on_selector('a[href*="p=work&id="]', 'a => a.getAttribute("href")')
        for path in ('index.php', work, 'index.php?p=embed', 'index.php?p=backup', 'index.php?p=update'):
            page.goto(BASE + '/admin/' + path); page.wait_for_load_state('networkidle')
            for issue in page.evaluate(CHECK, 'page'): problems[issue].add(f'admin/{path} [{scheme}]')
        ctx.close()

    # Hover and keyboard focus (PC).
    ctx = context(browser, viewport={'width': 1280, 'height': 900})
    page = ctx.new_page()
    check_focus(page, '/?page=5', 'focus /?page=5', 120)
    visit(page, '/?page=5', 'hover base')
    for sel in ('.log-pager a', '.log-pager-step', '.log-share-btn', '.log-sidebar a', '.log-post a', '.log-site-header a', '.log-categories > a'):
        for el in page.query_selector_all(sel)[:3]:
            if el.is_visible():
                el.hover(); check_page(page, f'hover {sel}')
    login(page)
    check_focus(page, '/admin/index.php?p=settings&section=log', 'focus settings', 60)
    check_focus(page, '/admin/index.php?p=log&per_page=4&page=3', 'focus admin list', 60)
    ctx.close()


def main():
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        raise SystemExit('Playwright for Python is needed: pip install playwright')
    RESULTS.mkdir(exist_ok=True)
    root = RESULTS / ('contrast-audit-' + secrets.token_hex(4))
    root.mkdir()
    site = root / 'site'
    shutil.copytree(HERE.parent / 'server', site, ignore=shutil.ignore_patterns('paths.php'))
    process = start(site, PORT)
    try:
        c, csrf, _ = install(PORT)
        post, cat, ids = seed(c, csrf)
        with sync_playwright() as p:
            browser = p.chromium.launch(channel='chrome')
            try: run(browser, post, cat, ids)
            finally: browser.close()
    finally:
        process.terminate(); process.wait(timeout=10)
    for issue, where in sorted(problems.items()):
        where = sorted(where)
        print(issue + '\n    ' + f'{len(where)} x ' + '; '.join(where[:5]) + (' …' if len(where) > 5 else ''))
    print(f'\n{len(problems)} problems' if problems else '\nAll pages pass WCAG 2.2 AA contrast in every theme.')
    raise SystemExit(1 if problems else 0)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main()
