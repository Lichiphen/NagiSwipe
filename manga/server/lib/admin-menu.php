<?php
/*
 * The admin menu, shared by the admin pages (the header and the button that slides in once the header scrolls away)
 * and the LOG pages while the owner is logged in (a button at the top right on PCs, at the bottom right on phones).
 * Every entry has an icon left of its name. The button opens all entries in a wide dialog in the middle of the screen
 * (viewer/admin-menu.js, viewer/admin-menu.css). MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }
require_once __DIR__ . '/update.php';

/** The entries: [main, more]. $base is the way to admin/index.php from the page ('' in the admin, 'admin/' on the LOG). */
function nm_menu_entries(string $base, string $current, bool $guest): array
{
    $link = fn(string $q, string $icon, string $label, string $key = '', string $extra = '') => '<a href="' . h($base . 'index.php' . $q) . '"'
        . ($key !== '' && $key === $current ? ' aria-current="page"' : '') . '>' . nm_icon($icon, 'nm-menu-icon') . '<span>' . h($label) . '</span>' . $extra . '</a>';
    $main = $link('', 'tool-manga', 'NagiMANGA', 'manga');
    // The bell: comments to approve or not seen yet (lib/log-comments.php), while comments are on or some are kept.
    $bell = !$guest && function_exists('nl_comments_notice') && (nl_settings()['comments'] || nl_comments_index()['n']) ? $link('?p=log_comments', 'nav-bell', nm_t('コメント'), 'comments', nl_comments_menu_badge()) : '';
    if (!$guest) $main .= $link('?p=log', 'nav-log', nm_t('LOG・投稿'), 'log') . $bell . $link('?p=scope', 'nav-scope', 'NagiScope', 'scope') . $link('?p=settings', 'ui-settings', nm_t('設定'), 'settings');
    $more = $guest ? '' : $link('?p=embed', 'nav-code', nm_t('漫画の設置用コード')) . $link('?p=backup', 'nav-backup', nm_t('バックアップ'))
        . $link('?p=update', 'nav-update', nm_t('更新'), '', nm_update_available(nm_update_cached()) ? ' <span class="badge new">' . h(nm_t('新')) . '</span>' : '');
    $more .= '<form method="post" action="' . h($base . 'index.php') . '"><input type="hidden" name="csrf" value="' . h(nm_csrf_token()) . '"><input type="hidden" name="do" value="logout">'
        . '<button class="link">' . nm_icon('nav-logout', 'nm-menu-icon') . '<span>' . h(nm_t($guest ? 'ゲストを終了' : 'ログアウト')) . '</span></button></form>';
    return [$main, $more];
}

/**
 * The floating button and its dialog. $where: 'admin' (shown once the header has scrolled away) or 'log'
 * (shown from the start, floating in softly).
 */
function nm_menu_dialog(string $base, string $current, bool $guest, string $where): string
{
    [$main, $more] = nm_menu_entries($base, $current, $guest);
    $label = h(nm_t('管理メニュー'));
    // New comments show on the button too, so the owner sees them from any page.
    $count = !$guest && function_exists('nl_comments_notice') ? nl_comments_notice() : 0;
    return '<button type="button" class="nav-fab" data-nav-fab="' . h($where) . '" aria-haspopup="dialog" aria-controls="nav-dialog" tabindex="-1" aria-hidden="true">' . nm_icon('menu', 'nm-menu-icon') . '<span>' . $label . '</span>' . ($count > 0 ? '<span class="nav-fab-count">' . ($count > 99 ? '99+' : $count) . '</span>' : '') . '</button>'
        . '<dialog class="nav-dialog" id="nav-dialog" aria-labelledby="nav-dialog-title"><div class="nav-dialog-head"><h2 id="nav-dialog-title">' . $label . '</h2><button type="button" class="btn" data-nav-close>' . h(nm_t('閉じる')) . '</button></div>'
        . '<div class="nav-dialog-main">' . $main . '</div><div class="nav-dialog-more">' . $more . '</div></dialog>';
}
