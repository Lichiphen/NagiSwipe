<?php
/*
 * LOG embeds: a URL alone on its own line becomes a player or card when its domain is a known service.
 * URLs inside a sentence stay plain links. Frames are built from IDs parsed here, never from the raw URL.
 * Provider scripts (X, Instagram, note) are loaded by viewer/log-embed.js, never from the post body.
 * MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/** A whole line that is just one URL (surrounding spaces allowed). */
const NL_EMBED_PATTERN = '(?<![^\n])[ \t]*https?:\/\/[^\s<>"\[\]]+[ \t]*(?![^\r\n])';
const NL_GIGAVIEWER_HOSTS = ['shonenjumpplus.com', 'tonarinoyj.jp', 'comic-days.com', 'kuragebunch.com', 'viewer.heros-web.com', 'comicborder.com', 'comic-gardo.com', 'comic-zenon.com', 'magcomi.com', 'comic-action.com', 'comic-trail.com', 'feelweb.jp', 'www.sunday-webry.com', 'comic-ogyaaa.com', 'comic-earthstar.com', 'ourfeel.jp', 'comic-seasons.com', 'ichicomi.com', 'comic-y-ours.com'];
/** Frame origins every embed may use; the CSP lists exactly these. */
const NL_EMBED_FRAMES = ['https://www.youtube-nocookie.com', 'https://embed.nicovideo.jp', 'https://open.spotify.com', 'https://embed.music.apple.com', 'https://platform.twitter.com', 'https://syndication.twitter.com',
    'https://www.instagram.com', 'https://codepen.io', 'https://note.com', 'https://voicy.jp', 'https://store.steampowered.com', 'https://music.amazon.co.jp', 'https://w.soundcloud.com',
    'https://bandcamp.com', 'https://www.tiktok.com', 'https://embed.bsky.app', 'https://www.threads.com', 'https://player-widget.mixcloud.com', 'https://audiomack.com', 'https://www.facebook.com', 'https://player.vimeo.com', 'https://player.twitch.tv', 'https://clips.twitch.tv', "'self'"];
const NL_EMBED_SCRIPTS = ['https://platform.twitter.com', 'https://www.instagram.com', 'https://note.com'];
const NL_EMBED_NAMES = ['youtube' => 'YouTube', 'nicovideo' => 'ニコニコ動画', 'spotify' => 'Spotify', 'applemusic' => 'Apple Music', 'amazonmusic' => 'Amazon Music', 'tweet' => 'X（Twitter）', 'instagram' => 'Instagram',
    'soundcloud' => 'SoundCloud', 'bandcamp' => 'Bandcamp', 'tiktok' => 'TikTok', 'bluesky' => 'Bluesky', 'threads' => 'Threads', 'mastodon' => 'Mastodon', 'mixcloud' => 'Mixcloud', 'audiomack' => 'Audiomack', 'facebook' => 'Facebook', 'vimeo' => 'Vimeo', 'twitch' => 'Twitch',
    'codepen' => 'CodePen', 'note' => 'note', 'voicy' => 'Voicy', 'steam' => 'Steam', 'gigaviewer' => 'コミック', 'video' => '動画'];
require_once __DIR__ . '/log-embed-resolve.php';
require_once __DIR__ . '/log-embed-providers.php';

function nl_embed_regex(): string { return '/(' . NL_EMBED_PATTERN . ')/i'; }

/** CSP sources: frames for the known services and the provider scripts. */
function nl_embed_frame_src(): string { return implode(' ', array_merge(NL_EMBED_FRAMES, array_map(static fn($h) => 'https://' . $h, NL_GIGAVIEWER_HOSTS))); }
function nl_embed_script_src(): string { return implode(' ', NL_EMBED_SCRIPTS); }

