<?php
/*
 * NagiManga public endpoint (read only)
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 *   GET  read.php?a=m&id=ID[&t=TOKEN]          page list (JSON)
 *   POST read.php  a=u&id=ID&password=...      unlock -> reader token (JSON)
 *   GET  read.php?a=i&id=ID&f=FILE[&t=TOKEN]   page image
 *   GET  read.php?a=t&id=ID&f=FILE[&t=TOKEN]   page thumbnail
 *   GET  read.php?nagimanga=ID[&dir=..]        share URL: the work's own page (opens the viewer)
 *   GET  read.php?a=o&id=ID                    link-card image (OGP) of a public work's cover
 *
 * Anything else, or anything not found / not allowed: the same plain 404.
 */
declare(strict_types=1);

define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';

$cfg = nm_config();
if (!$cfg || empty($cfg['admin_hash'])) nm_not_found();

nm_cors($cfg);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$src = $method === 'POST' ? $_POST : $_GET;
$action = nm_str($src, 'a', 2);
if ($method === 'GET' && in_array($action, ['i', 't', 'o'], true)) nm_image_guard($cfg);

// The share URL opened directly (new tab, RSS reader, no script on the page)
if ($method === 'GET' && $action === '' && isset($_GET['nagimanga'])) {
    $id = nm_str($_GET, 'nagimanga', 12);
    if (!nm_valid_id($id)) nm_not_found();
    $work = nm_load_work($id);
    if (!$work || empty($work['pages']) || !nm_work_page_public($work)) nm_not_found();
    nm_serve_reader_page($work, $cfg);
}

$id = nm_str($src, 'id', 12);
if (!nm_valid_id($id)) nm_not_found();

$work = nm_load_work($id);
if (!$work || empty($work['pages'])) nm_not_found();

// Link cards: any site may fetch the cover of a public work (it is one small image)
if ($method === 'GET' && $action === 'o') nm_serve_og_image($work);
// Hotlink protection (settings > advanced): pages only for this site and the listed ones
if (!nm_hotlink_ok($cfg)) nm_not_found();

match (true) {
    $method === 'GET' && $action === 'm' => nm_serve_manifest($work, nm_str($_GET, 't', 80)),
    $method === 'POST' && $action === 'u' => nm_unlock($work),
    $method === 'GET' && ($action === 'i' || $action === 't') => nm_serve_image($work, nm_str($_GET, 'f', 40), nm_str($_GET, 't', 80), $action === 't'),
    default => nm_not_found(),
};

// ---------------------------------------------------------------------------

/** Allow the manifest / unlock calls from sites listed in the settings. */
function nm_cors(array $cfg): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return;
    if (in_array(rtrim($origin, '/'), $cfg['allowed_origins'] ?? [], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Max-Age: 600');
    }
    header('Vary: Origin');
}

/**
 * Hotlink protection. Off by default. When on, the manifest, unlock and page
 * images answer only this site, the sites listed for embedding and the extra
 * allow list; everything else gets the same 404.
 */
function nm_hotlink_ok(array $cfg): bool
{
    if (empty($cfg['hotlink'])) return true;
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site === 'same-origin' || $site === 'same-site') return true;
    $from = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($from === '' || $from === 'null') $from = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $u = parse_url($from);
    if (!is_array($u) || empty($u['host']) || !in_array($u['scheme'] ?? '', ['http', 'https'], true)) return false;
    $host = strtolower($u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
    if ($host === strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) return true;
    $origin = strtolower($u['scheme']) . '://' . $host;
    $allow = array_merge($cfg['allowed_origins'] ?? [], $cfg['hotlink_allow'] ?? []);
    return in_array($origin, array_map('strtolower', $allow), true);
}

