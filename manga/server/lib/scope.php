<?php
/*
 * NagiScope: access analytics built into NagiManga and NagiLog. No outside service, no cookies.
 * Off until the owner turns it on (admin > NagiScope). While off, nothing is recorded and no page sends anything.
 *
 * Records: the reader's browser sends small events (viewer/scope.js for the LOG, NagiManga.js for manga) to scope.php,
 * which checks them against the works, posts and pages that exist and appends one line per event to
 * data/scope/raw/YYYY-MM.jsonl.php. The owner's visit to the NagiScope screen adds the new lines to one summary per day
 * (data/scope/daily/YYYY-MM-DD.json.php); the screen reads only those. Both kinds of file start with a line of PHP that
 * stops at once, so a server that ignores data/.htaccess never shows the addresses in them.
 * MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NS_HEAD = "<?php http_response_code(404); exit; __halt_compiler();\n";
/** Sends one reader (IPv4 address or IPv6 /64) may make per minute, and events per send; past that they are dropped (at most 300 events a minute). */
const NS_RATE_MINUTE = 60;
const NS_EVENTS_PER_SEND = 5;
/** Programs that announce themselves (crawlers, link previews, test browsers, HTTP libraries). */
const NS_BOT_UA = '~bot\b|bot/|bot;|crawl|spider|slurp|headless|lighthouse|pagespeed|facebookexternalhit|embedly|preview|curl/|wget/|python|httpclient|java/|go-http|okhttp|axios|node-fetch|libwww|phantom|selenium|puppeteer|playwright|scrapy|uptime|monitor|feedfetcher|mediapartners~i';
/** Events a page may send. lk (like) is recorded by like.php itself. */
const NS_EVENTS = ['pv', 'lv', 'rd', 'ck', 'fv', 'fo', 'fl', 'fg', 'fs', 'ft', 'mo', 'mp', 'me'];
const NS_IP_MODES = ['full', 'partial', 'none'];
const NS_RAW_MONTHS = [0, 3, 6, 12, 24];
/** How many keys a list in a day's summary keeps (search words, links, domains); the rest are left out. */
const NS_MAP_CAP = 400;
/** Where visitors come from: the labels on the screen. */
const NS_REF_LABELS = ['x' => 'X（Twitter）', 'pixiv' => 'pixiv', 'search' => '検索', 'sns' => 'ほかの SNS', 'site' => 'ほかのサイト', 'direct' => '直接・ブックマーク'];
const NS_DEVICE_LABELS = ['mobile' => 'スマホ', 'pc' => 'PC', 'tablet' => 'タブレット'];
/** Goal kinds: the labels on the screen. "game" is NagiScope's own: a reader started a game in a page embed. */
const NS_GOAL_KINDS = ['page' => 'ページに来た', 'click' => 'リンクを押した', 'end' => '読み終えた', 'like' => 'いいねした', 'game' => '埋め込みのゲームを始めた'];

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

function ns_settings(?array $cfg = null): array
{
    $s = ($cfg ?? nm_config() ?? [])['scope'] ?? [];
    $s = is_array($s) ? $s : [];
    return [
        'on' => !empty($s['on']),
        'ip' => in_array($s['ip'] ?? '', NS_IP_MODES, true) ? $s['ip'] : 'full',
        'raw_months' => in_array($months = (int)($s['raw_months'] ?? 0), NS_RAW_MONTHS, true) ? $months : 0,
        'backup_raw' => !empty($s['backup_raw']),
        // The owner answered the card on the works screen ("あとで" hides it), or turned NagiScope on once.
        'asked' => !empty($s['asked']),
        'since' => (int)($s['since'] ?? 0),
    ];
}
function ns_on(): bool { return ns_settings()['on']; }
function ns_save_settings(array $changes): void
{
    $cfg = nm_config() ?? [];
    $cfg['scope'] = array_merge(ns_settings($cfg), $changes);
    nm_save_config($cfg);
}
function ns_dir(): string { return NM_DATA . '/scope'; }

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** Japan time: the day and hour every count is filed under (the LOG's dates use the same zone). */
function ns_day(int $t): string { return gmdate('Y-m-d', $t + 32400); }
function ns_hour(int $t): int { return (int)gmdate('G', $t + 32400); }
function ns_today(): string { return ns_day(time()); }
function ns_shift(string $day, int $days): string { return gmdate('Y-m-d', (int)strtotime($day . ' 00:00:00 UTC') + $days * 86400); }

/** The same reader on one day, without cookies: a keyed hash of the address and browser that changes every day. */
function ns_visitor(int $t, string $ip, string $ua): string
{
    return substr(hash_hmac('sha256', 'scope|' . ns_day($t) . '|' . $ip . '|' . $ua, (string)(nm_config()['secret'] ?? '')), 0, 16);
}

/** The address as the setting keeps it: whole, with its end hidden, or not at all (''). */
function ns_ip_kept(string $ip, string $mode): string
{
    if ($mode === 'none' || $ip === '' || $ip === '0.0.0.0') return '';
    if ($mode === 'full') return $ip;
    if (!str_contains($ip, ':')) return (string)preg_replace('/\.\d+\z/', '.xxx', $ip);
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) return '';
    $words = str_split(bin2hex(substr($bin, 0, 6)), 4);
    return implode(':', array_map(static fn($w) => ltrim($w, '0') ?: '0', $words)) . ':xxxx';
}

function ns_is_bot(string $ua): bool
{
    return $ua === '' || strlen($ua) < 20 || (bool)preg_match(NS_BOT_UA, $ua);
}

/** mobile, tablet or pc. $touch: the page saw a touch screen (an iPad reports itself as a Mac). */
function ns_device(string $ua, bool $touch): string
{
    if (preg_match('~iPad|Tablet|Kindle|Silk|PlayBook|Nexus (?:7|9|10)~i', $ua) || (preg_match('~Android~i', $ua) && !preg_match('~Mobile~i', $ua))) return 'tablet';
    if (preg_match('~Mobi|iPhone|iPod|Android|Windows Phone~i', $ua)) return 'mobile';
    if ($touch && preg_match('~Macintosh~', $ua)) return 'tablet';
    return 'pc';
}

/** "Chrome / Windows": the kind of browser, kept instead of the whole User-Agent. */
function ns_browser(string $ua): string
{
    $b = match (true) {
        (bool)preg_match('~Edg/~', $ua) => 'Edge',
        (bool)preg_match('~SamsungBrowser~', $ua) => 'Samsung Internet',
        (bool)preg_match('~OPR/|Opera~', $ua) => 'Opera',
        (bool)preg_match('~Firefox/|FxiOS~', $ua) => 'Firefox',
        (bool)preg_match('~Chrome/|CriOS~', $ua) => 'Chrome',
        (bool)preg_match('~Safari/~', $ua) => 'Safari',
        default => 'other',
    };
    $os = match (true) {
        (bool)preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
        (bool)preg_match('~Android~', $ua) => 'Android',
        (bool)preg_match('~Windows~', $ua) => 'Windows',
        (bool)preg_match('~Macintosh|Mac OS~', $ua) => 'Mac',
        (bool)preg_match('~CrOS~', $ua) => 'ChromeOS',
        (bool)preg_match('~Linux~', $ua) => 'Linux',
        default => 'other',
    };
    return $b . ' / ' . $os;
}

