<?php
/*
 * NagiManga server core
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_VERSION = '0.4.0';

// Never show PHP errors to visitors (they reveal server paths); log them instead
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('NM_ROOT', dirname(__DIR__));
// The data directory may be moved outside the web root: create lib/paths.php
// that returns the absolute path (e.g. <?php return '/home/you/nagimanga-data';)
define('NM_DATA', (static function (): string {
    // Development / tests only (a web visitor cannot set server environment variables)
    $env = getenv('NAGIMANGA_DATA');
    if (is_string($env) && $env !== '') return rtrim($env, '/\\');
    $custom = __DIR__ . '/paths.php';
    if (is_file($custom)) {
        $p = require $custom;
        if (is_string($p) && $p !== '') return rtrim($p, '/\\');
    }
    return NM_ROOT . '/data';
})());

const NM_ID_PATTERN = '/\A[A-Za-z0-9]{12}\z/';
const NM_PAGE_PATTERN = '/\A(p[0-9]{4}_[a-f0-9]{8})\.(webp|jpg|gif)\z/';
require_once __DIR__ . '/image-guard.php';

/** Content-based cache keys, including the reader's automatically loaded CSS. */
function nm_asset_version(string $file): string
{
    if (!is_file($file) && in_array(basename($file), ['NagiSwipe-main.js', 'NagiSwipe-main.css'], true)) {
        // The development router serves these from the repository root.
        $file = dirname(NM_ROOT, 2) . '/' . basename($file);
    }
    if (!is_file($file)) return NM_VERSION;
    $hash = hash_init('sha256');
    hash_update_file($hash, $file);
    if (basename($file) === 'NagiManga.js' && is_file(dirname($file) . '/NagiManga.css')) hash_update_file($hash, dirname($file) . '/NagiManga.css');
    return substr(hash_final($hash), 0, 12);
}

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

/**
 * Every refusal looks the same: no hint about what exists or why it failed.
 */
function nm_not_found(): never
{
    $html = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html');
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Vary: Accept, Cookie');
        nm_lscache_header(false);
        if ($html) header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    if ($html) {
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/404.php'))), '/');
        if (str_ends_with($dir, '/admin')) $dir = substr($dir, 0, -6);
        $base = $dir === '.' ? '' : $dir;
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>404｜見つかりません</title><link rel="stylesheet" href="' . h($base . '/viewer/404.css?v=' . nm_asset_version(NM_ROOT . '/viewer/404.css')) . '"></head><body class="nm-void">' . nm_blackhole_scene() . '<main><p class="nm-error-number">404</p><h1>このページは、ブラックホールの中へ。</h1><p>探していたページは見つかりませんでした。</p><a href="' . h($base . '/') . '">LOGへ戻る <span aria-hidden="true">↗</span></a><small>Not Found</small></main></body></html>';
        exit;
    }
    echo 'Not Found';
    exit;
}

