<?php
/*
 * NagiScope: where pages send what readers do (navigator.sendBeacon, text/plain JSON; one event or a list of up to 5).
 *   POST scope.php   {"e": "pv", "s": "<page session>", "k": "p", "i": "<post id>", ...}
 * Answers 204 whatever happens, so a page learns nothing from it. Nothing is recorded while NagiScope is off,
 * for programs, for the logged-in owner, for turned-away addresses or past the per-minute limit.
 * MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/log.php';
require __DIR__ . '/lib/scope.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
nm_lscache_header(false, false);
$cfg = nm_config();
if (!$cfg || empty($cfg['admin_hash']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') nm_not_found();
if (!ns_on()) { http_response_code(204); exit; }
$raw = file_get_contents('php://input', false, null, 0, 16384);
$in = is_string($raw) && strlen($raw) < 16384 ? json_decode($raw, true) : null;
if (is_array($in) && !array_is_list($in)) $in = [$in];
if (is_array($in) && $in && count($in) <= NS_EVENTS_PER_SEND && ns_may_record()) {
    foreach ($in as $one) {
        if (!is_array($one)) continue;
        try {
            $rec = ns_event($one);
            if ($rec !== null) ns_append($rec);
        } catch (Throwable) {
            // A broken event is dropped; the reader's page never notices.
        }
    }
}
http_response_code(204);
