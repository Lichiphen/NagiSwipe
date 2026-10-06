<?php
/* LOG sidebar and calendar. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_archive_filter(array $src): array
{
    $base = ['date' => '', 'month' => nl_date(time(), 'Y-m'), 'query' => '', 'label' => '', 'category' => '', 'tag' => '', 'search' => '', 'terms' => []];
    if (isset($src['q'])) {
        $q = trim((string)preg_replace('/[\s　]+/u', ' ', nm_str($src, 'q', 400)));
        if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);
        $terms = nl_search_terms($q);
        if ($terms) return ['search' => $q, 'terms' => $terms, 'query' => 'q=' . rawurlencode($q), 'label' => '「' . $q . '」の検索結果'] + $base;
    }
    if (isset($src['category'])) {
        $id = nm_str($src, 'category', 12); $tax = nl_taxonomy();
        if (!isset($tax['categories'][$id])) nm_not_found();
        return ['category' => $id, 'query' => 'category=' . $id, 'label' => $tax['categories'][$id] . 'の記録'] + $base;
    }
    if (isset($src['tag'])) {
        $tag = nm_str($src, 'tag', 250);
        if (!preg_match('/\A[\p{L}\p{M}\p{N}_]{1,60}\z/u', $tag)) nm_not_found();
        return ['tag' => $tag, 'query' => 'tag=' . rawurlencode($tag), 'label' => '#' . $tag . 'の記録'] + $base;
    }
    $day = nm_str($src, 'date', 10);
    $month = nm_str($src, 'month', 7);
    if (isset($src['date'])) {
        if (!preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $day, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) nm_not_found();
        return ['date' => $day, 'month' => substr($day, 0, 7), 'query' => 'date=' . $day, 'label' => $day . 'の記録'] + $base;
    }
    if (isset($src['month'])) {
        if (!preg_match('/\A([0-9]{4})-([0-9]{2})\z/', $month, $m) || !checkdate((int)$m[2], 1, (int)$m[1])) nm_not_found();
        return ['month' => $month, 'query' => 'month=' . $month, 'label' => $month . 'の記録'] + $base;
    }
    return $base;
}
/** True when the HTML links to an image NagiSwipe would open (same test as its isImageUrl: the path or the whole URL ends with an image extension). */
function nl_has_image_links(string $html): bool
{
    if (str_contains($html, 'class="imagelink"')) return true;
    preg_match_all('/\bhref="([^"]+)"/', $html, $m);
    foreach ($m[1] as $href) {
        $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('/\.(?:jpe?g|png|webp|gif|bmp|avif|svg)\z/i', $path) || preg_match('/\.(?:jpe?g|png|webp|gif|bmp|avif|svg)\z/i', $url)) return true;
    }
    return false;
}
/**
 * Breadcrumb steps for a page: [name, query (without "?"), or null for the page itself].
 * The top page's first page has none; a post goes through its first category.
 */
function nl_breadcrumb_trail(array $s, array $filter, int $page, ?array $post = null): array
{
    $trail = [[nl_crumb_home($s), '']];
    if ($post) {
        $cats = nl_taxonomy()['categories'];
        foreach ($post['categories'] ?? [] as $id) if (isset($cats[$id])) { $trail[] = [$cats[$id], 'category=' . $id]; break; }
        $trail[] = [nl_post_title($post), null];
        return $trail;
    }
    if ($filter['category'] !== '') $trail[] = [nl_taxonomy()['categories'][$filter['category']], $filter['query']];
    elseif ($filter['tag'] !== '') $trail[] = ['#' . $filter['tag'], $filter['query']];
    elseif ($filter['search'] !== '') $trail[] = ['「' . $filter['search'] . '」の検索結果', $filter['query']];
    elseif ($filter['date'] !== '') {
        [$y, $m, $d] = array_map('intval', explode('-', $filter['date']));
        $trail[] = [$y . '年' . $m . '月', 'month=' . $filter['month']];
        $trail[] = [$m . '月' . $d . '日', $filter['query']];
    } elseif ($filter['query'] !== '') {
        [$y, $m] = array_map('intval', explode('-', $filter['month']));
        $trail[] = [$y . '年' . $m . '月', $filter['query']];
    }
    if ($page > 1) $trail[] = [$page . 'ページ目', null];
    if (count($trail) === 1) return [];
    $last = count($trail) - 1;
    $trail[$last][1] = null;
    return $trail;
}
/**
 * The visible breadcrumb (house icon + HOME or the site name › … › this page) and the same steps as schema.org
 * BreadcrumbList JSON-LD for search engines. Returns [html, json] ('' when there is no trail).
 */
