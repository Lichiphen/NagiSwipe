#!/usr/bin/env python3
"""Create/update a localhost-only embed gallery in an already installed development preview."""
import argparse, json, os, re, subprocess
from pathlib import Path
import attack_test as h
import log_test as base

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--port', type=int, default=5399)
parser.add_argument('--data', type=Path, required=True)
args = parser.parse_args()
HERE = Path(__file__).resolve().parent
samples = json.loads((HERE/'embed_samples.json').read_text(encoding='utf-8'))
manifest_path = HERE/'results'/f'embed-gallery-{args.port}.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8')) if manifest_path.exists() else {'posts':{}}
if manifest.get('data') and manifest['data'] != str(args.data.resolve()):
    raise SystemExit('The existing gallery belongs to a different data directory.')
client = h.Client(args.port)
login = client.get('/admin/login.php')
result = client.post('/admin/login.php', {'csrf':h.csrf_of(login.text),'password':'log-test-password'})
if result.status != 303: raise SystemExit('Local preview login failed. Use an isolated development fixture.')
csrf = h.csrf_of(client.get('/admin/index.php?p=log').text)
for index, sample in reversed(list(enumerate(samples, 1))):
    body = f"{index:02d}. {sample['name']}\n{sample['note']}\n\n{sample['url']}\n\n元の投稿 {sample['url']}"
    extra = {'new_categories':'埋め込みサンプル'}
    previous = manifest['posts'].get(sample['name'])
    if previous:
        edit = client.get('/admin/index.php?p=log_edit&id='+previous['id'])
        revision = re.search(r'name="revision" value="(\d+)"', edit.text)
        if revision: extra.update(post_id=previous['id'], revision=revision.group(1))
    saved = client.post('/admin/index.php', {'csrf':csrf, **base.payload(body=body, **extra)})
    if saved.status != 200: raise RuntimeError(f"{sample['name']}: {saved.text}")
    manifest['posts'][sample['name']] = {**sample,'id':json.loads(saved.text)['id']}
    manifest['data'] = str(args.data.resolve())
    manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
    print(f"{sample['name']}: saved",flush=True)
site = HERE.parent/'server'
cmd = h.php_cmd(0,False,site); cmd = cmd[:cmd.index('-S')]
code = "define('NAGIMANGA',true); require '"+(site/'lib/bootstrap.php').as_posix()+"'; require '"+(site/'lib/log.php').as_posix()+"'; echo json_encode(nl_taxonomy()['categories'],JSON_UNESCAPED_UNICODE);"
env = {**os.environ,'NAGIMANGA_DATA':str(args.data.resolve())}
taxonomy = json.loads(subprocess.run(cmd+['-r',code],env=env,capture_output=True,text=True,encoding='utf-8',check=True).stdout)
category = next(key for key,value in taxonomy.items() if value == '埋め込みサンプル')
manifest['gallery'] = f'http://localhost:{args.port}/?category={category}'
manifest_path.write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
print(manifest['gallery'])
