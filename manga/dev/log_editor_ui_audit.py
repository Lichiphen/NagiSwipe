#!/usr/bin/env python3
"""Post editor UI audit: clicks every tool, panel, menu and tab in each layout and checks the screen after each step.

    python manga/dev/log_editor_ui_audit.py

Layouts: the top page's editor with the floating dock (PC), the full-screen editor (PC), the edit page (PC),
a phone held upright and a phone turned sideways. After every step it checks that the bands over the text
(〔メディア1〕) lie on the text box, that the page does not scroll sideways, that the rating menu opens by its button
inside the screen, that the floating dock stays out of the editor, and that the tool row is above the text on a PC.
Screenshots and report.md go to dev/results/ui-audit-<random>/.
"""
import io
import json
import os
import re
import secrets
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import attack_test as h  # noqa: E402
import log_test as base  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

PORT = 5464
URL = f'http://{h.HOST}:{PORT}'

# Every check returns problems as text; an empty list is a pass.
CHECK = r'''() => {
  const out = [], panel = document.querySelector('#log-compose'), form = panel.querySelector('form'), ta = form.querySelector('#log-body');
  const layer = form.querySelector('.log-mark-layer'), shown = el => el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  if (shown(layer) && shown(ta)) {
    const a = layer.getBoundingClientRect(), b = ta.getBoundingClientRect(), cs = getComputedStyle(ta);
    const top = b.top + parseFloat(cs.borderTopWidth), left = b.left + parseFloat(cs.borderLeftWidth);
    if (Math.abs(a.top - top) > 1.5 || Math.abs(a.left - left) > 1.5 || Math.abs(a.width - ta.clientWidth) > 1.5)
      out.push(`本文の帯の層がずれている（層 ${Math.round(a.left)},${Math.round(a.top)} 幅${Math.round(a.width)} / 本文欄 ${Math.round(left)},${Math.round(top)} 幅${ta.clientWidth}）`);
    layer.querySelectorAll('.log-mark-band').forEach(band => {
      const r = band.getBoundingClientRect();
      if (r.bottom > b.top + 2 && r.top < b.bottom - 2 && (r.left < b.left - 8 || r.right > b.right + 8)) out.push('帯が本文欄の横にはみ出している');
    });
  }
  if (!shown(panel)) out.push('投稿画面が閉じてしまった');
  const head = panel.querySelector('.log-compose-head');
  if (shown(layer) && shown(head)) layer.querySelectorAll('.log-mark-band').forEach(band => {
    const r = band.getBoundingClientRect(), x = r.left + Math.min(r.width / 2, 30), y = r.top + r.height / 2, hr = head.getBoundingClientRect();
    if (y > hr.top && y < hr.bottom && x > hr.left && x < hr.right && band.contains(document.elementFromPoint(x, y))) out.push('本文の帯が投稿画面の見出しの上に重なっている');
  });
  if (document.documentElement.scrollWidth > innerWidth + 1) out.push(`ページが横にスクロールする（${document.documentElement.scrollWidth} > ${innerWidth}）`);
  const menu = document.querySelector('[data-att-menu]');
  if (menu && !menu.hidden) {
    const m = menu.getBoundingClientRect(), opener = document.querySelector('.log-att-rating[aria-expanded="true"]');
    if (m.left < -1 || m.right > innerWidth + 1 || m.top < -1 || m.bottom > innerHeight + 1) out.push('閲覧注意のメニューが画面からはみ出している');
    if (opener) { const o = opener.getBoundingClientRect(); const under = m.top >= o.bottom - 2 && m.top <= o.bottom + 40, over = m.bottom <= o.top + 2 && m.bottom >= o.top - 40; if ((!under && !over) || m.left > o.right || m.right < o.left) out.push('閲覧注意のメニューがボタンから離れている'); }
  }
  const dock = panel.querySelector('[data-media-dock]');
  if (panel.dataset.dock === 'float' && shown(dock) && !dock.classList.contains('is-tucked')) {
    const d = dock.getBoundingClientRect(), c = panel.getBoundingClientRect();
    if (d.right > c.left - 4) out.push('浮かべたメディア欄が投稿欄に重なっている');
    if (d.bottom > innerHeight + 1) out.push('浮かべたメディア欄が画面の下にはみ出している');
  }
  if (panel.dataset.dock === 'grid' && shown(dock)) { const d = dock.getBoundingClientRect(), f = form.getBoundingClientRect(); if (d.right > f.left) out.push('メディア欄が本文に重なっている'); }
  if (form.classList.contains('is-wide')) {
    const z = form.querySelector('[data-toolzone]');
    if (shown(ta) && z.getBoundingClientRect().top > ta.getBoundingClientRect().top) out.push('PC表示なのに道具が本文欄の下にある');
  } else {
    const bar = form.querySelector('.log-compose-bar'), att = form.querySelector('[data-attachments]');
    if (att && shown(att) && shown(bar) && bar.getBoundingClientRect().top > att.getBoundingClientRect().top) out.push('スマホ表示で道具の列がメディアより下にある');
  }
  const previewSheet = document.querySelector('.log-preview-sheet[open]');
  if (previewSheet) {
    const foot = previewSheet.querySelector('.log-preview-sheet-foot').getBoundingClientRect(), r = previewSheet.getBoundingClientRect();
    if (r.top > 1 || r.bottom < innerHeight - 1 || r.width < innerWidth - 1) out.push('全画面のプレビューが画面いっぱいでない');
    if (foot.bottom > innerHeight + 1 || foot.top < innerHeight - foot.height - 2) out.push('プレビューの閉じるが画面の下にない');
    if (form.classList.contains('is-wide')) out.push('PC表示なのに全画面のプレビューが開いている');
  }
  if (!previewSheet && document.documentElement.classList.contains('log-preview-open')) out.push('プレビューを閉じたのにページのスクロールが止まったまま');
  const toBody = panel.querySelector('[data-to-body]');
  if (toBody && shown(toBody) !== form.classList.contains('is-wide')) out.push(form.classList.contains('is-wide') ? 'PC表示なのに本文へのボタンがない' : 'スマホ表示で本文へのボタンが出ている');
  const submit = form.querySelector('.log-submit');
  if (shown(ta) && shown(submit)) { const s = submit.getBoundingClientRect(), t = ta.getBoundingClientRect(); if (getComputedStyle(submit).position !== 'sticky' && s.top < t.bottom - 1) out.push('保存ボタンの行が本文欄に重なっている'); }
  const sheet = document.querySelector('.log-panel-sheet[open]');
  if (sheet) { const r = sheet.getBoundingClientRect(); if (r.bottom > innerHeight + 1 || r.top < -1) out.push('下から出る設定シートが画面からはみ出している'); }
  return out;
}'''


