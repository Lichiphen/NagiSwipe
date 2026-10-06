<?php
/*
 * Fixed pages (privacy policy, terms, about…) written in Markdown. MIT License (c) 2026 Lichiphen.
 * Each page is one guarded PHP record in data/log/pages/; the public URL is ./?pg=<URL name>.
 * Markdown is turned into HTML here from escaped text only: raw HTML in the source is shown as text.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_PAGE_LAYOUTS = ['sidebar' => 'サイドバー付き（通常）', 'wide' => '幅広1カラム', 'narrow' => '幅狭1カラム'];
const NL_PAGE_MAX = 200;
const NL_PAGE_BODY_MAX = 200000;
const NL_PAGE_TITLE_MAX = 100;
const NL_PAGE_ID_PATTERN = '/\Apg[a-f0-9]{12}\z/';
/** The URL name: lowercase letters, digits and hyphens (privacy, terms, about-me). */
const NL_PAGE_SLUG_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\z/';

function nl_page_file(string $id): string
{
    if (!preg_match(NL_PAGE_ID_PATTERN, $id)) throw new InvalidArgumentException('bad page');
    return nl_root() . '/pages/' . $id . '.php';
}
function nl_load_page(string $id): ?array
{
    if (!preg_match(NL_PAGE_ID_PATTERN, $id)) return null;
    $p = nl_read_record(nl_page_file($id));
    return $p && ($p['id'] ?? '') === $id && is_string($p['slug'] ?? null) ? $p : null;
}
/** Every page (or only the published ones), newest change first. */
function nl_pages(bool $public = false): array
{
    $all = nl_memo('pages', static function (): array {
        $out = [];
        foreach (glob(nl_root() . '/pages/pg*.php') ?: [] as $file) if ($p = nl_load_page(basename($file, '.php'))) $out[] = $p;
        usort($out, static fn($a, $b) => [$b['updated'], $b['id']] <=> [$a['updated'], $a['id']]);
        return $out;
    });
    return $public ? array_values(array_filter($all, static fn($p) => $p['status'] === 'published')) : $all;
}
function nl_find_page(string $slug): ?array
{
    foreach (nl_pages() as $p) if ($p['slug'] === $slug) return $p;
    return null;
}
function nl_fixed_url(array $p): string { return './?pg=' . rawurlencode($p['slug']); }
/** LOG pictures a page shows (written as ./?media=<id>… in its Markdown). */
function nl_page_media_refs(string $body): array
{
    preg_match_all('/[?&]media=([a-f0-9]{16})/', $body, $m);
    return array_values(array_unique($m[1]));
}
function nl_pages_media_ids(bool $public = false): array
{
    $ids = [];
    foreach (nl_pages($public) as $p) foreach ($p['media'] ?? [] as $id) $ids[$id] = true;
    return array_keys($ids);
}
/** Checked page fields from the editor (nothing written). */
function nl_page_input(array $in): array
{
    $title = trim((string)($in['title'] ?? ''));
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    $body = str_replace(["\r\n", "\r"], "\n", (string)($in['body'] ?? ''));
    $layout = (string)($in['layout'] ?? '');
    $status = (string)($in['status'] ?? '');
    if ($title === '' || mb_strlen($title) > NL_PAGE_TITLE_MAX || preg_match('/[\x00-\x1F\x7F]/', $title)) throw new UnexpectedValueException('タイトルを' . NL_PAGE_TITLE_MAX . '文字以内の1行で入力してください');
    if ($slug === '') $slug = 'page-' . bin2hex(random_bytes(3));
    if (!preg_match(NL_PAGE_SLUG_PATTERN, $slug)) throw new UnexpectedValueException('URL名は半角の英小文字・数字・ハイフンで、40文字以内にしてください（例：privacy）');
    if (strlen($body) > NL_PAGE_BODY_MAX || !mb_check_encoding($body, 'UTF-8')) throw new UnexpectedValueException('本文は200KB以内にしてください');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) throw new UnexpectedValueException('本文に使えない文字があります');
    if (!isset(NL_PAGE_LAYOUTS[$layout])) throw new UnexpectedValueException('ページの幅を選んでください');
    if (!in_array($status, ['published', 'draft'], true)) throw new UnexpectedValueException('公開か下書きを選んでください');
    return ['title' => $title, 'slug' => $slug, 'body' => $body, 'layout' => $layout, 'status' => $status];
}
/** Save under the LOG lock; the revision stops two tabs overwriting each other. */
function nl_page_save(string $id, int $revision, array $fields): array
{
    return nm_with_lock('personal-log', static function () use ($id, $revision, $fields) {
        $old = $id !== '' ? nl_load_page($id) : null;
        if ($id !== '' && !$old) throw new UnexpectedValueException('固定ページが見つかりません。一覧を開き直してください');
        if ($old && $old['revision'] !== $revision) throw new UnexpectedValueException('別の画面で更新されています。本文をコピーしてから開き直してください');
        if (!$old && count(nl_pages()) >= NL_PAGE_MAX) throw new UnexpectedValueException('固定ページは' . NL_PAGE_MAX . '件までです');
        foreach (nl_pages() as $p) if ($p['slug'] === $fields['slug'] && $p['id'] !== $id) throw new UnexpectedValueException('URL名「' . $fields['slug'] . '」はほかの固定ページで使っています');
        $now = time();
        $page = $fields + ['id' => $old['id'] ?? 'pg' . bin2hex(random_bytes(6)), 'media' => nl_page_media_refs($fields['body']), 'created' => $old['created'] ?? $now, 'updated' => $now, 'revision' => ($old['revision'] ?? 0) + 1];
        nl_write_record(nl_page_file($page['id']), $page);
        nm_log('log_page_saved', $page['id']);
        return $page;
    });
}
function nl_page_delete(string $id, int $revision): void
{
    nm_with_lock('personal-log', static function () use ($id, $revision) {
        $p = nl_load_page($id);
        if (!$p) throw new UnexpectedValueException('固定ページが見つかりません');
        if ($p['revision'] !== $revision) throw new UnexpectedValueException('別の画面で更新されています。開き直してください');
        if (!@unlink(nl_page_file($id))) throw new RuntimeException('delete failed');
        if (function_exists('opcache_invalidate')) @opcache_invalidate(nl_page_file($id), true);
        nm_touch_content();
        nm_log('log_page_deleted', $id);
    });
}