/** Where a visit came from, by the referrer's domain: [kind, domain]. Pages of this site are "internal". */
function ns_ref_class(string $url, string $own): array
{
    if ($url === '') return ['direct', ''];
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return ['direct', ''];
    $host = (string)preg_replace('/\Awww\./', '', $host);
    $own = (string)preg_replace('/\Awww\./', '', strtolower((string)preg_replace('/:\d+\z/', '', $own)));
    if ($own !== '' && $host === $own) return ['internal', ''];
    $is = static fn(string ...$domains): bool => (bool)array_filter($domains, static fn($d) => $host === $d || str_ends_with($host, '.' . $d));
    if ($is('x.com', 'twitter.com', 't.co')) return ['x', $host];
    if ($is('pixiv.net', 'pixiv.me', 'fanbox.cc')) return ['pixiv', $host];
    if (preg_match('~(?:\A|\.)google\.[a-z.]{2,6}\z~', $host) || $is('bing.com', 'search.yahoo.co.jp', 'search.yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.ru', 'yandex.com', 'search.naver.com', 'ecosia.org', 'startpage.com', 'search.brave.com', 'kagi.com')) return ['search', $host];
    if ($is('bsky.app', 'misskey.io', 'fedibird.com', 'pawoo.net', 'mstdn.jp', 'mastodon.social', 'facebook.com', 'instagram.com', 'threads.net', 'threads.com', 'tumblr.com', 'youtube.com', 'tiktok.com', 'discord.com', 'reddit.com', 'line.me', 'note.com', 'skeb.jp', 'fantia.jp', 'ci-en.net', 'booth.pm', 'tegalog.net')) return ['sns', $host];
    return ['site', $host];
}

/** Text a reader sent: one line, no control characters, at most $max characters. */
function ns_text(mixed $v, int $max): string
{
    if (!is_string($v) || strlen($v) > $max * 4 || !mb_check_encoding($v, 'UTF-8')) return '';
    $v = trim((string)preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $v));
    return mb_substr($v, 0, $max);
}
function ns_url(mixed $v, int $max = 500): string
{
    $v = ns_text($v, $max);
    return $v !== '' && preg_match('~\Ahttps?://[^\s<>"]+\z~i', $v) && filter_var($v, FILTER_VALIDATE_URL) ? $v : '';
}
function ns_int(mixed $v, int $min, int $max): ?int
{
    if (!is_int($v) && !(is_float($v) && floor($v) === $v)) return null;
    $v = (int)$v;
    return $v >= $min && $v <= $max ? $v : null;
}

/** Letters only, for checking that a heading a reader sent is one of the post's. */
function ns_letters(string $s): string
{
    return mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
}

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

function ns_raw_file(string $month): string { return ns_dir() . '/raw/' . $month . '.jsonl.php'; }
function ns_daily_file(string $day): string { return ns_dir() . '/daily/' . $day . '.json.php'; }

