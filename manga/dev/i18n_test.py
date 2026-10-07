#!/usr/bin/env python3
"""Every text passed to nm_t() / t() is in each lib/lang/*.json, with the same {placeholders} and no characters that break HTML."""
import json
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
SERVER = Path(__file__).resolve().parents[1] / 'server'
# A literal followed by "." or "+" is only the start of a longer text (nm_t('過去の' . $noun)).
CALL = re.compile(r"\b(?:nm_t|t)\(\s*'((?:[^'\\]|\\.)*)'(?!\s*[.+])")
CHOICE = re.compile(r"\b(?:nm_t|t)\(\s*[^'()]*\?\s*'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'")
JP = re.compile(r'[぀-ヿ一-鿿]')
# Texts that reach nm_t() from constants or built names rather than a literal.
EXTRA = ['新しい投稿', '過去の投稿', '投稿一覧のページ', '過去の投稿を{n}件', '最新の投稿から見る', '新しい画像', '過去の画像', '画像一覧のページ',
         '投稿一覧', 'カテゴリ・タグ', '固定ページ', 'LOGの設定', 'なし', 'センシティブ', '注意なし',
         '肌の露出・軽い流血など。画像をぼかします', '強い流血・暴力・グロテスク。記事を折りたたみ、年齢を確認します', '性的な表現。記事を折りたたみ、年齢を確認します',
         'LOG', '投稿を編集', '画像一覧', 'LOGの分類', '{name}：{message}']

keys = set(EXTRA)
for f in [*SERVER.rglob('*.php'), *SERVER.rglob('*.js')]:
    if 'data' in f.relative_to(SERVER).parts or f.name.startswith('NagiSwipe-main'):
        continue
    text = f.read_text(encoding='utf-8')
    for m in CALL.finditer(text):
        if JP.search(m.group(1)):
            keys.add(m.group(1).replace("\\'", "'"))
    for m in CHOICE.finditer(text):
        keys.update(k.replace("\\'", "'") for k in m.groups() if JP.search(k))
    # nm_t(cond(...) ? 'A' : 'B'): the condition may have its own parentheses, so take any choice after a call on the line.
    for line in text.split('\n'):
        at = max(line.find('nm_t('), line.find(' t('), line.find('(t('))
        if at < 0:
            continue
        for m in re.finditer(r"\?\s*'((?:[^'\\]|\\.)*)'\s*:\s*'((?:[^'\\]|\\.)*)'", line[at:]):
            keys.update(k.replace("\\'", "'") for k in m.groups() if JP.search(k))

# Labels kept in constants and translated where they are shown (nm_t(NL_THEMES[$key]) and so on).
LABEL_CONSTS = ['NM_SETTINGS_SECTIONS', 'NL_THEMES', 'NL_PAGERS', 'NL_CRUMB_HOMES', 'NL_LAYOUTS', 'NL_RELATED_BY', 'NL_RELATED_ORDER',
                'NL_LINK_ICONS', 'NL_SIDEBAR_BUILTINS', 'NL_PAGE_LAYOUTS', 'NL_MENUS', 'NL_RATINGS', 'NL_RATING_NOTES']
for f in SERVER.rglob('*.php'):
    if 'data' in f.relative_to(SERVER).parts:
        continue
    text = f.read_text(encoding='utf-8')
    for name in LABEL_CONSTS:
        m = re.search(r'const ' + name + r' = \[(.*?)\];', text, re.S)
        if m:
            keys.update(v for v in re.findall(r"'((?:[^'\\]|\\.)*)'", m.group(1)) if JP.search(v))

passed = failed = 0
def check(name, ok, detail=''):
    global passed, failed
    if ok:
        passed += 1
    else:
        failed += 1
        print('[FAIL]', name, detail)

placeholders = lambda s: sorted(re.findall(r'\{(\w+)\}', s))
TAG = re.compile(r'<[^<>]*>')
ENTITY = re.compile(r'&(?:#\d+|[a-z]+);')
def unsafe(key, value):
    """A translation may use only the tags and entities its Japanese text uses; otherwise no quotes, ampersands or brackets."""
    if sorted(TAG.findall(value)) != sorted(TAG.findall(key)):
        return True
    if not set(ENTITY.findall(value)) <= set(ENTITY.findall(key)):
        return True
    text = ENTITY.sub('', TAG.sub('', value))
    return bool(re.search(r'''['"<>&]''', text))
for lang in sorted((SERVER / 'lib' / 'lang').glob('*.json')):
    table = json.loads(lang.read_text(encoding='utf-8'))
    check(f'{lang.name}: _name', isinstance(table.get('_name'), str) and table['_name'] != '')
    missing = sorted(k for k in keys if k not in table)
    check(f'{lang.name}: every text is translated', not missing, '\n  ' + '\n  '.join(missing))
    for k, v in table.items():
        if k.startswith('_'):
            continue
        check(f'{lang.name}: same placeholders in {k}', placeholders(k) == placeholders(v), v)
        check(f'{lang.name}: no quotes or other markup in {k}', not unsafe(k, v), v)
print(f'\n{passed} / {passed + failed} passed')
sys.exit(1 if failed else 0)
