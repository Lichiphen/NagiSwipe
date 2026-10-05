#!/usr/bin/env python3
"""
Build the NagiSeries documentation site from the READMEs (standard library only).

    python site/build.py

  README.md (NagiSwipe section)  -> nagiswipe.html
  manga/README.md                -> nagimanga.html
  manga/README-LOG.md            -> nagilog.html
  manga/docs/overview.md         -> guide.html
  site/top.html + README.md      -> index.html (the rights and license sections)
  everything                     -> site/search.json, sitemap.xml

The READMEs are the only source of the text: edit them, then run this script and
commit the generated pages too (Cloudflare Pages / GitLab Pages serve them as they are).
Every URL of a local file carries ?v=<content hash>, so a changed file is never served
from an old cache. demo.html gets the same ?v= for NagiSwipe-main.js / .css.

The site is not part of the NagiManga release ZIP (manga/dev/build_release.py packs
manga/server only) and .gitattributes keeps it out of GitHub's source archives.
"""
import hashlib
import html
import json
import posixpath
import re
import struct
import sys
import unicodedata
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SITE = ROOT / "site"
SITE_URL = "https://nagiswipe.pages.dev/"
REPO_URL = "https://github.com/Lichiphen/NagiSwipe"
GITLAB_URL = "https://gitlab.com/lichiphen/nagiswipe"
BRANCH = "main"

PAGES = [
    dict(key="top", out="index.html", nav="トップ", sub="NagiSeries について",
         title="NagiSeries", src="README.md", sections=["権利・免責事項", "ライセンス"],
         desc="NagiSwipe・NagiManga・NagiLog のドキュメント。個人サイトのための、画像ポップアップ・漫画ビューアー・データベース不要の LOG。",
         ogp="img/ogp/nagiswipe-ogp.png"),
    dict(key="nagiswipe", out="nagiswipe.html", nav="NagiSwipe", sub="画像ポップアップ",
         src="README.md", unwrap="NagiSwipe",
         desc="JS と CSS を読み込むだけで動く、軽量な画像ポップアップギャラリー NagiSwipe の導入方法とオプション。",
         ogp="img/ogp/nagiswipe-ogp.png"),
    dict(key="nagimanga", out="nagimanga.html", nav="NagiManga", sub="漫画ビューアー",
         src="manga/README.md",
         desc="タグを 1 つ貼るだけで開く漫画ビューアー NagiManga の設置・管理画面・機能の説明。",
         ogp="img/ogp/nagimanga-ogp.png"),
    dict(key="nagilog", out="nagilog.html", nav="NagiLog", sub="個人用LOG",
         src="manga/README-LOG.md",
         desc="データベース不要で、文章・画像・漫画を自分のサイトへ投稿できる NagiLog の使い方と設定。",
         ogp="img/ogp/nagilog-ogp.png"),
    dict(key="guide", out="guide.html", nav="手引き", sub="開発者でない方へ",
         src="manga/docs/overview.md",
         desc="NagiManga を使うかどうか決めたい人、長く使い続けたい人のための手引き。",
         ogp="img/ogp/nagimanga-ogp.png"),
]
# Links between the READMEs become links between the pages.
PAGE_OF_SRC = {"README.md": "nagiswipe.html", "manga/README.md": "nagimanga.html",
               "manga/README-LOG.md": "nagilog.html", "manga/docs/overview.md": "guide.html"}

