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
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>404｜見つかりません</title><link rel="stylesheet" href="' . h($base . '/viewer/404.css?v=' . nm_asset_version(NM_ROOT . '/viewer/404.css')) . '"></head><body class="nm-void">' . nm_blackhole_scene() . '<main><p class="nm-error-number">404</p><h1>このページは、宇宙の彼方へ。</h1><p>探していたページは見つかりませんでした。</p><a href="' . h($base . '/') . '">LOGへ戻る <span aria-hidden="true">↗</span></a><small>Not Found</small></main></body></html>';
        exit;
    }
    echo 'Not Found';
    exit;
}

/** Self-contained SVG: layered light, a warped disk, and an unlit event horizon. */
function nm_blackhole_scene(): string
{
    return <<<'HTML'
<div class="nm-universe" aria-hidden="true"><div class="nm-stars nm-stars-near"></div><div class="nm-stars nm-stars-far"></div><div class="nm-nebula"></div>
<svg class="nm-blackhole" viewBox="0 0 1600 900" focusable="false" xmlns="http://www.w3.org/2000/svg">
<defs>
<radialGradient id="nm-aura"><stop stop-color="#ffd2a1" stop-opacity=".46"/><stop offset=".35" stop-color="#d47852" stop-opacity=".2"/><stop offset=".7" stop-color="#654399" stop-opacity=".08"/><stop offset="1" stop-color="#12091b" stop-opacity="0"/></radialGradient>
<linearGradient id="nm-disk"><stop stop-color="#62334b" stop-opacity="0"/><stop offset=".16" stop-color="#cf7258"/><stop offset=".4" stop-color="#fff0c9"/><stop offset=".51" stop-color="#fffef1"/><stop offset=".65" stop-color="#ffd996"/><stop offset=".86" stop-color="#a54d42"/><stop offset="1" stop-color="#5d2a44" stop-opacity="0"/></linearGradient>
<linearGradient id="nm-lens" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#fffce6"/><stop offset=".36" stop-color="#ffce83"/><stop offset="1" stop-color="#bc5d45" stop-opacity=".4"/></linearGradient>
<radialGradient id="nm-core-light"><stop offset=".78" stop-color="#020206"/><stop offset=".88" stop-color="#030307"/><stop offset=".935" stop-color="#714635"/><stop offset=".961" stop-color="#fff1be"/><stop offset=".98" stop-color="#ad6044"/><stop offset="1" stop-color="#3a1723" stop-opacity="0"/></radialGradient>
<filter id="nm-blur" x="-50%" y="-70%" width="200%" height="240%"><feGaussianBlur stdDeviation="19"/></filter><filter id="nm-glow" x="-30%" y="-70%" width="160%" height="240%"><feGaussianBlur stdDeviation="5"/></filter><clipPath id="nm-front"><path d="M0 400H1600V900H0Z"/></clipPath>
</defs>
<ellipse cx="800" cy="410" rx="670" ry="370" fill="url(#nm-aura)"/>
<g class="nm-disk-glow" fill="none" stroke="url(#nm-disk)" filter="url(#nm-blur)"><ellipse cx="800" cy="413" rx="545" ry="51" stroke-width="29"/><path d="M566 415C586 130 1014 130 1034 415" stroke-width="38"/></g>
<g class="nm-lensing" fill="none" stroke="url(#nm-lens)">
<path d="M569 411C596 133 1004 133 1031 411" stroke-width="14" opacity=".45" filter="url(#nm-glow)"/><path d="M576 406C605 153 995 153 1024 406" stroke-width="7" opacity=".7"/>
<path d="M586 407C621 183 979 183 1014 407" stroke-width="3"/><path d="M596 408C635 206 965 206 1004 408" stroke-width="1.4" opacity=".7"/><path d="M610 407C647 236 953 236 990 407" stroke-width="1.2" opacity=".5"/>
<path d="M576 423C603 628 997 628 1024 423" stroke-width="4" opacity=".26"/>
</g>
<g fill="none" stroke="url(#nm-disk)">
<ellipse cx="800" cy="413" rx="589" ry="57" stroke-width="2" opacity=".24"/><ellipse cx="800" cy="413" rx="564" ry="51" stroke-width="2" opacity=".36"/><ellipse cx="800" cy="413" rx="530" ry="45" stroke-width="3" opacity=".65"/>
<ellipse cx="800" cy="413" rx="507" ry="40" stroke-width="4" opacity=".85"/><ellipse cx="800" cy="413" rx="473" ry="35" stroke-width="5"/><ellipse cx="800" cy="413" rx="441" ry="30" stroke-width="6"/><ellipse cx="800" cy="413" rx="409" ry="26" stroke-width="3"/>
</g>
<circle cx="800" cy="408" r="155" fill="url(#nm-core-light)"/><circle cx="800" cy="408" r="141" fill="#010105"/><circle cx="800" cy="408" r="146" fill="none" stroke="#ffde9b" stroke-width="1.5" opacity=".84"/>
<g class="nm-front-disk" clip-path="url(#nm-front)" fill="none" stroke="url(#nm-disk)">
<ellipse cx="800" cy="413" rx="535" ry="50" stroke-width="20" opacity=".7" filter="url(#nm-glow)"/><ellipse cx="800" cy="413" rx="540" ry="53" stroke-width="1" opacity=".5"/>
<ellipse cx="800" cy="413" rx="512" ry="48" stroke-width="2"/><ellipse cx="800" cy="413" rx="486" ry="43" stroke-width="3"/><ellipse cx="800" cy="413" rx="458" ry="39" stroke-width="4"/><ellipse cx="800" cy="413" rx="428" ry="34" stroke-width="5"/>
<ellipse cx="800" cy="413" rx="400" ry="29" stroke-width="2"/><ellipse cx="800" cy="413" rx="370" ry="26" stroke-width="1" opacity=".7"/>
</g>
<g fill="#fff3dc"><circle cx="248" cy="202" r="1.4"/><circle cx="1345" cy="119" r="1.2"/><circle cx="1261" cy="657" r="1.6"/><circle cx="467" cy="673" r="1.1"/><circle cx="1034" cy="77" r="1"/></g>
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
