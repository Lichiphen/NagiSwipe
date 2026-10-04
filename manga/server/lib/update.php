<?php
/*
 * NagiManga self-update from the GitHub releases of Lichiphen/NagiSwipe
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * A release carries nagimanga-vX.Y.Z.zip with the program under "nagimanga/".
 * The package is checked against the SHA-256 GitHub publishes for the asset,
 * every entry is validated, and only program files are replaced: data/, the
 * plugins and lib/paths.php are never touched. The replaced files are kept in
 * data/update/backup-* (as .bak, never runnable) so the update can be undone.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_UPDATE_REPO = 'Lichiphen/NagiSwipe';
const NM_UPDATE_TTL = 43200;                 // check GitHub at most every 12 hours
const NM_UPDATE_RETRY = 3600;                // after a failed check, try again in 1 hour
const NM_UPDATE_ASSET = '/\Anagimanga-v([0-9]+\.[0-9]+\.[0-9]+)\.zip\z/';
const NM_UPDATE_MAX_ZIP = 20 * 1024 * 1024;
const NM_UPDATE_MAX_ENTRY = 5 * 1024 * 1024;
const NM_UPDATE_MAX_TOTAL = 30 * 1024 * 1024;
const NM_UPDATE_KEEP_BACKUPS = 3;
const NM_UPDATE_REQUIRED = ['index.php', 'read.php', 'log.php', 'lib/bootstrap.php', 'admin/index.php', 'viewer/NagiManga.js',
    '404.php', 'viewer/404.css', 'lib/image-guard.php',
    'lib/log.php', 'lib/log-taxonomy.php', 'lib/log-sidebar.php', 'lib/log-admin.php', 'lib/log-public.php', 'lib/log-backup.php', 'admin/login.php', 'admin/log-editor.js', 'admin/log-settings.js', 'admin/log-manage.js', 'viewer/log.css', 'viewer/log-menu.js',
    'viewer/NagiSwipe-main.js', 'viewer/NagiSwipe-main.css'];
const NM_UPDATE_EXT = ['php', 'js', 'css', 'html', 'txt', 'json', 'svg', 'png', 'jpg', 'webp', 'ico', 'woff2'];

/**
 * Development only: NAGIMANGA_UPDATE_API (release list URL) and NAGIMANGA_UPDATE_DL
 * (allowed download prefix) point the updater at a local mock. Environment
 * variables cannot be set from the web.
 */
function nm_update_dev(): bool
{
    return (string)getenv('NAGIMANGA_UPDATE_API') !== '';
}

function nm_update_api_url(): string
{
    return nm_update_dev()
        ? (string)getenv('NAGIMANGA_UPDATE_API')
        : 'https://api.github.com/repos/' . NM_UPDATE_REPO . '/releases?per_page=20';
}

function nm_update_download_prefix(): string
{
    return nm_update_dev()
        ? (string)getenv('NAGIMANGA_UPDATE_DL')
        : 'https://github.com/' . NM_UPDATE_REPO . '/releases/download/';
}

function nm_update_auto(array $cfg): bool
{
    return ($cfg['update_check'] ?? true) !== false;
}

/** How this server can talk to GitHub ('' = it cannot). */
function nm_update_transport(): string
{
    if (function_exists('curl_init')) return 'curl';
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && in_array('https', stream_get_wrappers(), true)) return 'stream';
    return '';
}

/**
 * GET with a size cap. Returns [status, body]; throws on network errors.
 * Only https (plain http only for the local mock in development).
 */
