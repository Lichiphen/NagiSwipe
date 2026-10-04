<?php
/* Public personal LOG. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/log.php';
require __DIR__ . '/lib/log-public.php';
require __DIR__ . '/lib/log-admin.php';
// Keep old bookmarks; all generated and canonical URLs use the directory root.
if (!defined('NL_FRONT') && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ./' . ($query !== '' ? '?' . $query : ''), true, 301);
    header('Cache-Control: no-cache');
    exit;
}
if (!nm_config() || empty(nm_config()['admin_hash'])) nm_not_found();
// Anonymous readers create no session. Only a valid owner session adds controls.
$owner = false;
if (isset($_COOKIE['nm_admin']) && is_string($_COOKIE['nm_admin'])) {
    nm_session_start();
    $owner = nm_ip_allowed(nm_config()) && nm_is_logged_in() && !nm_is_guest();
}
define('NL_OWNER', $owner);
$s = nl_settings();
header('Vary: Cookie');
if (!$s['public'] && !$owner) nm_not_found();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// LiteSpeed caches only what a visitor without the login cookie sees on a public LOG; images stay with PHP (BOT guard).
nm_lscache_header(!$owner && $s['public'] && !isset($_COOKIE['nm_admin']) && !isset($_GET['media']) && !isset($_GET['card']));
if (!in_array($method, ['GET', 'HEAD'], true)) nm_not_found();
if (isset($_GET['card'])) nl_serve_card(nm_str($_GET, 'card', 20));
if (isset($_GET['media'])) nl_serve_media(nm_str($_GET, 'media', 16), isset($_GET['thumb']), $owner);
$id = nm_str($_GET, 'id', 24);
$single = isset($_GET['id']);
$post = $single ? nl_load_post($id) : null;
if ($single && (!$post || $post['status'] !== 'published')) nm_not_found();
$summaries = nl_post_summaries();
$filter = nl_archive_filter($single ? ['month' => nl_date($post['created'], 'Y-m')] : $_GET);
$all = $single ? [$post] : array_values(array_filter($summaries, static function ($p) use ($filter) {
    if ($filter['category'] !== '') return in_array($filter['category'], $p['categories'] ?? [], true);
    if ($filter['tag'] !== '') return in_array($filter['tag'], $p['hashtags'] ?? [], true);
    if ($filter['search'] !== '') return true;
    return $filter['query'] === '' || nl_date($p['created'], $filter['date'] !== '' ? 'Y-m-d' : 'Y-m') === ($filter['date'] ?: $filter['month']);
}));
if ($filter['search'] !== '') $all = nl_search_posts($all, $filter['terms']);
$page = max(1, min(100000, (int)nm_str($_GET, 'page', 6)));
$perPage = $s['posts_per_page'];
// A page past the end (old bookmark, typed URL) is not an empty list.
if (!$single && $all && ($page - 1) * $perPage >= count($all)) nm_not_found();
$posts = $single ? [$post] : array_values(array_filter(array_map(static fn($s) => nl_load_post($s['id']), array_slice($all, ($page - 1) * $perPage, $perPage)), static fn($p) => $p && $p['status'] === 'published'));
$base = nl_base_url();
$query = $filter['query'] . ($page > 1 ? ($filter['query'] !== '' ? '&' : '') . 'page=' . $page : '');
$canonical = $base . '/' . ($single ? '?id=' . rawurlencode($id) : ($query !== '' ? '?' . $query : ''));
$title = $single ? nl_post_title($post) : ($filter['label'] !== '' ? $filter['label'] . '｜' . $s['title'] : $s['title']);
$desc = $single ? (nl_excerpt($post) ?: $s['description']) : $s['description'];
$ogImage = ($og = nl_load_media($s['og_image'])) ? $base . '/' . nl_media_url($og) : $base . '/viewer/log-og.png?v=' . nm_asset_version(__DIR__ . '/viewer/log-og.png');
if ($single) {
    // Article images take priority; text/manga-only posts use the shared image.
    preg_match_all('/\[Image:([a-f0-9]{16})\]/', $post['body'], $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if ($m = nl_load_media($match[1])) { $ogImage = $base . '/' . nl_media_url($m); break; }
    }
}
$indexable = $s['public'] && !$single && $page === 1 && ($filter['query'] === '' || $filter['category'] !== '') && count($all) > 0;
$robots = $indexable ? 'index,follow' : 'noindex,follow';
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: ' . ($owner ? 'private, no-store' : 'no-cache'));
header('X-Robots-Tag: ' . $robots);
$breadcrumb = $single ? json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => $s['title'], 'item' => $base . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $canonical],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '';
$jsonHash = $breadcrumb !== '' ? " 'sha256-" . base64_encode(hash('sha256', $breadcrumb, true)) . "'" : '';
// The existing viewers set styles dynamically and use data/blob placeholders.
// Embeds: frames only for the known services; their scripts are loaded by viewer/log-embed.js.
header("Content-Security-Policy: default-src 'self'; script-src 'self' " . nl_embed_script_src() . $jsonHash . "; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:" . nl_sidebar_image_origins() . "; frame-src " . nl_embed_frame_src() . "; media-src 'self' https:; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
function nl_public_asset(string $file): string { return h($file . '?v=' . nm_asset_version(__DIR__ . '/' . $file)); }
?><!DOCTYPE html>
<html lang="ja" data-log-theme="<?= h($s['theme']) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($single ? $title . '｜' . $s['title'] : $title) ?></title>
<meta name="description" content="<?= h($desc) ?>"><meta name="robots" content="<?= $robots ?>">
<link rel="canonical" href="<?= h($canonical) ?>"><meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:type" content="<?= $single ? 'article' : 'website' ?>"><meta property="og:site_name" content="<?= h($s['title']) ?>">
<meta property="og:title" content="<?= h($title) ?>"><meta property="og:description" content="<?= h($desc) ?>"><meta property="og:image" content="<?= h($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= h($title) ?>"><meta name="twitter:description" content="<?= h($desc) ?>"><meta name="twitter:image" content="<?= h($ogImage) ?>">
<link rel="icon" href="<?= ($icon = nl_load_media($s['icon'])) ? h(nl_media_url($icon, true)) : nl_public_asset('viewer/favicon.svg') ?>"><link rel="stylesheet" href="<?= nl_public_asset('viewer/log.css') ?>">
<link rel="stylesheet" href="<?= nl_public_asset('viewer/NagiSwipe-main.css') ?>">
<script src="<?= nl_public_asset('viewer/NagiSwipe-main.js') ?>" defer></script>
<script src="<?= nl_public_asset('viewer/NagiManga.js') ?>" defer></script>
<script src="<?= nl_public_asset('viewer/log-menu.js') ?>" defer></script>
<script src="<?= nl_public_asset('viewer/log-mail.js') ?>" defer></script>
<script src="<?= nl_public_asset('viewer/log-embed.js') ?>" defer></script>
<?php if (!$single && $s['pager'] === 'more'): ?><script src="<?= nl_public_asset('viewer/log-pager.js') ?>" defer></script><?php endif; ?>
<?php if ($owner): ?><script src="<?= nl_public_asset('admin/log-editor.js') ?>" defer></script><?php endif; ?>
<?php if ($breadcrumb !== ''): ?><script type="application/ld+json"><?= $breadcrumb ?></script><?php endif; ?>
</head><body class="log-site"><header class="log-site-header"><a href="./"><?= nl_icon_html($s, '', 'log-site-avatar') ?><span><?= h($s['title']) ?></span></a><p><?= h($s['description']) ?></p></header>
<button class="log-menu-toggle" type="button" aria-controls="log-sidebar" aria-expanded="false" aria-label="メニューを開く"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M5 12h14M5 17h14"/></svg><span class="log-menu-label" aria-hidden="true">MENU</span></button>
<div class="log-menu-backdrop" hidden></div><div class="log-site-grid"><main class="log-site-main" id="log-main">
<?php if ($single): ?><nav aria-label="Breadcrumb" class="log-breadcrumb"><ol><li><a href="./"><?= h($s['title']) ?></a></li><li><?= h($title) ?></li></ol></nav><?php else: ?><h1 class="sr-only"><?= h($s['title']) ?></h1><?php endif; ?>
<?php if (!$single && $filter['label'] !== ''): ?><div class="log-archive-head"><h2 class="log-archive-title"><?= h($filter['label']) ?></h2><span class="log-archive-count"><?= count($all) ?>件</span><a class="log-all-chip" href="./"><?= nl_all_icon() ?><span>すべての投稿</span><span class="log-all-count"><?= count($summaries) ?></span></a></div><?php endif; ?>
<?php if ($owner): ?><?php if (!$s['public']): ?><p class="log-private-note">自分専用Memo · 記事とLOGの画像はログイン時だけ表示されます。</p><?php endif; ?><div class="log-compose-slot" data-compose-slot><?= nl_editor(null, true) ?></div><?php endif; ?>
<?php foreach ($posts as $p): ?><article class="log-post"><div class="log-post-meta"><?= nl_icon_html($s) ?><strong><?= h($s['name']) ?></strong><a href="./?id=<?= h($p['id']) ?>"><time datetime="<?= h(nl_date($p['created'], 'c')) ?>" title="<?= h(nl_date($p['created'])) ?>"><?= h(nl_date($p['created'], 'Y/m/d')) ?></time></a><?php if ($owner): ?><a class="log-edit-link" href="admin/index.php?p=log_edit&id=<?= h($p['id']) ?>"><?= nl_ui_icon() ?><span>編集</span></a><?php endif; ?></div>
<?php if ($single): ?><h1><?= h(nl_post_title($p, 0)) ?></h1><?php else: ?><h2><a href="./?id=<?= h($p['id']) ?>"><?= h(nl_post_title($p, 0)) ?></a></h2><?php endif; ?>
<?= nl_render_body($p) ?><?= nl_category_links($p) ?></article><?php endforeach; ?>
<?php if (!$posts): ?><p class="log-empty"><?= $filter['search'] !== '' ? '見つかりませんでした。言葉を短くするか、別の言葉で探してみてください。' : ($filter['tag'] !== '' || $filter['category'] !== '' ? 'この分類の記録はありません。' : ($filter['query'] !== '' ? 'この日の記録はありません。' : 'まだ記録はありません。')) ?></p><?php endif; ?>
<?= $single ? ($s['post_nav'] ? nl_post_nav($summaries, $post) : '') : nl_pager($s, $filter['query'], $page, count($all)) ?>
</main><?= nl_sidebar($summaries, $filter, $s, $owner) ?></div><?php if ($s['show_footer'] && $s['footer_text'] !== ''): ?><footer class="log-site-footer"><?= h($s['footer_text']) ?></footer><?php endif; ?></body></html>
