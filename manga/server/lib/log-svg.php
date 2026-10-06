<?php
/*
 * SVG pictures for the LOG. MIT License (c) 2026 Lichiphen.
 *
 * An SVG is a document, not pixels: it can carry scripts, event handlers, links to other sites and
 * embedded HTML. Uploads are checked first, and anything that can run code or reach outside the file
 * stops the upload with the reason (nl_svg_check). A file that passes is then written out again from
 * an allowlist of drawing elements and attributes, so editor leftovers (Inkscape / Illustrator data,
 * comments, unknown namespaces) are dropped too. The public page serves it with a sandboxing CSP.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_SVG_MAX_BYTES = 2 * 1024 * 1024;
const NL_SVG_MAX_NODES = 50000;
const NL_SVG_MAX_SIDE = 16000;
const NL_SVG_NS = 'http://www.w3.org/2000/svg';
const NL_XLINK_NS = 'http://www.w3.org/1999/xlink';
const NL_XML_NS = 'http://www.w3.org/XML/1998/namespace';
/** Drawing elements kept as they are (SVG 1.1 / 2 names, including filters, gradients and SMIL animation). */
const NL_SVG_ELEMENTS = ['svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
    'text', 'tspan', 'textPath', 'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker', 'filter', 'style', 'image', 'switch', 'view',
    'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow',
    'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight',
    'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'animate', 'animateTransform', 'animateMotion', 'set', 'mpath'];
/** Elements that only hold editor data: dropped with everything inside. */
const NL_SVG_DROPPED = ['metadata', 'sodipodi:namedview', 'namedview'];
/** Elements that can run code or show another document: the upload is refused. */
const NL_SVG_FORBIDDEN = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener', 'html', 'body', 'audio', 'video', 'canvas'];

/**
 * Check and rewrite an SVG. Returns ['svg' => bytes, 'w' => int, 'h' => int, 'dropped' => [names]], or an error message
 * (Japanese, for the owner) naming what was found.
 */
function nl_svg_clean(string $bytes): array|string
{
    if ($bytes === '' || strlen($bytes) > NL_SVG_MAX_BYTES) return 'SVGは2MBまでにしてください';
    if (str_starts_with($bytes, "\x1f\x8b")) return '圧縮されたSVG（SVGZ）は使えません。通常のSVGで保存し直してください';
    // A DOCTYPE can declare entities (outside files, "billion laughs"); plain drawing never needs one.
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) return 'DOCTYPE・ENTITY宣言の入ったSVGは使えません（外部ファイルの読み込みに使われるため）';
    if (!class_exists('DOMDocument')) return 'SVGを確認するには、PHPのDOM拡張が必要です';
    $doc = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    try { $ok = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    $root = $ok ? $doc->documentElement : null;
    if (!$root || $root->localName !== 'svg' || $root->namespaceURI !== NL_SVG_NS) return 'SVGとして読めませんでした（ルートが<svg>で、xmlns="http://www.w3.org/2000/svg"のファイルを選んでください）';
    $found = nl_svg_check($doc);
    if ($found) return 'このSVGは安全のため使えません：' . implode('、', array_slice(array_unique($found), 0, 5));
    [$w, $h] = nl_svg_size($root);
    if ($w < 1 || $h < 1) return 'SVGの大きさが分かりません。width・height か viewBox を指定してください';
    $out = new DOMDocument('1.0', 'UTF-8');
    $dropped = [];
    $out->appendChild(nl_svg_copy($root, $out, $dropped));
    $svg = $out->saveXML($out->documentElement);
    if ($svg === false || strlen($svg) > NL_SVG_MAX_BYTES) return 'SVGを書き出せませんでした';
    return ['svg' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg . "\n", 'w' => $w, 'h' => $h, 'dropped' => array_values(array_unique($dropped))];
}

/** Everything that makes the file unsafe, as short Japanese reasons; empty when it is only drawing. */
function nl_svg_check(DOMDocument $doc): array
{
    $found = []; $nodes = 0;
    $walk = static function (DOMNode $node, int $depth) use (&$walk, &$found, &$nodes): void {
        if (++$nodes > NL_SVG_MAX_NODES || $depth > 200) { $found[] = '要素が多すぎるか、入れ子が深すぎます'; return; }
        if ($node instanceof DOMProcessingInstruction) { $found[] = '処理命令 <?' . $node->target . '?>（外部スタイルシートの読み込みなど）'; return; }
        if (!$node instanceof DOMElement) return;
        $name = strtolower($node->localName ?? '');
        $ns = (string)$node->namespaceURI;
        if (in_array($name, NL_SVG_FORBIDDEN, true) && ($ns === NL_SVG_NS || $ns === 'http://www.w3.org/1999/xhtml' || $ns === '')) {
            $found[] = match ($name) { 'script' => '<script>（プログラム）', 'foreignobject' => '<foreignObject>（HTMLの埋め込み）', default => '<' . $name . '>' };
            return;
        }
        if ($ns === 'http://www.w3.org/1999/xhtml') { $found[] = 'HTMLの要素 <' . $name . '>'; return; }
        foreach ($node->attributes ?? [] as $attr) {
            $attrName = strtolower($attr->localName);
            $value = (string)$attr->value;
            $plain = (string)preg_replace('/[\x00-\x20]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_starts_with($attrName, 'on')) { $found[] = $attrName . '属性（イベントで動くプログラム）'; continue; }
            if (preg_match('/(?:java|vb)script:|data:text\/html|data:application/i', $plain)) { $found[] = $attrName . '属性の中のスクリプトURL'; continue; }
            // A link (<a>) is unwrapped when the file is written out; only the targets of drawing are limited to the file.
            if ($attrName === 'href' && $name !== 'a' && !nl_svg_href_ok($value, $name)) { $found[] = '<' . $node->localName . '> の外部への参照（' . mb_strimwidth($value, 0, 40, '…', 'UTF-8') . '）'; continue; }
            if (in_array($attrName, ['attributename', 'attributetype'], true) && preg_match('/\A\s*(?:xlink:)?href\s*\z|\A\s*on/i', $value)) { $found[] = 'リンク先やイベントを書き換えるアニメーション'; continue; }
            if ($attrName === 'style' && ($why = nl_svg_css_problem($value)) !== '') { $found[] = 'style属性の' . $why; continue; }
            if ($attrName !== 'style' && stripos($value, 'url(') !== false && ($why = nl_svg_css_problem($value)) !== '') $found[] = $attrName . '属性の' . $why;
        }
        if ($name === 'style' && ($why = nl_svg_css_problem($node->textContent)) !== '') $found[] = '<style>の' . $why;
        foreach ($node->childNodes as $child) $walk($child, $depth + 1);
    };
    foreach ($doc->childNodes as $child) $walk($child, 0);
    return $found;
}

/** Only references inside the file (#id); <image> may also hold a picture written into the file (data:image/…;base64). */
function nl_svg_href_ok(string $value, string $element): bool
{
    $value = trim($value);
    if ($value === '' || preg_match('/\A#[A-Za-z_][\w.:-]*\z/', $value)) return true;
    return in_array($element, ['image', 'feimage'], true) && (bool)preg_match('~\Adata:image/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/=\s]+\z~', $value);
}

/** Why a piece of CSS is unsafe ('' when it is fine): imports, scripts, or url() pointing outside the file. */
function nl_svg_css_problem(string $css): string
{
    // Escapes can spell any word (u\72l, \40import); drawing CSS never needs them.
    if (str_contains($css, '\\')) return 'エスケープされた文字';
    $flat = strtolower((string)preg_replace('/\/\*.*?\*\//s', '?', $css));
    if (str_contains($flat, '@import')) return '@import（外部スタイルシート）';
    if (preg_match('/expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:/', $flat)) return 'スクリプト';
    if (preg_match('/image-set\s*\(|(?<![\w-])(?:src|image)\s*\(/', $flat)) return '外部への参照';
    if (preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/s', $flat, $m)) {
        foreach ($m[2] as $url) if (!preg_match('/\A#[\w.:-]+\z/', trim($url)) && !preg_match('~\Adata:image/(?:png|jpeg|gif|webp);base64,~', trim($url))) return '外部への参照 url(' . mb_strimwidth(trim($url), 0, 30, '…', 'UTF-8') . ')';
    }
    // An unclosed url( would still load what follows.
    if (preg_match('/url\s*\(/', (string)preg_replace('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/s', '', $flat))) return '閉じていない url(';
    return '';
}

/** Width and height in CSS pixels: width/height (px, or physical units) first, the viewBox otherwise. */
function nl_svg_size(DOMElement $root): array
{
    $length = static function (string $v): float {
        if (!preg_match('/\A\s*([0-9]*\.?[0-9]+(?:e[+-]?[0-9]+)?)\s*(px|pt|pc|mm|cm|in)?\s*\z/i', $v, $m)) return 0.0;
        return (float)$m[1] * ['' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96][strtolower($m[2] ?? '')];
    };
    $w = $length($root->getAttribute('width')); $h = $length($root->getAttribute('height'));
    $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
    $bw = count($box) === 4 ? (float)$box[2] : 0.0; $bh = count($box) === 4 ? (float)$box[3] : 0.0;
    if ($w <= 0 && $h > 0 && $bw > 0 && $bh > 0) $w = $h * $bw / $bh;
    if ($h <= 0 && $w > 0 && $bw > 0 && $bh > 0) $h = $w * $bh / $bw;
    if ($w <= 0 || $h <= 0) { $w = $bw; $h = $bh; }
    if ($w <= 0 || $h <= 0) return [0, 0];
    // Keep the proportions inside the limits every other picture has.
    $k = min(1, NL_SVG_MAX_SIDE / max($w, $h));
    return [max(1, (int)round($w * $k)), max(1, (int)round($h * $k))];
}

/** Copy one checked element into $out with only drawing elements and plain attributes. */
function nl_svg_copy(DOMElement $node, DOMDocument $out, array &$dropped): DOMElement
{
    $el = $out->createElementNS(NL_SVG_NS, $node->localName);
    foreach ($node->attributes ?? [] as $attr) {
        $name = $attr->localName; $ns = (string)$attr->namespaceURI;
        if ($ns === NL_XLINK_NS && $name === 'href') { $el->setAttributeNS(NL_XLINK_NS, 'xlink:href', $attr->value); continue; }
        if ($ns === NL_XML_NS && in_array($name, ['space', 'lang'], true)) { $el->setAttributeNS(NL_XML_NS, 'xml:' . $name, $attr->value); continue; }
        if ($ns !== '' || !preg_match('/\A[A-Za-z][A-Za-z0-9-]*\z/', $name) || in_array(strtolower($name), ['src', 'action', 'formaction', 'srcdoc'], true)) { $dropped[] = ($attr->prefix ? $attr->prefix . ':' : '') . $name; continue; }
        $el->setAttribute($name, $attr->value);
    }
    $text = in_array($node->localName, ['text', 'tspan', 'textPath', 'title', 'desc', 'style'], true);
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            if ($text || trim($child->data) === '') $el->appendChild($out->createTextNode($child->data));
            continue;
        }
        if (!$child instanceof DOMElement) continue;
        if ($child->namespaceURI === NL_SVG_NS && $child->localName === 'a') {
            // Unwrap a link: keep what it draws.
            $wrapper = nl_svg_copy($child, $out, $dropped);
            while ($wrapper->firstChild) $el->appendChild($wrapper->firstChild);
            $dropped[] = 'a';
            continue;
        }
        $known = $child->namespaceURI === NL_SVG_NS && in_array($child->localName, NL_SVG_ELEMENTS, true);
        if (!$known) { $dropped[] = $child->nodeName; continue; }
        $el->appendChild(nl_svg_copy($child, $out, $dropped));
    }
    return $el;
}

