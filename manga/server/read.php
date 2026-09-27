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
$id = nm_str($src, 'id', 12);
if (!nm_valid_id($id)) nm_not_found();

$work = nm_load_work($id);
if (!$work || empty($work['pages'])) nm_not_found();

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

    $type = str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg';
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