function nl_breadcrumb(array $trail, string $canonical): array
{
    if (!$trail) return ['', ''];
    $base = nl_base_url();
    $home = '<svg class="log-crumb-home" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 10.6 12 3.8l8.5 6.8"/><path d="M5.8 9v10.2a.8.8 0 0 0 .8.8h3.6v-5.4a1.8 1.8 0 0 1 3.6 0V20h3.6a.8.8 0 0 0 .8-.8V9"/></svg>';
    $sep = '<svg class="log-crumb-sep" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9.5 6 6 6-6 6"/></svg>';
    $items = ''; $list = [];
    foreach ($trail as $i => [$name, $query]) {
        $current = $query === null;
        $url = $current ? $canonical : $base . '/' . ($query !== '' ? '?' . $query : '');
        $label = ($i === 0 ? $home : '') . '<span>' . h($name) . '</span>';
        $items .= '<li' . ($current ? ' aria-current="page"' : '') . '>' . ($i > 0 ? $sep : '')
            . ($current ? '<span class="log-crumb">' . $label . '</span>' : '<a class="log-crumb" href="./' . ($query !== '' ? '?' . h($query) : '') . '"' . ($i === 0 ? ' title="' . h(nl_settings()['title']) . 'のトップ"' : '') . '>' . $label . '</a>') . '</li>';
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
    }
    $json = json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return ['<nav class="log-breadcrumb" aria-label="パンくずリスト"><ol>' . $items . '</ol></nav>', (string)$json];
}
function nl_page_url(string $query, int $page): string
{
    $q = $query . ($page > 1 ? ($query !== '' ? '&' : '') . 'page=' . $page : '');
    return './' . ($q !== '' ? '?' . $q : '');
}
function nl_pager_arrow(bool $older): string
{
    return '<span class="log-pager-arrow" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="' . ($older ? 'm10 6 6 6-6 6' : 'm14 6-6 6 6 6') . '"/></svg></span>';
}
/** Page numbers to show: first, last, the current page and its neighbours; a gap hiding one page shows that page instead. */
function nl_pager_numbers(int $page, int $pages): array
{
    $keep = [1, $pages, $page - 1, $page, $page + 1];
    if ($page <= 3) array_push($keep, 2, 3, 4);
    if ($page >= $pages - 2) array_push($keep, $pages - 1, $pages - 2, $pages - 3);
    $keep = array_values(array_unique(array_filter($keep, static fn($n) => $n >= 1 && $n <= $pages)));
    sort($keep);
    $out = [];
    foreach ($keep as $n) {
        $prev = $out ? end($out) : 0;
        if ($prev && $n - $prev === 2) $out[] = $prev + 1;
        elseif ($prev && $n - $prev > 2) $out[] = null;
        $out[] = $n;
    }
    return $out;
}
/** The list pager. Every control is a plain link; log-pager.js only enhances "もっと見る". */
function nl_pager(array $s, string $query, int $page, int $total): string
{
    $per = $s['posts_per_page'];
    $pages = max(1, (int)ceil($total / $per));
    if ($pages < 2) return '';
    $mode = $s['pager'];
    $start = ($page - 1) * $per + 1; $end = min($total, $page * $per);
    $status = $s['pager_status'] ? '<p class="log-pager-status" aria-live="polite"><span class="log-pager-total">' . $total . '件中</span><b>' . $start . '〜' . $end . '件目</b>' . ($mode !== 'more' ? '<span class="log-pager-of">' . $page . ' / ' . $pages . 'ページ</span>' : '') . '</p>' : '';
    $newer = $page > 1
        ? '<a class="log-pager-step is-newer" rel="prev" href="' . h(nl_page_url($query, $page - 1)) . '">' . nl_pager_arrow(false) . '<span>新しい投稿</span></a>'
        : '<span class="log-pager-step is-newer" aria-disabled="true">' . nl_pager_arrow(false) . '<span>新しい投稿</span></span>';
    $older = $page < $pages
        ? '<a class="log-pager-step is-older" rel="next" href="' . h(nl_page_url($query, $page + 1)) . '"><span>過去の投稿</span>' . nl_pager_arrow(true) . '</a>'
        : '<span class="log-pager-step is-older" aria-disabled="true"><span>過去の投稿</span>' . nl_pager_arrow(true) . '</span>';
    $data = ' data-mode="' . $mode . '" data-start="' . $start . '" data-end="' . $end . '" data-total="' . $total . '"';
    if ($mode === 'more') {
        $next = min($per, $total - $end);
        $body = ($page < $pages
                ? '<a class="log-pager-more" rel="next" href="' . h(nl_page_url($query, $page + 1)) . '" data-pager-more><span>もっと見る</span><small>過去の投稿を' . $next . '件</small></a>'
                : '<p class="log-pager-end">ここまでで全部です</p>')
            . ($page > 1 ? '<a class="log-pager-back" href="' . h(nl_page_url($query, 1)) . '">' . nl_pager_arrow(false) . '<span>最新の投稿から見る</span></a>' : '');
        return '<nav class="log-pager is-more" aria-label="投稿一覧のページ"' . $data . '>' . $status . $body . '</nav>';
    }
    $numbers = '';
    if ($mode === 'numbers') {
        foreach (nl_pager_numbers($page, $pages) as $n) {
            $numbers .= $n === null ? '<li class="log-pager-gap" aria-hidden="true"><span></span><span></span><span></span></li>'
                : ($n === $page ? '<li><span class="log-pager-num" aria-current="page"><span class="sr-only">ページ</span>' . $n . '</span></li>'
                    : '<li><a class="log-pager-num" href="' . h(nl_page_url($query, $n)) . '"><span class="sr-only">ページ</span>' . $n . '</a></li>');
        }
        $numbers = '<ol class="log-pager-pages">' . $numbers . '</ol>';
    }
    $jump = '';
    if ($mode === 'numbers' && $pages >= 8) {
        parse_str($query, $hidden);
        $fields = '';
        foreach ($hidden as $k => $v) if (is_string($v)) $fields .= '<input type="hidden" name="' . h((string)$k) . '" value="' . h($v) . '">';
        $jump = '<form class="log-pager-jump" method="get" action="./">' . $fields . '<label><span>ページを指定</span><input type="number" name="page" min="1" max="' . $pages . '" value="' . $page . '" inputmode="numeric" required></label><span aria-hidden="true">/ ' . $pages . '</span><button type="submit">移動</button></form>';
    }
    return '<nav class="log-pager is-' . $mode . '" aria-label="投稿一覧のページ"' . $data . '>' . $status . '<div class="log-pager-row">' . $newer . $numbers . $older . '</div>' . $jump . '</nav>';
}
/** Newer / older neighbours of a published post, with a way back to the whole list. */
function nl_post_nav(array $summaries, array $post): string
{
    $ids = array_column($summaries, 'id');
    $i = array_search($post['id'], $ids, true);
    if ($i === false) return '';
    $link = static function (?array $p, bool $older): string {
        if (!$p) return '<span class="log-post-nav-link is-empty" aria-hidden="true"></span>';
        $tab = '<span class="log-post-nav-tab" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="' . ($older ? 'm10 6 6 6-6 6' : 'm14 6-6 6 6 6') . '"/></svg></span>';
        return '<a class="log-post-nav-link ' . ($older ? 'is-older" rel="next"' : 'is-newer" rel="prev"') . ' href="./?id=' . h($p['id']) . '">' . $tab . '<span class="log-post-nav-body"><span class="log-post-nav-kicker">' . ($older ? '過去の投稿' : '新しい投稿') . '<time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'], 'Y/m/d')) . '</time></span><span class="log-post-nav-title">' . h($p['title'] !== '' ? $p['title'] : '画像の記録') . '</span></span></a>';
    };
    return '<nav class="log-post-nav" aria-label="前後の投稿">' . $link($summaries[$i - 1] ?? null, false) . $link($summaries[$i + 1] ?? null, true)
        . '<a class="log-all-chip" href="./">' . nl_all_icon() . '<span>すべての投稿</span><span class="log-all-count">' . count($summaries) . '</span></a></nav>';
}
/** Our own share UI icons from viewer/link-icons (original artwork, no service logos). */
function nl_share_icon(string $name, string $extra = ''): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $svg = (string)file_get_contents(__DIR__ . '/../viewer/link-icons/' . $name . '.svg');
        $cache[$name] = (string)preg_replace('/\A.*?(<svg )/s', '$1', $svg);
    }
    return str_replace('<svg ', '<svg class="log-share-icon' . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true" focusable="false" ', $cache[$name]);
}
/**
 * Like (when turned on), Share and Copy under a post; Share and Copy both with "title\nURL". viewer/log-share.js sends Share to the phone's share sheet,
 * or to the dialog from nl_share_dialog() on a PC; without script the Share link opens X.
 */
