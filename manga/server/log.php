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
$file = isset($_GET['media']) || isset($_GET['card']) || isset($_GET['ogimage']);
nm_lscache_header(!$owner && $s['public'] && !isset($_COOKIE['nm_admin']) && !$file, !$file);
if (!in_array($method, ['GET', 'HEAD'], true)) nm_not_found();
// Turned away from search engines: images say so too (image search).
if (!$s['search_engines'] && $file) header('X-Robots-Tag: noindex, nofollow');
if (isset($_GET['card'])) nl_serve_card(nm_str($_GET, 'card', 20));
if (isset($_GET['ogimage'])) nl_serve_og(nm_str($_GET, 'ogimage', 24));
if (isset($_GET['media'])) nl_serve_media(nm_str($_GET, 'media', 16), isset($_GET['thumb']), $owner);
$id = nm_str($_GET, 'id', 24);
$single = isset($_GET['id']);
$post = $single ? nl_load_post($id) : null;
if ($single && (!$post || $post['status'] !== 'published')) nm_not_found();
// A fixed page (./?pg=<URL name>); the owner also sees drafts.
$fixed = !$single && isset($_GET['pg']) ? nl_find_page(nm_str($_GET, 'pg', 40)) : null;
if (!$single && isset($_GET['pg']) && (!$fixed || ($fixed['status'] !== 'published' && !$owner))) nm_not_found();
$summaries = nl_post_summaries();
if (!$single && !$fixed && isset($_GET['feed'])) nl_serve_feed($s, $summaries);
if (!$single && !$fixed && isset($_GET['sitemap'])) nl_serve_sitemap($s, $summaries);
$grid = $s['layout'] === 'grid';
$filter = nl_archive_filter($single ? ['month' => nl_date($post['created'], 'Y-m')] : ($fixed ? [] : $_GET));
$all = $single ? [$post] : ($fixed ? [] : array_values(array_filter($summaries, static function ($p) use ($filter) {
    if ($filter['category'] !== '') return in_array($filter['category'], $p['categories'] ?? [], true);
    if ($filter['tag'] !== '') return in_array($filter['tag'], $p['hashtags'] ?? [], true);
    if ($filter['search'] !== '') return true;
    return $filter['query'] === '' || nl_date($p['created'], $filter['date'] !== '' ? 'Y-m-d' : 'Y-m') === ($filter['date'] ?: $filter['month']);
})));
if ($filter['search'] !== '') $all = nl_search_posts($all, $filter['terms']);
$page = max(1, min(100000, (int)nm_str($_GET, 'page', 6)));
$perPage = $s['posts_per_page'];
// A page past the end (old bookmark, typed URL) is not an empty list.
if (!$single && $all && ($page - 1) * $perPage >= count($all)) nm_not_found();
$pageSummaries = $single || $fixed ? [] : array_slice($all, ($page - 1) * $perPage, $perPage);
// Tiles need only the summary index; the mini blog reads each post on the page.
$posts = $single ? [$post] : ($fixed ? [] : ($grid ? $pageSummaries : array_values(array_filter(array_map(static fn($s) => nl_load_post($s['id']), $pageSummaries), static fn($p) => $p && $p['status'] === 'published'))));
$base = nl_base_url();
$query = $filter['query'] . ($page > 1 ? ($filter['query'] !== '' ? '&' : '') . 'page=' . $page : '');
$canonical = $base . '/' . ($single ? '?id=' . rawurlencode($id) : ($fixed ? '?pg=' . rawurlencode($fixed['slug']) : ($query !== '' ? '?' . $query : '')));
$title = $single ? nl_post_title($post) : ($fixed ? $fixed['title'] : ($filter['label'] !== '' ? $filter['label'] . '｜' . $s['title'] : $s['title']));
$rating = $single ? nl_post_rating($post) : '';
$desc = $single ? (nl_veil_description($post) ?: nl_excerpt($post) ?: $s['description']) : ($fixed ? (nl_page_excerpt($fixed) ?: $s['description']) : $s['description']);
// Share cards need a bitmap: an SVG is never used as og:image.
[$ogImage, $ogWidth, $ogHeight] = ($og = nl_load_media($s['og_image'])) && !nl_is_svg($og) ? [nl_public_url(nl_media_url($og)), (int)$og['w'], (int)$og['h']] : [$base . '/viewer/log-og.png?v=' . nm_asset_version(__DIR__ . '/viewer/log-og.png'), 0, 0];
// A post shares its first picture without a warning; with only warned pictures, a blurred copy with the rating's label
// (lib/log-og.php). Text and manga only posts use the shared image.
if ($single && ($postOg = nl_og_image($post))) [$ogImage, $ogWidth, $ogHeight] = $postOg;
$robots = $fixed ? ($s['search_engines'] && $fixed['status'] === 'published' ? 'index,follow' : 'noindex,nofollow') : nl_robots($s, $single, $filter, $page, count($all));
// R-18 posts stay out of search engines.
if ($rating === 'r18' && $robots === 'index,follow') $robots = 'noindex,follow';
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: ' . ($owner ? 'private, no-store' : 'no-cache'));
header('X-Robots-Tag: ' . $robots);
// One trail for the visible breadcrumb and the BreadcrumbList JSON-LD (allowed in the CSP by its hash).
[$breadcrumbHtml, $breadcrumb] = nl_breadcrumb($fixed ? [[nl_crumb_home($s), ''], [$fixed['title'], null]] : nl_breadcrumb_trail($s, $filter, $page, $single ? $post : null), $canonical);
$jsonHash = $breadcrumb !== '' ? " 'sha256-" . base64_encode(hash('sha256', $breadcrumb, true)) . "'" : '';
function nl_public_asset(string $file): string { return h($file . '?v=' . nm_asset_version(__DIR__ . '/' . $file)); }
// The top page keeps the open editor; other pages open it from the floating button.
$index = !$single && !$fixed && $filter['query'] === '' && $page === 1;
$postsHtml = '';
if ($fixed) $postsHtml = '<article class="log-post log-page">' . ($owner ? '<div class="log-post-meta">' . ($fixed['status'] !== 'published' ? '<span class="badge">下書き（ログイン中だけ表示）</span>' : '') . '<a class="log-edit-link" href="admin/index.php?p=log_page&amp;id=' . h($fixed['id']) . '">' . nl_ui_icon() . '<span>編集</span></a></div>' : '')
    . '<h1>' . h($fixed['title']) . '</h1><div class="log-page-body">' . nl_markdown($fixed['body']) . '</div></article>';
