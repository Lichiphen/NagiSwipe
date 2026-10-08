<?php
/*
 * Share images (OGP) of a post. MIT License (c) 2026 Lichiphen.
 * A post shares its first picture without a content warning. When every picture has one (or the whole post has),
 * the server makes a 1200x630 WebP once: that picture blurred beyond recognition, darkened, with the label of the
 * rating laid over it. The labels are drawn from SVG in advance (manga/dev/build_og_veil.py -> viewer/og-veil/*.png),
 * so GD needs no font. Made images are kept in data/log/og/ and named by everything they depend on.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_OG_W = 1200;
const NL_OG_H = 630;
/** Where the label card sits on the image (its PNG holds only the card and its shadow). */
const NL_OG_CARD = [230, 110];
/** Raise to make every blurred image again after changing how they are drawn. */
const NL_OG_VERSION = 1;

/** Absolute URL of a public path like "./?media=…" (without a "/./" in the middle). */
function nl_public_url(string $relative): string
{
    return nl_base_url() . '/' . (string)preg_replace('~\A\./~', '', $relative);
}
/**
 * What a post shares: ['media', picture] for its first picture without a warning (SVG is skipped: cards need a bitmap),
 * ['veil', picture, rating] when only pictures with a warning are left, or null for the shared image of the site.
 */
