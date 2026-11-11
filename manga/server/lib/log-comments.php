<?php
/*
 * LOG comments: anonymous stamps and short texts under posts and fixed pages. No database: one small file per
 * post or page (data/log/comments/<id>.php) and a tiny index for the counts and the owner's notice.
 * Pages stay cached: comments arrive through comment.php and viewer/log-comment.js, never by rebuilding a page.
 * MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_COMMENT_TEXT_MAX = 300;
const NL_COMMENT_LINES_MAX = 8;
/** A reader's name: optional, one line. Shown beside their mark, so the same name from someone else still looks different. */
const NL_COMMENT_NAME_MAX = 16;
/** Approved names kept in the index (uid|name), so a reader's next comment with the same name needs no approval. */
const NL_COMMENT_NAMES_MAX = 2000;
/** Comments kept for one post or page (shown and waiting together). */
const NL_COMMENT_ITEM_MAX = 500;
/** Texts waiting for the owner, the whole site together: a flood of them cannot fill the disk. */
const NL_COMMENT_WAIT_MAX = 300;
/** One line (IP address, IPv6 per /64): this many a minute and a day. */
const NL_COMMENT_MINUTE = 4;
const NL_COMMENT_DAY = 60;
const NL_COMMENT_STAMPS = ['👍', '👏', '😊', '😂', '😮', '😢', '🥰', '🙏', '🎉', '✨', '🔥', '💯'];
const NL_COMMENT_STAMPS_MAX = 24;
/** Bundled icons (viewer/comment-icons/<key>.svg): original outlines with a gradient. */
const NL_COMMENT_ICONS = ['star' => '星', 'moon' => '月', 'drop' => 'しずく', 'leaf' => '葉っぱ', 'flower' => '花', 'cloud' => '雲', 'heart' => 'ハート', 'spark' => 'きらめき'];
/** Icons the owner adds (SVG, checked by lib/log-svg.php): how many and how large. */
const NL_COMMENT_ICON_UPLOADS = 32;
const NL_COMMENT_ICON_BYTES = 64 * 1024;
const NL_COMMENT_ICON_KEY = '/\Au[a-f0-9]{12}\z/';
/** Posts (published ids) and fixed pages take comments; drafts never do. */
const NL_COMMENT_TARGET = '/\A(?:pg[a-f0-9]{12}|[0-9]{14}(?:-[0-9]{2,6})?)\z/';
const NL_COMMENT_ID = '/\A[a-f0-9]{10}\z/';
/** The reader's own random key, kept by their browser; their shown ID is made from it. */
const NL_COMMENT_TOKEN = '/\A[a-f0-9]{32}\z/';
/** The ticket comment.php hands out with the comments: a write needs one at least this many seconds old, and not older than the second number. */
const NL_COMMENT_TICKET_WAIT = 2;
const NL_COMMENT_TICKET_LIFE = 6 * 3600;
/** Addresses in a text or name (a scheme, www., or a name ending in a common top-level domain); checked after full-width letters are made plain. */
const NL_COMMENT_URL = '~[a-z][a-z0-9+.\-]*://|\bwww\.|\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.(?:com|net|org|info|biz|xyz|top|site|online|shop|store|club|live|io|co|me|jp|ru|cn|uk|de|fr|us|tk|ml|ga|cf|gq|link|click|app|dev|pro|vip|win|bid|loan|cc|tv|ly|in|be|to|gl|gg|ws|su|pw|work|fun|space|website|icu|buzz|today|life)(?![a-z0-9])~i';

function nl_comments_dir(): string { return nl_root() . '/comments'; }
function nl_comments_index_file(): string { return nl_root() . '/comments-index.php'; }
function nl_comment_icons_file(): string { return nl_root() . '/comment-icons.php'; }
function nl_comment_icons_dir(): string { return nl_root() . '/comment-icons'; }
function nl_comments_file(string $id): string
{
    if (!preg_match(NL_COMMENT_TARGET, $id)) throw new InvalidArgumentException('bad comment target');
    return nl_comments_dir() . '/' . $id . '.php';
}

/* ---- Switches ------------------------------------------------------------------------------- */

/** The site takes comments at all (a private Memo never does). */
function nl_comments_on(): bool
{
    $s = nl_settings();
    return $s['public'] && $s['comments'];
}
/** One post or page takes comments: the site's switch and its own, and it is published. */
function nl_comments_open(?array $item): bool
{
    return $item !== null && nl_comments_on() && ($item['comments'] ?? false) === true && ($item['status'] ?? '') === 'published' && preg_match(NL_COMMENT_TARGET, (string)($item['id'] ?? ''));
}
/** A post or page by its id, as comment.php sees it. */
function nl_comment_target(string $id): ?array
{
    if (!preg_match(NL_COMMENT_TARGET, $id)) return null;
    return str_starts_with($id, 'pg') ? nl_load_page($id) : nl_load_post($id);
}
/** The editor's switch for a post or page (null: the form has no switch, so the record keeps its own). */
function nl_comments_input(): ?bool
{
    if (!isset($_POST['comments_form'])) return null;
    return nm_str($_POST, 'comments', 1) === '1';
}
/** Without a switch in the form: an old record keeps its own (none = off); a new one follows the site's switch. */
function nl_comments_default(?array $old): bool
{
    return $old ? ($old['comments'] ?? false) === true : nl_settings()['comments'];
}

/* ---- Settings ------------------------------------------------------------------------------- */

