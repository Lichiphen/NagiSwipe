<?php
/* Public image collector and burst limits. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }
const NM_IMAGE_COLLECTORS = ['ImageScraper', 'ImageCollector', 'ImageDownloader', 'HTTrack', 'WebCopier', 'Scrapy'];
/** First line of every counter file: a guard, so the counters never show as text on hosts ignoring .htaccess. */
const NM_IMAGE_GUARD_HEAD = "<?php http_response_code(404); exit; ?>\n";
function nm_image_guard_file(string $ip, array $cfg): string
{
    return NM_DATA . '/ratelimit/image-' . hash_hmac('sha256', $ip, (string)$cfg['secret']) . '.php';
}
/** Counter line: "short burst minute count until". Anything else (an older format, a torn write) starts over. */
function nm_image_guard_state(string $raw): array
{
    $line = str_starts_with($raw, NM_IMAGE_GUARD_HEAD) ? trim(substr($raw, strlen(NM_IMAGE_GUARD_HEAD))) : '';
    if (!preg_match('/\A([0-9]{1,12}) ([0-9]{1,9}) ([0-9]{1,12}) ([0-9]{1,9}) ([0-9]{1,12})\z/', $line, $m)) return [];
    return ['short' => (int)$m[1], 'burst' => (int)$m[2], 'minute' => (int)$m[3], 'count' => (int)$m[4], 'until' => (int)$m[5]];
}
function nm_image_guard_line(array $s): string
{
    return NM_IMAGE_GUARD_HEAD . implode(' ', [(int)$s['short'], (int)$s['burst'], (int)$s['minute'], (int)$s['count'], (int)$s['until']]) . "\n";
}
/**
 * Each visitor's counters live in one small file, locked and rewritten in place: no shared lock, so one
 * visitor's images never wait for another's, and no PHP to compile on every image.
 */
function nm_image_guard(array $cfg): void
{
    if (isset($cfg['image_guard_enabled']) && !$cfg['image_guard_enabled']) return;
    $ua = strtolower(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000));
    foreach ($cfg['image_guard_agents'] ?? NM_IMAGE_COLLECTORS as $agent) if ($agent !== '' && str_contains($ua, strtolower($agent))) nm_not_found();
    $file = nm_image_guard_file(nm_client_ip(), $cfg);
    if (!is_dir(dirname($file))) nm_ensure_dir(dirname($file));
    $fh = @fopen($file, 'c+');
    if ($fh === false) throw new RuntimeException('image guard failed');
    try {
        flock($fh, LOCK_EX);
        $state = nm_image_guard_state((string)stream_get_contents($fh));
        $now = time();
        $blocked = ($state['until'] ?? 0) > $now;
        if (!$blocked) {
            $short = intdiv($now, 10); $minute = intdiv($now, 60);
            $state = ['short' => $short, 'minute' => $minute, 'burst' => ($state['short'] ?? -1) === $short ? $state['burst'] + 1 : 1,
                'count' => ($state['minute'] ?? -1) === $minute ? $state['count'] + 1 : 1, 'until' => 0];
            $blocked = $state['burst'] > (int)($cfg['image_guard_burst'] ?? 120) || $state['count'] > (int)($cfg['image_guard_minute'] ?? 300);
            if ($blocked) $state['until'] = $now + 300;
            $line = nm_image_guard_line($state);
            rewind($fh); ftruncate($fh, 0); fwrite($fh, $line);
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    if ($blocked) nm_not_found();
    if (random_int(1, 100) === 1) foreach (glob(NM_DATA . '/ratelimit/image-*.php') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
}
