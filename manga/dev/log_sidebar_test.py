#!/usr/bin/env python3
"""Sidebar HTTP, persistence, image-sharing and backup checks using fresh private data."""
import argparse,base64,copy,hashlib,html,io,json,os,re,secrets,shutil,socket,subprocess,sys,zipfile
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('root',nargs='?')
parser.add_argument('--port',type=int,default=5394)
parser.add_argument('--other-port',type=int,default=5396)
args=parser.parse_args()
ROOT=Path(args.root).resolve() if args.root else next(p for p in Path(__file__).resolve().parents if (p/'manga/server').is_dir())
sys.path.insert(0,str(ROOT/'manga/dev'))
import attack_test as h
import log_test as base
checks=[]
def check(name,ok):
    checks.append((name,bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}",flush=True)
def php(site,code):
    prefix="define('NAGIMANGA',true); require '"+(site/'lib/bootstrap.php').as_posix()+"'; require '"+(site/'lib/log.php').as_posix()+"'; "
    cmd=h.php_cmd(0,False,site); cmd=cmd[:cmd.index('-S')]+['-r',prefix+code]
    r=subprocess.run(cmd,env=dict(os.environ,NAGIMANGA_DATA=str(site/'data')),capture_output=True,text=True,encoding='utf-8',check=True)
    if r.stderr: raise RuntimeError(r.stderr)
    return json.loads(r.stdout) if r.stdout else None
def literal(value):
    return "json_decode(base64_decode('"+base64.b64encode(json.dumps(value,ensure_ascii=False).encode()).decode()+"'),true)"
def side(site): return php(site,'echo json_encode(nl_sidebar_settings(),JSON_UNESCAPED_UNICODE);')
def hashes(site): return {str(p.relative_to(site/'data/log')):hashlib.sha256(p.read_bytes()).hexdigest() for p in (site/'data/log').rglob('*') if p.is_file()}
def block(text,bid):
    m=re.search(r'<div class="log-sidebar-block" data-sidebar-id="'+re.escape(bid)+r'">(.*?)(?=<div class="log-sidebar-block"|</aside>)',text,re.S)
    return m.group(1) if m else ''
def custom(text='',title='自由なHTML',framed=True,enabled=True):
    return {'id':'html-'+secrets.token_hex(8),'kind':'html','enabled':enabled,'title':title,'framed':framed,'html':text}
def zip_edit(blob,edit):
    out=io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(blob)) as src,zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as dst:
        for name in src.namelist():
            data=src.read(name)
            if name=='backup.json': mark=json.loads(data); edit(mark); data=json.dumps(mark,ensure_ascii=False).encode()
            dst.writestr(name,data)
    return out.getvalue()