function nm_update_http(string $url, int $max, int $timeout, bool $json): array
{
    $dev = nm_update_dev();
    if (!preg_match($dev ? '~\Ahttps?://~' : '~\Ahttps://~', $url)) throw new RuntimeException('bad url');
    $headers = [
        'User-Agent: NagiManga/' . NM_VERSION,
        'Accept: ' . ($json ? 'application/vnd.github+json' : 'application/octet-stream'),
    ];
    $transport = nm_update_transport();
    if ($transport === 'curl') {
        $body = '';
        $tooBig = false;
        $proto = $dev ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => $proto,
            CURLOPT_REDIR_PROTOCOLS => $proto,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($tooBig) throw new RuntimeException('too large');
        if ($ok === false) throw new RuntimeException('network: ' . $err);
        return [$status, $body];
    }
    if ($transport === 'stream') {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout,
                       'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $fh = @fopen($url, 'rb', false, $ctx);
        if ($fh === false) throw new RuntimeException('network');
        $body = (string)stream_get_contents($fh, $max + 1);
        $meta = stream_get_meta_data($fh);
        fclose($fh);
        if (strlen($body) > $max) throw new RuntimeException('too large');
        $status = 0;
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('~\AHTTP/\S+\s+([0-9]{3})~', (string)$line, $m)) $status = (int)$m[1];
        }
        return [$status, $body];
    }
    throw new RuntimeException('no transport');
}

function nm_update_cache_file(): string
{
    return NM_DATA . '/update/check.json';
}

/** Last known status without touching the network. */
function nm_update_cached(): ?array
{
    $st = nm_read_json(nm_update_cache_file());
    return ($st && ($st['current'] ?? '') === NM_VERSION) ? $st : null;
}

function nm_update_available(?array $st): bool
{
    return $st !== null && !empty($st['latest']) && version_compare((string)$st['latest'], NM_VERSION, '>');
}

/**
 * Newest release that ships a NagiManga package. Cached for NM_UPDATE_TTL;
 * $force asks GitHub now.
 */
function nm_update_status(bool $force = false): array
{
    $cache = nm_update_cached();
    if (!$force && $cache && time() - (int)($cache['checked'] ?? 0) < NM_UPDATE_TTL) return $cache;

    $st = ['checked' => time(), 'current' => NM_VERSION, 'latest' => null, 'error' => ''];
    try {
        if (nm_update_transport() === '') throw new RuntimeException('no transport');
        [$status, $body] = nm_update_http(nm_update_api_url(), 2 * 1024 * 1024, 8, true);
        if ($status !== 200) throw new RuntimeException('status ' . $status);
        $list = json_decode($body, true);
        if (!is_array($list)) throw new RuntimeException('bad json');
        $best = nm_update_pick($list);
        if ($best) $st = array_merge($st, $best);
    } catch (Throwable $e) {
        $st['error'] = $e->getMessage() === 'no transport'
            ? 'このサーバーからは GitHub に接続できません（PHP の curl も allow_url_fopen も使えません）'
            : 'GitHub に接続できませんでした。しばらくしてからもう一度お試しください';
        // Keep what we knew, and try again sooner than usual
        if ($cache && !empty($cache['latest'])) {
            foreach (['latest', 'tag', 'notes', 'published', 'url', 'size', 'digest', 'page'] as $k) {
                if (isset($cache[$k])) $st[$k] = $cache[$k];
            }
        }
        $st['checked'] = time() - NM_UPDATE_TTL + NM_UPDATE_RETRY;
        nm_log('update_check_failed', $e->getMessage());
    }
    try {
        nm_write_json(nm_update_cache_file(), $st);
    } catch (Throwable) {
        // A read-only data folder only costs a fresh check next time
    }
    return $st;
}

/** The newest non-draft, non-prerelease release with a valid package asset. */
function nm_update_pick(array $releases): ?array
{
    $best = null;
    $prefix = nm_update_download_prefix();
    foreach ($releases as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])) continue;
        foreach ((array)($rel['assets'] ?? []) as $a) {
            if (!is_array($a) || !preg_match(NM_UPDATE_ASSET, (string)($a['name'] ?? ''), $m)) continue;
            $url = (string)($a['browser_download_url'] ?? '');
            if ($prefix === '' || !str_starts_with($url, $prefix)) continue;
            if ($best && version_compare($m[1], $best['latest'], '<=')) continue;
            $page = (string)($rel['html_url'] ?? '');
            $best = [
                'latest' => $m[1],
                'tag' => mb_substr((string)($rel['tag_name'] ?? ''), 0, 40),
                'notes' => mb_substr((string)($rel['body'] ?? ''), 0, 6000),
                'published' => mb_substr((string)($rel['published_at'] ?? ''), 0, 30),
                'url' => $url,
                'size' => (int)($a['size'] ?? 0),
                'digest' => (string)($a['digest'] ?? ''),
                'page' => str_starts_with($page, 'https://github.com/' . NM_UPDATE_REPO . '/') ? $page : '',
            ];
        }
    }
    return $best;
}