/** Which service a URL belongs to and the IDs needed to build its embed, or null. */
function nl_embed_detect(string $url): ?array
{
    $url = trim($url);
    if (!preg_match('~\Ahttps?://[^\s<>"\[\]]+\z~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $q);

    if (preg_match('/\A(?:www\.|m\.|music\.)?youtube\.com\z/', $host) || $host === 'youtu.be') {
        $id = ''; $list = ''; $start = 0;
        if ($host === 'youtu.be' && preg_match('~\A/([-\w]+)~', $path, $m)) $id = $m[1];
        elseif ($path === '/watch' && is_string($q['v'] ?? null)) $id = $q['v'];
        elseif (preg_match('~\A/(?:shorts|live|embed)/([-\w]+)~', $path, $m)) $id = $m[1];
        elseif ($path === '/playlist' && is_string($q['list'] ?? null)) $list = $q['list'];
        if (is_string($q['t'] ?? null) && preg_match('/\A(\d+)s?\z/', $q['t'], $m)) $start = (int)$m[1];
        if ($list !== '' && preg_match('/\A[-\w]{2,64}\z/', $list)) return ['type' => 'youtube', 'list' => $list];
        if (preg_match('/\A[-\w]{6,32}\z/', $id)) return ['type' => 'youtube', 'id' => $id, 'start' => $start];
        return null;
    }
    if (preg_match('/\A(?:www\.|sp\.)?nicovideo\.jp\z/', $host) && preg_match('~\A/watch/([a-z]{2}\d{1,12}|\d{1,12})\z~i', $path, $m)) return ['type' => 'nicovideo', 'id' => $m[1]];
    if ($host === 'nico.ms' && preg_match('~\A/([a-z]{2}\d{1,12})\z~i', $path, $m)) return ['type' => 'nicovideo', 'id' => $m[1]];
    if ($host === 'open.spotify.com' && preg_match('~\A/(?:embed/|intl-[a-z]{2}(?:-[a-z]{2})?/)?(track|album|playlist|artist|episode|show)/([A-Za-z0-9]{8,40})~', $path, $m)) return ['type' => 'spotify', 'kind' => $m[1], 'id' => $m[2]];
    if (in_array($host, ['music.apple.com', 'embed.music.apple.com'], true) && preg_match('~\A/[a-z]{2}/(album|playlist|song|music-video|station)/[^/]{0,200}/?[\w.\-]{1,80}\z~', $path, $m)) {
        return ['type' => 'applemusic', 'path' => $path, 'i' => is_string($q['i'] ?? null) && ctype_digit($q['i']) ? $q['i'] : '', 'song' => $m[1] === 'song' || isset($q['i'])];
    }
    if ($host === 'music.amazon.co.jp') {
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))) return null;
        if (!preg_match('~\A/(albums|playlists)/([A-Z0-9]{10})/?\z~i', $path, $m)) return null;
        $kind = strtolower($m[1]) === 'playlists' ? 'playlist' : 'album';
        $id = strtoupper($m[2]);
        // A song shared from its album has the song ASIN in the query, not in the path.
        if ($kind === 'album' && array_key_exists('trackAsin', $q)) {
            if (!is_string($q['trackAsin']) || !preg_match('/\A[A-Z0-9]{10}\z/i', $q['trackAsin'])) return null;
            $kind = 'track'; $id = strtoupper($q['trackAsin']);
        }
        return ['type' => 'amazonmusic', 'kind' => $kind, 'id' => $id];
    }
    if (in_array($host, ['soundcloud.com', 'www.soundcloud.com'], true)) {
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80))) return null;
        if (array_key_exists('secret_token', $q)) return null; // Private links need a separate sharing flow.
        if (!preg_match('~\A/([A-Za-z0-9_-]{1,100})/(sets/)?([A-Za-z0-9_-]{1,200})/?\z~', $path, $m)) return null;
        if ($m[2] === '' && in_array(strtolower($m[3]), ['sets', 'tracks', 'albums', 'likes', 'reposts', 'popular-tracks', 'spotlight', 'comments', 'followers', 'following'], true)) return null;
        return ['type' => 'soundcloud', 'kind' => $m[2] === '' ? 'track' : 'playlist', 'user' => $m[1], 'slug' => $m[3]];
    }
    if (preg_match('/\A(?:(?:www|mobile)\.)?(?:twitter|x)\.com\z/', $host) && preg_match('~\A/(\w{1,15})/status(?:es)?/(\d{1,25})~', $path, $m)) return ['type' => 'tweet', 'user' => $m[1], 'id' => $m[2]];
    if (preg_match('/\A(?:www\.)?instagram\.com\z/', $host) && preg_match('~\A/(?:[\w.]{1,30}/)?(p|reel|tv)/([\w-]{5,40})~', $path, $m)) return ['type' => 'instagram', 'kind' => $m[1], 'id' => $m[2]];
    if (preg_match('/\A(?:www\.)?codepen\.io\z/', $host) && preg_match('~\A/([\w-]{1,60})/(?:pen|full|details)/(\w{3,20})~', $path, $m)) return ['type' => 'codepen', 'user' => $m[1], 'id' => $m[2]];
    if (preg_match('/\A(?:www\.)?note\.com\z/', $host) && preg_match('~\A/[\w.-]{1,60}/n/(n[a-f0-9]{8,20})\z~', $path, $m)) return ['type' => 'note', 'id' => $m[1]];
    if ($host === 'voicy.jp' && preg_match('~\A/channel/(\d{1,10}(?:/\d{1,12})?)~', $path, $m)) return ['type' => 'voicy', 'id' => $m[1]];
    if ($host === 'store.steampowered.com' && preg_match('~\A/app/(\d{1,12})~', $path, $m)) return ['type' => 'steam', 'id' => $m[1]];
    if (in_array($host, NL_GIGAVIEWER_HOSTS, true) && preg_match('~\A/episode/(\d{5,25})~', $path, $m)) return ['type' => 'gigaviewer', 'host' => $host, 'id' => $m[1]];
    if ($extra = nl_embed_extra_detect($parts, $q)) return $extra;
    if (preg_match('~\.(?:mp4|webm|ogv|mov|m4v)\z~i', $path)) return ['type' => 'video', 'url' => $url];
    return null;
}

