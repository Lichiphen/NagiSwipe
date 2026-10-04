<?php
/* Public image collector and burst limits. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }
const NM_IMAGE_COLLECTORS = ['ImageScraper', 'ImageCollector', 'ImageDownloader', 'HTTrack', 'WebCopier', 'Scrapy'];
function nm_image_guard(array $cfg): void
{
    if (isset($cfg['image_guard_enabled']) && !$cfg['image_guard_enabled']) return;
    $ua = strtolower(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000));
    foreach ($cfg['image_guard_agents'] ?? NM_IMAGE_COLLECTORS as $agent) if ($agent !== '' && str_contains($ua, strtolower($agent))) nm_not_found();
    $ip = nm_client_ip();
    $file = NM_DATA . '/ratelimit/image-' . hash_hmac('sha256', $ip, (string)$cfg['secret']) . '.php';
    $blocked = nm_with_lock('image-guard', static function () use ($cfg, $file) {
        $now = time(); $state = is_file($file) ? require $file : [];
        if (($state['until'] ?? 0) > $now) return true;
        $short = intdiv($now, 10); $minute = intdiv($now, 60);
        $state = ['short' => $short, 'minute' => $minute, 'burst' => ($state['short'] ?? -1) === $short ? (int)$state['burst'] + 1 : 1,
            'count' => ($state['minute'] ?? -1) === $minute ? (int)$state['count'] + 1 : 1, 'until' => 0];
        $deny = $state['burst'] > (int)($cfg['image_guard_burst'] ?? 120) || $state['count'] > (int)($cfg['image_guard_minute'] ?? 300);
        if ($deny) $state['until'] = $now + 300;
        nm_write_atomic($file, "<?php\nif (!defined('NAGIMANGA')) { http_response_code(404); exit; }\nreturn " . var_export($state, true) . ";\n");
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        return $deny;
    });
    if ($blocked) nm_not_found();
    if (random_int(1, 100) === 1) foreach (glob(NM_DATA . '/ratelimit/image-*.php') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
}
