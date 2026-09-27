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
nm_load_plugins();

const NM_SETUP_WINDOW = 1800; // first-run setup must happen within 30 min
// The image-popup script most sites pair with NagiManga (for the Tegalog setting line)
const NM_NAGISWIPE_JS = 'https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.js';

nm_admin_headers();

$cfg = nm_config();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') nm_not_found();

// --- First run -------------------------------------------------------------
if (!$cfg || empty($cfg['admin_hash'])) {
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
    nm_handle_post($cfg);
    exit;
}

$page = nm_str($_GET, 'p', 20);
match ($page) {
    '', 'works' => nm_view_dashboard(),
    'work' => nm_view_work(nm_str($_GET, 'id', 12)),
    'img' => nm_admin_image(nm_str($_GET, 'id', 12), nm_str($_GET, 'f', 40), isset($_GET['full'])),
    'backup' => nm_is_guest() ? nm_view_guest_denied('バックアップ') : nm_view_backup(),
    'settings' => nm_is_guest() ? nm_view_guest_denied('設定') : nm_view_settings($cfg),
    'embed' => nm_is_guest() ? nm_view_guest_denied('設置用コード') : nm_view_embed(),
    'update' => nm_is_guest() ? nm_view_guest_denied('更新') : nm_view_update($cfg),
    default => nm_not_found(),
};
exit;

// ===========================================================================
// Headers / layout
// ===========================================================================

function nm_admin_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Frame-Options: DENY');
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
    $v = is_file($path) ? substr(sha1_file($path), 0, 10) : NM_VERSION;
    return h($file . '?v=' . $v);
}

function nm_layout(string $title, string $body, bool $nav = true): void
{
    $flash = '';
    if (!empty($_SESSION['nm_flash'])) {
        foreach ($_SESSION['nm_flash'] as [$type, $msg]) {
            $flash .= '<p class="flash flash-' . h($type) . '">' . h($msg) . '</p>';
        }
        unset($_SESSION['nm_flash']);
    }
    $navHtml = '';
    $banner = '';
    if ($nav) {
        $guest = nm_is_guest();
        $navHtml = '<nav class="nav"><a href="index.php">作品一覧</a>'
            . ($guest ? '' : '<a href="index.php?p=embed">設置用コード</a><a href="index.php?p=backup">バックアップ</a><a href="index.php?p=settings">設定</a>'
                . '<a href="index.php?p=update">更新' . (nm_update_available(nm_update_cached()) ? ' <span class="badge new">新</span>' : '') . '</a>')
            . '<form method="post" action="index.php" class="inline">' . nm_csrf_field() . '<input type="hidden" name="do" value="logout"><button class="link">' . ($guest ? 'ゲストを終了' : 'ログアウト') . '</button></form></nav>';
        $g = $guest ? nm_guest_config() : null;
        if ($g) $banner = '<p class="guest-banner">' . h($g['banner']) . '</p>';
    }
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . ' - NagiManga</title>'
        . '<link rel="stylesheet" href="' . nm_asset('admin.css') . '">'
        . '<script src="' . nm_asset('admin.js') . '" defer></script>'
        . '</head><body><header class="top"><span class="brand">NagiManga</span>' . $navHtml . '</header>'
        . '<main class="main">' . $banner . $flash . $body . '</main>'
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
        if (mb_strlen($pw) < 10) $errors[] = 'パスワードは 10 文字以上にしてください';
        if ($pw !== $pw2) $errors[] = '確認用のパスワードが一致しません';

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
            nm_layout('セットアップ完了', '<section class="card"><h1>セットアップが完了しました</h1>'
                . '<p><strong>次の URL をブックマークしてください。</strong>この URL 以外からは管理画面を開けません（他の URL は「Not Found」になります）。</p>'
                . '<p><input class="copy-src wide" readonly value="' . h($login) . '"> <button type="button" class="btn js-copy">コピー</button></p>'
                . '<p class="note">忘れた場合は、FTP で <code>data/config.php</code> の <code>login_key</code> を確認し、<code>admin/index.php?k=</code> の後ろに付けてください。</p>'
                . '<p><a class="btn primary" href="' . h($login) . '">ログイン画面へ</a></p></section>', false);
            return;
        }
    }

    $_SESSION['nm_pre'] = bin2hex(random_bytes(32));
    $sup = nm_image_support();
    $checks = [
        ['PHP 8.1 以上', PHP_VERSION_ID >= 80100, PHP_VERSION],
        ['GD（画像処理）', $sup['gd'], ''],
        ['WebP 書き出し', $sup['webp'], $sup['webp'] ? '' : 'JPEG で保存します'],
        ['fileinfo（画像の中身の判定）', $sup['finfo'], ''],
        ['ZipArchive（バックアップ）', nm_zip_available(), nm_zip_available() ? '' : 'バックアップは FTP で data/works をコピーしてください'],
        // The server path is shown only when something must be fixed
        ['data フォルダへの書き込み', is_writable(NM_DATA), is_writable(NM_DATA) ? '' : 'FTP で data フォルダを書き込み可能（755 など）にしてください'],
    ];
    $rows = '';
    foreach ($checks as [$label, $ok, $note]) {
        $rows .= '<tr><td>' . h($label) . '</td><td class="' . ($ok ? 'ok' : 'ng') . '">' . ($ok ? 'OK' : '要確認') . '</td><td>' . h($note) . '</td></tr>';
    }
    $err = '';
    foreach ($errors as $e) $err .= '<p class="flash flash-err">' . h($e) . '</p>';

    nm_layout('セットアップ', $err . '<section class="card"><h1>NagiManga のセットアップ</h1>'
        . '<table class="table">' . $rows . '</table>'
        . '<form method="post" action="index.php" class="form">'
        . '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['nm_pre']) . '">'
        . '<label>管理者パスワード（10 文字以上）<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>'
        . '<label>もう一度<input type="password" name="password2" autocomplete="new-password" required minlength="10"></label>'
        . '<label class="check"><input type="checkbox" name="lock_ip" value="1" checked> 今の IP アドレス（' . h($ip) . '）からだけ管理画面を開けるようにする（推奨。あとで設定から変更できます）</label>'
        . '<button class="btn primary">セットアップする</button></form>'
        . '<p class="note">この画面は、ファイルを置いてから 30 分だけ表示されます。</p></section>', false);
}

