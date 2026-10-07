<?php
/* LOG likes: one small counter file, kept apart from the posts so a like never touches a post or the page cache. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/** One reader (the same line and the same device) can add this many likes to one post per day (Japan time). */
const NL_LIKE_DAILY = 100;
/** The whole line (IP address) together, whatever devices it reports: device names are easy to fake. */
const NL_LIKE_LINE_DAILY = 500;
const NL_LIKE_MAX = 99999999;

function nl_likes_file(): string { return nl_root() . '/likes.php'; }
function nl_likes_day_file(): string { return nl_root() . '/likes-today.php'; }
/** Like counts by post id, read once per request. */
function nl_like_counts(): array { return nl_memo('likes', 'nl_likes_read'); }
/** Straight from the file: used under the lock, where another request may have just written. */
function nl_likes_read(): array
{
    $r = nl_read_record(nl_likes_file());
    $out = [];
    foreach ((is_array($r['posts'] ?? null) ? $r['posts'] : []) as $id => $n) if (is_int($n) && $n > 0) $out[(string)$id] = $n;
    return $out;
}
function nl_like_count(string $id): int { return nl_like_counts()[$id] ?? 0; }
function nl_likes_write(array $counts): void
{
    $counts = array_filter($counts, static fn($n) => is_int($n) && $n > 0);
    nl_write_record(nl_likes_file(), ['schema' => 1, 'posts' => $counts]);
}
/**
 * Keys for today's counts, without storing the address or the device: HMACs with the secret key.
 * "reader" is the line plus the device (User-Agent); "line" is the IP alone. IPv6 counts per /64,
 * since one home line gets a whole range of addresses.
 */
function nl_like_keys(string $postId): array
{
    $ip = nm_client_ip();
    if (str_contains($ip, ':') && ($bin = @inet_pton($ip)) !== false) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
    $ua = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 400);
    $secret = (string)(nm_config()['secret'] ?? '');
    return [substr(hash_hmac('sha256', 'log-like|' . $ip . '|' . $ua . '|' . $postId, $secret), 0, 20),
        'l' . substr(hash_hmac('sha256', 'log-like-line|' . $ip . '|' . $postId, $secret), 0, 19)];
}
function nl_like_today(): array
{
    $r = nl_read_record(nl_likes_day_file());
    $day = nl_date(time(), 'Y-m-d');
    return ($r['day'] ?? '') === $day && is_array($r['used'] ?? null) ? $r : ['day' => $day, 'used' => []];
}
/** What this reader may still add to one post today: their own allowance, cut by what is left for their line. */
function nl_like_room(array $used, string $id): int
{
    [$reader, $line] = nl_like_keys($id);
    return max(0, min(NL_LIKE_DAILY - (int)($used[$reader] ?? 0), NL_LIKE_LINE_DAILY - (int)($used[$line] ?? 0)));
}
/** Likes this reader may still add to each post today. */
function nl_like_left(array $ids): array
{
    $used = nl_like_today()['used'];
    $out = [];
    foreach ($ids as $id) $out[$id] = nl_like_room($used, $id);
    return $out;
}
/** Adds up to $n likes (cut to what is left today). Returns [count, left]. */
function nl_like_add(string $id, int $n): array
{
    return nm_with_lock('log-likes', static function () use ($id, $n) {
        $today = nl_like_today();
        $room = nl_like_room($today['used'], $id);
        $add = max(0, min($n, $room));
        $counts = nl_likes_read();
        if ($add > 0) {
            $counts[$id] = min(NL_LIKE_MAX, ($counts[$id] ?? 0) + $add);
            foreach (nl_like_keys($id) as $key) $today['used'][$key] = (int)($today['used'][$key] ?? 0) + $add;
            nl_likes_write($counts);
            nl_write_record(nl_likes_day_file(), $today);
        }
        return [$counts[$id] ?? 0, $room - $add];
    });
}
/** The owner's edit screen: set a count (0 removes it). */
function nl_like_set(string $id, int $count): void
{
    nm_with_lock('log-likes', static function () use ($id, $count) {
        $counts = nl_likes_read();
        if ($count > 0) $counts[$id] = min(NL_LIKE_MAX, $count); else unset($counts[$id]);
        nl_likes_write($counts);
    });
}
/** Deleted posts take their counts with them. */
function nl_likes_forget(array $ids): void
{
    $counts = nl_like_counts();
    if (!array_intersect_key($counts, array_flip($ids))) return;
    nm_with_lock('log-likes', static function () use ($ids) {
        $counts = nl_likes_read();
        foreach ($ids as $id) unset($counts[$id]);
        nl_likes_write($counts);
    });
}
/** Heart button, first in the share row. viewer/log-like.js adds the taps, the long press and the sending. */
function nl_like_button(array $p): string
{
    $n = nl_like_count($p['id']);
    return '<button class="log-share-btn log-like" type="button" data-like="' . h($p['id']) . '" aria-label="' . nm_t('いいね（{n}）', ['n' => $n]) . '">'
        . '<svg class="log-share-icon log-like-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20.3 4.6 13a4.9 4.9 0 0 1 6.9-6.9l.5.5.5-.5a4.9 4.9 0 0 1 6.9 6.9Z"/></svg>'
        . '<span class="log-like-count">' . $n . '</span></button>';
}
