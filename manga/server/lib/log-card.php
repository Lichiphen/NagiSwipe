<?php
/*
 * LOG blog cards: a URL alone on its line (not a known embed service) shows the page's OGP as a card.
 * Pages are fetched only when the owner saves or previews a post, never on a visitor's page view.
 * The fetch refuses internal addresses (SSRF), pins the checked IP, and limits size, time and redirects.
 * The card image is copied and re-encoded here, so visitors never load it from the other site.
 * MIT License (c) 2026 Lichiphen.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_CARD_TTL = 7 * 86400;          // refresh a card when the post is saved a week later
const NL_CARD_RETRY = 86400;            // a failed page is tried again the next day
const NL_CARD_MAX_PER_SAVE = 10;
const NL_CARD_BUDGET = 20;              // seconds for all cards of one save
const NL_CARD_HTML_MAX = 1024 * 1024;   // the <head> is near the top; the rest is not needed
const NL_CARD_IMAGE_MAX = 8 * 1024 * 1024;

function nl_card_key(string $url): string { return substr(hash('sha256', $url), 0, 20); }
function nl_card_dir(string $key): string { return nl_root() . '/cards/' . $key; }
function nl_card_load(string $url): ?array
{
    $c = nl_read_record(nl_card_dir(nl_card_key($url)) . '/card.php');
    return is_array($c) && ($c['url'] ?? '') === $url ? $c : null;
}

/** Own-line URLs in a body that are neither embeds nor already fresh cards. */
function nl_card_targets(string $body): array
{
    preg_match_all('/' . NL_EMBED_PATTERN . '/i', str_replace("\r", '', $body), $m);
    $out = [];
    foreach ($m[0] as $line) {
        $url = trim($line);
        if (nl_embed_detect($url) || !filter_var($url, FILTER_VALIDATE_URL) || isset($out[$url])) continue;
        $c = nl_card_load($url);
        if ($c && time() - (int)$c['fetched'] < ($c['ok'] ? NL_CARD_TTL : NL_CARD_RETRY)) continue;
        $out[$url] = true;
        if (count($out) >= NL_CARD_MAX_PER_SAVE) break;
    }
    return array_keys($out);
}

/** Fetch and store cards for a body before it is saved or previewed. Failures only mean "no card". */
function nl_cards_prepare(string $body): void
{
    $deadline = microtime(true) + NL_CARD_BUDGET;
    foreach (nl_card_targets($body) as $url) {
        if (microtime(true) > $deadline) break;
        try { $card = nl_card_fetch($url, $deadline); }
        catch (Throwable) { $card = null; }
        $key = nl_card_key($url);
        $dir = nl_card_dir($key);
        $old = nl_card_load($url);
        $record = $card ?? ['url' => $url, 'ok' => false, 'fetched' => time()];
        nm_with_lock('log-cards', static function () use ($dir, $record, $old) {
            nm_ensure_dir($dir);
            nl_write_record($dir . '/card.php', $record);
            $oldFile = $old['image']['f'] ?? '';
            if ($oldFile !== '' && $oldFile !== ($record['image']['f'] ?? '')) nm_delete_page_files($dir, $oldFile);
        });
    }
}

function nl_card_fetch(string $url, float $deadline): ?array
{
    $res = nl_http_get($url, NL_CARD_HTML_MAX, 'text/html,application/xhtml+xml', $deadline, true);
    if (!$res || !preg_match('~\A(text/html|application/xhtml\+xml)~i', $res['type'])) return null;
    $meta = nl_card_parse($res['body'], $res['type'], $res['url']);
    if ($meta['title'] === '') return null;
    $card = ['url' => $url, 'final' => $res['url'], 'title' => $meta['title'], 'desc' => $meta['desc'], 'site' => $meta['site'], 'image' => null, 'ok' => true, 'fetched' => time()];
    if ($meta['image'] !== '' && microtime(true) < $deadline) {
        $img = nl_http_get($meta['image'], NL_CARD_IMAGE_MAX, 'image/*', $deadline, false);
        if ($img) {
            $tmp = tempnam(sys_get_temp_dir(), 'nlc');
            file_put_contents($tmp, $img['body']);
            $cfg = nm_config();
            $page = nm_import_image($tmp, nl_card_dir(nl_card_key($url)), 1, (int)($cfg['image_quality'] ?? 90), NL_CARD_IMAGE_MAX, true, false);
            @unlink($tmp);
            if (is_array($page)) $card['image'] = $page;
        }
    }
    return $card;
}