/** Stamps are single emoji: symbols with their joiners and modifiers, never letters, digits or markup. */
function nl_comment_stamp_ok(string $stamp): bool
{
    return strlen($stamp) <= 40 && (bool)preg_match('/\A\p{So}[\p{So}\p{Sk}\p{Mn}\p{Me}\x{200D}\x{FE0F}\x{20E3}]{0,9}\z/u', $stamp);
}
/** The owner's list, from the settings field (separated by spaces or line breaks). */
function nl_comment_stamps_parse(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,、]+/u', trim($text)) ?: [] as $stamp) {
        if ($stamp === '') continue;
        if (!nl_comment_stamp_ok($stamp)) throw new UnexpectedValueException(nm_t('スタンプには絵文字だけを、空白で区切って入れてください（「{stamp}」は使えません）', ['stamp' => mb_substr($stamp, 0, 20)]));
        $out[$stamp] = true;
    }
    if (count($out) > NL_COMMENT_STAMPS_MAX) throw new UnexpectedValueException(nm_t('スタンプは{n}個までにしてください', ['n' => NL_COMMENT_STAMPS_MAX]));
    return array_keys($out);
}
function nl_comment_stamps_clean(mixed $stamps): array
{
    if (!is_array($stamps)) return NL_COMMENT_STAMPS;
    return array_values(array_slice(array_unique(array_filter($stamps, static fn($s) => is_string($s) && nl_comment_stamp_ok($s))), 0, NL_COMMENT_STAMPS_MAX));
}
/** The settings block (admin/index.php): switches, stamps, which icons readers see, icons to add or delete. */
function nl_comments_settings_input(): Closure
{
    $on = nm_str($_POST, 'comments', 1) === '1';
    $text = nm_str($_POST, 'comment_text', 1) === '1';
    $stamps = nl_comment_stamps_parse(nm_str($_POST, 'comment_stamps', 2000));
    $custom = nl_comment_icons_custom();
    $delete = array_values(array_intersect(array_keys($custom), array_filter((array)($_POST['comment_icon_delete'] ?? []), 'is_string')));
    // New pictures are checked and converted here, before anything is stored.
    $adds = nl_comment_icon_uploads($_FILES['comment_icon_files'] ?? null);
    if (count($custom) - count($delete) + count(array_diff_key($adds, $custom)) > NL_COMMENT_ICON_UPLOADS) throw new UnexpectedValueException(nm_t('追加できるアイコンは{n}個までです', ['n' => NL_COMMENT_ICON_UPLOADS]));
    $shown = array_filter((array)($_POST['comment_icons'] ?? []), 'is_string');
    $all = array_values(array_diff(array_keys(nl_comment_icons(true)), $delete));
    $off = array_values(array_diff($all, $shown));
    if ($on && count($off) === count($all) && !$adds) throw new UnexpectedValueException(nm_t('コメントのアイコンを1つ以上選んでください'));
    if ($on && !$stamps && !$text) throw new UnexpectedValueException(nm_t('スタンプを入れるか、文章のコメントを受け付けてください'));
    return static function (array $s) use ($on, $text, $stamps, $off, $adds, $delete): array {
        if ($adds || $delete) nl_comment_icons_apply($adds, $delete);
        // New icons are shown at once (they are not in the list of hidden ones).
        $s['comments'] = $on; $s['comment_text'] = $text; $s['comment_stamps'] = $stamps; $s['comment_icons_off'] = array_values(array_diff($off, array_keys($adds)));
        return $s;
    };
}

/* ---- Icons ---------------------------------------------------------------------------------- */

/** The owner's icons: key => ['name' => …, 'added' => time, 'type' => 'svg' or 'png']. */
function nl_comment_icons_custom(): array
{
    return nl_memo('comment-icons', static function (): array {
        $r = nl_read_record(nl_comment_icons_file());
        $out = [];
        foreach ((is_array($r['icons'] ?? null) ? $r['icons'] : []) as $key => $icon) {
            $type = is_array($icon) && ($icon['type'] ?? 'svg') === 'png' ? 'png' : 'svg';
            if (preg_match(NL_COMMENT_ICON_KEY, (string)$key) && is_array($icon) && is_file(nl_comment_icons_dir() . '/' . $key . '.' . $type)) $out[(string)$key] = ['name' => (string)($icon['name'] ?? ''), 'added' => (int)($icon['added'] ?? 0), 'type' => $type];
        }
        return $out;
    });
}
/** Every icon readers may choose (or, with $all, every icon there is): key => ['name', 'url']. $base: the way to the LOG's folder. */
function nl_comment_icons(bool $all = false, string $base = ''): array
{
    $off = $all ? [] : array_flip(nl_settings()['comment_icons_off']);
    $out = [];
    foreach (NL_COMMENT_ICONS as $key => $name) if (!isset($off[$key])) $out[$key] = ['name' => nm_t($name), 'url' => $base . 'viewer/comment-icons/' . $key . '.svg?v=' . nm_asset_version(NM_ROOT . '/viewer/comment-icons/' . $key . '.svg')];
    // The key is made from the picture, so its address never shows an older one.
    foreach (nl_comment_icons_custom() as $key => $icon) if (!isset($off[$key])) $out[$key] = ['name' => $icon['name'] !== '' ? $icon['name'] : nm_t('追加したアイコン'), 'url' => $base . 'comment.php?icon=' . $key];
    return $out;
}
/** Square side of an added picture icon, in pixels. */
const NL_COMMENT_ICON_SIDE = 128;
/**
 * An icon from picture bytes: an SVG is checked by lib/log-svg.php; PNG, JPEG, WebP and GIF are drawn again into a
 * 128×128 PNG (transparent margins keep their shape), so nothing but the pixels is kept. Returns ['type', 'bytes'] or an error message.
 */
