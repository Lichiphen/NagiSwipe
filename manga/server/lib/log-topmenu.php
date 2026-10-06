<?php
/*
 * Link menus edited like the sidebar: the top menu under the site title (data/log/topmenu.php) and the footer links
 * (data/log/footmenu.php). MIT License (c) 2026 Lichiphen.
 * On phones the top menu is one line that scrolls sideways (viewer/log-topmenu.js shows that there is more).
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_TOPMENU_MAX = 30;
const NL_TOPMENU_LABEL_MAX = 40;
/** Each menu: its file, its name in messages, its settings block and the form fields the settings screen sends. */
const NL_MENUS = [
    'top' => ['file' => 'topmenu', 'name' => 'トップメニュー', 'section' => 'log_topmenu', 'field' => 'topmenu'],
    'foot' => ['file' => 'footmenu', 'name' => 'フッターのリンク', 'section' => 'log_footmenu', 'field' => 'footmenu'],
];

function nl_menu_settings(string $kind): array
{
    return nl_memo('menu:' . $kind, static function () use ($kind): array {
        $s = nl_read_record(nl_root() . '/' . NL_MENUS[$kind]['file'] . '.php') ?? [];
        return ['revision' => (int)($s['revision'] ?? 0), 'updated' => (int)($s['updated'] ?? 0), 'items' => is_array($s['items'] ?? null) ? $s['items'] : []];
    });
}
function nl_topmenu_settings(): array { return nl_menu_settings('top'); }
/** A menu link: what the sidebar accepts, plus mail links. */
function nl_topmenu_url(string $url): string
{
    $url = trim($url);
    return preg_match('/\Amailto:[^\s@<>"]+@[^\s@<>"]+\z/i', $url) ? $url : nl_sidebar_url($url);
}
function nl_topmenu_validate(mixed $items, string $kind = 'top'): array
{
    $name = NL_MENUS[$kind]['name'];
    if (!is_array($items) || !array_is_list($items)) throw new UnexpectedValueException($name . 'の項目を確認してください');
    if (count($items) > NL_TOPMENU_MAX) throw new UnexpectedValueException($name . 'は' . NL_TOPMENU_MAX . '項目までです');
    $out = []; $ids = [];
    foreach ($items as $n => $item) {
        $no = ($n + 1) . '番目の項目';
        if (!is_array($item) || !is_string($item['id'] ?? null) || !preg_match('/\Atm-[a-f0-9]{12}\z/', $item['id']) || isset($ids[$item['id']]) || !is_bool($item['enabled'] ?? null) || !is_string($item['label'] ?? null) || !is_string($item['url'] ?? null)) throw new UnexpectedValueException($name . 'の項目が重複しているか、形式が違います');
        $ids[$item['id']] = true;
        $label = trim($item['label']);
        if ($label === '' || mb_strlen($label) > NL_TOPMENU_LABEL_MAX || !mb_check_encoding($label, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $label)) throw new UnexpectedValueException($name . $no . 'の表示名を' . NL_TOPMENU_LABEL_MAX . '文字以内で入力してください');
        $url = nl_topmenu_url($item['url']);
        if ($url === '') throw new UnexpectedValueException($name . $no . '（' . $label . '）のリンク先を確認してください。https:// で始まるURLか、./?pg=privacy のようなサイト内のURLを使えます');
        $out[] = ['id' => $item['id'], 'label' => $label, 'url' => $url, 'enabled' => $item['enabled']];
    }
    return $out;
}
function nl_menu_save(string $kind, array $items, int $revision): int
{
    $items = nl_topmenu_validate($items, $kind);
    return nm_with_lock('personal-log', static function () use ($kind, $items, $revision) {
        if (nl_menu_settings($kind)['revision'] !== $revision) throw new UnexpectedValueException('別の画面で' . NL_MENUS[$kind]['name'] . 'が更新されています。開き直してください');
        nl_write_record(nl_root() . '/' . NL_MENUS[$kind]['file'] . '.php', ['revision' => $revision + 1, 'updated' => time(), 'items' => $items]);
        return $revision + 1;
    });
}
function nl_topmenu_save(array $items, int $revision): int { return nl_menu_save('top', $items, $revision); }
/** A menu from the settings screen: checked here, saved with nl_menu_save after the other blocks. */
function nl_menu_input(string $kind): array
{
    $field = NL_MENUS[$kind]['field']; $name = NL_MENUS[$kind]['name'];
    $raw = $_POST[$field . '_items'] ?? '';
    if (!is_string($raw) || strlen($raw) > 100000 || !mb_check_encoding($raw, 'UTF-8')) throw new UnexpectedValueException($name . 'の項目を確認してください');
    $items = nl_topmenu_validate(nl_json_list($raw), $kind);
    $revision = nm_str($_POST, $field . '_revision', 20);
    if (!preg_match('/\A[0-9]{1,10}\z/', $revision)) throw new UnexpectedValueException('更新情報を確認して、画面を開き直してください');
    if (nl_menu_settings($kind)['revision'] !== (int)$revision) throw new UnexpectedValueException('別の画面で' . $name . 'が更新されています。開き直してください');
    return [$items, (int)$revision];
}
function nl_topmenu_input(): array { return nl_menu_input('top'); }
/** What the editor can link by name: the top, fixed pages, categories and hashtags. */
function nl_topmenu_candidates(): array
{
    $out = [['t' => 'ホーム（すべての投稿）', 'u' => './', 'k' => 'トップ']];
    foreach (nl_pages() as $p) $out[] = ['t' => $p['title'], 'u' => nl_fixed_url($p), 'k' => $p['status'] === 'published' ? '固定ページ' : '固定ページ・下書き'];
    foreach (nl_taxonomy()['categories'] as $id => $name) $out[] = ['t' => $name, 'u' => './?category=' . rawurlencode((string)$id), 'k' => 'カテゴリ'];
    foreach (array_slice(nl_ordered_hashtags(true), 0, 100) as $tag) $out[] = ['t' => '#' . $tag, 'u' => './?tag=' . rawurlencode($tag), 'k' => 'ハッシュタグ'];
    return $out;
}
function nl_topmenu_edit_row(array $item): string
{
    $label = $item['label'] !== '' ? $item['label'] : '新しい項目';
    return '<div class="log-topmenu-edit-row" data-topmenu-item data-topmenu-id="' . h($item['id']) . '">'
        . '<button class="btn log-icon-btn log-sort-handle" type="button" aria-label="' . h($label) . 'をドラッグして移動">' . nl_sidebar_ui_icon('grip') . '</button>'
        . '<label class="log-topmenu-switch"><input class="log-switch" type="checkbox" role="switch" data-topmenu-enabled' . ($item['enabled'] ? ' checked' : '') . ' aria-label="' . h($label) . 'を表示する"></label>'
        . '<div class="log-topmenu-fields"><div class="log-topmenu-name"><label class="log-topmenu-label">表示名（入力すると候補が出ます）<input data-topmenu-label maxlength="' . NL_TOPMENU_LABEL_MAX . '" value="' . h($item['label']) . '" placeholder="例：プライバシー" autocomplete="off" required role="combobox" aria-autocomplete="list" aria-expanded="false"></label>'
        . '<div class="log-topmenu-suggest" data-topmenu-suggest role="listbox" aria-label="リンク先の候補" hidden></div></div>'
        . '<label class="log-topmenu-url">リンク先<input data-topmenu-url maxlength="2000" value="' . h($item['url']) . '" placeholder="候補から選ぶか、https://… を入力" autocomplete="off" spellcheck="false" required></label></div>'
        . '<span class="log-sidebar-steps"><button class="btn log-icon-btn" type="button" data-topmenu-step="-1" aria-label="左（上）へ移動">' . nl_sidebar_ui_icon('up') . '</button><button class="btn log-icon-btn" type="button" data-topmenu-step="1" aria-label="右（下）へ移動">' . nl_sidebar_ui_icon('down') . '</button>'
        . '<button class="btn log-icon-btn" type="button" data-topmenu-remove aria-label="' . h($label) . 'を外す">' . nl_sidebar_ui_icon('remove') . '</button></span></div>';
}
/** The editor of one menu ($lead: the explanation under its heading; $extra: more fields of its block). */
function nl_menu_editor(string $kind, string $lead, string $extra = ''): string
{
    $m = NL_MENUS[$kind]; $s = nl_menu_settings($kind); $rows = '';
    foreach ($s['items'] as $item) $rows .= nl_topmenu_edit_row($item);
    $candidates = json_encode(nl_topmenu_candidates(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<section class="card log-topmenu-manager" id="log-' . $m['file'] . '-settings"><h2>' . $m['name'] . '</h2><p class="note">' . $lead . '</p>'
        . '<div class="form" data-settings-section="' . $m['section'] . '" data-topmenu-manager data-items-field="' . $m['field'] . '_items" data-revision-field="' . $m['field'] . '_revision" data-revision="' . $s['revision'] . '" data-candidates="' . h((string)$candidates) . '">'
        . $extra
        . '<div class="log-topmenu-list" data-topmenu-items>' . $rows . '</div>'
        . '<p class="log-topmenu-empty note" data-topmenu-empty' . ($rows !== '' ? ' hidden' : '') . '>項目はまだありません。</p>'
        . '<button class="btn" type="button" data-topmenu-add>' . nl_sidebar_ui_icon('add') . '項目を追加</button>'
        . '<p class="note">表示名に固定ページやカテゴリの名前の一部を入れると、候補が出ます。選ぶとリンク先（サイト内のURL）が入ります。外部サイトは https:// から入力します。最大' . NL_TOPMENU_MAX . '項目、表示名は' . NL_TOPMENU_LABEL_MAX . '文字までです。</p>'
        . '<p class="note" data-topmenu-status role="status" aria-live="polite">並べ替えは左の持ち手のドラッグか、矢印のボタンで。画面の下の「設定を保存」で反映します。</p><noscript><p>' . $m['name'] . 'の編集には、ブラウザのJavaScriptを有効にしてください。</p></noscript></div>'
        . '<template data-topmenu-template>' . nl_topmenu_edit_row(['id' => '', 'label' => '', 'url' => '', 'enabled' => true]) . '</template></section>';
}
function nl_topmenu_panel(): string
{
    return nl_menu_editor('top', 'サイト名の下に並ぶリンクです。左から順に表示します。項目がないときは表示しません。スマホでは1行に並べ、入りきらないときは指で左右にスワイプして読めます（続きがあることを矢印と動きで知らせます）。');
}
function nl_footmenu_panel(): string
{
    $s = nl_settings();
    return nl_menu_editor('foot', 'ページの一番下に並ぶリンクです。利用規約やプライバシーポリシーなどを置けます。左から順に表示し、スマホでは折り返して並べます。',
        '<input type="hidden" name="footer_home_form" value="1"><label class="check"><input type="checkbox" name="footer_home" value="1"' . ($s['footer_home'] ? ' checked' : '') . '>先頭に、家のアイコンと「' . h(nl_crumb_home($s)) . '」のトップへのリンクを出す</label><p class="note">文字はパンくずリストの先頭と同じです（「一覧の見せ方・記事の下」で変えられます）。</p>');
}
/** Whether a menu link points at the page being shown ($here: pg / category / tag / id / top). */
function nl_menu_current(string $url, array $here): bool
{
    $base = nl_base_url();
    if (!(str_starts_with($url, './') || str_starts_with($url, '?') || str_starts_with($url, $base . '/'))) return false;
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $q);
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $top = in_array($path, ['', './', '.', rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/') . '/'], true) || str_ends_with($path, '/index.php');
    foreach (['pg', 'category', 'tag', 'id'] as $key) if (isset($q[$key]) && is_string($q[$key]) && $q[$key] === ($here[$key] ?? null)) return true;
    return !array_intersect_key($q, ['pg' => 1, 'category' => 1, 'tag' => 1, 'id' => 1, 'q' => 1, 'date' => 1, 'month' => 1]) && $top && !empty($here['top']);
}
/** The links of a menu as <li> items; only the first link to the page shown is marked current. */
function nl_menu_items(string $kind, array $here): string
{
    $base = nl_base_url();
    $list = ''; $marked = false;
    foreach (nl_menu_settings($kind)['items'] as $item) {
        if (!$item['enabled']) continue;
        $current = !$marked && nl_menu_current($item['url'], $here);
        $marked = $marked || $current;
        $external = preg_match('~\Ahttps?://~i', $item['url']) && !str_starts_with($item['url'], $base . '/');
        $list .= '<li><a href="' . h($item['url']) . '"' . ($current ? ' aria-current="page"' : '') . ($external ? ' rel="noopener noreferrer"' : '') . '>' . h($item['label']) . '</a></li>';
    }
    return $list;
}
/** The public top menu. */
function nl_topmenu_html(array $here): string
{
    $list = nl_menu_items('top', $here);
    if ($list === '') return '';
    $arrow = static fn(string $dir, string $label, string $path) => '<button class="log-topmenu-arrow is-' . $dir . '" type="button" tabindex="-1" aria-hidden="true" title="' . $label . '" hidden><svg viewBox="0 0 24 24"><path d="' . $path . '"/></svg></button>';
    return '<nav class="log-topmenu" aria-label="サイトのメニュー"><div class="log-topmenu-inner"><div class="log-topmenu-scroller" data-topmenu-scroller><ul class="log-topmenu-links">' . $list . '</ul></div>'
        . $arrow('prev', '前の項目', 'm14 6-6 6 6 6') . $arrow('next', '続きの項目', 'm10 6 6 6-6 6') . '</div></nav>';
}
/** The footer: HOME with the house icon, the footer links, then the footer text. Empty when there is nothing to show. */
function nl_footer_html(array $s, array $here): string
{
    $home = $s['footer_home'] ? '<li><a class="log-footer-home" href="./"' . (!empty($here['top']) ? ' aria-current="page"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 10.6 12 3.8l8.5 6.8"/><path d="M5.8 9v10.2a.8.8 0 0 0 .8.8h3.6v-5.4a1.8 1.8 0 0 1 3.6 0V20h3.6a.8.8 0 0 0 .8-.8V9"/></svg><span>' . h(nl_crumb_home($s)) . '</span></a></li>' : '';
    $links = $home . nl_menu_items('foot', $here + ['top' => false]);
    $text = $s['show_footer'] && $s['footer_text'] !== '' ? '<p class="log-footer-text">' . h($s['footer_text']) . '</p>' : '';
    if ($links === '' && $text === '') return '';
    return '<footer class="log-site-footer">' . ($links !== '' ? '<nav class="log-footer-nav" aria-label="フッターのリンク"><ul>' . $links . '</ul></nav>' : '') . $text . '</footer>';
}