def run(site,other_site,c,csrf,other,ot):
    pub=h.Client(args.port); opub=h.Client(args.other_port)
    def post(data,files=None,client=c,token=csrf,headers=None): return client.post('/admin/index.php',{'csrf':token,**data},files=files,headers=headers)
    def save_sidebar(items,revision=None,**kw):
        return post({'do':'log_sidebar_settings','items':json.dumps(items,ensure_ascii=False),'revision':str(side(site)['revision'] if revision is None else revision)},**kw)
    def ok_sidebar(items,**kw):
        r=save_sidebar(items,**kw)
        if r.status!=200: raise RuntimeError(f'Sidebar save {r.status}: {r.text[:400]}')
        return json.loads(r.text)
    def save(body,**extra):
        r=post(base.payload(body=body,**extra))
        if r.status!=200: raise RuntimeError(r.text)
        return json.loads(r.text)['id']
    def image(name):
        r=post({'do':'log_upload'},files={'image':(name+'.png',h.png(66,90),'image/png')})
        if r.status!=200: raise RuntimeError(r.text)
        return json.loads(r.text)['media']
    def image_path(m): return '/?media='+m['id']
    def delete(pid): return post({'do':'log_delete','post_id':pid,'revision':'1'})
    builtins=side(site)['items']; names=[x['id'] for x in builtins]
    check('保存前は標準7項目・revision0',names==['login','calendar','latest','categories','hashtags','updated','all'] and side(site)['revision']==0)
    save('分類を表示する記事\n本文 #監査タグ',new_categories='監査分類')
    check('標準7項目を既定順で公開',re.findall(r'data-sidebar-id="([^"]+)"',pub.get('/').text)==names)
    panel=c.get('/admin/index.php?p=settings&section=log').text
    check('LOG設定へ並び替え・3テンプレート・保存操作を用意','data-sidebar-manager' in panel and all('data-sidebar-template="'+x+'"' in panel for x in ('grid','banner','blank')) and 'data-sidebar-step="-1"' in panel and 'data-sidebar-step="1"' in panel)
    check('JavaScript無効時の案内・サイズ・外部画像の説明','<noscript>' in panel and '50KB' in panel and '1MB' in panel and '外部画像' in panel)
    check('バナーテンプレートのローカル画像が実在',pub.get('/viewer/og.jpg').status==200)
    check('ログイン項目に公開範囲設定との関係を説明','ログインリンクの表示もオンにしたときに出ます' in panel)
    templates=php(site,'echo json_encode(nl_sidebar_templates(),JSON_UNESCAPED_UNICODE);')
    additions=[custom(**{'text':t['html'],'title':t['title'],'framed':t['framed']}) for t in templates.values()]
    reordered=[builtins[2],additions[0],builtins[0],builtins[4],additions[1],builtins[1],builtins[3],additions[2],builtins[5],builtins[6]]
    response=ok_sidebar(reordered); stored=side(site)
    check('7項目と3HTML枠の順序を保存しrevisionを増加',response['ok'] is True and response['revision']==1 and stored['revision']==1 and response['items']==stored['items'])
    text=pub.get('/').text
    check('公開サイドバーをHTML枠を含む指定順で表示',re.findall(r'data-sidebar-id="([^"]+)"',text)==[x['id'] for x in reordered])
    check('グリッド・枠なしバナー・通常HTMLの装飾',all(x in block(text,additions[0]['id']) for x in ('log-link-grid','log-widget')) and 'log-widget' not in block(text,additions[1]['id']) and 'log-banner-link' in block(text,additions[1]['id']) and '<h2>' not in block(text,additions[1]['id']) and '<h2>自由なHTML</h2>' in block(text,additions[2]['id']))
    revised=copy.deepcopy(stored['items']); revised[0]['enabled']=False; revised[1]['enabled']=False
    ok_sidebar(revised)
    check('標準項目とHTML枠を個別に非表示','data-sidebar-id="latest"' not in pub.get('/').text and additions[0]['id'] not in pub.get('/').text and side(site)['items'][1]['html']==stored['items'][1]['html'])
    post({'do':'log_preferences','visibility':'public','posts_per_page':'10'})
    check('既存ログイン非表示設定を標準ブロックより優先','data-sidebar-id="login"' not in pub.get('/').text)
    post({'do':'log_preferences','visibility':'public','show_login':'1','posts_per_page':'10'})
    ok_sidebar(stored['items']); keep=side(site)
    for name,items in [
        ('標準項目の削除',builtins[:-1]),('重複ID',builtins+[builtins[0]]),('未知の標準項目',builtins+[{'id':'unknown','kind':'unknown','enabled':True}]),
        ('文字列のenabled',[{**x,'enabled':'true'} for x in builtins]),('不正HTML枠ID',builtins+[custom()|{'id':'html-wrong'}]),('文字列のframed',builtins+[custom()|{'framed':'true'}]),
        ('見出し101文字',builtins+[custom(title='字'*101)]),('見出し改行',builtins+[custom(title='一行\n二行')]),('見出し制御文字',builtins+[custom(title='制御\x01文字')]),
        ('HTML50001byte',builtins+[custom('a'*50001)]),('HTML配列',builtins+[custom()|{'html':[]}]),('JSONobject',{str(i):v for i,v in enumerate(builtins)}),('JSONnull',None),('全体1MB超',builtins+[custom('a'*49999) for _ in range(23)])
    ]:
        before=hashes(site); r=save_sidebar(items)
        check(name+'を422JSONで拒否し既存データを保持',r.status==422 and r.getheader('Content-Type').startswith('application/json') and isinstance(json.loads(r.text).get('error'),str) and hashes(site)==before)
    for revision in (keep['revision']-1,'','1x','-1','10000000000'):
        before=hashes(site); r=save_sidebar(builtins,revision)
        check('古い・不正revision拒否 '+repr(revision),r.status==422 and isinstance(json.loads(r.text).get('error'),str) and hashes(site)==before)
    escaped=custom('<p>'+'&'*11000+'</p>')
    before=hashes(site); r=save_sidebar(builtins+[escaped])
    check('サニタイズ展開後の50KB超も拒否',r.status==422 and hashes(site)==before)
    boundary=custom('a'*50000,title='字'*100)
    ok_sidebar(builtins+[boundary]); check('100文字タイトルと50000byteHTMLを保存',side(site)['items'][-1]['html']=='a'*50000 and len(side(site)['items'][-1]['title'])==100)
    many=[custom('<p>枠'+str(i)+'</p>') for i in range(105)]
    ok_sidebar(builtins+many); check('少なくとも105枠を固定個数制限なく保存',len(side(site)['items'])==112 and len(re.findall(r'data-sidebar-id="html-',pub.get('/').text))==105)
    evil=custom('<p onclick="alert(1)" style="color:red" id="x">日本語 <strong>太字</strong> &amp; 文字</p><script>alert(2)</script><iframe src="https://evil.example"></iframe><form><input></form><a href="javascript:alert(1)">危険</a><a href="https://example.com/" target="_blank">リンク</a><img src="http://example.org/x.png" onerror="alert(3)"><div class="log-link-grid unknown">二列</div>',title='<script>見出し</script>')
    returned=ok_sidebar(builtins+[evil]); cleaned=returned['items'][-1]['html']; view=block(pub.get('/').text,evil['id'])
    check('サニタイズしたHTMLを保存結果として返す',cleaned==side(site)['items'][-1]['html'] and cleaned in view and '<strong>太字</strong>' in cleaned)
    check('スクリプト・フォーム・イベント・style・危険URLを除去',not any(x in cleaned for x in ('<script','<iframe','<form','onclick','onerror','style=','id=','javascript:','http://example.org')))
    check('見出しは文字としてエスケープ','<h2>&lt;script&gt;見出し&lt;/script&gt;</h2>' in view)
    check('外部リンクの別窓にnoopener noreferrer','target="_blank" rel="noopener noreferrer"' in cleaned and 'class="log-link-grid"' in cleaned and 'unknown' not in cleaned)
    before=hashes(site); anon=h.Client(args.port)
    r=save_sidebar(builtins,client=anon); check('未ログインの設定変更を拒否',r.status==404 and hashes(site)==before)
    r=save_sidebar(builtins,token='bad'); check('不正CSRFの設定変更を拒否',r.status==404 and hashes(site)==before)
    r=save_sidebar(builtins,headers={'Origin':'https://evil.example'}); check('外部Originの設定変更を拒否',r.status==404 and hashes(site)==before)
    check('GETでサイドバー設定は変更しない',c.get('/admin/index.php?do=log_sidebar_settings').status==200 and hashes(site)==before)
    shutil.copy2(ROOT/'manga/plugins/guest-mode.php',site/'plugins/guest-mode.php'); guest=h.Client(args.port); guest.get('/admin/index.php?guest'); gt=h.csrf_of(guest.get('/admin/index.php').text)
    r=save_sidebar(builtins,client=guest,token=gt); check('ゲストの設定変更を拒否',r.status==403 and json.loads(r.text)['ok'] is False and hashes(site)==before)
    external=custom('<img src="https://img.example.org/a.png"><img src="https://img.example.org/b.png"><img src="https://cdn.example.net:8443/c.png"><a href="https://only-link.example/">外部リンク</a>')
    hiddenexternal=custom('<img src="https://hidden.example/a.png">',enabled=False)
    ok_sidebar(builtins+[external,hiddenexternal]); r=pub.get('/'); csp=r.getheader('Content-Security-Policy')
    imgpolicy=next(x for x in csp.split(';') if x.strip().startswith('img-src ')); scriptpolicy=next(x for x in csp.split(';') if x.strip().startswith('script-src '))
    check('可視画像のHTTPS originだけをCSPへ追加',imgpolicy.count('https://img.example.org')==1 and 'https://cdn.example.net:8443' in imgpolicy and 'only-link.example' not in imgpolicy and 'hidden.example' not in imgpolicy)
    check('画像許可をscript-srcへ流用しない','img.example.org' not in scriptpolicy and 'cdn.example.net' not in scriptpolicy)
    media=image('sidebar-only'); htmlmedia=custom('<img src="/?media='+media['id']+'">')
    check('未使用画像は公開しない',pub.get(image_path(media)).status==404)
    ok_sidebar(builtins+[htmlmedia]); check('可視HTML枠の画像を公開',pub.get(image_path(media)).status==200)
    before=hashes(site); post({'do':'log_media_delete','media':media['id'],'revision':'1'})
    check('HTML枠だけで使う画像の手動削除を拒否',hashes(site)==before and pub.get(image_path(media)).status==200)
    card=re.search(r'<article class="log-media-card" data-media-card="'+media['id']+r'".*?</article>',c.get('/admin/index.php?p=log_media').text,re.S).group(0)
    check('画像一覧でサイドバーの使用を表示し削除ボタンを無効化','0件で使用' not in card and re.search(r'<button class="btn small danger" disabled>',card) is not None)
    htmlmedia['enabled']=False; ok_sidebar(builtins+[htmlmedia]); before=hashes(site); post({'do':'log_media_delete','media':media['id'],'revision':'1'})
    check('非表示のHTML枠は画像を非公開にして保存画像を保護',pub.get(image_path(media)).status==404 and hashes(site)==before)
    for i,path in enumerate(('/?media=','./?media=','./log.php?media=','./index.php?media=','/./?media=','/index.php?media=','/%69ndex.php?media=')):
        m=image('alias'+str(i)); pid=save('共有画像alias\n'+m['tag']); item=custom('<img src="'+path+m['id']+'">',enabled=(i%2==0))
        ok_sidebar(builtins+[item]); r=delete(pid)
        local=php(site,'echo json_encode(nl_load_media('+literal(m['id'])+')!==null);')
        check('記事削除でHTML枠の画像aliasを保護 '+path,r.status==303 and local and pub.get(image_path(m)).status==(200 if item['enabled'] else 404))
    m=image('bulk'); pid=save('一括共有画像\n'+m['tag']); item=custom('<img src="/?media='+m['id']+'">')
    ok_sidebar(builtins+[item]); r=post({'do':'log_bulk_delete','posts':json.dumps([{'id':pid,'revision':1}])})
    check('一括削除でもサイドバー共有画像を残す',r.status==303 and pub.get(image_path(m)).status==200)
    n=image('linked-only'); pid=save('リンクのみ\n'+n['tag']); link=custom('<a href="/?media='+n['id']+'">画像リンク</a>')
    ok_sidebar(builtins+[link]); delete(pid)
    check('画像へのaリンクも共有参照として数え削除から保護',php(site,'echo json_encode(nl_load_media('+literal(n['id'])+')!==null);') and pub.get(image_path(n)).status==200)
    outside=image('foreign'); pid=save('外部画像\n'+outside['tag']); foreign=custom('<img src="https://other.example/?media='+outside['id']+'">')
    ok_sidebar(builtins+[foreign]); delete(pid)
    check('別サイトの同じmediaパラメータはローカル画像を保護しない',php(site,'echo json_encode(nl_load_media('+literal(outside['id'])+')===null);'))
    ok_sidebar(builtins+[item]); post({'do':'log_preferences','visibility':'private','show_login':'1','posts_per_page':'10'})
    check('Memoは可視HTML枠と画像を匿名へ漏らさない',pub.get('/').status==404 and pub.get(image_path(m)).status==404 and c.get('/').status==200 and c.get(image_path(m)).status==200)
    post({'do':'log_preferences','visibility':'public','show_login':'1','posts_per_page':'10'})
    ok_sidebar(builtins)
    check('HTML枠を外すと画像を非公開にし実ファイルは維持',pub.get(image_path(m)).status==404 and php(site,'echo json_encode(nl_load_media('+literal(m['id'])+')!==null);'))
    finalitem=custom('<p>復元する日本語</p><img src="./?media='+m['id']+'">',title='保存済み枠',framed=False)
    ok_sidebar(list(reversed(builtins))+[finalitem]); final=side(site)
    backup=post({'do':'log_backup'}).body; mark=json.loads(zipfile.ZipFile(io.BytesIO(backup)).read('backup.json'))
    check('ZIPへサイドバー順序・表示・HTML・revisionを保存',mark['sidebar']==final)
    post({'do':'log_restore'},files={'backup':('sidebar.zip',backup,'application/zip')},client=other,token=ot)
    restored=side(other_site)
    check('新しい設置先へ相対media参照を含むサイドバー復元',restored['items']==final['items'] and restored['revision']==1 and opub.get(image_path(m)).status==200 and '復元する日本語' in opub.get('/').text)
    otheritems=copy.deepcopy(restored['items']); otheritems[-1]['title']='移転先で変更'; before_rev=restored['revision']
    r=post({'do':'log_sidebar_settings','revision':str(before_rev),'items':json.dumps(otheritems,ensure_ascii=False)},client=other,token=ot)
    check('復元先で設定を編集できる',r.status==200 and side(other_site)['revision']==before_rev+1)
    before=side(other_site); post({'do':'log_restore'},files={'backup':('merge.zip',backup,'application/zip')},client=other,token=ot)
    check('上書きなしの復元では既存サイドバーを保持',side(other_site)==before)
    post({'do':'log_restore','overwrite':'1'},files={'backup':('overwrite.zip',backup,'application/zip')},client=other,token=ot)
    check('上書き復元は内容を置換し復元先revisionを進める',side(other_site)['items']==final['items'] and side(other_site)['revision']==before['revision']+1)
    legacy=zip_edit(backup,lambda x:x.pop('sidebar')); before=side(other_site)
    post({'do':'log_restore','overwrite':'1'},files={'backup':('legacy.zip',legacy,'application/zip')},client=other,token=ot)
    check('サイドバー欠落の旧ZIPは既存サイドバーを保持',side(other_site)==before)
    for name,edit in [('標準欠落',lambda x:x['sidebar']['items'].pop(0)),('重複',lambda x:x['sidebar']['items'].append(x['sidebar']['items'][0])),('不正revision',lambda x:x['sidebar'].__setitem__('revision',-1)),('数字キーJSONobject',lambda x:x['sidebar'].__setitem__('items',{str(i):v for i,v in enumerate(x['sidebar']['items'])}))]:
        before=hashes(other_site); post({'do':'log_restore','overwrite':'1'},files={'backup':('invalid.zip',zip_edit(backup,edit),'application/zip')},client=other,token=ot)
        check('不正サイドバーZIPを拒否し既存データ保持 '+name,hashes(other_site)==before)
    cleanbackup=post({'do':'log_backup'}).body
    post({'do':'log_restore','overwrite':'1'},files={'backup':('self.zip',cleanbackup,'application/zip')})
    check('自分で作ったZIPを自分へ復元できる',side(site)['items']==final['items'] and pub.get(image_path(m)).status==200)
    # Also verify installation-prefix resolution with admin SCRIPT_NAME, without changing saved records.
    prefix=php(site,"$_SERVER['HTTP_HOST']='127.0.0.1:"+str(args.port)+"';$_SERVER['SCRIPT_NAME']='/nagimanga/admin/index.php';echo json_encode(nl_base_url());")
    check('base_url未設定の管理側で設置サブフォルダを判定',prefix=='http://127.0.0.1:'+str(args.port)+'/nagimanga')
    nested_ids=php(site,"$_SERVER['HTTP_HOST']='127.0.0.1:"+str(args.port)+"';$_SERVER['SCRIPT_NAME']='/nagimanga/admin/index.php';echo json_encode(nl_sidebar_media_ids());")
    check('サブフォルダ設置でも相対URLのmediaを認識',nested_ids==[m['id']])
    admin_folder_public=php(site,"define('NL_FRONT',true);$_SERVER['HTTP_HOST']='127.0.0.1:"+str(args.port)+"';$_SERVER['SCRIPT_NAME']='/admin/index.php';echo json_encode(nl_base_url());")
    check('adminという名前の公開設置先もbase_url未設定で維持',admin_folder_public=='http://127.0.0.1:'+str(args.port)+'/admin')
    admin_folder_owner=php(site,"$_SERVER['HTTP_HOST']='127.0.0.1:"+str(args.port)+"';$_SERVER['SCRIPT_NAME']='/admin/admin/index.php';echo json_encode(nl_base_url());")
    check('adminという設置先の管理画面も同じ公開URLを導出',admin_folder_owner=='http://127.0.0.1:'+str(args.port)+'/admin')
    # Origin/path aliases are CLI-only checks: no request reaches the example hosts.
    # Each call restores the same private fixture's config and sidebar in finally.
    encoded='%E3%83%86%E3%82%B9%E3%83%88'
    for label,baseurl,src,tag,expect in [
        ('HTTPSの省略baseと明示443','https://example.test','https://example.test:443/', 'img',True),
        ('HTTPSの明示443baseと省略URL','https://example.test:443','https://example.test/', 'img',True),
        ('HTTPの省略baseと明示80','http://example.test','http://example.test:80/', 'a',True),
        ('HTTPの明示80baseと省略URL','http://example.test:80','http://example.test/', 'a',True),
        ('HTTPSbaseと別scheme HTTPリンク','https://example.test','http://example.test/', 'a',False),
        ('HTTPbaseと別scheme HTTPS画像','http://example.test','https://example.test/', 'img',False),
        ('同じhostの別port画像','https://example.test','https://example.test:8443/', 'img',False),
        ('別hostの画像','https://example.test','https://foreign.test/', 'img',False),
        ('encoded日本語baseと相対index','https://example.test/'+encoded,'./index.php', 'img',True),
        ('encoded日本語baseと絶対path','https://example.test/'+encoded,'/'+encoded+'/./index.php', 'img',True),
    ]:
        url=src+'?media='+m['id']; markup='<'+tag+' '+('src' if tag=='img' else 'href')+'="'+url+'">'+('画像リンク</a>' if tag=='a' else '')
        code="$oldcfg=nm_config();$oldside=nl_sidebar_settings();try{$cfg=$oldcfg;$cfg['base_url']="+literal(baseurl)+";nm_save_config($cfg);$s=nl_sidebar_defaults();$s['items'][]="+literal(custom(markup))+";nl_write_record(nl_root().'/sidebar.php',$s);$ids=nl_sidebar_media_ids();}finally{nm_save_config($oldcfg);nl_write_record(nl_root().'/sidebar.php',$oldside);}echo json_encode($ids);"
        check('共有参照URLの同値判定 '+label,php(site,code)==([m['id']] if expect else []))
    # Restart against the same audit data, then check stored order/HTML and anonymous media.
    expected=side(site); before=hashes(site); processes[0].terminate(); processes[0].wait(timeout=10); processes[0]=base.start(site,args.port)
    check('サーバー再起動でサイドバー記録と画像公開を保持',side(site)==expected and hashes(site)==before and pub.get(image_path(m)).status==200 and '保存済み枠' in pub.get('/').text)
    post({'do':'log_settings','title':'独立監査','description':'保存する','name':'監査者','theme':'dark-plum'}); keep=side(site)
    check('デザイン設定を変えてもサイドバーは不変',keep['items']==final['items'])
def main():
    if args.port==args.other_port: raise RuntimeError('Two separate unused ports required')
    for port in (args.port,args.other_port):
        with socket.socket() as sock: sock.bind((h.HOST,port))
    temp=ROOT/'manga/dev/results'/('sidebar-test-'+secrets.token_hex(4)); site=temp/'site'; other_site=temp/'other'
    for path in (site,other_site): shutil.copytree(ROOT/'manga/server',path,ignore=shutil.ignore_patterns('paths.php'))
    global processes
    processes=[]
    try:
        processes.extend((base.start(site,args.port),base.start(other_site,args.other_port)))
        c,csrf,_=base.install(args.port); other,ot,_=base.install(args.other_port)
        run(site,other_site,c,csrf,other,ot)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed=sum(ok for _,ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp/'report.json').write_text(json.dumps({'passed':passed,'total':len(checks),'checks':checks},ensure_ascii=False,indent=2),encoding='utf-8')
    raise SystemExit(0 if passed==len(checks) else 1)
if __name__=='__main__': main()
