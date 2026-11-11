<?php
/*
 * LOG comments. MIT License (c) 2026 Lichiphen.
 *   GET  comment.php?id=ID      the comments, the icons and stamps to choose from (header X-NagiLog-Key: the reader's key, to see their own waiting texts)
 *   POST comment.php            {"id", "token", "icon", "stamp", "text", "name", "re", "ticket", "website"} with the header X-NagiLog: comment
 *   GET  comment.php?icon=KEY   an icon the owner added (SVG, served as a picture only)
 * Pages stay cached: comments arrive here, never by rebuilding a page.
 */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/log.php';
require __DIR__ . '/lib/scope.php';
if (!nm_config() || empty(nm_config()['admin_hash']) || ns_denied()) nm_not_found();
// The owner reads the comments of a locked post without its key, as on the page itself (log.php).
$owner = false;
if (isset($_COOKIE['nm_admin']) && is_string($_COOKIE['nm_admin'])) {
    nm_session_start();
    $owner = nm_ip_allowed(nm_config()) && nm_is_logged_in() && !nm_is_guest();
}
define('NL_OWNER', $owner);
nm_lang(nm_site_lang());
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (isset($_GET['icon']) && in_array($method, ['GET', 'HEAD'], true)) { nm_lscache_header(false, false); nl_comment_icon_serve(nm_str($_GET, 'icon', 13)); }
if (!nl_comments_on()) nm_not_found();
nm_lscache_header(false, false);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
// A post or page that takes comments and that this reader may read (a locked one only with its key).
$target = static function (string $id): array {
    $item = nl_comment_target($id);
    if (!nl_comments_open($item) || !nl_unlocked($item)) nm_not_found();
    return $item;
};
if ($method === 'GET') {
    $id = nm_str($_GET, 'id', 24);
    $target($id);
    $token = (string)($_SERVER['HTTP_X_NAGILOG_KEY'] ?? '');
    $uid = preg_match(NL_COMMENT_TOKEN, $token) ? nl_comment_uid($token) : '';
    $s = nl_settings();
    $icons = [];
    foreach (nl_comment_icons() as $key => $icon) $icons[] = ['key' => $key, 'name' => $icon['name'], 'url' => $icon['url']];
    // The owner's replies carry the LOG's name and icon.
    $avatar = nl_load_media($s['icon']);
    nm_json(['items' => nl_comments_for($id, $uid), 'icons' => $icons, 'stamps' => $s['comment_stamps'], 'text' => $s['comment_text'], 'max' => NL_COMMENT_TEXT_MAX, 'name_max' => NL_COMMENT_NAME_MAX, 'me' => $uid, 'ticket' => nl_comment_ticket($id), 'wait' => NL_COMMENT_TICKET_WAIT,
        'owner' => ['name' => $s['name'], 'icon' => $avatar ? nl_media_url($avatar, true) : '']]);
}
if ($method !== 'POST') nm_not_found();
// A custom header cannot be sent by another site's form, and fetch() from another origin needs a preflight we never answer.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$from = parse_url($origin) ?: [];
$fromHost = ($from['host'] ?? '') . (isset($from['port']) ? ':' . $from['port'] : '');
if (($_SERVER['HTTP_X_NAGILOG'] ?? '') !== 'comment' || ($origin !== '' && strcasecmp($fromHost, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0)) nm_not_found();
$raw = file_get_contents('php://input', false, null, 0, 4000);
$in = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($in) || !is_string($in['id'] ?? null)) nm_not_found();
$target($in['id']);
// A few a minute and a day from one line; a script writing faster only meets "busy".
nm_rate_gc();
$net = nm_client_net();
if (nm_rate_hit('log-comment', $net, 60) > NL_COMMENT_MINUTE || nm_rate_hit('log-comment-day', $net, 86400) > NL_COMMENT_DAY) nm_json(['error' => 'busy'], 429);
// Bots: a ticket from opening the comments (not too fresh, not too old), and the hidden field left empty.
$bot = nl_comment_ticket_check($in['id'], $in['ticket'] ?? null);
if ($bot === '' && ($in['website'] ?? '') !== '') $bot = 'spam';
if ($bot !== '') { nm_log('log_comment_refused', $in['id'] . ' (' . $bot . ')'); nm_json(['error' => $bot] + ($bot === 'old' ? ['ticket' => nl_comment_ticket($in['id'])] : []), 422); }
try {
    $c = nl_comment_add($in['id'], $in);
} catch (UnexpectedValueException $e) {
    nm_json(['error' => in_array($e->getMessage(), ['empty', 'long', 'full', 'busy', 'gone', 'url'], true) ? $e->getMessage() : 'form'], 422);
}
nm_log('log_comment', $in['id'] . ($c['wait'] ? ' (waiting)' : ''));
// A shown comment changes the counts beside Share on the cached pages: they are rebuilt on their next visit.
if (!$c['wait']) nm_lscache_purge(true);
nm_json(['item' => $c, 'me' => $c['uid']]);
