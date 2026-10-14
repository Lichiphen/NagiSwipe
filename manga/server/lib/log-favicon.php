<?php
/*
 * Favicon and home-screen icons made from one square picture (512 x 512 recommended). MIT License (c) 2026 Lichiphen.
 * Every size is scaled down in halving steps (a box filter per step) and saved as lossless PNG, so small icons stay sharp
 * without the blur or blocks of a single big jump or lossy compression. favicon.ico holds the 16, 32 and 48 px PNGs.
 * Files live in data/log/favicon/<version>/; the LOG serves them as ./?favicon=<name>&v=<version>.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_FAVICON_SIZES = [16, 32, 48, 180, 192, 512];
const NL_FAVICON_ICO = [16, 32, 48];
const NL_FAVICON_MIN = 48;
const NL_FAVICON_BEST = 512;
const NL_FAVICON_PATTERN = '/\A[a-f0-9]{12}\z/';
/** The page colour of each design: behind the iPhone icon (iOS shows transparency as black) and in the web app manifest. */
const NL_THEME_BG = ['light-blue' => '#f3f7fb', 'light-sage' => '#eff5f0', 'light-paper' => '#f4efe7', 'dark-navy' => '#111b2a', 'dark-charcoal' => '#191b1f', 'dark-plum' => '#241d2d'];

function nl_favicon_dir(string $v = ''): string { return nl_root() . '/favicon' . ($v !== '' ? '/' . $v : ''); }
/** The icon set in use: ['v' => version, 'sizes' => made sizes, 'side' => the picture's side], or null. */
function nl_favicon_current(): ?array
{
    $v = nl_settings()['favicon'] ?? '';
    if (!is_string($v) || !preg_match(NL_FAVICON_PATTERN, $v)) return null;
    return nl_memo('favicon:' . $v, static function () use ($v): ?array {
        $r = nl_read_record(nl_favicon_dir($v) . '/favicon.php');
        return is_array($r) && ($r['v'] ?? '') === $v && is_array($r['sizes'] ?? null) ? $r : null;
    });
}
/** Files a request may ask for, by name. */
function nl_favicon_files(): array
{
    $out = ['ico' => 'favicon.ico', 'apple' => 'apple.png'];
    foreach (NL_FAVICON_SIZES as $size) $out[(string)$size] = $size . '.png';
    return $out;
}
/** <link> tags for the page head ('' without an icon set). $app: also the iPhone icon and the web app manifest. */
function nl_favicon_links(string $base = './', bool $app = true): string
{
    $f = nl_favicon_current();
    if (!$f) return '';
    $at = static fn(string $name) => h($base . '?favicon=' . $name . '&v=' . $f['v']);
    $out = '<link rel="icon" href="' . $at('ico') . '" sizes="any">';
    if (in_array(192, $f['sizes'], true)) $out .= '<link rel="icon" href="' . $at('192') . '" type="image/png" sizes="192x192">';
    if (!$app) return $out;
    if (in_array(180, $f['sizes'], true)) $out .= '<link rel="apple-touch-icon" href="' . $at('apple') . '">';
    if (in_array(192, $f['sizes'], true)) $out .= '<link rel="manifest" href="' . h($base . '?favicon=manifest&v=' . nl_manifest_version()) . '">';
    return $out;
}
function nl_manifest_version(): string
{
    $s = nl_settings();
    return substr(hash('sha256', ($s['favicon'] ?? '') . '|' . $s['title'] . '|' . $s['theme']), 0, 12);
}
/** ./?favicon=<name>: the picture, or the web app manifest. Served whether or not the LOG is public (the login page shows it too). */
function nl_serve_favicon(string $name): never
{
    $f = nl_favicon_current();
    if (!$f) nm_not_found();
    $asked = nm_str($_GET, 'v', 12);
    if ($name === 'manifest') {
        $s = nl_settings();
        // The manifest names the site: a private Memo keeps it to the owner (the icons themselves say nothing).
        if (!in_array(192, $f['sizes'], true) || (!$s['public'] && !(defined('NL_OWNER') && NL_OWNER))) nm_not_found();
        $icon = static fn(int $size) => ['src' => './?favicon=' . $size . '&v=' . $f['v'], 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => 'any'];
        $icons = [$icon(192)];
        if (in_array(512, $f['sizes'], true)) $icons[] = $icon(512);
        $bg = NL_THEME_BG[$s['theme']] ?? '#ffffff';
        $json = json_encode(['name' => $s['title'], 'short_name' => mb_strimwidth($s['title'], 0, 24, '…', 'UTF-8'), 'description' => $s['description'], 'lang' => nm_site_lang(),
            'start_url' => './', 'scope' => './', 'display' => 'minimal-ui', 'background_color' => $bg, 'theme_color' => $bg, 'icons' => $icons], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        nl_favicon_send('application/manifest+json; charset=UTF-8', (string)$json, $asked === nl_manifest_version(), 'manifest-' . nl_manifest_version());
    }
    $files = nl_favicon_files();
    $file = isset($files[$name]) ? nl_favicon_dir($f['v']) . '/' . $files[$name] : '';
    if ($file === '' || !is_file($file)) nm_not_found();
    nl_favicon_send($name === 'ico' ? 'image/x-icon' : 'image/png', (string)file_get_contents($file), $asked === $f['v'], $f['v'] . '-' . $name);
}
function nl_favicon_send(string $type, string $bytes, bool $current, string $tag): never
{
    $etag = '"fav-' . $tag . '"';
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    // The URL carries the version, so a new icon gets a new address; an old address asks again every time.
    header('Cache-Control: ' . ($current ? 'public, max-age=31536000, immutable' : 'no-cache'));
    header('ETag: ' . $etag);
    if (in_array($etag, array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))), true)) { http_response_code(304); exit; }
    header('Content-Length: ' . strlen($bytes));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $bytes;
    exit;
}

