<?php
/*
 * NagiManga admin
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
declare(strict_types=1);

define('NAGIMANGA', true);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/image.php';
require __DIR__ . '/../lib/backup.php';
require __DIR__ . '/../lib/epub.php';
require __DIR__ . '/../lib/update.php';
require __DIR__ . '/../lib/log.php';
require __DIR__ . '/../lib/log-public.php';
require __DIR__ . '/../lib/log-backup.php';
require __DIR__ . '/../lib/log-admin.php';
nm_load_plugins();

const NM_SETUP_WINDOW = 1800; // first-run setup must happen within 30 min
// The image-popup script most sites pair with NagiManga (for the Tegalog setting line)
const NM_NAGISWIPE_JS = 'https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.4.0/NagiSwipe-main.js';
/** Blocks of the settings screen: their heading (for messages) and tab. */
const NM_SETTINGS_SECTIONS = [
    'log_preferences' => ['公開範囲・表示件数・ページ送り', 'log'],
    'log_display' => ['一覧の見せ方・記事の下', 'log'],
    'log_design' => ['LOGのデザイン・アイコン', 'log'],
    'log_seo' => ['検索エンジンとサイトマップ', 'log'],
    'log_topmenu' => ['トップメニュー', 'log'],
    'log_sidebar' => ['サイドバー・メニュー', 'log'],
    'log_footer' => ['サイト下部の表記', 'log'],
    'log_footmenu' => ['フッターのリンク', 'log'],
    'log_warning' => ['閲覧注意の定型文', 'log'],
    'admin_lang' => ['管理画面の言語', 'common'],
    'lscache' => ['LiteSpeed Cache', 'common'],
    'login_days' => ['ログインを保つ期間', 'common'],
    'log_guard' => ['画像収集BOTへの対策', 'common'],
    'access' => ['管理画面を開ける場所（IP 制限）', 'common'],
    'general' => ['設置先と画像アップロード', 'common'],
    'page' => ['NagiMANGAの作品ページ', 'manga'],
    'advanced' => ['上級者向けの設定', 'manga'],
];

nm_admin_headers();

$cfg = nm_config();
// The admin follows its setting or the browser (Japanese or English); public pages stay Japanese.
nm_lang(nm_admin_lang($cfg ?: []));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') nm_not_found();

// --- First run -------------------------------------------------------------
if (!$cfg || empty($cfg['admin_hash'])) {
    if (defined('NL_PUBLIC_LOGIN')) nm_not_found();
    nm_setup($method);
    exit;
}

// --- Gate: IP, then login key, then session ----------------------------------
// A guest plugin may let read-only guests past the IP list; the admin role never is.
$guestCfg = nm_guest_config();
$ipOk = nm_ip_allowed($cfg);
if (!$ipOk && !($guestCfg && $guestCfg['bypass_ip'])) {
    nm_log('admin_ip_denied');
    nm_not_found();
}

nm_session_start();

// Guest entrance: admin/index.php?guest (or ?guest=KEY when the plugin sets one)
if ($method === 'GET' && isset($_GET['guest']) && $guestCfg) {
    nm_guest_enter($guestCfg);
}

if (!nm_is_logged_in()) {
    if (!$ipOk) {
        nm_log('admin_ip_denied');
        nm_not_found();
    }
    nm_login_page($method, $cfg);
    exit;
}

if (nm_is_guest()) {
    // Plugin removed, or IP rules no longer let guests in: end the guest session
    if (!$guestCfg || (!$ipOk && !$guestCfg['bypass_ip'])) {
        nm_logout();
        nm_not_found();
    }
} elseif (!$ipOk) {
    nm_log('admin_ip_denied');
    nm_not_found();
}

// --- Logged in ---------------------------------------------------------------
if ($method === 'POST') {
    if (!nm_csrf_ok()) {
        nm_log('csrf_rejected', (string)($_POST['do'] ?? ''));
        nm_not_found();
    }
    // Guests: everything except leaving is refused, whatever the form says
    if (nm_is_guest() && ($_POST['do'] ?? '') !== 'logout') nm_guest_refuse();
    // Any change (posts, images, works, settings, restore) can show on the cached LOG pages.
    nm_lscache_purge(nm_lscache_soft_action($_POST));
    nm_handle_post($cfg);
    exit;
}

$page = nm_str($_GET, 'p', 20);
match ($page) {
    '', 'works' => nm_view_dashboard(),
    'work' => nm_view_work(nm_str($_GET, 'id', 12)),
    'log' => nm_is_guest() ? nm_view_guest_denied('LOG') : nl_view_log(),
    'log_edit' => nm_is_guest() ? nm_view_guest_denied('LOG') : nl_view_edit(nm_str($_GET, 'id', 24)),
    'log_media' => nm_is_guest() ? nm_view_guest_denied(nm_t('LOGの画像')) : nl_view_media(),
    'log_pages' => nm_is_guest() ? nm_view_guest_denied(nm_t('固定ページ')) : nl_view_pages(),
    'log_page' => nm_is_guest() ? nm_view_guest_denied(nm_t('固定ページ')) : nl_view_page_edit(nm_str($_GET, 'id', 14)),
    'log_image' => nm_is_guest() ? nm_not_found() : nl_serve_media(nm_str($_GET, 'media', 16), isset($_GET['thumb']), true),
    'log_media_json' => nm_is_guest() ? nm_not_found() : nl_admin_catalog(false),
    'log_manga_json' => nm_is_guest() ? nm_not_found() : nl_admin_catalog(true),
    'img' => nm_admin_image(nm_str($_GET, 'id', 12), nm_str($_GET, 'f', 40), isset($_GET['full'])),
    'backup' => nm_is_guest() ? nm_view_guest_denied(nm_t('バックアップ')) : nm_view_backup(),
    'settings' => nm_is_guest() ? nm_view_guest_denied(nm_t('設定')) : nm_view_settings($cfg),
    'embed' => nm_is_guest() ? nm_view_guest_denied(nm_t('設置用コード')) : nm_view_embed(),
    'update' => nm_is_guest() ? nm_view_guest_denied(nm_t('更新')) : nm_view_update($cfg),
    default => nm_not_found(),
};
exit;

// ===========================================================================
// Headers / layout
// ===========================================================================

function nm_admin_headers(): void
{
    // LOG previews show embedded players; provider scripts stay off in the admin.
    $frames = function_exists('nl_embed_frame_src') ? '; frame-src ' . nl_embed_frame_src() . "; media-src 'self' https:" : '';
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" . $frames);
    header('X-Frame-Options: DENY');
    nm_lscache_header(false);
    header('X-Content-Type-Options: nosniff');
    // The login key is in the URL: never leak it through Referer
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function nm_asset(string $file): string
{
    $path = __DIR__ . '/' . $file;
    $v = nm_asset_version($path);
    return h($file . '?v=' . $v);
}

function nm_layout(string $title, string $body, bool $nav = true): void
{
    $flash = '';
    if (!empty($_SESSION['nm_flash'])) {
        foreach ($_SESSION['nm_flash'] as [$type, $msg]) {
            $flash .= '<p class="flash flash-' . h($type) . '">' . h(nm_t((string)$msg)) . '</p>';
        }
        unset($_SESSION['nm_flash']);
    }
    $navHtml = '';
    $banner = '';
    if ($nav) {
        $guest = nm_is_guest();
        $p = nm_str($_GET, 'p', 20);
        $isLog = str_starts_with($p, 'log');
        $navHtml = '<nav class="nav workspace-nav" aria-label="' . h(nm_t('管理メニュー')) . '"><a href="index.php"' . (!$isLog && $p !== 'settings' ? ' aria-current="page"' : '') . '>NagiMANGA</a>'
            . ($guest ? '' : '<a href="index.php?p=log"' . ($isLog ? ' aria-current="page"' : '') . '>' . h(nm_t('LOG・投稿')) . '</a><a href="index.php?p=settings"' . ($p === 'settings' ? ' aria-current="page"' : '') . '>' . h(nm_t('設定')) . '</a>')
            . '<details class="nav-more"><summary>' . h(nm_t('管理メニュー')) . '</summary><div>'
            . ($guest ? '' : '<a href="index.php?p=embed">' . h(nm_t('漫画の設置用コード')) . '</a><a href="index.php?p=backup">' . h(nm_t('バックアップ')) . '</a><a href="index.php?p=update">' . h(nm_t('更新')) . (nm_update_available(nm_update_cached()) ? ' <span class="badge new">' . h(nm_t('新')) . '</span>' : '') . '</a>')
            . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="logout"><button class="link">' . h(nm_t($guest ? 'ゲストを終了' : 'ログアウト')) . '</button></form></div></details></nav>';
        $g = $guest ? nm_guest_config() : null;
        if ($g) $banner = '<p class="guest-banner">' . h($g['banner']) . '</p>';
    }
    $logPage = $nav && !nm_is_guest() && str_starts_with(nm_str($_GET, 'p', 20), 'log');
    $logUi = $logPage || (defined('NL_PUBLIC_LOGIN') && NL_PUBLIC_LOGIN) || ($nav && !nm_is_guest() && nm_str($_GET, 'p', 20) === 'settings');
    echo '<!DOCTYPE html><html lang="' . nm_lang() . '"' . ($logUi ? ' data-log-theme="' . h(nl_settings()['theme']) . '" data-embed-scripts="off"' : '') . '><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h(nm_t($title)) . ' - NagiManga</title>' . nm_t_script()
        . '<link rel="icon" href="' . nm_asset('../viewer/favicon.svg') . '" type="image/svg+xml">'
        . '<link rel="stylesheet" href="' . nm_asset('admin.css') . '">'
        . '<script src="' . nm_asset('admin.js') . '" defer></script>'
        . ($logUi
            ? '<link rel="stylesheet" href="' . nm_asset('../viewer/log.css') . '"><link rel="stylesheet" href="' . nm_asset('../viewer/log-owner.css') . '"><script src="' . nm_asset('../viewer/log-embed.js') . '" defer></script>'
              . '<script src="' . nm_asset('log-settings.js') . '" defer></script>'
              . '<script src="' . nm_asset('../viewer/log-loading.js') . '" defer></script>' : '')
        . ($logPage
            ? '<link rel="stylesheet" href="' . nm_asset('../viewer/NagiSwipe-main.css') . '">'
              . '<script src="' . nm_asset('log-editor.js') . '" defer></script>'
              . '<script src="' . nm_asset('log-manage.js') . '" defer></script>'
              . '<script src="' . nm_asset('log-pages.js') . '" defer></script>'
              . '<script src="' . nm_asset('../viewer/NagiSwipe-main.js') . '" defer></script>'
              . '<script src="' . nm_asset('../viewer/NagiManga.js') . '" defer></script>' : '')
        . '</head><body><header class="top"><span class="brand">' . nl_ui_icon() . '<span>NagiManga / LOG</span></span>' . $navHtml . '</header>'
        . '<main class="main' . ($logPage ? ' log-admin-main' : '') . '">' . $banner . $flash . $body . '</main>'
        . '<footer class="foot">NagiManga ' . h(NM_VERSION) . '</footer></body></html>';
}

function nm_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(nm_csrf_token()) . '">';
}

function nm_flash(string $type, string $msg): void
{
    $_SESSION['nm_flash'][] = [$type, $msg];
}

function nm_redirect(string $query = ''): never
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function nm_base_url(): string
{
    $cfg = nm_config();
    if (!empty($cfg['base_url'])) return rtrim((string)$cfg['base_url'], '/');
    return nm_detect_base_url();
}

function nm_detect_base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A[a-z0-9.\-:\[\]]+\z/i', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), 2)), '/');
    return (nm_is_https() ? 'https' : 'http') . '://' . $host . $dir;
}

// ===========================================================================
// Setup (first run)
// ===========================================================================