/** Self-contained SVG: the inside of the hole, bands of beige ribbons rippling, with light waves crashing through. */
function nm_blackhole_scene(): string
{
    return <<<'HTML'
<div class="nm-universe" aria-hidden="true">
<svg class="nm-blackhole" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" focusable="false" xmlns="http://www.w3.org/2000/svg">
<defs><radialGradient id="nm-inner-light" cx=".5" cy=".42" r=".7"><stop stop-color="#fffaf0"/><stop offset=".45" stop-color="#f1e3cc"/><stop offset="1" stop-color="#c9ab86"/></radialGradient><radialGradient id="nm-core-glow"><stop stop-color="#fffdf7" stop-opacity=".9"/><stop offset=".6" stop-color="#fff6e6" stop-opacity=".35"/><stop offset="1" stop-color="#fff6e6" stop-opacity="0"/></radialGradient><linearGradient id="nm-crest-light"><stop stop-color="#fffaf0" stop-opacity="0"/><stop offset=".35" stop-color="#fffaf0" stop-opacity=".55"/><stop offset=".55" stop-color="#ffffff" stop-opacity=".95"/><stop offset=".75" stop-color="#f6e6cc" stop-opacity=".5"/><stop offset="1" stop-color="#f6e6cc" stop-opacity="0"/></linearGradient></defs>
<rect width="1600" height="900" fill="url(#nm-inner-light)"/>
<ellipse class="nm-inner-glow" cx="800" cy="390" rx="620" ry="320" fill="url(#nm-core-glow)"/>
<g class="nm-waves" fill="none" stroke-linecap="round">
<g class="nm-band nm-band-1"><g class="nm-drift nm-l800"><path d="M-800 74Q-600 42 -400 74T0 74T400 74T800 74T1200 74T1600 74T2000 74T2400 74T2800 74T3200 74" stroke="#efdfc6" stroke-width="30" opacity="0.61"/><path d="M-800 98Q-600 60 -400 98T0 98T400 98T800 98T1200 98T1600 98T2000 98T2400 98T2800 98T3200 98" stroke="#e2cba9" stroke-width="30" opacity="0.57"/><path d="M-800 114Q-600 78 -400 114T0 114T400 114T800 114T1200 114T1600 114T2000 114T2400 114T2800 114T3200 114" stroke="#f3e4cc" stroke-width="22" opacity="0.65"/><path d="M-800 126Q-600 93 -400 126T0 126T400 126T800 126T1200 126T1600 126T2000 126T2400 126T2800 126T3200 126" stroke="#efdfc6" stroke-width="5" opacity="0.46"/><path d="M-800 145Q-600 109 -400 145T0 145T400 145T800 145T1200 145T1600 145T2000 145T2400 145T2800 145T3200 145" stroke="#ddc39f" stroke-width="22" opacity="0.62"/><path d="M-800 162Q-600 131 -400 162T0 162T400 162T800 162T1200 162T1600 162T2000 162T2400 162T2800 162T3200 162" stroke="#e2cba9" stroke-width="14" opacity="0.67"/></g></g>
<g class="nm-band nm-band-2"><g class="nm-drift nm-l640 nm-rev"><path d="M-640 209Q-480 165 -320 209T0 209T320 209T640 209T960 209T1280 209T1600 209T1920 209T2240 209T2560 209T2880 209" stroke="#d1b28c" stroke-width="5" opacity="0.57"/><path d="M-640 223Q-480 189 -320 223T0 223T320 223T640 223T960 223T1280 223T1600 223T1920 223T2240 223T2560 223T2880 223" stroke="#c4a37f" stroke-width="30" opacity="0.47"/><path d="M-640 240Q-480 202 -320 240T0 240T320 240T640 240T960 240T1280 240T1600 240T1920 240T2240 240T2560 240T2880 240" stroke="#e2cba9" stroke-width="22" opacity="0.71"/><path d="M-640 258Q-480 225 -320 258T0 258T320 258T640 258T960 258T1280 258T1600 258T1920 258T2240 258T2560 258T2880 258" stroke="#f3e4cc" stroke-width="30" opacity="0.67"/><path d="M-640 275Q-480 234 -320 275T0 275T320 275T640 275T960 275T1280 275T1600 275T1920 275T2240 275T2560 275T2880 275" stroke="#f3e4cc" stroke-width="22" opacity="0.58"/><path d="M-640 293Q-480 248 -320 293T0 293T320 293T640 293T960 293T1280 293T1600 293T1920 293T2240 293T2560 293T2880 293" stroke="#e7d2b2" stroke-width="30" opacity="0.44"/></g></g>
<g class="nm-band nm-band-3"><g class="nm-drift nm-l1000"><path d="M-1000 340Q-750 281 -500 340T0 340T500 340T1000 340T1500 340T2000 340T2500 340T3000 340T3500 340T4000 340" stroke="#efdfc6" stroke-width="30" opacity="0.70"/><path d="M-1000 356Q-750 308 -500 356T0 356T500 356T1000 356T1500 356T2000 356T2500 356T3000 356T3500 356T4000 356" stroke="#ddc39f" stroke-width="9" opacity="0.54"/><path d="M-1000 374Q-750 333 -500 374T0 374T500 374T1000 374T1500 374T2000 374T2500 374T3000 374T3500 374T4000 374" stroke="#e2cba9" stroke-width="22" opacity="0.62"/><path d="M-1000 387Q-750 339 -500 387T0 387T500 387T1000 387T1500 387T2000 387T2500 387T3000 387T3500 387T4000 387" stroke="#e2cba9" stroke-width="14" opacity="0.57"/><path d="M-1000 407Q-750 351 -500 407T0 407T500 407T1000 407T1500 407T2000 407T2500 407T3000 407T3500 407T4000 407" stroke="#d1b28c" stroke-width="22" opacity="0.52"/><path d="M-1000 425Q-750 386 -500 425T0 425T500 425T1000 425T1500 425T2000 425T2500 425T3000 425T3500 425T4000 425" stroke="#f3e4cc" stroke-width="9" opacity="0.61"/></g></g>
<g class="nm-band nm-band-4"><g class="nm-drift nm-l800 nm-rev"><path d="M-800 480Q-600 413 -400 480T0 480T400 480T800 480T1200 480T1600 480T2000 480T2400 480T2800 480T3200 480" stroke="#d1b28c" stroke-width="14" opacity="0.72"/><path d="M-800 496Q-600 440 -400 496T0 496T400 496T800 496T1200 496T1600 496T2000 496T2400 496T2800 496T3200 496" stroke="#e2cba9" stroke-width="22" opacity="0.67"/><path d="M-800 513Q-600 467 -400 513T0 513T400 513T800 513T1200 513T1600 513T2000 513T2400 513T2800 513T3200 513" stroke="#efdfc6" stroke-width="14" opacity="0.48"/><path d="M-800 528Q-600 466 -400 528T0 528T400 528T800 528T1200 528T1600 528T2000 528T2400 528T2800 528T3200 528" stroke="#e7d2b2" stroke-width="30" opacity="0.45"/><path d="M-800 549Q-600 484 -400 549T0 549T400 549T800 549T1200 549T1600 549T2000 549T2400 549T2800 549T3200 549" stroke="#d1b28c" stroke-width="22" opacity="0.73"/><path d="M-800 566Q-600 512 -400 566T0 566T400 566T800 566T1200 566T1600 566T2000 566T2400 566T2800 566T3200 566" stroke="#b79574" stroke-width="9" opacity="0.65"/></g></g>
<g class="nm-band nm-band-5"><g class="nm-drift nm-l640"><path d="M-640 605Q-480 544 -320 605T0 605T320 605T640 605T960 605T1280 605T1600 605T1920 605T2240 605T2560 605T2880 605" stroke="#e2cba9" stroke-width="5" opacity="0.35"/><path d="M-640 628Q-480 586 -320 628T0 628T320 628T640 628T960 628T1280 628T1600 628T1920 628T2240 628T2560 628T2880 628" stroke="#f7eedf" stroke-width="9" opacity="0.45"/><path d="M-640 644Q-480 582 -320 644T0 644T320 644T640 644T960 644T1280 644T1600 644T1920 644T2240 644T2560 644T2880 644" stroke="#c4a37f" stroke-width="14" opacity="0.41"/><path d="M-640 660Q-480 607 -320 660T0 660T320 660T640 660T960 660T1280 660T1600 660T1920 660T2240 660T2560 660T2880 660" stroke="#e7d2b2" stroke-width="22" opacity="0.63"/><path d="M-640 676Q-480 625 -320 676T0 676T320 676T640 676T960 676T1280 676T1600 676T1920 676T2240 676T2560 676T2880 676" stroke="#e2cba9" stroke-width="9" opacity="0.73"/><path d="M-640 691Q-480 641 -320 691T0 691T320 691T640 691T960 691T1280 691T1600 691T1920 691T2240 691T2560 691T2880 691" stroke="#d1b28c" stroke-width="9" opacity="0.72"/></g></g>
<g class="nm-band nm-band-6"><g class="nm-drift nm-l1000 nm-rev"><path d="M-1000 748Q-750 706 -500 748T0 748T500 748T1000 748T1500 748T2000 748T2500 748T3000 748T3500 748T4000 748" stroke="#ddc39f" stroke-width="9" opacity="0.56"/><path d="M-1000 764Q-750 711 -500 764T0 764T500 764T1000 764T1500 764T2000 764T2500 764T3000 764T3500 764T4000 764" stroke="#f7eedf" stroke-width="22" opacity="0.71"/><path d="M-1000 784Q-750 738 -500 784T0 784T500 784T1000 784T1500 784T2000 784T2500 784T3000 784T3500 784T4000 784" stroke="#f3e4cc" stroke-width="5" opacity="0.36"/><path d="M-1000 797Q-750 757 -500 797T0 797T500 797T1000 797T1500 797T2000 797T2500 797T3000 797T3500 797T4000 797" stroke="#f3e4cc" stroke-width="14" opacity="0.72"/><path d="M-1000 819Q-750 774 -500 819T0 819T500 819T1000 819T1500 819T2000 819T2500 819T3000 819T3500 819T4000 819" stroke="#e2cba9" stroke-width="9" opacity="0.41"/><path d="M-1000 830Q-750 784 -500 830T0 830T500 830T1000 830T1500 830T2000 830T2500 830T3000 830T3500 830T4000 830" stroke="#efdfc6" stroke-width="22" opacity="0.59"/></g></g>
</g>
<g class="nm-crests" fill="none" stroke-linecap="round"><path class="nm-crest nm-crest-1" d="M0 300Q175 230 350 300T700 300T1050 300T1400 300" stroke="url(#nm-crest-light)" stroke-width="16"/><path class="nm-crest nm-crest-2" d="M0 520Q175 430 350 520T700 520T1050 520T1400 520" stroke="url(#nm-crest-light)" stroke-width="22"/><path class="nm-crest nm-crest-3" d="M0 720Q175 650 350 720T700 720T1050 720T1400 720" stroke="url(#nm-crest-light)" stroke-width="14"/></g>
</svg><div class="nm-space-vignette"></div></div>
HTML;
}

