<?php
/*
 * NagiScope screens in the admin (admin/index.php?p=scope): the dashboard, one work / post / page, goals, the access
 * list by IP address and the settings. Everything a visitor sent (referrers, search words, links, headings) is escaped
 * with h() on its way to the screen. MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }
require_once __DIR__ . '/scope.php';

const NS_VIEWS = ['' => 'ダッシュボード', 'goals' => 'ゴール', 'access' => 'アクセスの一覧', 'settings' => '設定'];
const NS_PERIOD_LABELS = ['day' => '1日', 'month' => '1ヶ月', 'year' => '1年'];

function ns_admin_url(array $q = []): string
{
    return 'index.php?' . http_build_query(['p' => 'scope'] + $q);
}

/** The card on the works screen while NagiScope has never been turned on. */
function ns_invite_card(): string
{
    $s = ns_settings();
    if ($s['on'] || $s['asked'] || nm_is_guest()) return '';
    return '<section class="card ns-invite"><h2>' . nm_t('NagiScope を使ってみませんか？') . '</h2>'
        . '<p>' . nm_t('漫画が何ページ目まで読まれたか、記事がどの見出しまで読まれたかが分かる、このサイトだけのアクセス解析です。外部サービスの登録やタグ貼りはいりません。いまは OFF で、何も数えていません。') . '</p>'
        . '<div class="ns-invite-actions"><a class="btn primary" href="' . h(ns_admin_url()) . '">' . nm_t('使ってみる') . '</a>'
        . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_later"><button class="btn">' . nm_t('あとで') . '</button></form></div></section>';
}

function ns_view(): void
{
    ns_aggregate();
    $view = nm_str($_GET, 'view', 12);
    $s = ns_settings();
    $head = '<link rel="stylesheet" href="' . nm_asset('scope.css') . '"><script src="' . nm_asset('scope.js') . '" defer></script>';
    if (!$s['on'] && !$s['since'] && $view !== 'settings') {
        nm_layout('NagiScope', '<h1 class="ns-title">NagiScope</h1>' . ns_start_panel(), true, $head);
        return;
    }
    $tabs = '<nav class="workspace-tabs" aria-label="NagiScope">';
    foreach (NS_VIEWS as $key => $label) {
        $current = $key === $view || ($key === '' && $view === 'item');
        $tabs .= '<a href="' . h(ns_admin_url($key !== '' ? ['view' => $key] : [])) . '"' . ($current ? ' aria-current="page"' : '') . '>' . h(nm_t($label)) . '</a>';
    }
    $tabs .= '</nav>';
    $off = $s['on'] ? '' : '<p class="warn">' . nm_t('NagiScope は OFF です。いまは何も数えていません。これまでのデータは残っています。') . ' <a href="' . h(ns_admin_url(['view' => 'settings'])) . '">' . nm_t('設定で ON にする') . '</a></p>';
    $body = match ($view) {
        'item' => ns_item_view(nm_str($_GET, 'key', 40)),
        'period' => ns_period_json(),
        'goals' => ns_goals_view(),
        'access' => ns_access_view(),
        'settings' => ns_settings_view(),
        default => ns_dashboard(),
    };
    nm_layout('NagiScope', '<h1 class="ns-title">NagiScope</h1>' . $tabs . $off . $body, true, $head);
}

// ---------------------------------------------------------------------------
// Turning it on
// ---------------------------------------------------------------------------

function ns_ip_choices(string $current): string
{
    $choices = [
        'full' => ['そのまま保存する（おすすめ）', 'つきまといや嫌がらせがあったとき、相手を絞り込む手がかりになります。管理画面では 203.0.113.45 のように見えます。プライバシーポリシーへの記載が必要です。'],
        'partial' => ['一部を伏せて保存する', '末尾を伏せて 203.0.113.xxx のように保存します。回線の範囲までは分かります。プライバシーポリシーへの記載が必要です。'],
        'none' => ['保存しない', '同じ人かどうかを見分けるためだけに使い、日ごとに変わる値にして捨てます。プライバシーポリシーには「Cookie を使わず、個人を特定しない形で数えています」程度の記載で足ります。'],
    ];
    $html = '<fieldset class="ns-ip"><legend>' . nm_t('IP アドレスをどう扱いますか？') . '</legend>';
    foreach ($choices as $key => [$label, $note]) {
        $html .= '<label class="check"><input type="radio" name="ip" value="' . $key . '"' . ($current === $key ? ' checked' : '') . ' data-ns-ip>' . nm_t($label) . '</label><p class="note">' . nm_t($note) . '</p>';
    }
    return $html . '</fieldset>';
}

/** The dialog shown before saving a setting that keeps IP addresses, with the privacy policy samples. */
function ns_policy_dialog(): string
{
    return '<dialog class="settings-leave-dialog ns-dialog" data-ns-dialog aria-labelledby="ns-dialog-title"><h2 id="ns-dialog-title">' . nm_t('IP アドレスを保存すると、プライバシーポリシーへの記載が必要です') . '</h2>'
        . '<p>' . nm_t('サイトのプライバシーポリシーに、次のような文を載せてください。保存期間は設定に合わせて直してください。') . '</p>'
        . ns_policy_samples('full', 'data-ns-sample-full') . ns_policy_samples('partial', 'data-ns-sample-partial')
        . '<div class="ns-dialog-actions"><button type="button" class="btn primary" data-ns-confirm>' . nm_t('この設定で保存する') . '</button><button type="button" class="btn" data-ns-cancel>' . nm_t('戻る') . '</button></div></dialog>';
}

function ns_policy_samples(string $mode, string $attr = ''): string
{
    $out = '<div class="ns-samples"' . ($attr !== '' ? ' ' . $attr : '') . '>';
    foreach (['ja' => '日本語', 'en' => 'English'] as $lang => $label) {
        $out .= '<div class="ns-sample"><p class="ns-sample-head"><strong>' . h($lang === 'ja' ? nm_t($label) : $label) . '</strong> <button type="button" class="btn small js-copy">' . nm_t('コピー') . '</button></p>'
            . '<textarea class="copy-src" readonly rows="6">' . h(ns_policy_text($mode, $lang)) . '</textarea></div>';
    }
    return $out . '</div>';
}

function ns_start_panel(): string
{
    return '<section class="card ns-start"><h2>' . nm_t('NagiScope を使ってみませんか？') . '</h2>'
        . '<p>' . nm_t('NagiScope は、このサイトに組み込まれたアクセス解析です。外部サービスの登録もタグ貼りもいりません。漫画は「何ページ目でやめたか」、記事は「どの見出しでやめたか」が分かります。') . '</p>'
        . '<ul class="ns-points"><li>' . nm_t('Cookie は使いません。') . '</li><li>' . nm_t('ログイン中のあなた自身の閲覧と、ロボット（検索エンジンの巡回など）は数えません。') . '</li><li>' . nm_t('いつでも OFF にできます。OFF にしても、それまでのデータは消えません。') . '</li></ul>'
        . '<form method="post" action="index.php" class="form" data-ns-ip-form>' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_enable">'
        . ns_ip_choices('full')
        . '<p class="note">' . nm_t('IP アドレスだけでは、相手の名前や住所は分かりません。相手を特定するには、プロバイダーへの発信者情報開示の手続きが必要です。保存した IP アドレスは、その手続きの証拠として使えます。') . '</p>'
        . '<button class="btn primary">' . nm_t('NagiScope を ON にする') . '</button></form>' . ns_policy_dialog() . '</section>';
}

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