function nm_setup(string $method): void
{
    // The setup form only exists for a short time after the files were put in place
    nm_ensure_dir(NM_DATA);
    $mark = NM_DATA . '/install.lock';
    if (!is_file($mark)) @file_put_contents($mark, (string)time());
    $started = (int)@file_get_contents($mark);
    if ($started < time() - NM_SETUP_WINDOW) {
        nm_log('setup_expired');
        nm_not_found();
    }

    nm_session_start();
    $errors = [];
    $ip = nm_client_ip();

    if ($method === 'POST') {
        $pre = (string)($_SESSION['nm_pre'] ?? '');
        if ($pre === '' || !hash_equals($pre, (string)($_POST['csrf'] ?? ''))) nm_not_found();

        $pw = nm_str($_POST, 'password', 200);
        $pw2 = nm_str($_POST, 'password2', 200);
        if (mb_strlen($pw) < 10) $errors[] = nm_t('パスワードは 10 文字以上にしてください');
        if ($pw !== $pw2) $errors[] = nm_t('確認用のパスワードが一致しません');

        if (!$errors) {
            $cfg = nm_default_config();
            $cfg['admin_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            // base_url stays empty: share tags follow the address the admin is opened with
            if (!empty($_POST['lock_ip'])) $cfg['allowed_ips'] = [$ip];
            nm_save_config($cfg);
            @unlink($mark);
            nm_ensure_dir(NM_DATA . '/works');
            // Lets the admin page check that data/ is not reachable from the web
            @file_put_contents(NM_DATA . '/probe.txt', 'nm-probe');
            nm_log('setup_done');

            $login = nm_base_url() . '/admin/index.php?k=' . $cfg['login_key'];
            $_SESSION = [];
            nm_layout(nm_t('セットアップ完了'), '<section class="card"><h1>' . nm_t('セットアップが完了しました') . '</h1>'
                . '<p>' . nm_t('<strong>次の URL をブックマークしてください。</strong>LOGのメニューにある「ログイン」からも、パスワードで入れます。') . '</p>'
                . '<p><input class="copy-src wide" readonly value="' . h($login) . '"> <button type="button" class="btn js-copy">' . nm_t('コピー') . '</button></p>'
                . '<p class="note">' . nm_t('忘れた場合は、FTP で {config} の {key} を確認し、{url} の後ろに付けてください。', ['config' => '<code>data/config.php</code>', 'key' => '<code>login_key</code>', 'url' => '<code>admin/index.php?k=</code>']) . '</p>'
                . '<p><a class="btn primary" href="' . h($login) . '">' . nm_t('ログイン画面へ') . '</a></p></section>', false);
            return;
        }
    }

    $_SESSION['nm_pre'] = bin2hex(random_bytes(32));
    $sup = nm_image_support();
    $checks = [
        [nm_t('PHP 8.1 以上'), PHP_VERSION_ID >= 80100, PHP_VERSION],
        [nm_t('GD（画像処理）'), $sup['gd'], ''],
        [nm_t('WebP 書き出し'), $sup['webp'], $sup['webp'] ? '' : nm_t('JPEG で保存します')],
        [nm_t('fileinfo（画像の中身の判定）'), $sup['finfo'], ''],
        [nm_t('ZipArchive（バックアップ）'), nm_zip_available(), nm_zip_available() ? '' : nm_t('バックアップは FTP で data/works をコピーしてください')],
        // The server path is shown only when something must be fixed
        [nm_t('data フォルダへの書き込み'), is_writable(NM_DATA), is_writable(NM_DATA) ? '' : nm_t('FTP で data フォルダを書き込み可能（755 など）にしてください')],
    ];
    $rows = '';
    foreach ($checks as [$label, $ok, $note]) {
        $rows .= '<tr><td>' . h($label) . '</td><td class="' . ($ok ? 'ok' : 'ng') . '">' . ($ok ? 'OK' : nm_t('要確認')) . '</td><td>' . h($note) . '</td></tr>';
    }
    $err = '';
    foreach ($errors as $e) $err .= '<p class="flash flash-err">' . h($e) . '</p>';

    nm_layout(nm_t('セットアップ'), $err . '<section class="card"><h1>' . nm_t('NagiManga のセットアップ') . '</h1>'
        . '<table class="table">' . $rows . '</table>'
        . '<form method="post" action="index.php" class="form">'
        . '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['nm_pre']) . '">'
        . '<label>' . nm_t('管理者パスワード（10 文字以上）') . '<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>'
        . '<label>' . nm_t('もう一度') . '<input type="password" name="password2" autocomplete="new-password" required minlength="10"></label>'
        . '<label class="check"><input type="checkbox" name="lock_ip" value="1" checked> ' . nm_t('今の IP アドレス（{ip}）からだけ管理画面を開けるようにする（推奨。あとで設定から変更できます）', ['ip' => h($ip)]) . '</label>'
        . '<button class="btn primary">' . nm_t('セットアップする') . '</button></form>'
        . '<p class="note">' . nm_t('この画面は、ファイルを置いてから 30 分だけ表示されます。') . '</p></section>', false);
}

// ===========================================================================
// Login
// ===========================================================================

function nm_login_page(string $method, array $cfg): void
{
    $publicLogin = defined('NL_PUBLIC_LOGIN') && NL_PUBLIC_LOGIN;
    $src = $method === 'POST' ? $_POST : $_GET;
    $key = nm_str($src, 'k', 64);
    // The LOG login alias exposes only a password form; the keyed URL stays valid.
    if (!$publicLogin && ($key === '' || !hash_equals((string)$cfg['login_key'], $key))) {
        // While the LOG shows its "ログイン" link, the password form is public anyway: send an expired
        // session (or an old "管理" tab) there instead of a dead end. A wrong key or a hidden link: keep answering 404.
        if ($method === 'GET' && $key === '' && function_exists('nl_settings') && nl_settings()['show_login'] && !isset($_GET['guest']) && nm_ip_allowed($cfg)) {
            header('Location: login.php', true, 303);
            exit;
        }
        nm_not_found();
    }
    if (nm_login_blocked()) {
        nm_log('login_blocked');
        nm_not_found();
    }

    $error = '';
    if ($method === 'POST') {
        $pre = (string)($_SESSION['nm_pre'] ?? '');
        if ($pre === '' || !hash_equals($pre, (string)($_POST['csrf'] ?? ''))) nm_not_found();
        if (nm_try_login(nm_str($_POST, 'password', 200))) nm_redirect($publicLogin ? 'p=log' : '');
        if (nm_login_blocked()) nm_not_found();
        $error = '<p class="flash flash-err">' . nm_t('パスワードが違います') . '</p>';
    }

    $_SESSION['nm_pre'] = bin2hex(random_bytes(32));
    nm_layout(nm_t('ログイン'), $error . '<section class="card narrow log-login-card"><h1>' . ($publicLogin ? nl_icon_html(nl_settings(), '../', 'log-login-avatar') : '') . nm_t('ログイン') . '</h1>'
        . '<form method="post" action="' . ($publicLogin ? 'login.php' : 'index.php') . '" class="form">'
        . '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['nm_pre']) . '">'
        . ($publicLogin ? '' : '<input type="hidden" name="k" value="' . h($key) . '">')
        . '<label>' . nm_t('パスワード') . '<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button class="btn primary">' . ($publicLogin ? nl_login_icon() : '') . nm_t('ログイン') . '</button></form>'
        . ($publicLogin ? '<p>' . nm_t('<a href="../">LOGへ戻る</a>') . '</p>' : '') . '</section>', false);
}

// ===========================================================================
// POST actions
// ===========================================================================

function nm_handle_post(array $cfg): void
{
    $do = nm_str($_POST, 'do', 30);
    if (str_starts_with($do, 'log_')) nl_handle_post($do);
    $id = nm_str($_POST, 'id', 12);

    switch ($do) {
        case 'logout':
            $wasGuest = nm_is_guest();
            $g = $wasGuest ? nm_guest_config() : null;
            nm_logout();
            $link = $g && $g['exit_url'] !== '' ? '<p><a class="btn primary" href="' . h($g['exit_url']) . '">' . nm_t('デモのページへ戻る') . '</a></p>' : '';
            nm_layout(nm_t('ログアウト'), '<section class="card narrow"><h1>' . ($wasGuest ? nm_t('ゲストを終了しました') : nm_t('ログアウトしました')) . '</h1>' . $link . '</section>', false);
            exit;

        case 'create':
            $title = trim(nm_str($_POST, 'title', 600));
            if ($title === '' || mb_strlen($title) > 200) {
                nm_flash('err', nm_t('タイトルは 1〜200 文字で入力してください'));
                nm_redirect();
            }
            $newId = nm_random_id();
            while (is_dir(nm_work_dir($newId))) $newId = nm_random_id();
            nm_create_work_dirs($newId);
            nm_save_work([
                'id' => $newId,
                'title' => $title,
                'series' => mb_substr(trim(nm_str($_POST, 'series', 600)), 0, 200),
                'direction' => nm_direction(nm_str($_POST, 'direction', 10)),
                'password_hash' => '',
                'seq' => 0,
                'created' => time(),
                'pages' => [],
            ]);
            nm_log('work_created', $newId);
            nm_flash('ok', nm_t('作品を作成しました。ページ画像をアップロードしてください'));
            nm_redirect('p=work&id=' . $newId);

        case 'update':
            nm_modify_work($id, static function (array $w) {
                $title = trim(nm_str($_POST, 'title', 600));
                if ($title === '' || mb_strlen($title) > 200) throw new UnexpectedValueException(nm_t('タイトルは 1〜200 文字で入力してください'));
                $w['title'] = $title;
                $w['series'] = mb_substr(trim(nm_str($_POST, 'series', 600)), 0, 200);
                $w['direction'] = nm_direction(nm_str($_POST, 'direction', 10));
                return $w;
            });
            nm_flash('ok', nm_t('保存しました'));
            nm_redirect('p=work&id=' . $id);

        case 'upload':
            nm_upload_page($id, $cfg);

        case 'upload_light':
            nm_upload_light($id, $cfg);

        case 'clear_light':
            nm_modify_work($id, static function (array $w) {
                $dir = nm_work_dir($w['id']) . '/pages';
                foreach ($w['pages'] as &$p) {
                    if (isset($p['m']['f'])) nm_delete_page_files($dir, $p['m']['f']);
                    unset($p['m']);
                }
                unset($p);
                return $w;
            });
            nm_flash('ok', nm_t('小容量版をすべて外しました（通常版だけで表示します）'));
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'epub_light':
            if (!nm_valid_id($id) || !nm_load_work($id)) nm_not_found();
            $f = $_FILES['epub_light'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                nm_flash('err', nm_t('EPUB を受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）'));
                nm_redirect('p=work&id=' . $id . '#pages');
            }
            nm_flash_light_result(nm_epub_attach_light($id, (string)$f['tmp_name'], $cfg));
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'epub':
            $f = $_FILES['epub'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                nm_flash('err', nm_t('EPUB を受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）'));
                nm_redirect();
            }
            $res = nm_epub_import((string)$f['tmp_name'],
                mb_substr(trim(nm_str($_POST, 'title', 600)), 0, 200),
                mb_substr(trim(nm_str($_POST, 'series', 600)), 0, 200), $cfg);
            if (is_string($res)) {
                nm_log('epub_rejected', $res);
                nm_flash('err', $res);
                nm_redirect();
            }
            nm_log('epub_imported', $res['id'] . ' ' . $res['count']);
            nm_flash('ok', nm_t('「{title}」を EPUB から作りました（{n} ページ', ['title' => $res['title'], 'n' => $res['count']])
                . ($res['skipped'] ? nm_t('、読めなかった {n} ページは飛ばしました', ['n' => $res['skipped']]) : '') . nm_t('）'));
            $light = $_FILES['epub_light'] ?? null;
            if (is_array($light) && ($light['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$light['tmp_name'])) {
                nm_flash_light_result(nm_epub_attach_light($res['id'], (string)$light['tmp_name'], $cfg));
            }
            nm_redirect('p=work&id=' . $res['id']);

        case 'sort_name':
            nm_modify_work($id, static function (array $w) {
                usort($w['pages'], static fn($a, $b) => strnatcasecmp($a['o'] ?: $a['f'], $b['o'] ?: $b['f']));
                return $w;
            });
            nm_flash('ok', nm_t('ファイル名の順に並べ替えました'));
            nm_redirect('p=work&id=' . $id);

        case 'order':
            $order = json_decode(nm_str($_POST, 'order', 200000), true);
            nm_modify_work($id, static function (array $w) use ($order) {
                if (!is_array($order)) throw new UnexpectedValueException(nm_t('並び順を受け取れませんでした'));
                $byFile = [];
                foreach ($w['pages'] as $p) $byFile[$p['f']] = $p;
                // Must be exactly the same set of pages, just reordered
                if (count($order) !== count($byFile) || count(array_unique($order)) !== count($order)) {
                    throw new UnexpectedValueException(nm_t('ページが更新されています。再読み込みしてください'));
                }
                $new = [];
                foreach ($order as $f) {
                    if (!is_string($f) || !isset($byFile[$f])) throw new UnexpectedValueException(nm_t('ページが更新されています。再読み込みしてください'));
                    $new[] = $byFile[$f];
                }
                $w['pages'] = $new;
                return $w;
            }, true);
            nm_json(['ok' => true]);

        case 'delpage':
            $file = nm_str($_POST, 'f', 40);
            nm_modify_work($id, static function (array $w) use ($file) {
                foreach ($w['pages'] as $p) {
                    if ($p['f'] === $file) nm_delete_page(nm_work_dir($w['id']) . '/pages', $p);
                }
                $w['pages'] = array_values(array_filter($w['pages'], static fn($p) => $p['f'] !== $file));
                return $w;
            });
            nm_flash('ok', nm_t('ページを削除しました'));
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'setpw':
            $pw = nm_str($_POST, 'password', 200);
            nm_modify_work($id, static function (array $w) use ($pw) {
                if (mb_strlen($pw) < 4) throw new UnexpectedValueException(nm_t('パスワードは 4 文字以上にしてください'));
                $w['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                // Encrypted copy so the admin can look it up again (see nm_seal)
                $w['password_enc'] = nm_seal($pw);
                return $w;
            });
            nm_log('work_password_set', $id);
            nm_flash('ok', nm_t('パスワードを設定しました（以前に配った閲覧用の鍵は無効になります）'));
            nm_redirect('p=work&id=' . $id);

        case 'page_public':
            $on = !empty($_POST['on']);
            nm_modify_work($id, static function (array $w) use ($on) {
                $w['page'] = $on;
                return $w;
            });
            nm_flash('ok', $on ? nm_t('個別ページを公開しました') : nm_t('個別ページを非公開にしました（ブログやてがろぐに埋め込んだビューアーは今までどおり読めます）'));
            nm_redirect('p=work&id=' . $id);

        case 'clearpw':
            nm_modify_work($id, static function (array $w) {
                $w['password_hash'] = '';
                $w['password_enc'] = '';
                return $w;
            });
            nm_log('work_password_cleared', $id);
            nm_flash('ok', nm_t('パスワードを外しました（誰でも読めます）'));
            nm_redirect('p=work&id=' . $id);

        case 'delete':
            $work = nm_load_work($id);
            if (!$work) nm_not_found();
            if (nm_str($_POST, 'confirm', 600) !== $work['title']) {
                nm_flash('err', nm_t('確認のタイトルが一致しないため、削除しませんでした'));
                nm_redirect('p=work&id=' . $id);
            }
            nm_with_lock('work-' . $id, static fn() => nm_rmdir_recursive(nm_work_dir($id)));
            nm_log('work_deleted', $id);
            nm_flash('ok', nm_t('「{title}」を削除しました', ['title' => $work['title']]));
            nm_redirect();

        case 'backup':
            nm_log('backup_download', $id);
            nm_backup_download($id !== '' && nm_valid_id($id) ? [$id] : []);

        case 'restore':
            nm_restore_upload();

        case 'update_check':
            $st = nm_update_status(true);
            if ($st['error'] !== '') nm_flash('err', $st['error']);
            elseif (nm_update_available($st)) nm_flash('ok', nm_t('新しいバージョン {v} があります', ['v' => $st['latest']]));
            else nm_flash('ok', nm_t('最新のバージョンです'));
            nm_redirect('p=update');

        case 'update_apply':
            $res = nm_update_apply();
            nm_flash($res['ok'] ? 'ok' : 'err', $res['msg']);
            if ($res['ok']) nm_flash('ok', nm_t('ビューアーのキャッシュバスターが変わりました。「設置用コード」の値に貼り直してください'));
            nm_redirect('p=update');

        case 'update_rollback':
            $res = nm_update_rollback(nm_str($_POST, 'backup', 60));
            nm_flash($res['ok'] ? 'ok' : 'err', $res['msg']);
            nm_redirect('p=update');

        case 'update_auto':
            $cfg['update_check'] = !empty($_POST['auto']);
            nm_save_config($cfg);
            nm_flash('ok', $cfg['update_check'] ? nm_t('ログイン時に新しいバージョンを確認します') : nm_t('ログイン時の確認をやめました（更新画面から手動で確認できます）'));
            nm_redirect('p=update');

        case 'settings_pw':
            $cur = nm_str($_POST, 'current', 200);
            $new = nm_str($_POST, 'password', 200);
            $new2 = nm_str($_POST, 'password2', 200);
            if (!password_verify($cur, (string)$cfg['admin_hash'])) {
                nm_log('admin_password_change_failed');
                nm_flash('err', nm_t('今のパスワードが違います'));
            } elseif (mb_strlen($new) < 10 || $new !== $new2) {
                nm_flash('err', nm_t('新しいパスワードは 10 文字以上で、確認用と同じにしてください'));
            } else {
                $cfg['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
                nm_save_config($cfg);
                $_SESSION['nm_ver'] = nm_admin_version();
                nm_log('admin_password_changed');
                nm_flash('ok', nm_t('管理者パスワードを変更しました（他の端末はログアウトされます）'));
            }
            nm_redirect('p=settings&section=common');

        // One block at a time (older pages and scripts); the settings screen sends settings_save_all.
        case 'settings_lscache':
            $cfg = nm_settings_one('lscache', $cfg);
            nm_save_config($cfg);
            nm_settings_logged($cfg, ['lscache']);
            nm_flash('ok', $cfg['lscache'] ? nm_t('LiteSpeed Cache を使う設定にしました') : nm_t('LiteSpeed Cache を使わない設定にしました'));
            nm_redirect('p=settings&section=common');

        case 'settings_login_days':
            $cfg = nm_settings_one('login_days', $cfg);
            nm_save_config($cfg);
            nm_session_keep();
            nm_settings_logged($cfg, ['login_days']);
            nm_flash('ok', nm_t('ログインを保つ期間を {n} 日にしました', ['n' => $cfg['login_days']]));
            nm_redirect('p=settings&section=common');

        case 'settings_access':
            $cfg = nm_settings_one('access', $cfg);
            nm_save_config($cfg);
            nm_settings_logged($cfg, ['access']);
            nm_flash('ok', nm_t('アクセス設定を保存しました'));
            nm_redirect('p=settings&section=common');

        case 'settings_general':
            nm_save_config(nm_settings_one('general', $cfg));
            nm_flash('ok', nm_t('保存しました'));
            nm_redirect('p=settings&section=common');

        case 'settings_page':
            nm_save_config(nm_settings_one('page', $cfg));
            nm_flash('ok', nm_t('個別ページの設定を保存しました'));
            nm_redirect('p=settings&section=manga');

        case 'settings_advanced':
            $cfg = nm_settings_one('advanced', $cfg);
            nm_save_config($cfg);
            nm_settings_logged($cfg, ['advanced']);
            nm_flash('ok', $cfg['hotlink'] ? nm_t('直リンク防止をオンにしました') : nm_t('直リンク防止をオフにしました'));
            nm_redirect('p=settings&section=manga');

        case 'settings_save_all':
            nm_settings_save_all($cfg);

        case 'regen_key':
            $cfg['login_key'] = nm_random_id(24);
            nm_save_config($cfg);
            nm_log('login_key_changed');
            nm_flash('ok', nm_t('ログイン URL を変更しました。新しい URL をブックマークし直してください'));
            nm_redirect('p=settings&section=common');
    }
    nm_not_found();
}

// ===========================================================================
// Settings: one form, one save button
// ===========================================================================

/*
 * Blocks kept in config.php. Each reads its fields from $_POST, checks them and returns the
 * changed config without saving it, so one save can check every block before writing any.
 */
function nm_settings_admin_lang(array $cfg): array
{
    $lang = nm_str($_POST, 'admin_lang', 10);
    $cfg['admin_lang'] = isset(nm_langs()[$lang]) ? $lang : 'auto';
    return $cfg;
}

function nm_settings_lscache(array $cfg): array
{
    $cfg['lscache'] = !empty($_POST['lscache']);
    return $cfg;
}

function nm_settings_login_days(array $cfg): array
{
    $days = (int)nm_str($_POST, 'login_days', 3);
    if (!in_array($days, NM_LOGIN_DAYS, true)) throw new UnexpectedValueException(nm_t('ログインを保つ期間を選んでください'));
    $cfg['login_days'] = $days;
    return $cfg;
}

function nm_settings_access(array $cfg): array
{
    $ips = nm_lines(nm_str($_POST, 'allowed_ips', 5000));
    foreach ($ips as $r) {
        if (!nm_valid_ip_rule($r)) throw new UnexpectedValueException(nm_t('IP アドレスの書き方が正しくありません:') . ' ' . $r);
    }
    // Never lock yourself out with one click
    $me = nm_client_ip();
    if ($ips && !array_filter($ips, static fn($r) => nm_ip_match($me, $r))) throw new UnexpectedValueException(nm_t('今の IP アドレス（{ip}）が含まれていないため保存しませんでした', ['ip' => $me]));
    $origins = [];
    foreach (nm_lines(nm_str($_POST, 'allowed_origins', 5000)) as $o) {
        $o = rtrim($o, '/');
        if (!preg_match('~\Ahttps?://[a-z0-9.\-]+(:[0-9]{1,5})?\z~i', $o)) throw new UnexpectedValueException(nm_t('サイトの書き方が正しくありません（例: https://example.com）:') . ' ' . $o);
        $origins[] = strtolower($o);
    }
    $cfg['allowed_ips'] = $ips;
    $cfg['allowed_origins'] = array_values(array_unique($origins));
    return $cfg;
}

function nm_settings_general(array $cfg): array
{
    $base = rtrim(trim(nm_str($_POST, 'base_url', 500)), '/');
    if ($base !== '' && !preg_match('~\Ahttps?://[^\s"\'<>]+\z~i', $base)) throw new UnexpectedValueException(nm_t('公開URLの書き方が正しくありません'));
    $cfg['base_url'] = $base;
    $cfg['image_quality'] = max(60, min(100, (int)(nm_str($_POST, 'image_quality', 4) ?: 90)));
    $cfg['max_upload_mb'] = max(1, min(200, (int)(nm_str($_POST, 'max_upload_mb', 4) ?: 30)));
    return $cfg;
}

function nm_settings_page(array $cfg): array
{
    $back = trim(nm_str($_POST, 'page_back', 500));
    if ($back !== '' && !preg_match('~\Ahttps?://[^\s"\'<>]+\z~i', $back)) throw new UnexpectedValueException(nm_t('戻る先の URL の書き方が正しくありません（例: https://example.com/）'));
    $cfg['page_back'] = $back;
    return $cfg;
}

function nm_settings_advanced(array $cfg): array
{
    $allow = [];
    foreach (nm_lines(nm_str($_POST, 'hotlink_allow', 5000)) as $o) {
        $o = rtrim($o, '/');
        if (!preg_match('~\Ahttps?://[a-z0-9.\-]+(:[0-9]{1,5})?\z~i', $o)) throw new UnexpectedValueException(nm_t('サイトの書き方が正しくありません（例: https://example.com）:') . ' ' . $o);
        $allow[] = strtolower($o);
    }
    $cfg['hotlink'] = !empty($_POST['hotlink']);
    $cfg['hotlink_allow'] = array_values(array_unique($allow));
    return $cfg;
}

/** One config block for the single-block actions: a mistake goes back to its tab. */
function nm_settings_one(string $key, array $cfg): array
{
    try {
        return ('nm_settings_' . $key)($cfg);
    } catch (UnexpectedValueException $e) {
        nm_flash('err', $e->getMessage());
        nm_redirect('p=settings&section=' . NM_SETTINGS_SECTIONS[$key][1]);
    }
}

/** Security log lines for the blocks that change who can reach what. */
function nm_settings_logged(array $cfg, array $sections): void
{
    if (in_array('lscache', $sections, true)) nm_log('settings_lscache_changed', $cfg['lscache'] ? 'on' : 'off');
    if (in_array('login_days', $sections, true)) nm_log('settings_login_days_changed', (string)$cfg['login_days']);
    if (in_array('access', $sections, true)) nm_log('settings_access_changed', implode(',', $cfg['allowed_ips']));
    if (in_array('advanced', $sections, true)) nm_log('settings_hotlink_changed', ($cfg['hotlink'] ? 'on ' : 'off ') . implode(',', $cfg['hotlink_allow']));
}

/**
 * The settings screen's save button: every changed block is checked first, and nothing is
 * written unless all of them pass. The page sends only the changed blocks (sections[]);
 * without JavaScript every block's marker is sent.
 */
function nm_settings_save_all(array $cfg): never
{
    $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $raw = $_POST['sections'] ?? [];
    $asked = is_array($raw) ? array_filter($raw, 'is_string') : [];
    $sections = array_values(array_filter(array_keys(NM_SETTINGS_SECTIONS), static fn($k) => in_array($k, $asked, true)));
    $tab = nm_str($_POST, 'tab', 20);
    $at = '';
    try {
        // 1. Check every block; nothing is written yet.
        $next = $cfg;
        foreach (['admin_lang', 'lscache', 'login_days', 'access', 'general', 'page', 'advanced'] as $key) {
            if (!in_array($key, $sections, true)) continue;
            // Without LiteSpeed the switch is disabled and absent; keep the stored value.
            if ($key === 'lscache' && !nm_lscache_server()) continue;
            $at = $key;
            $next = ('nm_settings_' . $key)($next);
        }
        if (in_array('log_guard', $sections, true)) { $at = 'log_guard'; $next = nl_guard_input($next); }
        $changes = [];
        foreach (['log_preferences' => 'nl_preferences_input', 'log_display' => 'nl_display_input', 'log_seo' => 'nl_seo_input', 'log_footer' => 'nl_footer_input', 'log_warning' => 'nl_warning_presets_input'] as $key => $fn) {
            if (in_array($key, $sections, true)) { $at = $key; $changes[] = $fn(); }
        }
        $design = null;
        if (in_array('log_design', $sections, true)) { $at = 'log_design'; $design = nl_design_input(); }
        $sidebar = null;
        if (in_array('log_sidebar', $sections, true)) { $at = 'log_sidebar'; $sidebar = nl_sidebar_input(); }
        $topmenu = null;
        if (in_array('log_topmenu', $sections, true)) { $at = 'log_topmenu'; $topmenu = nl_topmenu_input(); }
        $footmenu = null;
        if (in_array('log_footmenu', $sections, true)) { $at = 'log_footmenu'; $footmenu = nl_menu_input('foot'); if (isset($_POST['footer_home_form'])) $changes[] = nl_footer_home_input(); }
        // 2. Pictures: an image that cannot be read stops here, before any setting changes.
        if ($design !== null) {
            $at = 'log_design';
            $uploads = nl_design_uploads();
            $changes[] = static fn(array $s) => nl_design_apply($s, $design, $uploads);
        }
        // 3. Write.
        $at = '';
        if ($next !== $cfg) nm_save_config($next);
        if ($changes) nl_settings_update($changes);
        if ($sidebar !== null) { $at = 'log_sidebar'; nl_sidebar_save($sidebar[0], $sidebar[1]); }
        if ($topmenu !== null) { $at = 'log_topmenu'; nl_topmenu_save($topmenu[0], $topmenu[1]); }
        if ($footmenu !== null) { $at = 'log_footmenu'; nl_menu_save('foot', $footmenu[0], $footmenu[1]); }
    } catch (Throwable $e) {
        $message = $e instanceof UnexpectedValueException ? nm_t($e->getMessage()) : nm_t('保存できませんでした。空き容量や書き込み権限を確認してください');
        nm_log('settings_error', $at . ' ' . $e->getMessage());
        $label = NM_SETTINGS_SECTIONS[$at][0] ?? '';
        $message = $label !== '' ? nm_t('「{section}」: {message}。ほかの設定も保存していません', ['section' => nm_t($label), 'message' => $message]) : nm_t('{message}。ほかの設定も保存していません', ['message' => $message]);
        if ($json) nm_json(['ok' => false, 'error' => $message, 'section' => $at], 422);
        nm_flash('err', $message);
        nm_redirect('p=settings&section=' . (NM_SETTINGS_SECTIONS[$at][1] ?? (in_array($tab, ['manga', 'log', 'common'], true) ? $tab : 'log')));
    }
    if (in_array('login_days', $sections, true)) nm_session_keep();
    nm_settings_logged($next, $sections);
    $labels = array_map(static fn($k) => nm_t(NM_SETTINGS_SECTIONS[$k][0]), $sections);
    nm_flash('ok', $sections ? nm_t('設定を保存しました（{sections}）', ['sections' => implode(nm_t('、'), $labels)]) : nm_t('変更はありませんでした'));
    if ($json) nm_json(['ok' => true]);
    nm_redirect('p=settings&section=' . (in_array($tab, ['manga', 'log', 'common'], true) ? $tab : 'log'));
}

/** Guest entrance (the plugin decides whether a key is needed). */
function nm_guest_enter(array $g): never
{
    $key = nm_str($_GET, 'guest', 64);
    if ($g['enter_key'] !== '' && !hash_equals($g['enter_key'], $key)) nm_not_found();
    // A logged-in admin stays admin
    if (nm_is_logged_in() && !nm_is_guest()) nm_redirect();
    // Each entrance makes a session: keep bots from filling the disk
    if (nm_rate_hit('guest', nm_client_ip(), 3600) > 60) {
        nm_log('guest_rate_limited');
        nm_not_found();
    }
    nm_rate_gc();
    nm_start_guest();
    nm_redirect();
}

function nm_guest_refuse(): never
{
    $do = nm_str($_POST, 'do', 30);
    nm_log('guest_write_blocked', $do);
    $msg = nm_t('ゲスト（閲覧のみ）なので変更できません');
    if ($do === 'upload' || $do === 'order' || str_starts_with($do, 'log_')) nm_json(['ok' => false, 'error' => $msg], 403);
    nm_flash('err', $msg);
    $id = nm_str($_POST, 'id', 12);
    nm_redirect(nm_valid_id($id) ? 'p=work&id=' . $id : '');
}

function nm_view_guest_denied(string $what): void
{
    nm_layout($what, '<section class="card"><h1>' . h($what) . '</h1><p>' . nm_t('ゲスト（閲覧のみ）では{what}の画面は表示できません。', ['what' => h($what)]) . '</p><p>' . nm_t('<a href="index.php">作品一覧へ戻る</a>') . '</p></section>');
}

function nm_direction(string $v): string
{
    return in_array($v, ['rtl', 'ltr', 'vertical'], true) ? $v : 'rtl';
}

/** @return string[] */
function nm_lines(string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $s) ?: [])));
}

/**
 * Load, change and save a work under its lock. Errors thrown as
 * UnexpectedValueException are shown to the admin.
 */
function nm_modify_work(string $id, callable $fn, bool $json = false): void
{
    if (!nm_valid_id($id) || !nm_load_work($id)) nm_not_found();
    try {
        nm_with_lock('work-' . $id, static function () use ($id, $fn) {
            $w = nm_load_work($id);
            if (!$w) throw new UnexpectedValueException(nm_t('作品が見つかりません'));
            nm_save_work($fn($w));
        });
    } catch (UnexpectedValueException $e) {
        if ($json) nm_json(['ok' => false, 'error' => nm_t($e->getMessage())], 400);
        nm_flash('err', $e->getMessage());
        nm_redirect('p=work&id=' . $id);
    }
}

function nm_upload_page(string $id, array $cfg): never
{
    if (!nm_valid_id($id) || !($work = nm_load_work($id))) nm_not_found();
    $f = $_FILES['page'] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || is_array($f['name'] ?? null)) {
        nm_json(['ok' => false, 'error' => nm_t('ファイルを受け取れませんでした')], 400);
    }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => nm_t('サーバーの上限より大きいファイルです'),
            UPLOAD_ERR_PARTIAL => nm_t('途中で切れました。もう一度試してください'),
            default => nm_t('アップロードに失敗しました'),
        };
        nm_json(['ok' => false, 'error' => $msg], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) nm_not_found();

    // Original name only for display / sorting, never for the file system
    $orig = basename(str_replace('\\', '/', (string)$f['name']));
    $orig = mb_substr((string)preg_replace('/[\x00-\x1F\x7F<>"]/u', '', mb_check_encoding($orig, 'UTF-8') ? $orig : ''), 0, 200);

    $dir = nm_work_dir($id) . '/pages';
    $res = nm_import_image($f['tmp_name'], $dir, count($work['pages']) + 1,
        (int)($cfg['image_quality'] ?? 90), (int)($cfg['max_upload_mb'] ?? 30) * 1024 * 1024);
    if (is_string($res)) {
        nm_log('upload_rejected', "$id " . $orig . ' ' . $res);
        nm_json(['ok' => false, 'error' => nm_t($res)], 400);
    }

    $count = nm_with_lock('work-' . $id, static function () use ($id, $res, $orig, $dir) {
        $w = nm_load_work($id);
        if (!$w) {
            nm_delete_page_files($dir, $res['f']);
            return -1;
        }
        $w['pages'][] = ['f' => $res['f'], 'w' => $res['w'], 'h' => $res['h'], 'o' => $orig];
        $w['seq'] = (int)($w['seq'] ?? 0) + 1;
        nm_save_work($w);
        return count($w['pages']);
    });
    if ($count < 0) nm_not_found();
    nm_json(['ok' => true, 'count' => $count]);
}

