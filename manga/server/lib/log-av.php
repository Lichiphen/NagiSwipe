<?php
/*
 * NagiLog video and audio
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Files are kept as uploaded: shared hosts have no encoder, and browsers play these formats as they are.
 * The type comes from the file's first bytes, never from its name. Large files arrive in pieces
 * (hosts often cap one upload at 2–64 MB) and are joined here; a piece sent again is accepted.
 * What the player needs that the server cannot work out (length, size, the waveform, a cover or
 * poster picture) is measured by the owner's browser at upload and kept with the file.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

/** Stored extension => kind and MIME type. */
const NL_AV_TYPES = [
    'mp4' => ['video', 'video/mp4'], 'webm' => ['video', 'video/webm'],
    'mp3' => ['audio', 'audio/mpeg'], 'm4a' => ['audio', 'audio/mp4'], 'ogg' => ['audio', 'audio/ogg'],
    'wav' => ['audio', 'audio/wav'], 'flac' => ['audio', 'audio/flac'], 'weba' => ['audio', 'audio/webm'],
];
/** One video or audio file at most (MB). */
const NL_AV_MAX_MB = 1024;
/** One piece of an upload at most; smaller when the host's own limit is lower. */
const NL_AV_CHUNK_MAX = 8 * 1024 * 1024;
/** One answer to a player's Range request at most (it asks again for the rest). */
const NL_AV_PIECE = 512 * 1024;
/** Bars kept for the waveform. */
const NL_AV_PEAKS = 200;

function nl_is_av(?array $m): bool { return $m !== null && in_array($m['kind'] ?? '', ['video', 'audio'], true); }

function nl_av_mime(array $m): string { return NL_AV_TYPES[pathinfo((string)$m['f'], PATHINFO_EXTENSION)][1] ?? 'application/octet-stream'; }

/** The stored extension for a file, from its first bytes; $audio decides between video and audio for MP4 and WebM. */
function nl_av_detect(string $file, bool $audio): ?string
{
    $head = (string)@file_get_contents($file, false, null, 0, 64);
    if (strlen($head) < 12) return null;
    if (substr($head, 4, 4) === 'ftyp') {
        $brand = substr($head, 8, 4);
        return $audio || in_array($brand, ['M4A ', 'M4B ', 'M4P '], true) ? 'm4a' : 'mp4';
    }
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return $audio ? 'weba' : 'webm';
    if (str_starts_with($head, 'ID3') || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) return 'mp3';
    if (str_starts_with($head, 'OggS')) return 'ogg';
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') return 'wav';
    if (str_starts_with($head, 'fLaC')) return 'flac';
    return null;
}