/* ---- Making the icons (admin) ------------------------------------------------------------------ */

/** A clear square canvas that keeps alpha. */
function nl_favicon_canvas(int $side): GdImage
{
    $im = imagecreatetruecolor($side, $side);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    return $im;
}
/** Scale a square down to $size: halve while more than twice as big, then one last step (each step averages whole pixels). */
function nl_favicon_scale(GdImage $src, int $size): GdImage
{
    $cur = $src; $side = imagesx($src);
    while ($side >= $size * 2) {
        $next = intdiv($side + 1, 2);
        $half = nl_favicon_canvas($next);
        imagecopyresampled($half, $cur, 0, 0, 0, 0, $next, $next, $side, $side);
        if ($cur !== $src) imagedestroy($cur);
        [$cur, $side] = [$half, $next];
    }
    if ($side === $size) return $cur === $src ? nl_favicon_copy($src) : $cur;
    $out = nl_favicon_canvas($size);
    imagecopyresampled($out, $cur, 0, 0, 0, 0, $size, $size, $side, $side);
    if ($cur !== $src) imagedestroy($cur);
    return $out;
}
function nl_favicon_copy(GdImage $src): GdImage
{
    $out = nl_favicon_canvas(imagesx($src));
    imagecopy($out, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
    return $out;
}
/** PNG bytes, best (lossless) compression. */
function nl_favicon_png(GdImage $im): string
{
    imagesavealpha($im, true);
    ob_start();
    $ok = imagepng($im, null, 9);
    $bytes = (string)ob_get_clean();
    if (!$ok || $bytes === '') throw new RuntimeException('png failed');
    return $bytes;
}
/** favicon.ico with PNG images inside (every current browser reads these). */
function nl_favicon_ico(array $pngs): string
{
    $count = count($pngs);
    $head = pack('vvv', 0, 1, $count); $dir = ''; $data = '';
    $offset = 6 + 16 * $count;
    foreach ($pngs as $size => $bytes) {
        $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($bytes), $offset);
        $data .= $bytes; $offset += strlen($bytes);
    }
    return $head . $dir . $data;
}
/** Open an uploaded picture and make it a square (a transparent margin, never cropped). Returns the square or an error message. */
function nl_favicon_open(string $tmp, string $name): GdImage|string
{
    if (!extension_loaded('gd') || !class_exists('finfo')) return nm_t('サーバーの PHP に GD / fileinfo がありません');
    if (!is_file($tmp) || is_link($tmp)) return nm_t('ファイルを受け取れませんでした');
    $size = (int)filesize($tmp);
    if ($size <= 0 || $size > 20 * 1024 * 1024) return nm_t('ファビコンの画像は20MB以内にしてください');
    if (nl_svg_upload($tmp, $name)) return nm_t('ファビコンにはSVGを使えません。512×512pxのPNGを選んでください');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!is_string($mime) || !isset(NM_ALLOWED_MIME[$mime]) || !$info || $info[2] !== NM_ALLOWED_MIME[$mime]) return nm_t('画像ではないか、対応していない形式です');
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1 || $w > NM_MAX_SIDE || $h > NM_MAX_SIDE || $w * $h > NM_MAX_PIXELS) return nm_t('画像の縦横が大きすぎます');
    if (max($w, $h) < NL_FAVICON_MIN) return nm_t('ファビコンの画像は{min}px以上にしてください（おすすめは512×512px）', ['min' => NL_FAVICON_MIN]);
    $side = max($w, $h);
    if (($w * $h + $side * $side) * 5 * 1.8 > nm_memory_available()) return nm_t('サーバーのメモリが足りません（画像を小さくしてください）');
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmp) : false,
        IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($tmp) : false,
        default => false,
    };
    if (!$src) return nm_t('画像を読み込めませんでした');
    if ($info[2] === IMAGETYPE_JPEG) $src = nm_apply_exif_orientation($src, $tmp);
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);
    imagealphablending($src, false);
    imagesavealpha($src, true);
    [$w, $h] = [imagesx($src), imagesy($src)];
    $side = max($w, $h);
    if ($w === $h) return $src;
    $square = nl_favicon_canvas($side);
    imagecopy($square, $src, intdiv($side - $w, 2), intdiv($side - $h, 2), 0, 0, $w, $h);
    imagedestroy($src);
    return $square;
}
/**
 * Make the icon set from a square picture and the design's page colour; returns its version.
 * Sizes larger than the picture are skipped rather than blown up.
 */
