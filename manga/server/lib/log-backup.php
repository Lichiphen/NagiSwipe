<?php
/* Personal LOG portable ZIP backup. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_backup_download(): never
{
    if (!nm_zip_available()) nm_not_found();
    $tmp = NM_DATA . '/tmp/log-backup-' . bin2hex(random_bytes(8)) . '.zip';
    nm_ensure_dir(dirname($tmp));
    nm_with_lock('personal-log', static function () use ($tmp) {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('zip failed');
        try {
            $zip->addFromString('backup.json', json_encode(['app' => 'NagiMangaLog', 'schema' => 1, 'created' => time(), 'settings' => nl_settings(), 'taxonomy' => nl_taxonomy(), 'sidebar' => nl_sidebar_settings()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            foreach (nl_post_summaries(false) as $summary) {
                $p = nl_load_post($summary['id']);
                if ($p) $zip->addFromString('posts/' . $p['id'] . '.json', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            foreach (nl_list_media() as $m) {
                $prefix = 'media/' . $m['id'] . '/';
                $zip->addFromString($prefix . 'media.json', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                foreach ([$m['f'], 't_' . $m['f']] as $file) {
                    $path = nl_media_dir($m['id']) . '/' . $file;
                    if (!is_file($path)) throw new RuntimeException('missing image');
                    $zip->addFile($path, $prefix . $file); $zip->setCompressionName($prefix . $file, ZipArchive::CM_STORE);
                }
            }
        } finally { $zip->close(); }
    });
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="nagimanga-log-' . nl_date(time(), 'Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp)); header('Cache-Control: no-store');
    readfile($tmp); @unlink($tmp); exit;
}
function nl_restore_string(array $data, string $key, int $max): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new UnexpectedValueException('バックアップの文字データが壊れています');
    return $value;
}
function nl_restore_json(ZipArchive $zip, int $index, mixed &$native = null): array
{
    $raw = nm_zip_read($zip, $index, 2 * 1024 * 1024);
    $data = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new UnexpectedValueException('バックアップのデータを読めません');
    $native = json_decode($raw);
    return $data;
}
function nl_backup_restore(string $file, bool $overwrite): array
{
    if (!nm_zip_available()) throw new UnexpectedValueException('ZipArchiveがありません');
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) throw new UnexpectedValueException('ZIPを開けませんでした');
    $stage = NM_DATA . '/tmp/log-restore-' . bin2hex(random_bytes(8));
    nm_ensure_dir($stage);
    try {
        if ($zip->numFiles > 20000) throw new UnexpectedValueException('バックアップのファイル数が多すぎます');
        $entries = []; $postEntries = []; $mediaEntries = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) throw new UnexpectedValueException('ZIPの項目を読めません');
            $name = $stat['name'];
            if (isset($entries[$name])) throw new UnexpectedValueException('ZIPの項目が重複しています');
            $entries[$name] = $i;
            if ($stat['size'] > 60 * 1024 * 1024 || ($total += $stat['size']) > 1024 * 1024 * 1024) throw new UnexpectedValueException('バックアップが大きすぎます');
            if ($name === 'backup.json') continue;
            if (preg_match('~\Aposts/([0-9]{14}(?:-[0-9]{2,6})?|d[a-f0-9]{16})\.json\z~', $name, $m)) $postEntries[$m[1]] = $i;
            elseif (preg_match('~\Amedia/([a-f0-9]{16})/(media\.json|(?:t_)?p[0-9]{4}_[a-f0-9]{8}\.(?:jpg|webp|gif))\z~', $name, $m)) $mediaEntries[$m[1]][$m[2]] = $i;
            else throw new UnexpectedValueException('LOG以外のファイルが入っています');
        }
        if (!isset($entries['backup.json'])) throw new UnexpectedValueException('LOGのバックアップではありません');
        $mark = nl_restore_json($zip, $entries['backup.json'], $nativeMark);
        if (($mark['app'] ?? '') !== 'NagiMangaLog' || ($mark['schema'] ?? 0) !== 1) throw new UnexpectedValueException('LOGのバックアップではありません');
        $sidebar = null;
        if (array_key_exists('sidebar', $mark)) {
            if (!is_object($nativeMark) || !is_object($nativeMark->sidebar ?? null) || !is_array($nativeMark->sidebar->items ?? null) || !is_array($mark['sidebar']) || !is_int($mark['sidebar']['revision'] ?? null) || $mark['sidebar']['revision'] < 0) throw new UnexpectedValueException('サイドバーの設定が壊れています');
            $sidebar = nl_sidebar_validate($mark['sidebar']['items'] ?? null);
        }
        $settings = (array)($mark['settings'] ?? []);
        $preferences = [];
        foreach (['public', 'show_login', 'show_footer'] as $field) {
            if (array_key_exists($field, $settings) && !is_bool($settings[$field])) throw new UnexpectedValueException('LOGの公開設定が壊れています');
            $preferences[$field] = $settings[$field] ?? nl_settings()[$field];
        }
        if (array_key_exists('posts_per_page', $settings) && (!is_int($settings['posts_per_page']) || $settings['posts_per_page'] < 1 || $settings['posts_per_page'] > 100)) throw new UnexpectedValueException('LOGの表示件数が壊れています');
        $preferences['posts_per_page'] = $settings['posts_per_page'] ?? nl_settings()['posts_per_page'];
        $preferences['footer_text'] = nl_restore_string($settings + ['footer_text' => nl_settings()['footer_text']], 'footer_text', 800);
        if (mb_strlen($preferences['footer_text']) > 200 || preg_match('/[\x00-\x1F\x7F]/', $preferences['footer_text'])) throw new UnexpectedValueException('フッターの文字データが壊れています');
        $settings = ['title' => nl_restore_string($settings, 'title', 600), 'description' => nl_restore_string($settings, 'description', 1500), 'name' => nl_restore_string($settings, 'name', 600),
            'theme' => nl_restore_string($settings + ['theme' => 'light-blue'], 'theme', 30), 'icon' => nl_restore_string($settings, 'icon', 16), 'og_image' => nl_restore_string($settings, 'og_image', 16), 'updated' => time()];
        if (!isset(NL_THEMES[$settings['theme']]) || ($settings['icon'] !== '' && !nl_valid_media($settings['icon'])) || ($settings['og_image'] !== '' && !nl_valid_media($settings['og_image']))) throw new UnexpectedValueException('デザインや紹介画像の設定が壊れています');
        $settings = array_replace($settings, $preferences);
        $tax = $mark['taxonomy']['categories'] ?? [];
        if (!is_array($tax) || count($tax) > 10000) throw new UnexpectedValueException('カテゴリの設定が壊れています');
        foreach ($tax as $id => $name) if (!preg_match('/\A[a-f0-9]{12}\z/', (string)$id) || !is_string($name) || nl_category_name($name) !== $name) throw new UnexpectedValueException('カテゴリの設定が壊れています');
        $tagOrder = $mark['taxonomy']['hashtag_order'] ?? nl_taxonomy()['hashtag_order'];
        if (!is_array($tagOrder) || !array_is_list($tagOrder) || count($tagOrder) > 10000) throw new UnexpectedValueException('ハッシュタグの順序が壊れています');
        foreach ($tagOrder as $tag) if (!is_string($tag) || !preg_match('/\A[\p{L}\p{M}\p{N}_]{1,60}\z/u', $tag)) throw new UnexpectedValueException('ハッシュタグの順序が壊れています');
        if (count(array_unique($tagOrder)) !== count($tagOrder)) throw new UnexpectedValueException('ハッシュタグの順序が重複しています');
        $media = []; $posts = [];
        foreach ($mediaEntries as $id => $files) {
            $id = (string)$id;
            if (!isset($files['media.json'])) throw new UnexpectedValueException('画像の設定がありません');
            $m = nl_restore_json($zip, $files['media.json']);
            if (($m['id'] ?? '') !== $id || !is_string($m['f'] ?? null) || !preg_match(NM_PAGE_PATTERN, $m['f']) || !isset($files[$m['f']])) throw new UnexpectedValueException('画像の設定が壊れています');
            $bytes = nm_zip_read($zip, $files[$m['f']], NM_RESTORE_MAX_ENTRY);
            if ($bytes === null) throw new UnexpectedValueException('画像を読めませんでした');
            $input = $stage . '/image.tmp'; file_put_contents($input, $bytes);
            $dir = $stage . '/media/' . $id;
            $image = nm_import_image($input, $dir, 1, 90, NM_RESTORE_MAX_ENTRY, true, true);
            @unlink($input);
            if (is_string($image)) throw new UnexpectedValueException($image);
            $media[$id] = array_merge($image, ['id' => $id, 'alt' => nl_restore_string($m, 'alt', 1500), 'created' => max(0, (int)($m['created'] ?? 0)), 'updated' => time(), 'revision' => max(1, (int)($m['revision'] ?? 1))]);
        }
        foreach ($postEntries as $id => $i) {
            // Numeric timestamp keys are converted to integers by PHP arrays.
            $id = (string)$id;
            $p = nl_restore_json($zip, $i);
            if (($p['id'] ?? '') !== $id || !in_array($p['status'] ?? '', ['draft', 'published'], true)
                || (str_starts_with($id, 'd') && $p['status'] === 'published')) throw new UnexpectedValueException('投稿の設定が壊れています');
            $body = nl_restore_string($p, 'body', NL_BODY_MAX); nl_validate_body($body);
            $refs = nl_manga_refs($body, (array)($p['manga'] ?? []));
            $categories = $p['categories'] ?? [];
            if (!is_array($categories) || count($categories) > 20) throw new UnexpectedValueException('投稿のカテゴリが壊れています');
            foreach ($categories as $cid) if ((!is_string($cid) && !is_int($cid)) || !preg_match('/\A[a-f0-9]{12}\z/', (string)$cid)) throw new UnexpectedValueException('投稿のカテゴリが壊れています');
            $categories = array_map('strval', $categories);
            $posts[$id] = ['id' => $id, 'title' => nl_restore_string($p, 'title', 1000), 'body' => $body, 'manga' => $refs, 'media' => nl_media_refs($body), 'categories' => array_values(array_unique($categories)), 'status' => $p['status'], 'created' => max(0, (int)($p['created'] ?? 0)), 'updated' => time(), 'revision' => max(1, (int)($p['revision'] ?? 1))];
        }
        return nm_with_lock('personal-log', static function () use ($stage, $media, $posts, $settings, $tax, $tagOrder, $sidebar, $overwrite) {
            // Validate all references before replacing any live file.
            if ($settings['icon'] !== '' && !isset($media[$settings['icon']]) && !nl_load_media($settings['icon'])) throw new UnexpectedValueException('アイコンの画像がバックアップにありません');
            if ($settings['og_image'] !== '' && !isset($media[$settings['og_image']]) && !nl_load_media($settings['og_image'])) throw new UnexpectedValueException('紹介画像がバックアップにありません');
            $taxonomy = nl_taxonomy();
            $taxonomy['categories'] = $overwrite ? $tax + $taxonomy['categories'] : $taxonomy['categories'] + $tax;
            $taxonomy['hashtag_order'] = $overwrite ? $tagOrder : array_values(array_unique(array_merge($taxonomy['hashtag_order'], $tagOrder)));
            foreach ($posts as $p) foreach ($p['categories'] as $id) if (!isset($taxonomy['categories'][$id])) throw new UnexpectedValueException('投稿のカテゴリがバックアップにありません');
            foreach ($posts as $p) foreach ($p['media'] as $id) if (!isset($media[$id]) && !nl_load_media($id)) throw new UnexpectedValueException('投稿に必要な画像がバックアップにありません');
            $changes = []; $result = ['media' => 0, 'posts' => 0];
            try {
                $dest = nl_root() . '/taxonomy.php';
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                $taxonomy['revision']++; $taxonomy['updated'] = time();
                nl_write_record($dest, $taxonomy);
                foreach ($media as $id => $m) {
                    $id = (string)$id;
                    $old = nl_load_media($id);
                    if ($old && !$overwrite) continue;
                    if ($old) $m['revision'] = max($m['revision'], $old['revision'] + 1);
                    $new = $stage . '/media/' . $id;
                    nl_write_record($new . '/media.php', $m);
                    $dest = nl_media_dir($id); nm_ensure_dir(dirname($dest));
                    $backup = $stage . '/old-media-' . $id;
                    if (is_dir($dest) && !rename($dest, $backup)) throw new RuntimeException('swap failed');
                    $changes[] = ['dir', $dest, is_dir($backup) ? $backup : null];
                    if (!rename($new, $dest)) throw new RuntimeException('swap failed');
                    $result['media']++;
                }
                foreach ($posts as $id => $p) {
                    $old = nl_load_post((string)$id);
                    if ($old && !$overwrite) continue;
                    if ($old) $p['revision'] = max($p['revision'], $old['revision'] + 1);
                    $dest = nl_post_file((string)$id);
                    $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                    nl_write_record($dest, $p); $result['posts']++;
                }
                if ($overwrite || !is_file(nl_root() . '/settings.php')) {
                    $dest = nl_root() . '/settings.php';
                    $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                    nl_write_record($dest, $settings);
                }
                if ($sidebar !== null && ($overwrite || !is_file(nl_root() . '/sidebar.php'))) {
                    $dest = nl_root() . '/sidebar.php';
                    $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                    $oldSidebar = nl_sidebar_settings();
                    nl_write_record($dest, ['revision' => $oldSidebar['revision'] + 1, 'updated' => time(), 'items' => $sidebar]);
                }
                $dest = nl_root() . '/index.php';
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                nl_rebuild_index();
                return $result;
            } catch (Throwable $e) {
                foreach (array_reverse($changes) as [$kind, $dest, $old]) {
                    if ($kind === 'dir') { if (is_dir($dest)) nm_rmdir_recursive($dest); if ($old !== null) rename($old, $dest); }
                    elseif ($old !== null) { nm_write_atomic($dest, $old); if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true); }
                    else @unlink($dest);
                }
                throw $e;
            }
        });
    } finally { $zip->close(); if (is_dir($stage)) nm_rmdir_recursive($stage); }
}
