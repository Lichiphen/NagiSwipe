<?php
/*
 * NagiManga admin authentication
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Layers (each one alone answers 404):
 *   1. IP allow list (config, checked here even if .htaccess is not honoured)
 *   2. Secret login key in the URL (scanners never find the login form)
 *   3. Password + lockout after repeated failures (how many, and for how long, is a setting), then (when turned on) a code from an
 *      authenticator app (lib/totp.php), counted against the same lockout
 *   4. Session bound to the browser; the owner stays signed in for 30 or 365
 *      days after logging in (setting), guests keep the short idle / absolute timeouts
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

const NM_SESSION_IDLE = 1800;       // 30 min (guest)
const NM_SESSION_MAX = 43200;       // 12 h (guest)
const NM_LOGIN_DAYS = [30, 365];    // owner: days from the last login, first one is the default
const NM_SESSION_GC = 31708800;     // 367 days: PHP must not collect a session that is still valid
const NM_LOGIN_FAIL_MAX = 5;        // per IP / 15 min (default; the setting allows NM_LOGIN_FAIL_CHOICES)
const NM_LOGIN_FAIL_CHOICES = [3, 4, 5, 6, 7, 8, 9, 10];
const NM_LOGIN_LOCKS = [900, 3600, 21600, 86400];   // how long an address is shut out: 15 min (default), 1 h, 6 h, 24 h
const NM_LOGIN_FAIL_GLOBAL = 40;    // all IPs / hour (distributed guessing); only browsers without the owner's mark wait
const NM_DEVICE_COOKIE = 'nm_device';   // the owner's mark: this browser has logged in before (see nm_device_id())
const NM_DEVICE_DAYS = 400;

/**
 * Whether admin/login.php (the LOG's "ログイン" link) may show the login form. "secret": only the secret login URL
 * (admin/index.php?k=…) does, and a shortened address answers 404 like any other. Installs from before the setting
 * existed have no value and keep the public entrance until it is turned off.
 */
