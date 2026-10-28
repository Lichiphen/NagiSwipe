<?php
/**
 * Search engines and access analytics on the public pages: the ownership checks of the search consoles
 * (meta tags) and an analytics tag (Matomo, Google Analytics and the like).
 * The owner pastes the service's tag; it is checked when saved and again before every page uses it,
 * so code that could harm visitors never reaches them. Only script tags pass; inline code is allowed in the
 * page's Content-Security-Policy by its hash, and the outside sites the tag names (plus the ones the known
 * services call afterwards) are allowed for scripts, connections and pictures only.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */

declare(strict_types=1);

if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NM_ANALYTICS_MAX = 20000;
const NM_ANALYTICS_MAX_SCRIPTS = 8;
/** Ownership checks: the setting, its meta tag name, the label and the form of its code. */
const NM_SEARCH_VERIFY = [
    'verify_google' => ['meta' => 'google-site-verification', 'label' => 'Google Search Console', 'pattern' => '~\A[A-Za-z0-9_-]{20,100}\z~'],
    'verify_bing' => ['meta' => 'msvalidate.01', 'label' => 'Bing Webmaster Tools', 'pattern' => '~\A[A-Za-z0-9]{16,64}\z~'],
    'verify_yandex' => ['meta' => 'yandex-verification', 'label' => 'Yandex Webmaster', 'pattern' => '~\A[A-Za-z0-9]{8,50}\z~'],
    'verify_naver' => ['meta' => 'naver-site-verification', 'label' => 'Naver Search Advisor', 'pattern' => '~\A[A-Za-z0-9]{20,120}\z~'],
    'verify_baidu' => ['meta' => 'baidu-site-verification', 'label' => '百度（Baidu）站长平台', 'pattern' => '~\A[A-Za-z0-9_-]{8,60}\z~'],
];

/**
 * The code of an ownership check from what was pasted: the whole meta tag, "name=code" (the DNS record form) or the code.
 * @throws UnexpectedValueException when it is not a code of that service
 */
function nm_search_verify_code(string $raw, string $key): string
{
    $v = NM_SEARCH_VERIFY[$key];
    $raw = trim($raw);
    if ($raw === '') return '';
    if (preg_match('~<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']*)["\']~i', $raw, $m)) {
        if (!preg_match('~\bname\s*=\s*["\']' . preg_quote($v['meta'], '~') . '["\']~i', $raw)) throw new UnexpectedValueException(nm_t('{service} の meta タグではありません（name が {name} のタグを貼ってください）', ['service' => $v['label'], 'name' => $v['meta']]));
        $raw = trim($m[1]);
    }
    if (str_starts_with($raw, $v['meta'] . '=')) $raw = substr($raw, strlen($v['meta']) + 1);
    if (!preg_match($v['pattern'], $raw)) throw new UnexpectedValueException(nm_t('{service} の確認コードが正しくありません', ['service' => $v['label']]));
    return $raw;
}

/** The meta tags of the ownership checks, for the head of the LOG pages. */
function nm_search_verify_meta(): string
{
    $cfg = nm_config() ?? [];
    $html = '';
    foreach (NM_SEARCH_VERIFY as $key => $v) {
        $code = (string)($cfg[$key] ?? '');
        if ($code !== '' && preg_match($v['pattern'], $code)) $html .= '<meta name="' . $v['meta'] . '" content="' . h($code) . '">';
    }
    return $html;
}