/** Title, description, site name and image URL from OGP (falling back to <title> and meta description). */
function nl_card_parse(string $html, string $type, string $base): array
{
    $charset = preg_match('/charset=["\']?([\w-]+)/i', $type, $m) ? $m[1] : (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m) ? $m[1] : 'UTF-8');
    // Japanese sites often say Shift_JIS / EUC-JP but use the Windows supersets.
    if (preg_match('/\A(?:shift[_-]?jis|sjis|x-sjis|windows-31j|cp932)\z/i', $charset)) $charset = 'SJIS-win';
    elseif (preg_match('/\Aeuc-?jp\z/i', $charset)) $charset = 'eucJP-win';
    if (strcasecmp($charset, 'UTF-8') !== 0) {
        try { $html = (string)mb_convert_encoding($html, 'UTF-8', $charset); }
        catch (ValueError) { /* unknown charset: keep the bytes and let the UTF-8 check clean them */ }
    }
    if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    // The text is UTF-8 now: stop libxml from decoding it again with the page's own charset.
    $html = (string)preg_replace('/<meta\b(?=[^>]*\bcharset\s*=)[^>]*>/i', '', $html);
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $meta = [];
    foreach ($doc->getElementsByTagName('meta') as $el) {
        $name = strtolower(trim($el->getAttribute('property') ?: $el->getAttribute('name')));
        if ($name !== '' && !isset($meta[$name])) $meta[$name] = trim($el->getAttribute('content'));
    }
    $titleTag = $doc->getElementsByTagName('title')->item(0)?->textContent ?? '';
    $clean = static fn(string $s, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s))), 0, $max);
    $image = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['og:image:secure_url'] ?? $meta['twitter:image'] ?? '';
    return [
        'title' => $clean($meta['og:title'] ?? $meta['twitter:title'] ?? $titleTag, 200),
        'desc' => $clean($meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? '', 300),
        'site' => $clean($meta['og:site_name'] ?? (string)parse_url($base, PHP_URL_HOST), 100),
        'image' => $image !== '' ? (nl_url_resolve($base, $image) ?? '') : '',
    ];
}

function nl_url_resolve(string $base, string $ref): ?string
{
    $ref = trim($ref);
    if (preg_match('~\Ahttps?://~i', $ref)) $url = $ref;
    elseif (str_starts_with($ref, '//')) $url = (string)parse_url($base, PHP_URL_SCHEME) . ':' . $ref;
    else {
        $b = parse_url($base);
        if (!$b || empty($b['host'])) return null;
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '/')) $url = $origin . $ref;
        else $url = $origin . preg_replace('~[^/]*\z~', '', $b['path'] ?? '/') . $ref;
    }
    return filter_var($url, FILTER_VALIDATE_URL) && preg_match('~\Ahttps?://~i', $url) ? $url : null;
}

/** Public, routable address only: no loopback, private, link-local, CGNAT, reserved or mapped ranges. */
function nl_ip_public(string $ip): bool
{
    if (getenv('NAGIMANGA_CARD_ALLOW_LOCAL') === '1' && in_array($ip, ['127.0.0.1', '::1'], true)) return true; // development tests only
    if (preg_match('/\A::ffff:(\d+\.\d+\.\d+\.\d+)\z/i', $ip, $m)) $ip = $m[1];
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['0.0.0.0', 8]] as [$net, $bits]) {
            if ((($n ^ ip2long($net)) >> (32 - $bits)) === 0) return false;
        }
    } elseif (preg_match('/\A(?:64:ff9b|2002|2001:0?db8|fec0)/i', $ip)) return false;
    return true;
}

/** Resolve a host and return one public address, or null if any address is not public. */
function nl_resolve_public(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP)) return nl_ip_public($host) ? $host : null;
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) return nl_ip_public($host) ? $host : null;
    $ips = @gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    if (!$ips) return null;
    foreach ($ips as $ip) if (!nl_ip_public($ip)) return null;
    return $ips[0];
}

/**
 * Minimal HTTP GET with the connection pinned to a checked public IP.
 * @return array{body:string,type:string,url:string}|null
 */
