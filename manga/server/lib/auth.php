<?php
/*
 * NagiManga admin authentication
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Layers (each one alone answers 404):
 *   1. IP allow list (config, checked here even if .htaccess is not honoured)
 *   2. Secret login key in the URL (scanners never find the login form)
 *   3. Password + lockout after repeated failures
 *   4. Session bound to the browser, idle / absolute timeouts
 *   5. CSRF token + same-origin check on every POST
 *
 * Roles: "admin" (the password above) and, only when a plugin enables it,
 * "guest" (read only, e.g. for a public demo). What a guest may do is decided
 * here and in admin/index.php, never by the plugin.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_SESSION_IDLE = 1800;       // 30 min
const NM_SESSION_MAX = 43200;       // 12 h
const NM_LOGIN_FAIL_MAX = 5;        // per IP / 15 min
const NM_LOGIN_FAIL_GLOBAL = 40;    // all IPs / hour (distributed guessing)

function nm_ip_allowed(array $cfg): bool
{
    $list = $cfg['allowed_ips'] ?? [];
    if (!$list) return true;
    $ip = nm_client_ip();
    foreach ($list as $rule) {
        if (nm_ip_match($ip, (string)$rule)) return true;
    }
    return false;
}

/** Exact IP or CIDR (IPv4 / IPv6). */
function nm_ip_match(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') return false;
    if (!str_contains($rule, '/')) return $ip === $rule;
    [$net, $bits] = explode('/', $rule, 2);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8);
    $rest = $bits % 8;
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rest === 0) return true;
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
}

function nm_valid_ip_rule(string $rule): bool
{
    if (filter_var($rule, FILTER_VALIDATE_IP)) return true;
    if (!preg_match('~\A([0-9a-fA-F:.]+)/([0-9]{1,3})\z~', $rule, $m)) return false;
    if (!filter_var($m[1], FILTER_VALIDATE_IP)) return false;
    $max = str_contains($m[1], ':') ? 128 : 32;
    return (int)$m[2] <= $max;
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function nm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = NM_DATA . '/sessions';
    nm_ensure_dir($dir);
    // Own storage: shared hosts often share /tmp between customers
    session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)NM_SESSION_MAX);
    session_name('nm_admin');
    $path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\') . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => nm_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function nm_browser_fingerprint(): string
{
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function nm_is_logged_in(): bool
{
    if (empty($_SESSION['nm_admin'])) return false;
    $now = time();
    $ok = ($_SESSION['nm_fp'] ?? '') === nm_browser_fingerprint()
        && $now - (int)($_SESSION['nm_seen'] ?? 0) < NM_SESSION_IDLE
        && $now - (int)($_SESSION['nm_login'] ?? 0) < NM_SESSION_MAX
        // Password changed elsewhere: log this session out
        && ($_SESSION['nm_ver'] ?? '') === nm_admin_version();
    if (!$ok) {
        nm_logout();
        return false;
    }
    $_SESSION['nm_seen'] = $now;
    return true;
}

function nm_admin_version(): string
{
    $cfg = nm_config();
    return substr(hash('sha256', (string)($cfg['admin_hash'] ?? '')), 0, 16);
}

function nm_login_blocked(): bool
{
    $ip = nm_client_ip();
    return nm_rate_count('login', $ip, 900) >= NM_LOGIN_FAIL_MAX
        || nm_rate_count('login-all', 'all', 3600) >= NM_LOGIN_FAIL_GLOBAL;
}

function nm_try_login(string $password): bool
{
    $cfg = nm_config();
    $ip = nm_client_ip();
    if ($password !== '' && password_verify($password, (string)$cfg['admin_hash'])) {
        if (password_needs_rehash((string)$cfg['admin_hash'], PASSWORD_DEFAULT)) {
            $cfg['admin_hash'] = password_hash($password, PASSWORD_DEFAULT);
            nm_save_config($cfg);
        }
        session_regenerate_id(true);
        $_SESSION = [
            'nm_admin' => true,
            'nm_fp' => nm_browser_fingerprint(),
            'nm_login' => time(),
            'nm_seen' => time(),
            'nm_ver' => nm_admin_version(),
            'nm_csrf' => bin2hex(random_bytes(32)),
            'nm_role' => 'admin',
        ];
        nm_rate_clear('login', $ip);
        nm_log('login_ok');
        return true;
    }
    nm_rate_hit('login', $ip, 900);
    nm_rate_hit('login-all', 'all', 3600);
    nm_log('login_failed');
    usleep(random_int(400000, 900000));
    return false;
}

/**
 * Forget the login but keep a (new, empty) session: the same request may still
 * need it, e.g. the login form token right after an idle timeout. Destroying
 * the session here made the next login attempt fail.
 */
function nm_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        // New id, old session file deleted
        session_regenerate_id(true);
    }
}

// ---------------------------------------------------------------------------
// Guest (read only)
// ---------------------------------------------------------------------------

/**
 * Guest settings from a plugin (plugins/guest-mode.php), or null when no
 * plugin enables guests.
 *
 * @return array{enter_key:string, bypass_ip:bool, banner:string, exit_url:string}|null
 */
function nm_guest_config(): ?array
{
    $g = nm_filter('guest_mode', null);
    if (!is_array($g)) return null;
    $exit = (string)($g['exit_url'] ?? '');
    return [
        'enter_key' => (string)($g['enter_key'] ?? ''),
        'bypass_ip' => (bool)($g['bypass_ip'] ?? true),
        'banner' => (string)($g['banner'] ?? 'ゲスト（閲覧のみ）で表示しています。内容の変更はできません。'),
        // Where "ゲストを終了" leads: an absolute http(s) URL or a site path, nothing else
        'exit_url' => preg_match('~\A(https?://|/(?!/))[^\s"\'<>\\\\]*\z~i', $exit) ? $exit : '',
    ];
}

function nm_is_guest(): bool
{
    return !empty($_SESSION['nm_admin']) && ($_SESSION['nm_role'] ?? '') === 'guest';
}

function nm_start_guest(): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'nm_admin' => true,
        'nm_role' => 'guest',
        'nm_fp' => nm_browser_fingerprint(),
        'nm_login' => time(),
        'nm_seen' => time(),
        'nm_ver' => nm_admin_version(),
        'nm_csrf' => bin2hex(random_bytes(32)),
    ];
    nm_log('guest_enter');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function nm_csrf_token(): string
{
    if (empty($_SESSION['nm_csrf'])) $_SESSION['nm_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['nm_csrf'];
}

/**
 * POSTs must carry the session token and, when the browser tells us, come
 * from this very host.
 */
function nm_csrf_ok(): bool
{
    $sent = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_NM_CSRF'] ?? '');
    $expect = (string)($_SESSION['nm_csrf'] ?? '');
    if ($expect === '' || !hash_equals($expect, $sent)) return false;

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $v = (string)($_SERVER[$h] ?? '');
        if ($v === '' || $v === 'null') continue;
        $vh = parse_url($v, PHP_URL_HOST);
        $vp = parse_url($v, PHP_URL_PORT);
        $origin = $vh . ($vp ? ':' . $vp : '');
        return is_string($vh) && strcasecmp($origin, $host) === 0;
    }
    return true;
}
