<?php
/* Original LOG link icons and editor. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_LINK_ICONS = ['conversation' => '会話・短文', 'pictures' => '写真・画像', 'sky-network' => '空・通信', 'code-branch' => 'コード・分岐', 'film' => '動画・映像', 'mail' => 'メール', 'link' => '一般リンク'];
const NL_LINKS_MESSAGE_MAX = 300;

function nl_links_default_item(): array
{
    return ['id' => 'links', 'kind' => 'links', 'enabled' => true, 'icon_frame' => true, 'message' => '', 'links' => [
        ['label' => 'X', 'url' => 'https://x.com/', 'icon' => 'conversation'],
        ['label' => 'Instagram', 'url' => 'https://www.instagram.com/', 'icon' => 'pictures'],
        ['label' => 'Bluesky', 'url' => 'https://bsky.app/', 'icon' => 'sky-network'],
        ['label' => 'GitHub', 'url' => 'https://github.com/', 'icon' => 'code-branch'],
        ['label' => 'YouTube', 'url' => 'https://www.youtube.com/', 'icon' => 'film'],
    ]];
}
/** Old installations and backups gain the new block and fields without losing their order. */
function nl_sidebar_with_links(array $items): array
{
    foreach ($items as $i => $item) {
        if (($item['id'] ?? '') !== 'links' || !is_array($item)) continue;
        $items[$i] += ['icon_frame' => true, 'message' => ''];
        return $items;
    }
    array_unshift($items, nl_links_default_item());
    return $items;
}
/** Plain text shown above the buttons; line breaks are kept, HTML is not. */
function nl_links_message(mixed $message): string
{
    if (!is_string($message) || !mb_check_encoding($message, 'UTF-8')) throw new UnexpectedValueException('メッセージの形式を確認してください');
    $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
    if (mb_strlen($message) > NL_LINKS_MESSAGE_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $message)) throw new UnexpectedValueException('メッセージは' . NL_LINKS_MESSAGE_MAX . '文字以内で入力してください');
    return preg_replace("/\n{3,}/", "\n\n", $message);
}
function nl_links_validate(mixed $links): array
{
    if (!is_array($links) || !array_is_list($links)) throw new UnexpectedValueException('リンク集の形式を確認してください');
    $out = [];
    foreach ($links as $link) {
        if (!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['url'] ?? null) || !is_string($link['icon'] ?? null)
            || !isset(NL_LINK_ICONS[$link['icon']]) || !mb_check_encoding($link['label'], 'UTF-8')
            || mb_strlen($link['label']) > 100 || preg_match('/[\x00-\x1F\x7F]/', $link['label'])) throw new UnexpectedValueException('リンクの表示名は100文字以内で、アイコンは一覧から選んでください');
        $label = trim($link['label']); $url = nl_link_url($link['url']);
        if ($label === '' || $url === '') throw new UnexpectedValueException('リンクの表示名とURLを入力してください。URLはhttp・https・メールアドレスか、このサイト内のパスを使います');
        $out[] = ['label' => $label, 'url' => $url, 'icon' => $link['icon']];
    }
    return $out;
}
/** Link buttons also accept one mail address, typed bare or as mailto: (no ?subject= or other headers). */
function nl_link_url(string $url): string
{
    $url = trim($url);
    $address = preg_match('/\Amailto:(.+)\z/i', $url, $m) ? rawurldecode($m[1]) : (str_contains($url, '@') && !str_contains($url, '/') ? $url : null);
    if ($address !== null) return strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $address : '';
    return nl_sidebar_url($url);
}
/** The editor shows a mail link as the plain address the owner typed. */
function nl_link_edit_url(string $url): string
{
    return str_starts_with($url, 'mailto:') ? substr($url, 7) : $url;
}
/** Inline only our own bundled SVGs; user HTML cannot supply SVG markup. */
function nl_link_icon(string $icon): string
{
    static $cache = [];
    if (!isset(NL_LINK_ICONS[$icon])) $icon = 'link';
    if (!isset($cache[$icon])) {
        $svg = file_get_contents(__DIR__ . '/../viewer/link-icons/' . $icon . '.svg');
        $cache[$icon] = str_replace('<svg ', '<svg class="log-link-icon" aria-hidden="true" focusable="false" ', $svg ?: '');
    }
    return $cache[$icon];
}
function nl_links_html(array $item, array $s): string
{
    $message = $item['message'] ?? '';
    $html = '<section class="log-profile-links" aria-label="プロフィールとリンク集"><div class="log-profile-icon' . (($item['icon_frame'] ?? true) ? ' log-profile-icon-framed' : '') . '">' . nl_icon_html($s, '', 'log-settings-avatar') . '</div>'
        . ($message !== '' ? '<p class="log-profile-message">' . nl2br(h($message), false) . '</p>' : '')
        . '<nav class="log-link-buttons" aria-label="リンク集">';
    foreach ($item['links'] as $link) {
        $url = nl_link_url($link['url']);
        if ($url === '') continue;
        $html .= '<a class="log-link-button" href="' . h($url) . '"' . (str_starts_with($url, 'mailto:') ? ' data-mail="' . h(substr($url, 7)) . '"' : ' target="_blank" rel="noopener noreferrer"') . '>' . nl_link_icon($link['icon']) . '<span>' . h($link['label']) . '</span></a>';
    }
    return $html . '</nav></section>';
}
function nl_link_edit_row(array $link): string
{
    $options = '';
    foreach (NL_LINK_ICONS as $key => $label) $options .= '<option value="' . $key . '"' . ($link['icon'] === $key ? ' selected' : '') . '>' . h($label) . '</option>';
    return '<div class="log-link-edit-row" data-link-item><span class="log-link-edit-preview" data-link-preview>' . nl_link_icon($link['icon']) . '</span>'
        . '<label class="log-link-edit-label">表示名<input data-link-label maxlength="100" required placeholder="例：X" value="' . h($link['label']) . '"></label>'
        . '<label class="log-link-edit-url">URL・メール<input data-link-url maxlength="2000" required placeholder="https://example.com/your-profile または you@example.com" value="' . h(nl_link_edit_url($link['url'])) . '"></label>'
        . '<label class="log-link-edit-icon">アイコン<select data-link-icon>' . $options . '</select></label><div class="log-link-edit-actions">'
        . '<button class="btn log-icon-btn" type="button" data-link-step="-1" aria-label="リンクを上へ移動">' . nl_sidebar_ui_icon('up') . '</button><button class="btn log-icon-btn" type="button" data-link-step="1" aria-label="リンクを下へ移動">' . nl_sidebar_ui_icon('down') . '</button>'
        . '<button class="btn log-icon-btn danger" type="button" data-link-remove aria-label="このリンクを削除">' . nl_sidebar_ui_icon('remove') . '</button></div></div>';
}
function nl_links_editor(array $item): string
{
    $rows = ''; $icons = '';
    foreach ($item['links'] as $link) $rows .= nl_link_edit_row($link);
    foreach (array_keys(NL_LINK_ICONS) as $key) $icons .= '<span data-link-icon-source="' . $key . '">' . nl_link_icon($key) . '</span>';
    return '<details class="log-sidebar-editor log-links-editor"><summary>プロフィールとリンクを編集</summary><div class="log-sidebar-editor-body">'
        . '<fieldset class="log-links-profile"><legend>プロフィール</legend><label class="check"><input type="checkbox" data-links-frame' . (($item['icon_frame'] ?? true) ? ' checked' : '') . '>アイコンにフチを付ける</label>'
        . '<label>メッセージ（リンクの上に表示・任意）<textarea data-links-message rows="3" maxlength="' . NL_LINKS_MESSAGE_MAX . '" placeholder="例：イラストの依頼はDMへどうぞ。">' . h($item['message'] ?? '') . '</textarea></label>'
        . '<p class="note">サイトの紹介文とは別に、訪問者へ伝えたいことを書けます。' . NL_LINKS_MESSAGE_MAX . '文字まで、改行できます。HTMLは使いません。空欄なら表示しません。</p></fieldset>'
        . '<fieldset class="log-links-list"><legend>リンク</legend><div data-link-items>' . $rows . '</div><button class="btn" type="button" data-link-add>' . nl_sidebar_ui_icon('add') . 'リンクを追加</button>'
        . '<p class="note">初期のURLは各サービスのトップページです。自分のプロフィールURLに書き換えて使えます。メールは「you@example.com」のようにアドレスだけを入力し、アイコンに「メール」を選びます。訪問者が押すと、アドレスのコピーかメールアプリでの送信を選べます。アイコンは用途を表すオリジナルの線画で、公式ロゴは使っていません。</p></fieldset>'
        . '<template data-link-template>' . nl_link_edit_row(['label' => '', 'url' => '', 'icon' => 'link']) . '</template><template data-link-icon-set>' . $icons . '</template></div></details>';
}
