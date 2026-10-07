<?php
/* A same-origin frame for a verified Mastodon instance; its origin never broadens the parent CSP. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/log.php';
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
