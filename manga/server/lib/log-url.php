<?php
/*
 * Clean URLs for posts and fixed pages: ./20261014123456 and ./privacy instead of ./?id= and ./?pg=.
 * MIT License (c) 2026 Lichiphen.
 * The shipped .htaccess rewrites them to index.php and marks every request with NL_PRETTY. Without that mark
 * (nginx, a host that ignores .htaccess) the LOG keeps writing ./?id= and ./?pg=, which always work.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/** Folder and file names next to the LOG: a fixed page with one of these URL names keeps ./?pg=. */
const NL_URL_RESERVED = ['admin', 'viewer', 'lib', 'data', 'plugins', 'index', 'log', 'read', 'like', 'embed', '404'];

function nl_pretty(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    foreach (['NL_PRETTY', 'REDIRECT_NL_PRETTY', 'REDIRECT_REDIRECT_NL_PRETTY'] as $key) {
        if ((string)($_SERVER[$key] ?? getenv($key)) === '1') return $on = true;
    }
    return $on = false;
}
/** A post's address after the LOG's folder: "20261014123456", or "?id=20261014123456". */
function nl_post_path(string $id): string
{
    return nl_pretty() ? rawurlencode($id) : '?id=' . rawurlencode($id);
}
/** A post's link from a page in the LOG's folder ('./'), or from the admin ('../'). */
function nl_post_url(string $id, string $base = './'): string { return $base . nl_post_path($id); }
function nl_post_abs(string $id): string { return nl_base_url() . '/' . nl_post_path($id); }
/** A fixed page's URL name can stand alone unless it is a folder of the LOG or looks like a post. */
function nl_slug_free(string $slug): bool
{
    return !in_array($slug, NL_URL_RESERVED, true) && !nl_valid_post($slug);
}
function nl_fixed_path(string $slug): string
{
    return nl_pretty() && nl_slug_free($slug) ? rawurlencode($slug) : '?pg=' . rawurlencode($slug);
}
/**
 * The last part of the requested path, decoded: "20261014123456" for a clean post URL,
 * '' for the folder itself or a trailing slash, "index.php" or "log.php" for those files.
 */
function nl_request_leaf(): string
{
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
    return substr($path, strrpos($path, '/') + 1);
}
/**
 * Old and odd addresses of a post or fixed page (./?id=…, ./?pg=…, log.php?id=…, a trailing slash) move to the clean one for good.
 * Only GET and HEAD, and only while clean URLs work on this server.
 */
function nl_clean_redirect(string $path): void
{
    if (!nl_pretty() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
    if (str_starts_with($path, '?') || nl_request_leaf() === rawurldecode($path)) return;
    // The lock form's answer travels in the query; keep it.
    $keep = isset($_GET['locked']) && is_string($_GET['locked']) && preg_match('/\A[a-z]{1,10}\z/', $_GET['locked']) ? '?locked=' . $_GET['locked'] : '';
    header('Location: ' . nl_base_url() . '/' . $path . $keep, true, 301);
    header('Cache-Control: no-cache');
    exit;
}
