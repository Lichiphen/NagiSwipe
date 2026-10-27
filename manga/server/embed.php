<?php
/*
 * A same-origin frame for a verified Mastodon instance, or for a page embed ([ページ埋込]URL[/ページ埋込]);
 * their origins never broaden the parent CSP. MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/log.php';
const NL_FRAME_ALLOW = 'autoplay; gamepad; clipboard-write; screen-wake-lock';
if (isset($_GET['u'])) {
    // Only a URL this site signed when it drew the post (nl_frame_sig()), so the address cannot show any page someone types in.
    $url = $_GET['u']; $sig = $_GET['s'] ?? '';
    $f = is_string($url) && is_string($sig) && strlen($url) <= 2000 ? nl_frame_target($url) : null;
    if (!$f || !hash_equals(nl_frame_sig($f['url']), $sig)) nm_not_found();
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-src " . $f['origin'] . "; frame-ancestors 'self'; form-action 'none'; base-uri 'none'; object-src 'none'");
    // The page keeps its scripts, storage and full screen; its links open in new windows, and it can never move this site's own page elsewhere.
    echo '<!doctype html><html lang="' . nm_lang() . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($f['host']) . '</title>'
        . '<style>html,body{margin:0;height:100%;background:#000;overflow:hidden}iframe{display:block;width:100%;height:100%;border:0}</style>'
        . '<iframe src="' . h($f['url']) . '" title="' . h($f['host']) . '" allow="' . NL_FRAME_ALLOW . '" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"'
        . ' sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-pointer-lock allow-popups allow-popups-to-escape-sandbox allow-downloads"></iframe></html>';
    exit;
}
$key = $_GET['k'] ?? '';
if (!is_string($key) || !preg_match('/\A[a-f0-9]{20}\z/', $key)) nm_not_found();
$c = nl_read_record(nl_root() . '/embeds/' . $key . '.php');
$e = is_array($c) && is_string($c['url'] ?? null) ? nl_embed_detect($c['url']) : null;
if (!$e || $e['type'] !== 'mastodon' || nl_embed_key($e) !== $key || !($d = nl_embed_resolved($e))) nm_not_found();
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-src https://" . $e['host'] . "; frame-ancestors 'self'; base-uri 'none'; object-src 'none'");
echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mastodon</title><style>html,body{margin:0;height:100%;background:transparent}iframe{display:block;width:100%;height:100%;border:0}</style><iframe src="' . h($d['src']) . '" title="Mastodonの投稿" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe></html>';
