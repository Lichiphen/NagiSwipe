<?php
/*
 * Password locks for posts and fixed pages. MIT License (c) 2026 Lichiphen.
 * A record's 'lock' holds the password hash (and the password sealed for the owner, like a manga's reader password).
 * A reader who types the password gets a cookie with a signed key for that page, never the password itself:
 * the form is not a password form, so browsers do not offer to save it, and autocomplete stays off.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_LOCK_COOKIE = 'nl_keys';
const NL_LOCK_DAYS = 30;
/** Keys kept in the cookie; the oldest fall out first. */
const NL_LOCK_KEYS_MAX = 40;
const NL_LOCK_MIN = 4;
const NL_LOCK_MAX = 200;
const NL_LOCK_ITEM_PATTERN = '/\A(?:pg[a-f0-9]{12}|[0-9]{14}(?:-[0-9]{2,6})?|d[a-f0-9]{16})\z/';

function nl_lock_hash(array $item): string
{
    $hash = is_array($item['lock'] ?? null) ? ($item['lock']['hash'] ?? '') : '';
    return is_string($hash) ? $hash : '';
}
function nl_is_locked(array $item): bool { return nl_lock_hash($item) !== ''; }
/** The owner's view of the password, or null when it cannot be shown (set on another server). */
function nl_lock_plain(array $item): ?string
{
    $enc = is_array($item['lock'] ?? null) ? (string)($item['lock']['enc'] ?? '') : '';
    return $enc !== '' ? nm_unseal($enc) : null;
}
/**
 * The lock to save from an editor: the switch, the typed password and the record before.
 * An empty field with the switch on keeps the old password. Returns null for no lock.
 */
function nl_lock_input(bool $on, string $password, ?array $old): ?array
{
    if (!$on) return null;
    $password = trim($password);
    if ($password === '') {
        if ($old && nl_is_locked($old)) return $old['lock'];
        throw new UnexpectedValueException(nm_t('パスワードを入れてください（{min}〜{max}文字）', ['min' => NL_LOCK_MIN, 'max' => NL_LOCK_MAX]));
    }
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < NL_LOCK_MIN || mb_strlen($password) > NL_LOCK_MAX || preg_match('/[\x00-\x1F\x7F]/', $password)) {
        throw new UnexpectedValueException(nm_t('パスワードは{min}〜{max}文字の1行にしてください', ['min' => NL_LOCK_MIN, 'max' => NL_LOCK_MAX]));
    }
    // The same password again keeps the hash, so readers who opened the page stay in.
    if ($old && nl_is_locked($old) && password_verify($password, nl_lock_hash($old))) return $old['lock'];
    return ['hash' => password_hash($password, PASSWORD_DEFAULT), 'enc' => nm_seal($password)];
}
/** A lock read from a backup: a password hash and, if any, the sealed password. */
function nl_lock_restore(mixed $lock): ?array
{
    if ($lock === null || $lock === '' || $lock === []) return null;
    $hash = is_array($lock) ? ($lock['hash'] ?? null) : null;
    $enc = is_array($lock) ? ($lock['enc'] ?? '') : null;
    if (!is_string($hash) || strlen($hash) > 255 || !preg_match('/\A\$(?:2y|argon2id|argon2i)\$[!-~]+\z/', $hash)
        || !is_string($enc) || strlen($enc) > 2000 || ($enc !== '' && !preg_match('~\A[so]1:[A-Za-z0-9+/=]+\z~', $enc))) throw new UnexpectedValueException(nm_t('パスワードの設定が壊れています'));
    return ['hash' => $hash, 'enc' => $enc];
}
/** The key a reader keeps for one page. It changes with the password, so a new password locks everyone out again. */
function nl_lock_token(string $id, string $hash): string
{
    $mac = hash_hmac('sha256', 'log-lock|' . $id . '|' . $hash, (string)(nm_config()['secret'] ?? ''), true);
    return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
}
/** The keys this browser holds: id => key. */
function nl_lock_keys(): array
{
    $raw = $_COOKIE[NL_LOCK_COOKIE] ?? '';
    if (!is_string($raw) || $raw === '' || strlen($raw) > 4000) return [];
    $out = [];
    foreach (explode('.', $raw) as $pair) {
        if (preg_match('/\A([0-9a-z-]{12,24}):([A-Za-z0-9_-]{22})\z/', $pair, $m) && preg_match(NL_LOCK_ITEM_PATTERN, $m[1])) $out[$m[1]] = $m[2];
    }
    return array_slice($out, -NL_LOCK_KEYS_MAX, null, true);
}
/** Whether the page shows a locked record's content: always for the owner, otherwise with the right key. */
function nl_unlocked(array $item): bool
{
    if (!nl_is_locked($item) || (defined('NL_OWNER') && NL_OWNER)) return true;
    $key = nl_lock_keys()[(string)($item['id'] ?? '')] ?? '';
    return $key !== '' && hash_equals(nl_lock_token((string)$item['id'], nl_lock_hash($item)), $key);
}
/** For a summary of the index: a locked post is read to check the key. */
function nl_summary_unlocked(array $summary): bool
{
    if (empty($summary['locked']) || (defined('NL_OWNER') && NL_OWNER)) return true;
    $p = nl_load_post((string)$summary['id']);
    return $p !== null && nl_unlocked($p);
}
function nl_lock_cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/') . '/';
}
/** The public address of a post or a fixed page, with the lock form's answer when there is one. */
function nl_lock_target(array $item, bool $page, string $state = ''): string
{
    $url = nl_base_url() . '/' . ($page ? nl_fixed_path($item['slug']) : nl_post_path($item['id']));
    return $url . ($state !== '' ? (str_contains($url, '?') ? '&' : '?') . 'locked=' . $state : '');
}
/**
 * The lock form was sent (POST with nl_unlock). A right password adds the page's key to the cookie;
 * every answer goes back to the page (303), so reloading never sends the password again.
 */