function nm_public_login(?array $cfg = null): bool
{
    return (($cfg ?? nm_config() ?? [])['login_entry'] ?? 'public') !== 'secret';
}

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
    ini_set('session.gc_maxlifetime', (string)NM_SESSION_GC);
    session_name('nm_admin');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $path = rtrim(dirname($script), '/\\') . '/';
    if (preg_match('~/admin/[^/]+$~', $script)) $path = rtrim(dirname($script, 2), '/\\') . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => nm_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    $GLOBALS['nm_session_path'] = $path;
    session_start();
    nm_session_sweep($dir);
    // The public LOG can recognize the owner with the same protected session.
    // Migrate the former /admin/ cookie when an existing admin visits this page.
    if (preg_match('~/admin/[^/]+$~', $script)) {
        $params = session_get_cookie_params();
        setcookie('nm_admin', session_id(), ['expires' => 0, 'path' => $path, 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        setcookie('nm_admin', '', ['expires' => time() - 3600, 'path' => rtrim(dirname($script), '/\\') . '/', 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
}

/**
 * Sessions now live up to a year, so PHP's own collector is set far out. Now and
 * then drop the files that never became a login (login form visits) after a day.
 */
function nm_session_sweep(string $dir): void
{
    if (random_int(1, 50) !== 1) return;
    $now = time();
    foreach (glob($dir . '/sess_*') ?: [] as $file) {
        $age = $now - (int)@filemtime($file);
        if ($file === $dir . '/sess_' . session_id() || $age < 86400) continue;
        if ($age > NM_SESSION_GC || !str_contains((string)@file_get_contents($file, false, null, 0, 4096), 'nm_admin|b:1;')) @unlink($file);
    }
}

/** How long the owner stays signed in after logging in (setting: 30 or 365 days). */
function nm_login_days(): int
{
    $days = (int)(nm_config()['login_days'] ?? NM_LOGIN_DAYS[0]);
    return in_array($days, NM_LOGIN_DAYS, true) ? $days : NM_LOGIN_DAYS[0];
}

/** Keep the owner's cookie until the login expires, so closing the browser does not log out. */
function nm_session_keep(): void
{
    if (headers_sent() || ($_SESSION['nm_role'] ?? '') !== 'admin') return;
    $params = session_get_cookie_params();
    setcookie('nm_admin', session_id(), ['expires' => (int)$_SESSION['nm_login'] + nm_login_days() * 86400, 'path' => $GLOBALS['nm_session_path'] ?? $params['path'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
}

/** Version numbers are left out: browser updates must not end a year-long login. */
function nm_browser_fingerprint(): string
{
    return hash('sha256', (string)preg_replace('/[0-9]+(?:[._][0-9]+)*/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
}

function nm_is_logged_in(): bool
{
    if (empty($_SESSION['nm_admin'])) return false;
    $now = time();
    $guest = ($_SESSION['nm_role'] ?? '') === 'guest';
    $ok = ($_SESSION['nm_fp'] ?? '') === nm_browser_fingerprint()
        && (!$guest || $now - (int)($_SESSION['nm_seen'] ?? 0) < NM_SESSION_IDLE)
        && $now - (int)($_SESSION['nm_login'] ?? 0) < ($guest ? NM_SESSION_MAX : nm_login_days() * 86400)
        // Password changed elsewhere: log this session out
        && ($_SESSION['nm_ver'] ?? '') === nm_admin_version();
    if (!$ok) {
        nm_logout();
        return false;
    }
    $_SESSION['nm_seen'] = $now;
    nm_session_keep();
    if (!$guest && nm_device_id() === null) nm_device_mark();
    return true;
}

function nm_admin_version(): string
{
    $cfg = nm_config();
    // Turning the two-step login on or off also logs out the other devices.
    $since = is_array($cfg['totp'] ?? null) ? '|' . (int)($cfg['totp']['since'] ?? 0) : '';
    return substr(hash('sha256', (string)($cfg['admin_hash'] ?? '') . $since), 0, 16);
}

/** Wrong answers (password, code, passkey) one address may make within 15 minutes (setting, 3–10). */
function nm_login_fail_max(?array $cfg = null): int
{
    $n = (int)(($cfg ?? nm_config() ?? [])['login_fail_max'] ?? NM_LOGIN_FAIL_MAX);
    return in_array($n, NM_LOGIN_FAIL_CHOICES, true) ? $n : NM_LOGIN_FAIL_MAX;
}
/** Seconds an address stays shut out after that (setting: 15 minutes, 1, 6 or 24 hours). */
function nm_login_lock(?array $cfg = null): int
{
    $s = (int)(($cfg ?? nm_config() ?? [])['login_lock'] ?? NM_LOGIN_LOCKS[0]);
    return in_array($s, NM_LOGIN_LOCKS, true) ? $s : NM_LOGIN_LOCKS[0];
}

// ---------------------------------------------------------------------------
// The owner's browsers: a mark from a past login keeps the site-wide limit from shutting the owner out
// ---------------------------------------------------------------------------

/** The mark's id when this browser carries a valid one (signed with the secret key and the current password). */
function nm_device_id(): ?string
{
    $raw = (string)($_COOKIE[NM_DEVICE_COOKIE] ?? '');
    if (!preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/', $raw, $m)) return null;
    return hash_equals(nm_device_sig($m[1]), $m[2]) ? $m[1] : null;
}

/** A new password (or turning the two-step login on or off) changes nm_admin_version(), so every old mark stops counting. */
function nm_device_sig(string $id): string
{
    return hash_hmac('sha256', 'login-device|' . $id . '|' . nm_admin_version(), (string)(nm_config()['secret'] ?? ''));
}

/** A marked browser that keeps failing loses the mark's privilege, so a stolen mark cannot guess for long. */
function nm_device_trusted(): bool
{
    $id = nm_device_id();
    return $id !== null && nm_rate_count('login-device', $id, 86400) < nm_login_fail_max();
}

/** Give (or renew) the mark after a login, or to an owner signed in since before the marks existed. */
function nm_device_mark(): void
{
    if (headers_sent()) return;
    $id = nm_device_id() ?? bin2hex(random_bytes(16));
    $value = $id . '.' . nm_device_sig($id);
    setcookie(NM_DEVICE_COOKIE, $value, ['expires' => time() + NM_DEVICE_DAYS * 86400, 'path' => $GLOBALS['nm_session_path'] ?? session_get_cookie_params()['path'], 'secure' => nm_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    $_COOKIE[NM_DEVICE_COOKIE] = $value;
}

/**
 * Why this browser may not try a password now:
 *   'ip'   too many failures from its address (IPv6: its /64): the login form answers 404 (a marked browser is
 *          counted by its mark instead, and after too many failures there it is treated as unmarked)
 *   'busy' the whole site saw NM_LOGIN_FAIL_GLOBAL failures within the hour: the form stays, but a password is only
 *          taken from a marked browser or an address on the allow list; passkeys always work
 *   ''     free to try
 */
function nm_login_block(): string
{
    if (nm_device_trusted()) return '';
    $net = nm_client_net();
    if (nm_rate_count('login', $net, 900) >= nm_login_fail_max() || nm_rate_count('login-lock', $net, nm_login_lock()) > 0) return 'ip';
    $cfg = nm_config() ?? [];
    if (!empty($cfg['allowed_ips']) && nm_ip_allowed($cfg)) return '';
    return nm_rate_count('login-all', 'all', 3600) >= NM_LOGIN_FAIL_GLOBAL ? 'busy' : '';
}

function nm_login_blocked(): bool
{
    return nm_login_block() !== '';
}

/** The admin password; a wrong one counts towards the lockout. */
function nm_password_ok(string $password): bool
{
    $cfg = nm_config();
    if ($password !== '' && password_verify($password, (string)$cfg['admin_hash'])) {
        if (password_needs_rehash((string)$cfg['admin_hash'], PASSWORD_DEFAULT)) {
            $cfg['admin_hash'] = password_hash($password, PASSWORD_DEFAULT);
            nm_save_config($cfg);
        }
        return true;
    }
    nm_login_failed('login_failed');
    return false;
}

/**
 * A wrong answer. A marked browser counts against its mark only (the owner's typos never lock the address or the
 * site); others count against their address and, unless it is a passkey (which cannot be guessed), the whole site.
 */
function nm_login_failed(string $event, bool $guessable = true): void
{
    nm_log($event);
    $device = nm_device_id();
    if ($device !== null && nm_device_trusted()) {
        if (nm_rate_hit('login-device', $device, 86400) === nm_login_fail_max()) nm_log('login_device_locked');
    } else {
        $net = nm_client_net();
        $count = nm_rate_hit('login', $net, 900);
        if ($guessable) nm_rate_hit('login-all', 'all', 3600);
        // The failure that reaches the limit starts the lock (kept apart, so a long lock outlives the 15-minute count).
        if ($count === nm_login_fail_max()) {
            nm_rate_hit('login-lock', $net, nm_login_lock());
            nm_log('login_locked', (string)intdiv(nm_login_lock(), 60) . 'min');
        }
    }
    usleep(random_int(400000, 900000));
}

/**
 * Password, and the code when the two-step login is on. Returns true (logged in), false (wrong),
 * or 'code' (the password was right; the login form asks for the code next).
 */
function nm_try_login(string $password): bool|string
{
    if (!nm_password_ok($password)) return false;
    if (nm_totp_on()) {
        session_regenerate_id(true);
        $_SESSION = ['nm_2fa' => time(), 'nm_2fa_fp' => nm_browser_fingerprint()];
        nm_log('login_password_ok');
        return 'code';
    }
    nm_start_admin_session();
    return true;
}

/** Waiting for the code after the right password (5 minutes, same browser). */
function nm_code_pending(): bool
{
    return isset($_SESSION['nm_2fa']) && time() - (int)$_SESSION['nm_2fa'] < NM_TOTP_WAIT && ($_SESSION['nm_2fa_fp'] ?? '') === nm_browser_fingerprint();
}

/** The second step: true when the code (or a recovery code) is right and the login is done. */
function nm_try_code(string $code): bool
{
    if (!nm_code_pending()) return false;
    $kind = nm_totp_verify($code);
    if ($kind === null) {
        nm_login_failed('totp_failed');
        return false;
    }
    nm_start_admin_session($kind === 'recovery' ? 'login_ok_recovery' : 'login_ok');
    return true;
}

function nm_start_admin_session(string $event = 'login_ok'): void
{
    $device = nm_device_id();
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
    nm_session_keep();
    nm_rate_clear('login', nm_client_net());
    if ($device !== null) nm_rate_clear('login-device', $device);
    nm_device_mark();
    nm_log($event);
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
        'banner' => (string)($g['banner'] ?? nm_t('ゲスト（閲覧のみ）で表示しています。内容の変更はできません。')),
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