function nl_comment_icon_convert(string $bytes): array|string
{
    if ($bytes === '' || strlen($bytes) > NL_COMMENT_ICON_BYTES) return nm_t('アイコンの画像は{n}KBまでにしてください', ['n' => NL_COMMENT_ICON_BYTES / 1024]);
    $head = ltrim(substr($bytes, 0, 512));
    if (str_starts_with($head, '<')) {
        $svg = nl_svg_clean($bytes);
        return is_string($svg) ? $svg : ['type' => 'svg', 'bytes' => $svg['svg']];
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || !function_exists('imagecreatefromstring')) return nm_t('アイコンにはSVG・PNG・JPEG・WebP・GIFを使えます');
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w > 4096 || $h > 4096) return nm_t('アイコンの画像は4096px四方までにしてください');
    $src = @imagecreatefromstring($bytes);
    if (!$src) return nm_t('アイコンの画像を読めませんでした');
    $side = NL_COMMENT_ICON_SIDE;
    $out = imagecreatetruecolor($side, $side);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    $scale = min($side / $w, $side / $h);
    $dw = max(1, (int)round($w * $scale)); $dh = max(1, (int)round($h * $scale));
    imagecopyresampled($out, $src, intdiv($side - $dw, 2), intdiv($side - $dh, 2), 0, 0, $dw, $dh, $w, $h);
    imagesavealpha($out, true);
    ob_start(); imagepng($out, null, 9); $png = (string)ob_get_clean();
    return $png !== '' ? ['type' => 'png', 'bytes' => $png] : nm_t('アイコンの画像を読めませんでした');
}
/** The files of the settings field (several at once): key => ['type', 'bytes', 'name']. A file that cannot be used stops the save. */
function nl_comment_icon_uploads(mixed $files): array
{
    if (!is_array($files) || !is_array($files['name'] ?? null)) return [];
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) throw new UnexpectedValueException(nm_t('アイコンの画像を受け取れませんでした'));
        $icon = nl_comment_icon_convert((string)file_get_contents($tmp, false, null, 0, NL_COMMENT_ICON_BYTES + 1));
        if (is_string($icon)) throw new UnexpectedValueException(nm_t('「{file}」：{message}', ['file' => mb_substr((string)$name, 0, 60), 'message' => $icon]));
        // The file name without its extension names the icon (readers see it when they point at it).
        $label = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', pathinfo((string)$name, PATHINFO_FILENAME)) ?? ''), 0, 30);
        $out['u' . substr(hash('sha256', $icon['bytes']), 0, 12)] = $icon + ['name' => $label];
    }
    return $out;
}
/** Store new icons and remove deleted ones. Comments that used a deleted icon show the first icon instead. */
function nl_comment_icons_apply(array $adds, array $delete): void
{
    nm_with_lock('log-comments', static function () use ($adds, $delete) {
        $r = nl_read_record(nl_comment_icons_file()) ?? ['icons' => []];
        nm_ensure_dir(nl_comment_icons_dir());
        foreach ($delete as $key) {
            unset($r['icons'][$key]);
            foreach (['svg', 'png'] as $type) @unlink(nl_comment_icons_dir() . '/' . $key . '.' . $type);
        }
        foreach ($adds as $key => $icon) {
            nm_write_atomic(nl_comment_icons_dir() . '/' . $key . '.' . $icon['type'], $icon['bytes']);
            $r['icons'][$key] = ['name' => $icon['name'], 'added' => $r['icons'][$key]['added'] ?? time(), 'type' => $icon['type']];
        }
        nl_write_record(nl_comment_icons_file(), $r);
    });
}
/** comment.php?icon=<key>: an added icon, as a picture only (no scripts, nothing loaded from elsewhere). */
function nl_comment_icon_serve(string $key): never
{
    $icon = preg_match(NL_COMMENT_ICON_KEY, $key) ? (nl_comment_icons_custom()[$key] ?? null) : null;
    if (!$icon) nm_not_found();
    header('Content-Type: ' . ($icon['type'] === 'png' ? 'image/png' : 'image/svg+xml'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile(nl_comment_icons_dir() . '/' . $key . '.' . $icon['type']);
    exit;
}

/* ---- Storage -------------------------------------------------------------------------------- */

function nl_comments_read(string $id): array
{
    $r = nl_read_record(nl_comments_file($id));
    return array_values(array_filter(is_array($r['items'] ?? null) ? $r['items'] : [], 'is_array'));
}
function nl_comments_write(string $id, array $items): void
{
    if (!$items) { @unlink(nl_comments_file($id)); if (function_exists('opcache_invalidate')) @opcache_invalidate(nl_comments_file($id), true); return; }
    nm_ensure_dir(nl_comments_dir());
    nl_write_record(nl_comments_file($id), ['schema' => 1, 'items' => array_values($items)]);
}
/** The counts: 'ok' and 'wait' per id, 'new' = shown comments the owner has not looked at yet. */
function nl_comments_index(): array { return nl_memo('comments-index', 'nl_comments_index_read'); }
function nl_comments_index_read(): array
{
    $r = nl_read_record(nl_comments_index_file());
    if (!is_array($r) || !is_array($r['n'] ?? null)) return nl_comments_index_rebuild(false);
    return ['new' => max(0, (int)($r['new'] ?? 0)), 'n' => $r['n'], 'names' => is_array($r['names'] ?? null) ? $r['names'] : []];
}
/** From the comment files (after a restore, or when the index is missing). */
function nl_comments_index_rebuild(bool $write = true): array
{
    $index = ['new' => 0, 'n' => [], 'names' => []];
    foreach (glob(nl_comments_dir() . '/*.php') ?: [] as $file) {
        $id = basename($file, '.php');
        if (!preg_match(NL_COMMENT_TARGET, $id)) continue;
        $n = ['ok' => 0, 'wait' => 0];
        foreach (nl_comments_read($id) as $c) {
            $wait = ($c['st'] ?? '') === 'wait';
            $n[$wait ? 'wait' : 'ok']++;
            // A shown comment with a name: the owner approved that name for that reader.
            if (!$wait && ($c['name'] ?? '') !== '' && ($c['uid'] ?? '') !== '') $index['names'][$c['uid'] . '|' . $c['name']] = 1;
        }
        if ($n['ok'] + $n['wait'] > 0) $index['n'][$id] = $n;
    }
    if ($write) nl_write_record(nl_comments_index_file(), $index);
    return $index;
}
function nl_comments_index_write(array $index): void
{
    $index['n'] = array_filter($index['n'], static fn($n) => ($n['ok'] ?? 0) + ($n['wait'] ?? 0) > 0);
    $names = array_slice($index['names'] ?? [], -NL_COMMENT_NAMES_MAX, null, true);
    nl_write_record(nl_comments_index_file(), ['new' => max(0, $index['new']), 'n' => $index['n'], 'names' => $names]);
}
/** [shown, waiting] for one post or page. */
function nl_comment_counts(string $id): array
{
    $n = nl_comments_index()['n'][$id] ?? [];
    return [(int)($n['ok'] ?? 0), (int)($n['wait'] ?? 0)];
}
/** Texts waiting for approval, the whole site. */
function nl_comments_waiting(): int { return array_sum(array_map(static fn($n) => (int)($n['wait'] ?? 0), nl_comments_index()['n'])); }
/** The number on the bell: comments to approve, and shown ones the owner has not seen. */
function nl_comments_notice(): int
{
    if (!nl_settings()['comments'] && !nl_comments_index()['n']) return 0;
    return nl_comments_waiting() + nl_comments_index()['new'];
}
/** The owner opened the comment list. */
function nl_comments_seen(): void
{
    if (nl_comments_index()['new'] === 0) return;
    nm_with_lock('log-comments', static function () {
        $index = nl_comments_index_read(); $index['new'] = 0;
        nl_comments_index_write($index);
    });
}

/* ---- Readers -------------------------------------------------------------------------------- */

/* ---- Against bots: nothing for a reader to do ---------------------------------------------------- */

/** A ticket for writing on $id, signed with the site's secret: "time.mac". Sent with the comments (GET comment.php). */
function nl_comment_ticket(string $id, ?int $time = null): string
{
    $time ??= time();
    return $time . '.' . substr(hash_hmac('sha256', 'log-comment-ticket|' . $id . '|' . $time, (string)(nm_config()['secret'] ?? '')), 0, 24);
}
/**
 * '' when the ticket is good, else a short code: 'fast' (sent sooner than a person could, or by a script that never opened the page),
 * 'old' (the page was open for hours: the script gets a new ticket).
 */
function nl_comment_ticket_check(string $id, mixed $ticket): string
{
    if (!is_string($ticket) || !preg_match('/\A([0-9]{10})\.[a-f0-9]{24}\z/', $ticket, $m) || !hash_equals(nl_comment_ticket($id, (int)$m[1]), $ticket)) return 'fast';
    $age = time() - (int)$m[1];
    if ($age < NL_COMMENT_TICKET_WAIT) return 'fast';
    return $age > NL_COMMENT_TICKET_LIFE ? 'old' : '';
}
/** True when a reader's text or name holds an address (full-width letters count too). */
function nl_comment_has_url(string $text): bool
{
    return $text !== '' && preg_match(NL_COMMENT_URL, mb_convert_kana($text, 'as', 'UTF-8')) === 1;
}

/** The ID shown beside a comment: made from the reader's key, so a changing IP address keeps it and nobody else can show it. */
function nl_comment_uid(string $token): string
{
    $mac = hash_hmac('sha256', 'log-comment-id|' . $token, (string)(nm_config()['secret'] ?? ''), true);
    return substr(strtr(rtrim(base64_encode($mac), '='), '+/', 'xy'), 0, 8);
}
/** The line, for the owner only (several IDs from one line stand out); not the address itself. */
function nl_comment_net(): string
{
    return substr(hash_hmac('sha256', 'log-comment-net|' . nm_client_net(), (string)(nm_config()['secret'] ?? '')), 0, 8);
}
/** Plain text: line breaks kept (at most a few), no control characters. Returns '' for nothing. */
function nl_comment_text(mixed $text): string
{
    if (!is_string($text) || !mb_check_encoding($text, 'UTF-8')) throw new UnexpectedValueException('text');
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
    $text = preg_replace("/\n{2,}/", "\n", $text) ?? '';
    if (mb_strlen($text) > NL_COMMENT_TEXT_MAX || substr_count($text, "\n") >= NL_COMMENT_LINES_MAX) throw new UnexpectedValueException('long');
    return $text;
}
/** A reader's name: one line, no control characters, at most NL_COMMENT_NAME_MAX characters ('' for none). */
function nl_comment_name(mixed $name): string
{
    if ($name === null) return '';
    if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) throw new UnexpectedValueException('form');
    $name = trim(preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{25C6}]/u', '', $name) ?? '');
    if (mb_strlen($name) > NL_COMMENT_NAME_MAX) throw new UnexpectedValueException('long');
    return $name;
}
/** What a reader sees of a comment. 'to': the comment its thread starts with, 're': the one it answers, 'own': written by the owner. */
function nl_comment_public(array $c): array
{
    return ['id' => (string)$c['id'], 't' => (int)$c['t'], 'uid' => (string)$c['uid'], 'name' => (string)($c['name'] ?? ''), 'icon' => (string)$c['icon'], 'stamp' => (string)($c['stamp'] ?? ''), 'text' => (string)($c['text'] ?? ''),
        'to' => (string)($c['to'] ?? ''), 're' => (string)($c['re'] ?? ''), 'own' => !empty($c['own']), 'wait' => ($c['st'] ?? '') === 'wait'];
}
/**
 * Where a reply goes, from the comments of its post or page: ['to' => thread, 're' => answered comment]. Replies stay one level deep:
 * an answer to a reply joins the same thread (and names whom it answers). Throws 'gone' when that comment is not shown (any more).
 */
function nl_comment_reply_to(array $items, string $re, bool $owner = false): array
{
    if ($re === '') return ['to' => '', 're' => ''];
    if (!preg_match(NL_COMMENT_ID, $re)) throw new UnexpectedValueException('form');
    foreach ($items as $c) if ($c['id'] === $re && ($owner || ($c['st'] ?? '') !== 'wait')) return ['to' => ($c['to'] ?? '') !== '' ? (string)$c['to'] : $re, 're' => $re];
    throw new UnexpectedValueException('gone');
}
/** The comments a reader sees: the shown ones, and their own that wait for approval. */
function nl_comments_for(string $id, string $uid = ''): array
{
    $out = [];
    foreach (nl_comments_read($id) as $c) if (($c['st'] ?? '') !== 'wait' || ($uid !== '' && hash_equals((string)$c['uid'], $uid))) $out[] = nl_comment_public($c);
    return $out;
}
/**
 * A reader's comment. $in: token, icon, stamp, text. A stamp alone is shown at once; a text waits for the owner.
 * Returns the comment as the reader sees it. Refusals are short codes for the page script.
 */
function nl_comment_add(string $id, array $in): array
{
    $s = nl_settings();
    $token = is_string($in['token'] ?? null) ? $in['token'] : '';
    $icon = is_string($in['icon'] ?? null) ? $in['icon'] : '';
    $stamp = is_string($in['stamp'] ?? null) ? $in['stamp'] : '';
    if (!preg_match(NL_COMMENT_TOKEN, $token) || !isset(nl_comment_icons()[$icon])) throw new UnexpectedValueException('form');
    if ($stamp !== '' && !in_array($stamp, $s['comment_stamps'], true)) throw new UnexpectedValueException('form');
    $text = nl_comment_text($in['text'] ?? '');
    $name = nl_comment_name($in['name'] ?? '');
    $re = is_string($in['re'] ?? null) ? $in['re'] : '';
    if ($text !== '' && !$s['comment_text']) throw new UnexpectedValueException('form');
    if ($stamp === '' && $text === '') throw new UnexpectedValueException('empty');
    // Addresses are what spam is for: they are never taken (the owner's own replies may have them).
    if (nl_comment_has_url($text) || nl_comment_has_url($name)) throw new UnexpectedValueException('url');
    $c = ['id' => bin2hex(random_bytes(5)), 't' => time(), 'uid' => nl_comment_uid($token), 'name' => $name, 'icon' => $icon, 'stamp' => $stamp, 'text' => $text, 'to' => '', 're' => '', 'st' => 'ok', 'net' => nl_comment_net()];
    return nm_with_lock('log-comments', static function () use ($id, $c, $re) {
        $items = nl_comments_read($id);
        if (count($items) >= NL_COMMENT_ITEM_MAX) throw new UnexpectedValueException('full');
        $c = nl_comment_reply_to($items, $re) + $c;
        $index = nl_comments_index_read();
        // A text waits for the owner; so does a name the owner has not yet approved for this reader (else a stamp could carry any words).
        if ($c['text'] !== '' || ($c['name'] !== '' && !isset($index['names'][$c['uid'] . '|' . $c['name']]))) $c['st'] = 'wait';
        if ($c['st'] === 'wait' && array_sum(array_map(static fn($n) => (int)($n['wait'] ?? 0), $index['n'])) >= NL_COMMENT_WAIT_MAX) throw new UnexpectedValueException('busy');
        $items[] = $c;
        nl_comments_write($id, $items);
        $index['n'][$id] = ['ok' => (int)($index['n'][$id]['ok'] ?? 0), 'wait' => (int)($index['n'][$id]['wait'] ?? 0)];
        $index['n'][$id][$c['st']]++;
        if ($c['st'] === 'ok') $index['new']++;
        nl_comments_index_write($index);
        return nl_comment_public($c);
    });
}

/* ---- The owner ------------------------------------------------------------------------------ */

/** Approve ('ok') or delete ('delete') comments: [[target id, comment id], …]. */
function nl_comments_moderate(array $pairs, string $action): int
{
    if (!in_array($action, ['ok', 'delete'], true)) throw new UnexpectedValueException(nm_t('操作を選んでください'));
    $byTarget = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1]) || !preg_match(NL_COMMENT_TARGET, $pair[0]) || !preg_match(NL_COMMENT_ID, $pair[1])) throw new UnexpectedValueException(nm_t('コメントを選び直してください'));
        $byTarget[$pair[0]][$pair[1]] = true;
    }
    if (!$byTarget || count($pairs) > 500) throw new UnexpectedValueException(nm_t('コメントを選び直してください'));
    return nm_with_lock('log-comments', static function () use ($byTarget, $action) {
        $done = 0;
        foreach ($byTarget as $id => $ids) {
            // A post id is all digits, so PHP made the key an integer.
            $id = (string)$id;
            $items = [];
            foreach (nl_comments_read($id) as $c) {
                // A deleted comment takes the replies under it along (they would answer nothing).
                $hit = isset($ids[$c['id']]) || ($action === 'delete' && ($c['to'] ?? '') !== '' && isset($ids[$c['to']]));
                if (!$hit) { $items[] = $c; continue; }
                $done++;
                if ($action === 'ok') { $c['st'] = 'ok'; $items[] = $c; }
            }
            nl_comments_write($id, $items);
        }
        $index = nl_comments_index_rebuild(false);
        $index['new'] = nl_comments_index_read()['new'];
        nl_comments_index_write($index);
        return $done;
    });
}
/** The owner answers a comment from the admin list: shown at once, marked as the owner's, and the answered comment is approved too. */
function nl_comment_owner_reply(string $id, string $re, string $text): array
{
    if (!preg_match(NL_COMMENT_TARGET, $id) || !nl_comment_target($id)) throw new UnexpectedValueException(nm_t('コメントを選び直してください'));
    try { $text = nl_comment_text($text); } catch (UnexpectedValueException) { throw new UnexpectedValueException(nm_t('返信は{n}文字・数行までにしてください', ['n' => NL_COMMENT_TEXT_MAX])); }
    if ($text === '') throw new UnexpectedValueException(nm_t('返信の文章を書いてください'));
    return nm_with_lock('log-comments', static function () use ($id, $re, $text) {
        $items = nl_comments_read($id);
        if (count($items) >= NL_COMMENT_ITEM_MAX) throw new UnexpectedValueException(nm_t('この記事のコメントはいっぱいです。'));
        try { $where = nl_comment_reply_to($items, $re, true); } catch (UnexpectedValueException) { throw new UnexpectedValueException(nm_t('返信先のコメントが見つかりません')); }
        foreach ($items as &$c) if ($c['id'] === $re) $c['st'] = 'ok';
        unset($c);
        $items[] = $mine = ['id' => bin2hex(random_bytes(5)), 't' => time(), 'uid' => '', 'name' => '', 'icon' => '', 'stamp' => '', 'text' => $text, 'st' => 'ok', 'own' => true, 'net' => ''] + $where;
        nl_comments_write($id, $items);
        $index = nl_comments_index_rebuild(false);
        $index['new'] = nl_comments_index_read()['new'];
        nl_comments_index_write($index);
        return $mine;
    });
}
/** Deleted posts and pages take their comments with them. */
function nl_comments_forget(array $ids): void
{
    $ids = array_values(array_filter(array_map('strval', $ids), static fn($id) => preg_match(NL_COMMENT_TARGET, $id) && is_file(nl_comments_file($id))));
    if (!$ids) return;
    nm_with_lock('log-comments', static function () use ($ids) {
        foreach ($ids as $id) nl_comments_write($id, []);
        $index = nl_comments_index_read();
        foreach ($ids as $id) unset($index['n'][$id]);
        nl_comments_index_write($index);
    });
}
/** Every comment for the owner's list, newest first: each with 'target' (its post or page id). */
function nl_comments_all(): array
{
    $all = [];
    foreach (array_keys(nl_comments_index()['n']) as $id) foreach (nl_comments_read((string)$id) as $c) $all[] = $c + ['target' => (string)$id];
    usort($all, static fn($a, $b) => $b['t'] <=> $a['t']);
    return $all;
}
/** For the backup: id => comments. */
function nl_comments_backup(): array
{
    $out = [];
    foreach (array_keys(nl_comments_index_rebuild(false)['n']) as $id) $out[(string)$id] = nl_comments_read((string)$id);
    return $out;
}
/** A comment from a backup, checked like a new one (the owner's own file, but it may have been edited). */
function nl_comment_restore(mixed $c): ?array
{
    if (!is_array($c)) return null;
    $own = ($c['own'] ?? false) === true;
    if ( !is_string($c['id'] ?? null) || !preg_match(NL_COMMENT_ID, $c['id']) || !is_int($c['t'] ?? null) || !is_string($c['uid'] ?? null) || !preg_match($own ? '/\A\z/' : '/\A[A-Za-z0-9]{8}\z/', $c['uid'])
        || !is_string($c['icon'] ?? null) || !($own ? $c['icon'] === '' : (isset(NL_COMMENT_ICONS[$c['icon']]) || preg_match(NL_COMMENT_ICON_KEY, $c['icon'])))) return null;
    $stamp = is_string($c['stamp'] ?? null) && ($c['stamp'] === '' || nl_comment_stamp_ok($c['stamp'])) ? $c['stamp'] : null;
    try { $text = nl_comment_text($c['text'] ?? ''); $name = nl_comment_name($c['name'] ?? ''); } catch (UnexpectedValueException) { return null; }
    $to = $c['to'] ?? ''; $re = $c['re'] ?? '';
    if ($stamp === null || ($stamp === '' && $text === '') || !is_string($to) || !is_string($re) || ($to !== '' && !preg_match(NL_COMMENT_ID, $to)) || ($re !== '' && !preg_match(NL_COMMENT_ID, $re)) || ($to === '') !== ($re === '')) return null;
    return ['id' => $c['id'], 't' => $c['t'], 'uid' => $c['uid'], 'name' => $name, 'icon' => $c['icon'], 'stamp' => $stamp, 'text' => $text, 'to' => $to, 're' => $re, 'st' => ($c['st'] ?? '') === 'wait' ? 'wait' : 'ok', 'net' => is_string($c['net'] ?? null) && preg_match('/\A[a-f0-9]{8}\z/', $c['net']) ? $c['net'] : ''] + ($own ? ['own' => true] : []);
}

