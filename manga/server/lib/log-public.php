<?php
/* LOG sidebar and calendar. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_archive_filter(array $src): array
{
    $base = ['date' => '', 'month' => nl_date(time(), 'Y-m'), 'query' => '', 'label' => '', 'category' => '', 'tag' => ''];
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
        return '<a class="log-post-nav-link ' . ($older ? 'is-older" rel="next"' : 'is-newer" rel="prev"') . ' href="./?id=' . h($p['id']) . '"><span class="log-post-nav-kicker">' . ($older ? '<span>過去の投稿</span>' . nl_pager_arrow(true) : nl_pager_arrow(false) . '<span>新しい投稿</span>') . '</span><span class="log-post-nav-title">' . h($p['title'] !== '' ? $p['title'] : '画像の記録') . '</span><time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'], 'Y/m/d')) . '</time></a>';
    };
    return '<nav class="log-post-nav" aria-label="前後の投稿">' . $link($summaries[$i - 1] ?? null, false) . $link($summaries[$i + 1] ?? null, true)
        . '<a class="log-all-chip" href="./">' . nl_all_icon() . '<span>すべての投稿</span><span class="log-all-count">' . count($summaries) . '</span></a></nav>';
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
    if ($s['icon'] !== '') $media[$s['icon']] = true;
    if ($s['og_image'] !== '') $media[$s['og_image']] = true;
    foreach (array_keys($media) as $id) { $m = nl_load_media((string)$id); if ($m) $last = max($last, (int)$m['updated']); }
    foreach (array_keys($works) as $id) { $w = nm_load_work((string)$id); if ($w) $last = max($last, (int)($w['updated'] ?? 0)); }
    $blocks = [
        'login' => $s['show_login'] ? '<a class="log-login-link" href="' . ($owner ? 'admin/index.php?p=log' : 'admin/login.php') . '">' . nl_login_icon() . '<span>' . ($owner ? '管理ページ' : 'ログイン') . '</span></a>' : '',
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