/**
 * Light (small) version of one page. The browser sends the files in name
 * order and tells which page each one belongs to.
 */
function nm_upload_light(string $id, array $cfg): never
{
    if (!nm_valid_id($id) || !($work = nm_load_work($id))) nm_not_found();
    $target = nm_str($_POST, 'f', 40);
    $index = null;
    foreach ($work['pages'] as $i => $p) {
        if ($p['f'] === $target) $index = $i;
    }
    if ($index === null) nm_json(['ok' => false, 'error' => nm_t('ページが更新されています。再読み込みしてください')], 400);

    $f = $_FILES['page'] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) {
        nm_json(['ok' => false, 'error' => nm_t('ファイルを受け取れませんでした')], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) nm_not_found();

    $dir = nm_work_dir($id) . '/pages';
    $res = nm_import_image($f['tmp_name'], $dir, $index + 1, (int)($cfg['image_quality'] ?? 90),
        (int)($cfg['max_upload_mb'] ?? 30) * 1024 * 1024, false);
    if (is_string($res)) {
        nm_log('upload_rejected', "$id light " . $res);
        nm_json(['ok' => false, 'error' => nm_t($res)], 400);
    }
    $ok = nm_with_lock('work-' . $id, static function () use ($id, $target, $res, $dir) {
        $w = nm_load_work($id);
        foreach (($w['pages'] ?? []) as $i => $p) {
            if ($p['f'] !== $target) continue;
            if (isset($p['m']['f'])) nm_delete_page_files($dir, $p['m']['f']);
            $w['pages'][$i]['m'] = $res;
            nm_save_work($w);
            return true;
        }
        nm_delete_page_files($dir, $res['f']);
        return false;
    });
    if (!$ok) nm_json(['ok' => false, 'error' => nm_t('ページが更新されています。再読み込みしてください')], 400);
    nm_json(['ok' => true]);
}