function nm_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

/**
 * Client IP. X-Forwarded-For is never trusted unless the direct peer is a
 * proxy listed in config['trusted_proxies'] (it is trivially spoofed).
 */
function nm_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cfg = nm_config();
    $proxies = $cfg['trusted_proxies'] ?? [];
    if ($ip !== '' && in_array($ip, $proxies, true)) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $parts = array_map('trim', explode(',', $xff));
        $last = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) return $last;
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function nm_is_https(): bool
{
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    // Behind a reverse proxy listed in trusted_proxies (never trusted otherwise)
    $cfg = nm_config();
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $peer !== '' && in_array($peer, $cfg['trusted_proxies'] ?? [], true)
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function nm_str(array $src, string $key, int $max = 1000): string
{
    $v = $src[$key] ?? '';
    if (!is_string($v)) return '';
    if (strlen($v) > $max) return '';
    // Reject invalid UTF-8 and control characters (except tab / newline)
    if (!mb_check_encoding($v, 'UTF-8')) return '';
    return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
}

function nm_valid_id(string $id): bool
{
    return (bool)preg_match(NM_ID_PATTERN, $id);
}

function nm_random_id(int $len = 12): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    while (strlen($out) < $len) {
        // 62 * 4 = 248: reject bytes >= 248 to avoid modulo bias
        foreach (str_split(random_bytes($len * 2)) as $b) {
            $n = ord($b);
            if ($n >= 248) continue;
            $out .= $chars[$n % 62];
            if (strlen($out) === $len) break;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

function nm_ensure_dir(string $dir): void
{
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('mkdir failed');
    }
    // Inside data/: an empty index.html against directory listings
    if (str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', NM_DATA) . '/')) {
        @file_put_contents($dir . '/index.html', '');
    }
}

/** Write via a temp file + rename so readers never see half a file. */
function nm_write_atomic(string $path, string $content): void
{
    nm_ensure_dir(dirname($path));
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('write failed');
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        // Windows cannot rename over an existing file in some cases
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('rename failed');
        }
    }
}