// ===========================================================================
// Login
// ===========================================================================

function nm_login_page(string $method, array $cfg): void
{
    $src = $method === 'POST' ? $_POST : $_GET;
    $key = nm_str($src, 'k', 64);
    // Wrong or missing key: the admin does not exist
    if ($key === '' || !hash_equals((string)$cfg['login_key'], $key)) nm_not_found();
    if (nm_login_blocked()) {
        nm_log('login_blocked');
        nm_not_found();
    }

    $error = '';
    if ($method === 'POST') {
        $pre = (string)($_SESSION['nm_pre'] ?? '');
        if ($pre === '' || !hash_equals($pre, (string)($_POST['csrf'] ?? ''))) nm_not_found();
        if (nm_try_login(nm_str($_POST, 'password', 200))) nm_redirect();
        if (nm_login_blocked()) nm_not_found();
        $error = '<p class="flash flash-err">パスワードが違います</p>';
    }

    $_SESSION['nm_pre'] = bin2hex(random_bytes(32));
    nm_layout('ログイン', $error . '<section class="card narrow"><h1>ログイン</h1>'
        . '<form method="post" action="index.php" class="form">'
        . '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['nm_pre']) . '">'
        . '<input type="hidden" name="k" value="' . h($key) . '">'
        . '<label>パスワード<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<button class="btn primary">ログイン</button></form></section>', false);
}

// ===========================================================================
// POST actions
// ===========================================================================