/** Flash message for a light EPUB import. */
function nm_flash_light_result(array|string $res): void
{
    if (is_string($res)) {
        nm_log('epub_light_rejected', $res);
        nm_flash('err', nm_t('小容量版: {message}', ['message' => nm_t($res)]));
        return;
    }
    $msg = nm_t('小容量版を {n} ページに割り当てました', ['n' => $res['attached']]);
    if ($res['epubPages'] !== $res['pages']) {
        $msg .= nm_t('（作品は {pages} ページ、小容量版の EPUB は {epub} ページで、数が違います。順番を確認してください）', ['pages' => $res['pages'], 'epub' => $res['epubPages']]);
    }
    nm_flash($res['epubPages'] === $res['pages'] && $res['attached'] === $res['pages'] ? 'ok' : 'err', $msg);
}

function nm_restore_upload(): never
{
    $f = $_FILES['backup'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        nm_flash('err', nm_t('バックアップファイルを受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）'));
        nm_redirect('p=backup');
    }
    $res = nm_backup_restore((string)$f['tmp_name'], !empty($_POST['overwrite']));
    nm_log('backup_restore', json_encode($res, JSON_UNESCAPED_UNICODE) ?: '');
    if ($res['restored'] || $res['skipped']) {
        nm_flash('ok', "復元しました: {$res['restored']} 作品" . ($res['skipped'] ? "（既にある {$res['skipped']} 作品はそのままにしました）" : ''));
    }
    foreach ($res['errors'] as $e) nm_flash('err', $e);
    if (!$res['restored'] && !$res['skipped'] && !$res['errors']) nm_flash('err', nm_t('復元できる作品がありませんでした'));
    nm_redirect('p=backup');
}

