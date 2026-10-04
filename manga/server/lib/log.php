<?php
/* Personal LOG storage and safe markup. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_POST_PATTERN = '/\A(?:[0-9]{14}(?:-[0-9]{2,6})?|d[a-f0-9]{16})\z/';
const NL_MEDIA_PATTERN = '/\A[a-f0-9]{16}\z/';
const NL_BODY_MAX = 100000;
const NL_INDEX_SCHEMA = 5;
const NL_THEMES = ['light-blue' => 'ライトブルー', 'light-sage' => 'ライトセージ', 'light-paper' => 'ライトペーパー',
    'dark-navy' => 'ダークネイビー', 'dark-charcoal' => 'ダークチャコール', 'dark-plum' => 'ダークプラム'];
require_once __DIR__ . '/log-taxonomy.php';
require_once __DIR__ . '/log-sidebar.php';
require_once __DIR__ . '/log-links.php';
require_once __DIR__ . '/log-embed.php';
require_once __DIR__ . '/log-card.php';

function nl_valid_post(string $id): bool { return (bool)preg_match(NL_POST_PATTERN, $id); }
function nl_valid_media(string $id): bool { return (bool)preg_match(NL_MEDIA_PATTERN, $id); }
function nl_root(): string { return NM_DATA . '/log'; }
function nl_post_file(string $id): string
{
    if (!nl_valid_post($id)) throw new InvalidArgumentException('bad post');
    return nl_root() . '/posts/' . (str_starts_with($id, 'd') ? 'drafts' : substr($id, 0, 4)) . '/' . $id . '.php';
}
function nl_media_dir(string $id): string
{
    if (!nl_valid_media($id)) throw new InvalidArgumentException('bad media');
    $secret = (string)(nm_config()['secret'] ?? '');
    if ($secret === '') throw new RuntimeException('not configured');
    return nl_root() . '/media/' . substr(hash_hmac('sha256', 'log-media|' . $id, $secret), 0, 32);
}
function nl_write_record(string $file, array $data): void
{
    // Guarded PHP data cannot be fetched as text on hosts ignoring .htaccess.
    nm_write_atomic($file, "<?php\nif (!defined('NAGIMANGA')) { http_response_code(404); exit; }\nreturn " . var_export($data, true) . ";\n");
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
}
function nl_read_record(string $file): ?array
{
    if (!is_file($file) || is_link($file)) return null;
    $data = require $file;
    return is_array($data) ? $data : null;
}
function nl_load_post(string $id): ?array
{
    if (!nl_valid_post($id)) return null;
    $p = nl_read_record(nl_post_file($id));
    if ($p && is_array($p['categories'] ?? null)) $p['categories'] = array_map('strval', $p['categories']);
    return $p && ($p['id'] ?? '') === $id ? $p : null;
}
function nl_load_media(string $id): ?array
{
    if (!nl_valid_media($id)) return null;
    $m = nl_read_record(nl_media_dir($id) . '/media.php');
    return $m && ($m['id'] ?? '') === $id && preg_match(NM_PAGE_PATTERN, (string)($m['f'] ?? '')) ? $m : null;
}
function nl_post_summary(array $p): array
{
    return ['id' => $p['id'], 'created' => $p['created'], 'updated' => $p['updated'], 'title' => nl_post_title($p),
        'status' => $p['status'], 'media' => $p['media'] ?? [], 'manga' => array_values($p['manga'] ?? []), 'categories' => $p['categories'] ?? [], 'hashtags' => nl_hashtags($p['body'])];
}
/** A small derived index; original post files remain the source of truth. */
function nl_post_summaries(bool $public = true): array
{
    $posts = [];
    $index = nl_read_record(nl_root() . '/index.php');
    if (($index['schema'] ?? 0) === NL_INDEX_SCHEMA && is_array($index['posts'] ?? null)) {
        $posts = array_values($index['posts']);
    } else {
        // Read-only recovery when the derived index is removed (e.g. FTP edits).
        foreach (glob(nl_root() . '/posts/*/*.php') ?: [] as $file) {
            $p = nl_load_post(basename($file, '.php'));
            if ($p && isset($p['created'], $p['status'])) $posts[] = nl_post_summary($p);
        }
    }
    $posts = array_values(array_filter($posts, static fn($p) => is_array($p) && nl_valid_post((string)($p['id'] ?? '')) && (!$public || ($p['status'] ?? '') === 'published')));
    usort($posts, static fn($a, $b) => ($b['created'] <=> $a['created']) ?: strnatcmp($b['id'], $a['id']));
    return $posts;
}
function nl_list_posts(bool $public = true): array
{
    $out = [];
    foreach (nl_post_summaries($public) as $s) {
        $p = nl_load_post($s['id']);
        if ($p && (!$public || $p['status'] === 'published')) $out[] = $p;
    }
    return $out;
}
function nl_update_index(?array $post, string $remove = ''): void
{
    $summaries = [];
    foreach (nl_post_summaries(false) as $s) if ($s['id'] !== $remove) $summaries[$s['id']] = $s;
    if ($post) $summaries[$post['id']] = nl_post_summary($post);
    nl_write_record(nl_root() . '/index.php', ['schema' => NL_INDEX_SCHEMA, 'posts' => $summaries]);
}
function nl_rebuild_index(): void
{
    $summaries = [];
    foreach (glob(nl_root() . '/posts/*/*.php') ?: [] as $file) {
        $p = nl_load_post(basename($file, '.php'));
        if ($p) $summaries[$p['id']] = nl_post_summary($p);
    }
    nl_write_record(nl_root() . '/index.php', ['schema' => NL_INDEX_SCHEMA, 'posts' => $summaries]);
}
function nl_list_media(): array
{
    $out = [];
    foreach (glob(nl_root() . '/media/*/media.php') ?: [] as $file) {
        $data = nl_read_record($file);
        $m = nl_load_media((string)($data['id'] ?? ''));
        if ($m && realpath(dirname($file)) === realpath(nl_media_dir($m['id']))) $out[] = $m;
    }
    usort($out, static fn($a, $b) => [$b['created'], $b['id']] <=> [$a['created'], $a['id']]);
    return $out;
}
/** Remove only an empty folder or its automatically created, empty guard. */
function nl_prune_empty_dir(string $dir): bool
{
    if (!is_dir($dir)) return true;
    if (is_link($dir)) return false;
    $names = @scandir($dir);
    if ($names === false) return false;
    $names = array_values(array_diff($names, ['.', '..']));
    if ($names === ['index.html'] && !is_link($dir . '/index.html') && is_file($dir . '/index.html') && filesize($dir . '/index.html') === 0) {
        if (!@unlink($dir . '/index.html')) return false;
        $names = [];
    }
    return $names !== [] || @rmdir($dir);
}
/** Delete a reviewed batch and its unshared images; roll back before commit. */
function nl_delete_posts(mixed $items): array
{
    return nm_with_lock('personal-log', static function () use ($items) {
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 100) throw new UnexpectedValueException('削除する記事を1〜100件選んでください');
        $selected = []; $candidates = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_int($item['revision'] ?? null) || !nl_valid_post($item['id']) || isset($selected[$item['id']])) throw new UnexpectedValueException('記事の選択を確認してください');
            $p = nl_load_post($item['id']);
            if (!$p) throw new UnexpectedValueException('記事が見つかりません。一覧を開き直してください');
            if ($p['revision'] !== $item['revision']) throw new UnexpectedValueException('別の画面で更新された記事があります。一覧を開き直してください');
            $selected[$p['id']] = $p;
            foreach (nl_media_refs($p['body']) as $id) $candidates[$id] = true;
        }
        // Originals protect shared images even when the derived index is stale.
        $used = [];
        foreach (glob(nl_root() . '/posts/*/*.php') ?: [] as $file) {
            $p = nl_load_post(basename($file, '.php'));
            if ($p && !isset($selected[$p['id']])) foreach (nl_media_refs($p['body']) as $id) $used[$id] = true;
        }
        $s = nl_settings(); $used[$s['icon']] = true; $used[$s['og_image']] = true;
        foreach (nl_sidebar_media_ids() as $id) $used[$id] = true;
        $indexFile = nl_root() . '/index.php';
        $oldIndex = is_file($indexFile) ? file_get_contents($indexFile) : null;
        if ($oldIndex === false) throw new RuntimeException('cannot read index before delete');
        $stage = nl_root() . '/.delete-' . bin2hex(random_bytes(8));
        nm_ensure_dir($stage);
        $moved = [];
        $images = 0;
        $move = static function (string $from, string $to) use (&$moved): void {
            if (!rename($from, $to)) throw new RuntimeException('delete staging failed');
            $moved[] = [$from, $to];
            if (is_file($to) && function_exists('opcache_invalidate')) @opcache_invalidate($from, true);
        };
        try {
            foreach ($selected as $p) $move(nl_post_file($p['id']), $stage . '/post-' . $p['id'] . '.php');
            foreach (array_keys($candidates) as $id) {
                $id = (string)$id;
                if (isset($used[$id]) || !nl_load_media($id)) continue;
                $move(nl_media_dir($id), $stage . '/media-' . $id); $images++;
            }
            foreach (glob(nl_root() . '/receipts/*.php') ?: [] as $file) {
                $receipt = nl_read_record($file); $id = $receipt['id'] ?? '';
                if (is_string($id) && (isset($selected[$id]) || !nl_load_post($id))) $move($file, $stage . '/receipt-' . basename($file));
            }
            nl_rebuild_index();
        } catch (Throwable $e) {
            foreach (array_reverse($moved) as [$from, $to]) {
                if (!rename($to, $from)) throw new RuntimeException('delete rollback failed', 0, $e);
                if (is_file($from) && function_exists('opcache_invalidate')) @opcache_invalidate($from, true);
            }
            if (is_string($oldIndex)) nm_write_atomic($indexFile, $oldIndex); else @unlink($indexFile);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($indexFile, true);
            if (!nl_prune_empty_dir($stage)) throw new RuntimeException('delete rollback cleanup failed', 0, $e);
            throw $e;
        }
        // The temporary transaction folder is removed, never retained as trash.
        nm_rmdir_recursive($stage);
        $clean = !is_dir($stage);
        foreach ($selected as $p) $clean = nl_prune_empty_dir(dirname(nl_post_file($p['id']))) && $clean;
        foreach (['posts', 'media', 'receipts'] as $dir) $clean = nl_prune_empty_dir(nl_root() . '/' . $dir) && $clean;
        nm_log('log_deleted', count($selected) . ' posts / ' . $images . ' images' . ($clean ? '' : ' / cleanup incomplete'));
        return ['posts' => count($selected), 'media' => $images, 'clean' => $clean];
    });
}
function nl_settings(): array
{
    $s = array_replace(['title' => 'わたしのLOG', 'description' => '日々のメモと、絵と漫画。', 'name' => 'わたし', 'theme' => 'light-blue', 'icon' => '', 'og_image' => '', 'public' => true, 'show_login' => true, 'posts_per_page' => 10, 'show_footer' => true, 'footer_text' => 'Powered by NagiLog＆NagiManga', 'updated' => 0], nl_read_record(nl_root() . '/settings.php') ?? []);
    $s['public'] = $s['public'] === true;
    $s['show_login'] = $s['show_login'] === true;
    $s['show_footer'] = $s['show_footer'] === true;
    $s['posts_per_page'] = max(1, min(100, (int)$s['posts_per_page']));
    if (!isset(NL_THEMES[$s['theme']])) $s['theme'] = 'light-blue';
    if ($s['icon'] !== '' && !nl_valid_media($s['icon'])) $s['icon'] = '';
    if ($s['og_image'] !== '' && !nl_valid_media($s['og_image'])) $s['og_image'] = '';
    return $s;
}
function nl_date(int $time, string $format = 'Y/m/d H:i'): string
{
    return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format($format);
}
function nl_new_id(int $time): string
{
    $base = nl_date($time, 'YmdHis');
    $id = $base;
    for ($seq = 2; is_file(nl_post_file($id)); $seq++) $id = $base . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
    return $id;
}
function nl_media_refs(string $body): array
{
    preg_match_all('/\[Image:([a-f0-9]{16})\]/', $body, $m);
    return array_values(array_unique($m[1]));
}
function nl_manga_refs(string $body, array $refs): array
{
    $out = [];
    foreach ($refs as $tag => $id) {
        if (!is_string($tag) || !preg_match('/\A\[Manga[^\]\r\n]{1,230}\]\z/u', $tag)
            || !is_string($id) || !nm_valid_id($id)) continue;
        if (str_contains($body, $tag)) $out[$tag] = $id;
    }
    return $out;
}
function nl_validate_body(string $body): void
{
    if (trim($body) === '') throw new UnexpectedValueException('本文か画像、漫画を入れてください');
    if (strlen($body) > NL_BODY_MAX || !mb_check_encoding($body, 'UTF-8')) throw new UnexpectedValueException('本文は100KB以内にしてください');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) throw new UnexpectedValueException('本文に使えない文字があります');
}
/** Called under the shared log lock; revisions prevent edits in two tabs losing data. */
function nl_save_post(array $input): array
{
    return nm_with_lock('personal-log', static function () use ($input) {
        $nonce = (string)($input['nonce'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/', $nonce)) throw new UnexpectedValueException('投稿画面を開き直してください');
        $receiptFile = nl_root() . '/receipts/' . $nonce . '.php';
        $receipt = nl_read_record($receiptFile);
        if ($receipt && ($saved = nl_load_post((string)($receipt['id'] ?? '')))) return $saved;
        $id = (string)($input['id'] ?? '');
        $old = $id !== '' ? nl_load_post($id) : null;
        if ($id !== '' && !$old) throw new UnexpectedValueException('投稿が見つかりません');
        if ($old && (int)($input['revision'] ?? 0) !== (int)$old['revision']) throw new UnexpectedValueException('別の画面で更新されています。本文をコピーしてから開き直してください');
        $body = str_replace(["\r\n", "\r"], "\n", (string)($input['body'] ?? ''));
        nl_validate_body($body);
        $title = trim((string)($input['title'] ?? ''));
        if (!mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 200) throw new UnexpectedValueException('タイトルは200文字以内にしてください');
        $media = nl_media_refs($body);
        foreach ($media as $mid) if (!nl_load_media($mid)) throw new UnexpectedValueException('本文に見つからない画像があります');
        $refs = nl_manga_refs($body, (array)($input['manga'] ?? []));
        preg_match_all('/\[Manga[^\]\r\n]{1,230}\]/u', $body, $tags);
        foreach ($tags[0] as $tag) if (!isset($refs[$tag])) throw new UnexpectedValueException('漫画タグは「漫画を選ぶ」から入れてください');
        foreach ($refs as $wid) if (!nm_load_work($wid)) throw new UnexpectedValueException('選んだ漫画が見つかりません');
        $now = time();
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['published', 'draft'], true)) throw new UnexpectedValueException('公開か下書きを選んでください');
        if (!$old) $id = $status === 'published' ? nl_new_id($now) : 'd' . bin2hex(random_bytes(8));
        elseif (str_starts_with($id, 'd') && $status === 'published') $id = nl_new_id($now);
        $taxonomy = nl_taxonomy(); $oldTaxonomy = $taxonomy;
        $categories = nl_post_categories($input, $taxonomy);
        $p = ['id' => $id, 'title' => $title, 'body' => $body, 'manga' => $refs, 'media' => $media,
            'categories' => $categories,
            'status' => $status, 'created' => $old && (!str_starts_with($old['id'], 'd') || $status === 'draft') ? $old['created'] : $now,
            'updated' => $now, 'revision' => (int)($old['revision'] ?? 0) + 1];
        $indexFile = nl_root() . '/index.php';
        $oldIndex = is_file($indexFile) ? file_get_contents($indexFile) : null;
        $taxFile = nl_root() . '/taxonomy.php';
        $oldTaxBytes = is_file($taxFile) ? file_get_contents($taxFile) : null;
        try {
            if ($taxonomy !== $oldTaxonomy) { $taxonomy['revision']++; $taxonomy['updated'] = $now; nl_write_record($taxFile, $taxonomy); }
            nl_write_record(nl_post_file($id), $p);
            nl_update_index($p, $old && $old['id'] !== $id ? $old['id'] : '');
            nl_write_record($receiptFile, ['id' => $id, 'created' => $now]);
        } catch (Throwable $e) {
            if ($old && $old['id'] === $id) nl_write_record(nl_post_file($id), $old);
            else @unlink(nl_post_file($id));
            if (is_string($oldIndex)) nm_write_atomic($indexFile, $oldIndex); else @unlink($indexFile);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($indexFile, true);
            if (is_string($oldTaxBytes)) nm_write_atomic($taxFile, $oldTaxBytes); else @unlink($taxFile);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($taxFile, true);
            throw $e;
        }
        if ($old && $old['id'] !== $id) @unlink(nl_post_file($old['id']));
        // Retain receipts for one day; they only prevent duplicate form submissions.
        foreach (glob(nl_root() . '/receipts/*.php') ?: [] as $f) if (filemtime($f) < $now - 86400) @unlink($f);
        nm_log('log_saved', $id);
        return $p;
    });
}
function nl_upload_media(array $file, string $replace = '', int $revision = 0): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new UnexpectedValueException('画像を受け取れませんでした。アップロード上限も確認してください');
    }
    return nm_with_lock('personal-log', static function () use ($file, $replace, $revision) {
        $old = $replace !== '' ? nl_load_media($replace) : null;
        if ($replace !== '' && !$old) throw new UnexpectedValueException('画像が見つかりません');
        if ($old && $revision !== $old['revision']) throw new UnexpectedValueException('別の画面で画像が更新されています。開き直してください');
        $id = $old ? $old['id'] : bin2hex(random_bytes(8));
        $dir = nl_media_dir($id);
        $cfg = nm_config();
        $page = nm_import_image($file['tmp_name'], $dir, 1, (int)($cfg['image_quality'] ?? 90), (int)($cfg['max_upload_mb'] ?? 30) * 1024 * 1024, true, true);
        if (is_string($page)) throw new UnexpectedValueException($page);
        $name = (string)($file['name'] ?? '画像');
        if (!mb_check_encoding($name, 'UTF-8')) $name = '画像';
        $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 200);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $m = array_merge($page, ['id' => $id, 'alt' => $old['alt'] ?? $name, 'created' => $old['created'] ?? time(), 'updated' => time(), 'revision' => (int)($old['revision'] ?? 0) + 1]);
        try { nl_write_record($dir . '/media.php', $m); }
        catch (Throwable $e) { nm_delete_page_files($dir, $page['f']); throw $e; }
        if ($old) nm_delete_page_files($dir, $old['f']);
        nm_log($old ? 'log_image_replaced' : 'log_image_uploaded', $id);
        return $m;
    });
}
function nl_media_public(string $id): bool
{
    $s = nl_settings();
    if (!$s['public'] && !(defined('NL_OWNER') && NL_OWNER)) return false;
    if ($s['icon'] === $id || $s['og_image'] === $id) return true;
    if (in_array($id, nl_sidebar_media_ids(true), true)) return true;
    static $refs = null;
    if ($refs === null) {
        $refs = [];
        foreach (nl_post_summaries() as $p) foreach ($p['media'] ?? [] as $mid) $refs[$mid][] = $p['id'];
    }
    // Never trust cached visibility alone after unpublishing/deletion.
    foreach ($refs[$id] ?? [] as $pid) {
        $p = nl_load_post($pid);
        if ($p && $p['status'] === 'published' && in_array($id, $p['media'], true)) return true;
    }
    return false;
}
function nl_media_url(array $m, bool $thumb = false, bool $admin = false): string
{
    // End with the actual extension: NagiSwipe detects these links automatically.
    return ($admin ? 'index.php?p=log_image&' : './?') . 'media=' . $m['id'] . ($thumb ? '&thumb=1' : '') . '&v=' . $m['revision'] . '&format=image.' . pathinfo($m['f'], PATHINFO_EXTENSION);
}
function nl_serve_media(string $id, bool $thumb, bool $admin = false): never
{
    if (!nl_valid_media($id)) nm_not_found();
    if (!$admin && !nl_settings()['public']) nm_not_found();
    if (!$admin) nm_image_guard(nm_config());
    // Check visibility before conditional responses, including after unpublishing.
    $m = nl_load_media($id);
    if (!$m || (!$admin && !nl_media_public($id))) nm_not_found();
    $file = nl_media_dir($id) . '/' . ($thumb ? 't_' : '') . $m['f'];
    if (!is_file($file)) nm_not_found();
    header('Content-Type: ' . nm_image_mime($m['f']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($admin ? 'private, no-store' : 'public, max-age=0, must-revalidate'));
    header('Vary: Cookie');
    header('Content-Length: ' . filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') readfile($file);
    exit;
}
function nl_excerpt(array $p): string
{
    $text = preg_replace('/\[Image:[a-f0-9]{16}\]/', '', nl_post_parts($p)['body']);
    $text = preg_replace_callback(nl_embed_regex(), static fn($m) => ($name = nl_embed_name($m[1])) !== '' ? '（' . $name . '）' : $m[1], (string)$text);
    $text = str_replace('**', '', (string)$text);
    $text = preg_replace('/\[Manga([^\]\r\n]+)\]/u', '$1', $text);
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)$text)), 0, 140);
}
/** The first input line is the title. Keep first-line media in the body. */
function nl_post_parts(array $p): array
{
    $lines = preg_split('/\r?\n/', trim((string)$p['body']), 2);
    $line = $lines[0] ?? '';
    // A first line that is only an embeddable URL (YouTube etc.) is shown in the body, not as the title.
    $embed = nl_embed_name($line);
    $title = $embed !== '' ? '' : trim(str_replace('**', '', (string)preg_replace('/\[Image:[a-f0-9]{16}\]/', '', $line)));
    $title = (string)preg_replace('/\[Manga([^\]\r\n]+)\]/u', '$1', $title);
    preg_match_all('/\[Image:[a-f0-9]{16}\]|\[Manga[^\]\r\n]{1,230}\]/u', $line, $media);
    $body = $embed !== '' ? trim($line) : implode("\n", $media[0]);
    if (($lines[1] ?? '') !== '') $body .= ($body !== '' ? "\n" : '') . $lines[1];
    if ($title === '' && $embed !== '') $title = $embed . 'の記録';
    return ['title' => $title !== '' ? $title : '画像の記録', 'body' => $body];
}
function nl_post_title(array $p, int $limit = 80): string
{
    $first = nl_post_parts($p)['title'];
    return $limit > 0 && mb_strlen($first) > $limit ? mb_substr($first, 0, $limit) . '…' : $first;
}
function nl_render_text(string $text, bool $admin = false): string
{
    $parts = preg_split('~(https?://[^\s<>"\[\]]+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts ?: [] as $part) {
        if (preg_match('~\Ahttps?://~i', $part) && filter_var($part, FILTER_VALIDATE_URL)) $out .= '<a href="' . h($part) . '" rel="noopener noreferrer">' . h($part) . '</a>';
        else {
            $start = 0;
            preg_match_all(NL_HASHTAG_PATTERN, $part, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as $i => [$token, $offset]) {
                $out .= nl2br(h(substr($part, $start, $offset - $start)), false);
                $out .= '<a class="log-hashtag" href="' . ($admin ? '../' : '') . './?tag=' . h(rawurlencode($tags[1][$i][0])) . '">' . h($token) . '</a>';
                $start = $offset + strlen($token);
            }
            $out .= nl2br(h(substr($part, $start)), false);
        }
    }
    return $out;
}
function nl_render_body(array $p, bool $admin = false): string
{
    $tokens = preg_split('/(\[Image:[a-f0-9]{16}\]|\[Manga[^\]\r\n]{1,230}\]|' . NL_EMBED_PATTERN . '|\*\*[^\r\n]+?\*\*)/iu', nl_post_parts($p)['body'], -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $dark = str_starts_with(nl_settings()['theme'], 'dark');
    foreach ($tokens ?: [] as $token) {
        // A URL alone on its line: a player for known services, otherwise a blog card when its OGP was fetched.
        if (preg_match('~\A[ \t]*https?://~i', $token) && !preg_match('/\s\S/', trim($token))) {
            $out .= nl_embed_html(trim($token), $dark) ?? nl_card_html(trim($token), $admin) ?? nl_render_text($token, $admin);
            continue;
        }
        if (preg_match('/\A\[Image:([a-f0-9]{16})\]\z/', $token, $m)) {
            $im = nl_load_media($m[1]);
            if ($im && ($admin || nl_media_public($m[1]))) $out .= '<figure class="log-figure"><a class="imagelink" href="' . h(nl_media_url($im, false, $admin)) . '" data-ns-width="' . (int)$im['w'] . '" data-ns-height="' . (int)$im['h'] . '"><img src="' . h(nl_media_url($im, true, $admin)) . '" width="' . (int)$im['w'] . '" height="' . (int)$im['h'] . '" alt="' . h($im['alt']) . '" loading="lazy"></a></figure>';
            else $out .= '<span class="note">画像が見つかりません</span>';
        } elseif (isset($p['manga'][$token])) {
            $w = nm_load_work($p['manga'][$token]);
            if (!$w || empty($w['pages'])) { $out .= '<span class="note">漫画が見つかりません</span>'; continue; }
            $prefix = $admin ? '../' : '';
            $href = $prefix . 'read.php?nagimanga=' . $w['id'] . '&dir=' . rawurlencode($w['direction']);
            $cover = empty($w['password_hash']) && nm_work_page_public($w) ? $prefix . 'read.php?a=o&id=' . $w['id'] : $prefix . 'viewer/og.jpg';
            $out .= '<a class="log-manga" href="' . h($href) . '" data-nagimanga="' . h($w['id']) . '" data-endpoint="' . $prefix . 'read.php" data-direction="' . h($w['direction']) . '" data-view="auto" data-cover="1"><img src="' . h($cover) . '" alt="" loading="lazy"><span><strong>' . h($w['title']) . '</strong><small>' . (!empty($w['password_hash']) ? 'パスワードを入れて読む' : '漫画を読む') . '</small></span></a>';
        } elseif (str_starts_with($token, '**') && str_ends_with($token, '**')) $out .= '<strong>' . nl_render_text(substr($token, 2, -2), $admin) . '</strong>';
        else $out .= nl_render_text($token, $admin);
    }
    return '<div class="log-body">' . $out . '</div>';
}
function nl_base_url(): string
{
    $base = (string)(nm_config()['base_url'] ?? '');
    if ($base !== '') return rtrim($base, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A[a-z0-9.\-:\[\]]+\z/i', $host)) $host = 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/log.php'))), '/');
    if (!defined('NL_FRONT')) $dir = (string)preg_replace('~/admin\z~', '', $dir);
    return (nm_is_https() ? 'https' : 'http') . '://' . $host . $dir;
}