/* ---- Markdown ------------------------------------------------------------------------------- */

/**
 * The supported subset: headings (# and ## are 見出し2, ### 見出し3, #### and smaller 見出し4), paragraphs with
 * line breaks kept, **bold**, *italic*, ~~strike~~, `code`, code blocks (```), > quotes, lists (-, 1.) with
 * nesting, tables, --- rules, [links](URL), ![pictures](URL) and bare URLs. HTML is shown as text.
 */
function nl_markdown(string $md, bool $admin = false): string
{
    $ids = [];
    return nl_md_blocks(explode("\n", str_replace(["\r\n", "\r"], "\n", $md)), $admin, 0, $ids);
}
const NL_MD_ITEM = '/\A( *)([-*+]|[0-9]{1,9}[.)])(?: +(.*))?\z/u';
function nl_md_blocks(array $lines, bool $admin, int $depth, array &$ids): string
{
    $out = ''; $para = []; $n = count($lines);
    $flush = static function () use (&$para, &$out, $admin): void {
        if ($para) $out .= '<p>' . implode('<br>', array_map(static fn($l) => nl_md_inline(trim($l), $admin), $para)) . '</p>';
        $para = [];
    };
    for ($i = 0; $i < $n; $i++) {
        $line = str_replace("\t", '    ', $lines[$i]);
        if (trim($line) === '') { $flush(); continue; }
        if (preg_match('/\A {0,3}(`{3,}|~{3,})/', $line, $fence)) {
            $flush(); $code = [];
            for ($i++; $i < $n && !preg_match('/\A {0,3}' . preg_quote($fence[1], '/') . '\s*\z/', $lines[$i]); $i++) $code[] = $lines[$i];
            $out .= '<pre class="log-md-code"><code>' . h(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (preg_match('/\A {0,3}(#{1,6})[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*\z/u', $line, $m)) {
            $flush();
            $level = min(4, max(2, strlen($m[1])));
            $out .= '<h' . $level . ' id="' . h(nl_md_anchor($m[2], $ids)) . '">' . nl_md_inline($m[2], $admin) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/\A {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*\z/', $line)) { $flush(); $out .= '<hr>'; continue; }
        if (preg_match('/\A {0,3}>/', $line)) {
            $flush(); $quote = [];
            for (; $i < $n && preg_match('/\A {0,3}> ?(.*)\z/u', $lines[$i], $q); $i++) $quote[] = $q[1];
            $i--;
            $out .= '<blockquote>' . ($depth < 8 ? nl_md_blocks($quote, $admin, $depth + 1, $ids) : h(implode("\n", $quote))) . '</blockquote>';
            continue;
        }
        if (str_contains($line, '|') && $i + 1 < $n && nl_md_table_rule($lines[$i + 1]) !== null) {
            $flush();
            [$html, $i] = nl_md_table($lines, $i, $admin);
            $out .= $html;
            continue;
        }
        if (preg_match(NL_MD_ITEM, $line, $item) && ($item[3] ?? '') !== '' || preg_match('/\A *[-*+]\z/', $line)) {
            $flush();
            [$html, $i] = nl_md_list($lines, $i, $admin, $depth, $ids);
            $out .= $html;
            continue;
        }
        $para[] = $line;
    }
    $flush();
    return $out;
}
/** Column alignments of a table's second row (|---|:--:|), or null when the row is not one. */
function nl_md_table_rule(string $line): ?array
{
    $cells = nl_md_cells($line);
    if (!$cells || !str_contains($line, '-')) return null;
    $align = [];
    foreach ($cells as $cell) {
        if (!preg_match('/\A(:?)-+(:?)\z/', $cell, $m)) return null;
        $align[] = $m[1] !== '' && $m[2] !== '' ? 'center' : ($m[2] !== '' ? 'right' : ($m[1] !== '' ? 'left' : ''));
    }
    return $align;
}
function nl_md_cells(string $line): array
{
    $line = trim($line);
    if (str_starts_with($line, '|')) $line = substr($line, 1);
    if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) $line = substr($line, 0, -1);
    return array_map(static fn($c) => trim(str_replace('\\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $line) ?: []);
}
function nl_md_table(array $lines, int $i, bool $admin): array
{
    $head = nl_md_cells($lines[$i]); $align = nl_md_table_rule($lines[$i + 1]) ?? [];
    $cols = count($head);
    $cell = static function (string $tag, string $text, int $c) use ($align, $admin): string {
        $a = $align[$c] ?? '';
        return '<' . $tag . ($a !== '' ? ' class="is-' . $a . '"' : '') . '>' . nl_md_inline($text, $admin) . '</' . $tag . '>';
    };
    $html = '<div class="log-md-table"><table><thead><tr>';
    foreach ($head as $c => $text) $html .= $cell('th', $text, $c);
    $html .= '</tr></thead><tbody>';
    for ($i += 2; $i < count($lines) && trim($lines[$i]) !== '' && str_contains($lines[$i], '|'); $i++) {
        $row = array_pad(array_slice(nl_md_cells($lines[$i]), 0, $cols), $cols, '');
        $html .= '<tr>';
        foreach ($row as $c => $text) $html .= $cell('td', $text, $c);
        $html .= '</tr>';
    }
    return [$html . '</tbody></table></div>', $i - 1];
}
/** One list (with the lists nested in its items); returns [html, index of its last line]. */
function nl_md_list(array $lines, int $i, bool $admin, int $depth, array &$ids): array
{
    $n = count($lines);
    preg_match(NL_MD_ITEM, str_replace("\t", '    ', $lines[$i]), $first);
    $indent = strlen($first[1]);
    $ordered = !in_array($first[2], ['-', '*', '+'], true);
    $items = [];
    $same = static function (array $m) use ($indent, $ordered): bool {
        return strlen($m[1]) === $indent && $ordered === !in_array($m[2], ['-', '*', '+'], true);
    };
    for (; $i < $n; $i++) {
        $line = str_replace("\t", '    ', $lines[$i]);
        if (trim($line) === '') {
            // A blank line ends the list unless the list (or the item's nested part) goes on after it.
            $j = $i + 1;
            while ($j < $n && trim($lines[$j]) === '') $j++;
            $next = $j < $n ? str_replace("\t", '    ', $lines[$j]) : '';
            if ($j < $n && $items && ((preg_match(NL_MD_ITEM, $next, $m) && $same($m)) || strlen($next) - strlen(ltrim($next, ' ')) > $indent)) { $items[count($items) - 1][] = ''; continue; }
            break;
        }
        if (preg_match(NL_MD_ITEM, $line, $m) && $same($m)) { $items[] = [$m[3] ?? '']; continue; }
        $lead = strlen($line) - strlen(ltrim($line, ' '));
        if ($items && $lead > $indent) { $items[count($items) - 1][] = substr($line, min($lead, $indent + 2)); continue; }
        break;
    }
    $html = '';
    foreach ($items as $item) {
        $text = array_shift($item);
        $rest = $item && $depth < 8 ? nl_md_blocks($item, $admin, $depth + 1, $ids) : '';
        $html .= '<li>' . nl_md_inline($text, $admin) . $rest . '</li>';
    }
    $start = $ordered ? (int)$first[2] : 1;
    return [($ordered ? '<ol' . ($start !== 1 ? ' start="' . $start . '"' : '') . '>' : '<ul>') . $html . ($ordered ? '</ol>' : '</ul>'), $i - 1];
}
/** A heading's id for #links: its words, like GitHub (見出し → #見出し), numbered when repeated. */
function nl_md_anchor(string $text, array &$ids): string
{
    $plain = (string)preg_replace(['/!?\[([^\]]*)\]\([^)]*\)/u', '/[*_~`]/'], ['$1', ''], $text);
    $id = trim((string)preg_replace('/[^\p{L}\p{N}_-]+/u', '-', mb_strtolower($plain, 'UTF-8')), '-');
    $id = mb_substr($id !== '' ? $id : 'section', 0, 60);
    $base = $id;
    for ($k = 1; isset($ids[$id]); $k++) $id = $base . '-' . $k;
    $ids[$id] = true;
    return $id;
}
/** Inline marks on one line. Links, pictures, code and URLs become placeholders so bold can wrap them. */
function nl_md_inline(string $text, bool $admin, bool $links = true): string
{
    $tokens = [];
    $keep = static function (string $html) use (&$tokens): string { $tokens[] = $html; return "\x01" . (count($tokens) - 1) . "\x02"; };
    $pattern = '/(`+)(.+?)\1|!\[([^\]\n]*)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"]*")?\s*\)|\[([^\]\n]+)\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"]*")?\s*\)|(https?:\/\/[^\s<>"\[\]()]+)/u';
    $out = ''; $at = 0;
    preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($all as $m) {
        [$whole, $offset] = $m[0];
        $out .= h(substr($text, $at, $offset - $at));
        $at = $offset + strlen($whole);
        if (($m[2][1] ?? -1) >= 0 && $m[1][0] !== '') { $out .= $keep('<code>' . h($m[2][0]) . '</code>'); continue; }
        if (($m[4][1] ?? -1) >= 0) { $out .= $keep(nl_md_image($m[3][0], $m[4][0], $admin)); continue; }
        if (($m[6][1] ?? -1) >= 0) {
            $href = $links ? nl_md_url($m[6][0]) : '';
            $label = nl_md_inline($m[5][0], $admin, false);
            $out .= $keep($href !== '' ? '<a href="' . h($href) . '"' . (preg_match('~\Ahttps?://~i', $href) ? ' rel="noopener noreferrer"' : '') . '>' . $label . '</a>' : $label);
            continue;
        }
        $url = rtrim($m[7][0], '.,!?;:。、');
        $at -= strlen($m[7][0]) - strlen($url);
        $out .= $keep($links && filter_var($url, FILTER_VALIDATE_URL) ? '<a href="' . h($url) . '" rel="noopener noreferrer">' . h($url) . '</a>' : h($url));
    }
    $out .= h(substr($text, $at));
    $out = (string)preg_replace(['/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '/~~(?=\S)(.+?)(?<=\S)~~/u', '/(?<!\*)\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?!\*)/u'], ['<strong>$1</strong>', '<del>$1</del>', '<em>$1</em>'], $out);
    return (string)preg_replace_callback('/\x01([0-9]+)\x02/', static fn($t) => $tokens[(int)$t[1]] ?? '', $out);
}
/** Link targets: ordinary and relative links (as in the sidebar), plus mail and phone links. */
function nl_md_url(string $url): string
{
    $url = trim($url);
    if (preg_match('/\Amailto:[^\s@<>"]+@[^\s@<>"]+\z/i', $url) || preg_match('/\Atel:\+?[0-9()\-]{3,30}\z/i', $url)) return $url;
    return nl_sidebar_url($url);
}
/** The LOG picture a URL points to (./?media=<id>…, or the same under this site's address), if any. */
function nl_md_media(string $url): ?array
{
    $base = nl_base_url();
    $local = !preg_match('~\A[a-z][a-z0-9+.-]*:~i', $url) && !str_starts_with($url, '//');
    if (!$local && !str_starts_with(strtolower($url), strtolower($base) . '/')) return null;
    if (!preg_match('/[?&]media=([a-f0-9]{16})(?:&|#|\z)/', $url, $m)) return null;
    return nl_load_media($m[1]);
}
function nl_md_image(string $alt, string $url, bool $admin): string
{
    $alt = mb_substr($alt, 0, 300);
    if ($m = nl_md_media($url)) {
        // Readers see pictures that something published uses; the owner also sees them on a draft page.
        if (!$admin && !(defined('NL_OWNER') && NL_OWNER) && !nl_media_public($m['id'])) return '';
        // The address is rebuilt from the record, so a replaced picture shows its new version.
        $src = nl_media_url($m, false, $admin);
        return '<a class="imagelink log-md-image" href="' . h($src) . '" data-ns-width="' . (int)$m['w'] . '" data-ns-height="' . (int)$m['h'] . '"><img src="' . h($src) . '" width="' . (int)$m['w'] . '" height="' . (int)$m['h'] . '" alt="' . h($alt !== '' ? $alt : $m['alt']) . '" loading="lazy"></a>';
    }
    $src = nl_sidebar_url($url, true);
    return $src !== '' ? '<img class="log-md-image" src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy" referrerpolicy="no-referrer">' : h($alt);
}
/** Outside picture hosts a rendered page uses, for the page's Content-Security-Policy. */
function nl_md_image_origins(string $html): string
{
    preg_match_all('/<img\b[^>]*\bsrc="(https:\/\/[^"]+)"/i', $html, $m);
    $origins = [];
    foreach ($m[1] as $src) {
        $p = parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $origin = 'https://' . strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
        if (preg_match('~\Ahttps://[a-z0-9.\[\]:-]+\z~', $origin)) $origins[$origin] = true;
    }
    return $origins ? ' ' . implode(' ', array_keys($origins)) : '';
}
/** Plain text of a page for its description: Markdown marks and URLs removed. */
function nl_page_excerpt(array $p, int $length = 120): string
{
    $text = (string)preg_replace(['/^ {0,3}(`{3,}|~{3,}).*?^ {0,3}\1/ms', '/!\[[^\]]*\]\([^)]*\)/u', '/\[([^\]]*)\]\([^)]*\)/u', '~https?://\S+~u', '/^ {0,3}(?:#{1,6}|>|[-*+]|[0-9]+[.)])[ \t]+/mu', '/^[\s|:-]+$/mu', '/[*_~`|]/u'], ['', '', '$1', '', '', '', ''], $p['body']);
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
}

/* ---- Admin ---------------------------------------------------------------------------------- */

function nl_pages_tabs(string $current): string
{
    $tab = static fn(string $key, string $href, string $icon, string $label) => '<a href="' . $href . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . nl_ui_icon($icon) . '<span>' . $label . '</span></a>';
    return '<nav class="workspace-tabs log-admin-tabs" aria-label="LOGの管理">' . $tab('posts', 'index.php?p=log', 'list', '投稿一覧') . $tab('taxonomy', 'index.php?p=log&amp;view=taxonomy', 'tag', 'カテゴリ・タグ')
        . $tab('pages', 'index.php?p=log_pages', 'page', '固定ページ') . $tab('settings', 'index.php?p=settings&amp;section=log', 'settings', 'LOGの設定') . '</nav>';
}
function nl_view_pages(): void
{
    $rows = '';
    foreach (nl_pages() as $p) {
        $published = $p['status'] === 'published';
        $rows .= '<li class="log-page-row"><a class="log-page-row-title" href="index.php?p=log_page&amp;id=' . h($p['id']) . '">' . nl_ui_icon('page') . '<span>' . h($p['title']) . '</span>' . ($published ? '' : '<small class="badge">下書き</small>') . '</a>'
            . '<code class="log-page-row-url">?pg=' . h($p['slug']) . '</code><span class="log-page-row-layout">' . h(NL_PAGE_LAYOUTS[$p['layout']] ?? '') . '</span><span class="log-page-row-time">' . nl_ui_icon('time') . '<time datetime="' . h(nl_date($p['updated'], 'c')) . '">' . h(nl_date($p['updated'])) . '</time></span>'
            . ($published ? '<a class="btn log-list-view" href="../' . h(nl_fixed_url($p)) . '">' . nl_ui_icon('view') . '<span>ページを見る</span></a>' : '<span class="log-list-view note">非公開</span>')
            . '<a class="btn log-list-edit" href="index.php?p=log_page&amp;id=' . h($p['id']) . '">' . nl_ui_icon() . '<span>編集</span></a></li>';
    }
    $s = nl_settings();
    nm_layout('固定ページ', '<div class="log-heading"><div><h1>固定ページ</h1><p class="note">' . h($s['title']) . ' · プライバシーポリシー・利用規約・プロフィールなど、日付の流れに入らないページです。</p></div><div class="log-toolbar"><a class="btn primary" href="index.php?p=log_page">' . nl_ui_icon() . '<span>新しい固定ページ</span></a></div></div>'
        . nl_pages_tabs('pages')
        . '<p class="note">本文はMarkdownで書きます。トップメニューやサイドバーのリンク集から、タイトルで探してリンクできます。</p>'
        . '<ul class="log-page-list">' . ($rows ?: '<li class="log-empty">まだ固定ページはありません。「新しい固定ページ」から作れます。</li>') . '</ul>');
}
/** Markdown help for the page editor. */
function nl_page_help(): string
{
    $rows = [
        ['## 見出し', '大きな見出し（# も同じ）。### で小見出し、#### でさらに小さく'],
        ['**太字** *斜体* ~~取り消し線~~', '文字の強調'],
        ['- 項目 / 1. 項目', '箇条書きと番号付きリスト。行頭に空白2つで入れ子'],
        ['[文字](https://example.com/)', 'リンク。./?pg=privacy のようなサイト内のURLも使えます'],
        ['![説明](./?media=…)', '画像。「画像を追加」かドロップで入ります'],
        ['> 引用', '引用'],
        ['| 項目 | 内容 |<br>|---|---|<br>| a | b |', '表（2行目は区切り）'],
        ['---', '区切り線'],
        ['`コード` / ```', 'コード。``` で囲むと複数行'],
    ];
    $body = '';
    foreach ($rows as [$code, $note]) $body .= '<tr><td><code>' . str_replace('&lt;br&gt;', '<br>', h($code)) . '</code></td><td>' . h($note) . '</td></tr>';
    return '<details class="log-page-help"><summary>Markdownの書き方</summary><table class="table"><tbody>' . $body . '</tbody></table><p class="note">改行はそのまま改行になり、空行で段落が分かれます。HTMLのタグは文字として表示します。</p></details>';
}
function nl_view_page_edit(string $id): void
{
    $p = $id !== '' ? nl_load_page($id) : null;
    if ($id !== '' && !$p) nm_not_found();
    $p ??= ['id' => '', 'title' => '', 'slug' => '', 'body' => '', 'layout' => 'narrow', 'status' => 'draft', 'revision' => 0];
    $layouts = '';
    $notes = ['sidebar' => '投稿のページと同じ、右にサイドバーのある形です。', 'wide' => 'サイドバーを出さず、本文を全幅で表示します。表の多いページに。', 'narrow' => 'サイドバーを出さず、本文の列だけを中央に寄せます。規約やポリシーの読みやすい幅です。'];
    foreach (NL_PAGE_LAYOUTS as $key => $label) $layouts .= '<label class="log-page-layout-choice"><input type="radio" name="layout" value="' . $key . '"' . ($p['layout'] === $key ? ' checked' : '') . '><span class="log-page-layout-figure is-' . $key . '" aria-hidden="true"><i></i><i></i></span><span><b>' . h($label) . '</b><small>' . h($notes[$key]) . '</small></span></label>';
    $tool = static fn(string $attrs, string $label, string $text) => '<button type="button" class="btn log-md-tool" ' . $attrs . ' title="' . h($label) . '" aria-label="' . h($label) . '">' . $text . '</button>';
    $public = $p['id'] !== '' && $p['status'] === 'published';
    nm_layout($p['id'] !== '' ? '固定ページを編集' : '新しい固定ページ', '<p class="crumb"><a href="index.php?p=log_pages">固定ページの一覧へ戻る</a></p>'
        . '<section class="card log-page-editor"><h1>' . ($p['id'] !== '' ? '固定ページを編集' : '新しい固定ページ') . '</h1>'
        . '<form method="post" action="index.php" class="form" data-page-editor>' . nl_csrf_field() . '<input type="hidden" name="do" value="log_page_save"><input type="hidden" name="page_id" value="' . h($p['id']) . '"><input type="hidden" name="revision" value="' . (int)$p['revision'] . '">'
        . '<label>タイトル<input name="title" maxlength="' . NL_PAGE_TITLE_MAX . '" required value="' . h($p['title']) . '" placeholder="例：プライバシーポリシー"></label>'
        . '<label>URL名（半角英小文字・数字・ハイフン）<span class="log-page-slug"><span aria-hidden="true">./?pg=</span><input name="slug" maxlength="40" pattern="[a-z0-9](?:[a-z0-9\-]{0,38}[a-z0-9])?" value="' . h($p['slug']) . '" placeholder="privacy" autocapitalize="off" spellcheck="false"></span></label>'
        . '<p class="note">空欄なら自動で付けます。変えると以前のURLは404になるので、公開後はなるべく変えないでください。</p>'
        . '<fieldset class="log-page-layouts"><legend>ページの幅（PC）</legend>' . $layouts . '<p class="note">スマホでは、どの形でもサイドバーを「MENU」の中に出します。</p></fieldset>'
        . '<div class="log-md-toolbar" role="toolbar" aria-label="Markdownの入力">' . $tool('data-md="heading"', '見出し', 'H') . $tool('data-md="bold"', '太字', '<b>B</b>') . $tool('data-md="link"', 'リンク', 'リンク') . $tool('data-md="list"', '箇条書き', '・') . $tool('data-md="ordered"', '番号付きリスト', '1.') . $tool('data-md="table"', '表', '表') . $tool('data-md="rule"', '区切り線', '―')
        . '<button type="button" class="btn log-md-tool" data-md-upload>' . nl_compose_icon('upload') . '<span>画像を追加</span></button><button type="button" class="btn log-md-tool" data-md-pick aria-haspopup="dialog">' . nl_ui_icon('images') . '<span>画像一覧から選ぶ</span></button><input type="file" data-md-upload-input accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp,image/svg+xml,.svg" multiple hidden>'
        . '<button type="button" class="btn log-md-tool" data-md-preview aria-pressed="false">' . nl_ui_icon('view') . '<span>プレビュー</span></button></div>'
        . '<label class="log-page-body-label">本文（Markdown）<textarea name="body" rows="22" maxlength="' . NL_PAGE_BODY_MAX . '" spellcheck="false" data-md-body placeholder="## はじめに&#10;このサイトでは…">' . h($p['body']) . '</textarea></label>'
        . '<div class="log-page-images" data-md-images aria-label="本文の画像"></div>'
        . '<div class="log-page-preview log-page-body" data-md-preview-body hidden></div><p class="note" role="status" aria-live="polite" data-md-status>画像はこの枠のどこへドロップしても、本文へ貼り付けても追加できます。カーソルの位置に入ります。</p>'
        . nl_page_help()
        . '<div class="log-submit"><button class="btn" name="status" value="draft">下書き保存</button><button class="btn primary" name="status" value="published">公開して保存</button></div></form>'
        . '<dialog class="log-page-picker" aria-labelledby="log-page-picker-title" data-md-picker><div class="log-page-picker-head"><h2 id="log-page-picker-title">画像一覧から選ぶ</h2><button type="button" class="btn" data-pick-close>閉じる</button></div>'
        . '<label class="log-page-picker-search"><span class="sr-only">画像を検索</span><input type="search" data-pick-search placeholder="画像の説明で探す" autocomplete="off"></label><p class="note" data-pick-status role="status" aria-live="polite"></p>'
        . '<div class="log-page-picker-grid" data-pick-grid></div><button type="button" class="btn" data-pick-more hidden>さらに表示</button></dialog>'
        . ($public ? '<p><a class="btn" href="../' . h(nl_fixed_url($p)) . '" target="_blank" rel="noopener">' . nl_ui_icon('view') . '<span>公開ページで見る</span></a></p>' : '')
        . '</section>'
        . ($p['id'] !== '' ? '<section class="card"><h2>この固定ページを削除</h2><p class="note">トップメニューやサイドバーに書いたリンクは残るので、あわせて外してください。本文の画像は画像一覧に残ります。</p><form method="post" action="index.php" class="js-confirm" data-confirm="この固定ページを削除しますか？元に戻せません。">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_page_delete"><input type="hidden" name="page_id" value="' . h($p['id']) . '"><input type="hidden" name="revision" value="' . (int)$p['revision'] . '"><button class="btn danger">固定ページを削除</button></form></section>' : ''));
}