// ===========================================================================
// Views
// ===========================================================================

function nm_thumb_url(array $w, ?array $p = null): string
{
    $p ??= $w['pages'][0] ?? null;
    return $p ? 'index.php?p=img&id=' . $w['id'] . '&f=' . rawurlencode($p['f']) : '';
}

function nm_direction_label(string $d): string
{
    return ['rtl' => nm_t('右から左（漫画）'), 'ltr' => nm_t('左から右'), 'vertical' => nm_t('縦読み')][$d] ?? $d;
}

function nm_direction_options(string $cur): string
{
    $out = '';
    foreach (['rtl', 'ltr', 'vertical'] as $d) {
        $out .= '<option value="' . $d . '"' . ($cur === $d ? ' selected' : '') . '>' . h(nm_direction_label($d)) . '</option>';
    }
    return $out;
}

function nm_view_dashboard(): void
{
    $works = nm_list_works();
    // Group by series, keep newest-first inside
    usort($works, static fn($a, $b) => [$a['series'] ?? '', -($a['created'] ?? 0)] <=> [$b['series'] ?? '', -($b['created'] ?? 0)]);

    $rows = '';
    $lastSeries = null;
    foreach ($works as $w) {
        $series = (string)($w['series'] ?? '');
        if ($series !== $lastSeries) {
            $rows .= '<h2 class="series">' . h($series !== '' ? $series : nm_t('シリーズなし')) . '</h2>';
            $lastSeries = $series;
        }
        $thumb = nm_thumb_url($w);
        $rows .= '<a class="work" href="index.php?p=work&amp;id=' . h($w['id']) . '">'
            . ($thumb ? '<img src="' . h($thumb) . '" alt="" loading="lazy">' : '<span class="noimg">' . nm_t('画像なし') . '</span>')
            . '<span class="work-title">' . h($w['title']) . (nm_work_is_locked($w) ? ' <span class="badge">' . nm_t('パスワード') . '</span>' : '') . '</span>'
            . '<span class="work-meta">' . nm_t('{n} ページ・{direction}', ['n' => count($w['pages']), 'direction' => h(nm_t(nm_direction_label($w['direction'] ?? 'rtl')))]) . '</span></a>';
    }
    if ($rows === '') $rows = '<p class="note">' . nm_t('まだ作品がありません。上のフォームから作成してください。') . '</p>';

    if (nm_is_guest()) {
        nm_layout(nm_t('作品一覧'), '<section class="works">' . $rows . '</section>');
        return;
    }
    nm_layout(nm_t('作品一覧'), nm_update_banner()
        . '<section class="card"><h1>' . nm_t('作品を作る') . '</h1>'
        . '<form method="post" action="index.php" class="form row">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="create">'
        . '<label>' . nm_t('タイトル') . '<input name="title" required maxlength="200" placeholder="' . nm_t('例: 第1話 はじまり') . '"></label>'
        . '<label>' . nm_t('シリーズ名（任意）') . '<input name="series" maxlength="200" placeholder="' . nm_t('例: ねこの日々') . '"></label>'
        . '<label>' . nm_t('読み方') . '<select name="direction">' . nm_direction_options('rtl') . '</select></label>'
        . '<button class="btn primary">' . nm_t('作成') . '</button></form>'
        . '<h2>' . nm_t('EPUB から作る') . '</h2>'
        . '<p class="note">' . nm_t('CLIP STUDIO PAINT などで書き出した漫画の EPUB（画像のページだけのもの）から、ページの順番・読む向き・タイトルをそのまま取り込みます。DRM 付き・文章だけの EPUB は読めません。') . '</p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form row">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="epub">'
        . '<label>' . nm_t('EPUB ファイル（通常版）') . '<input type="file" name="epub" accept=".epub,application/epub+zip" required></label>'
        . '<label>' . nm_t('小容量版 EPUB（任意・スマホ用）') . '<input type="file" name="epub_light" accept=".epub,application/epub+zip"></label>'
        . '<label>' . nm_t('タイトル（空欄なら EPUB のタイトル）') . '<input name="title" maxlength="200"></label>'
        . '<label>' . nm_t('シリーズ名（任意）') . '<input name="series" maxlength="200"></label>'
        . '<button class="btn primary">' . nm_t('取り込む') . '</button></form>'
        . '<p class="note">' . nm_t('サーバーのアップロード上限: {max}（これより大きい EPUB は取り込めません）', ['max' => h((string)ini_get('upload_max_filesize'))]) . '</p></section>'
        . '<p class="warn js-probe" hidden data-probe="../data/probe.txt">' . nm_t('data フォルダがインターネットから見える状態です。.htaccess が効いていない可能性があります。README の「data フォルダを守る」を確認してください。') . '</p>'
        . '<section class="works">' . $rows . '</section>');
}

function nm_view_work(string $id): void
{
    $w = nm_load_work($id);
    if (!$w) nm_not_found();

    $base = nm_base_url();
    $endpoint = $base . '/read.php';
    $viewerPath = NM_ROOT . '/viewer/NagiManga.js';
    $viewerVer = nm_asset_version($viewerPath);
    $script = $base . '/viewer/NagiManga.js?v=' . $viewerVer;
    $locked = nm_work_is_locked($w);

    $guest = nm_is_guest();
    $pages = '';
    foreach ($w['pages'] as $i => $p) {
        $pages .= '<li class="page"' . ($guest ? '' : ' draggable="true"') . ' data-f="' . h($p['f']) . '">'
            . '<img src="' . h(nm_thumb_url($w, $p)) . '" alt="" loading="lazy">'
            . '<span class="page-no">' . ($i + 1) . '</span>'
            . (isset($p['m']) ? '<span class="page-light" title="' . nm_t('小容量版あり（{w}×{h}）', ['w' => (int)$p['m']['w'], 'h' => (int)$p['m']['h']]) . '">' . nm_t('小') . '</span>' : '')
            . '<span class="page-name" title="' . h($p['o'] ?? '') . '">' . h(($p['o'] ?? '') !== '' ? $p['o'] : $p['f']) . '</span>'
            . ($guest ? '' : '<form method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('このページを削除しますか？') . '">' . nm_csrf_field()
            . '<input type="hidden" name="do" value="delpage"><input type="hidden" name="id" value="' . h($w['id']) . '">'
            . '<input type="hidden" name="f" value="' . h($p['f']) . '"><button class="btn small danger">' . nm_t('削除') . '</button></form>')
            . '</li>';
    }

    $idH = h($w['id']);
    $hidden = nm_csrf_field() . '<input type="hidden" name="id" value="' . $idH . '">';

    nm_layout($w['title'], '<p class="crumb">' . nm_t('<a href="index.php">作品一覧</a> /') . ' ' . h($w['title']) . '</p>'

        // --- Share ---
        . '<section class="card"><h1>' . h($w['title']) . '</h1>'
        . '<p>' . nm_t('ID（ハッシュ）:') . ' <code>' . $idH . '</code>' . nm_t('・{n} ページ', ['n' => count($w['pages'])])
        . ($locked ? nm_t('・') . '<span class="badge">' . nm_t('パスワード付き') . '</span>' : nm_t('・誰でも読めます')) . '</p>'
        . '<div class="share js-share" data-id="' . $idH . '" data-endpoint="' . h($endpoint) . '" data-script="' . h($script) . '" data-title="' . h($w['title']) . '">'
        . '<h2>' . nm_t('共有リンク') . '</h2>'
        . '<p class="note">' . nm_t('note・アメブロ・Instagram・X・てがろぐなどには、このリンクを貼ります。押すと、この作品を読むページが開きます。') . '</p>'
        . '<div class="copy-box"><input class="copy-src wide js-share-url" readonly> <button type="button" class="btn small primary js-copy">' . nm_t('コピー') . '</button></div>'
        . nm_page_public_html($w, $guest)
        . '<details class="more"><summary>' . nm_t('くわしい設定（読み方・ブログ用の HTML タグ・試し読み）') . '</summary>'
        . '<div class="form row">'
        . '<label>' . nm_t('読み方') . '<select class="js-share-dir">' . nm_direction_options((string)($w['direction'] ?? 'rtl')) . '</select></label>'
        . '<label>' . nm_t('見開き') . '<select class="js-share-view"><option value="auto">' . nm_t('横長の画面なら見開き') . '</option><option value="single">' . nm_t('常に 1 ページ') . '</option></select></label>'
        . '<label>' . nm_t('縦読みの種類') . '<select class="js-share-vfit"><option value="page">' . nm_t('ページ漫画（1 ページが画面に収まる）') . '</option><option value="webtoon">' . nm_t('ウェブトゥーン（横幅いっぱいの長い絵）') . '</option></select></label>'
        . '<label>' . nm_t('表紙') . '<select class="js-share-cover"><option value="1">' . nm_t('1 ページ目は単独（表紙）') . '</option><option value="0">' . nm_t('1 ページ目から見開き') . '</option></select></label>'
        . '<label>' . nm_t('リンクの文字') . '<input class="js-share-label" maxlength="100" value="' . h(nm_t('{title}を読む', ['title' => $w['title']])) . '"></label>'
        . '</div>'
        . '<textarea class="copy-src js-share-out" readonly rows="4"></textarea>'
        . '<p><button type="button" class="btn js-copy">' . nm_t('コピー') . '</button> '
        // Preview through a relative URL: the admin may be opened under another host name than base_url
        . '<a href="#" class="btn js-preview" data-nagimanga="' . $idH . '" data-endpoint="../read.php">' . nm_t('ここで試し読み') . '</a></p>'
        . '<p class="note">' . nm_t('上の欄は、ブログなど HTML を書ける場所に貼るタグです。クリックしたときにその場でビューアーが開きます。<code>&lt;script&gt;</code> の行は 1 ページに 1 回で十分です。読み方の選択は、共有リンクにも反映されます。') . '</p>'
        . '<p class="note">' . nm_t('てがろぐでは、共有リンクを <code>[第1話を読む]URL</code> のように投稿します（設定のしかたは「<a href="index.php?p=embed">設置用コード</a>」にあります）。') . '</p>'
        . '<p class="note">' . nm_t(empty(nm_config()['base_url']) ? 'URL（{base}）は、この管理画面を開いているアドレスから自動で作っています。' : 'URL（{base}）は、設定の「公開URL」から作っています。', ['base' => '<code>' . h($base) . '</code>']) . '</p>'
        . '</details>'
        . '</div>'
        . '<script src="' . h('../viewer/NagiManga.js?v=' . $viewerVer) . '" defer></script>'
        . '</section>'

        . ($guest ? nm_work_sections_guest($w, $pages) : nm_work_sections_admin($w, $pages, $hidden, $locked)));
}