/* ---- The public page ------------------------------------------------------------------------ */

/** The comment block under a post or fixed page; viewer/log-comment.js fills it from comment.php (the page itself stays cached). */
function nl_comments_section(array $item): string
{
    [$ok] = nl_comment_counts((string)$item['id']);
    return '<section class="log-comments" id="comments" data-comments="' . h($item['id']) . '" aria-labelledby="log-comments-title"><h2 class="log-comments-title" id="log-comments-title">' . nm_icon('ui-comment', 'log-comments-icon') . '<span>' . nm_t('コメント') . '</span><span class="log-comments-count" data-comments-count>' . $ok . '</span>'
        // Right beside the count: a jump down to the form (the script also leaves a reply, if one was open).
        . '<a class="log-comments-write" href="#comments-form" data-comments-write>' . nm_icon('ui-pen', 'log-comments-write-icon') . '<span>' . nm_t('書き込む') . '</span></a></h2>'
        . '<div class="log-comments-list" data-comments-list><p class="log-comments-note">' . nm_t('読み込んでいます') . '</p></div><div class="log-comments-form" id="comments-form" data-comments-form></div>'
        . '<noscript><p class="log-comments-note">' . nm_t('コメントを見たり書いたりするには、JavaScriptを有効にしてください。') . '</p></noscript></section>';
}

