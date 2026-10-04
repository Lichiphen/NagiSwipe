#!/usr/bin/env python3
"""Exercise start_preview.ps1 with throwaway data, a free loopback port and restarts."""
import argparse, hashlib, json, os, re, secrets, socket, subprocess, sys, time
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('root',nargs='?'); parser.add_argument('--port',type=int,default=5395); args=parser.parse_args()
ROOT=Path(args.root).resolve() if args.root else next(p for p in Path(__file__).resolve().parents if (p/'manga/server').is_dir())
sys.path.insert(0,str(ROOT/'manga/dev'))
import attack_test as h
import log_test as base
checks=[]
def check(name,ok): checks.append((name,bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}",flush=True)
def stop(process):
    if process and process.poll() is None:
        subprocess.run(['taskkill','/PID',str(process.pid),'/T','/F'],capture_output=True,check=True)
        process.wait(timeout=15)
def start(data,log):
    with log.open('wb') as output:
        process=subprocess.Popen(['powershell','-NoProfile','-ExecutionPolicy','Bypass','-File',str(ROOT/'manga/dev/start_preview.ps1'),'-Port',str(args.port),'-DataDirectory',str(data)],stdout=output,stderr=subprocess.STDOUT,creationflags=subprocess.CREATE_NO_WINDOW)
    for _ in range(120):
        if process.poll() is not None: raise RuntimeError(f'Preview exited {process.returncode}: '+log.read_text(errors='replace')[:500])
        try:
            h.Client(args.port).get('/'); return process
        except OSError: time.sleep(.1)
    stop(process); raise RuntimeError('Preview did not become ready')
def file_hashes(data):
    wanted=[data/'config.php',*(data/'log').rglob('*.php'),*(data/'log/media').rglob('*.jpg'),*(data/'log/media').rglob('*.webp')]
    return {str(p.relative_to(data)):hashlib.sha256(p.read_bytes()).hexdigest() for p in wanted if p.is_file()}
def main():
    with socket.socket() as probe: probe.bind((h.HOST,args.port))
    root=ROOT/'manga/dev/results'/('preview-test-'+secrets.token_hex(4)); root.mkdir(); data=root/'persisted-data'; process=None
    try:
        process=start(data,root/'launch-1.txt')
        before=h.Client(args.port).get('/admin/index.php').text
        check('空の専用データで初期設定画面を出す',not (data/'config.php').exists() and 'name="password2"' in before)
        c,csrf,key=base.install(args.port)
        payload=base.payload(body='起動スクリプトの記録\n次の起動でも残す本文')
        response=c.post('/admin/index.php',{'csrf':csrf,**payload}); pid=json.loads(response.text)['id']
        media=json.loads(c.post('/admin/index.php',{'csrf':csrf,'do':'log_upload'},files={'image':('persist.png',h.png(64,96),'image/png')}).text)['media']
        c.post('/admin/index.php',{'csrf':csrf,**base.payload(body='起動スクリプトの記録\n'+media['tag'],post_id=pid,revision=1)})
        check('起動中の専用データへ記事と画像を保存',response.status==200 and (data/'config.php').is_file() and h.Client(args.port).get('/?media='+media['id']).status==200)
        hashes=file_hashes(data); stop(process); process=None
        process=start(data,root/'launch-2.txt')
        check('プロセス終了後も同じ保存先で記事・画像・設定を保つ',file_hashes(data)==hashes and '起動スクリプトの記録' in h.Client(args.port).get('/?id='+pid).text and h.Client(args.port).get('/?media='+media['id']).status==200)
        client=h.Client(args.port); page=client.get('/admin/login.php'); client.post('/admin/login.php',{'csrf':h.csrf_of(page.text),'password':'log-test-password'}); ct=h.csrf_of(client.get('/admin/index.php?p=log').text)
        response=client.post('/admin/index.php',{'csrf':ct,**base.payload(body='再起動後に編集できる記録',post_id=pid,revision=2)})
        check('再起動後に同じ管理者でログイン・編集できる',response.status==200 and '再起動後に編集できる記録' in h.Client(args.port).get('/?id='+pid).text)
        existing=file_hashes(data); alternative=root/'never-created'
        busy=subprocess.run(['powershell','-NoProfile','-ExecutionPolicy','Bypass','-File',str(ROOT/'manga/dev/start_preview.ps1'),'-Port',str(args.port),'-DataDirectory',str(alternative)],capture_output=True,timeout=20,creationflags=subprocess.CREATE_NO_WINDOW)
        (root/'busy-port.txt').write_bytes(busy.stdout+busy.stderr)
        check('使用中ポートへ再起動しても既存データを変えない',busy.returncode==0 and not alternative.exists() and file_hashes(data)==existing and h.Client(args.port).get('/?id='+pid).status==200)
        check('起動指定は外部公開しないloopbackだけ','-S "127.0.0.1:$Port"' in (ROOT/'manga/dev/start_preview.ps1').read_text(encoding='utf-8-sig'))
    finally: stop(process)
    passed=sum(ok for _,ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (root/'preview-report.md').write_text('# ローカル起動スクリプトの再起動試験\n\n'+f'{passed} / {len(checks)} passed\n\n'+'\n'.join(f"- {'PASS' if ok else 'FAIL'}: {name}" for name,ok in checks)+'\n',encoding='utf-8')
    raise SystemExit(0 if passed==len(checks) else 1)
if __name__=='__main__':main()