/** Short name for excerpts and titles, e.g. "YouTube"; '' when the line is not an embed. */
function nl_embed_name(string $line): string
{
    $e = nl_embed_detect($line);
    return $e ? NL_EMBED_NAMES[$e['type']] : '';
}

function nl_embed_frame(string $type, string $classes, string $src, string $title, string $attrs = '', string $href = ''): string
{
    return '<span class="log-embed ' . $classes . '" data-embed="' . $type . '"><iframe src="' . h($src) . '" title="' . h($title) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"' . $attrs . '></iframe>'
        . ($href !== '' ? '<a class="log-embed-source" href="' . h($href) . '" target="_blank" rel="noopener noreferrer">' . h($title) . 'で開く ↗</a>' : '') . '</span>';
}

/** HTML for a URL on its own line, or null when its domain is not a supported service. $dark picks the dark X card. */
function nl_embed_html(string $line, bool $dark = false, bool $admin = false): ?string
{
    $e = nl_embed_detect($line);
    if (!$e) return null;
    $media = ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen';
    switch ($e['type']) {
        case 'youtube':
            $src = isset($e['list']) ? 'https://www.youtube-nocookie.com/embed/videoseries?list=' . $e['list'] . '&rel=0'
                : 'https://www.youtube-nocookie.com/embed/' . $e['id'] . '?rel=0' . ($e['start'] > 0 ? '&start=' . $e['start'] : '');
            return nl_embed_frame('youtube', 'embeddedyoutube', $src, 'YouTube', $media);
        case 'nicovideo':
            return nl_embed_frame('nicovideo', 'embeddednicovideo', 'https://embed.nicovideo.jp/watch/' . $e['id'], 'ニコニコ動画', ' allow="autoplay; fullscreen" allowfullscreen');
        case 'spotify':
            $tall = in_array($e['kind'], ['album', 'playlist', 'artist', 'show'], true);
            return nl_embed_frame('spotify', 'embeddedspotify' . ($tall ? ' log-embed-tall' : ''), 'https://open.spotify.com/embed/' . $e['kind'] . '/' . $e['id'], 'Spotify', ' allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"');
        case 'applemusic':
            return nl_embed_frame('applemusic', 'embeddedapplemusic' . ($e['song'] ? ' log-embed-song' : ''), 'https://embed.music.apple.com' . $e['path'] . ($e['i'] !== '' ? '?i=' . $e['i'] : ''), 'Apple Music',
                ' allow="autoplay *; encrypted-media *; fullscreen *; clipboard-write" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-storage-access-by-user-activation allow-top-navigation-by-user-activation"');
        case 'amazonmusic':
            return nl_embed_frame('amazonmusic', 'embeddedamazonmusic' . ($e['kind'] === 'track' ? '' : ' log-embed-tall'),
                'https://music.amazon.co.jp/embed/' . $e['id'] . '/?marketplaceId=A1VC38T7YXB528&musicTerritory=JP', 'Amazon Music');
        case 'soundcloud':
            $url = 'https://soundcloud.com/' . $e['user'] . '/' . ($e['kind'] === 'playlist' ? 'sets/' : '') . $e['slug'];
            return nl_embed_frame('soundcloud', 'embeddedsoundcloud' . ($e['kind'] === 'playlist' ? ' log-embed-tall' : ''),
                'https://w.soundcloud.com/player/?url=' . rawurlencode($url) . '&auto_play=false&visual=false&show_comments=false', 'SoundCloud', ' scrolling="no" allow="autoplay; encrypted-media"');
        case 'tweet':
            $href = 'https://twitter.com/' . $e['user'] . '/status/' . $e['id'];
            return '<span class="log-embed embeddedtweet" data-embed="tweet"><blockquote class="twitter-tweet" data-dnt="true"' . ($dark ? ' data-theme="dark"' : '') . '><a href="' . h($href) . '" rel="noopener noreferrer">X（Twitter）で見る</a></blockquote></span>';
        case 'instagram':
            $href = 'https://www.instagram.com/' . $e['kind'] . '/' . $e['id'] . '/';
            return '<span class="log-embed embeddedinstagram" data-embed="instagram"><blockquote class="instagram-media" data-instgrm-permalink="' . h($href) . '" data-instgrm-version="14"><a href="' . h($href) . '" rel="noopener noreferrer">Instagramで見る</a></blockquote></span>';
        case 'codepen':
            return nl_embed_frame('codepen', 'embeddedcodepen', 'https://codepen.io/' . $e['user'] . '/embed/' . $e['id'] . '?default-tab=html%2Cresult', 'CodePen', ' allowfullscreen');
        case 'note':
            return '<span class="log-embed embeddednote" data-embed="note"><iframe class="note-embed" src="https://note.com/embed/notes/' . $e['id'] . '" title="note" loading="lazy" height="400"></iframe></span>';
        case 'voicy':
            return nl_embed_frame('voicy', 'embeddedvoicy', 'https://voicy.jp/embed/channel/' . $e['id'], 'Voicy', ' scrolling="no"');
        case 'steam':
            return nl_embed_frame('steam', 'embeddedsteam', 'https://store.steampowered.com/widget/' . $e['id'] . '/', 'Steam');
        case 'gigaviewer':
            return nl_embed_frame('gigaviewer', 'embeddedgigaviewer', 'https://' . $e['host'] . '/episode/' . $e['id'] . '/embed', 'コミック', ' scrolling="no" allowfullscreen');
        case 'video':
            return '<span class="log-embed log-embed-video" data-embed="video"><video class="embeddedvideo" controls preload="metadata" playsinline><source src="' . h($e['url']) . '"><a href="' . h($e['url']) . '" rel="noopener noreferrer">動画を開く</a></video></span>';
    }
    return nl_embed_extra_html($e, $dark, $admin);
}