function nm_write_json(string $path, array $data): void
{
    nm_write_atomic($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

function nm_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** Run $fn while holding an exclusive lock (one writer at a time). */
function nm_with_lock(string $name, callable $fn): mixed
{
    $dir = NM_DATA . '/locks';
    nm_ensure_dir($dir);
    $fh = fopen($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.lock', 'c');
    if ($fh === false) throw new RuntimeException('lock failed');
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function nm_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $real = realpath($dir);
    $base = realpath(NM_DATA);
    // Never delete outside the data directory
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('refused');
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isLink() || $f->isFile()) @unlink($f->getPathname());
        else @rmdir($f->getPathname());
    }
    @rmdir($real);
}

// ---------------------------------------------------------------------------
// Config (data/config.php, a PHP file so it is never served as text)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// LiteSpeed Cache: public LOG pages are cached by the web server itself.
// On any other server nothing is sent, so the site behaves exactly as before.
// ---------------------------------------------------------------------------
const NM_LSCACHE_TAG = 'nagilog';
const NM_LSCACHE_TTL = 3600;       // the calendar marks "today", so pages are rebuilt within the hour

/** True when this request is served by LiteSpeed (with its cache module) and the owner left the switch on. */
function nm_lscache_server(): bool
{
    // Development tests only; environment variables cannot be set from the web.
    if (getenv('NAGIMANGA_LSCACHE_FORCE') === '1') return true;
    return !empty($_SERVER['X-LSCACHE']) || stripos((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'litespeed') !== false;
}
function nm_lscache_active(): bool
{
    return nm_lscache_server() && (bool)((nm_config() ?? [])['lscache'] ?? true);
}
/**
 * Uploading a new version does not go through the admin, so nothing would purge pages cached by the
 * old code. Compare the program files' newest change time with the last one seen and purge once.
 */
function nm_lscache_code_changed(): bool
{
    static $changed = null;
    if ($changed !== null) return $changed;
    $newest = 0;
    foreach (array_merge(glob(NM_ROOT . '/*.php') ?: [], glob(NM_ROOT . '/lib/*.php') ?: [], glob(NM_ROOT . '/viewer/*.css') ?: [], glob(NM_ROOT . '/viewer/*.js') ?: []) as $file) $newest = max($newest, (int)@filemtime($file));
    $stamp = NM_VERSION . ':' . $newest;
    $file = NM_DATA . '/lscache-code.txt';
    $changed = @file_get_contents($file) !== $stamp;
    if ($changed) @file_put_contents($file, $stamp, LOCK_EX);
    return $changed;
}

/** Mark this response: cache it for visitors (public pages only) or never. */
function nm_lscache_header(bool $public): void
{
    if (headers_sent() || !nm_lscache_active()) return;
    if (nm_lscache_code_changed()) header('X-LiteSpeed-Purge: tag=' . NM_LSCACHE_TAG);
    // Anyone holding the login cookie is looked up separately, so a cached visitor page is never shown to the owner.
    header('X-LiteSpeed-Vary: cookie=nm_admin');
    if ($public) {
        header('X-LiteSpeed-Cache-Control: public,max-age=' . NM_LSCACHE_TTL);
        header('X-LiteSpeed-Tag: ' . NM_LSCACHE_TAG);
    } else {
        header('X-LiteSpeed-Cache-Control: no-cache');
        header_remove('X-LiteSpeed-Tag');
    }
}
/** Drop every cached LOG page (after posts, images, settings or works change). */
function nm_lscache_purge(): void
{
    if (!headers_sent() && nm_lscache_active()) header('X-LiteSpeed-Purge: tag=' . NM_LSCACHE_TAG);
}

/** Content-Type for a stored page or LOG image, by its extension. */
function nm_image_mime(string $file): string
{
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) { 'webp' => 'image/webp', 'gif' => 'image/gif', default => 'image/jpeg' };
}

function nm_config(bool $reload = false): ?array
{
    static $cfg = false;
    if ($cfg === false || $reload) {
        $path = NM_DATA . '/config.php';
        $cfg = null;
        if (is_file($path)) {
            $v = require $path;
            $cfg = is_array($v) ? $v : null;
        }
    }
    return $cfg;
}

function nm_save_config(array $cfg): void
{
    $php = "<?php\n// NagiManga settings. Edit only if you know what you are doing.\n"
        . "if (!defined('NAGIMANGA')) { http_response_code(404); exit; }\n"
        . 'return ' . var_export($cfg, true) . ";\n";
    nm_write_atomic(NM_DATA . '/config.php', $php);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(NM_DATA . '/config.php', true);
    nm_config(true);
}

function nm_default_config(): array
{
    return [
        'admin_hash' => '',
        'secret' => bin2hex(random_bytes(32)),
        // Part of the admin URL: without it the admin answers 404
        'login_key' => nm_random_id(24),
        'allowed_ips' => [],
        'allowed_origins' => [],
        'trusted_proxies' => [],
        'base_url' => '',
        'image_quality' => 90,
        'max_upload_mb' => 30,
        'installed_at' => time(),
    ];
}

// ---------------------------------------------------------------------------
// Security log (rotated by size)
// ---------------------------------------------------------------------------

function nm_log(string $event, string $detail = ''): void
{
    try {
        $dir = NM_DATA . '/logs';
        nm_ensure_dir($dir);
        $file = $dir . '/security.log';
        if (is_file($file) && filesize($file) > 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        $line = sprintf(
            "%s\t%s\t%s\t%s\n",
            date('c'),
            nm_client_ip(),
            preg_replace('/[^\w.-]/', '', $event),
            str_replace(["\r", "\n", "\t"], ' ', mb_substr($detail, 0, 300))
        );
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        // Logging must never break a request
    }
}

// ---------------------------------------------------------------------------
// Rate limit (file based, per bucket + key)
// ---------------------------------------------------------------------------

function nm_rate_file(string $bucket, string $key): string
{
    return NM_DATA . '/ratelimit/' . hash('sha256', $bucket . '|' . $key) . '.json';
}

/** Number of hits within $window seconds. */
function nm_rate_count(string $bucket, string $key, int $window): int
{
    $data = nm_read_json(nm_rate_file($bucket, $key)) ?? [];
    $now = time();
    return count(array_filter($data, static fn($t) => is_int($t) && $t > $now - $window));
}

function nm_rate_hit(string $bucket, string $key, int $window): int
{
    $file = nm_rate_file($bucket, $key);
    return nm_with_lock('ratelimit', static function () use ($file, $window) {
        $now = time();
        $data = array_values(array_filter(nm_read_json($file) ?? [], static fn($t) => is_int($t) && $t > $now - $window));
        $data[] = $now;
        // Keep the file small
        if (count($data) > 200) $data = array_slice($data, -200);
        nm_write_json($file, $data);
        return count($data);
    });
}

function nm_rate_clear(string $bucket, string $key): void
{
    @unlink(nm_rate_file($bucket, $key));
}

/** Drop rate-limit files nobody touched for a day (cheap, runs occasionally). */
function nm_rate_gc(): void
{
    if (random_int(1, 50) !== 1) return;
    foreach (glob(NM_DATA . '/ratelimit/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
}

// ---------------------------------------------------------------------------
// Works
// ---------------------------------------------------------------------------

/**
 * Folder of a work. The public id is not the folder name: the name is derived
 * from the id with the secret key, so even if the web server ignores the
 * .htaccess of data/, nobody can guess where a (password protected) work's
 * files are.
 */
function nm_work_dir(string $id): string
{
    if (!nm_valid_id($id)) throw new InvalidArgumentException('bad id');
    $cfg = nm_config();
    $secret = (string)($cfg['secret'] ?? '');
    if ($secret === '') throw new RuntimeException('not configured');
    return NM_DATA . '/works/' . substr(hash_hmac('sha256', 'work|' . $id, $secret), 0, 32);
}

/** Create a work's folders, each with an empty index.html against listings. */
function nm_create_work_dirs(string $id): void
{
    nm_ensure_works_root();
    $dir = nm_work_dir($id);
    foreach ([$dir, "$dir/pages"] as $d) {
        nm_ensure_dir($d);
        if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
    }
}

function nm_ensure_works_root(): void
{
    $root = NM_DATA . '/works';
    nm_ensure_dir($root);
    if (!is_file("$root/index.html")) @file_put_contents("$root/index.html", '');
}

function nm_load_work(string $id): ?array
{
    if (!nm_valid_id($id)) return null;
    $w = nm_read_json(nm_work_dir($id) . '/work.json');
    if (!$w || ($w['id'] ?? '') !== $id) return null;
    $w['pages'] = array_values(array_filter($w['pages'] ?? [], static fn($p) =>
        is_array($p) && is_string($p['f'] ?? null) && preg_match(NM_PAGE_PATTERN, $p['f'])));
    foreach ($w['pages'] as &$p) {
        // Optional light (small) version of the page: drop it if malformed
        if (isset($p['m']) && !nm_valid_light($p['m'])) unset($p['m']);
    }
    unset($p);
    return $w;
}

/** A page's light version: ['f' => file, 'w' => int, 'h' => int] */
function nm_valid_light(mixed $m): bool
{
    return is_array($m) && is_string($m['f'] ?? null) && (bool)preg_match(NM_PAGE_PATTERN, $m['f'])
        && (int)($m['w'] ?? 0) > 0 && (int)($m['h'] ?? 0) > 0;
}

function nm_save_work(array $w): void
{
    $w['updated'] = time();
    nm_write_json(nm_work_dir($w['id']) . '/work.json', $w);
}

/** @return array<int, array> newest first */
function nm_list_works(): array
{
    $out = [];
    foreach (glob(NM_DATA . '/works/*/work.json') ?: [] as $file) {
        $id = (string)((nm_read_json($file) ?? [])['id'] ?? '');
        // The folder must be the one derived from the id (ignores stray folders)
        if (!nm_valid_id($id) || realpath(dirname($file)) !== realpath(nm_work_dir($id))) continue;
        $w = nm_load_work($id);
        if ($w) $out[] = $w;
    }
    usort($out, static fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
    return $out;
}

/** Path of a stored page image, or null if it is not a page of this work. */
function nm_page_path(array $work, string $file, bool $thumb = false): ?string
{
    if (!preg_match(NM_PAGE_PATTERN, $file)) return null;
    foreach ($work['pages'] as $p) {
        if ($p['f'] === $file) {
            // The thumbnail has the same name with a "t_" prefix
            $path = nm_work_dir($work['id']) . '/pages/' . ($thumb ? 't_' : '') . $file;
            return is_file($path) ? $path : null;
        }
        // Light version (no thumbnail of its own)
        if (!$thumb && isset($p['m']) && $p['m']['f'] === $file) {
            $path = nm_work_dir($work['id']) . '/pages/' . $file;
            return is_file($path) ? $path : null;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Reader tokens for password-protected works
// ---------------------------------------------------------------------------

/**
 * Token = expiry.signature. The signature covers the work id and a fingerprint
 * of the current password hash, so changing the password revokes old tokens.
 */
function nm_make_token(array $work, int $ttl = 7200): string
{
    $exp = time() + $ttl;
    return $exp . '.' . nm_token_sig($work, $exp);
}

function nm_token_sig(array $work, int $exp): string
{
    $cfg = nm_config();
    $pw = (string)($work['password_hash'] ?? '');
    $sig = hash_hmac('sha256', $work['id'] . '|' . $exp . '|' . hash('sha256', $pw), (string)$cfg['secret'], true);
    return rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
}

function nm_check_token(array $work, string $token): bool
{
    if (!preg_match('/\A([0-9]{10})\.([A-Za-z0-9_-]{43})\z/', $token, $m)) return false;
    $exp = (int)$m[1];
    if ($exp < time()) return false;
    return hash_equals(nm_token_sig($work, $exp), $m[2]);
}

// ---------------------------------------------------------------------------
// Reader passwords, readable again by the admin
// ---------------------------------------------------------------------------

/*
 * A reader password is a shared word the author hands out, so the admin may
 * want to look it up later. It is stored encrypted with a key derived from the
 * secret in config.php (never exported in backups). Checking a password still
 * uses the password_hash only.
 */
function nm_seal_key(): string
{
    $cfg = nm_config();
    return hash('sha256', 'reader-password|' . (string)($cfg['secret'] ?? ''), true);
}

function nm_seal(string $plain): string
{
    $key = nm_seal_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (is_string($ct)) return 'o1:' . base64_encode($iv . $tag . $ct);
    }
    return ''; // No crypto on this server: the password simply cannot be shown later
}

/** @return string|null the password, or null if it cannot be recovered */
function nm_unseal(string $sealed): ?string
{
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false) return null;
    $key = nm_seal_key();
    if (str_starts_with($sealed, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
        return $plain === false ? null : $plain;
    }
    if (str_starts_with($sealed, 'o1:') && function_exists('openssl_decrypt')) {
        if (strlen($raw) <= 28) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Plugins: plugins/*.php, only the files the site owner put there
// ---------------------------------------------------------------------------

$GLOBALS['nm_filters'] = [];

/** Plugins register filters; the core asks for a value through nm_filter(). */
function nm_add_filter(string $name, callable $fn): void
{
    $GLOBALS['nm_filters'][$name][] = $fn;
}

function nm_filter(string $name, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['nm_filters'][$name] ?? [] as $fn) {
        $value = $fn($value, ...$args);
    }
    return $value;
}

function nm_load_plugins(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (glob(NM_ROOT . '/plugins/*.php') ?: [] as $file) {
        // Plain names only; never follow links placed there
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]*\.php\z/', basename($file)) || is_link($file)) continue;
        require_once $file;
    }
}

function nm_work_is_locked(array $work): bool
{
    return !empty($work['password_hash']);
}

/** Individual pages can be switched off; embedded viewers keep working. */
function nm_work_page_public(array $work): bool
{
    return ($work['page'] ?? true) !== false;
}