function ns_pct(int $part, int $whole): string
{
    return $whole > 0 ? number_format($part / $whole * 100, 1) . '%' : '—';
}

/** "▲18%" against the period before, or '' when there is nothing to compare with. */
function ns_change(int $now, int $before): array
{
    if ($before <= 0) return ['', ''];
    $p = (int)round(($now - $before) / $before * 100);
    return [$p >= 0 ? '▲' . $p . '%' : '▼' . abs($p) . '%', $p >= 0 ? 'up' : 'down'];
}

function ns_labels(string $tab, string $from): array
{
    if ($tab === 'day') return array_map(static fn($h) => nm_t('{h}時', ['h' => $h]), range(0, 23));
    $out = [];
    $step = $tab === 'month' ? 1 : 7;
    $n = $tab === 'month' ? 30 : 52;
    for ($i = 0; $i < $n; $i++) {
        $day = ns_shift($from, $i * $step);
        $out[] = (int)substr($day, 5, 2) . '/' . (int)substr($day, 8, 2);
    }
    return $out;
}

/** A horizontal bar as SVG (the admin's Content-Security-Policy allows no inline styles). */
function ns_bar(int $n, int $max, string $class = ''): string
{
    $w = $max > 0 && $n > 0 ? max(0.5, round($n / $max * 100, 2)) : 0;
    return '<svg class="ns-bar' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="ns-bar-bg" width="100" height="10" rx="2"/><rect width="' . $w . '" height="10" rx="2"/></svg>';
}
function ns_rows(array $rows, string $empty, ?int $max = null, string $suffix = ''): string
{
    if (!$rows) return '<p class="note">' . h($empty) . '</p>';
    $max ??= max(array_column($rows, 1));
    $html = '<ul class="ns-rows">';
    foreach ($rows as [$label, $n, $link, $note]) {
        $text = $link !== '' ? '<a href="' . h($link) . '">' . h($label) . '</a>' : h($label);
        $html .= '<li><span class="ns-row-label">' . $text . ($note !== '' ? ' <small>' . h($note) . '</small>' : '') . '</span>' . ns_bar((int)$n, (int)$max) . '<span class="ns-row-n">' . h(number_format((int)$n) . $suffix) . '</span></li>';
    }
    return $html . '</ul>';
}

/** One tab of the dashboard: the graph's data and the lists under it. */
function ns_period(string $site, string $tab, array $goals): array
{
    [$from, $to, $cFrom, $cTo] = ns_periods()[$tab];
    {
        $days = ns_days($from, $to);
        $before = ns_days($cFrom, $cTo);
        $t = ns_totals($days, $site);
        $tb = ns_totals($before, $site);
        [$change, $dir] = ns_change($t['visitors'], $tb['visitors']);
        $data[$tab] = [
            'points' => ns_series($days, $site, $tab),
            'compare' => ns_series($before, $site, $tab),
            'labels' => ns_labels($tab, $from),
            'visitors' => $t['visitors'],
            'label' => nm_t(['day' => '今日来てくれた人', 'month' => 'この30日に来てくれた人', 'year' => 'この1年に来てくれた人'][$tab]),
            'vs' => $change !== '' ? nm_t(['day' => '昨日より {p}', 'month' => 'その前の30日より {p}', 'year' => '去年より {p}'][$tab], ['p' => $change]) : '',
            'dir' => $dir,
            'pages' => $t['pages'],
            'rate' => ns_pct($t['done'], $t['reads']),
            'likes' => $t['likes'],
            'compareLabel' => nm_t(['day' => '昨日', 'month' => 'その前の30日', 'year' => '去年'][$tab]),
        ];
        return [$data[$tab], '<div class="ns-period" data-ns-period="' . $tab . '"' . ($tab === 'month' ? '' : ' hidden') . '>' . ns_dashboard_lists($site, $days, $t, $goals) . '</div>'];
    }
}

/** The 1-year tab reads two years of days: it is fetched only when chosen (admin/scope.js). */
function ns_period_json(): never
{
    $site = nm_str($_GET, 'site', 1) === 'l' ? 'l' : 'm';
    $tab = nm_str($_GET, 'tab', 6);
    if (!isset(NS_PERIOD_LABELS[$tab])) nm_not_found();
    [$data, $html] = ns_period($site, $tab, ns_goals());
    nm_json(['data' => $data, 'html' => $html]);
}

function ns_dashboard(): string
{
    $site = nm_str($_GET, 'site', 1) === 'l' ? 'l' : 'm';
    $goals = ns_goals();
    $data = [];
    $sections = '';
    foreach (['day', 'month'] as $tab) {
        [$data[$tab], $html] = ns_period($site, $tab, $goals);
        $sections .= $html;
    }
    $m = $data['month'];
    $siteTabs = '<nav class="ns-site" aria-label="' . nm_t('漫画とログの切り替え') . '">'
        . '<a href="' . h(ns_admin_url(['site' => 'm'])) . '"' . ($site === 'm' ? ' aria-current="page"' : '') . '>' . nm_t('漫画') . '</a>'
        . '<a href="' . h(ns_admin_url(['site' => 'l'])) . '"' . ($site === 'l' ? ' aria-current="page"' : '') . '>' . nm_t('ログ') . '</a></nav>';
    $periodTabs = '<div class="ns-periods" role="tablist" aria-label="' . nm_t('期間') . '">';
    foreach (NS_PERIOD_LABELS as $tab => $label) $periodTabs .= '<button type="button" role="tab" data-ns-tab="' . $tab . '" aria-selected="' . ($tab === 'month' ? 'true' : 'false') . '">' . nm_t($label) . '</button>';
    $periodTabs .= '</div>';
    $pagesLabel = $site === 'm' ? nm_t('読まれたページ数') : nm_t('見られたページ数');
    $rateLabel = $site === 'm' ? nm_t('最後まで読んだ人') : nm_t('きちんと読んだ人');
    return '<script type="application/json" id="ns-data" data-site="' . $site . '">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
        . '<section class="card ns-hero">' . $siteTabs . $periodTabs
        . '<p class="ns-big"><span class="ns-big-n" data-ns-big>' . number_format($m['visitors']) . '</span><span class="ns-big-unit">' . nm_t('人') . '</span>'
        . '<span class="ns-big-label" data-ns-label>' . h($m['label']) . '</span><span class="ns-vs" data-ns-vs data-dir="' . h($m['dir']) . '">' . h($m['vs']) . '</span></p>'
        . '<div class="ns-chart" data-ns-chart role="img" aria-label="' . nm_t('来てくれた人の数のグラフ') . '"></div>'
        . '<p class="ns-legend"><span class="ns-key ns-key-now"></span>' . nm_t('今回') . '<span class="ns-key ns-key-before"></span><span data-ns-compare-label>' . h($m['compareLabel']) . '</span></p>'
        . '<div class="ns-stats">'
        . '<div><span class="ns-stat-n" data-ns-stat="pages">' . number_format($m['pages']) . '</span><span class="ns-stat-label">' . $pagesLabel . '</span></div>'
        . '<div><span class="ns-stat-n" data-ns-stat="rate">' . h($m['rate']) . '</span><span class="ns-stat-label">' . $rateLabel . '</span></div>'
        . '<div><span class="ns-stat-n" data-ns-stat="likes">' . number_format($m['likes']) . '</span><span class="ns-stat-label">' . nm_t('いいね数') . '</span></div>'
        . '</div><p class="note">' . nm_t('「今日」の数字は、この画面を開いた時点までの分です。') . '</p></section>'
        . $sections;
}