/** 'ok' = install, 'skip' = leave alone, 'bad' = the package must not contain this. */
function nm_update_rel_kind(string $rel): string
{
    if ($rel === '' || strlen($rel) > 200) return 'bad';
    $segs = explode('/', $rel);
    foreach ($segs as $s) {
        if (!preg_match('/\A(?:\.htaccess|[A-Za-z0-9_][A-Za-z0-9._-]*)\z/', $s)) return 'bad';
    }
    $last = end($segs);
    if ($last !== '.htaccess' && !in_array(strtolower(pathinfo($last, PATHINFO_EXTENSION)), NM_UPDATE_EXT, true)) return 'bad';
    // Works, settings and plugins belong to the site; only the guard files are ours
    if ($segs[0] === 'data' || $segs[0] === 'plugins') {
        return count($segs) === 2 && ($last === '.htaccess' || $last === 'index.html') ? 'ok' : 'skip';
    }
    if ($rel === 'lib/paths.php') return 'skip';
    return 'ok';
}

/**
 * Validate the package and read the files to install.
 * Returns ['files' => [rel => bytes]] or ['error' => message].
 */
function nm_update_read_package(string $zipPath, string $expect): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) return ['error' => '更新ファイルを開けませんでした'];
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 500) return ['error' => '更新ファイルの中身が正しくありません'];
        $files = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if (!$st) return ['error' => '更新ファイルの中身が正しくありません'];
            $name = (string)$st['name'];
            if (!str_starts_with($name, 'nagimanga/')) continue;   // README, optional plugins
            $rel = substr($name, strlen('nagimanga/'));
            if ($rel === '' || str_ends_with($rel, '/')) continue;
            $kind = nm_update_rel_kind($rel);
            if ($kind === 'bad') return ['error' => '更新ファイルに入っていてはいけないファイルがあります: ' . mb_substr($rel, 0, 80)];
            if ($kind === 'skip') continue;
            if ($st['size'] > NM_UPDATE_MAX_ENTRY || ($total += $st['size']) > NM_UPDATE_MAX_TOTAL) {
                return ['error' => '更新ファイルが大きすぎます'];
            }
            $bytes = nm_zip_read($zip, $i, NM_UPDATE_MAX_ENTRY);
            if ($bytes === null || strlen($bytes) !== (int)$st['size']) return ['error' => '更新ファイルを読めませんでした'];
            $files[$rel] = $bytes;
        }
    } finally {
        $zip->close();
    }
    foreach (NM_UPDATE_REQUIRED as $r) {
        if (!isset($files[$r])) return ['error' => '更新ファイルに必要なファイルがありません: ' . $r];
    }
    if (!preg_match("/const NM_VERSION = '([0-9]+\\.[0-9]+\\.[0-9]+)';/", $files['lib/bootstrap.php'], $m) || $m[1] !== $expect) {
        return ['error' => '更新ファイルのバージョンが一致しません'];
    }
    return ['files' => $files];
}

/** Files that could not be written (the web server needs write access to them). */
function nm_update_unwritable(array $rels): array
{
    $bad = [];
    foreach ($rels as $rel) {
        $path = NM_ROOT . '/' . $rel;
        $dir = dirname($path);
        while (!is_dir($dir) && dirname($dir) !== $dir) $dir = dirname($dir);
        if ((is_file($path) && !is_writable($path)) || !is_writable($dir)) $bad[] = $rel;
    }
    return $bad;
}