function nl_favicon_write(GdImage $square, string $theme): string
{
    $side = imagesx($square);
    $files = []; $made = [];
    foreach (NL_FAVICON_SIZES as $size) {
        if ($size > $side) continue;
        $im = nl_favicon_scale($square, $size);
        $files[$size . '.png'] = nl_favicon_png($im);
        if ($size === 180) {
            // iOS fills transparency with black: lay the icon on the page colour.
            [$r, $g, $b] = sscanf(NL_THEME_BG[$theme] ?? '#ffffff', '#%02x%02x%02x');
            $flat = imagecreatetruecolor(180, 180);
            imagefill($flat, 0, 0, imagecolorallocate($flat, (int)$r, (int)$g, (int)$b));
            imagealphablending($flat, true);
            imagecopy($flat, $im, 0, 0, 0, 0, 180, 180);
            $files['apple.png'] = nl_favicon_png($flat);
            imagedestroy($flat);
        }
        imagedestroy($im);
        $made[] = $size;
    }
    // The picture itself (up to 512 px) is kept, so a new design colour can remake the iPhone icon.
    $master = $side > NL_FAVICON_BEST ? nl_favicon_scale($square, NL_FAVICON_BEST) : nl_favicon_copy($square);
    $files['master.png'] = nl_favicon_png($master);
    imagedestroy($master);
    $ico = [];
    foreach (NL_FAVICON_ICO as $size) if (isset($files[$size . '.png'])) $ico[$size] = $files[$size . '.png'];
    $files['favicon.ico'] = nl_favicon_ico($ico);
    $v = substr(hash('sha256', $files['master.png'] . '|' . ($files['apple.png'] ?? '') . '|' . implode(',', $made)), 0, 12);
    $dir = nl_favicon_dir($v);
    nm_ensure_dir($dir);
    foreach ($files as $file => $bytes) nm_write_atomic($dir . '/' . $file, $bytes);
    nl_write_record($dir . '/favicon.php', ['v' => $v, 'sizes' => $made, 'side' => $side, 'theme' => $theme, 'created' => time()]);
    // Earlier sets are no longer linked (the one in use stays until the settings are saved).
    $keep = [$v, (string)(nl_settings()['favicon'] ?? '')];
    foreach (glob(nl_favicon_dir() . '/*', GLOB_ONLYDIR) ?: [] as $old) if (!in_array(basename($old), $keep, true) && preg_match(NL_FAVICON_PATTERN, basename($old))) nm_rmdir_recursive($old);
    nm_log('log_favicon_made', $v . ' ' . $side . 'px');
    return $v;
}
/** An uploaded picture to an icon set: its version, or an error. */
function nl_favicon_upload(array $file, string $theme): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new UnexpectedValueException(nm_t('ファビコンの画像を受け取れませんでした。アップロード上限も確認してください'));
    $square = nl_favicon_open($file['tmp_name'], (string)($file['name'] ?? ''));
    if (is_string($square)) throw new UnexpectedValueException($square);
    try { return nl_favicon_write($square, $theme); } finally { imagedestroy($square); }
}
/** The design colour changed: remake the set from its kept picture. Returns the new version (the old one on any trouble). */
function nl_favicon_retheme(string $v, string $theme): string
{
    $r = preg_match(NL_FAVICON_PATTERN, $v) ? nl_read_record(nl_favicon_dir($v) . '/favicon.php') : null;
    if (!$r || ($r['theme'] ?? '') === $theme || !in_array(180, (array)($r['sizes'] ?? []), true)) return $v;
    try {
        $im = @imagecreatefrompng(nl_favicon_dir($v) . '/master.png');
        if (!$im) return $v;
        imagealphablending($im, false); imagesavealpha($im, true);
        try { return nl_favicon_write($im, $theme); } finally { imagedestroy($im); }
    } catch (Throwable) { return $v; }
}
/** The settings block: what is made now, the picture field and the switch to remove it. */
function nl_favicon_panel(array $s): string
{
    $f = nl_favicon_current();
    $preview = '';
    if ($f) {
        foreach ([16, 32, 48, 180, 192, 512] as $size) if (in_array($size, $f['sizes'], true)) {
            $show = min($size, 96);
            $preview .= '<figure class="log-favicon-size"><img src="' . h('../?favicon=' . ($size === 180 ? 'apple' : $size) . '&v=' . $f['v']) . '" width="' . $show . '" height="' . $show . '" alt=""><figcaption>' . ($size === 180 ? nm_t('{n}px（iPhone）', ['n' => 180]) : $size . 'px') . '</figcaption></figure>';
        }
        $missing = array_values(array_diff([180, 192, 512], $f['sizes']));
        $preview = '<div class="log-favicon-preview">' . $preview . '</div>'
            . ($missing ? '<p class="note log-favicon-warn">' . nm_t('元の画像が{side}pxのため、{sizes}pxは作っていません（引き伸ばすとぼやけるため）。512px以上の画像に差し替えてください。', ['side' => (int)$f['side'], 'sizes' => implode('・', $missing)]) . '</p>' : '');
    } else {
        $preview = '<p class="note">' . nm_t('まだ設定していません。今はアイコンの画像を縮小して使っています。') . '</p>';
    }
    return '<h3>' . nm_t('ファビコン・ホーム画面のアイコン') . '</h3><p class="note">' . nm_t('ブラウザーのタブやブックマーク、スマホのホーム画面に出るアイコンです。512×512pxの正方形のPNGがおすすめです。1枚選ぶと、favicon.ico（16・32・48px）、iPhoneのホーム画面用（180px）、Androidのホーム画面用（192・512px）を自動で作ります。') . '</p>'
        . '<p class="note">' . nm_t('縮小は半分ずつ段階的に行い、画質の落ちないPNGで保存します。正方形でない画像は、切り取らずに透明な余白を足します。iPhone用は、透明な部分をデザインの背景色で塗ります。') . '</p>'
        . $preview
        . '<label>' . nm_t('ファビコンの画像') . '<input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/gif,image/avif,image/bmp"></label>'
        . ($f ? '<label class="check"><input type="checkbox" name="remove_favicon" value="1">' . nm_t('ファビコンを外して、アイコンの画像に戻す') . '</label>' : '');
}