/** This install's public address (settings, or the address read.php is called with). */
function nm_public_base(array $cfg): string
{
    if (!empty($cfg['base_url'])) return rtrim((string)$cfg['base_url'], '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A[a-z0-9.\-:\[\]]+\z/i', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/read.php'))), '/');
    return (nm_is_https() ? 'https' : 'http') . '://' . $host . $dir;
}

/** The work's own page: cover, title, read and back buttons, link-card tags; opens the viewer. */
function nm_serve_reader_page(array $work, array $cfg): never
{
    $viewer = __DIR__ . '/viewer/NagiManga.js';
    $ver = nm_asset_version($viewer);
    $attrs = 'data-nagimanga="' . h($work['id']) . '" data-endpoint="read.php"';
    $dir = nm_str($_GET, 'dir', 8);
    if (in_array($dir, ['rtl', 'ltr', 'vertical'], true)) $attrs .= ' data-direction="' . $dir . '"';
    if (nm_str($_GET, 'view', 8) === 'single') $attrs .= ' data-view="single"';
    if (nm_str($_GET, 'cover', 1) === '0') $attrs .= ' data-cover="0"';
    if (nm_str($_GET, 'vertical', 8) === 'webtoon') $attrs .= ' data-vertical="webtoon"';

    $locked = nm_work_is_locked($work);
    $base = nm_public_base($cfg);
    $title = (string)($work['title'] ?? '');
    $count = count($work['pages']);
    $desc = $locked ? 'パスワード付きの作品です。' : '全 ' . $count . ' ページ。タップするとその場で読めます。';
    $first = $work['pages'][0];
    $ogImage = $locked
        ? $base . '/viewer/og.jpg'
        : $base . '/read.php?a=o&id=' . $work['id'] . '&v=' . substr(hash('sha256', $first['f']), 0, 8);
    $pageUrl = $base . '/read.php?nagimanga=' . $work['id'];
    $cover = $locked ? '' : '<img class="cover" src="read.php?a=t&amp;id=' . h($work['id']) . '&amp;f=' . h(rawurlencode($first['f'])) . '" alt="">';
    // Back button: a fixed address from the settings, or "auto" (decided by the viewer script)
    $back = (string)($cfg['page_back'] ?? '');
    $backHtml = $back !== ''
        ? '<a class="btn back" id="nm-back" href="' . h($back) . '">戻る</a>'
        : '<a class="btn back" id="nm-back" href="#" data-back="auto" hidden>戻る</a>';

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="icon" href="viewer/favicon.svg" type="image/svg+xml">'
        . '<meta name="description" content="' . h($desc) . '">'
        . '<meta property="og:type" content="article">'
        . '<meta property="og:title" content="' . h($title) . '">'
        . '<meta property="og:description" content="' . h($desc) . '">'
        . '<meta property="og:url" content="' . h($pageUrl) . '">'
        . '<meta property="og:image" content="' . h($ogImage) . '">'
        . '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">'
        . '<meta name="twitter:card" content="summary_large_image">'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Hiragino Sans","Yu Gothic UI",sans-serif;background:#111418;color:#eee}'
        . 'main{display:grid;justify-items:center;gap:14px;padding:32px 20px;text-align:center;max-width:520px}'
        . '.cover{width:min(240px,60vw);aspect-ratio:auto;border-radius:6px;box-shadow:0 12px 32px rgba(0,0,0,.5);background:#222}'
        . '.icon{width:72px;height:72px}'
        . 'h1{margin:6px 0 0;font-size:1.3rem;line-height:1.5}'
        . 'p{margin:0;color:#a7adb3;font-size:.9rem}'
        . '.btns{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:6px}'
        . '.btn{display:inline-block;padding:.8em 1.8em;border-radius:999px;text-decoration:none;font-weight:600}'
        . '.read{background:#eee;color:#111}.back{border:1px solid #555;color:#ddd}[hidden]{display:none!important}'
        . '</style>'
        . '</head><body><main>'
        . ($cover !== '' ? $cover : '<img class="icon" src="viewer/favicon.svg" alt="">')
        . '<h1>' . h($title) . '</h1><p>' . h($desc) . '</p>'
        . '<div class="btns"><a href="#" class="btn read" id="nm-open" ' . $attrs . '>読む</a>' . $backHtml . '</div>'
        . '</main>'
        . '<script src="viewer/NagiManga.js?v=' . h($ver) . '&amp;open=nm-open" defer></script>'
        . '</body></html>';
    exit;
}

/**
 * Link-card image (1200x630): the cover over a blurred, darkened copy of itself.
 * Made once per first page and kept next to the work. Password works and works
 * without an individual page have none.
 */
function nm_serve_og_image(array $work): never
{
    if (nm_work_is_locked($work) || !nm_work_page_public($work)) nm_not_found();
    $path = nm_og_path($work);
    if ($path === null) nm_not_found();
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function nm_og_path(array $work): ?string
{
    $first = $work['pages'][0];
    $src = nm_page_path($work, $first['f']);
    if ($src === null) return null;
    $dir = nm_work_dir($work['id']);
    $out = $dir . '/og-' . substr(hash('sha256', $first['f']), 0, 12) . '.jpg';
    if (is_file($out)) return $out;
    if (!function_exists('imagecreatetruecolor')) return null;
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return null;
    $W = 1200;
    $H = 630;
    $sw = imagesx($im);
    $sh = imagesy($im);
    $cv = imagecreatetruecolor($W, $H);
    // Background: the cover filling the card, blurred by a tiny round trip, then darkened
    $scale = max($W / $sw, $H / $sh);
    $cw = $W / $scale;
    $ch = $H / $scale;
    $small = imagecreatetruecolor(160, 84);
    imagecopyresampled($small, $im, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), 160, 84, (int)$cw, (int)$ch);
    for ($i = 0; $i < 6; $i++) imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
    imagecopyresampled($cv, $small, 0, 0, 0, 0, $W, $H, 160, 84);
    imagealphablending($cv, true);
    imagefilledrectangle($cv, 0, 0, $W, $H, imagecolorallocatealpha($cv, 10, 13, 16, 38));
    // Foreground: the whole cover, as large as fits
    $fs = min(590 / $sh, 1100 / $sw);
    $fw = (int)round($sw * $fs);
    $fh = (int)round($sh * $fs);
    imagecopyresampled($cv, $im, (int)(($W - $fw) / 2), (int)(($H - $fh) / 2), 0, 0, $fw, $fh, $sw, $sh);
    $tmp = $out . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $ok = imagejpeg($cv, $tmp, 85);
    if (!$ok || !@rename($tmp, $out)) {
        @unlink($tmp);
        return null;
    }
    foreach (glob($dir . '/og-*.jpg') ?: [] as $old) {
        if ($old !== $out) @unlink($old);
    }
    return $out;
}

function nm_serve_manifest(array $work, string $token): never
{
    header('Cache-Control: no-store');
    $locked = nm_work_is_locked($work);
    if ($locked && !nm_check_token($work, $token)) {
        // Title only: nothing about the pages before the password
        nm_json(['id' => $work['id'], 'title' => (string)($work['title'] ?? ''), 'locked' => true]);
    }

    $self = strtok((string)($_SERVER['SCRIPT_NAME'] ?? 'read.php'), '?');
    $base = basename($self);
    $tq = $locked ? '&t=' . rawurlencode($token) : '';
    $pages = [];
    foreach ($work['pages'] as $p) {
        $q = 'id=' . $work['id'] . '&f=' . rawurlencode($p['f']) . $tq;
        $page = [
            'w' => (int)$p['w'],
            'h' => (int)$p['h'],
            // Relative to read.php; the viewer resolves it against the endpoint URL
            'src' => "$base?a=i&$q",
            'thumb' => "$base?a=t&$q",
        ];
        if (isset($p['m'])) {
            // Light version: the viewer uses it when it is sharp enough for the screen
            $page['light'] = [
                'src' => "$base?a=i&id=" . $work['id'] . '&f=' . rawurlencode($p['m']['f']) . $tq,
                'w' => (int)$p['m']['w'],
                'h' => (int)$p['m']['h'],
            ];
        }
        $pages[] = $page;
    }
    nm_json([
        'id' => $work['id'],
        'title' => (string)($work['title'] ?? ''),
        'locked' => false,
        'direction' => in_array($work['direction'] ?? '', ['rtl', 'ltr', 'vertical'], true) ? $work['direction'] : 'rtl',
        'pages' => $pages,
    ]);
}

function nm_unlock(array $work): never
{
    header('Cache-Control: no-store');
    if (!nm_work_is_locked($work)) nm_not_found();

    $ip = nm_client_ip();
    $key = $ip . '|' . $work['id'];
    // 5 wrong answers per 15 minutes per work and IP, 30 per hour per IP overall
    if (nm_rate_count('unlock', $key, 900) >= 5 || nm_rate_count('unlock-ip', $ip, 3600) >= 30) {
        nm_log('unlock_blocked', $work['id']);
        nm_json(['ok' => false, 'error' => 'rate'], 429);
    }

    $pw = nm_str($_POST, 'password', 200);
    if ($pw !== '' && password_verify($pw, (string)$work['password_hash'])) {
        nm_rate_clear('unlock', $key);
        nm_log('unlock_ok', $work['id']);
        nm_json(['ok' => true, 'token' => nm_make_token($work)]);
    }

    nm_rate_hit('unlock', $key, 900);
    nm_rate_hit('unlock-ip', $ip, 3600);
    nm_rate_gc();
    nm_log('unlock_failed', $work['id']);
    // Constant-ish delay against fast guessing
    usleep(random_int(300000, 600000));
    nm_json(['ok' => false, 'error' => 'password'], 403);
}

function nm_serve_image(array $work, string $file, string $token, bool $thumb): never
{
    $locked = nm_work_is_locked($work);
    if ($locked && !nm_check_token($work, $token)) nm_not_found();

    $path = nm_page_path($work, $file, $thumb);
    if ($path === null) nm_not_found();

    $type = nm_image_mime($path);
    $etag = '"' . substr(sha1($file . '|' . filesize($path) . '|' . filemtime($path)), 0, 20) . '"';

    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('ETag: ' . $etag);
    // File names are unique per upload, so public pages never change under the same URL
    header($locked
        ? 'Cache-Control: private, max-age=3600'
        : 'Cache-Control: public, max-age=31536000, immutable');

    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
