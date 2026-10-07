#!/usr/bin/env python3
"""LOG embeds (Tegalog notation) and animated GIF checks using fresh private data."""
import argparse,http.server,json,os,re,secrets,shutil,socket,subprocess,sys,threading
from pathlib import Path
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('root',nargs='?')
parser.add_argument('--port',type=int,default=5397)
parser.add_argument('--other-port',type=int,default=5398)
args=parser.parse_args()
ROOT=Path(args.root).resolve() if args.root else next(p for p in Path(__file__).resolve().parents if (p/'manga/server').is_dir())
sys.path.insert(0,str(ROOT/'manga/dev'))
import attack_test as h
import log_test as base
checks=[]
def check(name,ok):
    checks.append((name,bool(ok))); print(f"[{'PASS' if ok else 'FAIL'}] {name}",flush=True)

def gif(frames=2,comment=b"<?php echo 'x'; ?>",tail=b'<script>alert(1)</script>',truncate=False):
    out=b'GIF89a'+bytes([1,0,1,0,0x80,0,0])+b'\x00\x00\x00\xff\xff\xff'
    out+=b'\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00'
    if comment: out+=b'\x21\xfe'+bytes([len(comment)])+comment+b'\x00'
    out+=b'\x21\xff\x0bXMP DataXMP\x05<x/>\x00\x00'
    for i in range(frames):
        out+=b'\x21\xf9\x04\x00\x0a\x00\x00\x00'+b'\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00'+(b'\x02\x02\x44\x01\x00' if i%2==0 else b'\x02\x02\x4c\x01\x00')
    out+=b'\x3b'+tail
    return out[:-len(tail)-8] if truncate else out