def png(color):
    from PIL import Image
    b = io.BytesIO(); Image.new('RGB', (480, 360), color).save(b, 'PNG'); return b.getvalue()


def login(page, key):
    page.goto(f'{URL}/admin/index.php?k={key}')
    page.fill('input[name="password"]', 'log-test-password')
    with page.expect_navigation():
        page.press('input[name="password"]', 'Enter')


class Audit:
    def __init__(self, out):
        self.out, self.rows, self.n = out, [], 0

    def step(self, page, where, name, action=None, settle=350):
        error = ''
        try:
            if action:
                action()
            page.wait_for_timeout(settle)
            issues = page.evaluate(CHECK)
        except Exception as e:  # a step that cannot run is a finding too
            issues, error = [], f'{type(e).__name__}: {str(e).splitlines()[0][:200]}'
        self.n += 1
        shot = f'{self.n:03d}-{where}-{re.sub(r"[^0-9A-Za-z]+", "-", name)[:40]}.png'
        try:
            page.screenshot(path=str(self.out / shot))
        except Exception:
            shot = ''
        self.rows.append({'where': where, 'step': name, 'issues': issues, 'error': error, 'shot': shot})
        flag = 'NG' if issues or error else 'OK'
        print(f'[{flag}] {where}: {name}' + ''.join(f'\n    - {i}' for i in issues) + (f'\n    ! {error}' if error else ''), flush=True)