/** Hosts the known services call after their first script: [script-src, connect-src and img-src]. */
const NM_ANALYTICS_EXTRA = [
    'googletagmanager.com' => [['https://*.googletagmanager.com'], ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com', 'https://*.g.doubleclick.net']],
    'google-analytics.com' => [[], ['https://*.google-analytics.com', 'https://*.analytics.google.com']],
    'clarity.ms' => [['https://*.clarity.ms'], ['https://*.clarity.ms', 'https://c.bing.com']],
    'cloudflareinsights.com' => [[], ['https://cloudflareinsights.com']],
];

/**
 * What would harm the people who read the pages, as [pattern, reason]. A tag from an analytics service only
 * loads the service's script and passes it a few settings, so none of this has a place in it.
 * @return list<array{0: string, 1: string}>
 */
function nm_analytics_rules(): array
{
    return [
        ['~document\s*\.\s*cookie~', nm_t('Cookie を読み書きするコードは入れられません')],
        ['~\b(?:localStorage|sessionStorage|indexedDB)\b~', nm_t('ブラウザーの保存データを読み書きするコードは入れられません')],
        ['~navigator\s*\.\s*(?:credentials|clipboard|serviceWorker|geolocation|mediaDevices|sendBeacon)\b~', nm_t('パスワード・クリップボード・位置情報などを扱うコードは入れられません')],
        ['~\beval\s*\(|\bFunction\s*\(|\bset(?:Timeout|Interval)\s*\(\s*[\'"`]|\bimport\s*\(~', nm_t('文字列をコードとして実行するコードは入れられません')],
        ['~\batob\s*\(|fromCharCode|\bunescape\s*\(|\\\\x[0-9a-fA-F]{2}|\\\\u\{?[0-9a-fA-F]{2,}|[A-Za-z0-9+/=]{120,}~', nm_t('中身を読めなくした（難読化した）コードは入れられません')],
        ['~(?<![\w$.])location\s*=(?!=)|\.location\s*=(?!=)|location\s*\.\s*(?:href|hash|search|pathname)\s*=(?!=)|location\s*\.\s*(?:replace|assign|reload)\s*\(|\bwindow\s*\.\s*open\s*\(|\btop\s*\.\s*location~', nm_t('ほかのページへ移動させるコードは入れられません')],
        ['~document\s*\.\s*write(?:ln)?\s*\(|\.(?:innerHTML|outerHTML)\s*=(?!=)|insertAdjacentHTML|createContextualFragment|\bDOMParser\b~', nm_t('ページの中身を書き換えるコードは入れられません')],
        ['~\bXMLHttpRequest\b|\bfetch\s*\(|\bWebSocket\b|\bEventSource\b|\bRTCPeerConnection\b|new\s+Image\b~', nm_t('自分で通信するコードは入れられません（送信は計測サービスのスクリプトに任せてください）')],
        ['~addEventListener\s*\(\s*[\'"`](?:key\w*|input|change|submit|paste|copy|cut|beforeinput)[\'"`]|\.on(?:key\w+|input|change|submit|paste|copy|cut)\s*=(?!=)~', nm_t('キー入力やフォームの送信を見張るコードは入れられません')],
        ['~(?:querySelector(?:All)?|getElementsBy\w+|getElementById)\s*\(\s*[\'"`][^\'"`]*(?:input|password|textarea|form|select)~i', nm_t('フォームの入力欄を読むコードは入れられません')],
        ['~\bWebAssembly\b|new\s+(?:Shared)?Worker\b|importScripts|coin-?hive|authedmine|cryptonight|coinimp|crypto-?loot|webminepool|monero~i', nm_t('閲覧者のパソコンで計算させる（マイニングなどの）コードは入れられません')],
        ['~javascript\s*:|data\s*:\s*[a-z]+/|blob\s*:~i', nm_t('javascript: や data: の URL は入れられません')],
    ];
}

/**
 * Checks a pasted tag and takes it apart. An empty text gives no scripts.
 * @return array{scripts: list<array{src: string, attrs: string, body: string}>, script: list<string>, connect: list<string>}
 * @throws UnexpectedValueException with the reason, for the settings screen
 */
function nm_analytics_parse(string $code): array
{
    $none = ['scripts' => [], 'script' => [], 'connect' => []];
    // The browser hashes inline code with its line breaks as \n.
    $code = trim(str_replace(["\r\n", "\r"], "\n", $code));
    if ($code === '') return $none;
    if (strlen($code) > NM_ANALYTICS_MAX || !mb_check_encoding($code, 'UTF-8')) throw new UnexpectedValueException(nm_t('アクセス解析のコードは2万文字以内にしてください'));
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $code)) throw new UnexpectedValueException(nm_t('見えない文字（制御文字）が含まれています。コードを貼り直してください'));
    foreach (nm_analytics_rules() as [$pattern, $reason]) if (preg_match($pattern, $code)) throw new UnexpectedValueException($reason);

    // HTML comments and noscript blocks (pictures or frames for browsers without JavaScript) are left out.
    $rest = preg_replace(['~<!--.*?-->~s', '~<noscript\b[^>]*>.*?</noscript\s*>~is'], '', $code);
    preg_match_all('~<script\b([^>]*)>(.*?)</script\s*>~is', $rest, $found, PREG_SET_ORDER);
    $outside = trim(preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $rest));
    if (preg_match('~<[a-z!/]~i', $outside)) throw new UnexpectedValueException(nm_t('script タグ以外（iframe・img・link など）は入れられません'));
    if (!$found || $outside !== '') throw new UnexpectedValueException(nm_t('計測サービスに表示されたコードを、script タグごとそのまま貼ってください'));
    if (count($found) > NM_ANALYTICS_MAX_SCRIPTS) throw new UnexpectedValueException(nm_t('script タグは{n}個までにしてください', ['n' => NM_ANALYTICS_MAX_SCRIPTS]));

    $scripts = []; $hosts = [];
    foreach ($found as [, $rawAttrs, $body]) {
        $src = ''; $attrs = '';
        preg_match_all('~([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $rawAttrs, $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $name = strtolower($pair[1]);
            $value = html_entity_decode(($pair[2] ?? '') . ($pair[3] ?? '') . ($pair[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($name, 'on')) throw new UnexpectedValueException(nm_t('script タグに on で始まる属性（{name}）は付けられません', ['name' => $name]));
            if ($name === 'src') { $src = $value; continue; }
            if ($name === 'type' && !in_array(strtolower($value), ['', 'text/javascript', 'application/javascript'], true)) throw new UnexpectedValueException(nm_t('script タグの type は text/javascript だけにしてください'));
            if (in_array($name, ['async', 'defer'], true)) { $attrs .= ' ' . $name; continue; }
            if (in_array($name, ['id', 'crossorigin', 'referrerpolicy', 'integrity'], true) || preg_match('~\Adata-[a-z0-9-]+\z~', $name)) { $attrs .= ' ' . $name . '="' . h($value) . '"'; continue; }
            if ($name !== 'type' && $name !== 'charset') throw new UnexpectedValueException(nm_t('script タグに {name} 属性は付けられません', ['name' => $name]));
        }
        if ($src !== '') {
            if (trim($body) !== '') throw new UnexpectedValueException(nm_t('src のある script タグの中には、コードを書けません'));
            $hosts[] = nm_analytics_host($src, true);
            if (str_starts_with($src, '//')) $src = 'https:' . $src;
        } elseif (trim($body) === '') {
            continue;
        } elseif (preg_match('~<!--|<script|</~i', $body)) {
            throw new UnexpectedValueException(nm_t('script タグの中に、別のタグを書くことはできません'));
        }
        // Every address written in the code: the tracker's server (Matomo's var u="…") and its script.
        if ($src === '') foreach (preg_match_all('~(?:https?:)?//[^\s\'"`)\\\\]+~i', $body, $urls) ? $urls[0] : [] as $url) $hosts[] = nm_analytics_host($url, false);
        $scripts[] = ['src' => $src, 'attrs' => $attrs, 'body' => $src === '' ? $body : ''];
    }
    if (!$scripts) return $none;

    $origins = array_values(array_unique(array_filter($hosts)));
    $script = $origins; $connect = $origins;
    foreach ($origins as $origin) {
        $host = substr($origin, 8);
        foreach (NM_ANALYTICS_EXTRA as $domain => [$moreScript, $moreConnect]) {
            if ($host !== $domain && !str_ends_with($host, '.' . $domain)) continue;
            $script = [...$script, ...$moreScript]; $connect = [...$connect, ...$moreConnect];
        }
    }
    return ['scripts' => $scripts, 'script' => array_values(array_unique($script)), 'connect' => array_values(array_unique($connect))];
}

/** https://host[:port] of an address in a tag; a script must come over https. */
function nm_analytics_host(string $url, bool $script): string
{
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if (($p['scheme'] ?? '') === 'http') {
        if ($script) throw new UnexpectedValueException(nm_t('スクリプトは https:// の URL から読み込んでください（{url}）', ['url' => $url]));
        return '';
    }
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || !preg_match('~\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\z~', $host)) {
        if ($script) throw new UnexpectedValueException(nm_t('読み込むスクリプトの URL が正しくありません（{url}）', ['url' => $url]));
        return '';
    }
    return 'https://' . $host . (isset($p['port']) ? ':' . (int)$p['port'] : '');
}

