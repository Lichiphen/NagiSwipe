<?php
/*
 * NagiManga image import
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Every uploaded file is decoded and re-encoded by GD. Whatever was hidden in
 * the original (PHP code, HTML, polyglot payloads, EXIF location data) does not
 * survive: only the pixels are written out again.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_ALLOWED_MIME = [
    'image/jpeg' => IMAGETYPE_JPEG,
    'image/png'  => IMAGETYPE_PNG,
    'image/gif'  => IMAGETYPE_GIF,
    'image/webp' => IMAGETYPE_WEBP,
    'image/bmp'  => IMAGETYPE_BMP,
    'image/x-ms-bmp' => IMAGETYPE_BMP,
    'image/avif' => IMAGETYPE_AVIF,
];

const NM_MAX_SIDE = 16000;          // px, either side
const NM_MAX_PIXELS = 50_000_000;   // decompression bomb guard
const NM_THUMB_W = 320;

function nm_image_support(): array
{
    return [
        'gd' => extension_loaded('gd'),
        'webp' => function_exists('imagewebp'),
        'finfo' => class_exists('finfo'),
    ];
}

/** Bytes PHP may still allocate (memory_limit - usage), or PHP_INT_MAX if unlimited. */
function nm_memory_available(): int
{
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') return PHP_INT_MAX;
    $n = (int)$limit;
    $unit = strtolower(substr($limit, -1));
    $n *= match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
    return max(0, $n - memory_get_usage(true));
}

/**
 * Import one uploaded file into $pagesDir.
 *
 * @return array{f:string,w:int,h:int}|string  page info, or an error message
 */
function nm_import_image(string $tmp, string $pagesDir, int $seq, int $quality, int $maxBytes, bool $withThumb = true): array|string
{
    $sup = nm_image_support();
    if (!$sup['gd'] || !$sup['finfo']) return 'サーバーの PHP に GD / fileinfo がありません';

    if (!is_file($tmp) || is_link($tmp)) return 'ファイルを受け取れませんでした';
    $size = filesize($tmp);
    if ($size === false || $size <= 0) return '空のファイルです';
    if ($size > $maxBytes) return 'ファイルが大きすぎます';

    // 1) Content sniffing, not the file name
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!is_string($mime) || !isset(NM_ALLOWED_MIME[$mime])) return '画像ではないか、対応していない形式です';

    // 2) The image header must agree with the sniffed type
    $info = @getimagesize($tmp);
    if (!$info || !isset($info[0], $info[1], $info[2])) return '画像として読めません';
    if ($info[2] !== NM_ALLOWED_MIME[$mime]) return '画像の形式が一致しません';
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1 || $w > NM_MAX_SIDE || $h > NM_MAX_SIDE || $w * $h > NM_MAX_PIXELS) {
        return '画像の縦横が大きすぎます';
    }

    // 3) Refuse before decoding if it cannot fit in memory (~5 bytes/px + margin)
    if ($w * $h * 5 * 1.8 > nm_memory_available()) return 'サーバーのメモリが足りません（画像を小さくしてください）';

    // 4) Decode
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmp) : false,
        IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($tmp) : false,
        default => false,
    };
    if (!$src) return '画像を読み込めませんでした';

    try {
        if ($info[2] === IMAGETYPE_JPEG) $src = nm_apply_exif_orientation($src, $tmp);
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);
        $w = imagesx($src);
        $h = imagesy($src);

        // 5) Re-encode into a fresh file with a name we choose
        nm_ensure_dir($pagesDir);
        $base = sprintf('p%04d_%s', $seq % 10000, bin2hex(random_bytes(4)));
        $ext = $sup['webp'] ? 'webp' : 'jpg';
        $file = "$base.$ext";
        $out = "$pagesDir/$file";
        $thumbOut = "$pagesDir/t_$base.$ext";

        if (!nm_encode($src, $out, $ext, $quality)) return '画像を保存できませんでした';

        if (!$withThumb) return ['f' => $file, 'w' => $w, 'h' => $h];

        // Small preview for the admin list and the page slider
        $tw = min(NM_THUMB_W, $w);
        $th = max(1, (int)round($h * $tw / $w));
        $thumb = imagecreatetruecolor($tw, $th);
        nm_prepare_canvas($thumb, $ext);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        nm_encode($thumb, $thumbOut, $ext, 80);
        imagedestroy($thumb);

        return ['f' => $file, 'w' => $w, 'h' => $h];
    } finally {
        imagedestroy($src);
    }
}

function nm_prepare_canvas(GdImage $im, string $ext): void
{
    if ($ext === 'webp') {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    } else {
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    }
}

function nm_encode(GdImage $im, string $path, string $ext, int $quality): bool
{
    $quality = max(40, min(100, $quality));
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if ($ext === 'webp') {
        imagesavealpha($im, true);
        $ok = imagewebp($im, $tmp, $quality);
    } else {
        // JPEG has no alpha: flatten onto white
        $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
        $ok = imagejpeg($flat, $tmp, $quality);
        imagedestroy($flat);
    }
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function nm_apply_exif_orientation(GdImage $im, string $file): GdImage
{
    if (!function_exists('exif_read_data')) return $im;
    $exif = @exif_read_data($file);
    $o = (int)($exif['Orientation'] ?? 1);
    $rotated = match ($o) {
        3 => imagerotate($im, 180, 0),
        6 => imagerotate($im, -90, 0),
        8 => imagerotate($im, 90, 0),
        default => null,
    };
    if ($rotated instanceof GdImage) {
        imagedestroy($im);
        return $rotated;
    }
    return $im;
}

/** Delete every file of a page: image, thumbnail and light version. */
function nm_delete_page(string $pagesDir, array $page): void
{
    nm_delete_page_files($pagesDir, (string)($page['f'] ?? ''));
    if (isset($page['m']['f'])) nm_delete_page_files($pagesDir, (string)$page['m']['f']);
}

/** Delete a page's files (image + thumbnail). */
function nm_delete_page_files(string $pagesDir, string $file): void
{
    if (!preg_match(NM_PAGE_PATTERN, $file)) return;
    @unlink("$pagesDir/$file");
    @unlink("$pagesDir/t_$file");
}