EMBEDS={
    'youtube':('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42','https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&amp;start=42'),
    'youtu.be':('https://youtu.be/dQw4w9WgXcQ','https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0'),
    'shorts':('https://www.youtube.com/shorts/abcDEF12345','https://www.youtube-nocookie.com/embed/abcDEF12345?rel=0'),
    'playlist':('https://www.youtube.com/playlist?list=PLabc123XYZ','https://www.youtube-nocookie.com/embed/videoseries?list=PLabc123XYZ&amp;rel=0'),
    'nicovideo':('https://www.nicovideo.jp/watch/sm9','https://embed.nicovideo.jp/watch/sm9'),
    'spotify':('https://open.spotify.com/intl-ja/track/4uLU6hMCjMI75M1A2tKUQC?si=abc','https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC'),
    'spotify album':('https://open.spotify.com/album/1DFixLWuPkv3KT3TnV35m3','log-embed-tall'),
    'apple music':('https://music.apple.com/jp/album/some-album/1440857781?i=1440857782','https://embed.music.apple.com/jp/album/some-album/1440857781?i=1440857782'),
    'amazon music track':('https://music.amazon.co.jp/albums/B0CY5SN6Y4?marketplaceId=A1VC38T7YXB528&musicTerritory=JP&ref=dm_sh_unPe1nLQrVwXkl1sS4eRZMBIR&trackAsin=B0CY5ST1FH','https://music.amazon.co.jp/embed/B0CY5ST1FH/?marketplaceId=A1VC38T7YXB528&amp;musicTerritory=JP'),
    'amazon music album':('https://music.amazon.co.jp/albums/B0CY5SN6Y4/','https://music.amazon.co.jp/embed/B0CY5SN6Y4/?marketplaceId=A1VC38T7YXB528&amp;musicTerritory=JP'),
    'amazon music playlist':('https://music.amazon.co.jp/playlists/B01HB13YCQ?marketplaceId=A1VC38T7YXB528&musicTerritory=JP&ref=dm_sh_fRduyIbJRl37x8bMEafrTT3so','https://music.amazon.co.jp/embed/B01HB13YCQ/?marketplaceId=A1VC38T7YXB528&amp;musicTerritory=JP'),
    'soundcloud track':('https://soundcloud.com/user-657615998/myuu_suffer_sick-rabbit-potion?si=af0a447eaeac44c791d27b729f8aaf87&utm_source=clipboard&utm_medium=text&utm_campaign=social_sharing','https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fuser-657615998%2Fmyuu_suffer_sick-rabbit-potion&amp;auto_play=false&amp;visual=false&amp;show_comments=false'),
    'soundcloud playlist':('https://soundcloud.com/sei_peridot/sets/peritunematerial?si=19189b94f98c412a9a7bf9adf1ef207f&utm_source=clipboard&utm_medium=text&utm_campaign=social_sharing','https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fsei_peridot%2Fsets%2Fperitunematerial&amp;auto_play=false&amp;visual=false&amp;show_comments=false'),
    'youtube music':('https://music.youtube.com/watch?v=pRrmQUm6Zvg&si=sharing','https://www.youtube-nocookie.com/embed/pRrmQUm6Zvg?rel=0'),
    'bandcamp album':('https://hammock.bandcamp.com/album/everything-and-nothing?from=sharing','https://bandcamp.com/EmbeddedPlayer/album=4067613091/'),
    'bandcamp track':('https://hammock.bandcamp.com/track/example','https://bandcamp.com/EmbeddedPlayer/track=1234567/'),
    'tiktok':('https://www.tiktok.com/@scout2015/video/6718335390845095173?is_from_webapp=1','https://www.tiktok.com/player/v1/6718335390845095173?autoplay=0'),
    'bluesky':('https://bsky.app/profile/bsky.app/post/3mx5e63uvns2d','https://embed.bsky.app/embed/did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mx5e63uvns2d'),
    'bluesky did':('https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mx5e63uvns2d','https://embed.bsky.app/embed/did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mx5e63uvns2d'),
    'mastodon':('https://mastodon.social/@Mastodon/99730307203414093','data-embed="mastodon"'),
    'mixcloud':('https://www.mixcloud.com/mixcloud/what-you-need-to-know-about-music-copyright-aneesh-patel-music-lawyer-and-copyright-expert/','https://player-widget.mixcloud.com/?hide_cover=0'),
    'mixcloud playlist':('https://www.mixcloud.com/mixcloud/playlists/example/','class="log-embed embeddedmixcloud log-embed-tall"'),
    'audiomack playlist':('https://audiomack.com/audiomack/playlist/audiomacks-fine-tuned-series','https://audiomack.com/embed/audiomack/playlist/audiomacks-fine-tuned-series'),
    'audiomack song':('https://audiomack.com/inayah/song/crazy-too-ft-new-master','https://audiomack.com/embed/inayah/song/crazy-too-ft-new-master'),
    'audiomack album':('https://audiomack.com/artist/album/example','class="log-embed embeddedaudiomack log-embed-tall"'),
    'facebook':('https://www.facebook.com/Engineering/posts/were-sharing-an-early-view-into-how-were-building-private-processing-a-new-techn/1091181673044313/','https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2FEngineering%2Fposts%2F1091181673044313'),
    'facebook video':('https://www.facebook.com/Engineering/videos/123456789/','https://www.facebook.com/plugins/video.php?href='),
    'facebook permalink':('https://www.facebook.com/permalink.php?story_fbid=123456789&id=987654321','permalink.php%3Fstory_fbid%3D123456789%26id%3D987654321'),
    'threads':('https://www.threads.net/@threads/post/C--z-PKuPeh?hl=en','https://www.threads.com/@threads/post/C--z-PKuPeh/embed/?theme=light'),
    'vimeo':('https://vimeo.com/76979871','https://player.vimeo.com/video/76979871?autoplay=0&amp;dnt=1'),
    'vimeo unlisted':('https://vimeo.com/76979871/abcdef1234','dnt=1&amp;h=abcdef1234'),
    'twitch live':('https://www.twitch.tv/twitchdev','https://player.twitch.tv/?channel=twitchdev&amp;parent=127.0.0.1&amp;autoplay=false'),
    'twitch vod':('https://www.twitch.tv/videos/40464143','https://player.twitch.tv/?video=v40464143&amp;parent=127.0.0.1&amp;autoplay=false'),
    'twitch clip':('https://clips.twitch.tv/IncredulousAbstemiousFennelImGlitch','https://clips.twitch.tv/embed?clip=IncredulousAbstemiousFennelImGlitch&amp;parent=127.0.0.1&amp;autoplay=false'),
    'tweet':('https://x.com/nishishi/status/1234567890123456789','<blockquote class="twitter-tweet" data-dnt="true"><a href="https://twitter.com/nishishi/status/1234567890123456789"'),
    'tweet short id':('https://twitter.com/jack/status/20','href="https://twitter.com/jack/status/20"'),
    'instagram':('https://www.instagram.com/p/C0abcDEFghi/?igsh=xyz','data-instgrm-permalink="https://www.instagram.com/p/C0abcDEFghi/"'),
    'reel':('https://instagram.com/reel/C0abcDEFghi/','data-instgrm-permalink="https://www.instagram.com/reel/C0abcDEFghi/"'),
    'codepen':('https://codepen.io/someone/pen/abcXYZ','https://codepen.io/someone/embed/abcXYZ?default-tab=html%2Cresult'),
    'note':('https://note.com/someone/n/n1234567890ab','https://note.com/embed/notes/n1234567890ab'),
    'voicy':('https://voicy.jp/channel/1234/567890','https://voicy.jp/embed/channel/1234/567890'),
    'steam':('https://store.steampowered.com/app/1091500/Cyberpunk_2077/','https://store.steampowered.com/widget/1091500/'),
    'gigaviewer':('https://shonenjumpplus.com/episode/3269754496401369355?from=x','https://shonenjumpplus.com/episode/3269754496401369355/embed'),
}

OGP_PORT=args.port+10
OGP_HITS={}
PNG=None
class OGP(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*a): pass
    def send(self,status,body,ctype='text/html; charset=UTF-8',extra=None):
        self.send_response(status); self.send_header('Content-Type',ctype); self.send_header('Content-Length',str(len(body)))
        for k,v in (extra or {}).items(): self.send_header(k,v)
        self.send_header('Connection','close'); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        OGP_HITS[self.path]=OGP_HITS.get(self.path,0)+1
        page=lambda head: ('<!doctype html><html><head><meta charset="utf-8">'+head+'</head><body>本文</body></html>').encode()
        if self.path=='/page.html': self.send(200,page('<title>違う題</title><meta property="og:title" content="カードのタイトル"><meta property="og:description" content="カードの説明文です。"><meta property="og:site_name" content="テストサイト"><meta property="og:image" content="/og.png">'))
        elif self.path=='/preview.html': self.send(200,page('<meta property="og:title" content="プレビュー用"><meta property="og:image" content="og.png">'))
        elif self.path=='/redirect': self.send(302,b'',extra={'Location':'/page.html'})
        elif self.path=='/private-redirect': self.send(302,b'',extra={'Location':'http://10.0.0.1/'})
        elif self.path=='/sjis.html': self.send(200,'<html><head><meta charset="Shift_JIS"><meta property="og:title" content="シフトJISの題名"></head></html>'.encode('cp932'),'text/html')
        elif self.path=='/noog.html': self.send(200,page('<title>題名だけのページ</title><meta name="description" content="メタ説明">'))
        elif self.path=='/evil.html': self.send(200,page('<meta property="og:title" content="&lt;script&gt;alert(1)&lt;/script&gt;"><meta property="og:description" content="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">'))
        elif self.path=='/chunked.html':
            body=page('<meta property="og:title" content="分割転送">')
            self.send_response(200); self.send_header('Content-Type','text/html; charset=utf-8'); self.send_header('Transfer-Encoding','chunked'); self.send_header('Connection','close'); self.end_headers()
            for i in range(0,len(body),40):
                part=body[i:i+40]; self.wfile.write(f'{len(part):x}\r\n'.encode()+part+b'\r\n')
            self.wfile.write(b'0\r\n\r\n')
        elif self.path=='/og.png': self.send(200,PNG,'image/png')
        else: self.send(404,b'not found')
