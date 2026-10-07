<?php
/* Additional official players, built only from validated URL components. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_embed_extra_detect(array $p, array $q): ?array
{
    $host = strtolower((string)($p['host'] ?? '')); $path = (string)($p['path'] ?? '');
    if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== (strtolower($p['scheme']) === 'https' ? 443 : 80))) return null;
    if (preg_match('/\A([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.bandcamp\.com\z/', $host) && preg_match('~\A/(album|track)/([A-Za-z0-9_-]{1,200})/?\z~', $path, $m)) {
        return ['type' => 'bandcamp', 'kind' => $m[1], 'url' => 'https://' . $host . '/' . $m[1] . '/' . $m[2]];
    }
    if (in_array($host, ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'], true) && preg_match('~\A/@[A-Za-z0-9._-]{1,80}/(?:video|photo)/(\d{10,25})/?\z~', $path, $m)) return ['type' => 'tiktok', 'id' => $m[1], 'url' => 'https://www.tiktok.com' . rtrim($path, '/')];
    if ($host === 'bsky.app' && preg_match('~\A/profile/([^/]{3,250})/post/([a-z0-9]{10,25})/?\z~', $path, $m)) {
        $repo = rawurldecode($m[1]);
        if (!nl_embed_did($repo) && !preg_match('/\A(?=.{3,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/i', $repo)) return null;
        return ['type' => 'bluesky', 'repo' => $repo, 'id' => $m[2], 'url' => 'https://bsky.app/profile/' . $repo . '/post/' . $m[2]];
    }
    if (in_array($host, ['threads.net', 'www.threads.net', 'threads.com', 'www.threads.com'], true) && preg_match('~\A/@([A-Za-z0-9._]{1,80})/post/([A-Za-z0-9_-]{5,80})/?\z~', $path, $m)) return ['type' => 'threads', 'url' => 'https://www.threads.com/@' . $m[1] . '/post/' . $m[2]];
    if (in_array($host, ['mixcloud.com', 'www.mixcloud.com'], true) && preg_match('~\A/([A-Za-z0-9_-]{1,100})/(playlists/)?([A-Za-z0-9_-]{1,200})/?\z~', $path, $m)) {
        if ($m[2] === '' && in_array(strtolower($m[3]), ['uploads', 'favorites', 'history', 'playlists', 'posts', 'following', 'followers', 'live'], true)) return null;
        return ['type' => 'mixcloud', 'kind' => $m[2] === '' ? 'show' : 'playlist', 'path' => '/' . $m[1] . '/' . $m[2] . $m[3] . '/', 'url' => 'https://www.mixcloud.com/' . $m[1] . '/' . $m[2] . $m[3] . '/'];
    }
    if (in_array($host, ['audiomack.com', 'www.audiomack.com'], true) && preg_match('~\A/([A-Za-z0-9_-]{1,100})/(song|album|playlist)/([A-Za-z0-9_-]{1,200})/?\z~', $path, $m)) return ['type' => 'audiomack', 'kind' => $m[2], 'path' => '/' . $m[1] . '/' . $m[2] . '/' . $m[3], 'url' => 'https://audiomack.com/' . $m[1] . '/' . $m[2] . '/' . $m[3]];
    if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
        if (!preg_match('~\A/(?:video/|channels/[A-Za-z0-9_-]{1,80}/|groups/[A-Za-z0-9_-]{1,80}/videos/)?(\d{1,15})(?:/([a-f0-9]{8,32}))?/?\z~', $path, $m)) return null;
        $hash = $m[2] ?? '';
        if (isset($q['h'])) { if (!is_string($q['h']) || !preg_match('/\A[a-f0-9]{8,32}\z/', $q['h'])) return null; $hash = $q['h']; }
        return ['type' => 'vimeo', 'id' => $m[1], 'hash' => $hash, 'url' => 'https://vimeo.com/' . $m[1] . ($hash !== '' ? '/' . $hash : '')];
    }
    if ($host === 'clips.twitch.tv' && preg_match('~\A/([A-Za-z0-9_-]{3,150})/?\z~', $path, $m)) return ['type' => 'twitch', 'kind' => 'clip', 'id' => $m[1], 'url' => 'https://clips.twitch.tv/' . $m[1]];
    if (in_array($host, ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv'], true)) {
        if (preg_match('~\A/videos/(\d{1,20})/?\z~', $path, $m)) return ['type' => 'twitch', 'kind' => 'video', 'id' => $m[1], 'url' => 'https://www.twitch.tv/videos/' . $m[1]];
        if (preg_match('~\A/[A-Za-z0-9_]{1,40}/clip/([A-Za-z0-9_-]{3,150})/?\z~', $path, $m)) return ['type' => 'twitch', 'kind' => 'clip', 'id' => $m[1], 'url' => 'https://clips.twitch.tv/' . $m[1]];
        if (preg_match('~\A/([A-Za-z0-9_]{1,40})/?\z~', $path, $m) && !in_array(strtolower($m[1]), ['directory', 'downloads', 'settings', 'subscriptions', 'wallet', 'login', 'signup'], true)) return ['type' => 'twitch', 'kind' => 'channel', 'id' => strtolower($m[1]), 'url' => 'https://www.twitch.tv/' . strtolower($m[1])];
        return null;
    }
    if (in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com'], true)) {
        if (preg_match('~\A/([A-Za-z0-9.]{1,100})/(posts|videos)/(?:[A-Za-z0-9_-]{1,250}/)?(\d{5,30}|pfbid[A-Za-z0-9]{10,200})/?\z~', $path, $m)) return ['type' => 'facebook', 'kind' => $m[2] === 'videos' ? 'video' : 'post', 'url' => 'https://www.facebook.com/' . $m[1] . '/' . $m[2] . '/' . $m[3]];
        if (preg_match('~\A/reel/(\d{5,30})/?\z~', $path, $m)) return ['type' => 'facebook', 'kind' => 'video', 'url' => 'https://www.facebook.com/reel/' . $m[1]];
        if (in_array($path, ['/watch', '/watch/'], true) && is_string($q['v'] ?? null) && preg_match('/\A\d{5,30}\z/', $q['v'])) return ['type' => 'facebook', 'kind' => 'video', 'url' => 'https://www.facebook.com/watch/?v=' . $q['v']];
        if ($path === '/permalink.php' && is_string($q['story_fbid'] ?? null) && is_string($q['id'] ?? null) && preg_match('/\A(?:\d{5,30}|pfbid[A-Za-z0-9]{10,200})\z/', $q['story_fbid']) && preg_match('/\A\d{5,30}\z/', $q['id'])) return ['type' => 'facebook', 'kind' => 'post', 'url' => 'https://www.facebook.com/permalink.php?story_fbid=' . $q['story_fbid'] . '&id=' . $q['id']];
        return null;
    }
    // Federated Mastodon hosts are confirmed by oEmbed on save, never accepted as raw HTML.
    if (strtolower($p['scheme']) === 'https' && preg_match('/\A(?=.{3,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host)) {
        if (preg_match('~\A/@([A-Za-z0-9_]{1,80})/(\d{5,25})/?\z~', $path, $m) || preg_match('~\A/users/([A-Za-z0-9_]{1,80})/statuses/(\d{5,25})/?\z~', $path, $m)) return ['type' => 'mastodon', 'host' => $host, 'url' => 'https://' . $host . '/@' . $m[1] . '/' . $m[2]];
    }
    return null;
}

function nl_embed_twitch_parent(): string
{
    $p = parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = strtolower((string)($p['host'] ?? 'localhost'));
    return preg_match('/\A[a-z0-9.-]{1,253}\z/', $host) ? $host : 'localhost';
}

function nl_embed_extra_html(array $e, bool $dark, bool $admin): ?string
{
    $media = ' allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen';
    $name = NL_EMBED_NAMES[$e['type']] ?? '';
    switch ($e['type']) {
        case 'bandcamp':
            $d = nl_embed_resolved($e);
            if (!$d) return nl_embed_unavailable($e['url'], $name);
            $src = 'https://bandcamp.com/EmbeddedPlayer/' . $e['kind'] . '=' . $d['id'] . '/size=large/bgcol=' . ($dark ? '333333/linkcol=7eb7ff' : 'ffffff/linkcol=0687f5') . '/artwork=small/tracklist=' . ($e['kind'] === 'album' ? 'true' : 'false') . '/transparent=true/';
            return nl_embed_frame('bandcamp', 'embeddedbandcamp' . ($e['kind'] === 'album' ? ' log-embed-tall' : ''), $src, $name, '', $e['url']);
        case 'bluesky':
            $did = nl_embed_did($e['repo']) ? $e['repo'] : (nl_embed_resolved($e)['did'] ?? '');
            if ($did === '') return nl_embed_unavailable($e['url'], $name);
            return nl_embed_frame('bluesky', 'embeddedbluesky', 'https://embed.bsky.app/embed/' . $did . '/app.bsky.feed.post/' . $e['id'] . '?id=' . $e['id'] . '&colorMode=' . ($dark ? 'dark' : 'light'), $name, '', $e['url']);
        case 'mastodon':
            if (!nl_embed_resolved($e)) return nl_embed_unavailable($e['url'], $name);
            return nl_embed_frame('mastodon', 'embeddedmastodon', ($admin ? '../' : './') . 'embed.php?k=' . nl_embed_key($e), $name, '', $e['url']);
        case 'tiktok':
            return nl_embed_frame('tiktok', 'embeddedtiktok', 'https://www.tiktok.com/player/v1/' . $e['id'] . '?autoplay=0&description=1&music_info=1', $name, $media, $e['url']);
        case 'threads':
            return nl_embed_frame('threads', 'embeddedthreads', $e['url'] . '/embed/?theme=' . ($dark ? 'dark' : 'light'), $name, '', $e['url']);
        case 'mixcloud':
            return nl_embed_frame('mixcloud', 'embeddedmixcloud' . ($e['kind'] === 'playlist' ? ' log-embed-tall' : ''), 'https://player-widget.mixcloud.com/?hide_cover=0&light=' . ($dark ? '0' : '1') . '&feed=' . rawurlencode($e['path']), $name, ' allow="autoplay; encrypted-media"', $e['url']);
        case 'audiomack':
            return nl_embed_frame('audiomack', 'embeddedaudiomack' . ($e['kind'] === 'song' ? '' : ' log-embed-tall'), 'https://audiomack.com/embed' . $e['path'], $name, ' scrolling="no" allow="autoplay; encrypted-media"', $e['url']);
        case 'vimeo':
            return nl_embed_frame('vimeo', 'embeddedvimeo', 'https://player.vimeo.com/video/' . $e['id'] . '?autoplay=0&dnt=1' . ($e['hash'] !== '' ? '&h=' . $e['hash'] : ''), $name, $media, $e['url']);
        case 'twitch':
            $parent = rawurlencode(nl_embed_twitch_parent());
            $src = $e['kind'] === 'clip' ? 'https://clips.twitch.tv/embed?clip=' . $e['id'] : 'https://player.twitch.tv/?' . ($e['kind'] === 'video' ? 'video=v' : 'channel=') . $e['id'];
            return nl_embed_frame('twitch', 'embeddedtwitch', $src . '&parent=' . $parent . '&autoplay=false', $name, $media, $e['url']);
        case 'facebook':
            return nl_embed_frame('facebook', 'embeddedfacebook', 'https://www.facebook.com/plugins/' . ($e['kind'] === 'video' ? 'video' : 'post') . '.php?href=' . rawurlencode($e['url']) . '&show_text=true&width=500&autoplay=false', $name, $e['kind'] === 'video' ? $media : '', $e['url']);
    }
    return null;
}

function nl_embed_unavailable(string $url, string $name): string
{
    return '<span class="log-embed log-embed-unavailable"><a href="' . h($url) . '" rel="noopener noreferrer">' . h($name) . 'で開く</a></span>';
}
