<?php
/* Personal LOG sidebar blocks and safe HTML. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_SIDEBAR_BUILTINS = ['links' => 'プロフィール・リンク集', 'login' => 'ログイン・管理ページ', 'calendar' => 'カレンダー', 'latest' => '最新ポスト', 'categories' => 'カテゴリ', 'hashtags' => 'ハッシュタグ', 'updated' => '最終更新日', 'all' => 'すべての投稿'];
const NL_SIDEBAR_CLASSES = ['log-link-grid', 'log-banner-link', 'log-banner'];

function nl_sidebar_defaults(): array
{
    $items = [];
    foreach (NL_SIDEBAR_BUILTINS as $id => $name) $items[] = $id === 'links' ? nl_links_default_item() : ['id' => $id, 'kind' => $id, 'enabled' => true];
    return ['revision' => 0, 'updated' => 0, 'items' => $items];
}
function nl_sidebar_settings(): array
{
    $s = nl_read_record(nl_root() . '/sidebar.php') ?? nl_sidebar_defaults();
    $s['items'] = nl_sidebar_with_links($s['items']);
    return $s;
}
/** Allow ordinary links and image paths, never active URL schemes. */
function nl_sidebar_url(string $url, bool $image = false): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || str_starts_with($url, '//')) return '';
    if (preg_match('~\Ahttps?://~i', $url)) {
        if ($image && !str_starts_with(strtolower($url), 'https://')) return '';
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || !filter_var($url, FILTER_VALIDATE_URL)) return '';
        return $url;
    }
    if (str_contains($url, ':') || !preg_match('~\A[./?#A-Za-z0-9_-]~', $url)) return '';
    return $url;
}
/** Rebuild from an allowlist instead of returning parser-controlled HTML. */
function nl_sidebar_html(string $html): string
{
    if ($html === '') return '';
    if (!class_exists('DOMDocument')) throw new UnexpectedValueException('HTML枠を使うには、PHPのDOM拡張が必要です');
    $doc = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    try { $ok = $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    if (!$ok) throw new UnexpectedValueException('HTMLを確認してください');
    $nodes = 0;
    $render = static function (DOMNode $node, int $depth) use (&$render, &$nodes): string {
        if (++$nodes > 5000 || $depth > 40) throw new UnexpectedValueException('HTMLの入れ子や要素を少なくしてください');
        if ($node instanceof DOMText) return h($node->nodeValue ?? '');
        if (!$node instanceof DOMElement) return '';
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template', 'noscript', 'svg', 'math', 'form', 'iframe', 'object', 'embed', 'base', 'meta', 'link', 'input', 'button', 'select', 'textarea', 'audio', 'video', 'source', 'applet'], true)) return '';
        $inner = '';
        foreach ($node->childNodes as $child) $inner .= $render($child, $depth + 1);
        if (!in_array($tag, ['div', 'span', 'p', 'strong', 'b', 'em', 'i', 'br', 'a', 'img', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'small', 'hr'], true)) return $inner;
        $attrs = '';
        $classes = array_intersect(preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], NL_SIDEBAR_CLASSES);
        if ($classes) $attrs .= ' class="' . h(implode(' ', array_unique($classes))) . '"';
        if ($node->hasAttribute('title')) $attrs .= ' title="' . h(mb_substr($node->getAttribute('title'), 0, 200)) . '"';
        if ($tag === 'a') {
            $href = nl_sidebar_url($node->getAttribute('href'));
            if ($href !== '') $attrs .= ' href="' . h($href) . '"';
            if ($node->getAttribute('target') === '_blank') $attrs .= ' target="_blank" rel="noopener noreferrer"';
        }
        if ($tag === 'img') {
            $src = nl_sidebar_url($node->getAttribute('src'), true);
            if ($src === '') return '';
            $attrs .= ' src="' . h($src) . '" alt="' . h(mb_substr($node->getAttribute('alt'), 0, 300)) . '" loading="lazy" referrerpolicy="no-referrer"';
            foreach (['width', 'height'] as $key) if (preg_match('/\A[0-9]{1,4}\z/', $node->getAttribute($key)) && (int)$node->getAttribute($key) > 0 && (int)$node->getAttribute($key) <= 4096) $attrs .= ' ' . $key . '="' . (int)$node->getAttribute($key) . '"';
        }
        return '<' . $tag . $attrs . '>' . (in_array($tag, ['img', 'br', 'hr'], true) ? '' : $inner . '</' . $tag . '>');
    };
    $out = '';
    foreach ($doc->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $child) $out .= $render($child, 0);
    return $out;
}
function nl_sidebar_validate(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items)) throw new UnexpectedValueException('サイドバーの項目を確認してください');
    $items = nl_sidebar_with_links($items);
    $out = []; $ids = []; $builtins = [];
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['kind'] ?? null) || !is_bool($item['enabled'] ?? null) || isset($ids[$item['id']])) throw new UnexpectedValueException('サイドバーの項目が重複しているか、形式が違います');
        $id = $item['id']; $kind = $item['kind']; $ids[$id] = true;
        if (isset(NL_SIDEBAR_BUILTINS[$id]) && $kind === $id) {
            $row = ['id' => $id, 'kind' => $kind, 'enabled' => $item['enabled']];
            if ($kind === 'links') {
                if (!is_bool($item['icon_frame'])) throw new UnexpectedValueException('アイコンのフチの設定を確認してください');
                $row += ['icon_frame' => $item['icon_frame'], 'message' => nl_links_message($item['message']), 'links' => nl_links_validate($item['links'] ?? null)];
            }
            $out[] = $row; $builtins[$id] = true; continue;
        }
        if ($kind !== 'html' || !preg_match('/\Ahtml-[a-f0-9]{16}\z/', $id) || !is_bool($item['framed'] ?? null)) throw new UnexpectedValueException('HTML枠の形式を確認してください');
        $title = $item['title'] ?? ''; $html = $item['html'] ?? '';
        if (!is_string($title) || !is_string($html) || !mb_check_encoding($title . $html, 'UTF-8') || mb_strlen($title) > 100 || strlen($html) > 50000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title . $html) || preg_match('/[\r\n\t]/', $title)) throw new UnexpectedValueException('見出しは100文字、HTMLは50KB以内で入力してください');
        $clean = nl_sidebar_html($html);
        if (strlen($clean) > 50000) throw new UnexpectedValueException('HTMLを整えると50KBを超えます。枠を分けてください');
        $out[] = ['id' => $id, 'kind' => 'html', 'enabled' => $item['enabled'], 'title' => trim($title), 'html' => $clean, 'framed' => $item['framed']];
    }
    if (count($builtins) !== count(NL_SIDEBAR_BUILTINS)) throw new UnexpectedValueException('標準の項目は削除せず、表示をオフにしてください');
    if (strlen(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 1024 * 1024) throw new UnexpectedValueException('サイドバー全体のHTMLを1MB以内にしてください');
    return $out;
}
function nl_sidebar_save(array $items, int $revision): int
{
    $items = nl_sidebar_validate($items);
    return nm_with_lock('personal-log', static function () use ($items, $revision) {
        $old = nl_sidebar_settings();
        if ($old['revision'] !== $revision) throw new UnexpectedValueException('別の画面でサイドバーが更新されています。開き直してください');
        nl_write_record(nl_root() . '/sidebar.php', ['revision' => $revision + 1, 'updated' => time(), 'items' => $items]);
        // The login row is the only switch for the login link; keep the stored flag in step.
        $login = array_values(array_filter($items, static fn($item) => $item['id'] === 'login'))[0]['enabled'];
        $s = nl_settings();
        if ($s['show_login'] !== $login) { $s['show_login'] = $login; $s['updated'] = time(); nl_write_record(nl_root() . '/settings.php', $s); }
        return $revision + 1;
    });
}
/** Image sources are already sanitized; use them for CSP and LOG references. */
function nl_sidebar_images(bool $visible = false): array
{
    $images = [];
    foreach (nl_sidebar_settings()['items'] as $item) {
        if ($item['kind'] !== 'html' || ($visible && !$item['enabled'])) continue;
        preg_match_all('/<img\b[^>]*\bsrc="([^"]*)"/i', nl_sidebar_html($item['html']), $matches);
        foreach ($matches[1] as $url) $images[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return array_values(array_unique($images));
}
function nl_sidebar_normalize_path(string $path): string
{
    $segments = [];
    foreach (explode('/', rawurldecode($path)) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') array_pop($segments); else $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
}
function nl_sidebar_media_ids(bool $visible = false): array
{
    $ids = []; $base = parse_url(nl_base_url());
    $urls = nl_sidebar_images($visible);
    foreach (nl_sidebar_settings()['items'] as $item) {
        if ($item['kind'] === 'links' && (!$visible || $item['enabled'])) {
            foreach ($item['links'] as $link) $urls[] = $link['url'];
        }
        if ($item['kind'] !== 'html' || ($visible && !$item['enabled'])) continue;
        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', nl_sidebar_html($item['html']), $matches);
        foreach ($matches[1] as $url) $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    foreach (array_unique($urls) as $url) {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['query'])) continue;
        if (isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? 'http'); $baseScheme = strtolower($base['scheme'] ?? 'http');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $basePort = $base['port'] ?? ($baseScheme === 'https' ? 443 : 80);
            if (strtolower($parts['host']) !== strtolower($base['host'] ?? '') || $scheme !== $baseScheme || $port !== $basePort) continue;
        }
        $root = rtrim($base['path'] ?? '', '/') . '/';
        $path = $parts['path'] ?? '';
        if (!str_starts_with($path, '/')) $path = $root . $path;
        // Browsers remove dot segments; hosts may also decode path escapes.
        $path = nl_sidebar_normalize_path($path);
        $entry = rtrim(nl_sidebar_normalize_path($root), '/');
        if (!in_array($path, [$entry ?: '/', $entry . '/log.php', $entry . '/index.php'], true)) continue;
        parse_str($parts['query'], $query);
        if (is_string($query['media'] ?? null) && nl_valid_media($query['media'])) $ids[] = $query['media'];
    }
    return array_values(array_unique($ids));
}
function nl_sidebar_image_origins(): string
{
    $origins = [];
    foreach (nl_sidebar_images(true) as $url) {
        $p = parse_url($url);
        if (strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) continue;
        $origin = 'https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        if (preg_match('~\Ahttps://[a-z0-9.\[\]:-]+\z~', $origin)) $origins[$origin] = true;
    }
    return $origins ? ' ' . implode(' ', array_keys($origins)) : '';
}
function nl_sidebar_templates(): array
{
    return [
        'grid' => ['title' => 'リンク集', 'framed' => true, 'html' => '<div class="log-link-grid">' . "\n" . '  <a href="https://example.com/">リンク1</a>' . "\n" . '  <a href="https://example.org/">リンク2</a>' . "\n" . '  <a href="https://example.net/">リンク3</a>' . "\n" . '  <a href="./">LOG</a>' . "\n" . '</div>'],
        'banner' => ['title' => '', 'framed' => false, 'html' => '<a class="log-banner-link" href="https://example.com/" target="_blank">' . "\n" . '  <img class="log-banner" src="viewer/og.jpg" alt="バナーの説明">' . "\n" . '</a>'],
        'blank' => ['title' => '自由なHTML', 'framed' => true, 'html' => '<p>ここに案内やリンクを書けます。</p>'],
    ];
}
/** Small stroke icons for the editor controls; they follow the text color. */
function nl_sidebar_ui_icon(string $name): string
{
    $paths = ['grip' => '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
        'up' => '<path d="m6 15 6-6 6 6"/>', 'down' => '<path d="m6 9 6 6 6-6"/>', 'remove' => '<path d="M5 7h14M10 11v6M14 11v6M7 7l1 12h8l1-12M9 7V5h6v2"/>', 'add' => '<path d="M12 5v14M5 12h14"/>'];
    return '<svg class="log-ui-icon log-ui-icon-' . $name . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}
function nl_sidebar_edit_row(array $item, bool $loginShown = true): string
{
    $custom = $item['kind'] === 'html';
    $label = $custom ? ($item['title'] ?: 'HTML枠（見出しなし）') : NL_SIDEBAR_BUILTINS[$item['kind']];
    $enabled = $item['enabled'] && ($item['kind'] !== 'login' || $loginShown);
    $chip = match ($item['kind']) { 'html' => 'HTML枠', 'links' => count($item['links']) . '件のリンク', default => '標準' };
    $body = match ($item['kind']) {
        'login' => '<p class="note">公開ページのメニューに「ログイン」、ログイン中は「管理ページ」へのリンクを出します。隠しても、パスワードによる保護はそのままです。</p>',
        'links' => nl_links_editor($item),
        'html' => '<details class="log-sidebar-editor log-sidebar-html-editor"><summary>HTMLと表示を編集</summary><div class="log-sidebar-editor-body"><label>見出し（空欄なら表示しません）<input data-sidebar-title maxlength="100" value="' . h($item['title']) . '"></label><label class="check"><input type="checkbox" data-sidebar-framed' . ($item['framed'] ? ' checked' : '') . '>枠と背景を表示する</label><label>HTML<textarea data-sidebar-html rows="7" spellcheck="false">' . h($item['html']) . '</textarea></label><div class="log-sidebar-remove"><button class="btn danger" type="button" data-sidebar-remove>' . nl_sidebar_ui_icon('remove') . 'このHTML枠を外す</button><p class="note">保存するまで取り消せます。外しても、リンク先や画像そのものは削除しません。</p></div></div></details>',
        default => '',
    };
    return '<div class="log-sidebar-edit-row" data-sidebar-item data-sidebar-id="' . h($item['id']) . '" data-sidebar-kind="' . h($item['kind']) . '">'
        . '<div class="log-sidebar-row-head"><button class="btn log-icon-btn log-sort-handle" type="button" aria-label="' . h($label) . 'をドラッグして移動">' . nl_sidebar_ui_icon('grip') . '</button>'
        . '<label class="log-sidebar-toggle"><input class="log-switch" type="checkbox" role="switch" data-sidebar-enabled' . ($enabled ? ' checked' : '') . '><span class="log-sidebar-row-title" data-sidebar-label>' . h($label) . '</span></label>'
        . '<span class="log-sidebar-chip" data-sidebar-chip>' . h($chip) . '</span>'
        . '<span class="log-sidebar-steps"><button class="btn log-icon-btn" type="button" data-sidebar-step="-1" aria-label="上へ移動">' . nl_sidebar_ui_icon('up') . '</button><button class="btn log-icon-btn" type="button" data-sidebar-step="1" aria-label="下へ移動">' . nl_sidebar_ui_icon('down') . '</button></span></div>'
        . ($body !== '' ? '<div class="log-sidebar-row-body">' . $body . '</div>' : '')
        . '</div>';
}
function nl_sidebar_panel(): string
{
    $s = nl_sidebar_settings(); $rows = ''; $loginShown = nl_settings()['show_login'];
    foreach ($s['items'] as $item) $rows .= nl_sidebar_edit_row($item, $loginShown);
    $templates = nl_sidebar_templates(); $options = '';
    foreach (['grid' => 'グリッドリンク', 'banner' => 'バナーリンク（枠なし）', 'blank' => '自由なHTML'] as $key => $name) $options .= '<option value="' . $key . '">' . $name . '</option>';
    $examples = '';
    foreach ($templates as $key => $item) $examples .= '<template data-sidebar-template="' . $key . '">' . nl_sidebar_edit_row(['id' => '', 'kind' => 'html', 'enabled' => true] + $item) . '</template>';
    return '<section class="card log-sidebar-manager" id="log-sidebar-settings"><h2>サイドバー・メニュー</h2><p class="note">PCの右側と、スマホの「MENU」に同じ順番で表示します。左の持ち手をドラッグするか、上下ボタンで並べ替えます。スイッチをオフにすると、その項目を隠します。</p>'
        . '<form method="post" action="index.php" class="form" data-sidebar-manager data-revision="' . $s['revision'] . '">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_sidebar_settings"><div class="log-sidebar-list" data-sidebar-items>' . $rows . '</div>'
        . '<details class="log-sidebar-add"><summary>上級者向け：HTML枠を追加</summary><div class="log-sidebar-editor-body"><p class="note">テンプレートを選んで追加し、リンク先や文章、画像のURLを書き換えて使います。枠は繰り返し追加できます。グリッドリンクは2列、バナーは枠や背景を付けずに表示します。</p><div class="log-sidebar-template-picker"><label>テンプレート<select data-sidebar-template-choice>' . $options . '</select></label><button class="btn" type="button" data-sidebar-add>' . nl_sidebar_ui_icon('add') . 'HTML枠を追加</button></div><p class="note">リンク・画像・見出し・段落・リスト・太字を使えます。script、iframe、フォーム、イベント属性、style属性は取り除いて保存します。画像は同じサイトのURLかHTTPSのURLを使ってください。外部画像は、表示時にリンク先のサーバーから読み込みます。見出しは100文字、1枠のHTMLは50KB、全体は1MBまでです。</p></div></details>'
        . '<div class="log-sidebar-save"><p class="note" data-sidebar-status role="status" aria-live="polite">順番と内容は「サイドバーを保存」で反映します。</p><div class="log-sidebar-save-actions"><a class="btn" href="../" target="_blank" rel="noopener">公開ページで確認</a><button class="btn primary" type="submit">サイドバーを保存</button></div></div><noscript><p>並び替えとHTML枠の編集には、ブラウザのJavaScriptを有効にしてください。</p></noscript></form>' . $examples . '</section>';
}