/** Rankings, sources, devices, goals and (LOG) the embeds, searches and tags of one period. */
function ns_dashboard_lists(string $site, array $days, array $t, array $goals): string
{
    $rank = [];
    foreach (array_slice($t['items'], 0, 10, true) as $key => $it) {
        $title = ns_item_title($key);
        $rank[] = [$title !== '' ? $title : nm_t('削除したページ'), $it['v'], ns_admin_url(['view' => 'item', 'key' => $key]), ns_item_kind_label($key) . ' · ' . nm_t('{n} 回', ['n' => number_format($it['views'])])];
    }
    $refs = [];
    foreach ($t['refs'] as $k => $n) $refs[] = [nm_t(NS_REF_LABELS[$k] ?? $k), $n, '', ''];
    $domains = [];
    foreach (array_slice($t['domains'], 0, 8, true) as $k => $n) $domains[] = [(string)$k, $n, '', ''];
    $devTotal = array_sum($t['dev']);
    $dev = '';
    if ($devTotal > 0) {
        $dev = '<div class="ns-devices">';
        $x = 0;
        $svg = '<svg class="ns-stack" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">';
        foreach (['mobile', 'pc', 'tablet'] as $i => $k) {
            $w = ($t['dev'][$k] ?? 0) / $devTotal * 100;
            $svg .= '<rect class="ns-dev-' . $k . '" x="' . round($x, 2) . '" width="' . round($w, 2) . '" height="10"/>';
            $x += $w;
        }
        $dev .= $svg . '</svg><ul class="ns-dev-list">';
        foreach (['mobile', 'pc', 'tablet'] as $k) $dev .= '<li><span class="ns-key ns-dev-' . $k . '"></span>' . nm_t(NS_DEVICE_LABELS[$k]) . ' <strong>' . ns_pct($t['dev'][$k] ?? 0, $devTotal) . '</strong></li>';
        $dev .= '</ul></div>';
    } else {
        $dev = '<p class="note">' . nm_t('まだデータがありません。') . '</p>';
    }
    $html = '<div class="ns-grid">'
        . '<section class="card"><h2>' . nm_t($site === 'm' ? '作品のランキング' : '記事・ページのランキング') . '</h2>' . ns_rows($rank, nm_t('まだ読まれていません。')) . '<p class="note">' . nm_t('数字は来てくれた人の数です。押すと詳しい画面へ移ります。') . '</p></section>'
        . '<section class="card"><h2>' . nm_t('どこから来たか') . '</h2>' . ns_rows($refs, nm_t('まだデータがありません。'))
        . ($domains ? '<h3>' . nm_t('来る前のサイト') . '</h3>' . ns_rows($domains, '') : '') . '</section>'
        . '<section class="card"><h2>' . nm_t('スマホと PC') . '</h2>' . $dev . '</section>';
    // Goals: everyone who came that period, both parts together.
    $all = array_sum(array_map(static fn($d) => (int)($d['all']['visitors'] ?? 0), $days));
    $gt = ns_goal_totals($days);
    $grows = '';
    foreach ($goals as $gid => $g) {
        $n = (int)($gt[$gid]['n'] ?? 0);
        $grows .= '<li><span class="ns-row-label">' . h($g['name']) . '</span>' . ns_bar($n, max(1, $all)) . '<span class="ns-row-n">' . nm_t('{n} 人', ['n' => number_format($n)]) . ' · ' . ns_pct($n, $all) . '</span></li>';
    }
    $html .= '<section class="card"><h2>' . nm_t('ゴールの達成') . '</h2>' . ($grows !== '' ? '<ul class="ns-rows">' . $grows . '</ul>' : '<p class="note">' . nm_t('ゴールはまだありません。') . '</p>')
        . '<p><a href="' . h(ns_admin_url(['view' => 'goals'])) . '">' . nm_t('ゴールを見る・作る') . '</a></p></section>';
    if ($site === 'l') {
        $html .= ns_frames_card(ns_frames($days));
        $search = [];
        foreach (array_slice(ns_merge($days, 'search'), 0, 15, true) as $k => $n) $search[] = [(string)$k, $n, '', ''];
        $tags = [];
        foreach (array_slice(ns_merge($days, 'tags'), 0, 15, true) as $k => $n) $tags[] = ['#' . $k, $n, '', ''];
        $links = [];
        foreach (array_slice(ns_merge($days, 'links'), 0, 10, true) as $k => $n) $links[] = [(string)$k, $n, '', ''];
        $html .= '<section class="card"><h2>' . nm_t('サイト内で検索された言葉') . '</h2>' . ns_rows($search, nm_t('まだ検索されていません。')) . '</section>'
            . '<section class="card"><h2>' . nm_t('タグの人気') . '</h2>' . ns_rows($tags, nm_t('タグのページはまだ開かれていません。')) . '<p class="note">' . nm_t('タグのページが開かれた回数です。') . '</p></section>'
            . '<section class="card"><h2>' . nm_t('押されたリンク') . '</h2>' . ns_rows($links, nm_t('まだ押されていません。')) . '</section>';
    }
    return $html . '</div>';
}

