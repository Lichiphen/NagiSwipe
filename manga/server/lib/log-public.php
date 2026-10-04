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
function nl_icon_html(array $s, string $prefix = '', string $class = 'log-avatar'): string
{
    $m = nl_load_media($s['icon']);
    return $m ? '<img class="' . h($class) . '" src="' . h($prefix . nl_media_url($m, true)) . '" alt="' . h($s['name'] . 'のアイコン') . '">'
        : '<span class="' . h($class) . '" aria-hidden="true">' . h(mb_substr($s['name'], 0, 1)) . '</span>';
}
function nl_login_icon(): string
{
    return '<svg class="log-login-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/></svg>';
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
function nl_sidebar(array $summaries, array $filter, array $s): string
{
    $latest = '';
    foreach (array_slice($summaries, 0, 3) as $p) $latest .= '<li><a href="./?id=' . h($p['id']) . '">' . h($p['title']) . '</a><time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time></li>';
    $last = max((int)$s['updated'], (int)nl_taxonomy()['updated']);
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
    return '<aside class="log-sidebar" id="log-sidebar" aria-labelledby="log-menu-title"><div class="log-sidebar-head"><h2 id="log-menu-title">メニュー</h2><button class="log-menu-close" type="button" aria-label="メニューを閉じる">×</button></div>'
        . '<a class="log-login-link" href="admin/login.php">' . nl_login_icon() . '<span>ログイン</span></a>'
        . nl_calendar($summaries, $filter) . '<section class="log-widget"><h2>最新ポスト</h2><ol class="log-latest">' . ($latest ?: '<li>まだ記録はありません。</li>') . '</ol></section>' . nl_taxonomy_sidebar($summaries)
        . '<section class="log-widget"><h2>最終更新日</h2>' . ($last > 0 ? '<time datetime="' . h(nl_date($last, 'c')) . '">' . h(nl_date($last)) . '</time>' : '<p>まだ更新はありません。</p>') . '</section><a href="./">すべての投稿</a></aside>';
}
function nl_taxonomy_sidebar(array $summaries): string
{
    $counts = []; $catLinks = ''; $tagLinks = '';
    foreach ($summaries as $p) foreach ($p['categories'] ?? [] as $id) $counts[$id] = ($counts[$id] ?? 0) + 1;
    foreach (nl_taxonomy()['categories'] as $id => $name) if (isset($counts[$id])) $catLinks .= '<a href="./?category=' . h((string)$id) . '">' . h($name) . '<small>' . $counts[$id] . '</small></a>';
    foreach (nl_recent_hashtags(true) as $name) $tagLinks .= '<a href="./?tag=' . h(rawurlencode($name)) . '">#' . h($name) . '</a>';
    return ($catLinks !== '' ? '<section class="log-widget"><h2>カテゴリ</h2><div class="log-chip-list">' . $catLinks . '</div></section>' : '') . ($tagLinks !== '' ? '<section class="log-widget"><h2>ハッシュタグ</h2><div class="log-chip-list">' . $tagLinks . '</div></section>' : '');
}
