<?php
/* LOG search engine rules, RSS feed and sitemap. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_FEED_ITEMS = 20;

/**
 * What a public page tells search engines.
 * - Search engines turned away in the settings: every page noindex,nofollow.
 * - Tiles: the top page, categories (every page) and each post are indexed; hashtags, dates and search results are not.
 * - Mini blog: lists show whole posts, so only the first page of the top and of each category is indexed.
 */
function nl_robots(array $s, bool $single, array $filter, int $page, int $count): string
{
    if (!$s['search_engines']) return 'noindex,nofollow';
    if (!$s['public'] || $count === 0) return 'noindex,follow';
    $listing = $filter['query'] === '' || $filter['category'] !== '';
    if ($s['layout'] === 'grid') return $single || $listing ? 'index,follow' : 'noindex,follow';
    return !$single && $page === 1 && $listing ? 'index,follow' : 'noindex,follow';
}
function nl_feed_url(string $query = ''): string { return nl_base_url() . '/?feed=rss' . ($query !== '' ? '&' . $query : ''); }
function nl_sitemap_url(): string { return nl_base_url() . '/?sitemap=xml'; }
function nl_xml(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Feeds and the sitemap answer 304 while nothing in them changed (readers and crawlers poll often). */
function nl_xml_headers(string $type, int $last, string $tag): void
{
    $etag = '"' . substr(hash('sha256', $tag . '|' . $last . '|' . NM_VERSION), 0, 20) . '"';
    header('Content-Type: ' . $type . '; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    header('ETag: ' . $etag);
    if ($last > 0) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $last) . ' GMT');
    if (in_array($etag, array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))), true)) { http_response_code(304); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
}
/** RSS 2.0 of the newest posts: title, link, date and the description (never the whole post). Optional ?category= or ?tag=. */
function nl_serve_feed(array $s, array $summaries): never
{
    $filter = nl_archive_filter(array_intersect_key($_GET, ['category' => true, 'tag' => true]));
    $posts = array_values(array_filter($summaries, static fn($p) => $filter['category'] !== '' ? in_array($filter['category'], $p['categories'] ?? [], true)
        : ($filter['tag'] === '' || in_array($filter['tag'], $p['hashtags'] ?? [], true))));
    $posts = array_slice($posts, 0, NL_FEED_ITEMS);
    $last = (int)$s['updated'];
    foreach ($posts as $p) $last = max($last, (int)$p['updated']);
    header('X-Robots-Tag: noindex' . ($s['search_engines'] ? '' : ',nofollow'));
    nl_xml_headers('application/rss+xml', $last, 'feed|' . $filter['query'] . '|' . implode(',', array_column($posts, 'id')));
    $base = nl_base_url();
    $categories = nl_taxonomy()['categories'];
    $title = $s['title'] . ($filter['label'] !== '' ? '｜' . $filter['label'] : '');
    $items = '';
    foreach ($posts as $summary) {
        $p = nl_load_post($summary['id']);
        if (!$p || $p['status'] !== 'published') continue;
        $link = $base . '/?id=' . rawurlencode($p['id']);
        $cats = '';
        foreach ($p['categories'] ?? [] as $id) if (isset($categories[$id])) $cats .= '<category>' . nl_xml($categories[$id]) . '</category>';
        $desc = nl_veil_description($p) ?: nl_excerpt($p);
        $items .= '<item><title>' . nl_xml(nl_post_title($p, 0)) . '</title><link>' . nl_xml($link) . '</link><guid isPermaLink="true">' . nl_xml($link) . '</guid>'
            . '<pubDate>' . nl_date((int)$p['created'], DATE_RSS) . '</pubDate>' . $cats . ($desc !== '' ? '<description>' . nl_xml($desc) . '</description>' : '') . "</item>\n";
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . nl_xml($title) . '</title><link>' . nl_xml($base . '/' . ($filter['query'] !== '' ? '?' . $filter['query'] : '')) . '</link>'
        . '<atom:link href="' . nl_xml(nl_feed_url($filter['query'])) . '" rel="self" type="application/rss+xml"/>'
        . '<description>' . nl_xml($s['description']) . '</description><language>ja</language>'
        . ($last > 0 ? '<lastBuildDate>' . nl_date($last, DATE_RSS) . '</lastBuildDate>' : '') . "<generator>NagiLog</generator>\n" . $items . '</channel></rss>';
    exit;
}
/**
 * Sitemap of the pages search engines may index (the same rules as nl_robots): the top page, categories with posts,
 * and on tiles every post. Pages beyond the first are found through the links, so they are not listed.
 */
function nl_serve_sitemap(array $s, array $summaries): never
{
    if (!$s['search_engines']) nm_not_found();
    $base = nl_base_url();
    $last = (int)$s['updated']; $byCategory = [];
    foreach ($summaries as $p) {
        $last = max($last, (int)$p['updated']);
        foreach ($p['categories'] ?? [] as $id) $byCategory[$id] = max($byCategory[$id] ?? 0, (int)$p['updated']);
    }
    $grid = $s['layout'] === 'grid';
    header('X-Robots-Tag: noindex');
    nl_xml_headers('application/xml', $last, 'sitemap|' . $s['layout'] . '|' . count($summaries) . '|' . implode(',', array_column(array_filter($summaries, static fn($p) => ($p['rating'] ?? '') === 'r18'), 'id')));
    $url = static fn(string $loc, int $time, string $priority): string => '<url><loc>' . nl_xml($loc) . '</loc>' . ($time > 0 ? '<lastmod>' . nl_date($time, 'c') . '</lastmod>' : '') . '<priority>' . $priority . "</priority></url>\n";
    $out = $url($base . '/', $last, '1.0');
    foreach (nl_taxonomy()['categories'] as $id => $name) if (isset($byCategory[$id])) $out .= $url($base . '/?category=' . rawurlencode((string)$id), $byCategory[$id], '0.5');
    // R-18 posts answer noindex, so they are not listed.
    if ($grid) foreach ($summaries as $p) if (($p['rating'] ?? '') !== 'r18') $out .= $url($base . '/?id=' . rawurlencode($p['id']), (int)$p['updated'], '0.7');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $out . '</urlset>';
    exit;
}