/** The page embeds (games): seen → opened → loaded → played → the game's own signal, and the time played. */
function ns_frames_card(array $frames): string
{
    $html = '<section class="card ns-frames"><h2>' . nm_t('埋め込みのゲーム（ページ埋込）') . '</h2>';
    if (!$frames) return $html . '<p class="note">' . nm_t('ページ埋込はまだ見られていません。記事に [ページ埋込]URL[/ページ埋込] を書くと、ここに出ます。') . '</p>' . ns_frames_note() . '</section>';
    $steps = ['fv' => '表示された', 'fo' => '読み込みを押した', 'fl' => '読み込みが終わった（参考）', 'fg' => 'ゲームを触った（起動）', 'fs' => 'ゲームから起動の合図'];
    foreach (array_slice($frames, 0, 8, true) as $url => $f) {
        $title = function_exists('nl_card_load') && ($card = nl_card_load($url)) && !empty($card['ok']) && (string)$card['title'] !== '' ? (string)$card['title'] : (string)parse_url($url, PHP_URL_HOST);
        $max = max(1, $f['fv'], $f['fo']);
        $rows = '';
        foreach ($steps as $k => $label) {
            if ($k === 'fs' && !$f['fs']) continue;
            $rows .= '<li><span class="ns-row-label">' . nm_t($label) . '</span>' . ns_bar($f[$k], $max) . '<span class="ns-row-n">' . nm_t('{n} 人', ['n' => number_format($f[$k])]) . '</span></li>';
        }
        // Play time: while the reader's focus was inside the game and the page was on screen.
        $avg = $f['msn'] > 0 ? (int)round($f['ms'] / $f['msn'] / 1000) : 0;
        $state = $f['fg'] + $f['fs'] > 0 ? '<span class="ns-ok">' . nm_t('起動を確認') . '</span>' : ($f['fo'] > 0 ? '<span class="ns-ng">' . nm_t('まだ起動を確認できていません') . '</span>' : '');
        $html .= '<div class="ns-frame"><h3>' . h($title) . ' ' . $state . '</h3><p class="note ns-url">' . h($url) . '</p><ul class="ns-rows">' . $rows . '</ul>'
            . ($avg > 0 ? '<p>' . nm_t('遊んだ時間の平均: {t}', ['t' => ns_duration($avg)]) . '</p>' : '') . '</div>';
    }
    return $html . ns_frames_note() . '</section>';
}
function ns_frames_note(): string
{
    return '<p class="note">' . nm_t('「読み込みが終わった」は、枠の読み込みが終わった人の数です。ゲームのサイトが埋め込みを断ったときや通信の失敗でも数えることがあるため、目安にしてください。') . '</p>'
        . '<p class="note">' . nm_t('「ゲームを触った（起動）」は、読者がゲームの枠の中を押したかタップした（操作がゲームへ移った）人の数です。ゲームの中のボタンやクリックは、ブラウザーの仕組み上取れません。') . '</p>'
        . '<p class="note">' . nm_t('自分で作ったゲームなら、起動したときに次の 1 行を実行すると「ゲームから起動の合図」も数えられ、確実に起動したかどうかが分かります。') . '</p>'
        . '<p><input class="copy-src wide" readonly value="if (window.parent !== window) window.parent.postMessage(&#039;nagiscope:start&#039;, &#039;*&#039;);"> <button type="button" class="btn small js-copy">' . nm_t('コピー') . '</button></p>';
}

function ns_duration(int $sec): string
{
    if ($sec < 60) return nm_t('{s} 秒', ['s' => $sec]);
    if ($sec < 3600) return nm_t('{m} 分 {s} 秒', ['m' => intdiv($sec, 60), 's' => $sec % 60]);
    return nm_t('{h} 時間 {m} 分', ['h' => intdiv($sec, 3600), 'm' => intdiv($sec % 3600, 60)]);
}

/** A small line of daily visitors (server side, not animated). */
function ns_sparkline(array $points): string
{
    $n = count($points);
    $max = max(1, ...$points);
    $coords = [];
    foreach ($points as $i => $v) $coords[] = round($i / max(1, $n - 1) * 300, 1) . ',' . round(58 - $v / $max * 54, 1);
    return '<svg class="ns-spark" viewBox="0 0 300 60" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $coords) . '"/></svg>';
}

// ---------------------------------------------------------------------------
// One work, post or page
// ---------------------------------------------------------------------------

function ns_item_view(string $key): string
{
    if (!preg_match('/\A[mpg]:[A-Za-z0-9_-]{1,30}\z/', $key)) nm_not_found();
    $range = nm_str($_GET, 'range', 6) === 'year' ? 'year' : 'month';
    [$from, $to] = ns_periods()[$range];
    $site = $key[0] === 'm' ? 'm' : 'l';
    $days = ns_days($from, $to);
    $t = ns_totals($days, $site);
    $it = $t['items'][$key] ?? ['v' => 0, 'views' => 0, 'pages' => 0, 'ends' => 0, 'skim' => 0, 'ms' => 0, 'msn' => 0, 'exit' => [], 'deep' => 0, 'leaves' => 0, 'likes' => 0, 'likers' => 0, 'n' => 0];
    $title = ns_item_title($key);
    $daily = array_values(array_map(static fn($d) => (int)($d[$site]['items'][$key]['v'] ?? 0), $days));
    $rangeTabs = '<nav class="ns-site">';
    foreach (['month' => 'この30日', 'year' => 'この1年'] as $r => $label) $rangeTabs .= '<a href="' . h(ns_admin_url(['view' => 'item', 'key' => $key, 'range' => $r])) . '"' . ($r === $range ? ' aria-current="page"' : '') . '>' . nm_t($label) . '</a>';
    $rangeTabs .= '</nav>';
    $stat = static fn(string $n, string $label) => '<div><span class="ns-stat-n">' . h($n) . '</span><span class="ns-stat-label">' . h($label) . '</span></div>';
    $html = '<p><a href="' . h(ns_admin_url(['site' => $site])) . '">← ' . nm_t('ダッシュボードへ戻る') . '</a></p>'
        . '<section class="card"><p class="ns-kind">' . h(ns_item_kind_label($key)) . '</p><h2>' . h($title !== '' ? $title : nm_t('削除したページ')) . '</h2>' . $rangeTabs . ns_sparkline($daily);
    if ($site === 'm') {
        $avg = $it['msn'] > 0 ? (int)round($it['ms'] / $it['msn'] / 1000) : 0;
        $html .= '<div class="ns-stats">' . $stat(number_format($it['v']), nm_t('来てくれた人')) . $stat(number_format($it['ends']), nm_t('最後まで読んだ人')) . $stat(ns_pct($it['ends'], $it['v']), nm_t('読了率'))
            . $stat(number_format($it['pages']), nm_t('読まれたページ数')) . $stat($avg > 0 ? ns_duration($avg) : '—', nm_t('読んだ時間の平均')) . $stat(number_format($it['skim']), nm_t('流し読み')) . '</div>'
            . '<p class="note">' . nm_t('流し読みは、1 ページ 1 秒未満でめくり続けて最後まで行った人です。最後まで読んだ人には入れていません。読んだ時間は、開いてから最後にめくるまでの時間です。') . '</p></section>'
            . ns_manga_exits($key, $it);
    } else {
        $html .= '<div class="ns-stats">' . $stat(number_format($it['v']), nm_t('来てくれた人')) . $stat(number_format($it['views']), nm_t('閲覧数')) . $stat(number_format($it['deep']), nm_t('きちんと読んだ人'))
            . $stat(ns_pct($it['deep'], $it['v']), nm_t('きちんと読んだ割合')) . $stat(number_format($it['likes']), nm_t('いいね数')) . $stat(ns_pct($it['likers'], $it['v']), nm_t('いいね率')) . '</div>'
            . '<p class="note">' . nm_t('きちんと読んだ人は、本文の 90% までスクロールし、文字数から見て妥当な時間（1 分に 500 字が目安）ページを開いていた人です。いいね率は、来てくれた人のうち、いいねした人の割合です。') . '</p></section>'
            . ns_log_exits($it);
        $frames = ns_frames($days, $key);
        if ($frames) $html .= ns_frames_card($frames);
    }
    return $html;
}

