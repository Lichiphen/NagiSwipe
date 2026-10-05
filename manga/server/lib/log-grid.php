<?php
/* LOG tile list, post thumbnails and related posts. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/** How the public lists show posts: the whole post (mini blog) or tiles with picture, date and title. */
const NL_LAYOUTS = ['stream' => 'ミニブログ（記事を全文で並べる）', 'grid' => 'タイル（サムネイル・日付・タイトル）'];
const NL_RELATED_BY = ['both' => 'カテゴリとハッシュタグ', 'category' => 'カテゴリだけ', 'tag' => 'ハッシュタグだけ'];
const NL_RELATED_ORDER = ['random' => 'ランダム', 'updated' => '更新が新しい順'];
const NL_RELATED_SHOWN = 6;
/** Random mode sends this many candidates; the page script shows six of them. */
const NL_RELATED_POOL = 18;
/** YouTube serves video thumbnails from here; allowed in the page's img-src. */
const NL_THUMB_ORIGINS = ['https://i.ytimg.com'];

/**
 * The first picture of a post, in body order, kept in the summary index as a short reference:
 * m:<LOG image> / w:<manga work> / y:<YouTube video> / c:<blog card>. '' when the post has none.
 */
function nl_post_thumb_ref(array $p): string
{
    $body = str_replace("\r", '', (string)($p['body'] ?? ''));
    preg_match_all('/\[Image:([a-f0-9]{16})\]|\[Manga[^\]\r\n]{1,230}\]|' . NL_EMBED_PATTERN . '/iu', $body, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $token = $hit[0];
        if (($hit[1] ?? '') !== '') { if (nl_load_media($hit[1])) return 'm:' . $hit[1]; continue; }
        if (str_starts_with($token, '[Manga')) { $id = $p['manga'][$token] ?? ''; if (is_string($id) && nm_valid_id($id)) return 'w:' . $id; continue; }
        $url = trim($token);
        $e = nl_embed_detect($url);
        if ($e) { if ($e['type'] === 'youtube' && isset($e['id'])) return 'y:' . $e['id']; continue; }
        $c = filter_var($url, FILTER_VALIDATE_URL) ? nl_card_load($url) : null;
        if ($c && !empty($c['ok']) && is_array($c['image'] ?? null)) return 'c:' . nl_card_key($url);
    }
    return '';
}

/** <img> for a thumbnail reference, or null when it no longer resolves (the caller shows a placeholder). */
function nl_thumb_img(string $ref, bool $large = false, string $loading = 'lazy'): ?string
{
    [$kind, $id] = array_pad(explode(':', $ref, 2), 2, '');
    $src = ''; $w = 0; $h = 0;
    if ($kind === 'm' && ($m = nl_load_media($id))) {
        $src = nl_media_url($m, !$large); $w = (int)$m['w']; $h = (int)$m['h'];
    } elseif ($kind === 'w' && nm_valid_id($id) && ($work = nm_load_work($id)) && !empty($work['pages']) && empty($work['password_hash']) && nm_work_page_public($work)) {
        $src = 'read.php?a=o&id=' . $work['id']; $w = 1200; $h = 630;
    } elseif ($kind === 'y' && preg_match('/\A[-\w]{6,32}\z/', $id)) {
        // hqdefault is 4:3 with bars; the 16:9 frame crops them away.
        $src = 'https://i.ytimg.com/vi/' . $id . '/' . ($large ? 'hqdefault' : 'mqdefault') . '.jpg'; $w = $large ? 480 : 320; $h = $large ? 360 : 180;
    } elseif ($kind === 'c' && preg_match('/\A[a-f0-9]{20}\z/', $id)) {
        $c = nl_read_record(nl_card_dir($id) . '/card.php');
        if (is_array($c) && preg_match(NM_PAGE_PATTERN, (string)($c['image']['f'] ?? ''))) { $src = './?card=' . $id . '&v=' . (int)$c['fetched']; $w = (int)$c['image']['w']; $h = (int)$c['image']['h']; }
    }
    if ($src === '') return null;
    $load = $loading === 'eager' ? 'fetchpriority="high"' : 'loading="lazy"';
    return '<img src="' . h($src) . '" alt="" width="' . max(1, $w) . '" height="' . max(1, $h) . '" ' . $load . ' decoding="async"' . ($kind === 'y' ? ' referrerpolicy="no-referrer"' : '') . '>';
}

