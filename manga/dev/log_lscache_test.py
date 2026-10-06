#!/usr/bin/env python3
"""LiteSpeed Cache headers: only public visitor pages are cacheable, writes purge, other servers get nothing."""
import argparse,json,os,secrets,shutil,socket,sys
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('root',nargs='?')
parser.add_argument('--port',type=int,default=5401)
parser.add_argument('--other-port',type=int,default=5402)
args=parser.parse_args()
ROOT=Path(args.root).resolve() if args.root else next(p for p in Path(__file__).resolve().parents if (p/'manga/server').is_dir())
sys.path.insert(0,str(ROOT/'manga/dev'))
import attack_test as h
import log_test as base
checks=[]
def check(name,ok):
    checks.append((name,bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}",flush=True)
def ls(r): return {k.lower():v for k,v in r.getheaders() if k.lower().startswith('x-litespeed')}

def run(site,c,csrf,plain,pt):
    pub=h.Client(args.port)
    def post(data,client=c,token=csrf): return client.post('/admin/index.php',{'csrf':token,**data})
    r=post(base.payload(body='キャッシュの記録\n本文'))
    pid=json.loads(r.text)['id']
    check('公開の投稿は古いページを1回返しながら作り直す（stale）',ls(r).get('x-litespeed-purge')=='stale,tag=nagilog')
    r=post(base.payload(body='下書きの記録\n本文',status='draft'))
    check('下書きへの保存はすぐ消す',ls(r).get('x-litespeed-purge')=='tag=nagilog')
    gone=json.loads(post(base.payload(body='消す記録\n本文')).text)['id']
    r=post({'do':'log_delete','post_id':gone,'revision':'1'})
    check('記事の削除はすぐ消す',ls(r).get('x-litespeed-purge')=='tag=nagilog')
    r=post({'do':'log_preferences','visibility':'public','posts_per_page':'10'})
    check('公開範囲・表示の保存はすぐ消す',ls(r).get('x-litespeed-purge')=='tag=nagilog')
    r=post({'do':'log_settings','title':'キャッシュLOG','name':'記録する人','description':'説明'})
    check('デザイン・サイト名の保存はstaleで消す',ls(r).get('x-litespeed-purge')=='stale,tag=nagilog')
    top=ls(pub.get('/'))
    check('訪問者の公開トップはキャッシュ（1時間・タグ付き）',top.get('x-litespeed-cache-control')=='public,max-age=3600' and top.get('x-litespeed-tag')=='nagilog')
    check('ログインCookieの有無でキャッシュを分ける',top.get('x-litespeed-vary')=='cookie=nm_admin')
    check('訪問者の個別記事もキャッシュ',ls(pub.get('/?id='+pid)).get('x-litespeed-cache-control')=='public,max-age=3600')
    owner=ls(c.get('/'))
    check('ログイン中の表示はキャッシュしない',owner.get('x-litespeed-cache-control')=='no-cache' and 'x-litespeed-tag' not in owner)
    fake=ls(h.Client(args.port).request('GET','/',headers={'Cookie':'nm_admin=0123456789abcdef0123456789abcdef'},cookies=False))
    check('古い・偽のログインCookieでもキャッシュしない',fake.get('x-litespeed-cache-control')=='no-cache' and 'x-litespeed-tag' not in fake)
    media=json.loads(c.post('/admin/index.php',{'csrf':csrf,'do':'log_upload'},files={'image':('a.png',h.png(20,20),'image/png')}).text)['media']
    post(base.payload(body='画像\n[Image:'+media['id']+']'))
    img=ls(pub.get('/?media='+media['id']+'&v=1'))
    check('画像はキャッシュしない（BOT対策をPHPで続ける）',img.get('x-litespeed-cache-control')=='no-cache' and 'x-litespeed-tag' not in img)
    nf=ls(pub.get('/?id=20990101000000',headers={'Accept':'text/html'}))
    check('存在しない記事・404はキャッシュしない',nf.get('x-litespeed-cache-control')=='no-cache' and 'x-litespeed-tag' not in nf)
    adm=ls(c.get('/admin/index.php'))
    check('管理画面はキャッシュしない',adm.get('x-litespeed-cache-control')=='no-cache')
    page=c.get('/admin/index.php?p=settings&section=common').text
    check('設定にLiteSpeedで動いていることを表示','このサーバーは LiteSpeed です。公開LOGのページをサーバーでキャッシュしています。' in page)
    post({'do':'log_preferences','visibility':'private','posts_per_page':'10'})
    memo=ls(pub.get('/',headers={'Accept':'text/html'}))
    check('自分専用のMemoはキャッシュしない',memo.get('x-litespeed-cache-control')=='no-cache' and 'x-litespeed-tag' not in memo)
    post({'do':'log_preferences','visibility':'public','posts_per_page':'10'})
    r=post({'do':'settings_lscache'})
    check('設定でオフにするときもキャッシュを消す',ls(r).get('x-litespeed-purge')=='tag=nagilog' and "'lscache' => false" in (site/'data/config.php').read_text(encoding='utf-8'))
    check('オフにするとLiteSpeedへの指示を送らない',ls(pub.get('/'))=={} and ls(post(base.payload(body='オフ後\n本文')))=={})
    post({'do':'settings_lscache','lscache':'1'})
    check('オンに戻すと再びキャッシュ',ls(pub.get('/')).get('x-litespeed-cache-control')=='public,max-age=3600')
    pub.get('/')
    check('プログラムを変えていなければキャッシュを消さない','x-litespeed-purge' not in ls(pub.get('/')))
    import time
    later=time.time()+5; os.utime(site/'lib/log.php',(later,later))
    first=ls(pub.get('/')); second=ls(pub.get('/'))
    check('プログラムを上書きしたら最初の表示で古いキャッシュを消す（1回だけ）',first.get('x-litespeed-purge')=='tag=nagilog' and 'x-litespeed-purge' not in second)
    check('.htaccessはLiteSpeedのときだけCacheLookupを使う','<IfModule LiteSpeed>\n    CacheLookup on\n</IfModule>' in (site/'.htaccess').read_text(encoding='utf-8'))

    # A server that is not LiteSpeed: nothing changes
    ppub=h.Client(args.other_port)
    r=plain.post('/admin/index.php',{'csrf':pt,**base.payload(body='普通のサーバー\n本文')})
    check('LiteSpeedでないサーバーには何も送らない',ls(r)=={} and ls(ppub.get('/'))=={} and ls(plain.get('/'))=={} and ls(ppub.get('/missing',headers={'Accept':'text/html'}))=={})
    sp=plain.get('/admin/index.php?p=settings&section=common').text
    check('LiteSpeedでないサーバーでは設定を変えられないと案内','LiteSpeed ではないため、この機能は動きません' in sp and 'name="lscache" value="1" checked disabled' in sp)
    check('LiteSpeedでないサーバーではページのCache-Controlも従来どおり',ppub.get('/').getheader('Cache-Control')=='no-cache')

def main():
    for port in (args.port,args.other_port):
        with socket.socket() as sock: sock.bind((h.HOST,port))
    temp=ROOT/'manga/dev/results'/('lscache-test-'+secrets.token_hex(4)); site=temp/'site'; plain_site=temp/'plain'
    for path in (site,plain_site): h.copy_server(path, ROOT/'manga/server')
    processes=[]
    try:
        os.environ['NAGIMANGA_LSCACHE_FORCE']='1'; processes.append(base.start(site,args.port))
        del os.environ['NAGIMANGA_LSCACHE_FORCE']; processes.append(base.start(plain_site,args.other_port))
        c,csrf,_=base.install(args.port); plain,pt,_=base.install(args.other_port)
        run(site,c,csrf,plain,pt)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed=sum(ok for _,ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp/'report.json').write_text(json.dumps({'passed':passed,'total':len(checks),'checks':checks},ensure_ascii=False,indent=2),encoding='utf-8')
    raise SystemExit(0 if passed==len(checks) else 1)
if __name__=='__main__': main()