function nl_og_choice(array $p): ?array
{
    $own = nl_rating($p['rating'] ?? '');
    $veil = null;
    foreach (nl_media_refs((string)$p['body']) as $id) {
        $m = nl_load_media($id);
        if (!$m) continue;
        $rating = nl_rating_max($own, nl_media_rating($p, $m));
        // Video and audio share their cover when they have one; a covered card is made only from pictures.
        if (nl_is_av($m)) { if ($rating === '' && ($m['cover'] ?? '') !== '' && !str_ends_with((string)$m['cover'], '.svg')) return ['media', $m]; continue; }
        if ($rating === '') { if (!nl_is_svg($m)) return ['media', $m]; continue; }
        // A bitmap blurs into colors; an SVG falls back to a plain background, so prefer a bitmap.
        if ($veil === null || (nl_is_svg($veil[1]) && !nl_is_svg($m))) $veil = ['veil', $m, $rating];
    }
    return $veil;
}
function nl_og_label(string $rating): string { return dirname(__DIR__) . '/viewer/og-veil/' . $rating . '.png'; }
function nl_og_key(array $m, string $rating): string
{
    return substr(hash('sha256', implode('|', [NL_OG_VERSION, $m['id'], $m['revision'], $m['f'], $rating, nm_asset_version(nl_og_label($rating))])), 0, 16);
}
function nl_og_ext(): string { return function_exists('imagewebp') ? 'webp' : 'jpg'; }
/** The og:image of a post: [url, width, height], or null for the shared image. */
function nl_og_image(array $p): ?array
{
    $choice = nl_og_choice($p);
    if (!$choice) return null;
    if ($choice[0] === 'media') return [nl_public_url(nl_media_url($choice[1], false, false, nl_is_av($choice[1]))), (int)$choice[1]['w'], (int)$choice[1]['h']];
    if (!is_file(nl_og_label($choice[2]))) return null;
    return [nl_base_url() . '/?ogimage=' . rawurlencode($p['id']) . '&v=' . nl_og_key($choice[1], $choice[2]) . '&format=image.' . nl_og_ext(), NL_OG_W, NL_OG_H];
}
/** Draw the blurred image with its label; returns the file's bytes. */
function nl_og_render(array $m, string $rating): string
{
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('GD is missing');
    $canvas = imagecreatetruecolor(NL_OG_W, NL_OG_H);
    $src = null;
    // The thumbnail is plenty: nothing of the picture is left but its colors.
    $thumb = nl_media_dir($m['id']) . '/t_' . $m['f'];
    if (!nl_is_svg($m) && is_file($thumb)) $src = @imagecreatefromstring((string)file_get_contents($thumb)) ?: null;
    if ($src) {
        $sw = imagesx($src); $sh = imagesy($src);
        // Cover: the middle of the picture at 1200:630.
        $cw = $sw; $ch = (int)round($sw * NL_OG_H / NL_OG_W);
        if ($ch > $sh) { $ch = $sh; $cw = (int)round($sh * NL_OG_W / NL_OG_H); }
        $tiny = imagecreatetruecolor(32, 17);
        imagecopyresampled($tiny, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), 32, 17, max(1, $cw), max(1, $ch));
        imagedestroy($src);
        for ($i = 0; $i < 3; $i++) imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
        // Soft steps up, blurring at each size, so the colors flow instead of showing blocks.
        $from = $tiny;
        foreach ([[80, 42, 4], [240, 126, 8]] as [$w, $h, $passes]) {
            $step = imagecreatetruecolor($w, $h);
            imagecopyresampled($step, $from, 0, 0, 0, 0, $w, $h, imagesx($from), imagesy($from));
            for ($i = 0; $i < $passes; $i++) imagefilter($step, IMG_FILTER_GAUSSIAN_BLUR);
            imagedestroy($from); $from = $step;
        }
        imagecopyresampled($canvas, $from, 0, 0, 0, 0, NL_OG_W, NL_OG_H, 240, 126);
        imagedestroy($from);
    } else {
        // No bitmap (SVG): a quiet two-color background in the label's tone.
        [$top, $bottom] = ['sensitive' => [[86, 70, 48], [40, 34, 30]], 'r18' => [[92, 44, 62], [38, 24, 34]], 'r18g' => [[66, 48, 98], [30, 24, 44]]][$rating] ?? [[40, 56, 80], [20, 28, 40]];
        for ($y = 0; $y < NL_OG_H; $y++) {
            $k = $y / (NL_OG_H - 1);
            imageline($canvas, 0, $y, NL_OG_W - 1, $y, imagecolorallocate($canvas, ...array_map(static fn($a, $b) => (int)round($a + ($b - $a) * $k), $top, $bottom)));
        }
    }
    imagealphablending($canvas, true);
    // Darker toward the bottom (the shade the label's SVG leaves to the server).
    for ($y = 0; $y < NL_OG_H; $y++) {
        $opacity = 0.18 + 0.34 * $y / (NL_OG_H - 1);
        imageline($canvas, 0, $y, NL_OG_W - 1, $y, imagecolorallocatealpha($canvas, 11, 20, 36, 127 - (int)round($opacity * 127)));
    }
    $label = @imagecreatefrompng(nl_og_label($rating));
    if (!$label) throw new RuntimeException('label missing');
    imagealphablending($label, true);
    imagecopy($canvas, $label, NL_OG_CARD[0], NL_OG_CARD[1], 0, 0, imagesx($label), imagesy($label));
    imagedestroy($label);
    ob_start();
    $ok = nl_og_ext() === 'webp' ? imagewebp($canvas, null, 84) : imagejpeg($canvas, null, 86);
    $bytes = (string)ob_get_clean();
    imagedestroy($canvas);
    if (!$ok || $bytes === '') throw new RuntimeException('encode failed');
    return $bytes;
}
function nl_og_dir(): string { return nl_root() . '/og'; }
/** Remove the made images of these posts (deleted posts). */
function nl_og_forget(array $ids): void
{
    foreach ($ids as $id) if (nl_valid_post((string)$id)) foreach (glob(nl_og_dir() . '/' . $id . '-*') ?: [] as $file) @unlink($file);
}
/** ./?ogimage=<post id>: the blurred share image, made on the first request. */
function nl_serve_og(string $id): never
{
    if (!nl_valid_post($id) || !nl_settings()['public']) nm_not_found();
    nm_image_guard(nm_config());
    $p = nl_load_post($id);
    if (!$p || $p['status'] !== 'published') nm_not_found();
    $choice = nl_og_choice($p);
    if (!$choice || $choice[0] !== 'veil' || !is_file(nl_og_label($choice[2]))) nm_not_found();
    $key = nl_og_key($choice[1], $choice[2]);
    $file = nl_og_dir() . '/' . $id . '-' . $key . '.' . nl_og_ext();
    if (!is_file($file)) {
        nm_with_lock('log-og', static function () use ($file, $id, $choice) {
            if (is_file($file)) return;
            $bytes = nl_og_render($choice[1], $choice[2]);
            // Older versions of this post's image are no longer linked anywhere.
            nl_og_forget([$id]);
            nm_ensure_dir(dirname($file));
            $part = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($part, $bytes) !== strlen($bytes) || !@rename($part, $file)) { @unlink($part); throw new RuntimeException('write failed'); }
            @chmod($file, 0644);
        });
    }
    $etag = '"og-' . $key . '"';
    header('Content-Type: ' . (nl_og_ext() === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    // The URL carries the key, so a changed picture or label gets a new address.
    header('Cache-Control: public, max-age=2592000');
    header('ETag: ' . $etag);
    if (in_array($etag, array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))), true)) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') readfile($file);
    exit;
}