function nl_share_row(array $p): string
{
    $title = nl_post_title($p, 120);
    $text = ($title !== '' ? $title : '画像の記録') . "\n" . nl_base_url() . '/?id=' . rawurlencode($p['id']);
    return '<div class="log-share" data-share-text="' . str_replace("\n", '&#10;', h($text)) . '">' . (nl_settings()['likes'] ? nl_like_button($p) : '')
        . '<a class="log-share-btn log-share-open" href="https://x.com/intent/tweet?text=' . rawurlencode($text) . '" target="_blank" rel="noopener noreferrer" aria-haspopup="dialog">' . nl_share_icon('share') . '<span>Share</span></a>'
        . '<button class="log-share-btn log-share-copy" type="button" aria-label="タイトルとURLをコピー">' . nl_share_icon('copy', 'log-share-icon-copy') . nl_share_icon('check', 'log-share-icon-done') . '<span class="log-share-label" aria-live="polite">Copy</span></button></div>';
}
/** One dialog per page for PCs: X, はてなブックマーク, LINE, Instagram. The script fills in the post's text. */
function nl_share_dialog(): string
{
    $services = [
        'x' => ['conversation', 'X'],
        'hatena' => ['bookmark-save', 'はてな|ブックマーク'],
        'line' => ['chat-bubbles', 'LINE'],
        'instagram' => ['pictures', 'Instagram'],
    ];
    $items = '';
    foreach ($services as $key => [$icon, $label]) $items .= '<button class="log-share-service" type="button" data-share-service="' . $key . '">' . nl_share_icon($icon) . '<span>' . str_replace('|', '<wbr>', h($label)) . '</span></button>';
    return '<dialog class="log-share-dialog" aria-labelledby="log-share-title"><div class="log-share-dialog-body"><h2 id="log-share-title">シェア</h2>'
        . '<p class="log-share-preview"></p><div class="log-share-services">' . $items . '</div>'
        . '<p class="log-share-status" role="status"></p><button class="log-share-close" type="button">閉じる</button></div></dialog>';
}
function nl_search_form(string $q): string
{
    return '<form class="log-search" role="search" method="get" action="./"><label class="sr-only" for="log-search-q">LOGを検索</label><input id="log-search-q" type="search" name="q" value="' . h($q) . '" maxlength="100" placeholder="ことば・#タグで探す" enterkeyhint="search" autocomplete="off"><button type="submit" aria-label="検索する"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 4.5 4.5"/></svg></button></form>';
}
/**
 * Site titles of 8 characters or more shrink on phones to stay on one line.
 * The width in em is estimated from the characters (full width 1em, half width about 0.62em, plus letter spacing).
 */