function nm_handle_post(array $cfg): void
{
    $do = nm_str($_POST, 'do', 30);
    $id = nm_str($_POST, 'id', 12);

    switch ($do) {
        case 'logout':
            $wasGuest = nm_is_guest();
            $g = $wasGuest ? nm_guest_config() : null;
            nm_logout();
            $link = $g && $g['exit_url'] !== '' ? '<p><a class="btn primary" href="' . h($g['exit_url']) . '">デモのページへ戻る</a></p>' : '';
            nm_layout('ログアウト', '<section class="card narrow"><h1>' . ($wasGuest ? 'ゲストを終了しました' : 'ログアウトしました') . '</h1>' . $link . '</section>', false);
            exit;

        case 'create':
            $title = trim(nm_str($_POST, 'title', 600));
            if ($title === '' || mb_strlen($title) > 200) {
                nm_flash('err', 'タイトルは 1〜200 文字で入力してください');
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
            nm_flash('ok', '作品を作成しました。ページ画像をアップロードしてください');
            nm_redirect('p=work&id=' . $newId);

        case 'update':
            nm_modify_work($id, static function (array $w) {
                $title = trim(nm_str($_POST, 'title', 600));
                if ($title === '' || mb_strlen($title) > 200) throw new UnexpectedValueException('タイトルは 1〜200 文字で入力してください');
                $w['title'] = $title;
                $w['series'] = mb_substr(trim(nm_str($_POST, 'series', 600)), 0, 200);
                $w['direction'] = nm_direction(nm_str($_POST, 'direction', 10));
                return $w;
            });
            nm_flash('ok', '保存しました');
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
            nm_flash('ok', '小容量版をすべて外しました（通常版だけで表示します）');
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'epub_light':
            if (!nm_valid_id($id) || !nm_load_work($id)) nm_not_found();
            $f = $_FILES['epub_light'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                nm_flash('err', 'EPUB を受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）');
                nm_redirect('p=work&id=' . $id . '#pages');
            }
            nm_flash_light_result(nm_epub_attach_light($id, (string)$f['tmp_name'], $cfg));
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'epub':
            $f = $_FILES['epub'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                nm_flash('err', 'EPUB を受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）');
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
            nm_flash('ok', '「' . $res['title'] . '」を EPUB から作りました（' . $res['count'] . ' ページ'
                . ($res['skipped'] ? '、読めなかった ' . $res['skipped'] . ' ページは飛ばしました' : '') . '）');
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
            nm_flash('ok', 'ファイル名の順に並べ替えました');
            nm_redirect('p=work&id=' . $id);

        case 'order':
            $order = json_decode(nm_str($_POST, 'order', 200000), true);
            nm_modify_work($id, static function (array $w) use ($order) {
                if (!is_array($order)) throw new UnexpectedValueException('並び順を受け取れませんでした');
                $byFile = [];
                foreach ($w['pages'] as $p) $byFile[$p['f']] = $p;
                // Must be exactly the same set of pages, just reordered
                if (count($order) !== count($byFile) || count(array_unique($order)) !== count($order)) {
                    throw new UnexpectedValueException('ページが更新されています。再読み込みしてください');
                }
                $new = [];
                foreach ($order as $f) {
                    if (!is_string($f) || !isset($byFile[$f])) throw new UnexpectedValueException('ページが更新されています。再読み込みしてください');
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
            nm_flash('ok', 'ページを削除しました');
            nm_redirect('p=work&id=' . $id . '#pages');

        case 'setpw':
            $pw = nm_str($_POST, 'password', 200);
            nm_modify_work($id, static function (array $w) use ($pw) {
                if (mb_strlen($pw) < 4) throw new UnexpectedValueException('パスワードは 4 文字以上にしてください');
                $w['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                // Encrypted copy so the admin can look it up again (see nm_seal)
                $w['password_enc'] = nm_seal($pw);
                return $w;
            });
            nm_log('work_password_set', $id);
            nm_flash('ok', 'パスワードを設定しました（以前に配った閲覧用の鍵は無効になります）');
            nm_redirect('p=work&id=' . $id);

        case 'clearpw':
            nm_modify_work($id, static function (array $w) {
                $w['password_hash'] = '';
                $w['password_enc'] = '';
                return $w;
            });
            nm_log('work_password_cleared', $id);
            nm_flash('ok', 'パスワードを外しました（誰でも読めます）');
            nm_redirect('p=work&id=' . $id);

        case 'delete':
            $work = nm_load_work($id);
            if (!$work) nm_not_found();
            if (nm_str($_POST, 'confirm', 600) !== $work['title']) {
                nm_flash('err', '確認のタイトルが一致しないため、削除しませんでした');
                nm_redirect('p=work&id=' . $id);
            }
            nm_with_lock('work-' . $id, static fn() => nm_rmdir_recursive(nm_work_dir($id)));
            nm_log('work_deleted', $id);
            nm_flash('ok', '「' . $work['title'] . '」を削除しました');
            nm_redirect();

        case 'backup':
            nm_log('backup_download', $id);
            nm_backup_download($id !== '' && nm_valid_id($id) ? [$id] : []);

        case 'restore':
            nm_restore_upload();

        case 'update_check':
            $st = nm_update_status(true);
            if ($st['error'] !== '') nm_flash('err', $st['error']);
            elseif (nm_update_available($st)) nm_flash('ok', '新しいバージョン ' . $st['latest'] . ' があります');
            else nm_flash('ok', '最新のバージョンです');
            nm_redirect('p=update');

        case 'update_apply':
            $res = nm_update_apply();
            nm_flash($res['ok'] ? 'ok' : 'err', $res['msg']);
            if ($res['ok']) nm_flash('ok', 'ビューアーのキャッシュバスターが変わりました。「設置用コード」の値に貼り直してください');
            nm_redirect('p=update');

        case 'update_rollback':
            $res = nm_update_rollback(nm_str($_POST, 'backup', 60));
            nm_flash($res['ok'] ? 'ok' : 'err', $res['msg']);
            nm_redirect('p=update');

        case 'update_auto':
            $cfg['update_check'] = !empty($_POST['auto']);
            nm_save_config($cfg);
            nm_flash('ok', $cfg['update_check'] ? 'ログイン時に新しいバージョンを確認します' : 'ログイン時の確認をやめました（更新画面から手動で確認できます）');
            nm_redirect('p=update');

        case 'settings_pw':
            $cur = nm_str($_POST, 'current', 200);
            $new = nm_str($_POST, 'password', 200);
            $new2 = nm_str($_POST, 'password2', 200);
            if (!password_verify($cur, (string)$cfg['admin_hash'])) {
                nm_log('admin_password_change_failed');
                nm_flash('err', '今のパスワードが違います');
            } elseif (mb_strlen($new) < 10 || $new !== $new2) {
                nm_flash('err', '新しいパスワードは 10 文字以上で、確認用と同じにしてください');
            } else {
                $cfg['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
                nm_save_config($cfg);
                $_SESSION['nm_ver'] = nm_admin_version();
                nm_log('admin_password_changed');
                nm_flash('ok', '管理者パスワードを変更しました（他の端末はログアウトされます）');
            }
            nm_redirect('p=settings');

        case 'settings_access':
            $ips = nm_lines(nm_str($_POST, 'allowed_ips', 5000));
            foreach ($ips as $r) {
                if (!nm_valid_ip_rule($r)) {
                    nm_flash('err', 'IP アドレスの書き方が正しくありません: ' . $r);
                    nm_redirect('p=settings');
                }
            }
            // Never lock yourself out with one click
            $me = nm_client_ip();
            if ($ips && !array_filter($ips, static fn($r) => nm_ip_match($me, $r))) {
                nm_flash('err', '今の IP アドレス（' . $me . '）が含まれていないため保存しませんでした');
                nm_redirect('p=settings');
            }
            $origins = [];
            foreach (nm_lines(nm_str($_POST, 'allowed_origins', 5000)) as $o) {
                $o = rtrim($o, '/');
                if (!preg_match('~\Ahttps?://[a-z0-9.\-]+(:[0-9]{1,5})?\z~i', $o)) {
                    nm_flash('err', 'サイトの書き方が正しくありません（例: https://example.com）: ' . $o);
                    nm_redirect('p=settings');
                }
                $origins[] = strtolower($o);
            }
            $cfg['allowed_ips'] = $ips;
            $cfg['allowed_origins'] = array_values(array_unique($origins));
            nm_save_config($cfg);
            nm_log('settings_access_changed', implode(',', $ips));
            nm_flash('ok', 'アクセス設定を保存しました');
            nm_redirect('p=settings');

        case 'settings_general':
            $base = rtrim(trim(nm_str($_POST, 'base_url', 500)), '/');
            if ($base !== '' && !preg_match('~\Ahttps?://[^\s"\'<>]+\z~i', $base)) {
                nm_flash('err', '設置 URL の書き方が正しくありません');
                nm_redirect('p=settings');
            }
            $cfg['base_url'] = $base;
            $cfg['image_quality'] = max(60, min(100, (int)($_POST['image_quality'] ?? 90)));
            $cfg['max_upload_mb'] = max(1, min(200, (int)($_POST['max_upload_mb'] ?? 30)));
            nm_save_config($cfg);
            nm_flash('ok', '保存しました');
            nm_redirect('p=settings');

        case 'regen_key':
            $cfg['login_key'] = nm_random_id(24);
            nm_save_config($cfg);
            nm_log('login_key_changed');
            nm_flash('ok', 'ログイン URL を変更しました。新しい URL をブックマークし直してください');
            nm_redirect('p=settings');
    }
    nm_not_found();
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
    $msg = 'ゲスト（閲覧のみ）なので変更できません';
    if ($do === 'upload' || $do === 'order') nm_json(['ok' => false, 'error' => $msg], 403);
    nm_flash('err', $msg);
    $id = nm_str($_POST, 'id', 12);
    nm_redirect(nm_valid_id($id) ? 'p=work&id=' . $id : '');
}

function nm_view_guest_denied(string $what): void
{
    nm_layout($what, '<section class="card"><h1>' . h($what) . '</h1><p>ゲスト（閲覧のみ）では' . h($what) . 'の画面は表示できません。</p><p><a href="index.php">作品一覧へ戻る</a></p></section>');
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
            if (!$w) throw new UnexpectedValueException('作品が見つかりません');
            nm_save_work($fn($w));
        });
    } catch (UnexpectedValueException $e) {
        if ($json) nm_json(['ok' => false, 'error' => $e->getMessage()], 400);
        nm_flash('err', $e->getMessage());
        nm_redirect('p=work&id=' . $id);
    }
}

function nm_upload_page(string $id, array $cfg): never
{
    if (!nm_valid_id($id) || !($work = nm_load_work($id))) nm_not_found();
    $f = $_FILES['page'] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || is_array($f['name'] ?? null)) {
        nm_json(['ok' => false, 'error' => 'ファイルを受け取れませんでした'], 400);
    }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = match ($f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'サーバーの上限より大きいファイルです',
            UPLOAD_ERR_PARTIAL => '途中で切れました。もう一度試してください',
            default => 'アップロードに失敗しました',
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
        nm_json(['ok' => false, 'error' => $res], 400);
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
    if ($index === null) nm_json(['ok' => false, 'error' => 'ページが更新されています。再読み込みしてください'], 400);

    $f = $_FILES['page'] ?? null;
    if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) {
        nm_json(['ok' => false, 'error' => 'ファイルを受け取れませんでした'], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) nm_not_found();

    $dir = nm_work_dir($id) . '/pages';
    $res = nm_import_image($f['tmp_name'], $dir, $index + 1, (int)($cfg['image_quality'] ?? 90),
        (int)($cfg['max_upload_mb'] ?? 30) * 1024 * 1024, false);
    if (is_string($res)) {
        nm_log('upload_rejected', "$id light " . $res);
        nm_json(['ok' => false, 'error' => $res], 400);
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
    if (!$ok) nm_json(['ok' => false, 'error' => 'ページが更新されています。再読み込みしてください'], 400);
    nm_json(['ok' => true]);
}

/** Flash message for a light EPUB import. */
function nm_flash_light_result(array|string $res): void
{
    if (is_string($res)) {
        nm_log('epub_light_rejected', $res);
        nm_flash('err', '小容量版: ' . $res);
        return;
    }
    $msg = '小容量版を ' . $res['attached'] . ' ページに割り当てました';
    if ($res['epubPages'] !== $res['pages']) {
        $msg .= '（作品は ' . $res['pages'] . ' ページ、小容量版の EPUB は ' . $res['epubPages'] . ' ページで、数が違います。順番を確認してください）';
    }
    nm_flash($res['epubPages'] === $res['pages'] && $res['attached'] === $res['pages'] ? 'ok' : 'err', $msg);
}

function nm_restore_upload(): never
{
    $f = $_FILES['backup'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        nm_flash('err', 'バックアップファイルを受け取れませんでした（サーバーのアップロード上限を超えている可能性があります）');
        nm_redirect('p=backup');
    }
    $res = nm_backup_restore((string)$f['tmp_name'], !empty($_POST['overwrite']));
    nm_log('backup_restore', json_encode($res, JSON_UNESCAPED_UNICODE) ?: '');
    if ($res['restored'] || $res['skipped']) {
        nm_flash('ok', "復元しました: {$res['restored']} 作品" . ($res['skipped'] ? "（既にある {$res['skipped']} 作品はそのままにしました）" : ''));
    }
    foreach ($res['errors'] as $e) nm_flash('err', $e);
    if (!$res['restored'] && !$res['skipped'] && !$res['errors']) nm_flash('err', '復元できる作品がありませんでした');
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
    return ['rtl' => '右から左（漫画）', 'ltr' => '左から右', 'vertical' => '縦読み'][$d] ?? $d;
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
            $rows .= '<h2 class="series">' . h($series !== '' ? $series : 'シリーズなし') . '</h2>';
            $lastSeries = $series;
        }
        $thumb = nm_thumb_url($w);
        $rows .= '<a class="work" href="index.php?p=work&amp;id=' . h($w['id']) . '">'
            . ($thumb ? '<img src="' . h($thumb) . '" alt="" loading="lazy">' : '<span class="noimg">画像なし</span>')
            . '<span class="work-title">' . h($w['title']) . (nm_work_is_locked($w) ? ' <span class="badge">パスワード</span>' : '') . '</span>'
            . '<span class="work-meta">' . count($w['pages']) . ' ページ・' . h(nm_direction_label($w['direction'] ?? 'rtl')) . '</span></a>';
    }
    if ($rows === '') $rows = '<p class="note">まだ作品がありません。上のフォームから作成してください。</p>';

    if (nm_is_guest()) {
        nm_layout('作品一覧', '<section class="works">' . $rows . '</section>');
        return;
    }
    nm_layout('作品一覧', nm_update_banner()
        . '<section class="card"><h1>作品を作る</h1>'
        . '<form method="post" action="index.php" class="form row">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="create">'
        . '<label>タイトル<input name="title" required maxlength="200" placeholder="例: 第1話 はじまり"></label>'
        . '<label>シリーズ名（任意）<input name="series" maxlength="200" placeholder="例: ねこの日々"></label>'
        . '<label>読み方<select name="direction">' . nm_direction_options('rtl') . '</select></label>'
        . '<button class="btn primary">作成</button></form>'
        . '<h2>EPUB から作る</h2>'
        . '<p class="note">CLIP STUDIO PAINT などで書き出した漫画の EPUB（画像のページだけのもの）から、ページの順番・読む向き・タイトルをそのまま取り込みます。DRM 付き・文章だけの EPUB は読めません。</p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form row">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="epub">'
        . '<label>EPUB ファイル（通常版）<input type="file" name="epub" accept=".epub,application/epub+zip" required></label>'
        . '<label>小容量版 EPUB（任意・スマホ用）<input type="file" name="epub_light" accept=".epub,application/epub+zip"></label>'
        . '<label>タイトル（空欄なら EPUB のタイトル）<input name="title" maxlength="200"></label>'
        . '<label>シリーズ名（任意）<input name="series" maxlength="200"></label>'
        . '<button class="btn primary">取り込む</button></form>'
        . '<p class="note">サーバーのアップロード上限: ' . h((string)ini_get('upload_max_filesize')) . '（これより大きい EPUB は取り込めません）</p></section>'
        . '<p class="warn js-probe" hidden data-probe="../data/probe.txt">data フォルダがインターネットから見える状態です。.htaccess が効いていない可能性があります。README の「data フォルダを守る」を確認してください。</p>'
        . '<section class="works">' . $rows . '</section>');
}

function nm_view_work(string $id): void
{
    $w = nm_load_work($id);
    if (!$w) nm_not_found();

    $base = nm_base_url();
    $endpoint = $base . '/read.php';
    $viewerPath = NM_ROOT . '/viewer/NagiManga.js';
    $viewerVer = is_file($viewerPath) ? substr(sha1_file($viewerPath), 0, 10) : NM_VERSION;
    $script = $base . '/viewer/NagiManga.js?v=' . $viewerVer;
    $locked = nm_work_is_locked($w);

    $guest = nm_is_guest();
    $pages = '';
    foreach ($w['pages'] as $i => $p) {
        $pages .= '<li class="page"' . ($guest ? '' : ' draggable="true"') . ' data-f="' . h($p['f']) . '">'
            . '<img src="' . h(nm_thumb_url($w, $p)) . '" alt="" loading="lazy">'
            . '<span class="page-no">' . ($i + 1) . '</span>'
            . (isset($p['m']) ? '<span class="page-light" title="小容量版あり（' . (int)$p['m']['w'] . '×' . (int)$p['m']['h'] . '）">小</span>' : '')
            . '<span class="page-name" title="' . h($p['o'] ?? '') . '">' . h(($p['o'] ?? '') !== '' ? $p['o'] : $p['f']) . '</span>'
            . ($guest ? '' : '<form method="post" action="index.php" class="js-confirm" data-confirm="このページを削除しますか？">' . nm_csrf_field()
            . '<input type="hidden" name="do" value="delpage"><input type="hidden" name="id" value="' . h($w['id']) . '">'
            . '<input type="hidden" name="f" value="' . h($p['f']) . '"><button class="btn small danger">削除</button></form>')
            . '</li>';
    }

    $idH = h($w['id']);
    $hidden = nm_csrf_field() . '<input type="hidden" name="id" value="' . $idH . '">';

    nm_layout($w['title'], '<p class="crumb"><a href="index.php">作品一覧</a> / ' . h($w['title']) . '</p>'

        // --- Share ---
        . '<section class="card"><h1>' . h($w['title']) . '</h1>'
        . '<p>ID（ハッシュ）: <code>' . $idH . '</code>・' . count($w['pages']) . ' ページ'
        . ($locked ? '・<span class="badge">パスワード付き</span>' : '・誰でも読めます') . '</p>'
        . '<h2>共有用のタグ</h2>'
        . '<div class="share js-share" data-id="' . $idH . '" data-endpoint="' . h($endpoint) . '" data-script="' . h($script) . '" data-title="' . h($w['title']) . '">'
        . '<div class="form row">'
        . '<label>読み方<select class="js-share-dir">' . nm_direction_options((string)($w['direction'] ?? 'rtl')) . '</select></label>'
        . '<label>見開き<select class="js-share-view"><option value="auto">横長の画面なら見開き</option><option value="single">常に 1 ページ</option></select></label>'
        . '<label>縦読みの種類<select class="js-share-vfit"><option value="page">ページ漫画（1 ページが画面に収まる）</option><option value="webtoon">ウェブトゥーン（横幅いっぱいの長い絵）</option></select></label>'
        . '<label>表紙<select class="js-share-cover"><option value="1">1 ページ目は単独（表紙）</option><option value="0">1 ページ目から見開き</option></select></label>'
        . '<label>リンクの文字<input class="js-share-label" maxlength="100" value="' . h($w['title'] . 'を読む') . '"></label>'
        . '</div>'
        . '<textarea class="copy-src js-share-out" readonly rows="4"></textarea>'
        . '<p><button type="button" class="btn js-copy">コピー</button> '
        // Preview through a relative URL: the admin may be opened under another host name than base_url
        . '<a href="#" class="btn js-preview" data-nagimanga="' . $idH . '" data-endpoint="../read.php">ここで試し読み</a></p>'
        . '<p class="note">このタグをブログやサイトの HTML に貼るだけで、クリックしたときにビューアーが開きます。<code>&lt;script&gt;</code> の行は 1 ページに 1 回で十分です。</p>'
        . '<h3>URL 形式（HTML を書けない場所向け）</h3>'
        . '<div class="copy-box"><input class="copy-src wide js-share-url" readonly> <button type="button" class="btn small js-copy">コピー</button></div>'
        . '<p class="note">てがろぐの投稿など、上のタグが使えない場所ではこの URL を貼ります（てがろぐでは <code>[第1話を読む]URL</code> のように書けます）。てがろぐの設定のしかたは README の「てがろぐに載せる」にあります。RSS リーダーなどで開いたときは、作品を読むためのページが開きます。</p>'
        . '<p class="note">タグの URL（<code>' . h($base) . '</code>）は、' . (empty(nm_config()['base_url']) ? 'この管理画面を開いているアドレスから自動で作っています' : '設定の「設置 URL」から作っています') . '。</p>'
        . '</div>'
        . '<script src="' . h('../viewer/NagiManga.js?v=' . $viewerVer) . '" defer></script>'
        . '</section>'

        . ($guest ? nm_work_sections_guest($w, $pages) : nm_work_sections_admin($w, $pages, $hidden, $locked)));
}

/** Read-only view of a work for guests: no forms at all. */
function nm_work_sections_guest(array $w, string $pages): string
{
    return '<section class="card" id="pages"><h2>ページ</h2>'
        . ($pages !== '' ? '<ol class="pages" data-id="' . h($w['id']) . '">' . $pages . '</ol>' : '<p class="note">まだページがありません。</p>')
        . '</section>'
        . '<section class="card"><h2>作品の設定</h2><table class="table">'
        . '<tr><td>タイトル</td><td>' . h($w['title']) . '</td></tr>'
        . '<tr><td>シリーズ名</td><td>' . h(($w['series'] ?? '') !== '' ? $w['series'] : '（なし）') . '</td></tr>'
        . '<tr><td>既定の読み方</td><td>' . h(nm_direction_label((string)($w['direction'] ?? 'rtl'))) . '</td></tr>'
        . '<tr><td>パスワード</td><td>' . (nm_work_is_locked($w) ? 'あり（ゲストには表示されません）' : 'なし（誰でも読めます）') . '</td></tr>'
        . '</table></section>';
}

/** Second upload area: the light (small) version of the pages. */
function nm_light_upload_html(array $w, string $hidden): string
{
    $total = count($w['pages']);
    $have = count(array_filter($w['pages'], static fn($p) => isset($p['m'])));
    return '<div class="drop js-drop-light" data-id="' . h($w['id']) . '" data-csrf="' . h(nm_csrf_token()) . '">'
        . '<h3>小容量版（スマホ用・任意）</h3>'
        . '<p class="note">同じページを小さく書き出した画像です。スマホではこちらを読み込み、パソコンや拡大したときは通常版に切り替えます。ファイル名の順に、1 ページ目から順番に割り当てます。</p>'
        . '<p>ここに画像をドラッグ＆ドロップ、または <label class="btn">ファイルを選ぶ<input type="file" class="js-file-light" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden></label></p>'
        . '<progress class="js-progress" max="1" value="0" hidden></progress><p class="js-status note"></p>'
        . '<p class="light-count">小容量版があるページ: <strong>' . $have . ' / ' . $total . '</strong></p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form row">' . $hidden
        . '<input type="hidden" name="do" value="epub_light">'
        . '<label>小容量版 EPUB から割り当てる<input type="file" name="epub_light" accept=".epub,application/epub+zip" required></label>'
        . '<button class="btn">取り込む</button></form>'
        . ($have ? '<form method="post" action="index.php" class="inline js-confirm" data-confirm="小容量版をすべて外しますか？（通常版は残ります）">' . $hidden
            . '<input type="hidden" name="do" value="clear_light"><button class="btn small">小容量版をすべて外す</button></form>' : '')
        . '</div>';
}

function nm_work_sections_admin(array $w, string $pages, string $hidden, bool $locked): string
{
    $idH = h($w['id']);
    return ''
        // --- Pages ---
        . '<section class="card" id="pages"><h2>ページ</h2>'
        . '<div class="uploads">'
        . '<div class="drop js-drop" data-id="' . $idH . '" data-csrf="' . h(nm_csrf_token()) . '">'
        . '<h3>通常版（パソコン・拡大用）</h3>'
        . '<p>ここに画像をドラッグ＆ドロップ、または <label class="btn">ファイルを選ぶ<input type="file" class="js-file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden></label></p>'
        . '<label class="check"><input type="checkbox" class="js-sort-after" checked> アップロード後にファイル名の順（001, 002, … 010）に並べる</label>'
        . '<progress class="js-progress" max="1" value="0" hidden></progress><p class="js-status note"></p></div>'
        . ($w['pages'] ? nm_light_upload_html($w, $hidden) : '')
        . '</div>'
        . ($pages !== ''
            ? '<p class="note">ドラッグで並べ替えできます。並べ替えたら「並び順を保存」を押してください。</p>'
              . '<ol class="pages js-pages" data-id="' . $idH . '">' . $pages . '</ol>'
              . '<p><button type="button" class="btn primary js-save-order" hidden>並び順を保存</button> '
              . '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="sort_name"><button class="btn">ファイル名の順に並べ替え</button></form></p>'
            : '<p class="note">まだページがありません。</p>')
        . '</section>'

        // --- Settings ---
        . '<section class="card"><h2>作品の設定</h2>'
        . '<form method="post" action="index.php" class="form row">' . $hidden . '<input type="hidden" name="do" value="update">'
        . '<label>タイトル<input name="title" required maxlength="200" value="' . h($w['title']) . '"></label>'
        . '<label>シリーズ名<input name="series" maxlength="200" value="' . h($w['series'] ?? '') . '"></label>'
        . '<label>既定の読み方<select name="direction">' . nm_direction_options((string)($w['direction'] ?? 'rtl')) . '</select></label>'
        . '<button class="btn">保存</button></form></section>'

        // --- Password ---
        . '<section class="card"><h2>パスワード（限定公開）</h2>'
        . '<p class="note">設定すると、読む人はリンクをクリックしたときにパスワードを入力します。パスワードを変えると、以前のパスワードで開いていた人も読めなくなります。</p>'
        . nm_current_password_html($w)
        . '<form method="post" action="index.php" class="form row">' . $hidden . '<input type="hidden" name="do" value="setpw">'
        . '<label>' . ($locked ? '新しいパスワード' : 'パスワード') . '（4 文字以上）<input type="text" name="password" required minlength="4" maxlength="200" autocomplete="off"></label>'
        . '<button class="btn">' . ($locked ? '変更' : '設定') . '</button></form>'
        . ($locked ? '<form method="post" action="index.php" class="inline js-confirm" data-confirm="パスワードを外して、誰でも読めるようにしますか？">' . $hidden . '<input type="hidden" name="do" value="clearpw"><button class="btn">パスワードを外す</button></form>' : '')
        . '</section>'

        // --- Backup / delete ---
        . '<section class="card"><h2>バックアップと削除</h2>'
        . '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="backup"><button class="btn">この作品をバックアップ（ZIP）</button></form>'
        . '<details class="danger-zone"><summary>作品を削除する</summary>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="delete">'
        . '<label>確認のため、タイトル「' . h($w['title']) . '」を入力<input name="confirm" required autocomplete="off"></label>'
        . '<button class="btn danger">完全に削除</button></form></details>'
        . '</section>';
}

function nm_view_backup(): void
{
    $zip = nm_zip_available();
    $max = ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size');
    nm_layout('バックアップ', '<section class="card"><h1>バックアップ</h1>'
        . ($zip
            ? '<p>すべての作品（画像と設定）を 1 つの ZIP にまとめてダウンロードします。管理者パスワードなどの設定は含まれません。</p>'
              . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="backup"><button class="btn primary">すべてをダウンロード</button></form>'
              . '<p class="note">定期的にダウンロードして、パソコンや外付けディスクに保存しておくと安心です。</p>'
            : '<p class="warn">このサーバーの PHP には ZipArchive がないため、ここからはバックアップできません。FTP で <code>data/works</code> フォルダをまるごとコピーしてください。</p>')
        . '</section>'
        . '<section class="card"><h2>復元</h2>'
        . '<p>このツールでダウンロードしたバックアップ ZIP から作品を戻します。</p>'
        . '<form method="post" action="index.php" enctype="multipart/form-data" class="form">' . nm_csrf_field()
        . '<input type="hidden" name="do" value="restore">'
        . '<input type="file" name="backup" accept=".zip,application/zip" required>'
        . '<label class="check"><input type="checkbox" name="overwrite" value="1"> 同じ作品がある場合は上書きする</label>'
        . '<button class="btn"' . ($zip ? '' : ' disabled') . '>復元する</button></form>'
        . '<p class="note">サーバーのアップロード上限: ' . h($max) . '。これより大きいバックアップは作品ごとに分けて復元してください。</p>'
        . '</section>');
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

    nm_layout('設定', '<section class="card"><h1>設定</h1><h2>ログイン URL</h2>'
        . '<p><input class="copy-src wide" readonly value="' . h($login) . '"> <button type="button" class="btn js-copy">コピー</button></p>'
        . '<form method="post" action="index.php" class="inline js-confirm" data-confirm="ログイン URL を変更しますか？今のブックマークは使えなくなります。">' . $hidden
        . '<input type="hidden" name="do" value="regen_key"><button class="btn">ログイン URL を変更する</button></form></section>'

        . '<section class="card"><h2>管理画面を開ける場所（IP 制限）</h2>'
        . '<p>今の IP アドレス: <code>' . h(nm_client_ip()) . '</code></p>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="settings_access">'
        . '<label>許可する IP アドレス（1 行に 1 つ。192.168.0.0/24 のような範囲も可。空欄なら制限なし）<textarea name="allowed_ips" rows="3">' . h(implode("\n", $cfg['allowed_ips'] ?? [])) . '</textarea></label>'
        . '<p class="note">家庭の回線は IP アドレスが変わることがあります。締め出された場合は、FTP で <code>data/config.php</code> の <code>allowed_ips</code> を空にしてください。</p>'
        . '<label>別のサイトに埋め込む場合、そのサイトのアドレス（1 行に 1 つ。例: https://blog.example.com）<textarea name="allowed_origins" rows="3">' . h(implode("\n", $cfg['allowed_origins'] ?? [])) . '</textarea></label>'
        . '<button class="btn">保存</button></form></section>'

        . '<section class="card"><h2>管理者パスワード</h2>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="settings_pw">'
        . '<label>今のパスワード<input type="password" name="current" autocomplete="current-password" required></label>'
        . '<label>新しいパスワード（10 文字以上）<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>'
        . '<label>もう一度<input type="password" name="password2" autocomplete="new-password" required minlength="10"></label>'
        . '<button class="btn">変更</button></form></section>'

        . '<section class="card"><h2>その他</h2>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="settings_general">'
        . '<label>設置 URL（共有タグに使われます。空欄なら、この管理画面を開いているアドレスから自動で決めます。通常は空欄のままで大丈夫です）<input name="base_url" value="' . h((string)($cfg['base_url'] ?? '')) . '" placeholder="' . h(nm_detect_base_url()) . '"></label>'
        . '<label>画像の画質（60〜100）<input type="number" name="image_quality" min="60" max="100" value="' . (int)($cfg['image_quality'] ?? 90) . '"></label>'
        . '<label>1 枚あたりの上限（MB）<input type="number" name="max_upload_mb" min="1" max="200" value="' . (int)($cfg['max_upload_mb'] ?? 30) . '"></label>'
        . '<p class="note">サーバー側の上限: upload_max_filesize ' . h((string)ini_get('upload_max_filesize')) . ' / memory_limit ' . h((string)ini_get('memory_limit')) . '</p>'
        . '<button class="btn">保存</button></form></section>'

        . '<section class="card"><h2>セキュリティログ（新しい順）</h2>'
        . ($log !== '' ? '<pre class="log">' . $log . '</pre>' : '<p class="note">記録はまだありません。</p>')
        . '</section>');
}

/** After login: a notice when GitHub has a newer NagiManga (checked at most every 12 hours). */
function nm_update_banner(): string
{
    if (!nm_update_auto(nm_config() ?? [])) return '';
    $st = nm_update_status();
    if (!nm_update_available($st)) return '';
    return '<p class="update-banner">新しいバージョン <strong>NagiManga ' . h((string)$st['latest']) . '</strong> が出ています（今は ' . h(NM_VERSION) . '）。'
        . ' <a class="btn small primary" href="index.php?p=update">更新画面へ</a></p>';
}

/** One copy field. */
function nm_copy_box(string $value, string $label = ''): string
{
    return ($label !== '' ? '<p class="copy-label">' . h($label) . '</p>' : '')
        . '<div class="copy-box"><input class="copy-src wide" readonly value="' . h($value) . '"> <button type="button" class="btn small js-copy">コピー</button></div>';
}

/** Where to put the viewer: URLs with the cache buster, ready to paste. */
function nm_view_embed(): void
{
    $viewerPath = NM_ROOT . '/viewer/NagiManga.js';
    $ver = is_file($viewerPath) ? substr(sha1_file($viewerPath), 0, 10) : NM_VERSION;
    $js = nm_base_url() . '/viewer/NagiManga.js?v=' . $ver;

    nm_layout('設置用コード', '<section class="card"><h1>設置用コード</h1>'
        . '<p>ビューアー（NagiManga.js）をサイトに読み込むためのコードです。末尾の <code>?v=' . h($ver) . '</code> は「キャッシュバスター」です。'
        . 'NagiManga を更新すると値が変わり、読む人のブラウザに古いビューアーが残らないようにします。</p>'
        . '<p class="warn">NagiManga を <strong>更新したら</strong>、この画面を開き直して、新しい値を貼り直してください。</p>'
        . nm_copy_box($js, 'ビューアーの URL（キャッシュバスター付き）')
        . '</section>'

        . '<section class="card"><h2>てがろぐで使う</h2>'
        . '<ol class="steps">'
        . '<li>てがろぐの管理画面で <strong>[設定] → [システム設定] → 【画像拡大スクリプトの選択】</strong> を開き、「他のスクリプトを使う：URLを指定」を選びます。</li>'
        . '<li>「JavaScriptのURL」欄を、次の 1 行に置き換えて保存します（NagiSwipe の画像拡大と一緒に読み込みます）。「CSSのURL」欄は NagiSwipe のままで大丈夫です。「JavaScriptをモジュールとして読み込む」はオフにしてください。'
        . nm_copy_box(NM_NAGISWIPE_JS . ' ' . $js) . '</li>'
        . '<li>画像のない投稿でも読み込まれるように、<strong>[設定] → [ページの表示] → 【投稿本文の表示／URL処理】</strong> の「画像リンクに独自のclass属性値を追加する」にチェックを入れ、<code>nagimanga</code> と入力して保存します。</li>'
        . '<li>作品ページの「URL 形式」をコピーして、<code>[第1話を読む]URL</code> のように投稿します。</li>'
        . '</ol></section>'

        . '<section class="card"><h2>ブログ・HTML で使う</h2>'
        . '<p>ページの HTML に、次の 1 行を 1 回だけ貼ります。作品ごとのリンクは、作品ページの「共有用のタグ」を使ってください（その中にもこの行が入っています）。</p>'
        . nm_copy_box('<script src="' . $js . '" defer></script>')
        . '<p class="note">てがろぐ・ブログと NagiManga を別のドメインに置いている場合は、「設定 → 別のサイトに埋め込む場合」にそのサイトのアドレスを登録してください。</p>'
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

    $body = '<section class="card"><h1>更新</h1>'
        . '<p>今のバージョン: <strong>NagiManga ' . h(NM_VERSION) . '</strong></p>';
    if ($newer) {
        $body .= '<div class="update-new"><h2>新しいバージョン ' . h((string)$st['latest']) . ' があります</h2>'
            . (!empty($st['published']) ? '<p class="note">公開日: ' . h(substr((string)$st['published'], 0, 10)) . '</p>' : '')
            . (!empty($st['notes']) ? '<pre class="notes">' . h((string)$st['notes']) . '</pre>' : '')
            . (!empty($st['page']) ? '<p><a href="' . h((string)$st['page']) . '" target="_blank" rel="noopener noreferrer">GitHub のリリースページで見る</a></p>' : '')
            . ($canApply
                ? '<form method="post" action="index.php" class="js-confirm" data-confirm="NagiManga を ' . h((string)$st['latest']) . ' に更新しますか？作品と設定はそのままです。">' . $hidden
                  . '<input type="hidden" name="do" value="update_apply"><button class="btn primary">' . h((string)$st['latest']) . ' に更新する</button></form>'
                : '<p class="warn">このサーバーでは自動で更新できません（下の「このサーバーの状態」を確認してください）。「手動で更新する」の手順で更新できます。</p>')
            . '</div>';
    } elseif (!empty($st['error'])) {
        $body .= '<p class="warn">' . h((string)$st['error']) . '</p>';
    } else {
        $body .= '<p>最新のバージョンです。</p>';
    }
    $body .= '<form method="post" action="index.php" class="inline">' . $hidden . '<input type="hidden" name="do" value="update_check">'
        . '<button class="btn">今すぐ確認</button></form> <span class="note">最後に確認した日時: ' . h($checked) . '</span>'
        . '</section>'

        . '<section class="card"><h2>更新について</h2><ul class="steps">'
        . '<li>更新するのはプログラムのファイルだけです。作品・設定（<code>data</code> フォルダ）とプラグインはそのままです。</li>'
        . '<li>更新ファイルは GitHub の公式リリースからダウンロードし、GitHub が公開している確認用の値（SHA-256）と一致したときだけ使います。</li>'
        . '<li>入れ替える前のファイルは自動で保管されるので、「元に戻す」で戻せます。念のため、先に<a href="index.php?p=backup">作品のバックアップ</a>も取っておくと安心です。</li>'
        . '<li>更新したあとは、<a href="index.php?p=embed">設置用コード</a>のキャッシュバスター（<code>?v=</code> の値）を、てがろぐなどに貼り直してください。</li>'
        . '</ul>'
        . '<h3>このサーバーの状態</h3><ul class="checks">'
        . '<li>' . ($transport !== '' ? '○' : '×') . ' GitHub への接続（' . h($transport !== '' ? $transport : 'PHP の curl も allow_url_fopen も使えません') . '）</li>'
        . '<li>' . ($zip ? '○' : '×') . ' ZIP の展開（ZipArchive）</li>'
        . '<li>' . ($write ? '○' : '×') . ' プログラムのファイルへの書き込み' . ($write ? '' : '（FTP でファイルの権限を確認してください）') . '</li>'
        . '</ul>'
        . '<details><summary>手動で更新する</summary><ol class="steps">'
        . '<li><a href="https://github.com/' . h(NM_UPDATE_REPO) . '/releases" target="_blank" rel="noopener noreferrer">リリースページ</a>から <code>nagimanga-v…zip</code> をダウンロードして展開します。</li>'
        . '<li>中の <code>nagimanga</code> フォルダの中身を、FTP で上書きアップロードします。<strong><code>data</code> フォルダはアップロードしないでください</strong>（作品と設定が消えます）。</li>'
        . '</ol></details></section>';

    $backups = nm_update_backups();
    if ($backups) {
        $body .= '<section class="card"><h2>元に戻す</h2><p>更新で入れ替えたファイルを、更新前に戻します。作品と設定はそのままです。</p><ul class="backups">';
        foreach ($backups as $b) {
            $body .= '<li><span>' . h($b['from']) . ' → ' . h($b['to']) . '（' . h(date('Y-m-d H:i', $b['created'])) . ' に更新）</span> '
                . '<form method="post" action="index.php" class="inline js-confirm" data-confirm="NagiManga ' . h($b['from']) . ' に戻しますか？">' . $hidden
                . '<input type="hidden" name="do" value="update_rollback"><input type="hidden" name="backup" value="' . h($b['name']) . '">'
                . '<button class="btn small">' . h($b['from']) . ' に戻す</button></form></li>';
        }
        $body .= '</ul></section>';
    }

    $auto = nm_update_auto($cfg);
    $body .= '<section class="card"><h2>自動の確認</h2>'
        . '<form method="post" action="index.php" class="form">' . $hidden . '<input type="hidden" name="do" value="update_auto">'
        . '<label class="check"><input type="checkbox" name="auto" value="1"' . ($auto ? ' checked' : '') . '> ログインしたときに新しいバージョンを確認する（12 時間に 1 回まで、GitHub に接続します）</label>'
        . '<button class="btn">保存</button></form></section>';

    nm_layout('更新', $body);
}

/** "Current password" row: hidden until the admin presses 表示. */
function nm_current_password_html(array $w): string
{
    if (!nm_work_is_locked($w)) return '';
    $plain = !empty($w['password_enc']) ? nm_unseal((string)$w['password_enc']) : null;
    if ($plain === null) {
        return '<p class="note">現在のパスワードは表示できません（この機能より前に設定したか、別のサーバーから復元したため）。もう一度設定すると、ここに表示されるようになります。</p>';
    }
    return '<div class="current-pw"><span>現在のパスワード</span>'
        . '<input class="copy-src js-pw" type="password" readonly value="' . h($plain) . '" aria-label="現在のパスワード">'
        . '<button type="button" class="btn small js-pw-toggle">表示</button>'
        . '<button type="button" class="btn small js-copy">コピー</button></div>';
}

/** Page images for the admin (session required, so locked works preview too). */
function nm_admin_image(string $id, string $file, bool $full): never
{
    $w = nm_valid_id($id) ? nm_load_work($id) : null;
    if (!$w) nm_not_found();
    $path = nm_page_path($w, $file, !$full) ?? nm_page_path($w, $file);
    if ($path === null) nm_not_found();
    header('Content-Type: ' . (str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg'));
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
