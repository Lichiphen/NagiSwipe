<?php
/* Resolve provider IDs only on save/preview; visitors use a bounded, verified cache. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_embed_key(array $e): string { return substr(hash('sha256', $e['type'] . ':' . $e['url']), 0, 20); }
function nl_embed_did(string $did): bool { return (bool)preg_match('/\Adid:(?:plc:[a-z0-9]{24}|web:[A-Za-z0-9._:%-]{3,240})\z/', $did); }
function nl_embed_cache_file(array $e): string { return nl_root() . '/embeds/' . nl_embed_key($e) . '.php'; }
function nl_embed_cache(array $e): ?array
{
    $c = nl_read_record(nl_embed_cache_file($e));
    return is_array($c) && ($c['url'] ?? '') === $e['url'] && ($c['type'] ?? '') === $e['type'] ? $c : null;
}

/** Never trust a saved response as HTML or as an arbitrary frame address. */
function nl_embed_resolved(array $e): ?array
{
    $c = nl_embed_cache($e);
    if (!$c || empty($c['ok']) || !is_array($c['data'] ?? null)) return null;
    $d = $c['data'];
    if ($e['type'] === 'bandcamp' && is_string($d['id'] ?? null) && preg_match('/\A\d{1,16}\z/', $d['id'])) return ['id' => $d['id']];
    if ($e['type'] === 'bluesky' && is_string($d['did'] ?? null) && nl_embed_did($d['did'])) return ['did' => $d['did']];
    if ($e['type'] === 'mastodon' && ($d['src'] ?? '') === $e['url'] . '/embed') return ['src' => $d['src']];
    return null;
}

/** Reuses the card fetcher's public-IP checks, TLS verification and size/time limits. Returns how many embeds became available. */
function nl_embeds_prepare(string $body, float $deadline): int
{
    $resolved = 0;
    preg_match_all('/' . NL_EMBED_PATTERN . '/i', str_replace("\r", '', $body), $lines);
    $seen = [];
    foreach ($lines[0] as $line) {
        $e = nl_embed_detect(trim($line));
        if (!$e || !in_array($e['type'], ['bandcamp', 'bluesky', 'mastodon'], true) || !isset($e['url'])) continue;
        if ($e['type'] === 'bluesky' && nl_embed_did($e['repo'])) continue;
        $key = nl_embed_key($e);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        if (count($seen) > NL_CARD_MAX_PER_SAVE || microtime(true) >= $deadline) break;
        $old = nl_embed_cache($e);
        if ($old && time() - (int)($old['fetched'] ?? 0) < (!empty($old['ok']) ? NL_CARD_TTL : NL_CARD_RETRY)) continue;
        try { $data = nl_embed_fetch($e, $deadline); } catch (Throwable) { $data = null; }
        $record = ['type' => $e['type'], 'url' => $e['url'], 'fetched' => time(), 'ok' => $data !== null, 'data' => $data];
        if ($data !== null && empty($old['ok'])) $resolved++;
        nm_with_lock('log-embeds', static function () use ($e, $record) {
            nm_ensure_dir(dirname(nl_embed_cache_file($e)));
            nl_write_record(nl_embed_cache_file($e), $record);
        });
    }
    return $resolved;
}

function nl_embed_fetch(array $e, float $deadline): ?array
{
    if ($e['type'] === 'bluesky') {
        $res = nl_http_get('https://bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=' . rawurlencode($e['repo']), 65536, 'application/json', $deadline, false);
        $d = $res ? json_decode($res['body'], true) : null;
        return is_array($d) && is_string($d['did'] ?? null) && nl_embed_did($d['did']) ? ['did' => $d['did']] : null;
    }
    if ($e['type'] === 'bandcamp') {
        $res = nl_http_get($e['url'], NL_CARD_HTML_MAX, 'text/html', $deadline, true);
        return $res ? nl_embed_bandcamp_parse($res['body'], $e['kind']) : null;
    }
    if ($e['type'] === 'mastodon') {
        $res = nl_http_get('https://' . $e['host'] . '/api/oembed?url=' . rawurlencode($e['url']), 131072, 'application/json', $deadline, false);
        $d = $res ? json_decode($res['body'], true) : null;
        return is_array($d) && is_string($d['html'] ?? null) ? nl_embed_mastodon_parse($d['html'], $e['url']) : null;
    }
    return null;
}

function nl_embed_dom(string $html): DOMDocument
{
    $doc = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return $doc;
}

function nl_embed_bandcamp_parse(string $html, string $kind): ?array
{
    foreach (nl_embed_dom($html)->getElementsByTagName('meta') as $meta) {
        if (strtolower($meta->getAttribute('name')) !== 'bc-page-properties') continue;
        $d = json_decode($meta->getAttribute('content'), true);
        if (!is_array($d) || ($d['item_type'] ?? '') !== ($kind === 'album' ? 'a' : 't')) return null;
        $id = $d['item_id'] ?? null;
        if ((is_int($id) || is_string($id)) && preg_match('/\A\d{1,16}\z/', (string)$id)) return ['id' => (string)$id];
    }
    return null;
}

function nl_embed_mastodon_parse(string $html, string $url): ?array
{
    $doc = nl_embed_dom($html); $expected = $url . '/embed';
    // Older Mastodon versions return an iframe; current versions return a blockquote.
    foreach ($doc->getElementsByTagName('iframe') as $frame) if (rtrim($frame->getAttribute('src'), '/') === $expected) return ['src' => $expected];
    foreach ($doc->getElementsByTagName('blockquote') as $quote) {
        if (in_array('mastodon-embed', explode(' ', $quote->getAttribute('class')), true) && rtrim($quote->getAttribute('data-embed-url'), '/') === $expected) return ['src' => $expected];
    }
    return null;
}