function nl_title_fit(string $title): string
{
    $chars = mb_strlen($title);
    if ($chars < 8) return '';
    $full = mb_strwidth($title) - $chars;
    $em = ($full + ($chars - $full) * 0.62 + $chars * 0.04) * 1.04;
    return ' class="log-title-long" style="--log-title-em:' . number_format($em, 2, '.', '') . '"';
}
function nl_all_icon(): string
{
    return '<svg class="log-all-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="2"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="2"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="2"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="2"/></svg>';
}
function nl_icon_html(array $s, string $prefix = '', string $class = 'log-avatar'): string
{
    $m = nl_load_media($s['icon']);
    if (!$s['public'] && !(defined('NL_OWNER') && NL_OWNER) && !(function_exists('nm_is_logged_in') && nm_is_logged_in() && !nm_is_guest())) $m = null;
    return $m ? '<img class="' . h($class) . '" src="' . h($prefix . nl_media_url($m, true)) . '" alt="' . h($s['name'] . 'のアイコン') . '">'
        : '<span class="' . h($class) . '" aria-hidden="true">' . h(mb_substr($s['name'], 0, 1)) . '</span>';
}
function nl_login_icon(): string
{
    return '<svg class="log-login-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/></svg>';
}
function nl_ui_icon(string $name = 'pen'): string
{
    $paths = [
        'pen' => '<path d="m15 4 5 5M4 20l4-1L20 7a2 2 0 0 0-3-3L5 16l-1 4Z"/>',
        'time' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'title' => '<path d="M5 4h14M12 4v16M8 20h8"/>',
        'delete' => '<path d="M4 6h16M9 6V3h6v3M6 6l1 14h10l1-14M10 10v6M14 10v6"/>',
        'view' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'wrench' => '<path d="M14.7 6.3a4.5 4.5 0 0 0-5.9 5.9L3.5 17.5a2.1 2.1 0 0 0 3 3l5.3-5.3a4.5 4.5 0 0 0 5.9-5.9l-2.8 2.8-2.4-.6-.6-2.4 2.8-2.8Z"/>',
        // The LOG admin tabs and toolbar: the post list, categories and tags, settings, the public page / private Memo.
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
        'tag' => '<path d="M3.5 12.6V5a1.5 1.5 0 0 1 1.5-1.5h7.6a1.5 1.5 0 0 1 1.06.44l7.4 7.4a1.5 1.5 0 0 1 0 2.12l-7.6 7.6a1.5 1.5 0 0 1-2.12 0l-7.4-7.4A1.5 1.5 0 0 1 3.5 12.6Z"/><circle cx="8.5" cy="8.5" r="1.6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1.1-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1Z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'images' => '<rect x="7" y="3.5" width="13.5" height="13.5" rx="2.2"/><path d="M4 7.5v10.3A2.7 2.7 0 0 0 6.7 20.5H17"/><circle cx="11.5" cy="8" r="1.4"/><path d="m7 15 3.6-3.4a1.4 1.4 0 0 1 1.9 0L17 16"/>',
    ];
    return '<svg class="log-ui-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['pen']) . '</svg>';
}
function nl_calendar(array $summaries, array $filter): string
{
    $first = new DateTimeImmutable($filter['month'] . '-01', new DateTimeZone('Asia/Tokyo'));
    $prev = $first->modify('-1 month')->format('Y-m');
    $next = $first->modify('+1 month')->format('Y-m');
    $counts = [];
    foreach ($summaries as $p) {
        $date = nl_date($p['created'], 'Y-m-d');
        $counts[$date] = ($counts[$date] ?? 0) + 1;
    }
    $html = '<section class="log-widget"><h2>カレンダー</h2><nav class="log-calendar-nav" aria-label="カレンダーの月"><a href="./?month=' . $prev . '" aria-label="前の月">‹</a><a href="./?month=' . h($filter['month']) . '">' . $first->format('Y年n月') . '</a><a href="./?month=' . $next . '" aria-label="次の月">›</a></nav>'
        . '<table class="log-calendar"><thead><tr>';
    foreach (['日', '月', '火', '水', '木', '金', '土'] as $d) $html .= '<th scope="col">' . $d . '</th>';
    $html .= '</tr></thead><tbody><tr>';
    $offset = (int)$first->format('w');
    for ($i = 0; $i < $offset; $i++) $html .= '<td></td>';
    $days = (int)$first->format('t');
    $today = nl_date(time(), 'Y-m-d');
    for ($day = 1; $day <= $days; $day++) {
        if (($offset + $day - 1) % 7 === 0 && $day !== 1) $html .= '</tr><tr>';
        $date = $filter['month'] . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        $class = ($date === $today ? ' today' : '') . ($date === $filter['date'] ? ' selected' : '');
        $html .= '<td class="' . trim($class) . '">';
        $html .= isset($counts[$date]) ? '<a href="./?date=' . $date . '" aria-label="' . $date . '・' . $counts[$date] . '件の投稿"' . ($date === $filter['date'] ? ' aria-current="date"' : '') . '>' . $day . '</a>' : '<span>' . $day . '</span>';
        $html .= '</td>';
    }
    $remaining = (7 - ($offset + $days) % 7) % 7;
    for ($i = 0; $i < $remaining; $i++) $html .= '<td></td>';
    return $html . '</tr></tbody></table></section>';
}
function nl_sidebar(array $summaries, array $filter, array $s, bool $owner = false): string
{
    $latest = '';
    foreach (array_slice($summaries, 0, 3) as $p) $latest .= '<li><a href="./?id=' . h($p['id']) . '">' . h($p['title']) . '</a><time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time></li>';
    $sidebar = nl_sidebar_settings();
    $last = max((int)$s['updated'], (int)nl_taxonomy()['updated'], (int)$sidebar['updated']);
    $media = []; $works = [];
    foreach ($summaries as $p) {
        $last = max($last, (int)$p['updated']);
        foreach ($p['media'] as $id) $media[$id] = true;
        foreach ($p['manga'] as $id) $works[$id] = true;
    }
    if (($changed = nm_content_updated()) !== null) {
        $last = max($last, $changed);
    } else {
        // Installs that have not saved an image or work since the timestamp existed.
        if ($s['icon'] !== '') $media[$s['icon']] = true;
        if ($s['og_image'] !== '') $media[$s['og_image']] = true;
        foreach (array_keys($media) as $id) { $m = nl_load_media((string)$id); if ($m) $last = max($last, (int)$m['updated']); }
        foreach (array_keys($works) as $id) { $w = nm_load_work((string)$id); if ($w) $last = max($last, (int)($w['updated'] ?? 0)); }
    }
    $blocks = [
        'login' => $s['show_login'] ? '<a class="log-login-link" href="' . ($owner ? 'admin/index.php?p=log' : 'admin/login.php') . '">' . nl_login_icon() . '<span>' . ($owner ? '管理ページ' : 'ログイン') . '</span></a>' : '',
        'search' => nl_search_form($filter['search']),
        'calendar' => nl_calendar($summaries, $filter),
        'latest' => '<section class="log-widget"><h2>最新ポスト</h2><ol class="log-latest">' . ($latest ?: '<li>まだ記録はありません。</li>') . '</ol></section>',
        'categories' => nl_taxonomy_sidebar($summaries, 'categories'),
        'hashtags' => nl_taxonomy_sidebar($summaries, 'hashtags'),
        'updated' => '<section class="log-widget"><h2>最終更新日</h2>' . ($last > 0 ? '<time datetime="' . h(nl_date($last, 'c')) . '">' . h(nl_date($last)) . '</time>' : '<p>まだ更新はありません。</p>') . '</section>',
        'all' => '<a class="log-all-link" href="./">' . nl_all_icon() . '<span>すべての投稿</span><span class="log-all-count">' . count($summaries) . '</span></a>',
    ];
    $html = '';
    foreach ($sidebar['items'] as $item) {
        if (!$item['enabled']) continue;
        if ($item['kind'] === 'links') {
            $html .= '<div class="log-sidebar-block" data-sidebar-id="links">' . nl_links_html($item, $s) . '</div>';
            continue;
        }
        $block = $item['kind'] === 'html'
            ? '<section class="' . ($item['framed'] ? 'log-widget log-custom-block' : 'log-custom-block') . '">' . ($item['title'] !== '' ? '<h2>' . h($item['title']) . '</h2>' : '') . nl_sidebar_html($item['html']) . '</section>'
            : ($blocks[$item['kind']] ?? '');
        if ($block !== '') $html .= '<div class="log-sidebar-block" data-sidebar-id="' . h($item['id']) . '">' . $block . '</div>';
    }
    return '<aside class="log-sidebar" id="log-sidebar" aria-labelledby="log-menu-title"><div class="log-sidebar-head"><h2 id="log-menu-title">メニュー</h2><button class="log-menu-close" type="button" aria-label="メニューを閉じる">×</button></div>' . $html . '</aside>';
}
function nl_taxonomy_sidebar(array $summaries, string $kind = ''): string
{
    $counts = []; $catLinks = ''; $tagLinks = '';
    foreach ($summaries as $p) foreach ($p['categories'] ?? [] as $id) $counts[$id] = ($counts[$id] ?? 0) + 1;
    // Categories read as a folder list with counts; hashtags as a cloud of small "#" labels, so the two never look alike.
    $folder = nl_category_icon();
    foreach (nl_taxonomy()['categories'] as $id => $name) if (isset($counts[$id])) $catLinks .= '<li><a href="./?category=' . h((string)$id) . '">' . $folder . '<span>' . h($name) . '</span><small aria-label="' . $counts[$id] . '件">' . $counts[$id] . '</small></a></li>';
    foreach (array_slice(nl_ordered_hashtags(true), 0, 8) as $name) $tagLinks .= '<a href="./?tag=' . h(rawurlencode($name)) . '">#' . h($name) . '</a>';
    return ($kind !== 'hashtags' && $catLinks !== '' ? '<section class="log-widget log-widget-categories"><h2>カテゴリ</h2><ul class="log-cat-list">' . $catLinks . '</ul></section>' : '') . ($kind !== 'categories' && $tagLinks !== '' ? '<section class="log-widget log-widget-tags"><h2>ハッシュタグ</h2><div class="log-tag-cloud">' . $tagLinks . '</div></section>' : '');
}
