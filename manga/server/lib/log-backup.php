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
            $zip->addFromString('backup.json', json_encode(['app' => 'NagiMangaLog', 'schema' => 1, 'created' => time(), 'settings' => nl_settings(), 'taxonomy' => nl_taxonomy(), 'sidebar' => nl_sidebar_settings(), 'topmenu' => nl_topmenu_settings(), 'footmenu' => nl_menu_settings('foot'), 'likes' => (object)nl_like_counts()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            foreach (nl_pages() as $page) $zip->addFromString('pages/' . $page['id'] . '.json', json_encode($page, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
    if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new UnexpectedValueException(nm_t('バックアップの文字データが壊れています'));
    return $value;
}
/** A content warning from a backup; older backups have none. An unknown value stops the restore rather than dropping a warning. */
function nl_restore_rating(array $data): string
{
    $rating = $data['rating'] ?? '';
    if (!is_string($rating) || ($rating !== '' && !isset(NL_RATINGS[$rating]))) throw new UnexpectedValueException(nm_t('閲覧注意の設定が壊れています'));
    return $rating;
}
function nl_restore_json(ZipArchive $zip, int $index, mixed &$native = null): array
{
    $raw = nm_zip_read($zip, $index, 2 * 1024 * 1024);
    $data = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new UnexpectedValueException(nm_t('バックアップのデータを読めません'));
    $native = json_decode($raw);
    return $data;
}
/** Check a backup without converting its images: layout, settings and posts. Returns everything the restore needs. */
function nl_restore_plan(ZipArchive $zip): array
{
    if ($zip->numFiles > 20000) throw new UnexpectedValueException(nm_t('バックアップのファイル数が多すぎます'));
    $entries = []; $postEntries = []; $mediaEntries = []; $pageEntries = []; $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) throw new UnexpectedValueException(nm_t('ZIPの項目を読めません'));
        $name = $stat['name'];
        if (isset($entries[$name])) throw new UnexpectedValueException(nm_t('ZIPの項目が重複しています'));
        $entries[$name] = $i;
        if ($stat['size'] > 60 * 1024 * 1024 || ($total += $stat['size']) > 1024 * 1024 * 1024) throw new UnexpectedValueException(nm_t('バックアップが大きすぎます'));
        if ($name === 'backup.json') continue;
        if (preg_match('~\Aposts/([0-9]{14}(?:-[0-9]{2,6})?|d[a-f0-9]{16})\.json\z~', $name, $m)) $postEntries[$m[1]] = $i;
        elseif (preg_match('~\Amedia/([a-f0-9]{16})/(media\.json|(?:t_)?p[0-9]{4}_[a-f0-9]{8}\.(?:jpg|webp|gif|svg))\z~', $name, $m)) $mediaEntries[$m[1]][$m[2]] = $i;
        elseif (preg_match('~\Apages/(pg[a-f0-9]{12})\.json\z~', $name, $m)) $pageEntries[$m[1]] = $i;
        else throw new UnexpectedValueException(nm_t('LOG以外のファイルが入っています'));
    }
    if (!isset($entries['backup.json'])) throw new UnexpectedValueException(nm_t('LOGのバックアップではありません'));
    $mark = nl_restore_json($zip, $entries['backup.json'], $nativeMark);
    if (($mark['app'] ?? '') !== 'NagiMangaLog' || ($mark['schema'] ?? 0) !== 1) throw new UnexpectedValueException(nm_t('LOGのバックアップではありません'));
    $sidebar = null;
    if (array_key_exists('sidebar', $mark)) {
        if (!is_object($nativeMark) || !is_object($nativeMark->sidebar ?? null) || !is_array($nativeMark->sidebar->items ?? null) || !is_array($mark['sidebar']) || !is_int($mark['sidebar']['revision'] ?? null) || $mark['sidebar']['revision'] < 0) throw new UnexpectedValueException(nm_t('サイドバーの設定が壊れています'));
        $sidebar = nl_sidebar_validate($mark['sidebar']['items'] ?? null);
    }
    $topmenu = null;
    if (array_key_exists('topmenu', $mark)) {
        if (!is_array($mark['topmenu']) || !is_array($mark['topmenu']['items'] ?? null)) throw new UnexpectedValueException(nm_t('トップメニューの設定が壊れています'));
        $topmenu = nl_topmenu_validate($mark['topmenu']['items']);
    }
    $footmenu = null;
    if (array_key_exists('footmenu', $mark)) {
        if (!is_array($mark['footmenu']) || !is_array($mark['footmenu']['items'] ?? null)) throw new UnexpectedValueException(nm_t('フッターのリンクの設定が壊れています'));
        $footmenu = nl_topmenu_validate($mark['footmenu']['items'], 'foot');
    }
    $settings = (array)($mark['settings'] ?? []);
    $preferences = [];
    foreach (['public', 'show_login', 'show_footer', 'pager_status', 'post_nav', 'show_description', 'footer_home'] as $field) {
        if (array_key_exists($field, $settings) && !is_bool($settings[$field])) throw new UnexpectedValueException(nm_t('LOGの公開設定が壊れています'));
        $preferences[$field] = $settings[$field] ?? nl_settings()[$field];
    }
    if (array_key_exists('posts_per_page', $settings) && (!is_int($settings['posts_per_page']) || $settings['posts_per_page'] < 1 || $settings['posts_per_page'] > 100)) throw new UnexpectedValueException(nm_t('LOGの表示件数が壊れています'));
    $preferences['posts_per_page'] = $settings['posts_per_page'] ?? nl_settings()['posts_per_page'];
    if (array_key_exists('pager', $settings) && (!is_string($settings['pager']) || !isset(NL_PAGERS[$settings['pager']]))) throw new UnexpectedValueException(nm_t('LOGのページ送りの設定が壊れています'));
    $preferences['pager'] = $settings['pager'] ?? nl_settings()['pager'];
    foreach (['likes', 'related', 'search_engines'] as $field) {
        if (array_key_exists($field, $settings) && !is_bool($settings[$field])) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
        $preferences[$field] = $settings[$field] ?? nl_settings()[$field];
    }
    foreach (['layout' => NL_LAYOUTS, 'related_by' => NL_RELATED_BY, 'related_order' => NL_RELATED_ORDER] as $field => $choices) {
        if (array_key_exists($field, $settings) && (!is_string($settings[$field]) || !isset($choices[$settings[$field]]))) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
        $preferences[$field] = $settings[$field] ?? nl_settings()[$field];
    }
    if (array_key_exists('new_days', $settings) && (!is_int($settings['new_days']) || $settings['new_days'] < 0 || $settings['new_days'] > NL_NEW_DAYS_MAX)) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
    if (array_key_exists('new_label', $settings) && (!is_string($settings['new_label']) || nl_new_label($settings['new_label']) !== $settings['new_label'])) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
    $preferences['new_days'] = $settings['new_days'] ?? nl_settings()['new_days'];
    $preferences['new_label'] = $settings['new_label'] ?? nl_settings()['new_label'];
    if (array_key_exists('crumb_home', $settings) && (!is_string($settings['crumb_home']) || !isset(NL_CRUMB_HOMES[$settings['crumb_home']]))) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
    if (array_key_exists('crumb_label', $settings) && (!is_string($settings['crumb_label']) || nl_crumb_label($settings['crumb_label']) !== $settings['crumb_label'])) throw new UnexpectedValueException(nm_t('LOGの表示設定が壊れています'));
    $preferences['crumb_home'] = $settings['crumb_home'] ?? nl_settings()['crumb_home'];
    $preferences['crumb_label'] = $settings['crumb_label'] ?? nl_settings()['crumb_label'];
    if (array_key_exists('warning_presets', $settings) && (!is_array($settings['warning_presets']) || !array_is_list($settings['warning_presets']) || nl_warning_presets($settings['warning_presets']) !== $settings['warning_presets'])) throw new UnexpectedValueException(nm_t('閲覧注意の定型文が壊れています'));
    $preferences['warning_presets'] = $settings['warning_presets'] ?? nl_settings()['warning_presets'];
    $likes = $mark['likes'] ?? [];
    if (!is_array($likes) || count($likes) > 100000) throw new UnexpectedValueException(nm_t('いいねの数が壊れています'));
    foreach ($likes as $id => $n) if (!nl_valid_post((string)$id) || !is_int($n) || $n < 0 || $n > NL_LIKE_MAX) throw new UnexpectedValueException(nm_t('いいねの数が壊れています'));
    $preferences['footer_text'] = nl_restore_string($settings + ['footer_text' => nl_settings()['footer_text']], 'footer_text', 800);
    if (mb_strlen($preferences['footer_text']) > 200 || preg_match('/[\x00-\x1F\x7F]/', $preferences['footer_text'])) throw new UnexpectedValueException(nm_t('フッターの文字データが壊れています'));
    $settings = ['title' => nl_restore_string($settings, 'title', 600), 'description' => nl_restore_string($settings, 'description', 1500), 'name' => nl_restore_string($settings, 'name', 600),
        'theme' => nl_restore_string($settings + ['theme' => 'light-blue'], 'theme', 30), 'icon' => nl_restore_string($settings, 'icon', 16), 'og_image' => nl_restore_string($settings, 'og_image', 16), 'logo' => nl_restore_string($settings, 'logo', 16), 'updated' => time()];
    if (!isset(NL_THEMES[$settings['theme']]) || ($settings['icon'] !== '' && !nl_valid_media($settings['icon'])) || ($settings['og_image'] !== '' && !nl_valid_media($settings['og_image'])) || ($settings['logo'] !== '' && !nl_valid_media($settings['logo']))) throw new UnexpectedValueException(nm_t('デザインや紹介画像の設定が壊れています'));
    $settings = array_replace($settings, $preferences);
    $tax = $mark['taxonomy']['categories'] ?? [];
    if (!is_array($tax) || count($tax) > 10000) throw new UnexpectedValueException(nm_t('カテゴリの設定が壊れています'));
    foreach ($tax as $id => $name) if (!preg_match('/\A[a-f0-9]{12}\z/', (string)$id) || !is_string($name) || nl_category_name($name) !== $name) throw new UnexpectedValueException(nm_t('カテゴリの設定が壊れています'));
    $tagOrder = $mark['taxonomy']['hashtag_order'] ?? nl_taxonomy()['hashtag_order'];
    if (!is_array($tagOrder) || !array_is_list($tagOrder) || count($tagOrder) > 10000) throw new UnexpectedValueException(nm_t('ハッシュタグの順序が壊れています'));
    foreach ($tagOrder as $tag) if (!is_string($tag) || !preg_match('/\A[\p{L}\p{M}\p{N}_]{1,60}\z/u', $tag)) throw new UnexpectedValueException(nm_t('ハッシュタグの順序が壊れています'));
    if (count(array_unique($tagOrder)) !== count($tagOrder)) throw new UnexpectedValueException(nm_t('ハッシュタグの順序が重複しています'));
    $posts = [];
    foreach ($postEntries as $id => $i) {
        // Numeric timestamp keys are converted to integers by PHP arrays.
        $id = (string)$id;
        $p = nl_restore_json($zip, $i);
        if (($p['id'] ?? '') !== $id || !in_array($p['status'] ?? '', ['draft', 'published'], true)
            || (str_starts_with($id, 'd') && $p['status'] === 'published')) throw new UnexpectedValueException(nm_t('投稿の設定が壊れています'));
        $body = nl_restore_string($p, 'body', NL_BODY_MAX); nl_validate_body($body);
        $refs = nl_manga_refs($body, (array)($p['manga'] ?? []));
        $categories = $p['categories'] ?? [];
        if (!is_array($categories) || count($categories) > 20) throw new UnexpectedValueException(nm_t('投稿のカテゴリが壊れています'));
        foreach ($categories as $cid) if ((!is_string($cid) && !is_int($cid)) || !preg_match('/\A[a-f0-9]{12}\z/', (string)$cid)) throw new UnexpectedValueException(nm_t('投稿のカテゴリが壊れています'));
        $categories = array_map('strval', $categories);
        $posts[$id] = ['id' => $id, 'title' => nl_restore_string($p, 'title', 1000), 'body' => $body, 'manga' => $refs, 'media' => nl_media_refs($body), 'categories' => array_values(array_unique($categories)), 'rating' => nl_restore_rating($p), 'warning' => nl_warning_text(nl_restore_string($p, 'warning', 400)), 'status' => $p['status'], 'created' => max(0, (int)($p['created'] ?? 0)), 'updated' => time(), 'revision' => max(1, (int)($p['revision'] ?? 1))];
    }
    $media = [];
    foreach ($mediaEntries as $id => $files) {
        if (!isset($files['media.json'])) throw new UnexpectedValueException(nm_t('画像の設定がありません'));
        $media[(string)$id] = $files;
    }
    $pages = [];
    if (count($pageEntries) > NL_PAGE_MAX) throw new UnexpectedValueException(nm_t('固定ページが多すぎます'));
    foreach ($pageEntries as $id => $i) {
        $p = nl_restore_json($zip, $i);
        if (($p['id'] ?? '') !== $id) throw new UnexpectedValueException(nm_t('固定ページの設定が壊れています'));
        try {
            $fields = nl_page_input(['title' => nl_restore_string($p, 'title', 1000), 'slug' => nl_restore_string($p, 'slug', 200), 'body' => nl_restore_string($p, 'body', NL_PAGE_BODY_MAX), 'layout' => nl_restore_string($p, 'layout', 10), 'status' => nl_restore_string($p, 'status', 10)]);
        } catch (UnexpectedValueException $e) { throw new UnexpectedValueException(nm_t('固定ページ「{title}」が壊れています：{message}', ['title' => mb_strimwidth((string)($p['title'] ?? ''), 0, 40, '…', 'UTF-8'), 'message' => nm_t($e->getMessage())]), 0, $e); }
        $pages[$id] = $fields + ['id' => $id, 'media' => nl_page_media_refs($fields['body']), 'created' => max(0, (int)($p['created'] ?? 0)), 'updated' => time(), 'revision' => max(1, (int)($p['revision'] ?? 1))];
    }
    $slugs = array_column($pages, 'slug');
    if (count($slugs) !== count(array_unique($slugs))) throw new UnexpectedValueException(nm_t('固定ページのURL名が重複しています'));
    return ['settings' => $settings, 'tax' => $tax, 'tag_order' => $tagOrder, 'sidebar' => $sidebar, 'topmenu' => $topmenu, 'footmenu' => $footmenu, 'pages' => $pages, 'likes' => $likes, 'media' => $media, 'posts' => $posts];
}
/** Convert one image of a backup into $stage/media/<id> and return its record. */
function nl_restore_image(ZipArchive $zip, string $stage, string $id, array $files): array
{
    $m = nl_restore_json($zip, $files['media.json']);
    if (($m['id'] ?? '') !== $id || !is_string($m['f'] ?? null) || !preg_match(NL_MEDIA_FILE_PATTERN, $m['f']) || !isset($files[$m['f']])) throw new UnexpectedValueException(nm_t('画像の設定が壊れています'));
    $bytes = nm_zip_read($zip, $files[$m['f']], NM_RESTORE_MAX_ENTRY);
    if ($bytes === null) throw new UnexpectedValueException(nm_t('画像を読めませんでした'));
    $dir = $stage . '/media/' . $id;
    if (is_dir($dir)) nm_rmdir_recursive($dir);
    // An SVG from a backup is checked again like an upload; other pictures are redrawn.
    if (str_ends_with($m['f'], '.svg')) $image = nl_svg_store($bytes, $dir);
    else {
        $input = $stage . '/image.tmp'; file_put_contents($input, $bytes);
        $image = nm_import_image($input, $dir, 1, 90, NM_RESTORE_MAX_ENTRY, true, true);
        @unlink($input);
    }
    if (is_string($image)) throw new UnexpectedValueException($image);
    return array_merge($image, ['id' => $id, 'alt' => nl_restore_string($m, 'alt', 1500), 'rating' => nl_restore_rating($m), 'created' => max(0, (int)($m['created'] ?? 0)), 'updated' => time(), 'revision' => max(1, (int)($m['revision'] ?? 1))]);
}
/** Swap the converted images, posts and settings in, all or nothing. */
function nl_restore_apply(string $stage, array $plan, array $media, bool $overwrite): array
{
    ['settings' => $settings, 'tax' => $tax, 'tag_order' => $tagOrder, 'sidebar' => $sidebar, 'likes' => $likes, 'posts' => $posts] = $plan;
    // Plans saved by an older version (a restore in progress during an update) have no pages or top menu.
    $pages = $plan['pages'] ?? []; $topmenu = $plan['topmenu'] ?? null; $footmenu = $plan['footmenu'] ?? null;
    return nm_with_lock('personal-log', static function () use ($stage, $media, $posts, $pages, $topmenu, $footmenu, $settings, $tax, $tagOrder, $sidebar, $likes, $overwrite) {
        // Validate all references before replacing any live file.
        if ($settings['icon'] !== '' && !isset($media[$settings['icon']]) && !nl_load_media($settings['icon'])) throw new UnexpectedValueException(nm_t('アイコンの画像がバックアップにありません'));
        if ($settings['og_image'] !== '' && !isset($media[$settings['og_image']]) && !nl_load_media($settings['og_image'])) throw new UnexpectedValueException(nm_t('紹介画像がバックアップにありません'));
        if ($settings['logo'] !== '' && !isset($media[$settings['logo']]) && !nl_load_media($settings['logo'])) throw new UnexpectedValueException(nm_t('タイトルロゴの画像がバックアップにありません'));
        $taxonomy = nl_taxonomy();
        $taxonomy['categories'] = $overwrite ? $tax + $taxonomy['categories'] : $taxonomy['categories'] + $tax;
        $taxonomy['hashtag_order'] = $overwrite ? $tagOrder : array_values(array_unique(array_merge($taxonomy['hashtag_order'], $tagOrder)));
        foreach ($posts as $p) foreach ($p['categories'] as $id) if (!isset($taxonomy['categories'][$id])) throw new UnexpectedValueException(nm_t('投稿のカテゴリがバックアップにありません'));
        foreach ($posts as $p) foreach ($p['media'] as $id) if (!isset($media[$id]) && !nl_load_media($id)) throw new UnexpectedValueException(nm_t('投稿に必要な画像がバックアップにありません'));
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
                nm_touch_content();
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
            $taken = [];
            foreach (nl_pages() as $p) if (!isset($pages[$p['id']]) || !$overwrite) $taken[$p['slug']] = $p['id'];
            foreach ($pages as $id => $p) {
                $old = nl_load_page((string)$id);
                if ($old && !$overwrite) continue;
                if ($old) $p['revision'] = max($p['revision'], $old['revision'] + 1);
                // Another page already has this URL name: keep both, the restored one with a number.
                for ($n = 2, $slug = $p['slug']; isset($taken[$slug]) && $taken[$slug] !== $id; $n++) $slug = substr($p['slug'], 0, 36) . '-' . $n;
                $p['slug'] = $slug; $taken[$slug] = $id;
                $dest = nl_page_file((string)$id);
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                nl_write_record($dest, $p);
            }
            foreach (['top' => $topmenu, 'foot' => $footmenu] as $kind => $menu) {
                $dest = nl_root() . '/' . NL_MENUS[$kind]['file'] . '.php';
                if ($menu === null || (!$overwrite && is_file($dest))) continue;
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                nl_write_record($dest, ['revision' => nl_menu_settings($kind)['revision'] + 1, 'updated' => time(), 'items' => $menu]);
            }
            if ($sidebar !== null && ($overwrite || !is_file(nl_root() . '/sidebar.php'))) {
                $dest = nl_root() . '/sidebar.php';
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                $oldSidebar = nl_sidebar_settings();
                nl_write_record($dest, ['revision' => $oldSidebar['revision'] + 1, 'updated' => time(), 'items' => $sidebar]);
            }
            if ($likes) {
                $dest = nl_likes_file();
                $changes[] = ['file', $dest, is_file($dest) ? file_get_contents($dest) : null];
                nm_with_lock('log-likes', static function () use ($likes, $overwrite) {
                    $counts = nl_likes_read();
                    foreach ($likes as $id => $n) if ($overwrite || !isset($counts[(string)$id])) $counts[(string)$id] = $n;
                    nl_likes_write($counts);
                });
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
}
function nl_backup_restore(string $file, bool $overwrite): array
{
    if (!nm_zip_available()) throw new UnexpectedValueException(nm_t('ZipArchiveがありません'));
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) throw new UnexpectedValueException(nm_t('ZIPを開けませんでした'));
    $stage = NM_DATA . '/tmp/log-restore-' . bin2hex(random_bytes(8));
    nm_ensure_dir($stage);
    try {
        $plan = nl_restore_plan($zip);
        $media = [];
        foreach ($plan['media'] as $id => $files) $media[(string)$id] = nl_restore_image($zip, $stage, (string)$id, $files);
        return nl_restore_apply($stage, $plan, $media, $overwrite);
    } finally { $zip->close(); if (is_dir($stage)) nm_rmdir_recursive($stage); }
}
/*
 * Restore in steps, for the admin's progress screen. One request converting every image can
 * outlast the host's time limit (a 503 from the proxy), so the browser uploads the ZIP once,
 * asks for a few seconds of image work at a time, then asks for the swap.
 * The job lives in data/tmp/log-restore-job-<token>/ and belongs to the session that started it.
 */
const NL_RESTORE_STEP_SECONDS = 4;
function nl_restore_job_dir(string $token): string
{
    if (!preg_match('/\A[a-f0-9]{16}\z/', $token) || !hash_equals((string)($_SESSION['nl_restore'] ?? ''), $token) || !is_file(NM_DATA . '/tmp/log-restore-job-' . $token . '/job.php')) throw new UnexpectedValueException(nm_t('復元の受付が切れました。もう一度ZIPを選んでください'));
    return NM_DATA . '/tmp/log-restore-job-' . $token;
}
function nl_restore_job_write(string $dir, array $job): void
{
    // Not nm_write_atomic: a job's progress is not site content and must not mark caches stale.
    $file = $dir . '/job.php';
    if (file_put_contents($file . '.tmp', "<?php\nif (!defined('NAGIMANGA')) { http_response_code(404); exit; }\nreturn " . var_export($job, true) . ";\n", LOCK_EX) === false || !rename($file . '.tmp', $file)) throw new RuntimeException('write failed');
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
}
/** Receive the ZIP and check everything but the images. */
function nl_restore_begin(array $file, bool $overwrite): array
{
    if (!nm_zip_available()) throw new UnexpectedValueException(nm_t('ZipArchiveがありません'));
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new UnexpectedValueException(nm_t('バックアップを受け取れませんでした'));
    // Jobs left by a closed tab.
    foreach (glob(NM_DATA . '/tmp/log-restore-job-*', GLOB_ONLYDIR) ?: [] as $old) if (filemtime($old) < time() - 86400) nm_rmdir_recursive($old);
    $token = bin2hex(random_bytes(8));
    $dir = NM_DATA . '/tmp/log-restore-job-' . $token;
    nm_ensure_dir($dir);
    try {
        if (!move_uploaded_file($file['tmp_name'], $dir . '/backup.zip')) throw new RuntimeException('move failed');
        $zip = new ZipArchive();
        if ($zip->open($dir . '/backup.zip', ZipArchive::RDONLY) !== true) throw new UnexpectedValueException(nm_t('ZIPを開けませんでした'));
        try { $plan = nl_restore_plan($zip); } finally { $zip->close(); }
        nl_restore_job_write($dir, ['plan' => $plan, 'overwrite' => $overwrite, 'ids' => array_map('strval', array_keys($plan['media'])), 'media' => []]);
    } catch (Throwable $e) { nm_rmdir_recursive($dir); throw $e; }
    $_SESSION['nl_restore'] = $token;
    return ['token' => $token, 'done' => 0, 'total' => count($plan['media']), 'posts' => count($plan['posts'])];
}
/** Convert images for a few seconds, at least one. */
function nl_restore_step(string $token): array
{
    $dir = nl_restore_job_dir($token);
    return nm_with_lock('log-restore', static function () use ($dir) {
        $job = nl_read_record($dir . '/job.php');
        if (!$job) throw new UnexpectedValueException(nm_t('復元の受付が切れました。もう一度ZIPを選んでください'));
        $total = count($job['ids']);
        $zip = new ZipArchive();
        if ($zip->open($dir . '/backup.zip', ZipArchive::RDONLY) !== true) throw new UnexpectedValueException(nm_t('ZIPを開けませんでした'));
        try {
            $start = microtime(true);
            while (count($job['media']) < $total) {
                $id = $job['ids'][count($job['media'])];
                $job['media'][$id] = nl_restore_image($zip, $dir, $id, $job['plan']['media'][$id]);
                if (microtime(true) - $start >= NL_RESTORE_STEP_SECONDS) break;
            }
        } catch (Throwable $e) { $zip->close(); nm_rmdir_recursive($dir); throw $e; }
        $zip->close();
        nl_restore_job_write($dir, $job);
        return ['done' => count($job['media']), 'total' => $total];
    });
}
/** Swap everything in once the images are ready. */
function nl_restore_finish(string $token): array
{
    $dir = nl_restore_job_dir($token);
    try {
        return nm_with_lock('log-restore', static function () use ($dir) {
            $job = nl_read_record($dir . '/job.php');
            if (!$job || count($job['media']) < count($job['ids'])) throw new UnexpectedValueException(nm_t('画像の復元が終わっていません'));
            return nl_restore_apply($dir, $job['plan'], $job['media'], $job['overwrite']);
        });
    } finally { nm_rmdir_recursive($dir); unset($_SESSION['nl_restore']); }
}
function nl_restore_cancel(string $token): void
{
    try { $dir = nl_restore_job_dir($token); } catch (UnexpectedValueException) { return; }
    nm_with_lock('log-restore', static fn() => nm_rmdir_recursive($dir));
    unset($_SESSION['nl_restore']);
}