function nl_http_get(string $url, int $max, string $accept, float $deadline, bool $truncate): ?array
{
    for ($hop = 0; $hop <= 3; $hop++) {
        $u = parse_url($url);
        if (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) return null;
        $https = strtolower($u['scheme']) === 'https';
        $port = (int)($u['port'] ?? ($https ? 443 : 80));
        if (!in_array($port, [80, 443], true) && getenv('NAGIMANGA_CARD_ALLOW_LOCAL') !== '1') return null;
        $host = strtolower($u['host']);
        $ip = nl_resolve_public($host);
        if ($ip === null) return null;
        $left = $deadline - microtime(true);
        if ($left <= 0) return null;
        $ctx = stream_context_create(['ssl' => ['peer_name' => trim($host, '[]'), 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $target = ($https ? 'ssl' : 'tcp') . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, min(6.0, $left), STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return null;
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        $hostHeader = $host . (isset($u['port']) ? ':' . $port : '');
        fwrite($fp, "GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUser-Agent: Mozilla/5.0 (compatible; NagiLog-Card/1.0)\r\nAccept: $accept\r\nAccept-Encoding: identity\r\nAccept-Language: ja,en;q=0.8\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp) && strlen($raw) < $max + 16384) {
            $left = $deadline - microtime(true);
            if ($left <= 0) break;
            stream_set_timeout($fp, max(1, (int)ceil(min(6.0, $left))));
            $chunk = fread($fp, 16384);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($fp)['timed_out'])) break;
            $raw .= $chunk;
        }
        fclose($fp);
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false || !preg_match('~\AHTTP/1\.[01] (\d{3})~', $raw, $sm)) return null;
        $status = (int)$sm[1];
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $split)) as $line) if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
        $body = substr($raw, $split + 4);
        if (in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
            $next = nl_url_resolve($url, $headers['location']);
            if ($next === null) return null;
            $url = $next;
            continue;
        }
        if ($status !== 200) return null;
        if (stripos($headers['transfer-encoding'] ?? '', 'chunked') !== false) $body = nl_http_dechunk($body);
        if (strlen($body) > $max) {
            if (!$truncate) return null;
            $body = substr($body, 0, $max);
        }
        if (stripos($headers['content-encoding'] ?? '', 'gzip') !== false) $body = (string)@gzdecode($body);
        return ['body' => $body, 'type' => $headers['content-type'] ?? '', 'url' => $url];
    }
    return null;
}

function nl_http_dechunk(string $in): string
{
    $out = ''; $pos = 0;
    while (($eol = strpos($in, "\r\n", $pos)) !== false) {
        $size = hexdec(trim(explode(';', substr($in, $pos, $eol - $pos))[0]));
        if ($size <= 0) break;
        $out .= substr($in, $eol + 2, (int)$size);
        $pos = $eol + 2 + (int)$size + 2;
    }
    return $out;
}

/** Card HTML for a fetched page, or null to fall back to a plain link. */
function nl_card_html(string $url, bool $admin = false): ?string
{
    $c = nl_card_load($url);
    if (!$c || empty($c['ok'])) return null;
    $key = nl_card_key($url);
    $img = '';
    if (is_array($c['image'] ?? null) && preg_match(NM_PAGE_PATTERN, (string)($c['image']['f'] ?? ''))) {
        $img = '<img class="log-card-image" src="' . h(($admin ? '../' : './') . '?card=' . $key . '&v=' . (int)$c['fetched']) . '" alt="" loading="lazy" width="' . (int)$c['image']['w'] . '" height="' . (int)$c['image']['h'] . '">';
    }
    $host = (string)parse_url($url, PHP_URL_HOST);
    return '<a class="log-card' . ($img === '' ? ' log-card-noimage' : '') . '" href="' . h($url) . '" rel="noopener noreferrer"><span class="log-card-text"><strong>' . h($c['title']) . '</strong>'
        . ($c['desc'] !== '' ? '<small>' . h($c['desc']) . '</small>' : '') . '<span class="log-card-site">' . h($c['site'] !== '' ? $c['site'] : $host) . '</span></span>' . $img . '</a>';
}

/** The stored card image (its small version). */
function nl_serve_card(string $key): never
{
    if (!preg_match('/\A[a-f0-9]{20}\z/', $key)) nm_not_found();
    nm_image_guard(nm_config());
    $c = nl_read_record(nl_card_dir($key) . '/card.php');
    $f = is_array($c) ? (string)($c['image']['f'] ?? '') : '';
    if (!preg_match(NM_PAGE_PATTERN, $f) || nl_card_key((string)($c['url'] ?? '')) !== $key) nm_not_found();
    $file = nl_card_dir($key) . '/t_' . $f;
    if (!is_file($file)) nm_not_found();
    header('Content-Type: ' . nm_image_mime($f));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') readfile($file);
    exit;
}
