#!/usr/bin/env python3
"""Independent HTTP and physical cleanup checks against an isolated audit root."""
import argparse, base64, html, io, json, os, re, secrets, shutil, socket, subprocess, sys, time, zipfile
from pathlib import Path
from urllib.parse import quote
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('root',nargs='?',help='Repository or independent snapshot root')
parser.add_argument('--port',type=int,default=5394)
parser.add_argument('--other-port',type=int,default=5396)
parser.add_argument('--fault-injection',action='store_true',help='Also force an index write failure; expected PHP warnings are retained')
args=parser.parse_args()
ROOT=Path(args.root).resolve() if args.root else next(p for p in Path(__file__).resolve().parents if (p/'manga/server').is_dir())
PORT,OTHER_PORT=args.port,args.other_port
sys.path.insert(0,str(ROOT/'manga/dev'))
import attack_test as h
import log_test as base
checks=[]
def check(name,ok):
    checks.append((name,bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}",flush=True)
def php(site,code):
    prefix="define('NAGIMANGA',true); require '"+(site/'lib/bootstrap.php').as_posix()+"'; require '"+(site/'lib/log.php').as_posix()+"'; "
    args=h.php_cmd(0,False,site); args=args[:args.index('-S')]+['-r',prefix+code]
    r=subprocess.run(args,env=dict(os.environ,NAGIMANGA_DATA=str(site/'data')),capture_output=True,text=True,encoding='utf-8',check=True)
    return json.loads(r.stdout) if r.stdout else None
def literal(value):
    encoded=base64.b64encode(json.dumps(value,ensure_ascii=False).encode()).decode()
    return "json_decode(base64_decode('"+encoded+"'),true)"
def state(site):
    return php(site,"$posts=[];foreach(glob(nl_root().'/posts/*/*.php')?:[] as $f){$p=nl_load_post(basename($f,'.php'));if($p)$posts[$p['id']]=$p;} $media=[];foreach(nl_list_media() as $m){$media[$m['id']]=['dir'=>nl_media_dir($m['id']),'files'=>array_map('basename',glob(nl_media_dir($m['id']).'/*')?:[])];} $receipts=[];foreach(glob(nl_root().'/receipts/*.php')?:[] as $f)$receipts[basename($f,'.php')]=nl_read_record($f);echo json_encode(['root'=>nl_root(),'posts'=>$posts,'media'=>$media,'receipts'=>$receipts,'taxonomy'=>nl_taxonomy(),'settings'=>nl_settings(),'index'=>nl_read_record(nl_root().'/index.php'),'ordered'=>nl_ordered_hashtags(),'recent'=>nl_recent_hashtags(false,8)],JSON_UNESCAPED_UNICODE);")
def posts_of(s): return s['posts'] if isinstance(s['posts'],dict) else {}
def hash_log(site):
    import hashlib
    return {str(p.relative_to(site/'data/log')):hashlib.sha256(p.read_bytes()).hexdigest() for p in (site/'data/log').rglob('*') if p.is_file()}
def zip_edit(blob,edit):
    out=io.BytesIO()
    with zipfile.ZipFile(io.BytesIO(blob)) as src,zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as dest:
        for name in src.namelist():
            data=src.read(name)
            if name=='backup.json': mark=json.loads(data); edit(mark); data=json.dumps(mark,ensure_ascii=False).encode()
            dest.writestr(name,data)
    return out.getvalue()
def main():
    if PORT==OTHER_PORT: raise RuntimeError('Choose two separate, unused ports')
    for port in (PORT,OTHER_PORT):
        with socket.socket() as probe: probe.bind((h.HOST,port))
    temp=ROOT/'manga/dev/results'/('cleanup-test-'+secrets.token_hex(4)); site=temp/'site'; other_site=temp/'other'
    for path in (site,other_site): shutil.copytree(ROOT/'manga/server',path,ignore=shutil.ignore_patterns('paths.php'))
    processes=[]
    try:
        processes.extend((base.start(site,PORT),base.start(other_site,OTHER_PORT)))
        c,csrf,key=base.install(PORT); other,ot,_=base.install(OTHER_PORT); public=h.Client(PORT); other_public=h.Client(OTHER_PORT)
        def post(data,files=None,client=c,token=csrf,headers=None): return client.post('/admin/index.php',{'csrf':token,**data},files=files,headers=headers)
        def save(body,**extra):
            data=base.payload(body=body,**extra); response=post(data)
            if response.status!=200: raise RuntimeError(f'{response.status}: {response.text[:200]}')
            return json.loads(response.text)['id'],data
        def footer(text,show=True): return post({'do':'log_footer_settings','footer_text':text,**({'show_footer':'1'} if show else {})})
        def order(kind,ids,rev=None):
            return post({'do':'log_taxonomy_order','kind':kind,'order':json.dumps(ids,ensure_ascii=False),'revision':state(site)['taxonomy']['revision'] if rev is None else rev})
        def bulk(items,**kw): return post({'do':'log_bulk_delete','posts':json.dumps(items,ensure_ascii=False)},**kw)
        def item(pid): return {'id':pid,'revision':posts_of(state(site))[pid]['revision']}
        def image(name):
            response=post({'do':'log_upload'},files={'image':(name+'.png',h.png(64,88),'image/png')})
            if response.status!=200: raise RuntimeError(response.text)
            return json.loads(response.text)['media']
        design={'do':'log_settings','title':'清掃監査','description':'保存と削除の確認','name':'監査者','theme':'light-blue'}
        check('既定のフッターを表示','<footer class="log-site-footer">Powered by NagiLog＆NagiManga</footer>' in public.get('/').text)
        footer(' 私の記録 © 2026 ')
        check('フッターの前後空白を除いて日本語を保存','<footer class="log-site-footer">私の記録 © 2026</footer>' in public.get('/').text and state(site)['settings']['footer_text']=='私の記録 © 2026')
        footer('表示を隠す',False); check('フッター非表示でも入力を保管','<footer' not in public.get('/').text and state(site)['settings']['footer_text']=='表示を隠す')
        footer(''); check('空欄のフッターは表示しない','<footer' not in public.get('/').text)
        evil='<script>alert(1)</script> <img src=x onerror=alert(2)> " &'
        footer(evil); fm=re.search(r'<footer class="log-site-footer">(.*?)</footer>',public.get('/').text,re.S)
        check('フッターのHTMLを文字として表示',fm is not None and html.unescape(fm.group(1))==evil and '<script' not in fm.group(1) and '<img' not in fm.group(1))
        footer('字'*200); check('フッター200文字を保存',state(site)['settings']['footer_text']=='字'*200)
        for text in ('字'*201,'改行\n文字','タブ\t文字','制御\x01文字'):
            before=state(site)['settings']; footer(text); check('フッター不正入力で既存設定を保持: '+repr(text[:10]),state(site)['settings']==before)
        before=state(site)['settings']; post({'do':'log_footer_settings','footer_text[]':'array','show_footer':'1'})
        check('フッター配列入力を拒否',state(site)['settings']==before)
        footer('保存するフッター',False); post(design); post({'do':'log_preferences','visibility':'public','show_login':'1','posts_per_page':'10'})
        check('デザインと公開範囲の保存はフッターを保持',state(site)['settings']['show_footer'] is False and state(site)['settings']['footer_text']=='保存するフッター')
        footer('非公開のフッター'); post({'do':'log_preferences','visibility':'private','show_login':'1','posts_per_page':'10'})
        check('Memoのフッターを未ログインへ漏らさない',public.get('/').status==404 and '非公開のフッター' not in public.get('/').text)
        post({'do':'log_preferences','visibility':'public','show_login':'1','posts_per_page':'10'}); footer('引っ越し先の表記')

        php(site,"$t=nl_taxonomy();$t['categories']['123456789012']='数字カテゴリ';nl_write_record(nl_root().'/taxonomy.php',$t);")
        pid,payload=save('分類テスト\n#タグA #123 #タグC',new_categories='分類A,分類B',**{'categories[0]':'123456789012'})
        draft,_=save('秘密の分類\n#秘密タグ',status='draft')
        initial=state(site); cats=list(initial['taxonomy']['categories']); tags=initial['ordered']; cat_reverse=list(reversed(cats)); tag_reverse=list(reversed(tags))
        r=order('category',cat_reverse); check('カテゴリの順序とrevisionを保存',r.status==200 and json.loads(r.text)['revision']==initial['taxonomy']['revision']+1 and list(state(site)['taxonomy']['categories'])==cat_reverse)
        r=order('hashtag',tag_reverse); check('数字タグを含む順序を保存',r.status==200 and state(site)['taxonomy']['hashtag_order']==tag_reverse)
        page=public.get('/').text; cat_section=re.search(r'<h2>カテゴリ</h2>(.*?)</section>',page,re.S).group(1)
        tag_section=re.search(r'<h2>ハッシュタグ</h2>(.*?)</section>',page,re.S).group(1)
        check('公開カテゴリを指定順で表示',re.findall(r'category=([a-f0-9]{12})',cat_section)==cat_reverse)
        check('公開タグを指定順で表示し下書きタグを隠す',[html.unescape(x) for x in re.findall(r'>#([^<]+)</a>',tag_section)]==[tag for tag in tag_reverse if tag!='秘密タグ'])
        check('最近8件タグの利用順は並び替えで変えない',state(site)['recent']==initial['recent'])
        admin_page=c.get('/admin/index.php?p=log&view=taxonomy').text
        groups=re.findall(r'<div class="log-taxonomy-group" data-sort-kind="([^"]+)">(.*?)(?=<h3>|</section>)',admin_page,re.S)
        check('分類管理にDnD・上下ボタンと順序情報を用意','draggable="true"' in admin_page and 'data-sort-step="-1"' in admin_page and 'data-sort-step="1"' in admin_page and 'data-taxonomy-manager' in admin_page and 'log-manage.js?v=' in admin_page)
        editor=c.get('/admin/index.php?p=log').text
        check('投稿欄カテゴリにも指定順を適用',re.findall(r'name="categories\[\]" value="([^"]+)"',editor)==cat_reverse)
        current_revision=state(site)['taxonomy']['revision']
        invalid=[cat_reverse[:-1],[cat_reverse[0]]*len(cat_reverse),cat_reverse[:-1]+['abcdefabcdef'],[123456789012]+[x for x in cat_reverse if x!='123456789012'],{'0':cat_reverse[0]},None]
        for idx,ids in enumerate(invalid):
            before=hash_log(site); response=order('category',ids)
            check('カテゴリ並び替えの不正配列を拒否: '+str(idx),response.status==422 and hash_log(site)==before)
        before=hash_log(site); response=order('category',cats,rev=current_revision-1)
        check('古いrevisionの並び替えで順序を変えない',response.status==422 and hash_log(site)==before)
        before=hash_log(site); response=order('unknown',cat_reverse)
        check('未知の分類種別を拒否',response.status==422 and hash_log(site)==before)
        response=post({'do':'log_taxonomy_order','kind':'category','order':'[broken','revision':current_revision})
        check('壊れた順序JSONを拒否',response.status==422 and list(state(site)['taxonomy']['categories'])==cat_reverse)
        for idx,ids in enumerate((tag_reverse[:-1],tag_reverse[:-1]+['未知タグ'],[123 if x=='123' else x for x in tag_reverse],[tag_reverse[0]]*len(tag_reverse))):
            before=hash_log(site); response=order('hashtag',ids)
            check('タグ順序の不正配列を拒否: '+str(idx),response.status==422 and hash_log(site)==before)
        renamed=post({'do':'log_taxonomy_rename','kind':'category','old':'123456789012','name':'数字の分類を改名','revision':state(site)['taxonomy']['revision']})
        check('カテゴリ改名後もIDと順序を保つ',renamed.status==303 and list(state(site)['taxonomy']['categories'])==cat_reverse)
        post({'do':'log_taxonomy_rename','kind':'hashtag','old':'タグA','name':'タグC','revision':state(site)['taxonomy']['revision']})
        expected_tags=list(dict.fromkeys('タグC' if x=='タグA' else x for x in tag_reverse))
        check('タグ改名の合流後に順序を重複させない',state(site)['taxonomy']['hashtag_order']==expected_tags and '#タグA' not in posts_of(state(site))[pid]['body'])
        newer,_=save('新しい分類\n#新規タグ',new_categories='分類追加')
        check('新しい分類は保存済み順序の末尾へ追加',list(state(site)['taxonomy']['categories'])[:len(cat_reverse)]==cat_reverse and state(site)['ordered'][-1]=='新規タグ')
        backup=post({'do':'log_backup'}).body; mark=json.loads(zipfile.ZipFile(io.BytesIO(backup)).read('backup.json'))
        check('バックアップにフッターと分類順序を保存',mark['settings']['footer_text']=='引っ越し先の表記' and mark['settings']['show_footer'] is True and list(mark['taxonomy']['categories'])==list(state(site)['taxonomy']['categories']) and mark['taxonomy']['hashtag_order']==expected_tags)
        local_payload=base.payload(body='復元先に残す記録\n#既存タグ',new_categories='復元先だけの分類')
        local_id=json.loads(post(local_payload,client=other,token=ot).text)['id']; local_cats=list(state(other_site)['taxonomy']['categories'])
        # Give the destination-only fixture a distinct year so timestamp IDs cannot coincide across independent installs.
        php(other_site,"$p=nl_load_post("+literal(local_id)+");$old=nl_post_file($p['id']);$p['id']='20230101120000';nl_write_record(nl_post_file($p['id']),$p);unlink($old);$f=nl_root().'/receipts/"+local_payload['nonce']+".php';$r=nl_read_record($f);$r['id']=$p['id'];nl_write_record($f,$r);nl_rebuild_index();")
        local_id='20230101120000'
        post({'do':'log_footer_settings','footer_text':'復元先だけの表記'},client=other,token=ot)
        post({'do':'log_taxonomy_order','kind':'hashtag','order':json.dumps(['既存タグ'],ensure_ascii=False),'revision':state(other_site)['taxonomy']['revision']},client=other,token=ot)
        post({'do':'log_restore'},files={'backup':('merge.zip',backup,'application/zip')},client=other,token=ot)
        merged=state(other_site)
        check('追加復元は既存カテゴリの順序を先に保つ',list(merged['taxonomy']['categories'])==local_cats+list(state(site)['taxonomy']['categories']))
        check('追加復元は既存タグ順序とフッターを保つ',merged['taxonomy']['hashtag_order']==['既存タグ']+expected_tags and merged['settings']['footer_text']=='復元先だけの表記' and merged['settings']['show_footer'] is False)
        post({'do':'log_restore','overwrite':'1'},files={'backup':('sorted.zip',backup,'application/zip')},client=other,token=ot)
        os_=state(other_site); check('別秘密鍵への上書き復元でバックアップ順を先に戻す',os_['settings']['footer_text']=='引っ越し先の表記' and list(os_['taxonomy']['categories'])==list(state(site)['taxonomy']['categories'])+local_cats and os_['taxonomy']['hashtag_order']==expected_tags)
        check('上書き復元も復元先だけにある記事と分類を消さない',local_id in posts_of(os_) and posts_of(os_)[local_id]['body']==local_payload['body'] and all(x in os_['taxonomy']['categories'] for x in local_cats))
        other.post('/admin/index.php',{'csrf':ot,'do':'log_footer_settings','footer_text':'旧形式復元先','show_footer':'0'})
        legacy=zip_edit(backup,lambda m:[m['settings'].pop(x,None) for x in ('footer_text','show_footer')]+[m['taxonomy'].pop('hashtag_order',None)])
        post({'do':'log_restore','overwrite':'1'},files={'backup':('legacy.zip',legacy,'application/zip')},client=other,token=ot)
        check('旧形式ZIPは復元先のフッターとタグ順序を保つ',state(other_site)['settings']['footer_text']=='旧形式復元先' and state(other_site)['settings']['show_footer'] is False and state(other_site)['taxonomy']['hashtag_order']==expected_tags)
        malformed=[lambda m:m['settings'].update({'show_footer':'false'}),lambda m:m['settings'].update({'footer_text':'行\n改行'}),lambda m:m['taxonomy'].update({'hashtag_order':['重複','重複']}),lambda m:m['taxonomy'].update({'hashtag_order':[123]})]
        for idx,edit in enumerate(malformed):
            before=hash_log(other_site); post({'do':'log_restore','overwrite':'1'},files={'backup':('bad.zip',zip_edit(backup,edit),'application/zip')},client=other,token=ot)
            check('フッター・並び順の壊れた復元は原状維持: '+str(idx),hash_log(other_site)==before)

        # Every media class is separate so physical deletion can be checked directly.
        media={name:image(name) for name in ('unique','shared','draft','icon','ogp','unused','batch')}
        php(site,"$m=nl_load_media("+literal(media['unique']['id'])+");$old=nl_media_dir($m['id']);$m['id']='1234567890123456';nl_write_record($old.'/media.php',$m);rename($old,nl_media_dir($m['id']));")
        media['unique']['id']='1234567890123456'; media['unique']['tag']='[Image:1234567890123456]'
        settings_path=site/'data/log/settings.php'
        php(site,"$s=nl_settings();$s['icon']="+literal(media['icon']['id'])+";$s['og_image']="+literal(media['ogp']['id'])+";nl_write_record(nl_root().'/settings.php',$s);")
        survivor,_=save('残す公開記事\n'+media['shared']['tag'])
        keep_draft,_=save('残す下書き\n'+media['draft']['tag'],status='draft')
        made=post({'do':'create','title':'残す漫画','direction':'rtl'}); wid=re.search(r'id=([A-Za-z0-9]{12})',made.getheader('Location')).group(1)
        post({'do':'upload','id':wid},files={'page':('page.png',h.png(90,120),'image/png')})
        manga=json.loads(c.get('/admin/index.php?p=log_manga_json').text)['items'][0]
        a,ap=save('削除記事A\n'+'\n'.join(media[x]['tag'] for x in ('unique','shared','draft','icon','ogp','batch'))+'\n'+manga['tag'],manga=json.dumps({manga['tag']:wid}))
        b,bp=save('削除記事B\n'+media['batch']['tag'])
        save('削除記事Aを編集\n'+'\n'.join(media[x]['tag'] for x in ('unique','shared','draft','icon','ogp','batch'))+'\n'+manga['tag'],post_id=a,revision=1,manga=json.dumps({manga['tag']:wid}))
        baseline=state(site); batch=[item(a),item(b)]
        listing=c.get('/admin/index.php?p=log&per_page=100').text
        check('公開記事に記事を見るURL、下書きにはリンクを出さない','class="btn log-list-view" href="../?id='+a+'"' in listing and 'class="btn log-list-view" href="../?id='+keep_draft+'"' not in listing)
        check('上部の一括削除モードと対象revisionを用意','data-bulk-toggle' in listing and 'data-bulk-form' in listing and 'data-bulk-all' in listing and 'data-bulk-submit' in listing and f'data-bulk-item value="{a}" data-revision="2"' in listing)
        invalid_batches=[[],batch+[batch[0]],batch[:-1]+[{'id':b,'revision':0}],batch+[{'id':'20240101123456','revision':1}],[{'id':'../config','revision':1}],[{'id':a,'revision':'2'}],[{'id':123,'revision':1}],{'object':batch[0]}]
        for idx,items in enumerate(invalid_batches):
            before=hash_log(site); response=bulk(items)
            check('一括削除の不正対象で一部も消さない: '+str(idx),response.status==303 and hash_log(site)==before)
        before=hash_log(site); response=post({'do':'log_bulk_delete','posts':'[broken'})
        check('壊れた削除JSONでデータを消さない',response.status==303 and hash_log(site)==before)
        for revision in ('','2junk','2.0'):
            before=hash_log(site); response=post({'do':'log_delete','post_id':a,'revision':revision})
            check('単独削除revisionの不正入力を拒否: '+repr(revision),response.status==303 and hash_log(site)==before)
        before=hash_log(site); response=post({'do':'log_delete','post_id':a,'revision[]':'2'})
        check('単独削除revisionの配列入力を拒否',response.status==303 and hash_log(site)==before)
        for revision in ('','1junk','1.5'):
            before=hash_log(site); response=post({'do':'log_taxonomy_order','kind':'category','order':json.dumps(list(state(site)['taxonomy']['categories'])),'revision':revision})
            check('並び替えrevisionの不正入力を拒否: '+repr(revision),response.status==422 and hash_log(site)==before)
        for do in ('log_bulk_delete','log_taxonomy_order','log_footer_settings'):
            data={'do':do,'posts':json.dumps(batch),'kind':'category','order':json.dumps(list(state(site)['taxonomy']['categories'])),'revision':state(site)['taxonomy']['revision'],'footer_text':'偽装','show_footer':'1'}
            before=hash_log(site)
            check('匿名の新操作を拒否: '+do,public.post('/admin/index.php',{'csrf':csrf,**data}).status==404 and hash_log(site)==before)
            check('不正CSRFの新操作を拒否: '+do,post(data,token='wrong').status==404 and hash_log(site)==before)
            check('外部Originの新操作を拒否: '+do,post(data,headers={'Origin':'https://evil.invalid'}).status==404 and hash_log(site)==before)
        shutil.copy2(ROOT/'manga/plugins/guest-mode.php',site/'plugins/guest-mode.php'); guest=h.Client(PORT); guest.get('/admin/index.php?guest'); gt=h.csrf_of(guest.get('/admin/index.php').text)
        for do in ('log_bulk_delete','log_taxonomy_order','log_footer_settings'):
            before=hash_log(site); response=post({'do':do,'posts':json.dumps(batch),'kind':'category','order':'[]','revision':0,'footer_text':'偽装'},client=guest,token=gt)
            check('ゲストの新操作を拒否: '+do,response.status==403 and hash_log(site)==before)
        before=hash_log(site); c.get('/admin/index.php?do=log_bulk_delete&posts='+quote(json.dumps(batch)))
        check('GETの一括削除指定では消さない',hash_log(site)==before)

        # An unreadable index target forces the transaction to roll back after staging.
        if args.fault_injection:
            index_path=site/'data/log/index.php'; old_index=index_path.read_bytes(); index_path.unlink(); index_path.mkdir()
            before=state(site); response=bulk(batch)
            rolled=state(site)
            check('一覧保存失敗で記事・画像・receiptを巻き戻す',response.status==303 and rolled['posts']==before['posts'] and rolled['media']==before['media'] and rolled['receipts']==before['receipts'] and not list((site/'data/log').glob('.delete-*')))
            index_path.rmdir(); index_path.write_bytes(old_index)
        # Hide the surviving shared-image post from the derived index only.
        php(site,"$i=nl_read_record(nl_root().'/index.php');unset($i['posts']["+literal(survivor)+"]);nl_write_record(nl_root().'/index.php',$i);")
        before=state(site); media_dirs={name:Path(before['media'][m['id']]['dir']) for name,m in media.items()}
        manga_dir=Path(php(site,"echo json_encode(nm_work_dir("+literal(wid)+"));"))
        manga_files={str(p.relative_to(site/'data')):p.read_bytes() for p in manga_dir.rglob('*') if p.is_file()}
        receipt_keep={k:v for k,v in before['receipts'].items() if v['id'] not in (a,b)}
        response=bulk(batch); after=state(site)
        check('有効な一括削除で指定2件だけ原本を削除',response.status==303 and a not in posts_of(after) and b not in posts_of(after) and {k:v for k,v in posts_of(before).items() if k not in (a,b)}==posts_of(after))
        for name in ('unique','batch'):
            check('専用画像の本体・thumb・情報フォルダを消す: '+name,not media_dirs[name].exists() and media[name]['id'] not in after['media'] and c.get('/admin/index.php?p=log_image&media='+media[name]['id']).status==404)
        for name in ('shared','draft','icon','ogp','unused'):
            check('保護した画像ファイルを残す: '+name,media_dirs[name].exists() and sorted(p.name for p in media_dirs[name].iterdir())==sorted(before['media'][media[name]['id']]['files']) and c.get('/admin/index.php?p=log_image&media='+media[name]['id']).status==200)
        check('一覧から欠けた共有記事を原本で保護して再構築',survivor in posts_of(after) and survivor in after['index']['posts'] and public.get('/?media='+media['shared']['id']).status==200)
        check('削除記事の作成・編集receiptだけを消す',all(v['id'] not in (a,b) for v in after['receipts'].values()) and after['receipts']==receipt_keep and ap['nonce'] not in after['receipts'] and bp['nonce'] not in after['receipts'])
        check('削除記事の派生index参照と仮置きフォルダを消す',a not in after['index']['posts'] and b not in after['index']['posts'] and not list((site/'data/log').glob('.delete-*')))
        check('漫画原本・表紙・ページは残す',bool(manga_files) and all((site/'data'/name).read_bytes()==data for name,data in manga_files.items()))
        check('同じ年の残す記事があれば年フォルダを保つ',(site/'data/log/posts'/survivor[:4]).is_dir())
        single=media['unused']; solo,sp=save('単独削除の記録\n'+single['tag'])
        post({'do':'log_delete','post_id':solo,'revision':1})
        check('単独削除も専用画像とreceiptを掃除',not media_dirs['unused'].exists() and not (site/'data/log/receipts'/f"{sp['nonce']}.php").exists() and public.get('/?id='+solo).status==404)
        old,oldp=save('別年の最後の記事')
        php(site,"$old=nl_load_post("+literal(old)+");$p=$old;$p['id']='20240101123456';nl_write_record(nl_post_file($p['id']),$p);unlink(nl_post_file($old['id']));$r=nl_read_record(nl_root().'/receipts/"+oldp['nonce']+".php');$r['id']=$p['id'];nl_write_record(nl_root().'/receipts/"+oldp['nonce']+".php',$r);nl_rebuild_index();")
        post({'do':'log_delete','post_id':'20240101123456','revision':1})
        check('最後の別年記事を消して空の年フォルダも掃除',not (site/'data/log/posts/2024').exists() and (site/'data/log/posts'/survivor[:4]).exists())
        promoted,dp=save('公開前の下書き',status='draft'); published,pp=save('下書きから公開した記録',post_id=promoted,revision=1)
        before=state(site); post({'do':'log_delete','post_id':published,'revision':2}); after=state(site)
        check('下書きから公開した記事の旧・新receiptを消す',dp['nonce'] in before['receipts'] and pp['nonce'] in before['receipts'] and dp['nonce'] not in after['receipts'] and pp['nonce'] not in after['receipts'])
        check('削除済み記事の編集再送を拒否',post(base.payload(body='古い編集',post_id=published,revision=2)).status==422)
        text_only,tp=save('作成再送の仕様'); post({'do':'log_delete','post_id':text_only,'revision':1}); retry=post(tp)
        check('receipt削除後の旧作成POSTは新規保存になる',retry.status==200 and len([p for p in posts_of(state(site)).values() if p['body']=='作成再送の仕様'])==1)
        # The same second can reuse an unsuffixed deleted ID; record count is authoritative.
        drafts=[{'id':p['id'],'revision':p['revision']} for p in posts_of(state(site)).values() if p['status']=='draft']
        bulk(drafts); check('最後の下書きを消して空の下書きフォルダも掃除',not (site/'data/log/posts/drafts').exists() and not any(p['status']=='draft' for p in posts_of(state(site)).values()))
        empty_tag=post({'do':'log_delete','post_id':pid,'revision':posts_of(state(site))[pid]['revision']}); visible=public.get('/').text
        check('使用記事がなくなったタグを公開メニューへ出さない','#タグC</a>' not in visible and '#123</a>' not in visible)

        obj_id,_=save('JSON型を調べる専用記事'); before=hash_log(site)
        response=bulk({'0':item(obj_id)})
        check('数字キーのJSONobjectも削除配列として受け入れない',response.status==303 and hash_log(site)==before)
        known_cats=list(state(site)['taxonomy']['categories']); before=hash_log(site)
        response=order('category',{str(i):v for i,v in enumerate(reversed(known_cats))})
        check('数字キーのJSONobjectも並び順配列として受け入れない',response.status==422 and hash_log(site)==before)
        om=json.loads(post({'do':'log_upload'},files={'image':('empty.png',h.png(72,88),'image/png')},client=other,token=ot).text)['media']
        post(base.payload(body='空フォルダ確認\n'+om['tag']),client=other,token=ot)
        all_other=[{'id':p['id'],'revision':p['revision']} for p in posts_of(state(other_site)).values()]
        response=bulk(all_other,client=other,token=ot)
        check('最後の記事画像を消して空guard付きposts/media/receiptsを掃除',response.status==303 and not any((other_site/'data/log'/x).exists() for x in ('posts','media','receipts')) and not posts_of(state(other_site)))

        nf=public.get('/?id=20240101000000',headers={'Accept':'text/html'})
        plain=public.get('/?id=20240101000000',headers={'Accept':'application/json'})
        check('新404のHTMLはSVG・状態404・検索除外',nf.status==404 and '<svg class="nm-blackhole"' in nf.text and nf.getheader('X-Robots-Tag')=='noindex, nofollow' and 'LOGへ戻る' in nf.text)
        check('404のplain応答を維持',plain.status==404 and plain.text=='Not Found' and plain.getheader('Content-Type').startswith('text/plain'))
        head=public.request('HEAD','/?id=20240101000000',headers={'Accept':'text/html'}); check('404のHEADに本文を返さない',head.status==404 and not head.body)
        css_url=html.unescape(re.search(r'<link rel="stylesheet" href="([^"]+)"',nf.text).group(1)); css=public.get(css_url)
        check('404の装飾を取得して動きを減らす指定を持つ',css.status==200 and 'prefers-reduced-motion' in css.text)
        (temp/'fixture-summary.json').write_text(json.dumps({'posts_after':len(posts_of(state(site))),'media_after':len(state(site)['media']),'expected_injected_error':'index.php temporarily made a directory to test rollback' if args.fault_injection else None},ensure_ascii=False,indent=2),encoding='utf-8')
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
    passed=sum(ok for _,ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (ROOT/'manga/dev/results/cleanup-report.md').write_text('# フッター・分類順序・記事削除の独立試験\n\n'+f'{passed} / {len(checks)} passed\n\n'+'\n'.join(f"- {'PASS' if ok else 'FAIL'}: {name}" for name,ok in checks)+'\n',encoding='utf-8')
    raise SystemExit(0 if passed==len(checks) else 1)
if __name__=='__main__': main()