def tools_sequence(a, page, where, wide):
    """The steps every layout goes through, once the editor is open with a picture in the text."""
    q = lambda s: page.locator(s).first

    def toggle(name):
        def run():
            if page.locator('.log-panel-sheet[open]').count():
                page.click('.log-panel-sheet[open] [data-sheet-close]')
            q(f'[data-panel="{name}"]').click()
        return run

    def close_panel(name):
        def run():
            if page.locator('.log-panel-sheet[open]').count():
                page.click('.log-panel-sheet[open] [data-sheet-close]')
            elif q(f'[data-panel="{name}"]').get_attribute('aria-expanded') == 'true':
                q(f'[data-panel="{name}"]').click()
        return run

    for name in ('tags', 'categories', 'rating', 'lock', 'help'):
        a.step(page, where, f'{name}を開く', toggle(name))
        if name == 'rating':
            a.step(page, where, '閲覧注意：センシティブを選ぶ', lambda: q('.log-rating-choice[data-veil="sensitive"]').click())
            a.step(page, where, '閲覧注意：定型文を押す', lambda: q('[data-warning-preset]').click())
            a.step(page, where, '閲覧注意：R-18を選ぶ', lambda: q('.log-rating-choice[data-veil="r18"]').click())
        if name == 'categories':
            a.step(page, where, 'カテゴリ：新しいカテゴリを入力', lambda: q('input[name="new_categories"]').fill('日記'))
        if name == 'lock':
            a.step(page, where, 'パスワード：入力してオン', lambda: q('[data-lock-field]').fill('secret-pass'))
        a.step(page, where, f'{name}を閉じる', close_panel(name))
    a.step(page, where, '2つの設定を続けて開く（#→閲覧注意）', lambda: (toggle('tags')(), toggle('rating')()))
    a.step(page, where, '閉じる', close_panel('rating'))
    a.step(page, where, 'チップを押す', lambda: q('[data-chips] button').click())
    a.step(page, where, 'チップで開いた設定を閉じる', lambda: [close_panel(n)() for n in ('rating', 'lock', 'categories', 'tags')])

    def select_title():
        page.click('#log-body')
        page.keyboard.press('Control+Home'); page.keyboard.press('Shift+End')
    a.step(page, where, '太字', lambda: (select_title(), q('[data-format="bold"]').click()))
    a.step(page, where, '装飾メニューを開く', lambda: q('[data-format-menu]').click())
    a.step(page, where, '装飾メニューを閉じる', lambda: page.keyboard.press('Escape'))
    a.step(page, where, '本文に長い文章を入れる（帯より下まで）', lambda: (page.click('#log-body'), page.keyboard.press('Control+End'), page.keyboard.type('\n' + '長い文章の行です。\n' * 14)))
    a.step(page, where, '本文欄を上へスクロール', lambda: page.eval_on_selector('#log-body', 'b => b.scrollTop = 0'))
    a.step(page, where, 'サムネイルの閲覧注意を開く', lambda: q('.log-att-rating').click())
    a.step(page, where, 'サムネイルの閲覧注意：R-18G', lambda: q('[data-att-menu] [data-rate="r18g"]').click())
    a.step(page, where, '「本文の最後へ」', lambda: q('.log-att-action').click())
    a.step(page, where, '「カーソルの位置へ」', lambda: (page.click('#log-body'), page.keyboard.press('Control+Home'), page.keyboard.press('ArrowDown'), q('.log-att-action').click()))
    a.step(page, where, '「別の場所にもメディアを置く」', lambda: q('.log-att-new').click())
    if wide:
        a.step(page, where, 'プレビューのタブ', lambda: q('#log-tab-preview').click(), settle=900)
        a.step(page, where, '書くのタブに戻る', lambda: q('#log-tab-write').click())
        a.step(page, where, 'メディア一覧のタブ', lambda: q('#log-dock-tab-library').click(), settle=700)
        a.step(page, where, 'この投稿のタブ', lambda: q('#log-dock-tab-post').click())
        a.step(page, where, '道具の「メディア一覧から選ぶ」', lambda: q('[data-picker="media"]').click(), settle=700)
        a.step(page, where, 'この投稿のタブに戻る', lambda: q('#log-dock-tab-post').click())

        def to_body():
            q('#log-tab-preview').click(); page.wait_for_timeout(700)
            q('#log-dock-tab-library').click(); toggle('tags')()
            if not page.eval_on_selector('[data-to-body]', 'b => b.classList.contains("is-away")'):
                raise AssertionError('本文へが目立っていない（プレビュー・設定・メディア一覧を開いているのに）')
            # A phone turned sideways shows the panel as a modal sheet: it is closed first (by design).
            if page.locator('.log-panel-sheet[open]').count():
                page.click('.log-panel-sheet[open] [data-sheet-close]')
            q('[data-to-body]').click(); page.wait_for_timeout(500)
            state = page.evaluate('''() => { const f = document.querySelector('#log-compose form'), d = document.querySelector('[data-media-dock]');
              return [f.classList.contains('is-previewing'), !!f.querySelector('[data-panel][aria-expanded=true]'), document.activeElement === f.querySelector('#log-body'),
                !!d && d.getClientRects().length > 0 && d.querySelector('[data-dock-pane="post"]').hidden, document.querySelector('[data-to-body]').classList.contains('is-away')]; }''')
            if state != [False, False, True, False, False]:
                raise AssertionError(f'本文へで書く画面に戻っていない: {state}')
        a.step(page, where, '本文へ（プレビュー・#・メディア一覧を開いてから）', to_body, settle=600)
    else:
        a.step(page, where, 'プレビュー（目・全画面）', lambda: q('[data-preview]').click(), settle=900)
        a.step(page, where, 'プレビューを下までスクロール', lambda: page.evaluate("document.querySelector('.log-preview-sheet').scrollTo(0, 99999)"), settle=500)
        a.step(page, where, 'プレビューを閉じる（下の閉じる）', lambda: q('[data-preview-close]').click())
        a.step(page, where, 'プレビューをEscで閉じる', lambda: (q('[data-preview]').click(), page.wait_for_timeout(800), page.keyboard.press('Escape')))
        a.step(page, where, '道具の列を右の端へ', lambda: q('.log-tools-hint.is-end').click(), settle=700)
        a.step(page, where, '道具の列を左の端へ', lambda: q('.log-tools-hint.is-start').click(), settle=700)
    a.step(page, where, '漫画を選ぶ（モーダル）', lambda: q('[data-picker="manga"]').click(), settle=600)
    a.step(page, where, '漫画のモーダルを閉じる', lambda: page.click('[data-picker-close]'))
    # On phones the panel is a modal sheet: the text behind it is not touched until it closes (by design).
    a.step(page, where, '使い方を開いたまま本文を入力（スマホはシートを閉じてから）', lambda: (toggle('help')(), page.locator('.log-panel-sheet[open]').count() and page.click('.log-panel-sheet[open] [data-sheet-close]'), page.click('#log-body'), page.keyboard.type('追記')))
    a.step(page, where, '使い方を閉じる', close_panel('help'))