/** Individual page state and switch (the switch is not shown to guests). */
function nm_page_public_html(array $w, bool $guest): string
{
    $public = ($w['page'] ?? true) !== false;
    $html = '<div class="page-public">' . nm_t('個別ページ:') . ' <strong>' . ($public ? nm_t('公開中') : nm_t('非公開')) . '</strong>';
    if ($public) {
        $html .= ' <a class="btn small" href="../read.php?nagimanga=' . h($w['id']) . '" target="_blank" rel="noopener">' . nm_t('個別ページを見る') . '</a>';
    }
    if (!$guest) {
        $html .= ' <form method="post" action="index.php" class="inline">' . nm_csrf_field()
            . '<input type="hidden" name="do" value="page_public"><input type="hidden" name="id" value="' . h($w['id']) . '">'
            . '<input type="hidden" name="on" value="' . ($public ? '0' : '1') . '">'
            . '<button class="btn small">' . ($public ? nm_t('非公開にする') : nm_t('公開する')) . '</button></form>';
    }
    $html .= '</div>';
    if (!$public) {
        $html .= '<p class="note">' . nm_t('非公開の間は、共有リンクを直接開いても表示されません。ブログやてがろぐに埋め込んだビューアーは今までどおり読めます。') . '</p>';
    }
    return $html;
}

/** Read-only view of a work for guests: no forms at all. */
function nm_work_sections_guest(array $w, string $pages): string
{
    return '<section class="card" id="pages"><h2>' . nm_t('ページ') . '</h2>'
        . ($pages !== '' ? '<ol class="pages" data-id="' . h($w['id']) . '">' . $pages . '</ol>' : '<p class="note">' . nm_t('まだページがありません。') . '</p>')
        . '</section>'
        . '<section class="card"><h2>' . nm_t('作品の設定') . '</h2><table class="table">'
        . '<tr><td>' . nm_t('タイトル') . '</td><td>' . h($w['title']) . '</td></tr>'
        . '<tr><td>' . nm_t('シリーズ名') . '</td><td>' . h(($w['series'] ?? '') !== '' ? $w['series'] : nm_t('（なし）')) . '</td></tr>'
        . '<tr><td>' . nm_t('既定の読み方') . '</td><td>' . h(nm_direction_label((string)($w['direction'] ?? 'rtl'))) . '</td></tr>'
        . '<tr><td>' . nm_t('パスワード') . '</td><td>' . (nm_work_is_locked($w) ? nm_t('あり（ゲストには表示されません）') : nm_t('なし（誰でも読めます）')) . '</td></tr>'
        . '</table></section>';
}

/** Second upload area: the light (small) version of the pages. */
function nm_light_upload_html(array $w, string $hidden): string
{
    $total = count($w['pages']);
    $have = count(array_filter($w['pages'], static fn($p) => isset($p['m'])));
    return '<div class="drop js-drop-light" data-id="' . h($w['id']) . '" data-csrf="' . h(nm_csrf_token()) . '">'
        . '<h3>' . nm_t('小容量版（スマホ用・任意）') . '</h3>'
        . '<p class="note">' . nm_t('同じページを小さく書き出した画像です。スマホではこちらを読み込み、パソコンや拡大したときは通常版に切り替えます。ファイル名の順に、1 ページ目から順番に割り当てます。') . '</p>'
        . '<p>' . nm_t('ここに画像をドラッグ＆ドロップ、または') . ' <label class="btn">' . nm_t('ファイルを選ぶ') . '<input type="file" class="js-file-light" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden></label></p>'
        . '<progress class="js-progress" max="1" value="0" hidden></progress><p class="js-status note"></p>'
        . '<p class="light-count">' . nm_t('小容量版があるページ:') . ' <strong>' . $have . ' / ' . $total . '</strong></p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form row">' . $hidden
        . '<input type="hidden" name="do" value="epub_light">'
        . '<label>' . nm_t('小容量版 EPUB から割り当てる') . '<input type="file" name="epub_light" accept=".epub,application/epub+zip" required></label>'
        . '<button class="btn">' . nm_t('取り込む') . '</button></form>'
        . ($have ? '<form method="post" action="index.php" class="inline js-confirm" data-confirm="' . nm_t('小容量版をすべて外しますか？（通常版は残ります）') . '">' . $hidden
            . '<input type="hidden" name="do" value="clear_light"><button class="btn small">' . nm_t('小容量版をすべて外す') . '</button></form>' : '')
        . '</div>';
}

function nm_work_sections_admin(array $w, string $pages, string $hidden, bool $locked): string
{
    $idH = h($w['id']);
    return ''
        // --- Pages ---
        . '<section class="card" id="pages"><h2>' . nm_t('ページ') . '</h2>'
        . '<div class="uploads">'
        . '<div class="drop js-drop" data-id="' . $idH . '" data-csrf="' . h(nm_csrf_token()) . '">'
        . '<h3>' . nm_t('通常版（パソコン・拡大用）') . '</h3>'
        . '<p>' . nm_t('ここに画像をドラッグ＆ドロップ、または') . ' <label class="btn">' . nm_t('ファイルを選ぶ') . '<input type="file" class="js-file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden></label></p>'
        . '<label class="check"><input type="checkbox" class="js-sort-after" checked> ' . nm_t('アップロード後にファイル名の順（001, 002, … 010）に並べる') . '</label>'
        . '<progress class="js-progress" max="1" value="0" hidden></progress><p class="js-status note"></p></div>'
        . ($w['pages'] ? nm_light_upload_html($w, $hidden) : '')
        . '</div>'
        . ($pages !== ''
            ? '<p class="note">' . nm_t('ドラッグで並べ替えできます。並べ替えたら「並び順を保存」を押してください。') . '</p>'
              . '<ol class="pages js-pages" data-id="' . $idH . '">' . $pages . '</ol>'
              . '<p><button type="button" class="btn primary js-save-order" hidden>' . nm_t('並び順を保存') . '</button> '
              . '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="sort_name"><button class="btn">' . nm_t('ファイル名の順に並べ替え') . '</button></form></p>'
            : '<p class="note">' . nm_t('まだページがありません。') . '</p>')
        . '</section>'

        // --- Settings ---
        . '<section class="card"><h2>' . nm_t('作品の設定') . '</h2>'
        . '<form method="post" action="index.php" class="form row">' . $hidden . '<input type="hidden" name="do" value="update">'
        . '<label>' . nm_t('タイトル') . '<input name="title" required maxlength="200" value="' . h($w['title']) . '"></label>'
        . '<label>' . nm_t('シリーズ名') . '<input name="series" maxlength="200" value="' . h($w['series'] ?? '') . '"></label>'
        . '<label>' . nm_t('既定の読み方') . '<select name="direction">' . nm_direction_options((string)($w['direction'] ?? 'rtl')) . '</select></label>'
        . '<button class="btn">' . nm_t('保存') . '</button></form></section>'

        // --- Password ---
        . '<section class="card"><h2>' . nm_t('パスワード（限定公開）') . '</h2>'
        . '<p class="note">' . nm_t('設定すると、読む人はリンクをクリックしたときにパスワードを入力します。パスワードを変えると、以前のパスワードで開いていた人も読めなくなります。') . '</p>'
        . nm_current_password_html($w)
        . '<form method="post" action="index.php" class="form row">' . $hidden . '<input type="hidden" name="do" value="setpw">'
        . '<label>' . nm_t($locked ? '新しいパスワード（4 文字以上）' : 'パスワード（4 文字以上）') . '<input type="text" name="password" required minlength="4" maxlength="200" autocomplete="off"></label>'
        . '<button class="btn">' . ($locked ? nm_t('変更') : nm_t('設定する')) . '</button></form>'
        . ($locked ? '<form method="post" action="index.php" class="inline js-confirm" data-confirm="' . nm_t('パスワードを外して、誰でも読めるようにしますか？') . '">' . $hidden . '<input type="hidden" name="do" value="clearpw"><button class="btn">' . nm_t('パスワードを外す') . '</button></form>' : '')
        . '</section>'

        // --- Backup / delete ---
        . '<section class="card"><h2>' . nm_t('バックアップと削除') . '</h2>'
        . '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="backup"><button class="btn">' . nm_t('この作品をバックアップ（ZIP）') . '</button></form>'
        . '<details class="danger-zone"><summary>' . nm_t('作品を削除する') . '</summary>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="delete">'
        . '<label>' . nm_t('確認のため、タイトル「{title}」を入力', ['title' => h($w['title'])]) . '<input name="confirm" required autocomplete="off"></label>'
        . '<button class="btn danger">' . nm_t('完全に削除') . '</button></form></details>'
        . '</section>';
}

function nm_view_backup(): void
{
    $zip = nm_zip_available();
    $max = ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size');
    nm_layout(nm_t('バックアップ'), '<section class="card"><h1>' . nm_t('バックアップ') . '</h1>'
        . ($zip
            ? '<p>' . nm_t('すべての作品（画像と設定）を 1 つの ZIP にまとめてダウンロードします。管理者パスワードなどの設定は含まれません。') . '</p>'
              . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="backup"><button class="btn primary">' . nm_t('すべてをダウンロード') . '</button></form>'
              . '<p class="note">' . nm_t('定期的にダウンロードして、パソコンや外付けディスクに保存しておくと安心です。') . '</p>'
            : '<p class="warn">' . nm_t('このサーバーの PHP には ZipArchive がないため、ここからはバックアップできません。FTP で <code>data/works</code> フォルダをまるごとコピーしてください。') . '</p>')
        . '</section>'
        . '<section class="card"><h2>' . nm_t('復元') . '</h2>'
        . '<p>' . nm_t('このツールでダウンロードしたバックアップ ZIP から作品を戻します。') . '</p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="restore">'
        . '<input type="file" name="backup" accept=".zip,application/zip" required>'
        . '<label class="check"><input type="checkbox" name="overwrite" value="1"> ' . nm_t('同じ作品がある場合は上書きする') . '</label>'
        . '<button class="btn"' . ($zip ? '' : ' disabled') . '>' . nm_t('復元する') . '</button></form>'
        . '<p class="note">' . nm_t('サーバーのアップロード上限: {max}。これより大きいバックアップは作品ごとに分けて復元してください。', ['max' => h($max)]) . '</p>'
        . '</section>' . nl_backup_panel());
}