/** Tile date, e.g. 2026.10.05. */
function nl_tile_date(int $time): string
{
    return '<time datetime="' . h(nl_date($time, 'c')) . '">' . h(nl_date($time, 'Y.m.d')) . '</time>';
}
/** One tile: picture, date and title. A post without a picture gets a soft panel with its first letter. */
function nl_tile(array $summary, bool $featured = false, string $loading = 'lazy', string $tag = 'li', string $extra = ''): string
{
    $title = (string)($summary['title'] ?? '') !== '' ? (string)$summary['title'] : '画像の記録';
    $img = nl_thumb_img((string)($summary['thumb'] ?? ''), $featured, $loading);
    $thumb = $img ?? '<span class="log-tile-blank" aria-hidden="true">' . h(mb_substr(trim($title), 0, 1)) . '</span>';
    return '<' . $tag . ' class="log-tile' . ($featured ? ' is-featured' : '') . '"' . $extra . '><a href="./?id=' . h($summary['id']) . '"><span class="log-tile-thumb">' . $thumb . ($featured ? '<span class="log-tile-badge">LATEST</span>' : '') . '</span>'
        . '<span class="log-tile-body">' . nl_tile_date((int)$summary['created']) . '<span class="log-tile-title">' . h($title) . '</span></span></a></' . $tag . '>';
}
/** The tile list of a public page. The newest post on the top page opens larger. */
function nl_tiles(array $summaries, bool $feature): string
{
    $out = '';
    foreach (array_values($summaries) as $i => $p) $out .= nl_tile($p, $feature && $i === 0, $i < 3 ? 'eager' : 'lazy');
    return $out !== '' ? '<ol class="log-tiles">' . $out . '</ol>' : '';
}

/**
 * Related posts below a post: the posts that share its categories and/or hashtags.
 * "updated" lists the six most recently updated. "random" sends a pool (the most related, then the newest)
 * and viewer/log-related.js picks six, so the cached page stays one page for everyone.
 */
function nl_related_posts(array $summaries, array $post, array $s): array
{
    $cats = $s['related_by'] !== 'tag' ? array_flip($post['categories'] ?? []) : [];
    $tags = $s['related_by'] !== 'category' ? array_flip(nl_hashtags((string)$post['body'])) : [];
    if (!$cats && !$tags) return [];
    $hits = [];
    foreach ($summaries as $p) {
        if ($p['id'] === $post['id']) continue;
        $score = 0;
        foreach ($p['categories'] ?? [] as $id) if (isset($cats[$id])) $score += 2;
        foreach ($p['hashtags'] ?? [] as $tag) if (isset($tags[$tag])) $score += 1;
        if ($score > 0) $hits[] = [$score, $p];
    }
    if ($s['related_order'] === 'updated') {
        usort($hits, static fn($a, $b) => ($b[1]['updated'] <=> $a[1]['updated']) ?: strnatcmp($b[1]['id'], $a[1]['id']));
        return array_column(array_slice($hits, 0, NL_RELATED_SHOWN), 1);
    }
    usort($hits, static fn($a, $b) => ($b[0] <=> $a[0]) ?: ($b[1]['updated'] <=> $a[1]['updated']));
    return array_column(array_slice($hits, 0, NL_RELATED_POOL), 1);
}
function nl_related_html(array $summaries, array $post, array $s): string
{
    if (!$s['related']) return '';
    $posts = nl_related_posts($summaries, $post, $s);
    if (!$posts) return '';
    $random = $s['related_order'] === 'random' && count($posts) > NL_RELATED_SHOWN;
    $items = '';
    foreach ($posts as $i => $p) $items .= nl_tile($p, false, 'lazy', 'li', $i >= NL_RELATED_SHOWN ? ' hidden' : '');
    return '<section class="log-related" aria-labelledby="log-related-title"' . ($random ? ' data-related-random' : '') . '><h2 id="log-related-title"><span>関連記事</span></h2><ul class="log-tiles log-related-list">' . $items . '</ul></section>';
}