/** Where readers of a work stopped: the share of readings that ended on each page, with its thumbnail. */
function ns_manga_exits(string $key, array $it): string
{
    $id = substr($key, 2);
    $work = nm_load_work($id);
    $total = array_sum($it['exit']);
    $html = '<section class="card"><h2>' . nm_t('何ページ目でやめたか') . '</h2>';
    if (!$work || $total === 0) return $html . '<p class="note">' . nm_t('まだデータがありません。') . '</p></section>';
    $max = max($it['exit']);
    $html .= '<p class="note">' . nm_t('そのページを最後に読むのをやめた人の割合です。最後のページは、最後まで読んだ人です。') . '</p><ol class="ns-pages">';
    foreach ($work['pages'] as $i => $p) {
        $n = (int)($it['exit'][(string)$i] ?? 0);
        $html .= '<li' . ($n === $max ? ' class="is-top"' : '') . '><img src="index.php?p=img&amp;id=' . h($id) . '&amp;f=' . h(rawurlencode($p['f'])) . '" alt="" loading="lazy" width="40" height="56">'
            . '<span class="ns-page-no">' . nm_t('{n} ページ', ['n' => $i + 1]) . '</span>' . ns_bar($n, $max) . '<span class="ns-row-n">' . ns_pct($n, $total) . '</span></li>';
    }
    return $html . '</ol></section>';
}

/** Where readers of a post or page stopped, by heading, and the heading by which half had left. */
function ns_log_exits(array $it): string
{
    $html = '<section class="card"><h2>' . nm_t('どの見出しの辺りでやめたか') . '</h2>';
    $total = array_sum($it['exit']);
    if ($total === 0) return $html . '<p class="note">' . nm_t('まだデータがありません。') . '</p></section>';
    $rows = [];
    foreach ($it['exit'] as $k => $n) {
        [$idx, $text] = array_pad(explode('|', (string)$k, 2), 2, '');
        $rows[] = [(int)$idx, $text, (int)$n];
    }
    usort($rows, static fn($a, $b) => $a[0] <=> $b[0]);
    $sum = 0; $half = null;
    foreach ($rows as [$idx, $text, $n]) {
        $sum += $n;
        if ($half === null && $sum * 2 >= $total) $half = $idx < 0 ? nm_t('最初の見出しより前') : $text;
    }
    if ($half !== null) $html .= '<p class="ns-half">' . h(nm_t('『{h}』の辺りで半分が離脱しています', ['h' => $half])) . '</p>';
    $list = [];
    foreach ($rows as [$idx, $text, $n]) $list[] = [$idx < 0 ? nm_t('最初の見出しより前') : $text, $n, '', ns_pct($n, $total)];
    return $html . ns_rows($list, '') . '<p class="note">' . nm_t('ページを閉じたとき、画面の真ん中より上に来ていた最後の見出しで数えています。最後の見出しには、最後まで読んだ人も入ります。') . '</p></section>';
}

// ---------------------------------------------------------------------------
// Goals
// ---------------------------------------------------------------------------

/** Works, posts, pages and embeds a goal can point at. */
function ns_goal_targets(): array
{
    $items = [];
    foreach (nm_list_works() as $w) $items['m:' . $w['id']] = nm_t('漫画') . '：' . (string)$w['title'];
    foreach (array_slice(nl_post_summaries(), 0, 300) as $p) {
        $post = nl_load_post($p['id']);
        if ($post) $items['p:' . $p['id']] = nm_t('記事') . '：' . nl_post_title($post, 60);
    }
    foreach (nl_pages(true) as $p) $items['g:' . $p['id']] = nm_t('固定ページ') . '：' . (string)$p['title'];
    $frames = [];
    $bodies = array_merge(array_map(static fn($p) => (string)(nl_load_post($p['id'])['body'] ?? ''), array_slice(nl_post_summaries(), 0, 300)), array_map(static fn($p) => (string)$p['body'], nl_pages(true)));
    foreach ($bodies as $body) foreach (explode("\n", $body) as $line) if ($f = nl_frame_parse(trim($line))) $frames[$f['url']] = $f['host'] . ' — ' . mb_strimwidth($f['url'], 0, 80, '…');
    return [$items, $frames];
}