def php(site,code):
    prefix="define('NAGIMANGA',true); require '"+(site/'lib/bootstrap.php').as_posix()+"'; require '"+(site/'lib/log.php').as_posix()+"'; "
    cmd=h.php_cmd(0,False,site); cmd=cmd[:cmd.index('-S')]+['-r',prefix+code]
    env={k:v for k,v in os.environ.items() if k!='NAGIMANGA_CARD_ALLOW_LOCAL'}; env['NAGIMANGA_DATA']=str(site/'data')
    r=subprocess.run(cmd,env=env,capture_output=True,text=True,encoding='utf-8')
    return json.loads(r.stdout) if r.stdout.strip() else r.stderr

def run(site,other_site,c,csrf,other,ot):
    pub=h.Client(args.port); opub=h.Client(args.other_port)
    def post(data,files=None,client=c,token=csrf): return client.post('/admin/index.php',{'csrf':token,**data},files=files)
    def save(body,**extra):
        r=post(base.payload(body=body,**extra))
        if r.status!=200: raise RuntimeError(r.text)
        return json.loads(r.text)['id']
    def upload(name,data,ctype):
        return post({'do':'log_upload'},files={'image':(name,data,ctype)})

    # --- Animated GIF ---------------------------------------------------------------
    r=upload('anim.gif',gif(),'image/gif')
    m=json.loads(r.text)['media'] if r.status==200 else {}
    check('アニメGIFはGIFのまま保存',r.status==200 and m.get('f','').endswith('.gif') and m.get('w')==1 and m.get('h')==1)
    pid=save('GIFの記録\n[Image:'+m.get('id','')+']\n動く絵')
    page=pub.get('/?id='+pid).text
    full=pub.get('/?media='+m.get('id','')+'&v=1&format=image.gif'); thumb=pub.get('/?media='+m.get('id','')+'&thumb=1&v=1&format=image.gif')
    check('公開ページのGIFは本文でも動く（縮小版もGIF）','format=image.gif' in page and thumb.status==200 and thumb.getheader('Content-Type')=='image/gif' and thumb.body.count(b'\x2c\x00\x00\x00\x00')==2)
    check('GIFはimage/gif・nosniffで配信',full.status==200 and full.getheader('Content-Type')=='image/gif' and full.getheader('X-Content-Type-Options')=='nosniff')
    body=full.body
    check('GIFのコメント・独自データ・終端後の文字を捨てる',b'<?php' not in body and b'<script' not in body and b'XMP' not in body and body.endswith(b'\x3b'))
    check('GIFのコマ・表示時間・繰り返し指定は残す',body.count(b'\x2c\x00\x00\x00\x00')==2 and body.count(b'\x21\xf9\x04')==2 and b'NETSCAPE2.0' in body and body.startswith(b'GIF89a'))
    r=upload('still.gif',gif(frames=1),'image/gif')
    still=json.loads(r.text)['media'] if r.status==200 else {}
    check('1コマだけのGIFは従来どおり変換',r.status==200 and not still.get('f','').endswith('.gif'))
    r=upload('broken.gif',gif(truncate=True),'image/gif')
    broken=json.loads(r.text)['media'] if r.status==200 else {}
    check('壊れたGIFはGIFとして残さない',r.status!=200 or not broken.get('f','').endswith('.gif'))
    r=upload('poly.gif',gif(tail=b'\n<?php system($_GET[1]); ?>'*3),'image/gif')
    poly=json.loads(r.text)['media'] if r.status==200 else {}
    pb=pub.get('/?media='+poly.get('id','')+'&v=1') if poly else None
    save('偽装の確認\n[Image:'+poly.get('id','')+']')
    pb=pub.get('/?media='+poly.get('id','')+'&v=1')
    check('GIFの後ろに隠したPHPは保存されない',r.status==200 and b'<?php' not in pb.body and not list((site/'data').rglob('*.gif.tmp')))
    rep=post({'do':'log_upload','media':m['id'],'revision':1},files={'image':('new.png',h.png(30,20),'image/png')})
    check('アニメGIFを静止画へ差し替えると古いGIFを消す',rep.status==200 and not json.loads(rep.text)['media']['f'].endswith('.gif') and not list((site/'data').rglob('*'+m['f'])))

    # Resolved-provider fixtures keep this suite independent of live APIs. Live browser checks use real URLs.
    fixture_data=[
        [EMBEDS['bandcamp album'][0],{'id':'4067613091'}],
        [EMBEDS['bandcamp track'][0],{'id':'1234567'}],
        [EMBEDS['bluesky'][0],{'did':'did:plc:z72i7hdynmk6r22z27h6tvur'}],
        [EMBEDS['mastodon'][0],{'src':'https://mastodon.social/@Mastodon/99730307203414093/embed'}],
    ]
    fixture_json=json.dumps(fixture_data).replace('\\','\\\\').replace("'","\\'")
    php(site,"foreach(json_decode('"+fixture_json+"',true) as [$url,$data]) {$e=nl_embed_detect($url); nm_ensure_dir(dirname(nl_embed_cache_file($e))); nl_write_record(nl_embed_cache_file($e), ['type'=>$e['type'],'url'=>$e['url'],'fetched'=>time(),'ok'=>true,'data'=>$data]);} echo json_encode(true);")
    fixture_before={f.name:f.read_bytes() for f in (site/'data/log/embeds').glob('*.php')}
    # --- Embeds: a URL alone on its line, decided by domain --------------------------------
    lines='\n'.join(t for t,_ in EMBEDS.values())
    eid=save('埋め込みの記録\n'+lines+'\nhttps://cdn.example.com/movie.mp4\n  https://youtu.be/spacedXYZ12  \n文中のYouTube https://youtu.be/inlineABC12 は普通のリンク\n[Umekomi]https://youtu.be/oldSyntax12')
    res=pub.get('/?id='+eid); page=res.text
    body_html=page[page.index('<div class="log-body">'):page.index('</article>')]
    for name,(_,want) in EMBEDS.items(): check('自動埋め込み: '+name,want in body_html)
    check('保存・読者の表示で有効な埋め込みキャッシュを取り直さない',fixture_before=={f.name:f.read_bytes() for f in (site/'data/log/embeds').glob('*.php')})
    check('追加サービスは元の公開URLもリンクで表示','class="log-embed-source"' in body_html and 'Blueskyで開く' in body_html and 'Bandcampで開く' in body_html)
    invalid_extra=[
        'https://hammock.bandcamp.com.evil.example/album/example', 'https://bandcamp.com/album/example',
        'https://hammock.bandcamp.com/album/../example', 'https://user:pw@hammock.bandcamp.com/album/example',
        'https://www.tiktok.com.evil.example/@user/video/6718335390845095173','https://www.tiktok.com/@user/video/123',
        'https://bsky.app/profile/evil%22onload/post/3mx5e63uvns2d','https://bsky.app/profile/foo..app/post/3mx5e63uvns2d',
        'https://bsky.app/profile/did:plc:too-short/post/3mx5e63uvns2d',
        'https://www.threads.com/@threads/post/example/extra','https://www.threads.com.evil.example/@threads/post/example',
        'https://www.mixcloud.com/user/uploads/','https://audiomack.com/artist/song/../../x',
        'https://www.facebook.com/Engineering/posts/123456789/extra','https://www.facebook.com/watch/?v[]=123456789',
        'https://vimeo.com/76979871?h[]=abcdef1234','https://player.vimeo.com.evil.example/video/76979871',
        'https://www.twitch.tv/login','https://clips.twitch.tv/IncredulousAbstemiousFennelImGlitch/extra',
        'https://localhost/@user/12345678','https://127.0.0.1/@user/12345678',
    ]
    extra_json=json.dumps(invalid_extra).replace('\\','\\\\').replace("'","\\'")
    check('追加サービスの似たドメイン・認証情報・不正なパスや配列を拒否',php(site,"echo json_encode(array_map('nl_embed_detect',json_decode('"+extra_json+"',true)));")==[None]*len(invalid_extra))
    check('Twitchのparentには設置先ホストだけを使う',php(site,"$_SERVER['HTTP_HOST']='example.com:8443'; echo json_encode(nl_embed_html('https://www.twitch.tv/twitchdev'));").find('parent=example.com&amp;autoplay=false')>=0)
    check('取得前のBandcampは元の公開URLへ案内',php(site,"echo json_encode(nl_embed_html('https://hammock.bandcamp.com/album/uncached'));").find('Bandcampで開く')>=0)
    check('Mastodonの内部アドレスへ取得しに行かない',php(site,"echo json_encode(nl_embed_fetch(nl_embed_detect('https://127.0.0.1.nip.io/@user/12345678'),microtime(true)+2));") is None)
    mastodon_key=re.search(r'embed\.php\?k=([a-f0-9]{20})',body_html).group(1)
    mastodon_frame=pub.get('/embed.php?k='+mastodon_key)
    check('Mastodonの中継枠は確認済みの投稿だけで、親のCSPを広げない',mastodon_frame.status==200 and 'https://mastodon.social/@Mastodon/99730307203414093/embed' in mastodon_frame.text and "frame-src https://mastodon.social; frame-ancestors 'self'" in (mastodon_frame.getheader('Content-Security-Policy') or '') and 'sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"' in mastodon_frame.text and pub.get('/embed.php?k=../../config').status==404)
    check('Mastodonの外部HTMLは書き出さず、同じ投稿の公式枠だけ採用',php(site,"echo json_encode([nl_embed_mastodon_parse('<iframe src=\"https://evil.example/embed\"></iframe><script>alert(1)</script>','https://mastodon.social/@Mastodon/99730307203414093'),nl_embed_bandcamp_parse('<meta name=\"bc-page-properties\" content=\"{&quot;item_type&quot;:&quot;a&quot;,&quot;item_id&quot;:&quot;1/onload=alert(1)&quot;}\">','album')]);")==[None,None])
    check('Amazon Musicは曲と曲一覧の高さを分ける',body_html.count('class="log-embed embeddedamazonmusic"')==1 and body_html.count('class="log-embed embeddedamazonmusic log-embed-tall"')==2)
    check('SoundCloudは曲とプレイリストの高さを分け、自動再生しない',body_html.count('class="log-embed embeddedsoundcloud"')==1 and body_html.count('class="log-embed embeddedsoundcloud log-embed-tall"')==1 and body_html.count('auto_play=false')==2)
    check('SoundCloudの共有用si・utmパラメータを枠に引き継がない','utm_' not in body_html and 'af0a447eaeac44c791d27b729f8aaf87' not in body_html and '19189b94f98c412a9a7bf9adf1ef207f' not in body_html)
    check('Amazon Musicの共有用refや外部の地域指定を枠に引き継がない','dm_sh_' not in body_html)
    invalid_amazon=[
        'https://music.amazon.co.jp.evil.example/albums/B0CY5SN6Y4',
        'https://evil.example/music.amazon.co.jp/playlists/B01HB13YCQ',
        'https://music.amazon.co.jp/albums/B0CY5SN6Y4?trackAsin[]=B0CY5ST1FH',
        'https://music.amazon.co.jp/albums/B0CY5SN6Y4?trackAsin=',
        'https://music.amazon.co.jp/albums/B0CY5SN6Y4?trackAsin=B0CY5ST1FH%22onload%3Dalert(1)',
        'https://music.amazon.co.jp/albums/B0CY5SN6Y4?trackAsin=../../../x',
        'https://music.amazon.co.jp/playlists/B01HB13YCQ/extra',
        'https://music.amazon.co.jp/albums/TOOSHORT',
        'https://music.amazon.co.jp/artists/B008M6RBW4',
        'https://user:pw@music.amazon.co.jp/albums/B0CY5SN6Y4',
        'https://music.amazon.co.jp:8080/albums/B0CY5SN6Y4',
        'https://amzn.to/example',
    ]
    invalid_json=json.dumps(invalid_amazon).replace('\\','\\\\').replace("'","\\'")
    check('Amazon Musicの不正なID・似たドメイン・未対応URLは埋め込まない',php(site,"echo json_encode(array_map('nl_embed_detect',json_decode('"+invalid_json+"',true)));")==[None]*len(invalid_amazon))
    invalid_soundcloud=[
        'https://soundcloud.com.evil.example/user/track',
        'https://evil.example/soundcloud.com/user/track',
        'https://user:pw@soundcloud.com/user/track',
        'https://soundcloud.com:8080/user/track',
        'https://soundcloud.com/user',
        'https://soundcloud.com/user/sets',
        'https://soundcloud.com/user/tracks',
        'https://soundcloud.com/user/likes',
        'https://soundcloud.com/user/sets/list/extra',
        'https://soundcloud.com/user/track%22onload%3Dalert(1)',
        'https://soundcloud.com/user/../track',
        'https://soundcloud.com/user/track?secret_token=s-private',
        'https://soundcloud.com/user/track/s-private',
        'https://on.soundcloud.com/example',
    ]
    invalid_soundcloud_json=json.dumps(invalid_soundcloud).replace('\\','\\\\').replace("'","\\'")
    check('SoundCloudの似たドメイン・不正なパス・一覧・非公開・短縮リンクは埋め込まない',php(site,"echo json_encode(array_map('nl_embed_detect',json_decode('"+invalid_soundcloud_json+"',true)));")==[None]*len(invalid_soundcloud))
    check('SoundCloudのwww付きや末尾スラッシュも同じ曲を表示',php(site,"echo json_encode(nl_embed_html('https://www.soundcloud.com/user-657615998/myuu_suffer_sick-rabbit-potion/'));").find(EMBEDS['soundcloud track'][1])>=0)
    check('動画ファイルのURLは動画プレーヤー','<video class="embeddedvideo" controls preload="metadata" playsinline><source src="https://cdn.example.com/movie.mp4">' in body_html)
    check('前後に空白があっても1行だけのURLなら埋め込む','embed/spacedXYZ12' in body_html)
    check('文の途中のURLは埋め込まず普通のリンク','embed/inlineABC12' not in body_html and '<a href="https://youtu.be/inlineABC12" rel="noopener noreferrer">' in body_html)
    check('てがろぐの書き方は特別扱いしない','embed/oldSyntax12' not in body_html and '[Umekomi]' in body_html)
    check('本文に<script>を書き出さない','<script' not in body_html)
    csp=res.getheader('Content-Security-Policy') or ''
    check('Amazon Musicの枠は日本版の公式ドメインだけ許可','https://music.amazon.co.jp' in csp.split('frame-src ')[1].split(';')[0] and 'amazon.co.jp' not in csp.split('script-src ')[1].split(';')[0])
    check('SoundCloudは公式プレーヤーの枠だけを許可','https://w.soundcloud.com' in csp.split('frame-src ')[1].split(';')[0] and 'soundcloud.com' not in csp.split('script-src ')[1].split(';')[0])
    check('追加サービスは各公式の枠と同じサイトだけ許可し、任意のMastodonホストは親に加えない',all(origin in csp.split('frame-src ')[1].split(';')[0] for origin in ['https://bandcamp.com','https://www.tiktok.com','https://embed.bsky.app','https://www.threads.com','https://player-widget.mixcloud.com','https://audiomack.com','https://www.facebook.com','https://player.vimeo.com','https://player.twitch.tv','https://clips.twitch.tv',"'self'"]) and 'https://mastodon.social' not in csp and 'https:' not in csp.split('frame-src ')[1].split(';')[0].split())
    check('CSPは対応サービスのフレームと公式スクリプトだけ許可','frame-src https://www.youtube-nocookie.com' in csp and 'https://shonenjumpplus.com' in csp and "script-src 'self' https://platform.twitter.com https://www.instagram.com https://note.com" in csp and "media-src 'self' https:" in csp and "object-src 'none'" in csp and "img-src 'self' data: blob: https://i.ytimg.com;" in csp)
    check('埋め込み用JSをキャッシュバスター付きで読む',re.search(r'viewer/log-embed\.js\?v=[a-z0-9]+',page) and pub.get('/viewer/log-embed.js').status==200)
    evil=save('悪い入力\nhttps://www.youtube.com/watch?v=abc"onload="alert(1)\nhttps://evil.example/watch?v=dQw4w9WgXcQ\nhttps://youtube.com.evil.example/watch?v=dQw4w9WgXcQ\njavascript:alert(1)')
    ev=pub.get('/?id='+evil).text; eb=ev[ev.index('<div class="log-body">'):ev.index('</article>')]
    check('似たドメインや属性の差し込みでは埋め込まない','onload="' not in eb and 'youtube-nocookie.com/embed/dQw4' not in eb and '<a href="javascript' not in eb)
    first=save('https://youtu.be/dQw4w9WgXcQ\n2行目の本文')
    fp=pub.get('/?id='+first).text
    check('1行目が埋め込みのURLならタイトルは「YouTubeの記録」で本文に表示','<title>YouTubeの記録｜' in fp and 'youtube-nocookie.com/embed/dQw4w9WgXcQ' in fp)
    amazon_first=save(EMBEDS['amazon music track'][0]+'\n聴いていた曲')
    amazon_page=pub.get('/?id='+amazon_first).text
    check('Amazon MusicのURLだけでもサービス名をタイトルにして曲を本文に表示','<title>Amazon Musicの記録｜' in amazon_page and EMBEDS['amazon music track'][1] in amazon_page)
    soundcloud_first=save(EMBEDS['soundcloud track'][0]+'\n聴いていた曲')
    soundcloud_page=pub.get('/?id='+soundcloud_first).text
    check('SoundCloudのURLが1行目でも曲を本文に表示','<title>SoundCloudの記録｜' in soundcloud_page and EMBEDS['soundcloud track'][1] in soundcloud_page)
    desc=pub.get('/?id='+eid).text.split('name="description" content="')[1].split('"')[0]
    check('説明文は文章だけで、埋め込みのURLもサービス名も入れない','（YouTube）' not in desc and 'http' not in desc)
    post({'do':'log_settings','title':'埋め込みLOG','name':'記録する人','description':'説明','theme':'dark-navy'})
    check('ダーク配色ではXの埋め込みもダーク','data-theme="dark"' in pub.get('/?id='+eid).text)
    preview=json.loads(post({**base.payload(body='プレビュー\nhttps://youtu.be/dQw4w9WgXcQ'),'do':'log_preview'}).text)['html']
    check('プレビューにも埋め込みを表示','youtube-nocookie.com/embed/dQw4w9WgXcQ' in preview)
    amazon_preview=json.loads(post({**base.payload(body='Amazon Musicのプレビュー\n'+EMBEDS['amazon music track'][0]+'\n'+EMBEDS['amazon music playlist'][0]),'do':'log_preview'}).text)['html']
    check('Amazon Musicの曲とプレイリストはプレビューにも表示',all(EMBEDS[name][1] in amazon_preview for name in ('amazon music track','amazon music playlist')))
    soundcloud_preview=json.loads(post({**base.payload(body='SoundCloudのプレビュー\n'+EMBEDS['soundcloud track'][0]+'\n'+EMBEDS['soundcloud playlist'][0]),'do':'log_preview'}).text)['html']
    check('SoundCloudの曲とプレイリストはプレビューにも表示',all(EMBEDS[name][1] in soundcloud_preview for name in ('soundcloud track','soundcloud playlist')))
    extra_preview=json.loads(post({**base.payload(body='追加サービスのプレビュー\n'+'\n'.join(EMBEDS[name][0] for name in ['bandcamp album','bluesky','mastodon','tiktok','threads','mixcloud','audiomack playlist','facebook','vimeo','twitch live'])),'do':'log_preview'}).text)['html']
    check('追加10サービスのプレビューにも埋め込みを表示',all(EMBEDS[name][1].replace('?theme=light','?theme=dark') in extra_preview for name in ['bandcamp album','bluesky','mastodon','tiktok','threads','mixcloud','audiomack playlist','facebook','vimeo','twitch live']) and '../embed.php?k=' in extra_preview)
    inline_soundcloud=save('文中のリンク\nこの曲 '+EMBEDS['soundcloud track'][0]+' を聴いた')
    check('文中のSoundCloud共有URLは通常リンクのまま','data-embed="soundcloud"' not in pub.get('/?id='+inline_soundcloud).text)
    inline_amazon=save('文中のリンク\nこの曲 '+EMBEDS['amazon music track'][0]+' を聴いた')
    check('文中のAmazon Music共有URLは通常リンクのまま','data-embed="amazonmusic"' not in pub.get('/?id='+inline_amazon).text)
    adm=c.get('/admin/index.php?p=log')
    check('管理画面のCSPでも埋め込みのフレームだけ許可','frame-src https://www.youtube-nocookie.com' in (adm.getheader('Content-Security-Policy') or '') and "script-src 'self';" in (adm.getheader('Content-Security-Policy') or ''))
    check('管理画面のCSPでもAmazon Musicを表示できる','https://music.amazon.co.jp' in (adm.getheader('Content-Security-Policy') or '').split('frame-src ')[1].split(';')[0])
    check('管理画面のCSPでもSoundCloudを表示できる','https://w.soundcloud.com' in (adm.getheader('Content-Security-Policy') or '').split('frame-src ')[1].split(';')[0])
    check('管理画面は自己配信の表示調整だけを使い、外部スクリプトを読み込まない','data-embed-scripts="off"' in adm.text and '../viewer/log-embed.js?v=' in adm.text)
    compose=c.get('/').text
    check('投稿欄は埋め込みボタンなしで使い方を案内','data-embed title=' not in compose and 'URLだけを1行に貼ると' in compose)

    # --- Blog cards from OGP ------------------------------------------------------------
    O=f'http://127.0.0.1:{OGP_PORT}'
    hits=lambda: sum(OGP_HITS.values())
    cid=save(f'カードの記録\n{O}/page.html\n文中の {O}/inline.html はリンク\n{O}/redirect\n{O}/sjis.html\n{O}/noog.html\n{O}/missing\n{O}/chunked.html\n{O}/evil.html\n{O}/private-redirect')
    cp=pub.get('/?id='+cid).text; cb=cp[cp.index('<div class="log-body">'):cp.index('</article>')]
    check('OGPのあるURLはブログカード',f'<a class="log-card" href="{O}/page.html" rel="noopener noreferrer">' in cb and '<strong>カードのタイトル</strong>' in cb and '<small>カードの説明文です。</small>' in cb and '<span class="log-card-site">テストサイト</span>' in cb)
    m=re.search(r'<img class="log-card-image" src="\./\?card=([a-f0-9]{20})&amp;v=\d+"',cb)
    img=pub.get('/?card='+m.group(1)) if m else None
    check('カードの画像はこのサーバーに取り込んで配信',bool(m) and img.status==200 and img.getheader('Content-Type') in ('image/webp','image/jpeg') and b'<?php' not in img.body and img.getheader('X-Content-Type-Options')=='nosniff')
    check('文の途中のURLはカードにしない',f'<a href="{O}/inline.html" rel="noopener noreferrer">' in cb and OGP_HITS.get('/inline.html',0)==0)
    check('転送先のOGPでもカードにする',f'href="{O}/redirect"' in cb and cb.count('<strong>カードのタイトル</strong>')>=2)
    check('Shift_JISのページも文字化けしない','<strong>シフトJISの題名</strong>' in cb)
    check('OGPがなければ<title>と説明を使う','<strong>題名だけのページ</strong>' in cb and 'log-card log-card-noimage' in cb and '<small>メタ説明</small>' in cb)
    check('取得できないURLは普通のリンク',f'<a href="{O}/missing" rel="noopener noreferrer">' in cb)
    check('chunked転送のページも読める','<strong>分割転送</strong>' in cb)
    check('OGPの文字はエスケープして表示','&lt;script&gt;' in cb and '<script>alert' not in cb and '<img src=x' not in cb)
    check('内部アドレスへの転送はたどらない',f'<a href="{O}/private-redirect" rel="noopener noreferrer">' in cb)
    before=hits()
    for _ in range(3): pub.get('/?id='+cid); pub.get('/')
    check('訪問者の表示では外部サイトへ取りに行かない',hits()==before)
    page_hits=OGP_HITS.get('/page.html',0)
    save(f'もう一度\n{O}/page.html')
    check('1週間以内の再保存では取り直さない',page_hits>=1 and OGP_HITS.get('/page.html',0)==page_hits)
    pv=json.loads(post({**base.payload(body=f'プレビュー\n{O}/preview.html'),'do':'log_preview'}).text)['html']
    check('プレビューでもカードを作る','<strong>プレビュー用</strong>' in pv and 'src="../?card=' in pv)
    check('カード画像のURLは形を確かめる',pub.get('/?card=../../config').status==404 and pub.get('/?card=0123456789abcdef0123').status==404)
    blocked=php(site,"echo json_encode(array_map('nl_ip_public',['127.0.0.1','10.1.2.3','172.16.0.1','192.168.1.1','169.254.169.254','100.64.0.1','0.0.0.0','::1','fc00::1','fe80::1','::ffff:127.0.0.1','8.8.8.8','2606:4700::1111']));")
    check('取得先は公開アドレスだけ（内部・予約・ループバックを拒否）',blocked==[False]*11+[True,True])
    check('URLに認証情報や80/443以外のポートがあれば取得しない',php(site,"echo json_encode([nl_http_get('http://user:pw@example.com/',1000,'text/html',microtime(true)+2,true), nl_http_get('http://example.com:8080/',1000,'text/html',microtime(true)+2,true)]);")==[None,None])

    save('分類の見た目\n本文 #見た目タグ',new_categories='見た目分類')
    top=pub.get('/').text
    check('サイドバーのカテゴリはフォルダの一覧、タグは#の並びで見分けられる','<ul class="log-cat-list"><li><a href="./?category=' in top and 'class="log-cat-icon"' in top and '<div class="log-tag-cloud"><a href="./?tag=' in top and '>#見た目タグ</a>' in top)
    owner_top=c.get('/').text
    check('ログイン中の公開ページに「管理」ボタン（スパナ）を出し、訪問者には出さない','<a class="log-fab-admin" href="admin/index.php?p=log">' in owner_top and 'log-fab-admin' not in top)
    check('まとめて削除のボタンに文言を切り替える場所がある','<span data-bulk-label>まとめて削除</span>' in c.get('/admin/index.php?p=log').text)
    post_page=pub.get('/').text
    check('記事のカテゴリは「カテゴリ」＋フォルダのアイコン＋名前',re.search(r'<nav class="log-categories" aria-label="カテゴリ"><span class="log-categories-label">カテゴリ</span><a href="\./\?category=[a-f0-9]{12}"><svg class="log-cat-icon"[^>]*>.*?</svg><span>見た目分類</span></a>',post_page) is not None)
    multi=save('二つの分類\n本文',new_categories='一つ目、二つ目')
    mp=pub.get('/?id='+multi).text
    check('文字だけの記事は画像ビューアと漫画リーダーを読まず、ログイン中は今まで通り読む','NagiSwipe-main.js' not in mp and 'NagiManga.js' not in mp and 'NagiSwipe-main.js' in c.get('/?id='+multi).text)
    check('投稿欄はトップだけ開き、ほかのページは右下のボタンから開く','<div class="log-compose-slot" data-compose-slot>' in c.get('/').text and '<div class="log-compose-slot log-compose-collapsed" data-compose-slot>' in c.get('/?id='+multi).text and 'log-compose-collapsed' in c.get('/?q=%E6%9C%AC%E6%96%87').text)
    check('カテゴリが複数なら「,」で区切る',re.search(r'<span>一つ目</span></a><span class="log-cat-sep" aria-hidden="true">,</span><a href="\./\?category=[a-f0-9]{12}"><svg',mp) is not None)
    anon=h.Client(args.port)
    r=anon.get('/admin/index.php?p=log')
    check('ログインしていない「管理」はログイン画面へ（ログインリンクを表示中）',r.status==303 and r.getheader('Location')=='login.php')
    settings_page=c.get('/admin/index.php?p=settings&section=log').text
    key=re.search(r'index\.php\?k=([A-Za-z0-9]+)',c.get('/admin/index.php?p=settings&section=common').text).group(1)
    check('ログインリンクを隠す前の確認用に管理用URLを渡す',f'data-login-url="http://127.0.0.1:{args.port}/admin/index.php?k={key}"' in settings_page)
    check('訪問者の公開ページには管理用URLを出さない',key not in top)
    media_page=c.get('/admin/index.php?p=log_media').text
    check('画像一覧の各枠にドロップで差し替えられると案内','log-drop-hint' in media_page and media_page.count('data-media-card=')==media_page.count('log-drop-hint'))
    php(site,"$f=nl_root().'/settings.php'; nl_write_record($f, array_replace(nl_read_record($f) ?? [], ['footer_text'=>'Powered by NagiManga / NagiSwipe']));")
    check('以前の標準フッターを保存したサイトは新しい標準に変わる',"Powered by NagiManga / NagiSwipe" in (site/'data/log/settings.php').read_text(encoding='utf-8') and '<p class="log-footer-text">Powered by NagiLog＆NagiManga</p>' in pub.get('/').text)

    # --- Backup and restore keep the animation -------------------------------------------
    anim=json.loads(upload('again.gif',gif(frames=3),'image/gif').text)['media']
    save('復元するGIF\n[Image:'+anim['id']+']')
    zipblob=post({'do':'log_backup'}).body
    r=post({'do':'log_restore','overwrite':'1'},files={'backup':('log.zip',zipblob,'application/zip')},client=other,token=ot)
    files=list((other_site/'data').rglob('*.gif'))
    check('バックアップから復元してもGIFアニメのまま',r.status in (200,303) and len([f for f in files if not f.name.startswith('t_')])>=2 and any(f.read_bytes().count(b'\x2c\x00\x00\x00\x00')==3 for f in files))
    restored=[f for f in files if f.read_bytes().count(b'\x2c\x00\x00\x00\x00')==3]
    check('復元したGIFにも隠しデータは残らない',restored and all(b'<?php' not in f.read_bytes() for f in files))

