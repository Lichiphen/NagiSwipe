<?php
/*
 * LOG likes. MIT License (c) 2026 Lichiphen.
 *   GET  like.php?ids=ID,ID   counts, and how many likes this reader may still add today
 *   POST like.php             {"id": ID, "n": 1-100} with the header X-NagiLog: like
 * Pages stay cached: counts arrive here, never by rebuilding a page.
 */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/log.php';
if (!nm_config() || empty(nm_config()['admin_hash'])) nm_not_found();
$s = nl_settings();
if (!$s['public'] || !$s['likes']) nm_not_found();
nm_lscache_header(false, false);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$published = static fn(string $id): bool => nl_valid_post($id) && ($p = nl_load_post($id)) !== null && $p['status'] === 'published';
if ($method === 'GET') {
    $ids = array_values(array_unique(array_filter(explode(',', nm_str($_GET, 'ids', 2500)), 'nl_valid_post')));
    if (!$ids || count($ids) > 100) nm_not_found();
    $counts = nl_like_counts();
    $likes = [];
    foreach ($ids as $id) $likes[$id] = $counts[$id] ?? 0;
    nm_json(['likes' => $likes, 'left' => nl_like_left($ids), 'daily' => NL_LIKE_DAILY]);
}
if ($method !== 'POST') nm_not_found();
// A custom header cannot be sent by another site's form, and fetch() from another origin needs a preflight we never answer.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$from = parse_url($origin) ?: [];
$fromHost = ($from['host'] ?? '') . (isset($from['port']) ? ':' . $from['port'] : '');
if (($_SERVER['HTTP_X_NAGILOG'] ?? '') !== 'like' || ($origin !== '' && strcasecmp($fromHost, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0)) nm_not_found();
$raw = file_get_contents('php://input', false, null, 0, 300);
$in = is_string($raw) ? json_decode($raw, true) : null;
$id = is_array($in) && is_string($in['id'] ?? null) ? $in['id'] : '';
$n = is_array($in) && is_int($in['n'] ?? null) ? $in['n'] : 0;
if ($n < 1 || $n > NL_LIKE_DAILY || !$published($id)) nm_not_found();
// The page sends one request per burst of taps; this only stops scripts hammering the counter file.
nm_rate_gc();
if (nm_rate_hit('log-like', nm_client_ip(), 60) > 60) nm_json(['error' => 'busy'], 429);
[$count, $left] = nl_like_add($id, $n);
nm_json(['id' => $id, 'count' => $count, 'left' => $left]);