/** The comment button in the row under a post (beside Share and Copy): the shown count, and a jump to the comment block. */
function nl_comment_share_button(array $p): string
{
    [$ok] = nl_comment_counts((string)$p['id']);
    return '<a class="log-share-btn log-comment-link" href="' . h(nl_post_url($p['id'])) . '#comments" data-comments-for="' . h($p['id']) . '" aria-label="' . h(nm_t('コメント（{n}件）', ['n' => $ok])) . '">'
        . nm_icon('ui-comment', 'log-share-icon log-comment-link-icon') . '<span>' . $ok . '</span></a>';
}

/* ---- Admin screens -------------------------------------------------------------------------- */

/** The settings block "コメント" (LOG tab). */
function nl_comments_settings_panel(): string
{
    $s = nl_settings();
    $icons = '';
    $off = array_flip($s['comment_icons_off']);
    $custom = nl_comment_icons_custom();
    // Each icon: shown to readers or not; an added one can also be deleted (on saving the settings).
    foreach (nl_comment_icons(true, '../') as $key => $icon) $icons .= '<div class="log-comment-icon-choice"><label class="log-comment-icon-show"><input type="checkbox" name="comment_icons[]" value="' . h($key) . '"' . (isset($off[$key]) ? '' : ' checked') . '><img src="' . h($icon['url']) . '" alt="" width="40" height="40"><span>' . h($icon['name']) . '</span></label>'
        . (isset($custom[$key]) ? '<label class="log-comment-icon-delete"><input type="checkbox" name="comment_icon_delete[]" value="' . h($key) . '">' . nm_t('削除') . '</label>' : '<small class="log-comment-icon-tag">' . nm_t('標準') . '</small>') . '</div>';
    return '<section class="card" id="log-comments-settings"><h2>' . nm_t('コメント') . '</h2>' . nl_settings_block('log_comments')
        . '<label class="check"><input type="checkbox" name="comments" value="1"' . ($s['comments'] ? ' checked' : '') . '>' . nm_t('記事と固定ページにコメント欄を出せるようにする') . '</label>'
        . '<p class="note">' . nm_t('オンにすると、投稿画面と固定ページの編集画面に「コメント欄」のスイッチが出ます。これから作る記事・固定ページは最初からオン、今までのものはオフのままなので、出したいものだけオンにしてください。自分専用Memoのときは出しません。') . '</p>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('書き込めるもの') . '</legend>'
        . '<label>' . nm_t('スタンプ（絵文字を空白で区切って{n}個まで）', ['n' => NL_COMMENT_STAMPS_MAX]) . '<input name="comment_stamps" value="' . h(implode(' ', $s['comment_stamps'])) . '" autocomplete="off" spellcheck="false"></label>'
        . '<p class="note">' . nm_t('読者はアイコンを選び、スタンプを押すだけで書き込めます。スタンプだけのコメントはすぐに表示します。') . '</p>'
        . '<label class="check"><input type="checkbox" name="comment_text" value="1"' . ($s['comment_text'] ? ' checked' : '') . '>' . nm_t('文章のコメントも受け付ける（{n}文字まで）', ['n' => NL_COMMENT_TEXT_MAX]) . '</label>'
        . '<p class="note">' . nm_t('文章のついたコメントは、管理画面の「コメント」で承認するまで公開しません。書いた本人には「承認待ち」として見えます。HTMLやリンクにはならず、文字のまま表示します。') . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('選べるアイコン') . '</legend><div class="log-comment-icon-choices">' . $icons . '</div>'
        . '<p class="note">' . nm_t('チェックを外したアイコンは、新しいコメントでは選べなくなります。追加したアイコンは「削除」にチェックを入れて保存すると消えます（使ったコメントは最初のアイコンで表示します）。') . '</p>'
        . '<label>' . nm_t('アイコンを追加（SVG・PNG・JPEG・WebP・GIF、1枚{n}KBまで・複数可）', ['n' => NL_COMMENT_ICON_BYTES / 1024]) . '<input type="file" name="comment_icon_files[]" accept="image/svg+xml,.svg,image/png,image/jpeg,image/webp,image/gif" multiple></label>'
        . '<p class="note">' . nm_t('正方形の画像がきれいに収まります。PNGなどは128×128pxに縮めて保存し、写真の位置情報などは残しません。スクリプトや外部の読み込みを含むSVGは、安全のため受け付けません。ファイル名がアイコンの名前になります。追加できるのは{n}個までです。', ['n' => NL_COMMENT_ICON_UPLOADS]) . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('名前と印') . '</legend><p class="note">' . nm_t('読者のブラウザーに推測できない合言葉を保存し、そこから作った印（◆と6文字）を名前の横に出します。スマホでIPアドレスが変わっても同じ印のままで、ほかの人は同じ印を出せません。名前は任意（{n}文字まで）で、同じ名前を名乗られても印で見分けられます。合言葉をなくす（ブラウザーのデータを消す）と、印が変わります。', ['n' => NL_COMMENT_NAME_MAX]) . '</p>'
        . '<p class="note">' . nm_t('名前の付いたコメントは、その名前を一度承認するまで公開しません（スタンプだけでも）。承認したあとは、同じ人が同じ名前で書くとすぐに表示します。') . '</p>'
        . '<p class="note">' . nm_t('返信は1段まで入れ子にして表示します。返信への返信は同じ流れの中に並び、誰あての返信かを表示します。管理画面の「コメント」から、管理人として返信できます。') . '</p>'
        . '<p class="note">' . nm_t('bot対策として、読者には何もさせずに次の3つを確かめます。コメント欄を開いてから{n}秒たたないうちの送信と、ページを開かずに送られたものは断ります。人には見えない入力欄に何か入っていたら断ります。文章と名前にURLがあれば断ります。', ['n' => NL_COMMENT_TICKET_WAIT]) . '</p>'
        . '<p class="note">' . nm_t('連続の書き込みは、同じ回線から1分に{minute}件、1日に{day}件までです。IPアドレスそのものは保存せず、管理画面では回線ごとの短い印だけを見られます。', ['minute' => NL_COMMENT_MINUTE, 'day' => NL_COMMENT_DAY]) . '</p></fieldset>'
        . '</div></section>';
}
/** The switch in the post editor (a panel of its tool row) and the page editor (a fieldset). Only while comments are on. */
function nl_comments_switch(?array $item, bool $page): string
{
    if (!nl_settings()['comments']) return '';
    $on = nl_comments_default($item);
    $box = '<input type="hidden" name="comments_form" value="1"><label class="' . ($page ? 'check' : 'log-panel-check') . '"><input type="checkbox" name="comments" value="1"' . ($on ? ' checked' : '') . '>' . ($page ? nm_t('このページにコメント欄を出す') : nm_t('この記事にコメント欄を出す')) . '</label>';
    [$ok, $wait] = $item && preg_match(NL_COMMENT_TARGET, (string)($item['id'] ?? '')) ? nl_comment_counts((string)$item['id']) : [0, 0];
    $note = '<p class="note">' . nm_t('読者は匿名で、アイコンとスタンプ（設定によっては文章）を書き込めます。オフにすると、書き込まれたコメントも表示しません（消えません）。') . ($ok + $wait > 0 ? ' ' . nm_t('いまのコメント：表示中 {ok}件・承認待ち {wait}件', ['ok' => $ok, 'wait' => $wait]) . ' <a href="index.php?p=log_comments">' . nm_t('コメントを見る') . '</a>' : '') . '</p>';
    if ($page) return '<fieldset class="log-page-comments"><legend>' . nl_ui_icon('comment') . '<span>' . nm_t('コメント欄') . '</span></legend>' . $box . $note . '</fieldset>';
    return '<div class="log-compose-panel" id="log-panel-comments" data-panel-body="comments" hidden><p class="log-panel-title">' . nm_t('コメント欄') . '</p>' . $box . $note . '</div>';
}
/** The count beside a post or page in the admin lists (waiting ones stand out). */
function nl_comments_badge(string $id): string
{
    if (!preg_match(NL_COMMENT_TARGET, $id)) return '';
    [$ok, $wait] = nl_comment_counts($id);
    if ($ok + $wait === 0) return '';
    return '<small class="log-list-comments' . ($wait > 0 ? ' is-waiting' : '') . '" aria-label="' . h(nm_t('コメント{n}件', ['n' => $ok + $wait]) . ($wait > 0 ? nm_t('（承認待ち{n}件）', ['n' => $wait]) : '')) . '">' . nm_icon('ui-comment') . ($ok + $wait) . ($wait > 0 ? '<b>' . nm_t('承認待ち{n}', ['n' => $wait]) . '</b>' : '') . '</small>';
}
/** The number on the bell of the admin menu: comments to approve or not seen yet. */
function nl_comments_menu_badge(): string
{
    $n = nl_comments_notice();
    return $n > 0 ? ' <span class="badge new nm-menu-count" aria-label="' . h(nm_t('新しいコメント{n}件', ['n' => $n])) . '">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
}
/** The title and public address of a comment's post or page (null when it is gone). */
function nl_comment_target_info(string $id): ?array
{
    $item = nl_comment_target($id);
    if (!$item) return null;
    $page = str_starts_with($id, 'pg');
    return ['title' => $page ? $item['title'] : nl_post_title($item), 'url' => '../' . ($page ? nl_page_path($item) : nl_post_path($id)) . '#comments', 'open' => nl_comments_open($item)];
}
/** index.php?p=log_comments: every comment, to approve or delete (the icons are in the settings). */
function nl_view_comments(): void
{
    $show = nm_str($_GET, 'show', 10) === 'wait' ? 'wait' : '';
    $all = nl_comments_all();
    $waiting = count(array_filter($all, static fn($c) => ($c['st'] ?? '') === 'wait'));
    $list = $show === 'wait' ? array_values(array_filter($all, static fn($c) => ($c['st'] ?? '') === 'wait')) : $all;
    $per = 50; $page = nl_page_number();
    $icons = nl_comment_icons(true, '../');
    $first = array_key_first($icons);
    $targets = [];
    $byId = [];
    foreach ($all as $c) $byId[$c['target']][$c['id']] = $c;
    $s = nl_settings();
    $rows = '';
    $form = static fn(string $action, string $pair, string $show, string $button, string $confirm = '') => '<form method="post" action="index.php"' . ($confirm !== '' ? ' class="js-confirm" data-confirm="' . $confirm . '"' : '') . '>' . nl_csrf_field()
        . '<input type="hidden" name="do" value="log_comments_moderate"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="items" value="[' . $pair . ']"><input type="hidden" name="show" value="' . $show . '">' . $button . '</form>';
    foreach (array_slice($list, ($page - 1) * $per, $per) as $c) {
        $info = $targets[$c['target']] ??= nl_comment_target_info($c['target']);
        $wait = ($c['st'] ?? '') === 'wait';
        $own = !empty($c['own']);
        $icon = $own ? null : ($icons[$c['icon']] ?? $icons[$first]);
        $pair = h(json_encode([$c['target'], $c['id']]));
        $re = ($c['re'] ?? '') !== '' ? ($byId[$c['target']][$c['re']] ?? null) : null;
        $who = $own ? '<b>' . h($s['name']) . '</b><span class="badge">' . nm_t('管理人') . '</span>' : (($c['name'] ?? '') !== '' ? '<b>' . h($c['name']) . '</b> ' : '') . '<b>◆' . h(substr($c['uid'], 0, 6)) . '</b><span class="note" title="' . nm_t('同じ回線からの書き込みは、同じ印になります') . '">' . nm_t('回線 {net}', ['net' => h($c['net'] ?? '')]) . '</span>';
        $rows .= '<li class="log-comment-row' . ($wait ? ' is-waiting' : '') . '">' . ($icon ? '<img class="log-comment-row-icon" src="' . h($icon['url']) . '" alt="" width="40" height="40">' : nl_icon_html($s, '../', 'log-comment-row-icon'))
            . '<div class="log-comment-row-main"><p class="log-comment-row-meta">' . $who . '<time datetime="' . h(nl_date($c['t'], 'c')) . '">' . h(nl_date($c['t'])) . '</time>' . ($wait ? '<span class="badge">' . nm_t('承認待ち') . '</span>' : '') . '</p>'
            . (($c['re'] ?? '') !== '' ? '<p class="log-comment-row-to note">' . nm_t('↳ {who}への返信', ['who' => h($re ? (!empty($re['own']) ? nm_t('管理人') : trim(($re['name'] ?? '') . ' ◆' . substr($re['uid'], 0, 6))) : nm_t('削除したコメント'))]) . '</p>' : '')
            . ($c['stamp'] !== '' ? '<p class="log-comment-row-stamp">' . h($c['stamp']) . '</p>' : '') . ($c['text'] !== '' ? '<p class="log-comment-row-text">' . nl2br(h($c['text']), false) . '</p>' : '')
            . '<p class="log-comment-row-target">' . ($info ? nl_ui_icon('page') . '<a href="' . h($info['url']) . '" target="_blank" rel="noopener">' . h($info['title']) . '</a>' . ($info['open'] ? '' : '<small class="badge">' . nm_t('コメント欄はオフ') . '</small>') : '<span class="note">' . nm_t('（見つからない記事）') . '</span>') . '</p></div>'
            . ($own ? '' : '<details class="log-comment-reply"><summary class="btn">' . nl_ui_icon('comment') . '<span>' . nm_t('返信') . '</span></summary><form method="post" action="index.php">' . nl_csrf_field()
                . '<input type="hidden" name="do" value="log_comments_reply"><input type="hidden" name="target" value="' . h($c['target']) . '"><input type="hidden" name="re" value="' . h($c['id']) . '"><input type="hidden" name="show" value="' . $show . '">'
                . '<label>' . nm_t('管理人として返信（{n}文字まで・すぐ表示）', ['n' => NL_COMMENT_TEXT_MAX]) . '<textarea name="text" rows="3" maxlength="' . NL_COMMENT_TEXT_MAX . '" required></textarea></label>'
                . '<button class="btn primary">' . ($wait ? nm_t('承認して返信') : nm_t('返信する')) . '</button></form></details>')
            . '<div class="log-comment-row-actions">' . ($wait ? $form('ok', $pair, $show, '<button class="btn primary">' . nm_t('承認して表示') . '</button>') : '')
            . $form('delete', $pair, $show, '<button class="btn danger">' . nl_ui_icon('delete') . '<span>' . nm_t('削除') . '</span></button>', (($c['to'] ?? '') === '' ? nm_t('このコメントと、その返信を削除しますか？元に戻せません。') : nm_t('このコメントを削除しますか？元に戻せません。'))) . '</div></li>';
    }
    // Seen: the bell counts only what arrives after this.
    nl_comments_seen();
    $state = !$s['public'] ? nm_t('自分専用Memoのため、コメント欄は出していません。') : (!$s['comments'] ? nm_t('コメントは「設定 › LOG › コメント」でオフになっています。書き込まれたコメントは残しています。') : '');
    $filter = '<nav class="log-comment-filter" aria-label="' . nm_t('表示するコメント') . '"><a href="index.php?p=log_comments"' . ($show === '' ? ' aria-current="page"' : '') . '>' . nm_t('すべて（{n}）', ['n' => count($all)]) . '</a><a href="index.php?p=log_comments&amp;show=wait"' . ($show === 'wait' ? ' aria-current="page"' : '') . '>' . nm_t('承認待ち（{n}）', ['n' => $waiting]) . '</a></nav>';
    nm_layout(nm_t('コメント'), '<div class="log-heading"><div><h1>' . nm_t('コメント') . '</h1><p class="note">' . h($s['title']) . ' ' . nm_t('· 記事と固定ページに書き込まれた匿名のコメントです。') . '</p></div><div class="log-toolbar"><a class="btn" href="index.php?p=settings&amp;section=log#log-comments-settings">' . nl_ui_icon('settings') . '<span>' . nm_t('コメントの設定') . '</span></a></div></div>'
        . nl_pages_tabs('comments') . ($state !== '' ? '<p class="flash">' . $state . '</p>' : '') . $filter
        . '<ul class="log-comment-list">' . ($rows ?: '<li class="log-empty">' . ($show === 'wait' ? nm_t('承認待ちのコメントはありません。') : nm_t('まだコメントはありません。')) . '</li>') . '</ul>'
        . nl_pager(['posts_per_page' => $per, 'pager' => 'numbers', 'pager_status' => true], 'p=log_comments' . ($show !== '' ? '&show=' . $show : ''), $page, count($list), 'index.php', 'コメント'));
}
/** The admin's comment actions (lib/log-admin.php nl_handle_post). */
function nl_comments_handle(string $do): never
{
    switch ($do) {
        case 'log_comments_moderate':
            $action = nm_str($_POST, 'action', 10);
            $n = nl_comments_moderate(nl_json_list(nm_str($_POST, 'items', 50000)), $action);
            nm_flash('ok', $action === 'ok' ? nm_t('コメントを{n}件承認しました', ['n' => $n]) : nm_t('コメントを{n}件削除しました', ['n' => $n]));
            nm_redirect('p=log_comments' . (nm_str($_POST, 'show', 10) === 'wait' ? '&show=wait' : ''));
        case 'log_comments_reply':
            nl_comment_owner_reply(nm_str($_POST, 'target', 24), nm_str($_POST, 're', 10), nm_str($_POST, 'text', 4000));
            nm_flash('ok', nm_t('返信しました'));
            nm_redirect('p=log_comments' . (nm_str($_POST, 'show', 10) === 'wait' ? '&show=wait' : ''));
    }
    nm_not_found();
}