def main():
    if args.port==args.other_port: raise RuntimeError('Two separate unused ports required')
    for port in (args.port,args.other_port):
        with socket.socket() as sock: sock.bind((h.HOST,port))
    temp=ROOT/'manga/dev/results'/('embed-test-'+secrets.token_hex(4)); site=temp/'site'; other_site=temp/'other'
    for path in (site,other_site): h.copy_server(path, ROOT/'manga/server')
    global PNG
    PNG=h.png(600,314,extra_after_iend=b"<?php echo 'x'; ?>")
    server=http.server.ThreadingHTTPServer((h.HOST,OGP_PORT),OGP); threading.Thread(target=server.serve_forever,daemon=True).start()
    os.environ['NAGIMANGA_CARD_ALLOW_LOCAL']='1'  # lets the test sites fetch the local OGP server
    processes=[]
    try:
        processes.extend((base.start(site,args.port),base.start(other_site,args.other_port)))
        c,csrf,_=base.install(args.port); other,ot,_=base.install(args.other_port)
        run(site,other_site,c,csrf,other,ot)
    finally:
        for process in processes: process.terminate(); process.wait(timeout=10)
        server.shutdown()
    passed=sum(ok for _,ok in checks); print(f'\n{passed} / {len(checks)} passed')
    (temp/'report.json').write_text(json.dumps({'passed':passed,'total':len(checks),'checks':checks},ensure_ascii=False,indent=2),encoding='utf-8')
    raise SystemExit(0 if passed==len(checks) else 1)
if __name__=='__main__': main()