function ns_goals_view(): string
{
    $range = nm_str($_GET, 'range', 6);
    if (!isset(NS_PERIOD_LABELS[$range])) $range = 'month';
    [$from, $to] = ns_periods()[$range];
    $days = ns_days($from, $to);
    $all = array_sum(array_map(static fn($d) => (int)($d['all']['visitors'] ?? 0), $days));
    $gt = ns_goal_totals($days);
    $goals = ns_goals();
    [$items, $frames] = ns_goal_targets();
    $edit = nm_str($_GET, 'edit', 16);
    $tabs = '<nav class="ns-site">';
    foreach (NS_PERIOD_LABELS as $r => $label) $tabs .= '<a href="' . h(ns_admin_url(['view' => 'goals', 'range' => $r])) . '"' . ($r === $range ? ' aria-current="page"' : '') . '>' . nm_t($label) . '</a>';
    $tabs .= '</nav>';
    $html = '<section class="card"><h2>' . nm_t('ゴールの結果') . '</h2>' . $tabs;
    if (!$goals) $html .= '<p class="note">' . nm_t('ゴールはまだありません。下のフォームから作れます。') . '</p>';
    foreach ($goals as $gid => $g) {
        $n = (int)($gt[$gid]['n'] ?? 0);
        $src = [];
        foreach (array_slice($gt[$gid]['src'] ?? [], 0, 8, true) as $k => $c) $src[] = [ns_source_label((string)$k), $c, '', ''];
        $html .= '<div class="ns-goal"><h3>' . h($g['name']) . ' <small>' . h(nm_t(NS_GOAL_KINDS[$g['kind']] ?? '')) . '</small></h3>'
            . '<p class="ns-goal-result">' . nm_t('達成 {n} 人 ／ 来た人 {all} 人 → {p}', ['n' => '<strong>' . number_format($n) . '</strong>', 'all' => number_format($all), 'p' => '<strong>' . ns_pct($n, $all) . '</strong>']) . '</p>'
            . ($src ? '<h4>' . nm_t('達成した人がどこから来たか') . '</h4>' . ns_rows($src, '') : '')
            . '<div class="ns-goal-actions"><a class="btn small" href="' . h(ns_admin_url(['view' => 'goals', 'edit' => $gid])) . '#ns-goal-form">' . nm_t('編集') . '</a>'
            . '<form method="post" action="index.php" data-ns-confirm-text="' . h(nm_t('このゴールを削除しますか？')) . '">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_goal_delete"><input type="hidden" name="goal" value="' . h($gid) . '"><button class="btn small danger">' . nm_t('削除') . '</button></form></div></div>';
    }
    $html .= '<p class="note">' . nm_t('1 人が 1 日に何度達成しても 1 人と数えます。「来た人」は、漫画とログを合わせた、その期間に来てくれた人の数です。') . '</p></section>';
    $g = $goals[$edit] ?? ['name' => '', 'kind' => 'page', 'target' => '', 'how' => 'contains', 'match' => ''];
    $options = static function (array $list, string $current, string $none = '') {
        $html = $none !== '' ? '<option value="">' . h($none) . '</option>' : '';
        foreach ($list as $k => $label) $html .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
        return $html;
    };
    $kinds = '';
    foreach (NS_GOAL_KINDS as $k => $label) $kinds .= '<option value="' . $k . '"' . ($g['kind'] === $k ? ' selected' : '') . '>' . nm_t($label) . '</option>';
    $html .= '<section class="card" id="ns-goal-form"><h2>' . nm_t(isset($goals[$edit]) ? 'ゴールを編集' : 'ゴールを作る') . '</h2>'
        . '<form method="post" action="index.php" class="form" data-ns-goal-form>' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_goal_save"><input type="hidden" name="goal" value="' . h(isset($goals[$edit]) ? $edit : '') . '">'
        . '<label>' . nm_t('ゴールの名前') . '<input name="name" required maxlength="80" value="' . h($g['name']) . '" placeholder="' . nm_t('例: ゲームのページへ行った') . '"></label>'
        . '<label>' . nm_t('何が起きたら達成？') . '<select name="kind" data-ns-kind>' . $kinds . '</select></label>'
        . '<label data-ns-for="page end">' . nm_t('ページ・作品') . '<select name="target_item">' . $options($items, $g['kind'] === 'page' || $g['kind'] === 'end' ? $g['target'] : '') . '</select></label>'
        . '<label data-ns-for="like">' . nm_t('記事（選ばなければどれでも）') . '<select name="target_like">' . $options(array_filter($items, static fn($k) => str_starts_with((string)$k, 'p:'), ARRAY_FILTER_USE_KEY), $g['kind'] === 'like' ? $g['target'] : '', nm_t('どの記事でも')) . '</select></label>'
        . '<label data-ns-for="game">' . nm_t('ページ埋込（選ばなければどれでも）') . '<select name="target_game">' . $options($frames, $g['kind'] === 'game' ? $g['target'] : '', nm_t('どのページ埋込でも')) . '</select></label>'
        . '<div data-ns-for="click" class="ns-click"><label>' . nm_t('リンクの URL が') . '<select name="how"><option value="contains"' . ($g['how'] === 'contains' ? ' selected' : '') . '>' . nm_t('次の文字を含む') . '</option><option value="prefix"' . ($g['how'] === 'prefix' ? ' selected' : '') . '>' . nm_t('次の文字で始まる') . '</option></select></label>'
        . '<label>' . nm_t('文字') . '<input name="match" maxlength="300" value="' . h($g['match']) . '" placeholder="itch.io/"></label></div>'
        . '<button class="btn primary">' . nm_t('保存') . '</button>' . (isset($goals[$edit]) ? ' <a class="btn" href="' . h(ns_admin_url(['view' => 'goals'])) . '">' . nm_t('やめる') . '</a>' : '') . '</form>'
        . '<p class="note">' . nm_t('作ったゴールは、残っている記録から過去にさかのぼって数えます（リンクのクリックは、NagiScope が記録を始めた後の分だけです）。') . '</p>'
        . '<p class="note">' . nm_t('埋め込み（iframe）の中のクリックは、ブラウザーの仕組み上取れません。ページ埋込のゲームは「埋め込みのゲームを始めた」で、ゲームの枠の中を押した人を数えられます。') . '</p></section>';
    return $html;
}

// ---------------------------------------------------------------------------
// Access list by IP address
// ---------------------------------------------------------------------------

function ns_event_label(array $r): string
{
    $title = ($key = ns_key($r)) !== '' ? ns_item_title($key) : '';
    $what = $title !== '' ? '「' . $title . '」' : '';
    return match ($r['e']) {
        'pv' => $r['k'] === 'l' ? (isset($r['q']) ? nm_t('検索: {q}', ['q' => $r['q']]) : (isset($r['tg']) ? nm_t('タグ: #{t}', ['t' => $r['tg']]) : nm_t('一覧を見た'))) : nm_t('{what}を見た', ['what' => $what]),
        'mo' => nm_t('{what}を開いた', ['what' => $what]),
        'mp' => nm_t('{what}を {n} ページ目まで読んだ', ['what' => $what, 'n' => (int)($r['mx'] ?? 0) + 1]),
        'me' => nm_t('{what}を読み終えた', ['what' => $what]),
        'lv' => nm_t('{what}を {y}% まで読んだ', ['what' => $what, 'y' => (int)($r['y'] ?? 0)]),
        'rd' => nm_t('{what}をきちんと読んだ', ['what' => $what]),
        'lk' => nm_t('{what}にいいねした', ['what' => $what]),
        'ck' => nm_t('リンクを押した: {u}', ['u' => (string)($r['u'] ?? '')]),
        'fo' => nm_t('ページ埋込を開いた: {u}', ['u' => (string)($r['u'] ?? '')]),
        'fg' => nm_t('埋め込みのゲームを触った: {u}', ['u' => (string)($r['u'] ?? '')]),
        'fs' => nm_t('ゲームから起動の合図: {u}', ['u' => (string)($r['u'] ?? '')]),
        default => '',
    };
}