function nm_view_settings(array $cfg): void
{
    $login = nm_base_url() . '/admin/index.php?k=' . $cfg['login_key'];
    $log = '';
    $logFile = NM_DATA . '/logs/security.log';
    if (is_file($logFile)) {
        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -40);
        $log = h(implode("\n", array_reverse($lines)));
    }
    $hidden = nm_csrf_field();
    $block = 'nl_settings_block';

    $section = nm_str($_GET, 'section', 20);
    if (!in_array($section, ['manga', 'log', 'common'], true)) $section = 'log';
    $tabs = '<h1>' . nm_t('設定') . '</h1><p class="note">' . nm_t('使いたい機能を選んで、必要な項目だけ設定できます。どのタブで変えた設定も、画面の下の「設定を保存」でまとめて保存します。') . '</p><nav class="workspace-tabs" data-settings-tabs aria-label="' . nm_t('設定の種類') . '">';
    foreach (['manga' => 'NagiMANGA', 'log' => 'LOG', 'common' => nm_t('共通・安全')] as $key => $label) $tabs .= '<a href="index.php?p=settings&amp;section=' . $key . '" data-settings-tab="' . $key . '"' . ($key === $section ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    $tabs .= '</nav>';
    $panel = static fn($key) => '<div id="settings-' . $key . '" data-settings-panel="' . $key . '"' . ($key === $section ? '' : ' hidden') . '>';
    // One form for every tab. The password and the login URL change at once with their own buttons,
    // so their forms sit after it and their fields join them with form="…".
    nm_layout(nm_t('設定'), $tabs . '<div class="settings-layout" data-settings-layout><nav class="settings-toc" data-settings-toc aria-label="' . nm_t('設定の目次') . '" hidden></nav>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="settings-form" data-settings-form novalidate autocomplete="off">' . $hidden . '<input type="hidden" name="do" value="settings_save_all"><input type="hidden" name="tab" value="' . $section . '">'
        . '<div class="settings-body">' . $panel('log') . nl_preferences_panel() . nl_display_panel() . nl_settings_panel() . nl_seo_panel() . nl_rss_panel() . nl_topmenu_panel() . nl_sidebar_panel() . nl_footer_panel() . nl_footmenu_panel() . nl_warning_presets_panel() . '<section class="card"><h2>' . nm_t('投稿の整理') . '</h2><p>' . nm_t('カテゴリとハッシュタグは、LOG・投稿のページで編集できます。') . '</p>' . nm_t('<a class="btn" href="index.php?p=log&amp;view=taxonomy">カテゴリ・タグを編集</a>') . '</section></div>' . $panel('common')
        . '<section class="card"><h2>' . h(nm_t('管理画面の言語')) . '</h2>' . $block('admin_lang')
        . implode('', array_map(static fn($value, $label) => '<label class="check"><input type="radio" name="admin_lang" value="' . h($value) . '"' . (($cfg['admin_lang'] ?? 'auto') === $value ? ' checked' : '') . '>' . h($label) . '</label>',
            array_keys(['auto' => 0] + nm_langs()), array_merge([nm_t('自動（ブラウザの言語に合わせる）')], array_values(nm_langs()))))
        . '<p class="note">' . h(nm_t('「自動」は、ブラウザの言語がこの中にあればその言語、なければ日本語で表示します。公開ページの言語は変わりません。')) . '</p></div></section>'
        . '<section class="card"><h2>' . nm_t('ログイン URL') . '</h2>'
        . '<p><input class="copy-src wide" readonly value="' . h($login) . '"> <button type="button" class="btn js-copy">' . nm_t('コピー') . '</button></p>'
        . '<p><button class="btn" form="settings-regen-key">' . nm_t('ログイン URL を変更する') . '</button></p><p class="note">' . nm_t('押すとすぐに変わります（「設定を保存」とは別です）。') . '</p></section>'

        . '<section class="card"><h2>LiteSpeed Cache</h2>'
        . '<p>' . (nm_lscache_server() ? nm_t('このサーバーは LiteSpeed です。') . (nm_lscache_active() ? nm_t('公開LOGのページをサーバーでキャッシュしています。') : nm_t('今はキャッシュを使っていません。')) : nm_t('このサーバーは LiteSpeed ではないため、この機能は動きません。設定を変える必要はありません。')) . '</p>'
        . (nm_lscache_server() ? $block('lscache') : '<div class="form">')
        . '<label class="check"><input type="checkbox" name="lscache" value="1"' . (($cfg['lscache'] ?? true) ? ' checked' : '') . (nm_lscache_server() ? '' : ' disabled') . '>' . nm_t('LiteSpeed のサーバーでは、公開LOGのページをキャッシュして速く表示する') . '</label>'
        . '<p class="note">' . nm_t('キャッシュするのは、ログインしていない人が見る公開LOGのページだけです。管理画面、ログイン中の表示、自分専用のMemo、画像、404はキャッシュしません。投稿・画像・漫画・設定を変えると、キャッシュをすぐに消します。カレンダーの「今日」に合わせ、キャッシュは1時間で作り直します。') . '</p>'
        . '</div></section>'

        . '<section class="card"><h2>' . nm_t('ログインを保つ期間') . '</h2>'
        . $block('login_days')
        . '<label class="check"><input type="radio" name="login_days" value="30"' . (nm_login_days() === 30 ? ' checked' : '') . '>' . nm_t('30 日（おすすめ）') . '</label>'
        . '<label class="check"><input type="radio" name="login_days" value="365"' . (nm_login_days() === 365 ? ' checked' : '') . '>' . nm_t('1 年（365 日）') . '</label>'
        . '<p class="note">' . nm_t('最後にログインした日から数えます。期間中はブラウザを閉じてもログインしたままです。共用のパソコンでは使わず、使い終わったらログアウトしてください。パスワードを変えると、ほかの端末はログアウトされます。') . '</p>'
        . '</div></section>'

        . nl_guard_panel()

        . '<section class="card"><h2>' . nm_t('管理画面を開ける場所（IP 制限）') . '</h2>'
        . '<p>' . nm_t('今の IP アドレス:') . ' <code>' . h(nm_client_ip()) . '</code></p>'
        . $block('access')
        . '<label>' . nm_t('許可する IP アドレス（1 行に 1 つ。192.168.0.0/24 のような範囲も可。空欄なら制限なし）') . '<textarea name="allowed_ips" rows="3">' . h(implode("\n", $cfg['allowed_ips'] ?? [])) . '</textarea></label>'
        . '<p class="note">' . nm_t('家庭の回線は IP アドレスが変わることがあります。締め出された場合は、FTP で <code>data/config.php</code> の <code>allowed_ips</code> を空にしてください。') . '</p>'
        . '<label>' . nm_t('別のサイトに埋め込む場合、そのサイトのアドレス（1 行に 1 つ。例: https://blog.example.com）') . '<textarea name="allowed_origins" rows="3">' . h(implode("\n", $cfg['allowed_origins'] ?? [])) . '</textarea></label>'
        . '</div></section>'

        . '<section class="card"><h2>' . nm_t('管理者パスワード') . '</h2>'
        . '<div class="form">'
        . '<label>' . nm_t('今のパスワード') . '<input type="password" name="current" form="settings-password" autocomplete="current-password" required></label>'
        . '<label>' . nm_t('新しいパスワード（10 文字以上）') . '<input type="password" name="password" form="settings-password" autocomplete="new-password" required minlength="10"></label>'
        . '<label>' . nm_t('もう一度') . '<input type="password" name="password2" form="settings-password" autocomplete="new-password" required minlength="10"></label>'
        . '<p class="note">' . nm_t('パスワードは、このボタンですぐに変わります（「設定を保存」とは別です）。') . '</p>'
        . '<p><button class="btn" form="settings-password">' . nm_t('パスワードを変更') . '</button></p></div></section>'

        . '<section class="card"><h2>' . nm_t('設置先と画像アップロード') . '</h2>'
        . $block('general')
        . '<label>' . nm_t('公開URL（共有リンク・OGPに使います。設置先を入力し、末尾にlog.phpは付けません。空欄ならアクセス時のアドレスから作ります）') . '<input name="base_url" value="' . h((string)($cfg['base_url'] ?? '')) . '" placeholder="' . h(nm_detect_base_url()) . '"></label>'
        . '<label>' . nm_t('画像の画質（60〜100）') . '<input type="number" name="image_quality" min="60" max="100" value="' . (int)($cfg['image_quality'] ?? 90) . '"></label>'
        . '<label>' . nm_t('1 枚あたりの上限（MB）') . '<input type="number" name="max_upload_mb" min="1" max="200" value="' . (int)($cfg['max_upload_mb'] ?? 30) . '"></label>'
        . '<p class="note">' . nm_t('サーバー側の上限:') . ' upload_max_filesize ' . h((string)ini_get('upload_max_filesize')) . ' / memory_limit ' . h((string)ini_get('memory_limit')) . '</p>'
        . '</div></section>'

        . '<section class="card"><details><summary>' . nm_t('セキュリティログを見る') . '</summary>' . ($log !== '' ? '<pre class="log">' . $log . '</pre>' : '<p class="note">' . nm_t('記録はまだありません。') . '</p>') . '</details></section></div>' . $panel('manga')
        . '<section class="card"><h2>' . nm_t('NagiMANGAの作品ページ') . '</h2>'
        . '<p>' . nm_t('作品ごとの共有リンク（{url}）を開くと、その作品を読むページが開きます。note・アメブロ・Instagram・X などに貼ると、表紙付きのカードで表示されます（パスワード付きの作品は表紙を出しません）。', ['url' => '<code>read.php?nagimanga=…</code>']) . '</p>'
        . $block('page')
        . '<label>' . nm_t('「戻る」ボタンの行き先（空欄なら自動：来たページに戻ります。わからないときは空欄のままで大丈夫です）') . '<input name="page_back" value="' . h((string)($cfg['page_back'] ?? '')) . '" placeholder="' . nm_t('例: https://example.com/') . '"></label>'
        . '</div></section>'

        . '<section class="card"><details class="more"><summary>' . nm_t('上級者向けの設定') . '</summary>'
        . '<h3>' . nm_t('直リンク防止') . '</h3>'
        . '<p>' . nm_t('オンにすると、漫画の画像は「このサイト」「別のサイトに埋め込む場合に登録したサイト」「下に書いたサイト」のページからしか読めなくなります。画像の URL を直接開いたり、ほかのサイトに貼られたりしたときは表示しません（サーバーの通信量の節約になります）。') . '</p>'
        . '<p class="note">' . nm_t('共有リンクで開く個別ページ、リンクのカード用の表紙は、オンでもそのまま使えます。サイト単位（例: https://note.com）で書いてください。ブラウザは、ほかのサイトからの読み込みでは URL の途中（/ユーザー名/ など）を送らないため、途中までの一致では判定できません。') . '</p>'
        . $block('advanced')
        . '<label class="check"><input type="checkbox" name="hotlink" value="1"' . (!empty($cfg['hotlink']) ? ' checked' : '') . '> ' . nm_t('直リンク防止を使う') . '</label>'
        . '<label>' . nm_t('ほかに読み込みを許可するサイト（1 行に 1 つ）') . '<textarea name="hotlink_allow" rows="3">' . h(implode("\n", $cfg['hotlink_allow'] ?? [])) . '</textarea></label>'
        . '</div>'
        . '</details></section>'

        . '</div></div>'
        . '<div class="settings-savebar" data-settings-savebar><p class="settings-savebar-status" data-settings-status role="status" aria-live="polite">' . nm_t('すべてのタブの設定を、このボタンでまとめて保存します。') . '</p><button class="btn primary settings-save" data-settings-save>' . nm_t('設定を保存') . '</button></div>'
        . '</form></div>'
        . '<form id="settings-password" method="post" action="index.php">' . $hidden . '<input type="hidden" name="do" value="settings_pw"></form>'
        . '<form id="settings-regen-key" method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('ログイン URL を変更しますか？今のブックマークは使えなくなります。') . '">' . $hidden . '<input type="hidden" name="do" value="regen_key"></form>');
}

/** After login: a notice when GitHub has a newer NagiManga (checked at most every 12 hours). */
function nm_update_banner(): string
{
    if (!nm_update_auto(nm_config() ?? [])) return '';
    $st = nm_update_status();
    if (!nm_update_available($st)) return '';
    return '<p class="update-banner"><span>' . nm_t('新しいバージョン {latest} が出ています（今は {current}。NagiLogも一緒に更新します）。', ['latest' => '<strong>NagiManga ' . h((string)$st['latest']) . '</strong>', 'current' => h(NM_VERSION)]) . '</span>'
        . ' <a class="btn small primary" href="index.php?p=update">' . h(nm_t('更新画面へ')) . '</a></p>';
}

/** One copy field. */
function nm_copy_box(string $value, string $label = ''): string
{
    return ($label !== '' ? '<p class="copy-label">' . h($label) . '</p>' : '')
        . '<div class="copy-box"><input class="copy-src wide" readonly value="' . h($value) . '"> <button type="button" class="btn small js-copy">' . nm_t('コピー') . '</button></div>';
}

/** Where to put the viewer: URLs with the cache buster, ready to paste. */
function nm_view_embed(): void
{
    $viewerPath = NM_ROOT . '/viewer/NagiManga.js';
    $ver = nm_asset_version($viewerPath);
    $js = nm_base_url() . '/viewer/NagiManga.js?v=' . $ver;

    nm_layout(nm_t('設置用コード'), '<section class="card"><h1>' . nm_t('設置用コード') . '</h1>'
        . '<p>' . nm_t('ビューアー（NagiManga.js）をサイトに読み込むためのコードです。末尾の {v} は「キャッシュバスター」です。', ['v' => '<code>?v=' . h($ver) . '</code>']) . ' '
        . nm_t('NagiManga を更新すると値が変わり、読む人のブラウザに古いビューアーが残らないようにします。') . '</p>'
        . '<p class="warn">' . nm_t('NagiManga を <strong>更新したら</strong>、この画面を開き直して、新しい値を貼り直してください。') . '</p>'
        . nm_copy_box($js, nm_t('ビューアーの URL（キャッシュバスター付き）'))
        . '</section>'

        . '<section class="card"><h2>' . nm_t('てがろぐで使う') . '</h2>'
        . '<ol class="steps">'
        . '<li>' . nm_t('てがろぐの管理画面で <strong>[設定] → [システム設定] → 【画像拡大スクリプトの選択】</strong> を開き、「他のスクリプトを使う：URLを指定」を選びます。') . '</li>'
        . '<li>' . nm_t('「JavaScriptのURL」欄を、次の 1 行に置き換えて保存します（NagiSwipe の画像拡大と一緒に読み込みます）。「CSSのURL」欄は NagiSwipe のままで大丈夫です。「JavaScriptをモジュールとして読み込む」はオフにしてください。')
        . nm_copy_box(NM_NAGISWIPE_JS . ' ' . $js) . '</li>'
        . '<li>' . nm_t('画像のない投稿でも読み込まれるように、<strong>[設定] → [ページの表示] → 【投稿本文の表示／URL処理】</strong> の「画像リンクに独自のclass属性値を追加する」にチェックを入れ、<code>nagimanga</code> と入力して保存します。') . '</li>'
        . '<li>' . nm_t('作品ページの「共有リンク」をコピーして、<code>[第1話を読む]URL</code> のように投稿します。') . '</li>'
        . '</ol></section>'

        . '<section class="card"><h2>' . nm_t('ブログ・HTML で使う') . '</h2>'
        . '<p>' . nm_t('ページの HTML に、次の 1 行を 1 回だけ貼ります。作品ごとのリンクは、作品ページの「共有用のタグ」を使ってください（その中にもこの行が入っています）。') . '</p>'
        . nm_copy_box('<script src="' . $js . '" defer></script>')
        . '<p class="note">' . nm_t('てがろぐ・ブログと NagiManga を別のドメインに置いている場合は、「設定 → 別のサイトに埋め込む場合」にそのサイトのアドレスを登録してください。') . '</p>'
        . '</section>');
}

/** Version, notice of a newer release, update / undo, and the automatic check switch. */
function nm_view_update(array $cfg): void
{
    $st = nm_update_cached() ?? nm_update_status();
    $newer = nm_update_available($st);
    $hidden = nm_csrf_field();
    $checked = !empty($st['checked']) ? date('Y-m-d H:i', (int)$st['checked']) : '—';
    $transport = nm_update_transport();
    $zip = nm_zip_available();
    $write = nm_update_can_write();
    $canApply = $transport !== '' && $zip && $write;

    $body = '<section class="card"><h1>' . nm_t('更新') . '</h1>'
        . '<p>' . nm_t('今のバージョン: {version}', ['version' => '<strong>NagiManga ' . h(NM_VERSION) . '</strong>']) . '</p>';
    if ($newer) {
        $body .= '<div class="update-new"><h2>' . nm_t('新しいバージョン {v} があります', ['v' => h((string)$st['latest'])]) . '</h2>'
            . (!empty($st['published']) ? '<p class="note">' . nm_t('公開日:') . ' ' . h(substr((string)$st['published'], 0, 10)) . '</p>' : '')
            . (!empty($st['notes']) ? '<div class="notes">' . nm_release_notes_html((string)$st['notes']) . '</div>' : '')
            . (!empty($st['page']) ? '<p><a href="' . h((string)$st['page']) . '" target="_blank" rel="noopener noreferrer">' . nm_t('GitHub のリリースページで見る') . '</a></p>' : '')
            . ($canApply
                ? '<form method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('NagiManga を {v} に更新しますか？作品と設定はそのままです。', ['v' => h((string)$st['latest'])]) . '">' . $hidden
                  . '<input type="hidden" name="do" value="update_apply"><button class="btn primary">' . nm_t('{v} に更新する', ['v' => h((string)$st['latest'])]) . '</button></form>'
                : '<p class="warn">' . nm_t('このサーバーでは自動で更新できません（下の「このサーバーの状態」を確認してください）。「手動で更新する」の手順で更新できます。') . '</p>')
            . '</div>';
    } elseif (!empty($st['error'])) {
        $body .= '<p class="warn">' . h((string)$st['error']) . '</p>';
    } else {
        $body .= '<p>' . nm_t('最新のバージョンです。') . '</p>';
    }
    $body .= '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="update_check">'
        . '<button class="btn">' . nm_t('今すぐ確認') . '</button></form> <span class="note">' . nm_t('最後に確認した日時:') . ' ' . h($checked) . '</span>'
        . '</section>'

        . '<section class="card"><h2>' . nm_t('更新について') . '</h2><ul class="steps">'
        . '<li>' . nm_t('更新するのはプログラムのファイルだけです。作品・設定（<code>data</code> フォルダ）とプラグインはそのままです。') . '</li>'
        . '<li>' . nm_t('更新ファイルは GitHub の公式リリースからダウンロードし、GitHub が公開している確認用の値（SHA-256）と一致したときだけ使います。') . '</li>'
        . '<li>' . nm_t('入れ替える前のファイルは自動で保管されるので、「元に戻す」で戻せます。念のため、先に<a href="index.php?p=backup">作品のバックアップ</a>も取っておくと安心です。') . '</li>'
        . '<li>' . nm_t('更新したあとは、{embed}のキャッシュバスター（{v} の値）を、てがろぐなどに貼り直してください。', ['embed' => '<a href="index.php?p=embed">' . nm_t('設置用コード') . '</a>', 'v' => '<code>?v=</code>']) . '</li>'
        . '</ul>'
        . '<h3>' . nm_t('このサーバーの状態') . '</h3><ul class="checks">'
        . '<li>' . ($transport !== '' ? '○' : '×') . ' ' . nm_t('GitHub への接続（{how}）', ['how' => h($transport !== '' ? $transport : nm_t('PHP の curl も allow_url_fopen も使えません'))]) . '</li>'
        . '<li>' . ($zip ? '○' : '×') . ' ' . nm_t('ZIP の展開（ZipArchive）') . '</li>'
        . '<li>' . ($write ? '○' : '×') . ' ' . nm_t('プログラムのファイルへの書き込み') . ($write ? '' : nm_t('（FTP でファイルの権限を確認してください）')) . '</li>'
        . '</ul>'
        . '<details><summary>' . nm_t('手動で更新する') . '</summary><ol class="steps">'
        . '<li>' . nm_t('{releases}から {file} をダウンロードして展開します。', ['releases' => '<a href="https://github.com/' . h(NM_UPDATE_REPO) . '/releases" target="_blank" rel="noopener noreferrer">' . nm_t('リリースページ') . '</a>', 'file' => '<code>nagimanga-v…zip</code>']) . '</li>'
        . '<li>' . nm_t('中の <code>nagimanga</code> フォルダの中身を、FTP で上書きアップロードします。<strong><code>data</code> フォルダはアップロードしないでください</strong>（作品と設定が消えます）。') . '</li>'
        . '</ol></details></section>';

    $backups = nm_update_backups();
    if ($backups) {
        $body .= '<section class="card"><h2>' . nm_t('元に戻す') . '</h2><p>' . nm_t('更新で入れ替えたファイルを、更新前に戻します。作品と設定はそのままです。') . '</p><ul class="backups">';
        foreach ($backups as $b) {
            $body .= '<li><span>' . h($b['from']) . ' → ' . h($b['to']) . nm_t('（{date} に更新）', ['date' => h(date('Y-m-d H:i', $b['created']))]) . '</span> '
                . '<form method="post" action="index.php" class="inline js-confirm" data-confirm="' . nm_t('NagiManga {v} に戻しますか？', ['v' => h($b['from'])]) . '">' . $hidden
                . '<input type="hidden" name="do" value="update_rollback"><input type="hidden" name="backup" value="' . h($b['name']) . '">'
                . '<button class="btn small">' . nm_t('{v} に戻す', ['v' => h($b['from'])]) . '</button></form></li>';
        }
        $body .= '</ul></section>';
    }

    $auto = nm_update_auto($cfg);
    $body .= '<section class="card"><h2>' . nm_t('自動の確認') . '</h2>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="update_auto">'
        . '<label class="check"><input type="checkbox" name="auto" value="1"' . ($auto ? ' checked' : '') . '> ' . nm_t('ログインしたときに新しいバージョンを確認する（12 時間に 1 回まで、GitHub に接続します）') . '</label>'
        . '<button class="btn">' . nm_t('保存') . '</button></form></section>';

    nm_layout(nm_t('更新'), $body);
}

/** "Current password" row: hidden until the admin presses 表示. */
function nm_current_password_html(array $w): string
{
    if (!nm_work_is_locked($w)) return '';
    $plain = !empty($w['password_enc']) ? nm_unseal((string)$w['password_enc']) : null;
    if ($plain === null) {
        return '<p class="note">' . nm_t('現在のパスワードは表示できません（この機能より前に設定したか、別のサーバーから復元したため）。もう一度設定すると、ここに表示されるようになります。') . '</p>';
    }
    return '<div class="current-pw"><span>' . nm_t('現在のパスワード') . '</span>'
        . '<input class="copy-src js-pw" type="password" readonly value="' . h($plain) . '" aria-label="' . nm_t('現在のパスワード') . '">'
        . '<button type="button" class="btn small js-pw-toggle">' . nm_t('表示') . '</button>'
        . '<button type="button" class="btn small js-copy">' . nm_t('コピー') . '</button></div>';
}

/** Page images for the admin (session required, so locked works preview too). */
function nm_admin_image(string $id, string $file, bool $full): never
{
    $w = nm_valid_id($id) ? nm_load_work($id) : null;
    if (!$w) nm_not_found();
    $path = nm_page_path($w, $file, !$full) ?? nm_page_path($w, $file);
    if ($path === null) nm_not_found();
    header('Content-Type: ' . nm_image_mime($path));
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