IMAGE_EXT = (".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".avif")
_hashes = {}


def esc(s):
    return html.escape(s, quote=True)


def vhash(rel):
    """?v= value: the first 10 hex digits of the file's SHA-256."""
    if rel not in _hashes:
        _hashes[rel] = hashlib.sha256((ROOT / rel).read_bytes()).hexdigest()[:10]
    return _hashes[rel]


def asset(rel):
    return f"{rel}?v={vhash(rel)}"


def image_size(rel):
    """(width, height) of a PNG or JPEG, or None."""
    try:
        data = (ROOT / rel).read_bytes()
    except OSError:
        return None
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return struct.unpack(">II", data[16:24])
    if data[:2] == b"\xff\xd8":
        i = 2
        while i + 9 < len(data):
            if data[i] != 0xFF:
                i += 1
                continue
            marker = data[i + 1]
            if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                i += 2
                continue
            length = struct.unpack(">H", data[i + 2:i + 4])[0]
            if 0xC0 <= marker <= 0xCF and marker not in (0xC4, 0xC8, 0xCC):
                h, w = struct.unpack(">HH", data[i + 5:i + 9])
                return w, h
            i += 2 + length
    return None


# --- headings (same IDs as GitHub / GitLab) -------------------------------------

def plain(title):
    t = re.sub(r'!\[([^\]]*)\]\([^)]*\)', r'\1', title)
    t = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', t)
    t = re.sub(r'`([^`]*)`', r'\1', t)
    t = re.sub(r'<[^>]+>', '', t)
    t = re.sub(r'(\*\*|__)(.+?)\1', r'\2', t)
    return html.unescape(t).strip()


def slug(text, seen):
    text = text.strip().lower()
    s = ''.join(c for c in text if c in ' -' or unicodedata.category(c)[0] in 'LMN'
                or unicodedata.category(c) == 'Pc').replace(' ', '-')
    n = seen.get(s, 0)
    seen[s] = n + 1
    return s if n == 0 else f'{s}-{n}'


# --- links and images -----------------------------------------------------------

class Ctx:
    def __init__(self, src, shift=0):
        self.src = src            # repo-relative path of the Markdown file
        self.shift = shift        # heading levels to lift (### -> ## when 1)
        self.seen = {}
        self.headings = []        # (level, id, inner html, plain text)


def resolve(href, ctx):
    """Repo-relative link -> page link, versioned asset or GitHub URL."""
    if href.startswith(SITE_URL):
        return href[len(SITE_URL):] or 'index.html'
    if re.match(r'[a-z][a-z0-9+.-]*:', href, re.I) or href.startswith('//') or href.startswith('#'):
        return href
    path, _, frag = href.partition('#')
    full = posixpath.normpath(posixpath.join(posixpath.dirname(ctx.src), urllib.parse.unquote(path)))
    if full in PAGE_OF_SRC:
        target = PAGE_OF_SRC[full]
        # README.md#nagiswipe is the whole nagiswipe.html page.
        if any(p['out'] == target and slug(p.get('unwrap', ''), {}) == frag for p in PAGES):
            frag = ''
        return target + (('#' + frag) if frag else '')
    frag = ('#' + frag) if frag else ''
    if full.lower().endswith(IMAGE_EXT) and (ROOT / full).is_file():
        return asset(full)
    kind = "tree" if path.endswith('/') or (ROOT / full).is_dir() else "blob"
    return f"{REPO_URL}/{kind}/{BRANCH}/{urllib.parse.quote(full)}{frag}"


def local_path(url):
    p = url.split('?', 1)[0]
    return p if not re.match(r'[a-z][a-z0-9+.-]*:', p, re.I) and (ROOT / p).is_file() else None


def img_tag(url, alt, extra=''):
    attrs = ''
    p = local_path(url)
    size = image_size(p) if p else None
    if size and 'width=' not in extra:
        attrs = f' width="{size[0]}" height="{size[1]}"'
    return f'<img src="{esc(url)}" alt="{esc(alt)}"{attrs}{extra} loading="lazy" decoding="async">'


def zoom_link(url, inner):
    """An image link NagiSwipe opens (data-ns-* gives the size before loading)."""
    p = local_path(url)
    size = image_size(p) if p else None
    data = f' data-ns-width="{size[0]}" data-ns-height="{size[1]}"' if size else ''
    return f'<a class="zoom" href="{esc(url)}"{data}>{inner}</a>'


def link_attrs(url):
    return ' class="ext"' if url.startswith(('http://', 'https://')) and not url.startswith(SITE_URL) else ''


# --- inline ---------------------------------------------------------------------

AUTOLINK = re.compile(r"(?<![\w/\"'=])https?://[A-Za-z0-9\-._~:/?#\[\]@!$&'+,;=%]+")


def inline(text, ctx):
    slots = []

    def put(h):
        slots.append(h)
        return f'\x00{len(slots) - 1}\x00'

    def finish(s):
        s = esc(s)
        s = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', s)
        s = re.sub(r'(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])', r'<em>\1</em>', s)
        return s

    def image(m):
        return put(img_tag(resolve(m.group(2), ctx), m.group(1)))

    def link(m):
        url = resolve(m.group(2), ctx)
        return put(f'<a href="{esc(url)}"{link_attrs(url)}>{finish(m.group(1))}</a>')

    def auto(m):
        url = m.group(0)
        tail = ''
        while url and url[-1] in '.,:;!?\')]':
            tail = url[-1] + tail
            url = url[:-1]
        return put(f'<a href="{esc(url)}" class="ext">{esc(url)}</a>') + tail

    text = re.sub(r'(`+)(.+?)\1', lambda m: put('<code>' + esc(m.group(2).strip()) + '</code>'), text)
    text = re.sub(r'\\([\\`*_{}\[\]()#+\-.!|<>~])', lambda m: put(esc(m.group(1))), text)
    text = re.sub(r'<!--.*?-->', '', text)
    text = re.sub(r'<(https?://[^>\s]+)>', lambda m: put(f'<a href="{esc(m.group(1))}" class="ext">{esc(m.group(1))}</a>'), text)
    text = re.sub(r'<img\b[^>]*>', lambda m: put(raw_img(m.group(0), ctx, wrap=False)), text, flags=re.I)
    text = re.sub(r'</?(?:br|kbd|sup|sub|strong|em|b|i|small|span)\b[^>]*>', lambda m: put(m.group(0)), text, flags=re.I)
    text = re.sub(r'!\[([^\]]*)\]\(<?([^)\s>]+)>?(?:\s+"[^"]*")?\)', image, text)
    text = re.sub(r'\[((?:[^\[\]]|\x00\d+\x00)+)\]\(<?([^)\s>]+)>?(?:\s+"[^"]*")?\)', link, text)
    text = AUTOLINK.sub(auto, text)
    text = finish(text)
    while '\x00' in text:
        text = re.sub(r'\x00(\d+)\x00', lambda m: slots[int(m.group(1))], text)
    return text


def raw_img(tag, ctx, wrap=True):
    src = re.search(r'\bsrc="([^"]*)"', tag)
    if not src:
        return tag
    url = resolve(html.unescape(src.group(1)), ctx)
    alt = re.search(r'\balt="([^"]*)"', tag)
    width = re.search(r'\bwidth="(\d+)"', tag)
    extra = f' width="{width.group(1)}"' if width else ''
    img = img_tag(url, html.unescape(alt.group(1)) if alt else '', extra)
    return zoom_link(url, img) if wrap else img


def raw_html(block, ctx):
    block = re.sub(r'<!--.*?-->', '', block, flags=re.S)
    block = re.sub(r'<img\b[^>]*>', lambda m: raw_img(m.group(0), ctx), block, flags=re.I)
    if '<img' in block:
        block = re.sub(r'<table>', '<table class="shots">', block)
    return block.strip()


# --- blocks ---------------------------------------------------------------------

HEADING = re.compile(r'^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$')
FENCE = re.compile(r'^( {0,3})(`{3,}|~{3,})\s*([\w+#.-]*)')
LIST = re.compile(r'^( {0,3})([-*+]|\d{1,9}[.)])(?:[ \t]+|$)')
HR = re.compile(r'^ {0,3}([-*_])(?:\s*\1){2,}\s*$')
HTML_START = re.compile(r'^ {0,3}<(?:!--|/?(?:table|div|p|details|summary|section|figure|picture|center|img|a|hr|h[1-6]|ul|ol|dl|pre)\b)', re.I)
TABLE_SEP = re.compile(r'^\s*\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?\s*$')


def indent_of(line):
    return len(line) - len(line.lstrip(' '))


def is_table(lines, i):
    return '|' in lines[i] and i + 1 < len(lines) and TABLE_SEP.match(lines[i + 1]) and '-' in lines[i + 1]


def starts_block(lines, i):
    line = lines[i]
    return (not line.strip() or HEADING.match(line) or FENCE.match(line) or HR.match(line)
            or LIST.match(line) or HTML_START.match(line) or line.lstrip().startswith('>')
            or is_table(lines, i))


def is_cjk(ch):
    return ord(ch) >= 0x2E80


def join_lines(lines):
    out = ''
    for line in lines:
        hard = line.endswith('  ') or line.endswith('\\')
        line = line.strip().rstrip('\\')
        if out and not out.endswith('<br>\n'):
            out += line if is_cjk(out[-1]) or (line and is_cjk(line[0])) else ' ' + line
        else:
            out += line
        if hard:
            out += '<br>\n'
    return out


def split_row(line):
    line = line.strip()
    if line.startswith('|'):
        line = line[1:]
    if line.endswith('|') and not line.endswith('\\|'):
        line = line[:-1]
    return [c.strip().replace('\\|', '|') for c in re.split(r'(?<!\\)\|', line)]


def render(lines, ctx, tight=False):
    out = []
    i, n = 0, len(lines)
    while i < n:
        line = lines[i]
        if not line.strip():
            i += 1
            continue

        m = FENCE.match(line)
        if m:
            ind, mark, lang = len(m.group(1)), m.group(2), m.group(3)
            i += 1
            body = []
            while i < n and not re.match(r'^ {0,3}' + re.escape(mark[0]) + '{' + str(len(mark)) + r',}\s*$', lines[i]):
                body.append(lines[i][min(ind, indent_of(lines[i])):])
                i += 1
            i += 1
            cls = f' class="language-{esc(lang)}"' if lang else ''
            out.append(f'<pre><code{cls}>{esc(chr(10).join(body))}</code></pre>')
            continue

        m = HEADING.match(line)
        if m:
            level = max(1, len(m.group(1)) - ctx.shift)
            text = plain(m.group(2))
            hid = slug(text, ctx.seen)
            inner = inline(m.group(2), ctx)
            ctx.headings.append((level, hid, inner, text))
            out.append(f'<h{level} id="{esc(hid)}">{inner}<a class="anchor" href="#{esc(hid)}" aria-label="この見出しへのリンク">#</a></h{level}>')
            i += 1
            continue

        if HR.match(line):
            out.append('<hr>')
            i += 1
            continue

        if HTML_START.match(line):
            block = []
            while i < n and lines[i].strip():
                block.append(lines[i])
                i += 1
            h = raw_html('\n'.join(block), ctx)
            if h:
                out.append(h)
            continue

        if is_table(lines, i):
            head = split_row(line)
            aligns = []
            for c in split_row(lines[i + 1]):
                aligns.append('center' if c.startswith(':') and c.endswith(':') else 'right' if c.endswith(':') else 'left' if c.startswith(':') else '')
            i += 2
            rows = []
            while i < n and lines[i].strip() and '|' in lines[i]:
                rows.append(split_row(lines[i]))
                i += 1

            def cell(tag, c, k):
                a = aligns[k] if k < len(aligns) else ''
                style = f' style="text-align:{a}"' if a else ''
                return f'<{tag}{style}>{inline(c, ctx)}</{tag}>'
            th = ''.join(cell('th', c, k) for k, c in enumerate(head))
            body = ''.join('<tr>' + ''.join(cell('td', r[k] if k < len(r) else '', k) for k in range(len(head))) + '</tr>' for r in rows)
            out.append(f'<div class="table-wrap"><table><thead><tr>{th}</tr></thead><tbody>{body}</tbody></table></div>')
            continue

        if line.lstrip().startswith('>'):
            quote = []
            while i < n and lines[i].strip() and (lines[i].lstrip().startswith('>') or quote):
                quote.append(re.sub(r'^\s*> ?', '', lines[i]))
                i += 1
            out.append('<blockquote>' + render(quote, ctx) + '</blockquote>')
            continue

        m = LIST.match(line)
        if m:
            h, i = render_list(lines, i, ctx)
            out.append(h)
            continue

        if indent_of(line) >= 4 and not tight:
            body = []
            while i < n and (indent_of(lines[i]) >= 4 or not lines[i].strip()):
                body.append(lines[i][4:])
                i += 1
            while body and not body[-1].strip():
                body.pop()
            out.append(f'<pre><code>{esc(chr(10).join(body))}</code></pre>')
            continue

        para = [line]
        i += 1
        while i < n and not starts_block(lines, i):
            para.append(lines[i])
            i += 1
        text = inline(join_lines(para), ctx)
        only_img = re.fullmatch(r'<img src="([^"]+)"[^>]*>', text)
        if only_img and not tight:
            url = html.unescape(only_img.group(1))
            out.append(f'<figure class="figure">{zoom_link(url, text)}</figure>')
        else:
            out.append(text if tight else f'<p>{text}</p>')
    return '\n'.join(out)


def render_list(lines, i, ctx):
    first = LIST.match(lines[i])
    base = len(first.group(1))
    ordered = first.group(2)[0].isdigit()
    start = int(re.match(r'\d+', first.group(2)).group(0)) if ordered else 1
    items, loose = [], False
    n = len(lines)
    while i < n:
        m = LIST.match(lines[i])
        if not m or len(m.group(1)) != base or m.group(2)[0].isdigit() != ordered:
            break
        content = len(m.group(0)) if lines[i][len(m.group(0)):].strip() else len(m.group(1)) + len(m.group(2)) + 1
        body = [lines[i][len(m.group(0)):]]
        i += 1
        while i < n:
            line = lines[i]
            if not line.strip():
                j = i
                while j < n and not lines[j].strip():
                    j += 1
                if j < n and indent_of(lines[j]) >= content:
                    body.extend([''] * (j - i))
                    if not LIST.match(lines[j][content:] if indent_of(lines[j]) >= content else ''):
                        loose = True
                    i = j
                    continue
                if j < n and LIST.match(lines[j]) and len(LIST.match(lines[j]).group(1)) == base:
                    loose = True
                break
            if indent_of(line) > base:
                body.append(line[min(indent_of(line), content):])
            elif LIST.match(line) or starts_block(lines, i):
                break
            else:
                body.append(line)     # lazy continuation
            i += 1
        items.append(body)
    lis = ''.join(f'<li>{render(b, ctx, tight=not loose)}</li>' for b in items)
    if ordered:
        attr = f' start="{start}"' if start != 1 else ''
        return f'<ol{attr}>{lis}</ol>', i
    return f'<ul>{lis}</ul>', i


# --- pages ----------------------------------------------------------------------

def md_lines(rel):
    text = (ROOT / rel).read_text(encoding='utf-8-sig').replace('\r\n', '\n')
    text = re.sub(r'<!-- TOC -->.*?<!-- /TOC -->\n?', '', text, flags=re.S)
    return text.split('\n')


def heading_index(lines):
    """[(line number, level, plain title)] outside code fences."""
    found, fence = [], None
    for k, line in enumerate(lines):
        m = FENCE.match(line)
        if m:
            fence = None if fence and m.group(2)[0] == fence else (fence or m.group(2)[0])
            continue
        if fence:
            continue
        h = HEADING.match(line)
        if h:
            found.append((k, len(h.group(1)), plain(h.group(2))))
    return found


def section(lines, title, heads):
    """Lines of the ## section whose title starts with title (heading included)."""
    for idx, (k, level, text) in enumerate(heads):
        if level == 2 and text.startswith(title):
            end = next((k2 for k2, l2, _ in heads[idx + 1:] if l2 <= 2), len(lines))
            return lines[k:end]
    sys.exit(f"section not found: {title}")


def build_doc(page):
    """(h1 html, lead html, body html, ctx) from the page's Markdown."""
    lines = md_lines(page['src'])
    heads = heading_index(lines)
    if page.get('unwrap'):
        part = section(lines, page['unwrap'], heads)
        ctx = Ctx(page['src'], shift=1)
        sub = heading_index(part)
        first = sub[1][0] if len(sub) > 1 else len(part)
        ctx.seen = {}
        lead = render(part[1:first], ctx)
        body = render(part[first:], ctx)
        return esc(page['nav']), lead, body, ctx
    ctx = Ctx(page['src'])
    h1 = next((k for k, level, _ in heads if level == 1), None)
    h2 = next((k for k, level, _ in heads if level == 2), len(lines))
    title = inline(HEADING.match(lines[h1]).group(2), ctx) if h1 is not None else esc(page['nav'])
    lead = render(lines[(h1 + 1 if h1 is not None else 0):h2], ctx)
    body = render(lines[h2:], ctx)
    return title, lead, body, ctx


def build_top(page):
    """index.html: site/top.html plus sections of README.md."""
    ctx = Ctx(page['src'])
    top = (SITE / 'top.html').read_text(encoding='utf-8')
    top = re.sub(r'\{\{asset:([^}]+)\}\}', lambda m: esc(asset(m.group(1))), top)
    top = re.sub(r'\{\{size:([^}]+)\}\}', lambda m: 'width="{}" height="{}"'.format(*image_size(m.group(1))), top)
    for m in re.finditer(r'<h([23]) id="([^"]+)">(.*?)<a class="anchor"', top):
        ctx.seen[m.group(2)] = 1
        ctx.headings.append((int(m.group(1)), m.group(2), m.group(3), plain(m.group(3))))
    lines = md_lines(page['src'])
    heads = heading_index(lines)
    rest = [render(section(lines, t, heads), ctx) for t in page['sections']]
    lead_m = re.search(r'<!-- lead -->(.*?)<!-- /lead -->', top, re.S)
    lead = lead_m.group(1).strip() if lead_m else ''
    top = top.replace(lead_m.group(0), '') if lead_m else top
    return 'NagiSeries', lead, top.strip() + '\n' + '\n'.join(rest), ctx


def toc_html(headings, cls):
    items, open_sub = [], False
    for level, hid, inner, _ in headings:
        if level not in (2, 3):
            continue
        label = re.sub(r'<a class="anchor".*?</a>', '', inner)
        label = re.sub(r'</?a\b[^>]*>', '', label)
        if level == 2:
            if open_sub:
                items.append('</ol></li>')
                open_sub = False
            elif items:
                items.append('</li>')
            items.append(f'<li><a href="#{esc(hid)}" data-id="{esc(hid)}">{label}</a>')
        else:
            if not items:
                items.append('<li>')
            if not open_sub:
                items.append('<ol>')
                open_sub = True
            items.append(f'<li><a href="#{esc(hid)}" data-id="{esc(hid)}">{label}</a></li>')
    if items:
        items.append('</ol></li>' if open_sub else '</li>')
    return f'<ol class="{cls}">' + ''.join(items) + '</ol>'


def nav_html(current, headings):
    rows = []
    for p in PAGES:
        cur = p['key'] == current
        aria = ' aria-current="page"' if cur else ''
        sub = toc_html(headings, 'nav-toc') if cur else ''
        rows.append(f'<li class="nav-item{" is-current" if cur else ""}"><a class="nav-link" href="{p["out"]}"{aria}>'
                    f'<span class="nav-name">{esc(p["nav"])}</span><span class="nav-sub">{esc(p["sub"])}</span></a>{sub}</li>')
    return '<ul class="nav-list">' + ''.join(rows) + '</ul>'


ICON_SEARCH = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="m16 16 4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
ICON_MENU = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
ICON_CLOSE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
BRAND = ('<a class="brand" href="index.html"><svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true">'
         '<rect width="32" height="32" rx="8" fill="var(--brand-bg)"/>'
         '<path d="M6 19c3.3-3.6 6.7-3.6 10 0s6.7 3.6 10 0" fill="none" stroke="var(--brand-wave)" stroke-width="2.6" stroke-linecap="round"/>'
         '<path d="M6 13c3.3-3.6 6.7-3.6 10 0s6.7 3.6 10 0" fill="none" stroke="var(--brand-wave)" stroke-width="2.6" stroke-linecap="round" opacity=".45"/>'
         '</svg><span class="brand-name">NagiSeries</span></a>')


def search_box(where):
    return (f'<div class="search" data-search-box><label class="search-field">{ICON_SEARCH}'
            f'<input type="search" placeholder="ドキュメントを検索" aria-label="ドキュメントを検索" autocomplete="off" enterkeyhint="search">'
            f'<kbd class="search-key" aria-hidden="true">/</kbd></label>'
            f'<div class="search-results" role="list" aria-label="検索結果" hidden></div></div>')


def layout(page, title_html, lead, body, ctx, prev_page, next_page):
    title_text = plain(re.sub(r'<[^>]+>', '', title_html))
    full_title = 'NagiSeries — 画像・漫画・LOG のツール集' if page['key'] == 'top' else f'{title_text} | NagiSeries'
    nav = nav_html(page['key'], ctx.headings)
    toc = toc_html(ctx.headings, 'toc-list')
    crumbs = '' if page['key'] == 'top' else f'<p class="crumbs"><a href="index.html">NagiSeries</a><span aria-hidden="true">/</span>{esc(page["nav"])}</p>'
    pager = ''
    if prev_page or next_page:
        a = f'<a class="pager-prev" href="{prev_page["out"]}"><span>前へ</span>{esc(prev_page["nav"])}</a>' if prev_page else '<span></span>'
        b = f'<a class="pager-next" href="{next_page["out"]}"><span>次へ</span>{esc(next_page["nav"])}</a>' if next_page else '<span></span>'
        pager = f'<nav class="pager" aria-label="ページ送り">{a}{b}</nav>'
    edit = f'{REPO_URL}/blob/{BRANCH}/{page["src"]}' if page['key'] != 'top' else f'{REPO_URL}/blob/{BRANCH}/site/top.html'
    url = SITE_URL + ('' if page['out'] == 'index.html' else page['out'])
    lead_html = f'<div class="lead">{lead}</div>' if lead.strip() else ''
    toc_block = (f'<nav class="toc-inline" aria-labelledby="toc-title-{page["key"]}"><p class="toc-title" id="toc-title-{page["key"]}">このページの目次</p>{toc}</nav>'
                 if len([h for h in ctx.headings if h[0] == 2]) >= 2 else '')
    return f'''<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(full_title)}</title>
<meta name="description" content="{esc(page['desc'])}">
<link rel="canonical" href="{url}">
<meta name="theme-color" content="#fbfcfc" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0e1316" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="{'website' if page['key'] == 'top' else 'article'}">
<meta property="og:site_name" content="NagiSeries">
<meta property="og:title" content="{esc(full_title)}">
<meta property="og:description" content="{esc(page['desc'])}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{SITE_URL}{asset(page['ogp'])}">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="{asset('site/favicon.svg')}" type="image/svg+xml">
<link rel="stylesheet" href="{asset('NagiSwipe-main.css')}">
<link rel="stylesheet" href="{asset('site/site.css')}">
<script src="{asset('NagiSwipe-main.js')}" defer></script>
<script src="{asset('site/site.js')}" data-index="{asset('site/search.json')}" defer></script>
</head>
<body class="page-{page['key']}">
<a class="skip" href="#main">本文へ移動</a>
<header class="topbar">
{BRAND}
<button class="topbar-btn" type="button" data-open-menu aria-haspopup="dialog" aria-controls="menu">{ICON_MENU}<span>目次</span></button>
</header>
<div class="layout">
<aside class="sidebar" aria-label="サイドバー">
{BRAND}
{search_box('side')}
<nav class="nav" aria-label="ドキュメント">{nav}</nav>
<p class="side-foot"><a class="ext" href="{REPO_URL}">GitHub</a><a class="ext" href="{GITLAB_URL}">GitLab</a><a href="demo.html">NagiSwipe デモ</a></p>
</aside>
<main id="main" class="main">
<article class="doc">
{crumbs}
<h1 class="doc-title">{title_html}</h1>
{lead_html}
{toc_block}
<div class="doc-body">
{body}
</div>
</article>
{pager}
<footer class="foot"><p>MIT License © 2026 Lichiphen</p><p><a class="ext" href="{edit}">このページの元の文書（GitHub）</a></p></footer>
</main>
</div>
<dialog class="menu" id="menu" aria-label="目次">
<div class="menu-head"><p class="menu-title">目次</p><button class="menu-close" type="button" data-close-menu aria-label="閉じる">{ICON_CLOSE}</button></div>
<div class="menu-body">
{search_box('menu')}
<nav class="nav" aria-label="ドキュメント">{nav}</nav>
<p class="side-foot"><a class="ext" href="{REPO_URL}">GitHub</a><a class="ext" href="{GITLAB_URL}">GitLab</a><a href="demo.html">NagiSwipe デモ</a></p>
</div>
</dialog>
</body>
</html>
'''


def search_entries(page, title_html, body, ctx):
    """One entry per ## / ### section: page, anchor, title, plain text."""
    def text_of(h):
        h = re.sub(r'<a class="anchor".*?</a>', '', h)
        h = re.sub(r'<(script|style)\b.*?</\1>', '', h, flags=re.S)
        h = re.sub(r'<img\b[^>]*alt="([^"]*)"[^>]*>', r' \1 ', h)
        return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', h))).strip()
    parts = re.split(r'(<h[23] id="[^"]+">.*?</h[23]>)', body, flags=re.S)
    entries = []
    page_title = plain(re.sub(r'<[^>]+>', '', title_html))
    cur = dict(u=page['out'], t=page_title, p=page['nav'], x='')
    for part in parts:
        m = re.match(r'<h[23] id="([^"]+)">', part)
        if m:
            entries.append(cur)
            cur = dict(u=f"{page['out']}#{html.unescape(m.group(1))}", t=text_of(part), p=page['nav'], x='')
        else:
            cur['x'] += ' ' + text_of(part)
    entries.append(cur)
    for e in entries:
        e['x'] = e['x'].strip()
    return [e for e in entries if e['x'] or e['t']]


def write(rel, text):
    path = ROOT / rel
    old = path.read_text(encoding='utf-8') if path.exists() else None
    if old != text:
        path.write_text(text, encoding='utf-8', newline='\n')
        print(f"{rel}: updated")


def main():
    index = []
    built = []
    for page in PAGES:
        title, lead, body, ctx = build_top(page) if page['key'] == 'top' else build_doc(page)
        built.append((page, title, lead, body, ctx))
        index += search_entries(page, title, (lead + '\n' if page['key'] != 'top' else '') + body, ctx)
    write('site/search.json', json.dumps(index, ensure_ascii=False, separators=(',', ':')))
    _hashes.pop('site/search.json', None)
    pages = {}
    for k, (page, title, lead, body, ctx) in enumerate(built):
        prev_page = PAGES[k - 1] if k > 0 else None
        next_page = PAGES[k + 1] if k + 1 < len(PAGES) else None
        pages[page['out']] = layout(page, title, lead, body, ctx, prev_page, next_page)
        write(page['out'], pages[page['out']])
    # Links to a heading that is not there (renamed in a README) are reported, not fixed.
    ids = {out: set(html.unescape(i) for i in re.findall(r'\bid="([^"]+)"', h)) for out, h in pages.items()}
    broken = 0
    for out, h in pages.items():
        for href in re.findall(r'href="([^"]*)"', h):
            href = html.unescape(href)
            target, _, frag = href.partition('#')
            target = target or out
            if target in ids and frag and frag not in ids[target]:
                print(f"{out}: link to a missing heading: {href}")
                broken += 1
            elif not frag and target.endswith('.html') and '/' not in target and not (ROOT / target).is_file():
                print(f"{out}: link to a missing page: {href}")
                broken += 1
    if broken:
        sys.exit(1)
    urls = ''.join(f'<url><loc>{SITE_URL}{"" if p["out"] == "index.html" else p["out"]}</loc></url>' for p in PAGES)
    write('sitemap.xml', f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{urls}</urlset>\n')
    demo = (ROOT / 'demo.html').read_text(encoding='utf-8')
    demo = re.sub(r'(NagiSwipe-main\.(?:css|js)|site/favicon\.svg)\?v=[0-9a-f]+', lambda m: asset(m.group(1)), demo)
    write('demo.html', demo)


if __name__ == '__main__':
    main()