function ns_access_view(): string
{
    $s = ns_settings();
    $ip = trim(nm_str($_GET, 'ip', 60));
    if ($ip !== '' && !preg_match('/\A[0-9a-fA-F.:x]{1,60}\z/', $ip)) $ip = '';
    $lines = array_values(array_filter(ns_raw_search($ip, 300), static fn($r) => !in_array($r['e'], ['fv', 'fl', 'ft'], true)));
    $html = '<section class="card"><h2>' . nm_t('アクセスの一覧') . '</h2>'
        . ($s['ip'] === 'none' ? '<p class="warn">' . nm_t('いまは IP アドレスを保存しない設定です。保存しない設定のあいだの記録には、IP アドレスがありません。') . '</p>' : '')
        . '<form method="get" action="index.php" class="form row"><input type="hidden" name="p" value="scope"><input type="hidden" name="view" value="access">'
        . '<label>' . nm_t('IP アドレスで絞り込む（前方一致）') . '<input name="ip" value="' . h($ip) . '" placeholder="203.0.113." inputmode="decimal" autocomplete="off"></label><button class="btn">' . nm_t('絞り込む') . '</button>'
        . ($ip !== '' ? ' <a class="btn" href="' . h(ns_admin_url(['view' => 'access'])) . '">' . nm_t('すべて表示') . '</a>' : '') . '</form>'
        . '<p class="note">' . nm_t('IP アドレスだけでは、相手の名前や住所は分かりません。相手を特定するには、プロバイダーへの発信者情報開示の手続きが必要です。ここに残る記録は、その手続きの証拠として使えます。') . '</p>';
    if ($ip !== '' && ($rule = ns_deny_rule($ip)) !== '') {
        $denied = in_array($rule, ns_denied_list(), true);
        $html .= '<form method="post" action="index.php" class="ns-deny" data-ns-confirm-text="' . h(nm_t('{ip} を拒否リストに入れますか？このアドレスからは、サイトが開けなくなります。', ['ip' => $rule])) . '">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_deny"><input type="hidden" name="ip" value="' . h($ip) . '">'
            . ($denied ? '<p>' . nm_t('{ip} は拒否リストに入っています。', ['ip' => h($rule)]) . '</p>' : '<button class="btn danger">' . nm_t('{ip} を拒否リストに入れる', ['ip' => h($rule)]) . '</button>') . '</form>';
    }
    if (!$lines) return $html . '<p class="note">' . nm_t('記録がありません。') . '</p></section>';
    $html .= '<div class="ns-table-wrap"><table class="ns-table"><thead><tr><th>' . nm_t('時刻') . '</th><th>' . nm_t('IP アドレス') . '</th><th>' . nm_t('出来事') . '</th><th>' . nm_t('端末・来る前のページ') . '</th></tr></thead><tbody>';
    foreach ($lines as $r) {
        $addr = (string)($r['ip'] ?? '');
        $html .= '<tr><td>' . h(gmdate('Y-m-d H:i:s', (int)$r['t'] + 32400)) . '</td><td>' . ($addr !== '' ? '<a href="' . h(ns_admin_url(['view' => 'access', 'ip' => $addr])) . '">' . h($addr) . '</a>' : '—') . '</td>'
            . '<td>' . h(ns_event_label($r)) . '</td><td>' . h(trim(nm_t(NS_DEVICE_LABELS[$r['d'] ?? ''] ?? '') . ' ' . ($r['b'] ?? ''))) . (isset($r['r']) ? '<br><small class="ns-url">' . h((string)$r['r']) . '</small>' : '') . '</td></tr>';
    }
    return $html . '</tbody></table></div><p class="note">' . nm_t('新しい順に最大 300 件です。時刻は日本時間です。') . '</p></section>';
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

function ns_settings_view(): string
{
    $s = ns_settings();
    [$raw, $daily] = ns_usage();
    $months = '';
    foreach (NS_RAW_MONTHS as $m) $months .= '<option value="' . $m . '"' . ($s['raw_months'] === $m ? ' selected' : '') . '>' . ($m === 0 ? nm_t('消さない（無期限）') : nm_t('{n} ヶ月で消す', ['n' => $m])) . '</option>';
    return '<section class="card"><h2>' . nm_t('NagiScope の設定') . '</h2>'
        . '<form method="post" action="index.php" class="form" data-ns-ip-form>' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_settings">'
        . '<label class="check ns-switch"><input type="checkbox" name="on" value="1"' . ($s['on'] ? ' checked' : '') . '>' . nm_t('NagiScope を使う（ON）') . '</label>'
        . '<p class="note">' . nm_t('OFF のあいだは、サーバーで数えることも、閲覧者のブラウザーから送ることもしません。OFF にしても、それまでのデータは消えません。') . '</p>'
        . ns_ip_choices($s['ip'])
        . '<p class="note">' . nm_t('IP アドレスを保存する設定では、GDPR（EU の個人データの規則）では個人データの扱いになります。「同意バナーがいらない」と言えるのは「保存しない」のときだけです。どの設定でも Cookie は使いません。') . '</p>'
        . '<label>' . nm_t('生のログ（1 件ずつの記録）') . '<select name="raw_months">' . $months . '</select></label>'
        . '<p class="note">' . nm_t('日ごとの集計は消しません。生のログを消すと、その期間は IP での絞り込みと、新しいゴールのさかのぼりができなくなります。') . '</p>'
        . '<label>' . nm_t('拒否リスト（1 行に 1 つ。203.0.113.0/24 のような範囲も可）') . '<textarea name="denied_ips" rows="4" spellcheck="false">' . h(implode("\n", ns_denied_list())) . '</textarea></label>'
        . '<p class="note">' . nm_t('ここにあるアドレスからは、漫画・LOG・いいねが「Not Found」になり、NagiScope にも数えません。LiteSpeed Cache がすでに保存したページは、届くことがあります。') . '</p>'
        . '<button class="btn primary">' . nm_t('保存') . '</button></form>' . ns_policy_dialog() . '</section>'
        . '<section class="card"><h2>' . nm_t('使っている容量') . '</h2><p>' . nm_t('生のログ: {raw}　日ごとの集計: {daily}', ['raw' => ns_bytes($raw), 'daily' => ns_bytes($daily)]) . '</p>'
        . '<p class="note">' . nm_t('目安: 月に 1 万回の記録で、生のログはおよそ 3 MB です。') . '</p></section>'
        . '<section class="card"><h2>' . nm_t('プライバシーポリシーの文例') . '</h2><p class="note">' . nm_t('いまの設定に合わせた文例です。サイトのプライバシーポリシーに載せてください。') . '</p>' . ns_policy_samples($s['ip']) . '</section>'
        . '<section class="card"><h2>' . nm_t('バックアップ') . '</h2>'
        . '<form method="post" action="index.php" class="form">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_backup">'
        . '<label class="check"><input type="checkbox" name="raw" value="1"' . ($s['backup_raw'] ? ' checked' : '') . '>' . nm_t('生のログも含める（IP アドレスを含みます）') . '</label>'
        . '<button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>' . nm_t('NagiScope のデータをダウンロード') . '</button></form>'
        . '<h3>' . nm_t('復元') . '</h3><form method="post" action="index.php" enctype="multipart/form-data" class="form">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_restore">'
        . '<label>' . nm_t('NagiScope のバックアップ ZIP') . '<input type="file" name="backup" accept=".zip,application/zip" required></label>'
        . '<label class="check"><input type="checkbox" name="overwrite" value="1">' . nm_t('同じ日の集計があれば上書きする') . '</label>'
        . '<button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>' . nm_t('復元する') . '</button></form></section>'
        . '<section class="card ns-danger"><h2>' . nm_t('データを消す') . '</h2><p>' . nm_t('生のログと日ごとの集計をすべて消します。ゴールと設定は残ります。元に戻せません。') . '</p>'
        . '<form method="post" action="index.php" class="form" data-ns-confirm-text="' . h(nm_t('NagiScope のデータをすべて消しますか？元に戻せません。')) . '">' . nm_csrf_field() . '<input type="hidden" name="do" value="scope_delete">'
        . '<label class="check"><input type="checkbox" name="sure" value="1" required>' . nm_t('消すことを確認しました') . '</label><button class="btn danger">' . nm_t('すべて消す') . '</button></form></section>';
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

function ns_handle_post(string $do): never
{
    $back = static function (string $msg, array $q = [], string $type = 'ok'): never {
        nm_flash($type, $msg);
        header('Location: ' . ns_admin_url($q), true, 303);
        exit;
    };
    $ip = in_array($_POST['ip'] ?? '', NS_IP_MODES, true) ? $_POST['ip'] : null;
    switch ($do) {
        case 'scope_later':
            ns_save_settings(['asked' => true]);
            nm_flash('ok', nm_t('NagiScope は、管理メニューの「NagiScope」からいつでも始められます。'));
            header('Location: index.php', true, 303);
            exit;
        case 'scope_enable':
            if ($ip === null) $back(nm_t('IP アドレスの扱いを選んでください'), [], 'error');
            ns_save_settings(['on' => true, 'ip' => $ip, 'asked' => true, 'since' => ns_settings()['since'] ?: time()]);
            nm_log('scope_on', $ip);
            $back(nm_t('NagiScope を ON にしました。これから来てくれた人を数えます。'));
        case 'scope_settings':
            $cfg = nm_config() ?? [];
            $rules = [];
            foreach (preg_split('/\R/', nm_str($_POST, 'denied_ips', 20000)) ?: [] as $line) {
                if (trim($line) === '') continue;
                $rule = ns_deny_rule($line);
                if ($rule === '') $back(nm_t('拒否リストに読めない行があります: {line}', ['line' => mb_substr(trim($line), 0, 60)]), ['view' => 'settings'], 'error');
                $rules[] = $rule;
            }
            $cfg['denied_ips'] = array_values(array_unique($rules));
            $on = !empty($_POST['on']);
            $old = ns_settings($cfg);
            $cfg['scope'] = array_merge($old, ['on' => $on, 'ip' => $ip ?? $old['ip'], 'raw_months' => in_array((int)($_POST['raw_months'] ?? 0), NS_RAW_MONTHS, true) ? (int)$_POST['raw_months'] : 0,
                'asked' => true, 'since' => $old['since'] ?: ($on ? time() : 0)]);
            nm_save_config($cfg);
            nm_log('scope_settings', ($on ? 'on' : 'off') . ' ' . $cfg['scope']['ip']);
            $back(nm_t('NagiScope の設定を保存しました'), ['view' => 'settings']);
        case 'scope_deny':
            $rule = ns_deny_rule(nm_str($_POST, 'ip', 60));
            if ($rule === '') $back(nm_t('IP アドレスが正しくありません'), ['view' => 'access'], 'error');
            $cfg = nm_config() ?? [];
            $cfg['denied_ips'] = array_values(array_unique([...ns_denied_list($cfg), $rule]));
            nm_save_config($cfg);
            nm_log('scope_deny', $rule);
            $back(nm_t('{ip} を拒否リストに入れました', ['ip' => $rule]), ['view' => 'access', 'ip' => nm_str($_POST, 'ip', 60)]);
        case 'scope_goal_save':
            $kind = nm_str($_POST, 'kind', 10);
            if (!isset(NS_GOAL_KINDS[$kind])) $back(nm_t('ゴールの種類を選んでください'), ['view' => 'goals'], 'error');
            $name = trim(nm_str($_POST, 'name', 400));
            if ($name === '' || mb_strlen($name) > 80) $back(nm_t('ゴールの名前を 80 文字以内で入れてください'), ['view' => 'goals'], 'error');
            [$items, $frames] = ns_goal_targets();
            $goal = ['name' => $name, 'kind' => $kind, 'target' => '', 'how' => 'contains', 'match' => ''];
            if ($kind === 'page' || $kind === 'end') {
                $goal['target'] = nm_str($_POST, 'target_item', 40);
                if (!isset($items[$goal['target']])) $back(nm_t('ページ・作品を選んでください'), ['view' => 'goals'], 'error');
            } elseif ($kind === 'like') {
                $goal['target'] = nm_str($_POST, 'target_like', 40);
                if ($goal['target'] !== '' && !isset($items[$goal['target']])) $back(nm_t('記事を選び直してください'), ['view' => 'goals'], 'error');
            } elseif ($kind === 'game') {
                $goal['target'] = nm_str($_POST, 'target_game', 2000);
                if ($goal['target'] !== '' && !isset($frames[$goal['target']])) $back(nm_t('ページ埋込を選び直してください'), ['view' => 'goals'], 'error');
            } else {
                $goal['how'] = nm_str($_POST, 'how', 10) === 'prefix' ? 'prefix' : 'contains';
                $goal['match'] = trim(nm_str($_POST, 'match', 300));
                if ($goal['match'] === '') $back(nm_t('リンクの URL に含まれる文字を入れてください'), ['view' => 'goals'], 'error');
            }
            $gid = nm_str($_POST, 'goal', 16);
            nm_with_lock('scope-goals', static function () use ($gid, $goal): void {
                $goals = ns_goals();
                $id = isset($goals[$gid]) ? $gid : 'g' . nm_random_id(10);
                $goals[$id] = $goal;
                if (count($goals) > 100) throw new RuntimeException('too many goals');
                ns_write_guarded(ns_dir() . '/goals.json.php', ['goals' => $goals]);
            });
            ns_goals_rebuild();
            $back(nm_t('ゴールを保存しました。残っている記録から、過去の分も数えました。'), ['view' => 'goals']);
        case 'scope_goal_delete':
            $gid = nm_str($_POST, 'goal', 16);
            nm_with_lock('scope-goals', static function () use ($gid): void {
                $goals = ns_goals();
                unset($goals[$gid]);
                ns_write_guarded(ns_dir() . '/goals.json.php', ['goals' => $goals]);
            });
            $back(nm_t('ゴールを削除しました'), ['view' => 'goals']);
        case 'scope_backup':
            $raw = !empty($_POST['raw']);
            if ($raw !== ns_settings()['backup_raw']) ns_save_settings(['backup_raw' => $raw]);
            ns_backup_download($raw);
        case 'scope_restore':
            $f = $_FILES['backup'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) $back(nm_t('ファイルを受け取れませんでした'), ['view' => 'settings'], 'error');
            try {
                $n = ns_backup_restore((string)$f['tmp_name'], !empty($_POST['overwrite']));
            } catch (UnexpectedValueException $e) {
                $back($e->getMessage(), ['view' => 'settings'], 'error');
            }
            nm_log('scope_restore', (string)$n);
            $back(nm_t('{n} 個のファイルを復元しました', ['n' => $n]), ['view' => 'settings']);
        case 'scope_delete':
            if (empty($_POST['sure'])) $back(nm_t('「消すことを確認しました」にチェックを入れてください'), ['view' => 'settings'], 'error');
            ns_delete_data();
            nm_log('scope_delete');
            $back(nm_t('NagiScope のデータを消しました'), ['view' => 'settings']);
    }
    nm_not_found();
}