/** True for an upload that should go through the SVG path (by content, or by its name when the server's file type check calls it text). */
function nl_svg_upload(string $tmp, string $name): bool
{
    $head = (string)@file_get_contents($tmp, false, null, 0, 4096);
    if (str_starts_with($head, "\x1f\x8b")) return (bool)preg_match('/\.svgz\z/i', $name);
    $mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
    if ($mime === 'image/svg+xml') return true;
    if (!in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html', ''], true)) return false;
    return (bool)preg_match('/\.svg\z/i', $name) || (bool)preg_match('/<svg[\s>]/i', $head);
}

/** Save a checked SVG as a LOG picture file; the same file is its thumbnail. Returns ['f','w','h'] or an error message. */
function nl_svg_store(string $bytes, string $dir): array|string
{
    $svg = nl_svg_clean($bytes);
    if (is_string($svg)) return $svg;
    nm_ensure_dir($dir);
    $file = sprintf('p0001_%s.svg', bin2hex(random_bytes(4)));
    foreach (["$dir/$file", "$dir/t_$file"] as $path) {
        $part = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($part, $svg['svg']) !== strlen($svg['svg']) || !@rename($part, $path)) {
            @unlink($part);
            nl_delete_media_files($dir, $file);
            return 'SVGを保存できませんでした';
        }
        @chmod($path, 0644);
    }
    return ['f' => $file, 'w' => $svg['w'], 'h' => $svg['h']];
}