function nl_unlock_request(): never
{
    $id = nm_str($_POST, 'item', 24);
    $page = (bool)preg_match(NL_PAGE_ID_PATTERN, $id);
    $item = $page ? nl_load_page($id) : nl_load_post($id);
    if (!$item || $item['status'] !== 'published' || !nl_is_locked($item)) nm_not_found();
    // Only this site's own form (browsers send Sec-Fetch-Site; older ones an Origin).
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (($site !== '' && $site !== 'same-origin') || ($site === '' && $origin !== '' && strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? '') . (($port = parse_url($origin, PHP_URL_PORT)) ? ':' . $port : '')) !== strtolower($host))) nm_not_found();
    header('Cache-Control: no-store');
    nm_lscache_header(false);
    $ip = nm_client_ip();
    $key = $ip . '|' . $id;
    // 5 wrong answers per 15 minutes for one page, 30 per hour from one address (like a manga's reader password).
    if (nm_rate_count('log-lock', $key, 900) >= 5 || nm_rate_count('log-lock-ip', $ip, 3600) >= 30) {
        nm_log('log_unlock_blocked', $id);
        header('Location: ' . nl_lock_target($item, $page, 'wait'), true, 303);
        exit;
    }
    $password = trim(nm_str($_POST, 'lockkey', NL_LOCK_MAX * 4));
    if ($password !== '' && password_verify($password, nl_lock_hash($item))) {
        nm_rate_clear('log-lock', $key);
        $keys = nl_lock_keys();
        unset($keys[$id]);
        $keys[$id] = nl_lock_token($id, nl_lock_hash($item));
        $keys = array_slice($keys, -NL_LOCK_KEYS_MAX, null, true);
        $value = implode('.', array_map(static fn($k, $v) => $k . ':' . $v, array_keys($keys), $keys));
        setcookie(NL_LOCK_COOKIE, $value, ['expires' => time() + NL_LOCK_DAYS * 86400, 'path' => nl_lock_cookie_path(), 'secure' => nm_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        nm_log('log_unlock_ok', $id);
        header('Location: ' . nl_lock_target($item, $page), true, 303);
        exit;
    }
    nm_rate_hit('log-lock', $key, 900);
    nm_rate_hit('log-lock-ip', $ip, 3600);
    nm_rate_gc();
    nm_log('log_unlock_failed', $id);
    usleep(random_int(300000, 600000));
    header('Location: ' . nl_lock_target($item, $page, 'wrong'), true, 303);
    exit;
}
function nl_lock_icon(string $class = 'log-lock-icon'): string
{
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/><path d="M12 14.5v2.2"/></svg>';
}
/** The form in place of a locked post's or page's content. $state: '' / 'wrong' / 'wait' (from ?locked=). */
function nl_lock_form(array $item, bool $page, string $state = ''): string
{
    $message = match ($state) {
        'wrong' => '<p class="log-lock-error" role="alert">' . nm_t('パスワードが違います。もう一度入れてください。') . '</p>',
        'wait' => '<p class="log-lock-error" role="alert">' . nm_t('続けて間違えたため、しばらく受け付けません。15分ほどしてから試してください。') . '</p>',
        default => '',
    };
    // A text field with the characters hidden by CSS: browsers offer to save only password fields; the password manager hints say the same.
    return '<div class="log-lock" id="log-lock">' . nl_lock_icon() . '<p class="log-lock-title">' . nm_t($page ? 'このページはパスワードで保護されています' : 'この記事はパスワードで保護されています') . '</p>' . $message
        . '<form class="log-lock-form" method="post" action="./" autocomplete="off"><input type="hidden" name="nl_unlock" value="1"><input type="hidden" name="item" value="' . h($item['id']) . '">'
        . '<label class="log-lock-field"><span>' . nm_t('パスワード') . '</span><input class="log-lock-input" type="text" name="lockkey" required maxlength="' . NL_LOCK_MAX . '" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"' . ($state !== '' ? ' autofocus' : '') . '></label>'
        . '<label class="log-lock-show"><input type="checkbox">' . nm_t('入力した文字を表示') . '</label><button class="log-lock-submit" type="submit">' . nl_lock_icon('log-lock-submit-icon') . '<span>' . nm_t('開く') . '</span></button></form>'
        . '<p class="log-lock-note">' . nm_t('開くと、このブラウザーでは{days}日間そのまま読めます。パスワードそのものは保存しません。', ['days' => NL_LOCK_DAYS]) . '</p></div>';
}
/** In a list: the post's title stays, its content waits behind the link. */
function nl_lock_teaser(array $p): string
{
    return '<div class="log-lock is-teaser">' . nl_lock_icon() . '<p class="log-lock-title">' . nm_t('パスワードで保護された記事です') . '</p><a class="log-lock-open" href="' . h(nl_post_url($p['id'])) . '#log-lock">' . nm_t('パスワードを入れて読む') . '</a></div>';
}
/** The owner's mark on a locked post or page. */
function nl_lock_badge(): string
{
    return '<span class="badge log-lock-badge">' . nl_lock_icon('log-lock-badge-icon') . '<span>' . nm_t('鍵付き') . '</span></span>';
}
/** The description of a locked post or page (search results, share cards, RSS). */
function nl_lock_description(bool $page = false): string
{
    return nm_t($page ? 'このページはパスワードで保護されています。' : 'この記事はパスワードで保護されています。');
}