function ns_read_guarded(string $file): ?array
{
    if (!is_file($file) || is_link($file)) return null;
    $raw = (string)@file_get_contents($file);
    if (!str_starts_with($raw, NS_HEAD)) return null;
    $data = json_decode(substr($raw, strlen(NS_HEAD)), true);
    return is_array($data) ? $data : null;
}
function ns_write_guarded(string $file, array $data): void
{
    nm_write_atomic($file, NS_HEAD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
function ns_goals(): array
{
    $g = ns_read_guarded(ns_dir() . '/goals.json.php');
    return is_array($g['goals'] ?? null) ? $g['goals'] : [];
}
function ns_state(): array
{
    return ns_read_guarded(ns_dir() . '/state.json.php') ?? ['f' => '', 'o' => 0, 'at' => 0];
}

/** One line at the end of this month's file; the lock keeps lines from different requests whole. */
function ns_append(array $rec): void
{
    $file = ns_raw_file(gmdate('Y-m', $rec['t'] + 32400));
    nm_ensure_dir(dirname($file));
    $fh = @fopen($file, 'c+');
    if ($fh === false) return;
    try {
        flock($fh, LOCK_EX);
        fseek($fh, 0, SEEK_END);
        if (ftell($fh) === 0) fwrite($fh, NS_HEAD);
        fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Bytes used by NagiScope: [raw logs, summaries]. */
function ns_usage(): array
{
    $sum = static fn(string $pattern) => array_sum(array_map('filesize', glob($pattern) ?: []));
    return [$sum(ns_dir() . '/raw/*.jsonl.php'), $sum(ns_dir() . '/daily/*.json.php')];
}
function ns_bytes(int $n): string
{
    return $n >= 1048576 ? number_format($n / 1048576, 1) . ' MB' : ($n >= 1024 ? number_format($n / 1024, 1) . ' KB' : $n . ' B');
}

// ---------------------------------------------------------------------------
// Visitors the owner turned away (the IP list of NagiScope's access screen)
// ---------------------------------------------------------------------------

function ns_denied_list(?array $cfg = null): array
{
    $list = ($cfg ?? nm_config() ?? [])['denied_ips'] ?? [];
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}
/** A rule of the list: an address, a range (203.0.113.0/24) or a hidden address (203.0.113.xxx, the same /24). */
function ns_deny_rule(string $rule): string
{
    $rule = trim($rule);
    if (preg_match('/\A(\d{1,3}\.\d{1,3}\.\d{1,3})\.xxx\z/', $rule, $m)) $rule = $m[1] . '.0/24';
    if (preg_match('/\A([0-9a-f:]+):xxxx\z/i', $rule, $m)) $rule = $m[1] . '::/48';
    [$net, $bits] = array_pad(explode('/', $rule, 2), 2, null);
    if (!filter_var($net, FILTER_VALIDATE_IP)) return '';
    if ($bits !== null && (!ctype_digit($bits) || (int)$bits > (str_contains($net, ':') ? 128 : 32))) return '';
    return $bits === null ? $net : $net . '/' . (int)$bits;
}
function ns_denied(?string $ip = null): bool
{
    $list = ns_denied_list();
    if (!$list) return false;
    $ip ??= nm_client_ip();
    if (!function_exists('nm_ip_match')) require_once __DIR__ . '/auth.php';
    foreach ($list as $rule) if (nm_ip_match($ip, $rule)) return true;
    return false;
}

// ---------------------------------------------------------------------------
// Recording
// ---------------------------------------------------------------------------

/** The owner's own visits are not counted: a valid login session (any role) on this request. */
function ns_is_owner(): bool
{
    if (!isset($_COOKIE['nm_admin']) || !is_string($_COOKIE['nm_admin'])) return false;
    if (!function_exists('nm_session_start')) require_once __DIR__ . '/auth.php';
    if (session_status() !== PHP_SESSION_ACTIVE) nm_session_start();
    return nm_is_logged_in();
}

/** Whether this request may add a line at all: on, not a program, not the owner, not turned away, not too many. */
function ns_may_record(): bool
{
    if (!ns_on()) return false;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (ns_is_bot($ua) || ns_denied() || ns_is_owner()) return false;
    nm_rate_gc();
    return nm_rate_hit('scope', nm_client_net(), 60) <= NS_RATE_MINUTE;
}

/** The base of every line: time, visitor, address (as the setting keeps it). */
function ns_base(string $event): array
{
    $t = time();
    $ip = nm_client_ip();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $rec = ['t' => $t, 'e' => $event, 'v' => ns_visitor($t, $ip, $ua)];
    $kept = ns_ip_kept($ip, ns_settings()['ip']);
    if ($kept !== '') $rec['ip'] = $kept;
    return $rec;
}

/** A published post, fixed page or manga work by kind and id, or null. */
function ns_target(string $kind, string $id): ?array
{
    if ($kind === 'p') {
        if (!function_exists('nl_load_post') || !nl_valid_post($id)) return null;
        $p = nl_load_post($id);
        return $p && $p['status'] === 'published' && nl_settings()['public'] ? $p : null;
    }
    if ($kind === 'g') {
        if (!function_exists('nl_load_page')) require_once __DIR__ . '/log-pages.php';
        $p = nl_load_page($id);
        return $p && $p['status'] === 'published' && nl_settings()['public'] ? $p : null;
    }
    if ($kind === 'm') {
        if (!nm_valid_id($id)) return null;
        $w = nm_load_work($id);
        return $w && !empty($w['pages']) ? $w : null;
    }
    return null;
}

/** Characters of a post or page's text, for "read properly": about 500 a minute. */
function ns_body_chars(array $item): int
{
    $body = (string)($item['body'] ?? '');
    $body = (string)preg_replace(['~!\[[^\]]*\]\([^)]*\)~u', '~\[\[([^\[\]\n]+)\]\]\([^)]*\)~u', '~\[([^\]]*)\]\([^)]*\)~u', '~https?://\S+~u', '/[#>*_`~|\-\[\]]+/u', '~\s+~u'], ['', '$1', '$1', '', '', ''], $body);
    return mb_strlen($body);
}

/**
 * Checks one event a page sent and turns it into a line, or null when anything in it is wrong
 * (a work, post, page, page number, heading or embed that does not exist is never recorded).
 */
function ns_event(array $in): ?array
{
    $e = is_string($in['e'] ?? null) ? $in['e'] : '';
    if (!in_array($e, NS_EVENTS, true)) return null;
    $sid = is_string($in['s'] ?? null) && preg_match('/\A[A-Za-z0-9]{8,24}\z/', $in['s']) ? $in['s'] : '';
    if ($sid === '') return null;
    $kind = is_string($in['k'] ?? null) ? $in['k'] : '';
    $id = is_string($in['i'] ?? null) ? $in['i'] : '';
    $manga = in_array($e, ['mo', 'mp', 'me'], true);
    if ($manga !== ($kind === 'm')) return null;
    if (!in_array($kind, ['p', 'g', 'l', 'm'], true)) return null;
    $item = $kind === 'l' ? [] : ns_target($kind, $id);
    if ($kind === 'l') {
        if ($id !== '' || $e !== 'pv' && $e !== 'ck' && $e !== 'lv') return null;
        if (!function_exists('nl_settings') || !nl_settings()['public']) return null;
    } elseif ($item === null) {
        return null;
    }
    $rec = ns_base($e) + ['s' => $sid, 'k' => $kind, 'i' => $id];
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $own = (string)($_SERVER['HTTP_HOST'] ?? '');
    switch ($e) {
        case 'pv':
        case 'mo':
            $touch = !empty($in['tc']);
            $rec['d'] = ns_device($ua, $touch);
            $rec['b'] = ns_browser($ua);
            $ref = ns_url($in['r'] ?? '');
            // Manga opened on another site (the embed code): that site is where the reader came from.
            $host = $manga ? ns_url($in['h'] ?? '') : '';
            [$rc, $rd] = ns_ref_class($ref, $own);
            if ($host !== '') {
                [$hc, $hd] = ns_ref_class($host, $own);
                if ($hc !== 'internal') { [$rc, $rd] = [$hc === 'direct' ? 'site' : $hc, $hd]; $ref = $host; }
            }
            if ($ref !== '') $rec['r'] = $ref;
            $rec['rc'] = $rc;
            if ($rd !== '') $rec['rd'] = $rd;
            if ($manga) {
                $rec['n'] = count($item['pages']);
                break;
            }
            if ($kind === 'p' || $kind === 'g') $rec['need'] = max(10, (int)ceil(ns_body_chars($item) / 500 * 60));
            if ($kind === 'l') {
                $q = ns_text($in['q'] ?? '', 100);
                if ($q !== '') $rec['q'] = $q;
                $tag = ns_text($in['tg'] ?? '', 80);
                if ($tag !== '' && function_exists('nl_ordered_hashtags') && in_array($tag, nl_ordered_hashtags(true), true)) $rec['tg'] = $tag;
            }
            break;
        case 'lv':
            if ($kind === 'l') return null;
            $y = ns_int($in['y'] ?? null, 0, 100);
            $hi = ns_int($in['hi'] ?? null, -1, 300);
            if ($y === null || $hi === null) return null;
            $rec['y'] = $y; $rec['hi'] = $hi;
            if ($hi >= 0) {
                $heading = ns_text($in['h'] ?? '', 120);
                $letters = ns_letters($heading);
                if ($letters === '' || !str_contains(ns_letters((string)($item['body'] ?? '')), $letters)) return null;
                $rec['h'] = $heading;
            }
            break;
        case 'rd':
            if ($kind === 'l') return null;
            break;
        case 'ck':
            $url = ns_url($in['u'] ?? '');
            if ($url === '') return null;
            $rec['u'] = $url;
            break;
        case 'fv': case 'fo': case 'fl': case 'fg': case 'fs': case 'ft':
            // Only a page embed written in this post or page: [ページ埋込]URL[/ページ埋込]
            $url = ns_url($in['u'] ?? '', 2000);
            if ($kind === 'l' || $url === '' || !function_exists('nl_frame_target') || !nl_frame_target($url) || !str_contains((string)($item['body'] ?? ''), $url)) return null;
            $rec['u'] = $url;
            if ($e === 'ft') {
                $ms = ns_int($in['ms'] ?? null, 0, 6 * 3600 * 1000);
                if ($ms === null) return null;
                $rec['ms'] = $ms;
            }
            break;
        case 'mp':
            $n = count($item['pages']);
            $pg = ns_int($in['pg'] ?? null, 0, 100000);
            $mx = ns_int($in['mx'] ?? null, 0, $n - 1);
            $ls = ns_int($in['ls'] ?? null, 0, $n - 1);
            $ms = ns_int($in['ms'] ?? null, 0, 24 * 3600 * 1000);
            if ($pg === null || $mx === null || $ls === null || $ms === null) return null;
            $rec += ['pg' => $pg, 'mx' => $mx, 'ls' => $ls, 'ms' => $ms];
            break;
        case 'me':
            $rec['sk'] = !empty($in['sk']) ? 1 : 0;
            break;
    }
    return $rec;
}

/** like.php: a like on a post, recorded by the server itself (the like is already counted there). */
function ns_record_like(string $postId, int $n): void
{
    try {
        if (!ns_may_record()) return;
        ns_append(ns_base('lk') + ['s' => '', 'k' => 'p', 'i' => $postId, 'n' => max(1, min(100, $n))]);
    } catch (Throwable) {
        // Analytics never break a like.
    }
}

// ---------------------------------------------------------------------------
// Adding the new lines to the day summaries
// ---------------------------------------------------------------------------

function ns_site_empty(): array
{
    return ['visitors' => 0, 'views' => 0, 'hours' => array_fill(0, 24, 0), 'refs' => [], 'domains' => [], 'dev' => [], 'items' => [],
        'pages' => 0, 'reads' => 0, 'done' => 0, 'likes' => 0];
}
function ns_day_empty(string $day): array
{
    return ['day' => $day, 'open' => 1, 'all' => ['visitors' => 0, 'hours' => array_fill(0, 24, 0)], 'm' => ns_site_empty(), 'l' => ns_site_empty(),
        'search' => [], 'tags' => [], 'links' => [], 'frames' => [], 'goals' => [], '_v' => [], '_s' => []];
}
function ns_inc(array &$map, string $key, int $n = 1): void
{
    if ($key === '') return;
    if (!isset($map[$key])) {
        if (count($map) >= NS_MAP_CAP) return;
        $map[$key] = 0;
    }
    $map[$key] += $n;
    if ($map[$key] <= 0) unset($map[$key]);
}
function &ns_item(array &$site, string $key): array
{
    $site['items'][$key] ??= ['v' => 0, 'views' => 0, 'pages' => 0, 'ends' => 0, 'skim' => 0, 'ms' => 0, 'msn' => 0, 'exit' => [], 'deep' => 0, 'leaves' => 0, 'likes' => 0, 'likers' => 0, 'n' => 0];
    return $site['items'][$key];
}
function ns_key(array $r): string { return $r['k'] === 'l' ? '' : $r['k'] . ':' . $r['i']; }

/** Whether line $r reaches goal $g. */
function ns_goal_hit(array $g, array $r): bool
{
    $key = ns_key($r);
    $target = (string)($g['target'] ?? '');
    switch ($g['kind'] ?? '') {
        case 'page': return ($r['e'] === 'pv' || $r['e'] === 'mo') && $key !== '' && $key === $target;
        case 'end': return (($r['e'] === 'rd') || ($r['e'] === 'me' && empty($r['sk']))) && $key === $target;
        case 'like': return $r['e'] === 'lk' && ($target === '' || $key === $target);
        // Started a game: pressed inside its frame, or the game said it started (whichever comes first).
        case 'game': return ($r['e'] === 'fg' || $r['e'] === 'fs') && ($target === '' || ($r['u'] ?? '') === $target);
        case 'click':
            if ($r['e'] !== 'ck') return false;
            $u = (string)($r['u'] ?? ''); $m = (string)($g['match'] ?? '');
            if ($m === '') return false;
            return ($g['how'] ?? 'contains') === 'prefix' ? str_starts_with($u, $m) : str_contains($u, $m);
    }
    return false;
}

/**
 * The page visit a line belongs to: kept per page session and kind, so a manga visit's line can never change a post's. A visit opened before midnight (Japan time) is carried over from $prev.
 */
function ns_session_key(array $r, string $kind): string
{
    // The page session id is random per page load and never shown to anyone else, so it is enough to keep visits apart
    // (the visitor mark changes at midnight and could not carry a visit over).
    return $kind . ':' . (string)($r['s'] ?? '');
}
function ns_session(array &$d, ?array &$prev, string $sk): ?array
{
    if (isset($d['_s'][$sk]) && is_array($d['_s'][$sk])) return $d['_s'][$sk];
    if ($prev !== null && isset($prev['_s'][$sk]) && is_array($prev['_s'][$sk])) {
        // Carried over: pages and time go on from yesterday's numbers (only what is new is added), while the place
        // where the reader stops, and the visit itself for the averages, count afresh today.
        return $d['_s'][$sk] = ['hk' => '', 'ls' => -1, 'new' => 1] + $prev['_s'][$sk];
    }
    return null;
}

/** What a visit keeps from its opening line: device and the way in. */
function ns_session_origin(array $r): array
{
    return array_intersect_key($r, ['d' => 1, 'rc' => 1, 'rd' => 1]);
}

/** Adds one line to the summary of its day ($prev: the day before, for visits that cross midnight). */
function ns_apply(array &$d, array $r, array $goals, ?array &$prev = null): void
{
    $vis = (string)($r['v'] ?? '');
    $e = (string)($r['e'] ?? '');
    if ($vis === '' || $e === '') return;
    $key = ns_key($r);
    $site = $r['k'] === 'm' ? 'm' : 'l';
    // A line that does not fit its visit (no visit, another page, too early to have read it) changes nothing,
    // goals included.
    $sk = ns_session_key($r, match ($e) { 'lv', 'rd' => 'p', 'mp', 'me' => 'm', 'ft' => 'f', default => '' });
    $sess = null;
    if (in_array($e, ['lv', 'rd', 'mp', 'me'], true)) {
        $sess = ns_session($d, $prev, $sk);
        if ($sess === null || ($sess['i'] ?? '') !== $key) return;
        if ($e === 'rd' && (int)$r['t'] - (int)$sess['t0'] < (int)$sess['need'] * 0.8) return;
        // The device and the way in come from the opening of the visit (a visit carried over from yesterday).
        foreach (['d', 'rc', 'rd'] as $f) if (!isset($r[$f]) && isset($sess[$f])) $r[$f] = $sess[$f];
    }
    $h = ns_hour((int)$r['t']);
    if (!isset($d['_v'][$vis])) {
        $rc = (string)($r['rc'] ?? 'direct');
        $d['_v'][$vis] = ['r' => $rc === 'internal' ? 'direct' : $rc, 'rd' => (string)($r['rd'] ?? ''), 'sites' => [], 'it' => [], 'g' => [], 'last' => '', 'x' => []];
        $d['all']['visitors']++;
        $d['all']['hours'][$h]++;
    }
    $V = &$d['_v'][$vis];
    $S = &$d[$site];
    $view = $e === 'pv' || $e === 'mo';
    // A visit carried over from before midnight: today's reader of that page too.
    if ($sess !== null && $key !== '' && !isset($V['it'][$key])) {
        $V['it'][$key] = 1;
        $it = &ns_item($S, $key);
        $it['v']++;
        $S['reads']++;
        unset($it);
        $view = true;
    }
    if ($view && !isset($V['sites'][$site])) {
        $V['sites'][$site] = 1;
        $S['visitors']++;
        $S['hours'][$h]++;
        $rc = (string)($r['rc'] ?? '');
        $rd = (string)($r['rd'] ?? '');
        if ($rc === '' || $rc === 'internal') { $rc = $V['r']; $rd = $V['rd']; }
        ns_inc($S['refs'], $rc);
        if ($rd !== '') ns_inc($S['domains'], $rd);
        ns_inc($S['dev'], (string)($r['d'] ?? 'pc'));
    }
    // Goals: once a day per visitor; the source is the page they were on before, or where they came from.
    foreach ($goals as $gid => $g) {
        if (isset($V['g'][$gid]) || !ns_goal_hit($g, $r)) continue;
        $V['g'][$gid] = 1;
        $d['goals'][$gid] ??= ['n' => 0, 'src' => []];
        $d['goals'][$gid]['n']++;
        $src = $V['last'] !== '' && $V['last'] !== $key ? 'i:' . $V['last'] : ($V['rd'] !== '' && $V['r'] === 'site' ? 'd:' . $V['rd'] : 'r:' . $V['r']);
        ns_inc($d['goals'][$gid]['src'], $src);
    }
    $once = static function (string $mark) use (&$V): bool {
        if (isset($V['x'][$mark])) return false;
        $V['x'][$mark] = 1;
        return true;
    };
    switch ($e) {
        case 'pv':
            $S['views']++;
            $S['pages']++;
            if ($key !== '') {
                $it = &ns_item($S, $key);
                $it['views']++;
                if (!isset($V['it'][$key])) { $V['it'][$key] = 1; $it['v']++; $S['reads']++; }
                unset($it);
                $d['_s'][ns_session_key($r, 'p')] = ['i' => $key, 't0' => (int)$r['t'], 'need' => (int)($r['need'] ?? 0), 'hk' => ''] + ns_session_origin($r);
            }
            if (isset($r['q']) && $once('q|' . $r['q'])) ns_inc($d['search'], (string)$r['q']);
            if (isset($r['tg'])) ns_inc($d['tags'], (string)$r['tg']);
            $V['last'] = $key;
            break;
        case 'lv':
            $hk = (int)$r['hi'] . '|' . (string)($r['h'] ?? '');
            $it = &ns_item($S, $key);
            if ((string)$sess['hk'] === '') $it['leaves']++;
            else ns_inc($it['exit'], (string)$sess['hk'], -1);
            ns_inc($it['exit'], $hk);
            $d['_s'][$sk]['hk'] = $hk;
            unset($it);
            break;
        case 'rd':
            // Read properly: the page saw 90% of the text and the visit has lasted long enough (checked above).
            if (!$once('rd|' . $key)) break;
            $it = &ns_item($S, $key);
            $it['deep']++;
            $S['done']++;
            unset($it);
            break;
        case 'ck':
            ns_inc($d['links'], mb_substr((string)$r['u'], 0, 300));
            break;
        case 'fv': case 'fo': case 'fl': case 'fg': case 'fs': case 'ft':
            // Page embeds are kept per post or page and address: the same game in two posts stays apart.
            $u = (string)$r['u'];
            $fk = $key . '|' . $u;
            if (!isset($d['frames'][$fk]) && count($d['frames']) >= 200) break;
            $d['frames'][$fk] ??= ['u' => $u, 'item' => $key, 'fv' => 0, 'fo' => 0, 'fl' => 0, 'fg' => 0, 'fs' => 0, 'ms' => 0, 'msn' => 0];
            if ($e !== 'ft') {
                if ($once($e . '|' . $fk)) $d['frames'][$fk][$e]++;
                break;
            }
            $sk .= '|' . substr(md5($u), 0, 10);
            $fs = ns_session($d, $prev, $sk);
            $old = (int)($fs['ms'] ?? -1);
            $ms = max((int)$r['ms'], $old);
            $d['frames'][$fk]['ms'] += $ms - max(0, $old);
            if ($old < 0 || (!empty($fs['new']) && $ms > $old)) $d['frames'][$fk]['msn']++;
            $d['_s'][$sk] = ['i' => $key, 'ms' => $ms];
            break;
        case 'mo':
            $S['views']++;
            $it = &ns_item($S, $key);
            $it['views']++;
            $it['n'] = (int)($r['n'] ?? $it['n']);
            if (!isset($V['it'][$key])) { $V['it'][$key] = 1; $it['v']++; $S['reads']++; }
            unset($it);
            $d['_s'][ns_session_key($r, 'm')] = ['i' => $key, 'pg' => 0, 'ls' => -1, 'ms' => 0] + ns_session_origin($r);
            $V['last'] = $key;
            break;
        case 'mp':
            $it = &ns_item($S, $key);
            $pg = max((int)$r['pg'], (int)$sess['pg']);
            $S['pages'] += $pg - (int)$sess['pg'];
            $it['pages'] += $pg - (int)$sess['pg'];
            $ms = max((int)$r['ms'], (int)$sess['ms']);
            if ((!empty($sess['new']) || (int)$sess['ms'] === 0) && $ms > (int)$sess['ms']) $it['msn']++;
            $it['ms'] += $ms - (int)$sess['ms'];
            if ((int)$sess['ls'] >= 0) ns_inc($it['exit'], (string)(int)$sess['ls'], -1);
            ns_inc($it['exit'], (string)(int)$r['ls']);
            $d['_s'][$sk] = ['i' => $key, 'pg' => $pg, 'ms' => $ms, 'ls' => (int)$r['ls']] + ns_session_origin($sess);
            unset($it);
            break;
        case 'me':
            if (!$once((empty($r['sk']) ? 'me|' : 'sk|') . $key)) break;
            $it = &ns_item($S, $key);
            if (empty($r['sk'])) { $it['ends']++; $S['done']++; } else $it['skim']++;
            unset($it);
            break;
        case 'lk':
            $likes = max(1, (int)($r['n'] ?? 1));
            $S['likes'] += $likes;
            if ($key === '') break;
            $it = &ns_item($S, $key);
            $it['likes'] += $likes;
            if ($once('lk|' . $key)) $it['likers']++;
            unset($it);
            break;
    }
}

/** "YYYY-MM:offset" of a line: comparable as text, so a day knows which lines it already has. */
function ns_pos(string $month, int $offset): string { return $month . ':' . str_pad((string)$offset, 12, '0', STR_PAD_LEFT); }

/**
 * Reads the lines added since the last time and adds them to the day summaries. Called when the owner opens NagiScope;
 * stops after a few seconds and goes on from there the next time. Each day remembers the last line it took ("pos"),
 * so a run cut off between writing the days and the state never adds a line twice.
 */
function ns_aggregate(float $budget = 6.0): void
{
    if (!is_dir(ns_dir() . '/raw')) return;
    nm_with_lock('scope-aggregate', static function () use ($budget): void {
        $state = ns_state();
        $goals = ns_goals();
        $deadline = microtime(true) + $budget;
        $days = [];
        $load = static function (string $day) use (&$days): void {
            if (!array_key_exists($day, $days)) $days[$day] = ns_read_guarded(ns_daily_file($day)) ?? ns_day_empty($day);
        };
        $files = glob(ns_dir() . '/raw/*.jsonl.php') ?: [];
        sort($files);
        $complete = true;
        foreach ($files as $file) {
            $month = basename($file, '.jsonl.php');
            if (!preg_match('/\A\d{4}-\d{2}\z/', $month) || ($state['f'] !== '' && $month < $state['f'])) continue;
            $offset = $month === $state['f'] ? max((int)$state['o'], strlen(NS_HEAD)) : strlen(NS_HEAD);
            [$offset, $done] = ns_read_lines($file, $offset, $deadline, static function (array $r, int $end) use (&$days, $load, $goals, $month): void {
                $day = ns_day((int)$r['t']);
                $load($day);
                $pos = ns_pos($month, $end);
                if (empty($days[$day]['open']) || strcmp((string)($days[$day]['pos'] ?? ''), $pos) >= 0) return;
                $yesterday = ns_shift($day, -1);
                $load($yesterday);
                $prev = null;
                if (!empty($days[$yesterday]['open'])) $prev = &$days[$yesterday];
                try {
                    ns_apply($days[$day], $r, $goals, $prev);
                } catch (Throwable) {
                    // A line that cannot be added up is skipped, never stopping the rest.
                }
                unset($prev);
                $days[$day]['pos'] = $pos;
            });
            $state = ['f' => $month, 'o' => $offset, 'at' => time()];
            if (!$done) { $complete = false; break; }
        }
        // Days before yesterday are complete once every line so far is in: their visitor lists go, the numbers stay.
        $closeBefore = ns_shift(ns_today(), -1);
        foreach ($days as $day => $d) {
            if (!is_file(ns_daily_file($day)) && empty($d['pos'])) continue;
            if ($complete && $day < $closeBefore) { unset($d['_v'], $d['_s']); $d['open'] = 0; }
            ns_write_guarded(ns_daily_file($day), $d);
        }
        // Days that got no new line still close (the last week is enough: older ones closed on an earlier visit).
        if ($complete) for ($day = ns_shift($closeBefore, -7); $day < $closeBefore; $day = ns_shift($day, 1)) {
            $file = ns_daily_file($day);
            if (isset($days[$day]) || !is_file($file)) continue;
            $d = ns_read_guarded($file);
            if ($d && !empty($d['open'])) { unset($d['_v'], $d['_s']); $d['open'] = 0; ns_write_guarded($file, $d); }
        }
        ns_write_guarded(ns_dir() . '/state.json.php', $state);
        ns_prune_raw($state['f']);
    });
}

/**
 * Lines of a raw file from $offset, each passed to $fn($line, $offsetAfterIt). Stops at the deadline, at $stop, or at a
 * line still being written (no line break yet), so no lock is held while reading. Returns
 * [offset after the last whole line read, true when the end of the file was reached].
 */
function ns_read_lines(string $file, int $offset, float $deadline, callable $fn, ?int $stop = null): array
{
    $fh = @fopen($file, 'rb');
    if ($fh === false) return [$offset, true];
    try {
        fseek($fh, $offset);
        while (($line = fgets($fh, 65536)) !== false) {
            if (!str_ends_with($line, "\n")) {
                // A line still being written: wait for it. One left unfinished for a minute (a crash) is passed over.
                if ($stop !== null || filemtime($file) > time() - 60) return [$offset, false];
                $offset += strlen($line);
                continue;
            }
            if ($stop !== null && $offset + strlen($line) > $stop) break;
            $offset += strlen($line);
            $r = json_decode($line, true);
            if (is_array($r) && is_int($r['t'] ?? null) && is_string($r['e'] ?? null) && is_string($r['k'] ?? null)) $fn($r, $offset);
            if (microtime(true) > $deadline) return [$offset, false];
        }
        return [$offset, true];
    } finally {
        fclose($fh);
    }
}

/** Deletes raw logs older than the setting allows (never the ones not yet added up). */
function ns_prune_raw(string $current): void
{
    $months = ns_settings()['raw_months'];
    if ($months <= 0) return;
    $keep = gmdate('Y-m', (int)strtotime(gmdate('Y-m-01', time() + 32400) . ' -' . $months . ' months'));
    foreach (glob(ns_dir() . '/raw/*.jsonl.php') ?: [] as $file) {
        $month = basename($file, '.jsonl.php');
        if ($month < $keep && $month < $current) @unlink($file);
    }
}

/**
 * A goal made or changed later still counts the past: every raw log still kept is read again and the goals of each
 * day are replaced (days whose raw log is gone keep what they had). Lines not yet added up are left to ns_aggregate().
 * One month is held in memory at a time; the raw logs are never locked while read, so recording goes on meanwhile.
 */
function ns_goals_rebuild(): void
{
    ns_aggregate(20.0);
    nm_with_lock('scope-aggregate', static function (): void {
        @set_time_limit(300);
        $state = ns_state();
        $goals = ns_goals();
        $files = glob(ns_dir() . '/raw/*.jsonl.php') ?: [];
        sort($files);
        $carry = [];
        foreach ($files as $file) {
            $month = basename($file, '.jsonl.php');
            if ($state['f'] === '' || $month > $state['f']) break;
            $fresh = $carry;
            ns_read_lines($file, strlen(NS_HEAD), INF, static function (array $r) use (&$fresh, $goals): void {
                $day = ns_day((int)$r['t']);
                $fresh[$day] ??= ns_day_empty($day);
                $yesterday = ns_shift($day, -1);
                $prev = null;
                if (isset($fresh[$yesterday])) $prev = &$fresh[$yesterday];
                try { ns_apply($fresh[$day], $r, $goals, $prev); } catch (Throwable) { /* skipped, as in ns_aggregate() */ }
                unset($prev);
            }, $month === $state['f'] ? (int)$state['o'] : null);
            // The last day of the month may go on in the next file.
            ksort($fresh);
            $carry = array_slice($fresh, -1, 1, true);
            foreach ($fresh as $day => $f) {
                $d = ns_read_guarded(ns_daily_file($day));
                if (!$d) continue;
                $d['goals'] = $f['goals'];
                if (!empty($d['open']) && isset($d['_v'])) foreach ($d['_v'] as $vis => $v) $d['_v'][$vis]['g'] = $f['_v'][$vis]['g'] ?? [];
                ns_write_guarded(ns_daily_file($day), $d);
            }
        }
    });
}

// ---------------------------------------------------------------------------
// Reading the summaries for the screen
// ---------------------------------------------------------------------------

/** Summaries of the days from $from to $to (inclusive), missing days as empty ones. */
function ns_days(string $from, string $to): array
{
    static $cache = [];
    $out = [];
    for ($day = $from; $day <= $to; $day = ns_shift($day, 1)) {
        // The tabs and their comparisons overlap: each day's file is read once per request.
        if (!array_key_exists($day, $cache)) {
            $file = ns_daily_file($day);
            $d = is_file($file) ? ns_read_guarded($file) : null;
            if ($d) unset($d['_v'], $d['_s']);
            $cache[$day] = $d;
        }
        $out[$day] = $cache[$day] ?? ns_day_empty($day);
        if (count($out) > 800) break;
    }
    return $out;
}

/** The periods of the three tabs: [from, to, compare from, compare to, number of points]. */
function ns_periods(): array
{
    $today = ns_today();
    return [
        'day' => [$today, $today, ns_shift($today, -1), ns_shift($today, -1), 24],
        'month' => [ns_shift($today, -29), $today, ns_shift($today, -59), ns_shift($today, -30), 30],
        'year' => [ns_shift($today, -363), $today, ns_shift($today, -727), ns_shift($today, -364), 52],
    ];
}

/** Points of the graph for one tab of one part (m / l / all): visitors by hour, day or week. */
function ns_series(array $days, string $site, string $tab): array
{
    if ($tab === 'day') return array_map('intval', array_values(reset($days)[$site]['hours'] ?? array_fill(0, 24, 0)));
    $daily = array_values(array_map(static fn($d) => (int)($d[$site]['visitors'] ?? 0), $days));
    if ($tab === 'month') return $daily;
    return array_map('array_sum', array_chunk($daily, 7));
}

/** Everything one tab shows for one part, added up over its period. */
function ns_totals(array $days, string $site): array
{
    $t = ['visitors' => 0, 'views' => 0, 'pages' => 0, 'reads' => 0, 'done' => 0, 'likes' => 0, 'refs' => [], 'domains' => [], 'dev' => [], 'items' => []];
    foreach ($days as $d) {
        $s = $d[$site] ?? ns_site_empty();
        foreach (['visitors', 'views', 'pages', 'reads', 'done', 'likes'] as $k) $t[$k] += (int)($s[$k] ?? 0);
        foreach (['refs', 'domains', 'dev'] as $k) foreach ($s[$k] ?? [] as $name => $n) $t[$k][$name] = ($t[$k][$name] ?? 0) + (int)$n;
        foreach ($s['items'] ?? [] as $key => $it) {
            $sum = &$t['items'][$key];
            $sum ??= ['v' => 0, 'views' => 0, 'pages' => 0, 'ends' => 0, 'skim' => 0, 'ms' => 0, 'msn' => 0, 'exit' => [], 'deep' => 0, 'leaves' => 0, 'likes' => 0, 'likers' => 0, 'n' => 0];
            foreach ($it as $k => $v) {
                if ($k === 'exit') { foreach ($v as $ek => $en) $sum['exit'][$ek] = ($sum['exit'][$ek] ?? 0) + (int)$en; }
                elseif ($k === 'n') $sum['n'] = max($sum['n'], (int)$v);
                elseif (isset($sum[$k])) $sum[$k] += (int)$v;
            }
            unset($sum);
        }
    }
    arsort($t['refs']); arsort($t['domains']); arsort($t['dev']);
    uasort($t['items'], static fn($a, $b) => [$b['v'], $b['views']] <=> [$a['v'], $a['views']]);
    return $t;
}

/** A map added up over the days ('search', 'tags', 'links'), largest first. */
function ns_merge(array $days, string $field): array
{
    $out = [];
    foreach ($days as $d) foreach ($d[$field] ?? [] as $k => $n) $out[(string)$k] = ($out[(string)$k] ?? 0) + (int)$n;
    arsort($out);
    return $out;
}

/** Embedded pages (games) over the days: url => counts, for the whole site or one post or page ($item). */
function ns_frames(array $days, ?string $item = null): array
{
    $out = [];
    foreach ($days as $d) foreach ($d['frames'] ?? [] as $fk => $f) {
        $u = (string)($f['u'] ?? $fk);
        if ($item !== null && (string)($f['item'] ?? '') !== $item) continue;
        $out[$u] ??= ['fv' => 0, 'fo' => 0, 'fl' => 0, 'fg' => 0, 'fs' => 0, 'ms' => 0, 'msn' => 0];
        foreach (['fv', 'fo', 'fl', 'fg', 'fs', 'ms', 'msn'] as $k) $out[$u][$k] += (int)($f[$k] ?? 0);
    }
    uasort($out, static fn($a, $b) => [$b['fg'], $b['fo']] <=> [$a['fg'], $a['fo']]);
    return $out;
}

/** Goal results over the days: id => [n, sources]. */
function ns_goal_totals(array $days): array
{
    $out = [];
    foreach ($days as $d) foreach ($d['goals'] ?? [] as $gid => $g) {
        $out[$gid] ??= ['n' => 0, 'src' => []];
        $out[$gid]['n'] += (int)$g['n'];
        foreach ($g['src'] ?? [] as $k => $n) $out[$gid]['src'][$k] = ($out[$gid]['src'][$k] ?? 0) + (int)$n;
    }
    foreach ($out as &$g) arsort($g['src']);
    return $out;
}

/** The title of a work, post or page by its key ("m:ID", "p:ID", "g:ID"), or '' when it is gone. */
function ns_item_title(string $key): string
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
    $title = '';
    if ($kind === 'm' && nm_valid_id($id)) $title = (string)(nm_load_work($id)['title'] ?? '');
    elseif ($kind === 'p' && function_exists('nl_load_post') && ($p = nl_load_post($id))) $title = nl_post_title($p, 60);
    elseif ($kind === 'g' && function_exists('nl_load_page') && ($p = nl_load_page($id))) $title = (string)$p['title'];
    return $cache[$key] = $title;
}
function ns_item_kind_label(string $key): string
{
    return match ($key[0] ?? '') { 'm' => nm_t('漫画'), 'p' => nm_t('記事'), 'g' => nm_t('固定ページ'), default => '' };
}

/** A goal source key as words: 記事「…」, X, 検索… */
function ns_source_label(string $src): string
{
    [$kind, $rest] = array_pad(explode(':', $src, 2), 2, '');
    if ($kind === 'i') {
        $title = ns_item_title($rest);
        return $title !== '' ? nm_t('{kind}「{title}」', ['kind' => ns_item_kind_label($rest), 'title' => $title]) : nm_t('削除したページ');
    }
    if ($kind === 'd') return $rest;
    return nm_t(NS_REF_LABELS[$rest] ?? '直接・ブックマーク');
}

// ---------------------------------------------------------------------------
// Raw log search (the IP screen)
// ---------------------------------------------------------------------------

/** The newest lines whose address starts with $ip (all lines when empty), newest first. */
function ns_raw_search(string $ip, int $limit = 300): array
{
    $out = [];
    $files = glob(ns_dir() . '/raw/*.jsonl.php') ?: [];
    rsort($files);
    foreach ($files as $file) {
        $lines = [];
        ns_read_lines($file, strlen(NS_HEAD), microtime(true) + 5, static function (array $r) use (&$lines, $ip, $limit): void {
            if ($ip !== '' && !str_starts_with((string)($r['ip'] ?? ''), $ip)) return;
            $lines[] = $r;
            if (count($lines) > $limit * 4) $lines = array_slice($lines, -$limit);
        });
        $out = array_merge($out, array_reverse($lines));
        if (count($out) >= $limit) break;
    }
    return array_slice($out, 0, $limit);
}

// ---------------------------------------------------------------------------
// Privacy policy samples
// ---------------------------------------------------------------------------

function ns_policy_text(string $mode, string $lang): string
{
    $raw = ns_settings()['raw_months'];
    if ($lang === 'en') {
        if ($mode === 'none') return "Access analytics\nThis site counts visits with its own access analytics (NagiScope). It uses no cookies and does not identify individuals: IP addresses are used only to tell visits apart within a day, turned into a value that changes every day, and are not stored.";
        return "Access analytics\nThis site records visits with its own access analytics (NagiScope), without cookies.\n"
            . "Information recorded: IP address" . ($mode === 'partial' ? " (with its last part hidden)" : '') . ", type of browser and device, pages viewed and how they were read, and the page you came from.\n"
            . "Purpose: to understand how the site is read, and to deal with unauthorized access and harassment.\n"
            . "Retention: " . ($raw > 0 ? "the detailed records are deleted after {$raw} months; totals without IP addresses are kept." : "kept until the site owner deletes them.") . "\n"
            . "This information is not provided to third parties, except where required by law.";
    }
    if ($mode === 'none') return "アクセス解析について\n当サイトでは、独自のアクセス解析（NagiScope）で閲覧数を数えています。Cookie を使わず、個人を特定しない形で数えています。IP アドレスは同じ日の閲覧をまとめるためだけに使い、日ごとに変わる値に置き換えたうえで、保存しません。";
    return "アクセス解析について\n当サイトでは、独自のアクセス解析（NagiScope）で閲覧の記録を取っています。Cookie は使いません。\n"
        . "記録する情報：IP アドレス" . ($mode === 'partial' ? "（末尾を伏せた形）" : '') . "、ブラウザーと端末の種類、閲覧したページとその読まれ方、当サイトに来る前のページ。\n"
        . "目的：閲覧状況の把握と、不正なアクセスや迷惑行為への対応のため。\n"
        . "保存期間：" . ($raw > 0 ? "詳しい記録は {$raw} ヶ月で削除します。IP アドレスを含まない集計は残します。" : "サイトの管理者が削除するまで保存します。") . "\n"
        . "これらの情報は、法令に基づく場合を除き、第三者に提供しません。";
}

// ---------------------------------------------------------------------------
// Backup (the NagiScope card of the backup screen)
// ---------------------------------------------------------------------------

function ns_backup_download(bool $raw): never
{
    if (!nm_zip_available()) nm_not_found();
    ns_aggregate();
    $tmp = NM_DATA . '/tmp/scope-backup-' . bin2hex(random_bytes(8)) . '.zip';
    nm_ensure_dir(dirname($tmp));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) nm_not_found();
    $zip->addFromString('nagiscope-backup.json', json_encode(['app' => 'NagiScope', 'version' => NM_VERSION, 'created' => time(), 'raw' => $raw]));
    foreach (array_merge([ns_dir() . '/goals.json.php', ns_dir() . '/state.json.php'], glob(ns_dir() . '/daily/*.json.php') ?: [], $raw ? glob(ns_dir() . '/raw/*.jsonl.php') ?: [] : []) as $file) {
        if (!is_file($file)) continue;
        $name = substr($file, strlen(ns_dir()) + 1);
        $zip->addFile($file, $name);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="nagiscope-' . gmdate('Ymd-His', time() + 32400) . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * Restores a NagiScope backup. Only the exact file names of a backup are read, each checked as NagiScope data;
 * days and months already here are kept unless $overwrite. Returns how many files were restored.
 * @throws UnexpectedValueException with the reason
 */
function ns_backup_restore(string $file, bool $overwrite): int
{
    if (!nm_zip_available()) throw new UnexpectedValueException(nm_t('このサーバーでは ZIP を扱えません'));
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new UnexpectedValueException(nm_t('ZIP ファイルを開けません'));
    try {
        if ($zip->locateName('nagiscope-backup.json') === false) throw new UnexpectedValueException(nm_t('NagiScope のバックアップではありません'));
        if ($zip->numFiles > 6000) throw new UnexpectedValueException(nm_t('バックアップのファイルが多すぎます'));
        $count = 0;
        nm_with_lock('scope-aggregate', static function () use ($zip, $overwrite, &$count): void {
            // Decided once, from this site as it was before the restore.
            $here = ns_state();
            $fresh = $here['f'] === '';
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (!preg_match('~\A(?:goals\.json\.php|state\.json\.php|daily/\d{4}-\d{2}-\d{2}\.json\.php|raw/\d{4}-\d{2}\.jsonl\.php)\z~', $name)) continue;
                $stat = $zip->statIndex($i);
                if (!$stat || $stat['size'] > 64 * 1024 * 1024) continue;
                $text = $zip->getFromIndex($i);
                if (!is_string($text) || !str_starts_with($text, NS_HEAD)) continue;
                $body = substr($text, strlen(NS_HEAD));
                if (str_starts_with($name, 'raw/')) {
                    // Every line must be one JSON object; anything else is not a NagiScope log.
                    foreach (explode("\n", rtrim($body, "\n")) as $line) if ($line !== '' && !is_array(json_decode($line, true))) continue 2;
                } elseif (!is_array(json_decode($body, true))) {
                    continue;
                }
                $entries[$name] = $body;
            }
            // Raw logs: kept ones stay unless overwriting; on a site already counting, never the month being added up or later.
            $raw = [];
            foreach ($entries as $name => $body) {
                if (!preg_match('~\Araw/(\d{4}-\d{2})~', $name, $m)) continue;
                if (!$overwrite && (is_file(ns_dir() . '/' . $name) || (!$fresh && $m[1] >= $here['f']))) continue;
                $raw[$m[1]] = true;
            }
            foreach ($entries as $name => $body) {
                $to = ns_dir() . '/' . $name;
                if (str_starts_with($name, 'raw/')) {
                    if (!isset($raw[substr($name, 4, 7)])) continue;
                } elseif ($name === 'state.json.php') {
                    // A position only means something together with its own raw log.
                    $st = json_decode($body, true);
                    if ((!$overwrite && !$fresh) || !isset($raw[(string)($st['f'] ?? '')])) continue;
                } else {
                    if (is_file($to) && !$overwrite) continue;
                    if (str_starts_with($name, 'daily/')) {
                        // A day remembers the last line it took; without that line's raw log here, the mark would
                        // hide this site's own new lines, so it goes.
                        $d = json_decode($body, true);
                        if (isset($d['pos']) && !isset($raw[substr((string)$d['pos'], 0, 7)])) {
                            unset($d['pos']);
                            $body = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                        }
                    }
                }
                nm_write_atomic($to, NS_HEAD . $body);
                $count++;
            }
            // Days kept here whose month's raw log was just replaced: their mark points into the old log, so it goes
            // (the new lines of that month are then taken again from the restored position).
            foreach (array_keys($raw) as $month) {
                foreach (glob(ns_dir() . '/daily/' . $month . '-*.json.php') ?: [] as $file) {
                    if (isset($entries['daily/' . basename($file)])) continue;
                    $d = ns_read_guarded($file);
                    if ($d && isset($d['pos'])) { unset($d['pos']); ns_write_guarded($file, $d); }
                }
            }
        });
        // The restored raw logs are added up again from their start only if nothing was added up yet.
        return $count;
    } finally {
        $zip->close();
    }
}

/** Deletes every record and summary (goals and settings stay). */
function ns_delete_data(): void
{
    nm_with_lock('scope-aggregate', static function (): void {
        foreach (['raw', 'daily'] as $dir) if (is_dir(ns_dir() . '/' . $dir)) nm_rmdir_recursive(ns_dir() . '/' . $dir);
        @unlink(ns_dir() . '/state.json.php');
    });
}