/** Program files the updater would replace, for the requirement check on the admin page. */
function nm_update_can_write(): bool
{
    return !nm_update_unwritable(NM_UPDATE_REQUIRED);
}

function nm_update_backup_root(): string
{
    return NM_DATA . '/update';
}

/** Undo points, newest first: [name, from, to, created]. */
function nm_update_backups(): array
{
    $out = [];
    foreach (glob(nm_update_backup_root() . '/backup-*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!preg_match('/\Abackup-[0-9.]+-[0-9]{14}\z/', $name)) continue;
        $meta = nm_read_json($dir . '/nagimanga-update.json');
        if (!$meta) continue;
        $out[] = ['name' => $name, 'from' => (string)($meta['from'] ?? ''), 'to' => (string)($meta['to'] ?? ''), 'created' => (int)($meta['created'] ?? 0)];
    }
    usort($out, static fn($a, $b) => $b['created'] <=> $a['created']);
    return $out;
}

function nm_update_put(string $rel, string $bytes): void
{
    $path = NM_ROOT . '/' . $rel;
    nm_ensure_dir(dirname($path));
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new RuntimeException('write failed: ' . $rel);
    @chmod($tmp, 0644);
    // Never delete the old file first: on Windows the running script (admin/index.php)
    // cannot be renamed over, but it can be overwritten in place
    if (!@rename($tmp, $path)) {
        $ok = @copy($tmp, $path);
        @unlink($tmp);
        if (!$ok || @file_get_contents($path) !== $bytes) throw new RuntimeException('replace failed: ' . $rel);
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
}

/** Download, verify and install the newest release. Returns ['ok' => bool, 'msg' => string]. */
function nm_update_apply(): array
{
    return nm_with_lock('update', static function (): array {
        $st = nm_update_status(true);
        if (!nm_update_available($st)) {
            return ['ok' => false, 'msg' => $st['error'] ?: 'すでに最新のバージョンです'];
        }
        if (!nm_zip_available()) return ['ok' => false, 'msg' => 'このサーバーの PHP には ZipArchive がないため、自動で更新できません'];
        if (!preg_match('/\Asha256:([a-f0-9]{64})\z/', (string)$st['digest'], $dm)) {
            return ['ok' => false, 'msg' => '更新ファイルの確認用の値が取得できないため、安全のため自動更新を中止しました'];
        }
        $size = (int)$st['size'];
        if ($size <= 0 || $size > NM_UPDATE_MAX_ZIP) return ['ok' => false, 'msg' => '更新ファイルの大きさが正しくありません'];

        try {
            [$status, $body] = nm_update_http((string)$st['url'], NM_UPDATE_MAX_ZIP, 60, false);
        } catch (Throwable $e) {
            nm_log('update_download_failed', $e->getMessage());
            return ['ok' => false, 'msg' => '更新ファイルをダウンロードできませんでした'];
        }
        if ($status !== 200 || strlen($body) !== $size || !hash_equals($dm[1], hash('sha256', $body))) {
            nm_log('update_digest_mismatch', (string)$st['latest']);
            return ['ok' => false, 'msg' => 'ダウンロードした更新ファイルが正しくないため、更新を中止しました（何も変更していません）'];
        }

        $root = nm_update_backup_root();
        nm_ensure_dir($root);
        $zipPath = $root . '/package-' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($zipPath, $body);
        unset($body);
        try {
            $pkg = nm_update_read_package($zipPath, (string)$st['latest']);
        } finally {
            @unlink($zipPath);
        }
        if (isset($pkg['error'])) {
            nm_log('update_package_rejected', $pkg['error']);
            return ['ok' => false, 'msg' => $pkg['error'] . '（何も変更していません）'];
        }
        $files = $pkg['files'];
        $bad = nm_update_unwritable(array_keys($files));
        if ($bad) {
            return ['ok' => false, 'msg' => '書き込めないファイルがあるため更新できません（何も変更していません）: ' . implode(', ', array_slice($bad, 0, 5))];
        }

        // Undo point: the files about to change, and the ones that will be new
        $backup = $root . '/backup-' . NM_VERSION . '-' . date('YmdHis');
        $saved = [];
        $added = [];
        foreach (array_keys($files) as $rel) {
            $path = NM_ROOT . '/' . $rel;
            if (is_file($path)) {
                // Stored as .bak: nothing under data/ may ever run as PHP
                nm_write_atomic($backup . '/files/' . $rel . '.bak', (string)file_get_contents($path));
                $saved[] = $rel;
            } else {
                $added[] = $rel;
            }
        }
        nm_write_json($backup . '/nagimanga-update.json', [
            'from' => NM_VERSION, 'to' => $st['latest'], 'created' => time(), 'files' => $saved, 'added' => $added,
        ]);

        // The entry points last, so a half-done update keeps the old ones working
        uksort($files, static function ($a, $b) {
            $late = ['lib/bootstrap.php' => 1, 'admin/index.php' => 2];
            return ($late[$a] ?? 0) <=> ($late[$b] ?? 0) ?: strcmp($a, $b);
        });
        try {
            foreach ($files as $rel => $bytes) nm_update_put($rel, $bytes);
        } catch (Throwable $e) {
            nm_update_restore(basename($backup));
            nm_log('update_failed', $e->getMessage());
            return ['ok' => false, 'msg' => '更新の途中で失敗したため、元に戻しました'];
        }
        @unlink(nm_update_cache_file());
        nm_update_prune();
        nm_log('update_applied', NM_VERSION . ' -> ' . $st['latest']);
        return ['ok' => true, 'msg' => 'NagiManga を ' . $st['latest'] . ' に更新しました', 'version' => $st['latest']];
    });
}

/** Put the files of an undo point back. Returns false when the name is not a known backup. */
function nm_update_restore(string $name): bool
{
    if (!preg_match('/\Abackup-[0-9.]+-[0-9]{14}\z/', $name)) return false;
    $dir = nm_update_backup_root() . '/' . $name;
    $meta = nm_read_json($dir . '/nagimanga-update.json');
    if (!$meta) return false;
    foreach ((array)($meta['files'] ?? []) as $rel) {
        if (!is_string($rel) || nm_update_rel_kind($rel) !== 'ok') continue;
        $src = $dir . '/files/' . $rel . '.bak';
        if (is_file($src)) nm_update_put($rel, (string)file_get_contents($src));
    }
    foreach ((array)($meta['added'] ?? []) as $rel) {
        if (!is_string($rel) || nm_update_rel_kind($rel) !== 'ok') continue;
        $path = NM_ROOT . '/' . $rel;
        if (is_file($path)) @unlink($path);
    }
    return true;
}

/** Undo an update from the admin: restore, then drop that undo point. */
function nm_update_rollback(string $name): array
{
    return nm_with_lock('update', static function () use ($name): array {
        $known = array_column(nm_update_backups(), null, 'name');
        if (!isset($known[$name])) return ['ok' => false, 'msg' => '元に戻すデータが見つかりません'];
        try {
            nm_update_restore($name);
        } catch (Throwable $e) {
            nm_log('update_rollback_failed', $e->getMessage());
            return ['ok' => false, 'msg' => '元に戻せませんでした'];
        }
        nm_rmdir_recursive(nm_update_backup_root() . '/' . $name);
        @unlink(nm_update_cache_file());
        nm_log('update_rolled_back', $known[$name]['to'] . ' -> ' . $known[$name]['from']);
        return ['ok' => true, 'msg' => 'NagiManga ' . $known[$name]['from'] . ' に戻しました'];
    });
}

function nm_update_prune(): void
{
    foreach (array_slice(nm_update_backups(), NM_UPDATE_KEEP_BACKUPS) as $b) {
        try {
            nm_rmdir_recursive(nm_update_backup_root() . '/' . $b['name']);
        } catch (Throwable) {
            // An old undo point left behind is harmless
        }
    }
}
