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
const NM_PAGE_PATTERN = '/\A(p[0-9]{4}_[a-f0-9]{8})\.(webp|jpg)\z/';
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

/** Self-contained SVG: the inside of the hole, dozens of beige ribbons rippling in warm light. */
function nm_blackhole_scene(): string
{
    return <<<'HTML'
<div class="nm-universe" aria-hidden="true">
<svg class="nm-blackhole" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMid slice" focusable="false" xmlns="http://www.w3.org/2000/svg">
<defs><radialGradient id="nm-inner-light" cx=".5" cy=".42" r=".7"><stop stop-color="#fffaf0"/><stop offset=".45" stop-color="#f1e3cc"/><stop offset="1" stop-color="#c9ab86"/></radialGradient><filter id="nm-silk" x="-5%" y="-30%" width="110%" height="160%"><feGaussianBlur stdDeviation="2.4"/></filter></defs>
<rect width="1600" height="900" fill="url(#nm-inner-light)"/>
<g class="nm-waves" fill="none" stroke-linecap="round" filter="url(#nm-silk)">
<path class="nm-wave nm-l500" d="M-500 56Q-375 37 -250 56T0 56T250 56T500 56T750 56T1000 56T1250 56T1500 56T1750 56T2000 56T2250 56T2500 56" stroke="#c8a983" stroke-width="10" opacity="0.54"/>
<path class="nm-wave nm-l800 nm-wave-rev" d="M-800 78Q-600 55 -400 78T0 78T400 78T800 78T1200 78T1600 78T2000 78T2400 78T2800 78T3200 78" stroke="#dfc6a2" stroke-width="16" opacity="0.77"/>
<path class="nm-wave nm-l640" d="M-640 99Q-480 62 -320 99T0 99T320 99T640 99T960 99T1280 99T1600 99T1920 99T2240 99T2560 99T2880 99" stroke="#c8a983" stroke-width="16" opacity="0.49"/>
<path class="nm-wave nm-l400" d="M-400 128Q-300 108 -200 128T0 128T200 128T400 128T600 128T800 128T1000 128T1200 128T1400 128T1600 128T1800 128T2000 128T2200 128T2400 128" stroke="#d4b791" stroke-width="24" opacity="0.78"/>
<path class="nm-wave nm-l400 nm-wave-rev" d="M-400 147Q-300 113 -200 147T0 147T200 147T400 147T600 147T800 147T1000 147T1200 147T1400 147T1600 147T1800 147T2000 147T2200 147T2400 147" stroke="#efdfc6" stroke-width="48" opacity="0.78"/>
<path class="nm-wave nm-l500" d="M-500 184Q-375 170 -250 184T0 184T250 184T500 184T750 184T1000 184T1250 184T1500 184T1750 184T2000 184T2250 184T2500 184" stroke="#dfc6a2" stroke-width="16" opacity="0.41"/>
<path class="nm-wave nm-l500" d="M-500 211Q-375 187 -250 211T0 211T250 211T500 211T750 211T1000 211T1250 211T1500 211T1750 211T2000 211T2250 211T2500 211" stroke="#ad8b6a" stroke-width="34" opacity="0.36"/>
<path class="nm-wave nm-l400 nm-wave-rev" d="M-400 221Q-300 189 -200 221T0 221T200 221T400 221T600 221T800 221T1000 221T1200 221T1400 221T1600 221T1800 221T2000 221T2200 221T2400 221" stroke="#c8a983" stroke-width="10" opacity="0.37"/>
<path class="nm-wave nm-l640" d="M-640 252Q-480 227 -320 252T0 252T320 252T640 252T960 252T1280 252T1600 252T1920 252T2240 252T2560 252T2880 252" stroke="#ad8b6a" stroke-width="16" opacity="0.43"/>
<path class="nm-wave nm-l400" d="M-400 277Q-300 240 -200 277T0 277T200 277T400 277T600 277T800 277T1000 277T1200 277T1400 277T1600 277T1800 277T2000 277T2200 277T2400 277" stroke="#c8a983" stroke-width="48" opacity="0.63"/>
<path class="nm-wave nm-l500 nm-wave-rev" d="M-500 287Q-375 259 -250 287T0 287T250 287T500 287T750 287T1000 287T1250 287T1500 287T1750 287T2000 287T2250 287T2500 287" stroke="#e2cdb0" stroke-width="48" opacity="0.37"/>
<path class="nm-wave nm-l640" d="M-640 324Q-480 288 -320 324T0 324T320 324T640 324T960 324T1280 324T1600 324T1920 324T2240 324T2560 324T2880 324" stroke="#bb9a76" stroke-width="34" opacity="0.59"/>
<path class="nm-wave nm-l640" d="M-640 347Q-480 303 -320 347T0 347T320 347T640 347T960 347T1280 347T1600 347T1920 347T2240 347T2560 347T2880 347" stroke="#dfc6a2" stroke-width="48" opacity="0.73"/>
<path class="nm-wave nm-l400 nm-wave-rev" d="M-400 374Q-300 324 -200 374T0 374T200 374T400 374T600 374T800 374T1000 374T1200 374T1400 374T1600 374T1800 374T2000 374T2200 374T2400 374" stroke="#efdfc6" stroke-width="6" opacity="0.50"/>
<path class="nm-wave nm-l640" d="M-640 384Q-480 363 -320 384T0 384T320 384T640 384T960 384T1280 384T1600 384T1920 384T2240 384T2560 384T2880 384" stroke="#e8d3b4" stroke-width="24" opacity="0.79"/>
<path class="nm-wave nm-l400" d="M-400 414Q-300 377 -200 414T0 414T200 414T400 414T600 414T800 414T1000 414T1200 414T1400 414T1600 414T1800 414T2000 414T2200 414T2400 414" stroke="#dfc6a2" stroke-width="24" opacity="0.71"/>
<path class="nm-wave nm-l500 nm-wave-rev" d="M-500 428Q-375 380 -250 428T0 428T250 428T500 428T750 428T1000 428T1250 428T1500 428T1750 428T2000 428T2250 428T2500 428" stroke="#e2cdb0" stroke-width="16" opacity="0.45"/>
<path class="nm-wave nm-l500" d="M-500 453Q-375 428 -250 453T0 453T250 453T500 453T750 453T1000 453T1250 453T1500 453T1750 453T2000 453T2250 453T2500 453" stroke="#f6ecdc" stroke-width="16" opacity="0.79"/>
<path class="nm-wave nm-l400" d="M-400 477Q-300 436 -200 477T0 477T200 477T400 477T600 477T800 477T1000 477T1200 477T1400 477T1600 477T1800 477T2000 477T2200 477T2400 477" stroke="#dfc6a2" stroke-width="24" opacity="0.77"/>
<path class="nm-wave nm-l500 nm-wave-rev" d="M-500 503Q-375 464 -250 503T0 503T250 503T500 503T750 503T1000 503T1250 503T1500 503T1750 503T2000 503T2250 503T2500 503" stroke="#e8d3b4" stroke-width="24" opacity="0.52"/>
<path class="nm-wave nm-l640" d="M-640 540Q-480 500 -320 540T0 540T320 540T640 540T960 540T1280 540T1600 540T1920 540T2240 540T2560 540T2880 540" stroke="#f6ecdc" stroke-width="34" opacity="0.67"/>
<path class="nm-wave nm-l800" d="M-800 544Q-600 513 -400 544T0 544T400 544T800 544T1200 544T1600 544T2000 544T2400 544T2800 544T3200 544" stroke="#e2cdb0" stroke-width="24" opacity="0.38"/>
<path class="nm-wave nm-l640 nm-wave-rev" d="M-640 580Q-480 544 -320 580T0 580T320 580T640 580T960 580T1280 580T1600 580T1920 580T2240 580T2560 580T2880 580" stroke="#d4b791" stroke-width="34" opacity="0.50"/>
<path class="nm-wave nm-l800" d="M-800 609Q-600 573 -400 609T0 609T400 609T800 609T1200 609T1600 609T2000 609T2400 609T2800 609T3200 609" stroke="#e2cdb0" stroke-width="16" opacity="0.77"/>
<path class="nm-wave nm-l640" d="M-640 623Q-480 558 -320 623T0 623T320 623T640 623T960 623T1280 623T1600 623T1920 623T2240 623T2560 623T2880 623" stroke="#efdfc6" stroke-width="6" opacity="0.78"/>
<path class="nm-wave nm-l400 nm-wave-rev" d="M-400 639Q-300 572 -200 639T0 639T200 639T400 639T600 639T800 639T1000 639T1200 639T1400 639T1600 639T1800 639T2000 639T2200 639T2400 639" stroke="#c8a983" stroke-width="16" opacity="0.50"/>
<path class="nm-wave nm-l400" d="M-400 680Q-300 634 -200 680T0 680T200 680T400 680T600 680T800 680T1000 680T1200 680T1400 680T1600 680T1800 680T2000 680T2200 680T2400 680" stroke="#efdfc6" stroke-width="10" opacity="0.50"/>
<path class="nm-wave nm-l640" d="M-640 689Q-480 631 -320 689T0 689T320 689T640 689T960 689T1280 689T1600 689T1920 689T2240 689T2560 689T2880 689" stroke="#d4b791" stroke-width="6" opacity="0.60"/>
<path class="nm-wave nm-l800 nm-wave-rev" d="M-800 713Q-600 660 -400 713T0 713T400 713T800 713T1200 713T1600 713T2000 713T2400 713T2800 713T3200 713" stroke="#f6ecdc" stroke-width="16" opacity="0.43"/>
<path class="nm-wave nm-l500" d="M-500 751Q-375 711 -250 751T0 751T250 751T500 751T750 751T1000 751T1250 751T1500 751T1750 751T2000 751T2250 751T2500 751" stroke="#efdfc6" stroke-width="10" opacity="0.73"/>
<path class="nm-wave nm-l500" d="M-500 758Q-375 711 -250 758T0 758T250 758T500 758T750 758T1000 758T1250 758T1500 758T1750 758T2000 758T2250 758T2500 758" stroke="#e8d3b4" stroke-width="48" opacity="0.60"/>
<path class="nm-wave nm-l400 nm-wave-rev" d="M-400 791Q-300 760 -200 791T0 791T200 791T400 791T600 791T800 791T1000 791T1200 791T1400 791T1600 791T1800 791T2000 791T2200 791T2400 791" stroke="#dfc6a2" stroke-width="6" opacity="0.69"/>
<path class="nm-wave nm-l500" d="M-500 810Q-375 739 -250 810T0 810T250 810T500 810T750 810T1000 810T1250 810T1500 810T1750 810T2000 810T2250 810T2500 810" stroke="#f6ecdc" stroke-width="10" opacity="0.50"/>
<path class="nm-wave nm-l400" d="M-400 828Q-300 753 -200 828T0 828T200 828T400 828T600 828T800 828T1000 828T1200 828T1400 828T1600 828T1800 828T2000 828T2200 828T2400 828" stroke="#e8d3b4" stroke-width="6" opacity="0.39"/>
</g>
<ellipse class="nm-inner-glow" cx="800" cy="380" rx="520" ry="260" fill="#fffaf2"/>
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