def add_picture(a, page, where, wide):
    if wide:
        a.step(page, where, 'メディア一覧のタブから1枚選ぶ', lambda: (page.click('#log-dock-tab-library'), page.wait_for_selector('.log-dock-grid button'), page.locator('.log-dock-grid button').first.click()), settle=600)
        a.step(page, where, 'この投稿のタブ', lambda: page.click('#log-dock-tab-post'))
    else:
        a.step(page, where, 'メディア一覧から1枚選ぶ（モーダル）', lambda: (page.locator('[data-picker="media"]').first.click(), page.wait_for_selector('.log-picker-grid button'), page.locator('.log-picker-grid button').first.click()), settle=600)


def main():
    h.RESULTS.mkdir(exist_ok=True)
    root = h.RESULTS / ('ui-audit-' + secrets.token_hex(4))
    site, out = root / 'site', root / 'shots'
    out.mkdir(parents=True)
    h.copy_server(site, HERE.parent / 'server')
    env = {k: v for k, v in os.environ.items() if k != 'NAGIMANGA_DATA'}
    process = subprocess.Popen(h.php_cmd(PORT, True, site), env=env, stdout=subprocess.DEVNULL, stderr=open(root / 'php.txt', 'wb'))
    a = Audit(out)
    errors = []
    try:
        for _ in range(100):
            try:
                h.Client(PORT).get('/admin/index.php'); break
            except OSError:
                time.sleep(.05)
        client, csrf, key = base.install(PORT)
        ids = []
        for c in ('#c0392b', '#2980b9', '#27ae60', '#8e44ad', '#d35400'):
            r = client.post('/admin/index.php', {'csrf': csrf, 'do': 'log_upload'}, files={'image': ('p.png', png(c), 'image/png')}, headers={'Accept': 'application/json'})
            ids.append(json.loads(r.text)['media']['id'])
        for n in range(4):
            client.post('/admin/index.php', {'csrf': csrf, 'do': 'log_save', 'body': f'記事{n}\n本文。\n\n[Image:{ids[n]}]\n\n' + '続き。' * 80, 'status': 'published',
                                              'nonce': secrets.token_hex(16), 'revision': '0', 'manga': '{}', 'media_ratings': '{}'}, headers={'Accept': 'application/json'})
        post_id = re.search(r'p=log_edit&(?:amp;)?id=([0-9a-z]+)', client.get('/admin/index.php?p=log').text).group(1)
        layouts = [
            ('top-pc', 'chromium', {'viewport': {'width': 1600, 'height': 900}}, True),
            ('full-pc', 'chromium', {'viewport': {'width': 1440, 'height': 860}}, True),
            ('edit-pc', 'chromium', {'viewport': {'width': 1280, 'height': 800}}, True),
            ('phone', 'chromium', 'Pixel 7', False),
            ('phone-side', 'webkit', 'iPhone 13 landscape', True),
        ]
        with sync_playwright() as pw:
            for where, engine, opts, wide in layouts:
                browser = getattr(pw, engine).launch()
                options = dict(pw.devices[opts]) if isinstance(opts, str) else dict(opts)
                context = browser.new_context(locale='ja-JP', **options)
                page = context.new_page()
                page.set_default_timeout(6000)
                page.on('pageerror', lambda e, w=where: errors.append(f'{w}: {e}'))
                page.on('dialog', lambda d: d.accept())
                login(page, key)
                if where == 'top-pc':
                    a.step(page, where, 'トップページを開く', lambda: (page.goto(URL + '/'), page.wait_for_load_state('networkidle')))
                    page.click('#log-body'); page.keyboard.type('タイトル\n本文の1行目\n')
                elif where == 'edit-pc':
                    a.step(page, where, '編集ページを開く', lambda: (page.goto(URL + '/admin/index.php?p=log_edit&id=' + post_id), page.wait_for_load_state('networkidle')))
                else:
                    page.goto(URL + '/admin/index.php?p=log'); page.wait_for_load_state('networkidle')
                    a.step(page, where, '投稿画面を開く', lambda: page.locator('[data-compose-open]:visible, .log-fab:visible').first.click(), settle=800)
                    page.click('#log-body'); page.keyboard.type('タイトル\n本文の1行目\n')
                if where != 'edit-pc':
                    add_picture(a, page, where, wide)
                tools_sequence(a, page, where, wide)
                if where == 'top-pc':
                    a.step(page, where, '下へスクロール（メディア欄が隠れる）', lambda: page.mouse.wheel(0, 700), settle=800)
                    a.step(page, where, '上へスクロール（メディア欄が戻る）', lambda: page.mouse.wheel(0, -250), settle=900)
                    a.step(page, where, '一番上へ戻る', lambda: page.evaluate('scrollTo(0, 0)'), settle=800)
                    a.step(page, where, '窓を狭くする（余白なし）', lambda: page.set_viewport_size({'width': 1100, 'height': 800}), settle=600)
                    a.step(page, where, '窓を広く戻す', lambda: page.set_viewport_size({'width': 1600, 'height': 900}), settle=600)
                if where == 'phone-side':
                    a.step(page, where, '縦に戻す', lambda: page.set_viewport_size({'width': 390, 'height': 844}), settle=700)
                    a.step(page, where, 'もう一度横にする', lambda: page.set_viewport_size({'width': 844, 'height': 390}), settle=700)
                browser.close()
    finally:
        process.terminate(); process.wait(timeout=10)
    bad = [r for r in a.rows if r['issues'] or r['error']]
    lines = ['# 投稿画面のUI自動確認', '', f'- 手順: {len(a.rows)}件（問題あり {len(bad)}件）', f'- ページのスクリプトエラー: {len(errors)}件', '']
    for e in errors:
        lines.append(f'  - {e}')
    current = None
    for r in a.rows:
        if r['where'] != current:
            current = r['where']; lines += ['', f'## {current}', '', '| # | 手順 | 結果 | キャプチャ |', '| --- | --- | --- | --- |']
        result = '<br>'.join(r['issues'] + ([r['error']] if r['error'] else [])) or 'OK'
        lines.append(f"| {a.rows.index(r) + 1} | {r['step']} | {result} | [{r['shot']}](shots/{r['shot']}) |")
    (root / 'report.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
    (root / 'report.json').write_text(json.dumps({'rows': a.rows, 'errors': errors}, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'\n{len(a.rows) - len(bad)} / {len(a.rows)} steps clean, {len(errors)} page errors\nreport: {root / "report.md"}')
    raise SystemExit(0 if not bad and not errors else 1)


if __name__ == '__main__':
    main()
