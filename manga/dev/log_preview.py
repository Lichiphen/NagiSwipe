#!/usr/bin/env python3
"""Seed an isolated localhost LOG preview at :5197 (never production data)."""
import json
import re
from pathlib import Path
from log_test import install, payload

ROOT = Path(__file__).resolve().parents[2]
if (Path(__file__).resolve().parent / 'results/log-preview-data/config.php').exists():
    raise SystemExit('Preview data already exists. Use the existing local preview.')
c, csrf, key = install(5197)


def post(data, files=None):
    return c.post('/admin/index.php', {'csrf': csrf, **data}, files=files)


post({'do': 'log_settings', 'title': '凪の記録', 'description': '描いたものと、日々の小さなこと。', 'name': 'Lichiphen'})
r = post({'do': 'create', 'title': '散歩のあとで', 'direction': 'rtl'})
wid = re.search(r'id=([A-Za-z0-9]{12})', r.getheader('Location')).group(1)
for name in ('Shimeshime.png', 'Fu-n.png', 'Kyururun.png'):
    post({'do': 'upload', 'id': wid}, files={'page': (name, (ROOT / 'img' / name).read_bytes(), 'image/png')})
image = json.loads(post({'do': 'log_upload'}, files={'image': ('Shimeshime.png', (ROOT / 'img/Shimeshime.png').read_bytes(), 'image/png')}).text)['media']
post(payload(body='今日の一枚。\n\n' + image['tag'] + '\n\n画像を押すと、NagiSwipeで拡大できます。'))
tag = '[Manga散歩のあとで]'
post(payload(body='短い漫画をひとつ。\n\n' + tag + '\n\n**描いたものを、気軽に残していこう。**', manga=json.dumps({tag: wid})))
post(payload(body='書きかけのメモ。あとで続きを書く。', status='draft'))
print('Preview login: http://127.0.0.1:5197/admin/index.php?k=' + key)
print('Preview password: log-test-password (local fixture only)')