/** Bytes from a php.ini size such as 32M. */
function nl_ini_bytes(string $value): int
{
    $value = trim($value);
    $n = (int)$value;
    return match (strtolower(substr($value, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Pieces small enough for this host: under both upload limits, with room for the rest of the form. */
function nl_av_chunk_size(): int
{
    $limits = array_filter([nl_ini_bytes((string)ini_get('upload_max_filesize')), nl_ini_bytes((string)ini_get('post_max_size'))], static fn($n) => $n > 0);
    $host = $limits ? min($limits) : NL_AV_CHUNK_MAX;
    return max(256 * 1024, min(NL_AV_CHUNK_MAX, (int)floor($host * 0.8)));
}

function nl_av_upload_dir(string $token): string
{
    if (!preg_match('/\A[a-f0-9]{24}\z/', $token)) throw new UnexpectedValueException(nm_t('アップロードを始め直してください'));
    return nl_root() . '/uploads/' . $token;
}

/** Start an upload: checks the size and kind, and returns where to send the pieces. */
function nl_av_begin(string $name, int $size, string $type, string $replace = '', int $revision = 0): array
{
    $kind = str_starts_with($type, 'video/') ? 'video' : (str_starts_with($type, 'audio/') ? 'audio' : '');
    if ($kind === '') throw new UnexpectedValueException(nm_t('動画か音声のファイルを選んでください'));
    if ($size < 1 || $size > NL_AV_MAX_MB * 1048576) throw new UnexpectedValueException(nm_t('{n}MBまでのファイルにしてください', ['n' => NL_AV_MAX_MB]));
    if ($replace !== '') {
        $old = nl_load_media($replace);
        if (!$old || !nl_is_av($old)) throw new UnexpectedValueException(nm_t('差し替えるファイルが見つかりません'));
        if ($old['revision'] !== $revision) throw new UnexpectedValueException(nm_t('別の画面で更新されています。開き直してください'));
    }
    $free = @disk_free_space(nl_root());
    if (is_float($free) && $free < $size * 2 + 50 * 1048576) throw new UnexpectedValueException(nm_t('サーバーの空き容量が足りません'));
    nl_av_sweep();
    $token = bin2hex(random_bytes(12));
    $dir = nl_av_upload_dir($token);
    nm_ensure_dir($dir);
    $name = mb_substr(basename(str_replace('\\', '/', mb_check_encoding($name, 'UTF-8') ? $name : '')), 0, 200);
    nl_write_record($dir . '/state.php', ['name' => (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name), 'size' => $size, 'kind' => $kind, 'replace' => $replace, 'revision' => $revision, 'started' => time()]);
    touch($dir . '/part');
    return ['token' => $token, 'chunk' => nl_av_chunk_size(), 'received' => 0];
}

/** Add one piece at $offset. A piece already received (sent again after a dropped reply) is accepted as is. */
function nl_av_chunk(string $token, int $offset, array $file): array
{
    $dir = nl_av_upload_dir($token);
    $state = nl_read_record($dir . '/state.php');
    if (!$state) throw new UnexpectedValueException(nm_t('アップロードを始め直してください'));
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new UnexpectedValueException(nm_t('ファイルの一部を受け取れませんでした。もう一度お試しください'));
    $part = $dir . '/part';
    clearstatcache(true, $part);
    $have = (int)filesize($part);
    $len = (int)filesize($file['tmp_name']);
    if ($len < 1 || $len > NL_AV_CHUNK_MAX) throw new UnexpectedValueException(nm_t('ファイルの一部を受け取れませんでした。もう一度お試しください'));
    if ($offset + $len <= $have) return ['received' => $have];
    if ($offset !== $have || $have + $len > $state['size']) return ['received' => $have, 'resend' => true];
    $in = fopen($file['tmp_name'], 'rb'); $out = fopen($part, 'ab');
    if (!$in || !$out) throw new RuntimeException('chunk write failed');
    try { if (stream_copy_to_stream($in, $out) !== $len) throw new RuntimeException('chunk write failed'); }
    finally { fclose($in); fclose($out); }
    return ['received' => $have + $len];
}

/**
 * A loop marked inside a song (LOOPSTART tags, a WAV smpl chunk), read by the owner's browser: [start, end] in seconds,
 * end 0 for the end of the file. Null when there is none or it does not fit the song.
 */
function nl_av_loop(mixed $value, float $duration): ?array
{
    if (!is_array($value) || count($value) !== 2 || !is_numeric($value[0] ?? null) || !is_numeric($value[1] ?? null)) return null;
    $start = round((float)$value[0], 4); $end = round((float)$value[1], 4);
    if ($duration > 0 && $end >= $duration - 0.01) $end = 0.0;
    if ($start < 0 || $end < 0 || ($end > 0 && $end - $start < 0.05) || ($duration > 0 && $start >= $duration - 0.05) || $start > 86400 || $end > 86400) return null;
    return [$start, $end];
}
/**
 * How a video plays in posts, chosen by the owner: by itself once it is seen (browsers allow that only
 * without sound), without sound at first, and over and over.
 */
function nl_av_play(mixed $value): array
{
    $value = is_array($value) ? $value : [];
    $auto = !empty($value['auto']);
    return ['auto' => $auto, 'muted' => $auto || !empty($value['muted']), 'loop' => !empty($value['loop'])];
}
/** Check the joined file and keep it with its measurements and cover; returns the media record. */
function nl_av_finish(string $token, array $meta, ?array $cover): array
{
    $dir = nl_av_upload_dir($token);
    $state = nl_read_record($dir . '/state.php');
    if (!$state) throw new UnexpectedValueException(nm_t('アップロードを始め直してください'));
    $part = $dir . '/part';
    clearstatcache(true, $part);
    if ((int)filesize($part) !== (int)$state['size']) throw new UnexpectedValueException(nm_t('ファイルが最後まで届いていません。もう一度お試しください'));
    $ext = nl_av_detect($part, $state['kind'] === 'audio');
    if ($ext === null || NL_AV_TYPES[$ext][0] !== $state['kind']) {
        nm_rmdir_recursive($dir);
        throw new UnexpectedValueException(nm_t('この形式には対応していません。動画は MP4・WebM・MOV、音声は MP3・M4A・OGG・WAV・FLAC です'));
    }
    $duration = is_numeric($meta['duration'] ?? null) ? round(max(0.0, min(86400.0, (float)$meta['duration'])), 2) : 0.0;
    $width = max(0, min(16384, (int)($meta['width'] ?? 0))); $height = max(0, min(16384, (int)($meta['height'] ?? 0)));
    $peaks = [];
    if ($state['kind'] === 'audio' && is_array($meta['peaks'] ?? null)) {
        foreach (array_slice($meta['peaks'], 0, NL_AV_PEAKS) as $v) $peaks[] = max(0, min(100, (int)$v));
    }
    $loop = $state['kind'] === 'audio' ? nl_av_loop($meta['loop'] ?? null, $duration) : null;
    // A replacement without a cover keeps the old one, unless the owner took the cover out of the file.
    $keepCover = empty($meta['drop_cover']);
    $result = nm_with_lock('personal-log', static function () use ($state, $part, $ext, $duration, $width, $height, $peaks, $loop, $keepCover, $cover) {
        $old = $state['replace'] !== '' ? nl_load_media($state['replace']) : null;
        if ($state['replace'] !== '' && (!$old || $old['revision'] !== $state['revision'])) throw new UnexpectedValueException(nm_t('別の画面で更新されています。開き直してください'));
        $id = $old ? $old['id'] : bin2hex(random_bytes(8));
        $mediaDir = nl_media_dir($id);
        nm_ensure_dir($mediaDir);
        $file = 'p0000_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!rename($part, $mediaDir . '/' . $file)) throw new RuntimeException('move failed');
        @chmod($mediaDir . '/' . $file, 0644);
        // The cover (audio art or the video's poster frame) is an ordinary picture, redrawn like any upload.
        $pic = null;
        if ($cover && ($cover['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($cover['tmp_name'] ?? null) && is_uploaded_file($cover['tmp_name'])) {
            $cfg = nm_config();
            $pic = nm_import_image($cover['tmp_name'], $mediaDir, 1, (int)($cfg['image_quality'] ?? 90), 20 * 1048576, true, true, 2048);
            if (is_string($pic)) $pic = null;
        }
        if (!$pic && $keepCover && $old && ($old['cover'] ?? '') !== '' && is_file($mediaDir . '/' . $old['cover'])) $pic = ['f' => $old['cover'], 'w' => $old['cw'] ?? 0, 'h' => $old['ch'] ?? 0];
        $name = $state['name'] !== '' ? (string)preg_replace('/\.[A-Za-z0-9]{1,5}\z/', '', $state['name']) : ($state['kind'] === 'video' ? '動画' : '音声');
        $m = ['id' => $id, 'kind' => $state['kind'], 'f' => $file, 'size' => (int)$state['size'], 'duration' => $duration,
            'w' => $pic ? (int)$pic['w'] : $width, 'h' => $pic ? (int)$pic['h'] : $height, 'vw' => $width, 'vh' => $height,
            'cover' => $pic ? $pic['f'] : '', 'cw' => $pic ? (int)$pic['w'] : 0, 'ch' => $pic ? (int)$pic['h'] : 0, 'peaks' => $peaks, 'loop' => $loop,
            'play' => $state['kind'] === 'video' ? nl_av_play($old['play'] ?? null) : null,
            'alt' => $old['alt'] ?? $name, 'rating' => $old['rating'] ?? '', 'created' => $old['created'] ?? time(), 'updated' => time(), 'revision' => (int)($old['revision'] ?? 0) + 1];
        nl_write_record($mediaDir . '/media.php', $m);
        nm_touch_content();
        if ($old) {
            if ($old['f'] !== $file) nl_delete_media_files($mediaDir, $old['f']);
            if (($old['cover'] ?? '') !== '' && $old['cover'] !== $m['cover']) nl_delete_media_files($mediaDir, $old['cover']);
        }
        nm_log($old ? 'log_media_replaced' : 'log_media_uploaded', $id . ' ' . $ext);
        return $m;
    });
    nm_rmdir_recursive($dir);
    return $result;
}

function nl_av_cancel(string $token): void
{
    $dir = nl_av_upload_dir($token);
    if (is_dir($dir)) nm_rmdir_recursive($dir);
}

/** Uploads left unfinished for a day are removed. */
function nl_av_sweep(): void
{
    foreach (glob(nl_root() . '/uploads/*/state.php') ?: [] as $file) {
        if (filemtime(dirname($file) . '/part') < time() - 86400) nm_rmdir_recursive(dirname($file));
    }
}

/** m:ss or h:mm:ss */
function nl_av_time(float $seconds): string
{
    $s = (int)round($seconds);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/**
 * The player. Without its script it is the browser's own player; log-player.js adds the controls
 * (and the waveform from data-peaks) and removes the browser's.
 */
function nl_av_html(array $m, bool $admin = false): string
{
    $src = h(nl_media_url($m, false, $admin));
    $cover = ($m['cover'] ?? '') !== '' ? nl_media_url($m, true, $admin) : '';
    $title = h((string)$m['alt']);
    $length = $m['duration'] > 0 ? nl_av_time((float)$m['duration']) : '';
    if ($m['kind'] === 'video') {
        // The frame keeps the video's shape (a phone recording stays upright and centered) before anything loads.
        $ratio = $m['vw'] > 0 && $m['vh'] > 0 ? ' style="--player-w:' . (int)$m['vw'] . ';--player-h:' . (int)$m['vh'] . '"' : '';
        $play = nl_av_play($m['play'] ?? null);
        return '<figure class="log-player log-video" data-player="video"' . ($play['auto'] ? ' data-autoplay' : '') . $ratio . '><video src="' . $src . '" preload="metadata" playsinline controls'
            . ($play['muted'] ? ' muted' : '') . ($play['loop'] ? ' loop' : '')
            . ($cover !== '' ? ' poster="' . h(nl_media_url($m, false, $admin, true)) . '"' : '') . ' aria-label="' . $title . '"></video>'
            . '<figcaption class="log-player-caption">' . $title . '</figcaption></figure>';
    }
    return '<figure class="log-player log-audio' . ($cover !== '' ? ' has-cover' : '') . '" data-player="audio" data-peaks="' . implode(',', array_map('intval', $m['peaks'] ?? [])) . '" data-duration="' . h((string)$m['duration']) . '"'
        . (is_array($m['loop'] ?? null) ? ' data-loop="' . h((float)$m['loop'][0] . ',' . (float)$m['loop'][1]) . '"' : '') . '>'
        . ($cover !== '' ? '<img class="log-player-cover" src="' . h($cover) . '" alt="" loading="lazy">' : '')
        . '<figcaption class="log-player-title">' . $title . '</figcaption>'
        . '<audio src="' . $src . '" preload="metadata" controls aria-label="' . $title . '"></audio>'
        . ($length !== '' ? '<span class="log-player-length">' . $length . '</span>' : '') . '</figure>';
}

/**
 * Send a video or audio file, or the part a player asks for (Range): seeking, and iPhone, need it.
 * $etag lets an unchanged file answer 304 to a plain request.
 */
function nl_av_send(string $file, string $mime, string $etag = ''): never
{
    $size = (int)filesize($file);
    $start = 0; $end = $size - 1;
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    $ifRange = trim((string)($_SERVER['HTTP_IF_RANGE'] ?? ''));
    if ($range !== '' && ($ifRange === '' || $ifRange === $etag) && preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $r) && ($r[1] !== '' || $r[2] !== '')) {
        if ($r[1] === '') $start = max(0, $size - (int)$r[2]);
        else { $start = (int)$r[1]; if ($r[2] !== '') $end = min($end, (int)$r[2]); }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        // At most one piece per answer: a phone's player asks for bytes=0- and stops reading once it has what it
        // needs, which would keep this PHP worker (the only one under php -S) waiting on the file. It asks again for the rest.
        $end = min($end, $start + NL_AV_PIECE - 1);
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();
    $fh = fopen($file, 'rb');
    if (!$fh) exit;
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $buf = fread($fh, (int)min(262144, $left));
        if ($buf === false || $buf === '') break;
        echo $buf; flush();
        $left -= strlen($buf);
    }
    fclose($fh);
    exit;
}