elseif ($grid && !$single) $postsHtml = nl_tiles($posts, $s);
else foreach ($posts as $p) {
    $postsHtml .= '<article class="log-post">' . ($single ? '' : nl_new_badge($p, $s, 'log-post-new')) . '<div class="log-post-meta">' . nl_icon_html($s) . '<strong>' . h($s['name']) . '</strong><a href="./?id=' . h($p['id']) . '"><time datetime="' . h(nl_date($p['created'], 'c')) . '" title="' . h(nl_date($p['created'])) . '">' . h(nl_date($p['created'], 'Y/m/d')) . '</time></a>'
        . ($owner ? '<a class="log-edit-link" href="admin/index.php?p=log_edit&id=' . h($p['id']) . '">' . nl_ui_icon() . '<span>編集</span></a>'
            . '<form class="log-delete-form" method="post" action="admin/index.php" data-log-delete><input type="hidden" name="csrf" value="' . h(nm_csrf_token()) . '"><input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($p['id']) . '"><input type="hidden" name="revision" value="' . (int)$p['revision'] . '"><input type="hidden" name="back" value="public">'
            . '<button class="log-delete-link" type="submit" title="この記事を削除">' . nl_ui_icon('delete') . '<span>削除</span></button></form>' : '') . "</div>\n"
        . ($single ? '<h1>' . h(nl_post_title($p, 0)) . '</h1>' : '<h2><a href="./?id=' . h($p['id']) . '">' . h(nl_post_title($p, 0)) . '</a></h2>')
        . nl_render_body($p) . nl_category_links($p) . ($s['public'] ? nl_share_row($p) : '') . '</article>';
}
$relatedHtml = $single && $s['public'] ? nl_related_html($summaries, $post, $s) : '';
$sidebarHtml = nl_sidebar($summaries, $filter, $s, $owner);
// The page shown, for the menus' current link.
$here = ['pg' => $fixed['slug'] ?? null, 'category' => $filter['category'] !== '' ? $filter['category'] : null, 'tag' => $filter['tag'] !== '' ? $filter['tag'] : null, 'id' => $single ? $post['id'] : null, 'top' => !$single && !$fixed && $filter['query'] === ''];
$topmenuHtml = nl_topmenu_html($here);
// The existing viewers set styles dynamically and use data/blob placeholders.
// Embeds: frames only for the known services; their scripts are loaded by viewer/log-embed.js. A fixed page adds the hosts of its outside pictures.
header("Content-Security-Policy: default-src 'self'; script-src 'self' " . nl_embed_script_src() . $jsonHash . "; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: " . implode(' ', NL_THUMB_ORIGINS) . nl_sidebar_image_origins() . ($fixed ? nl_md_image_origins($postsHtml) : '') . "; frame-src " . nl_embed_frame_src() . "; media-src 'self' https:; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
// The site title (logo or icon and name) is the page's h1 on the lists; a post or a fixed page has its own title as h1.
$logo = nl_load_media($s['logo']);
$brand = $logo ? '<img class="log-site-logo" src="' . h(nl_media_url($logo)) . '" width="' . (int)$logo['w'] . '" height="' . (int)$logo['h'] . '" alt="' . h($s['title']) . '">'
    : nl_icon_html($s, '', 'log-site-avatar') . '<span>' . h($s['title']) . '</span>';
$titleTag = !$single && !$fixed ? 'h1' : 'p';
// The image viewer and the manga reader load only where something uses them. The owner keeps both (editor previews),
// and so does a "show more" list with pages still to come, which may bring images or manga in later.
$more = !$single && !$fixed && $s['pager'] === 'more' && $page * $perPage < count($all);
$swipe = $owner || $more || nl_has_image_links($postsHtml . $sidebarHtml);
$shareRows = $s['public'] && $posts && (!$grid || $single);
$manga = $owner || $more || str_contains($postsHtml, 'data-nagimanga=');
?><!DOCTYPE html>
<html lang="ja" data-log-theme="<?= h($s['theme']) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($single || $fixed ? $title . '｜' . $s['title'] : $title) ?></title>
<meta name="description" content="<?= h($desc) ?>"><meta name="robots" content="<?= $robots ?>">
<link rel="canonical" href="<?= h($canonical) ?>"><meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:type" content="<?= $single ? 'article' : 'website' ?>"><meta property="og:site_name" content="<?= h($s['title']) ?>">
<meta property="og:title" content="<?= h($title) ?>"><meta property="og:description" content="<?= h($desc) ?>"><meta property="og:image" content="<?= h($ogImage) ?>"><?php if ($ogWidth > 0): ?><meta property="og:image:width" content="<?= $ogWidth ?>"><meta property="og:image:height" content="<?= $ogHeight ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= h($title) ?>"><meta name="twitter:description" content="<?= h($desc) ?>"><meta name="twitter:image" content="<?= h($ogImage) ?>">
<link rel="icon" href="<?= ($icon = nl_load_media($s['icon'])) ? h(nl_media_url($icon, true)) : nl_public_asset('viewer/favicon.svg') ?>"><link rel="stylesheet" href="<?= nl_public_asset('viewer/log.css') ?>">
<?php if ($swipe): ?><link rel="stylesheet" href="<?= nl_public_asset('viewer/NagiSwipe-main.css') ?>">
<script src="<?= nl_public_asset('viewer/NagiSwipe-main.js') ?>" defer></script><?php endif; ?>
<?php if ($manga): ?><script src="<?= nl_public_asset('viewer/NagiManga.js') ?>" defer></script><?php endif; ?>
<script src="<?= nl_public_asset('viewer/log-menu.js') ?>" defer></script>
<script src="<?= nl_public_asset('viewer/log-loading.js') ?>" defer></script>
<?php if ($topmenuHtml !== ''): ?><script src="<?= nl_public_asset('viewer/log-topmenu.js') ?>" defer></script><?php endif; ?>
<script src="<?= nl_public_asset('viewer/log-mail.js') ?>" defer></script>
<?php if ($shareRows): ?><script src="<?= nl_public_asset('viewer/log-share.js') ?>" defer></script><?php endif; ?>
<?php if ($shareRows && $s['likes']): ?><script src="<?= nl_public_asset('viewer/log-like.js') ?>" defer></script><?php endif; ?>
<?php if (str_contains($relatedHtml, 'data-related-random')): ?><script src="<?= nl_public_asset('viewer/log-related.js') ?>" defer></script><?php endif; ?>
<?php if ($s['public']): ?><link rel="alternate" type="application/rss+xml" title="<?= h($s['title']) ?>" href="<?= h(nl_feed_url()) ?>"><?php if (!$single && ($filter['category'] !== '' || $filter['tag'] !== '')): ?><link rel="alternate" type="application/rss+xml" title="<?= h($title) ?>" href="<?= h(nl_feed_url($filter['query'])) ?>"><?php endif; ?><?php endif; ?>
<script src="<?= nl_public_asset('viewer/log-embed.js') ?>" defer></script>
<?php if ($owner || $more || str_contains($postsHtml . $relatedHtml, 'data-veil=')): ?><script src="<?= nl_public_asset('viewer/log-veil.js') ?>" defer></script><?php endif; ?>
<?php if (str_contains($postsHtml, 'data-new-until=')): ?><script src="<?= nl_public_asset('viewer/log-new.js') ?>" defer></script><?php endif; ?>
<?php if (!$single && !$fixed && $s['pager'] === 'more'): ?><script src="<?= nl_public_asset('viewer/log-pager.js') ?>" defer></script><?php endif; ?>
<?php if ($owner): ?><script src="<?= nl_public_asset('admin/log-editor.js') ?>" defer></script><?php endif; ?>
<?php if ($breadcrumb !== ''): ?><script type="application/ld+json"><?= $breadcrumb ?></script><?php endif; ?>
</head><body class="log-site<?= $fixed ? ' log-layout-' . h($fixed['layout']) : '' ?>"><header class="log-site-header"><<?= $titleTag ?> class="log-site-title<?= $logo ? ' has-logo' : '' ?>"><a href="./"<?= $logo ? '' : nl_title_fit($s['title']) ?>><?= $brand ?></a></<?= $titleTag ?>><?php if ($s['show_description'] && $s['description'] !== ''): ?><p class="log-site-description"><?= h($s['description']) ?></p><?php endif; ?></header>
<?= $topmenuHtml ?><button class="log-menu-toggle" type="button" aria-controls="log-sidebar" aria-expanded="false" aria-label="メニューを開く"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M5 12h14M5 17h14"/></svg><span class="log-menu-label" aria-hidden="true">MENU</span></button>
<div class="log-menu-backdrop" hidden></div><div class="log-site-grid"><main class="log-site-main" id="log-main">
<?= $breadcrumbHtml ?>
<?php if (!$single && $filter['label'] !== ''): ?><div class="log-archive-head"><h2 class="log-archive-title"><?= h($filter['label']) ?></h2><span class="log-archive-count"><?= count($all) ?>件</span><a class="log-all-chip" href="./"><?= nl_all_icon() ?><span>すべての投稿</span><span class="log-all-count"><?= count($summaries) ?></span></a></div><?php endif; ?>
<?php if ($owner): ?><?php if (!$s['public']): ?><p class="log-private-note">自分専用Memo · 記事とLOGの画像はログイン時だけ表示されます。</p><?php endif; ?><div class="log-compose-slot<?= $index ? '' : ' log-compose-collapsed' ?>" data-compose-slot><?= nl_editor(null, true) ?></div><?php endif; ?>
<?= $postsHtml ?>
<?php if (!$posts && !$fixed): ?><p class="log-empty"><?= $filter['search'] !== '' ? '見つかりませんでした。言葉を短くするか、別の言葉で探してみてください。' : ($filter['tag'] !== '' || $filter['category'] !== '' ? 'この分類の記録はありません。' : ($filter['query'] !== '' ? 'この日の記録はありません。' : 'まだ記録はありません。')) ?></p><?php endif; ?>
<?= $single ? $relatedHtml . ($s['post_nav'] ? nl_post_nav($summaries, $post) : '') : ($fixed ? '' : nl_pager($s, $filter['query'], $page, count($all))) ?>
</main><?= $sidebarHtml ?></div><?= $shareRows ? nl_share_dialog() : '' ?><?= nl_footer_html($s, $here) ?></body></html>