/** The saved tag, checked again (a newer version may know more), or nothing. */
function nm_analytics(): array
{
    static $parsed = null;
    if ($parsed !== null) return $parsed;
    try {
        return $parsed = nm_analytics_parse((string)((nm_config() ?? [])['analytics'] ?? ''));
    } catch (UnexpectedValueException) {
        return $parsed = nm_analytics_parse('');
    }
}

/** Sources to add to a page's Content-Security-Policy: ['script' => " https://… 'sha256-…'", 'connect' => …, 'img' => …]. */
function nm_analytics_csp(bool $on = true): array
{
    $a = nm_analytics();
    if (!$on || !$a['scripts']) return ['script' => '', 'connect' => '', 'img' => ''];
    $hashes = array_map(static fn($s) => "'sha256-" . base64_encode(hash('sha256', $s['body'], true)) . "'", array_filter($a['scripts'], static fn($s) => $s['src'] === ''));
    $join = static fn(array $list) => $list ? ' ' . implode(' ', $list) : '';
    return ['script' => $join([...$a['script'], ...$hashes]), 'connect' => $join($a['connect']), 'img' => $join($a['connect'])];
}

/** The tag for the page's head, rebuilt from the checked parts. */
function nm_analytics_html(bool $on = true): string
{
    if (!$on) return '';
    $html = '';
    foreach (nm_analytics()['scripts'] as $s) $html .= $s['src'] !== '' ? '<script src="' . h($s['src']) . '"' . $s['attrs'] . '></script>' : '<script' . $s['attrs'] . '>' . $s['body'] . '</script>';
    return $html;
}
