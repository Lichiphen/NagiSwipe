<?php
/* Personal LOG admin screens. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_page_number(): int { return max(1, min(100000, (int)nm_str($_GET, 'page', 6))); }
function nl_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(nm_csrf_token()) . '">'; }
function nl_json_list(string $raw): array
{
    try {
        // Associative decoding converts {"0":...} into a PHP list; check JSON's own type first.
        if (!is_array(json_decode($raw, false, 512, JSON_THROW_ON_ERROR))) throw new UnexpectedValueException(nm_t('選んだ内容を確認してください'));
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) { throw new UnexpectedValueException(nm_t('選んだ内容を確認してください'), 0, $e); }
}
function nl_revision_input(): int
{
    $revision = nm_str($_POST, 'revision', 20);
    if (!preg_match('/\A[0-9]{1,10}\z/', $revision)) throw new UnexpectedValueException(nm_t('更新情報を確認して、画面を開き直してください'));
    return (int)$revision;
}
function nl_admin_media_data(array $m): array
{
    return array_merge($m, ['url' => nl_media_url($m, false, true), 'thumb' => nl_media_url($m, true, true), 'tag' => '[Image:' . $m['id'] . ']']);
}
function nl_admin_catalog(bool $manga): never
{
    // Images typed or pasted into the editor as tags: their thumbnails and ratings, by id.
    if (!$manga && isset($_GET['ids'])) {
        $out = [];
        foreach (array_slice(explode(',', nm_str($_GET, 'ids', 2000)), 0, 100) as $id) if ($m = nl_load_media($id)) $out[] = nl_admin_media_data($m);
        nm_json(['items' => $out, 'more' => false]);
    }
    $page = nl_page_number();
    $q = trim(nm_str($_GET, 'q', 200));
    $items = $manga ? nm_list_works() : nl_list_media();
    $counts = [];
    if ($manga) foreach ($items as $w) $counts[$w['title']] = ($counts[$w['title']] ?? 0) + 1;
    $items = array_values(array_filter($items, static fn($i) => (!$manga || !empty($i['pages'])) && ($q === '' || mb_stripos($i[$manga ? 'title' : 'alt'], $q) !== false)));
    $total = count($items);
    $items = array_slice($items, ($page - 1) * 30, 30);
    $out = [];
    foreach ($items as $i) {
        if (!$manga) { $out[] = nl_admin_media_data($i); continue; }
        $label = mb_substr(trim((string)preg_replace('/[\[\]\r\n]/u', ' ', $i['title'])), 0, 200) ?: nm_t('無題');
        // Include the ID for same-title works and titles containing tag delimiters.
        if (($counts[$i['title']] ?? 0) > 1 || $label !== $i['title']) $label .= ' #' . $i['id'];
        $out[] = ['id' => $i['id'], 'title' => $i['title'], 'thumb' => nm_thumb_url($i), 'tag' => '[Manga' . $label . ']', 'locked' => !empty($i['password_hash'])];
    }
    nm_json(['items' => $out, 'more' => $page * 30 < $total]);
}
function nl_input_post(): array
{
    $body = $_POST['body'] ?? '';
    if (!is_string($body)) throw new UnexpectedValueException(nm_t('本文を確認してください'));
    $rawRefs = nm_str($_POST, 'manga', 50000);
    $refs = json_decode($rawRefs, true);
    return ['id' => nm_str($_POST, 'post_id', 24), 'revision' => (int)nm_str($_POST, 'revision', 10),
        'nonce' => nm_str($_POST, 'nonce', 32), 'title' => nm_str($_POST, 'title', 1000), 'body' => $body,
        'status' => nm_str($_POST, 'status', 20), 'manga' => is_array($refs) ? $refs : [], 'categories' => $_POST['categories'] ?? [], 'new_categories' => nm_str($_POST, 'new_categories', 4000),
        'rating' => nm_str($_POST, 'rating', 20), 'warning' => nm_str($_POST, 'warning', 400), 'media_ratings' => nm_str($_POST, 'media_ratings', 20000)];
}
function nl_handle_post(string $do): never
{
    try {
        switch ($do) {
            case 'log_save':
                $input = nl_input_post();
                // Blog cards are fetched here, outside the save lock, so a slow site never blocks saving.
                if (is_string($input['body'] ?? null) && strlen($input['body']) <= 100000) nl_cards_prepare($input['body']);
                $p = nl_save_post($input);
                nm_json(['ok' => true, 'id' => $p['id'], 'redirect' => 'index.php?p=log&saved=' . rawurlencode($p['id'])]);
            case 'log_preview':
                $p = nl_input_post();
                nl_validate_body($p['body']);
                nl_cards_prepare($p['body']);
                $p['manga'] = nl_manga_refs($p['body'], $p['manga']);
                // Show the ratings chosen in the editor before they are saved.
                [$p['rating'], $p['warning']] = nl_rating_input($p);
                $p['media'] = nl_media_refs($p['body']);
                $p['_media_ratings'] = nl_media_ratings_input($p['media_ratings'], $p['media']);
                nm_json(['html' => '<h2>' . h(nl_post_title($p, 0)) . '</h2>' . nl_render_body($p, true)]);
            case 'log_upload':
                $file = $_FILES['image'] ?? null;
                if (!is_array($file)) throw new UnexpectedValueException(nm_t('画像を選んでください'));
                $m = nl_upload_media($file, nm_str($_POST, 'media', 16), (int)nm_str($_POST, 'revision', 10));
                nm_json(['ok' => true, 'media' => nl_admin_media_data($m)]);
            case 'log_delete':
            case 'log_bulk_delete':
                $items = $do === 'log_delete' ? [['id' => nm_str($_POST, 'post_id', 24), 'revision' => nl_revision_input()]] : nl_json_list(nm_str($_POST, 'posts', 30000));
                $result = nl_delete_posts($items);
                // From a post on the public page: back to its top (the post is gone).
                if ($do === 'log_delete' && nm_str($_POST, 'back', 10) === 'public') { header('Location: ../', true, 303); exit; }
                nm_flash($result['clean'] ? 'ok' : 'err', $result['clean'] ? nm_t('{posts}件の記事と、専用画像{media}件を削除しました。共有画像は残しています', ['posts' => $result['posts'], 'media' => $result['media']]) : nm_t('記事は削除しましたが、画像の掃除が完了していません。保存先の権限を確認してください'));
                nm_redirect('p=log');
            case 'log_media_alt':
                nm_with_lock('personal-log', static function () {
                    $m = nl_load_media(nm_str($_POST, 'media', 16));
                    if (!$m) throw new UnexpectedValueException(nm_t('画像が見つかりません'));
                    if ($m['revision'] !== (int)nm_str($_POST, 'revision', 10)) throw new UnexpectedValueException(nm_t('別の画面で更新されています。開き直してください'));
                    $m['alt'] = mb_substr(trim(nm_str($_POST, 'alt', 1500)), 0, 300);
                    $rating = nm_str($_POST, 'rating', 20);
                    if ($rating !== '' && !isset(NL_RATINGS[$rating])) throw new UnexpectedValueException(nm_t('閲覧注意を選び直してください'));
                    $rated = nl_rating($m['rating'] ?? '') !== $rating;
                    $m['rating'] = $rating;
                    $m['revision']++;
                    $m['updated'] = time();
                    nl_write_record(nl_media_dir($m['id']) . '/media.php', $m);
                    nm_touch_content();
                    // Lists and tiles read a post's rating from the summary index.
                    if ($rated) nl_rebuild_index();
                });
                nm_flash('ok', nm_t('画像の説明と閲覧注意を保存しました'));
                nm_redirect(nl_media_back());
            case 'log_media_delete':
                nm_with_lock('personal-log', static function () {
                    $id = nm_str($_POST, 'media', 16);
                    $m = nl_load_media($id);
                    if (!$m) throw new UnexpectedValueException(nm_t('画像が見つかりません'));
                    if ($m['revision'] !== (int)nm_str($_POST, 'revision', 10)) throw new UnexpectedValueException(nm_t('別の画面で更新されています。開き直してください'));
                    if (nl_settings()['icon'] === $id) throw new UnexpectedValueException(nm_t('アイコンで使っている画像は削除できません'));
                    if (nl_settings()['og_image'] === $id) throw new UnexpectedValueException(nm_t('OGPで使っている画像は削除できません'));
                    if (nl_settings()['logo'] === $id) throw new UnexpectedValueException(nm_t('タイトルロゴで使っている画像は削除できません'));
                    if (in_array($id, nl_pages_media_ids(), true)) throw new UnexpectedValueException(nm_t('固定ページで使っている画像は削除できません'));
                    if (in_array($id, nl_sidebar_media_ids(), true)) throw new UnexpectedValueException(nm_t('サイドバーで使っている画像は削除できません'));
                    foreach (nl_post_summaries(false) as $p) if (in_array($id, $p['media'], true)) throw new UnexpectedValueException(nm_t('投稿や下書きで使っている画像は削除できません'));
                    nm_rmdir_recursive(nl_media_dir($id));
                    nm_touch_content();
                });
                nm_flash('ok', nm_t('未使用の画像を削除しました'));
                nm_redirect(nl_media_back());
            case 'log_settings':
                $design = nl_design_input();
                $uploads = nl_design_uploads();
                nl_settings_update([static fn(array $s) => nl_design_apply($s, $design, $uploads)]);
                nm_flash('ok', nm_t('LOGの設定を保存しました'));
                nm_redirect('p=settings&section=log');
            case 'log_preferences':
                nl_settings_update([nl_preferences_input()]);
                nm_flash('ok', nm_str($_POST, 'visibility', 20) === 'public' ? nm_t('LOGを全体公開にしました') : nm_t('LOGを自分専用のMemoにしました。画像もログイン時だけ読めます'));
                nm_redirect('p=settings&section=log');
            case 'log_display_settings':
                nl_settings_update([nl_display_input()]);
                nm_flash('ok', nm_t('一覧と記事の下の表示を保存しました')); nm_redirect('p=settings&section=log#log-display');
            case 'log_seo_settings':
                nl_settings_update([nl_seo_input()]);
                nm_flash('ok', nm_str($_POST, 'search_engines', 10) === 'allow' ? nm_t('検索エンジンに載せる設定にしました') : nm_t('すべてのページを検索エンジンに載せない設定にしました'));
                nm_redirect('p=settings&section=log#log-seo');
            case 'log_like_set':
                $id = nm_str($_POST, 'post_id', 24); $count = nm_str($_POST, 'count', 12);
                if (!nl_load_post($id)) throw new UnexpectedValueException(nm_t('投稿が見つかりません'));
                if (!empty($_POST['reset'])) $count = '0';
                if (!preg_match('/\A[0-9]{1,8}\z/', $count)) throw new UnexpectedValueException(nm_t('いいねの数は0〜{max}で入力してください', ['max' => NL_LIKE_MAX]));
                nl_like_set($id, (int)$count);
                nm_flash('ok', (int)$count > 0 ? nm_t('いいねの数を{n}にしました', ['n' => (int)$count]) : nm_t('いいねを削除しました'));
                nm_redirect('p=log_edit&id=' . rawurlencode($id) . '#log-likes');
            case 'log_sidebar_settings':
                $raw = $_POST['items'] ?? '';
                if (!is_string($raw) || strlen($raw) > 1024 * 1024 || !mb_check_encoding($raw, 'UTF-8')) throw new UnexpectedValueException(nm_t('サイドバー全体のHTMLを1MB以内にしてください'));
                $revision = nl_sidebar_save(nl_json_list($raw), nl_revision_input());
                nm_json(['ok' => true, 'revision' => $revision, 'items' => nl_sidebar_settings()['items']]);
            case 'log_backup': nl_backup_download();
            case 'log_footer_settings':
                nl_settings_update([nl_footer_input()]);
                nm_flash('ok', nm_t('フッターを保存しました')); nm_redirect('p=settings&section=log#log-footer');
            case 'log_page_save':
                $page = nl_page_save(nm_str($_POST, 'page_id', 14), nl_revision_input(), nl_page_input(['title' => nm_str($_POST, 'title', 1000), 'slug' => nm_str($_POST, 'slug', 200), 'body' => is_string($_POST['body'] ?? null) ? $_POST['body'] : '', 'layout' => nm_str($_POST, 'layout', 10), 'status' => nm_str($_POST, 'status', 10)]));
                nm_flash('ok', $page['status'] === 'published' ? nm_t('固定ページを公開しました') : nm_t('固定ページを下書きとして保存しました'));
                // The editor sends with fetch so a refused save keeps the text on screen.
                if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) nm_json(['ok' => true, 'redirect' => 'index.php?p=log_page&id=' . $page['id']]);
                nm_redirect('p=log_page&id=' . $page['id']);
            case 'log_page_preview':
                $body = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
                if (strlen($body) > NL_PAGE_BODY_MAX || !mb_check_encoding($body, 'UTF-8')) throw new UnexpectedValueException(nm_t('本文は200KB以内にしてください'));
                nm_json(['html' => '<h1>' . h(trim(nm_str($_POST, 'title', 1000)) ?: nm_t('（タイトル）')) . '</h1>' . nl_markdown($body, true)]);
            case 'log_page_delete':
                nl_page_delete(nm_str($_POST, 'page_id', 14), nl_revision_input());
                nm_flash('ok', nm_t('固定ページを削除しました'));
                nm_redirect('p=log_pages');
            case 'log_taxonomy_rename':
                nl_taxonomy_rename(['kind' => nm_str($_POST, 'kind', 10), 'old' => nm_str($_POST, 'old', 250), 'name' => nm_str($_POST, 'name', 250), 'revision' => (int)nm_str($_POST, 'revision', 10)]);
                nm_flash('ok', nm_t('分類の名前を保存しました。ハッシュタグは本文にも反映しています'));
                nm_redirect('p=log&view=taxonomy');
            case 'log_taxonomy_order':
                $order = nl_json_list(nm_str($_POST, 'order', 100000));
                $revision = nl_taxonomy_reorder(nm_str($_POST, 'kind', 10), $order, nl_revision_input());
                nm_json(['ok' => true, 'revision' => $revision]);
            case 'log_guard_settings':
                nm_save_config(nl_guard_input(nm_config()));
                nm_flash('ok', nm_t('画像収集BOTへの対策を保存しました'));
                nm_redirect('p=settings&section=common#log-guard');
            case 'log_restore_begin':
                nm_json(['ok' => true] + nl_restore_begin($_FILES['backup'] ?? [], !empty($_POST['overwrite'])));
            case 'log_restore_step':
                nm_json(['ok' => true] + nl_restore_step(nm_str($_POST, 'token', 16)));
            case 'log_restore_finish':
                $r = nl_restore_finish(nm_str($_POST, 'token', 16));
                nm_flash('ok', nm_t('LOGを復元しました（投稿 {posts}件・画像 {media}件）', ['posts' => $r['posts'], 'media' => $r['media']]));
                nm_json(['ok' => true, 'redirect' => 'index.php?p=log'] + $r);
            case 'log_restore_cancel':
                nl_restore_cancel(nm_str($_POST, 'token', 16));
                nm_json(['ok' => true]);
            case 'log_restore':
                $f = $_FILES['backup'] ?? [];
                if (($f['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) throw new UnexpectedValueException(nm_t('バックアップを受け取れませんでした'));
                $r = nl_backup_restore($f['tmp_name'], !empty($_POST['overwrite']));
                nm_flash('ok', nm_t('LOGを復元しました（投稿 {posts}件・画像 {media}件）', ['posts' => $r['posts'], 'media' => $r['media']]));
                nm_redirect('p=log');
            default: nm_not_found();
        }
    } catch (Throwable $e) {
        $message = $e instanceof UnexpectedValueException ? $e->getMessage() : nm_t('保存できませんでした。空き容量や書き込み権限を確認してください');
        nm_log('log_error', $e->getMessage());
        if (in_array($do, ['log_save', 'log_preview', 'log_upload', 'log_taxonomy_order', 'log_sidebar_settings', 'log_page_preview'], true) || str_starts_with($do, 'log_restore_')
            || ($do === 'log_page_save' && str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'))) nm_json(['error' => nm_t($message)], 422);
        nm_flash('err', $message);
        nm_redirect(match ($do) { 'log_settings', 'log_preferences', 'log_footer_settings', 'log_display_settings', 'log_seo_settings' => 'p=settings&section=log', 'log_like_set' => 'p=log_edit&id=' . rawurlencode(nm_str($_POST, 'post_id', 24)) . '#log-likes', 'log_guard_settings' => 'p=settings&section=common#log-guard', 'log_taxonomy_rename' => 'p=log&view=taxonomy', 'log_page_save' => 'p=log_page' . (preg_match(NL_PAGE_ID_PATTERN, nm_str($_POST, 'page_id', 14)) ? '&id=' . nm_str($_POST, 'page_id', 14) : ''), 'log_page_delete' => 'p=log_pages', default => str_starts_with($do, 'log_media_') ? nl_media_back() : 'p=log' });
    }
}
/*
 * LOG settings blocks. Each *_input() checks its fields from $_POST and returns the change
 * without writing, so the settings screen can check every block before saving any of them.
 */
/** Apply changes to settings.php in one write, under the lock. */
function nl_settings_update(array $changes): void
{
    nm_with_lock('personal-log', static function () use ($changes) {
        $s = nl_settings();
        foreach ($changes as $change) $s = $change($s);
        $s['updated'] = time();
        nl_write_record(nl_root() . '/settings.php', $s);
    });
}
function nl_preferences_input(): Closure
{
    $visibility = nm_str($_POST, 'visibility', 20);
    $count = nm_str($_POST, 'posts_per_page', 20);
    if (!in_array($visibility, ['public', 'private'], true) || !preg_match('/\A[0-9]{1,3}\z/', $count) || (int)$count < 1 || (int)$count > 100) throw new UnexpectedValueException(nm_t('公開範囲と表示件数を確認してください'));
    // Older forms still post this; the sidebar's login row owns it now.
    $login = array_key_exists('show_login', $_POST) ? nm_str($_POST, 'show_login', 1) === '1' : null;
    // Forms without the paging fieldset (older pages) leave these as they were.
    $pager = null;
    if (isset($_POST['pager_form'])) {
        $pager = nm_str($_POST, 'pager', 10);
        if (!isset(NL_PAGERS[$pager])) throw new UnexpectedValueException(nm_t('ページ送りの形式を選んでください'));
    }
    $status = nm_str($_POST, 'pager_status', 1) === '1'; $nav = nm_str($_POST, 'post_nav', 1) === '1';
    return static function (array $s) use ($visibility, $count, $login, $pager, $status, $nav): array {
        $s['public'] = $visibility === 'public';
        if ($login !== null) $s['show_login'] = $login;
        $s['posts_per_page'] = (int)$count;
        if ($pager !== null) { $s['pager'] = $pager; $s['pager_status'] = $status; $s['post_nav'] = $nav; }
        return $s;
    };
}
function nl_display_input(): Closure
{
    $layout = nm_str($_POST, 'layout', 10); $by = nm_str($_POST, 'related_by', 10); $order = nm_str($_POST, 'related_order', 10);
    if (!isset(NL_LAYOUTS[$layout], NL_RELATED_BY[$by], NL_RELATED_ORDER[$order])) throw new UnexpectedValueException(nm_t('一覧の形と関連記事の設定を選んでください'));
    $likes = nm_str($_POST, 'likes', 1) === '1'; $related = nm_str($_POST, 'related', 1) === '1';
    $days = isset($_POST['new_days']) ? max(0, min(NL_NEW_DAYS_MAX, (int)nm_str($_POST, 'new_days', 3))) : null;
    $label = isset($_POST['new_label']) ? nl_new_label(nm_str($_POST, 'new_label', 200)) : null;
    // Forms without the breadcrumb fieldset (older pages) leave it as it was.
    $crumb = null;
    if (isset($_POST['crumb_home'])) {
        $crumb = [nm_str($_POST, 'crumb_home', 10), nl_crumb_label(nm_str($_POST, 'crumb_label', 300))];
        if (!isset(NL_CRUMB_HOMES[$crumb[0]])) throw new UnexpectedValueException(nm_t('パンくずリストの先頭を選んでください'));
    }
    return static function (array $s) use ($layout, $by, $order, $likes, $related, $days, $label, $crumb): array {
        $s['layout'] = $layout; $s['related_by'] = $by; $s['related_order'] = $order;
        $s['likes'] = $likes; $s['related'] = $related;
        if ($days !== null) $s['new_days'] = $days;
        if ($label !== null) $s['new_label'] = $label;
        if ($crumb !== null) [$s['crumb_home'], $s['crumb_label']] = $crumb;
        return $s;
    };
}
function nl_seo_input(): Closure
{
    $engines = nm_str($_POST, 'search_engines', 10);
    if (!in_array($engines, ['allow', 'block'], true)) throw new UnexpectedValueException(nm_t('検索エンジンに載せるかを選んでください'));
    return static function (array $s) use ($engines): array { $s['search_engines'] = $engines === 'allow'; return $s; };
}
/** The HOME link at the start of the footer links (a field of the footer links block). */
function nl_footer_home_input(): Closure
{
    $home = nm_str($_POST, 'footer_home', 1) === '1';
    return static function (array $s) use ($home): array { $s['footer_home'] = $home; return $s; };
}
function nl_footer_input(): Closure
{
    $text = $_POST['footer_text'] ?? '';
    if (!is_string($text) || mb_strlen($text) > 200 || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $text)) throw new UnexpectedValueException(nm_t('フッターは200文字以内の1行で入力してください'));
    $show = nm_str($_POST, 'show_footer', 1) === '1';
    return static function (array $s) use ($text, $show): array { $s['footer_text'] = trim($text); $s['show_footer'] = $show; return $s; };
}
/** The compose box's ready-made warning notes, one per line. */
function nl_warning_presets_input(): Closure
{
    $raw = $_POST['warning_presets'] ?? '';
    if (!is_string($raw) || strlen($raw) > 16000 || !mb_check_encoding($raw, 'UTF-8')) throw new UnexpectedValueException(nm_t('閲覧注意の定型文を確認してください'));
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), 'strlen'));
    if (count($lines) > NL_WARNING_PRESETS_MAX) throw new UnexpectedValueException(nm_t('閲覧注意の定型文は{n}個までにしてください', ['n' => NL_WARNING_PRESETS_MAX]));
    foreach ($lines as $line) if (mb_strlen($line) > NL_WARNING_MAX) throw new UnexpectedValueException(nm_t('閲覧注意の定型文は1行{n}文字以内にしてください（{line}）', ['n' => NL_WARNING_MAX, 'line' => mb_strimwidth($line, 0, 30, '…', 'UTF-8')]));
    $list = nl_warning_presets($lines);
    return static function (array $s) use ($list): array { $s['warning_presets'] = $list; return $s; };
}
/** Site name, profile and theme; the pictures are uploaded separately (nl_design_uploads) once everything checks out. */
function nl_design_input(): array
{
    $s = ['title' => mb_substr(trim(nm_str($_POST, 'title', 600)), 0, 100), 'description' => mb_substr(trim(nm_str($_POST, 'description', 1500)), 0, 300), 'name' => mb_substr(trim(nm_str($_POST, 'name', 300)), 0, 100)];
    if ($s['title'] === '' || $s['name'] === '') throw new UnexpectedValueException(nm_t('サイト名と名前を入力してください'));
    $s['theme'] = nm_str($_POST, 'theme', 30) ?: nl_settings()['theme'];
    if (!isset(NL_THEMES[$s['theme']])) throw new UnexpectedValueException(nm_t('デザインを6種類から選んでください'));
    // Forms without the switch (older pages, scripts) leave it as it was.
    $s['show_description'] = isset($_POST['description_form']) ? nm_str($_POST, 'show_description', 1) === '1' : nl_settings()['show_description'];
    return $s + ['remove_icon' => !empty($_POST['remove_icon']), 'remove_og_image' => !empty($_POST['remove_og_image']), 'remove_logo' => !empty($_POST['remove_logo'])];
}
/** Upload outside the settings lock; the media library takes the same lock. */
function nl_design_uploads(): array
{
    $ids = ['icon' => null, 'og_image' => null, 'logo' => null];
    // Share cards need a bitmap: refuse an SVG before anything is stored.
    $og = $_FILES['og_image'] ?? null;
    if (is_array($og) && ($og['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($og['tmp_name'] ?? null) && is_uploaded_file($og['tmp_name']) && nl_svg_upload($og['tmp_name'], (string)($og['name'] ?? ''))) throw new UnexpectedValueException(nm_t('共通のOGP画像にはSVGを使えません。PNGかJPEGを選んでください'));
    foreach ($ids as $name => $_) {
        $file = $_FILES[$name] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $ids[$name] = nl_upload_media($file)['id'];
    }
    return $ids;
}
function nl_design_apply(array $s, array $design, array $uploads): array
{
    $s['icon'] = $uploads['icon'] ?? ($design['remove_icon'] ? '' : $s['icon']);
    $s['og_image'] = $uploads['og_image'] ?? ($design['remove_og_image'] ? '' : $s['og_image']);
    $s['logo'] = $uploads['logo'] ?? ($design['remove_logo'] ? '' : $s['logo']);
    unset($design['remove_icon'], $design['remove_og_image'], $design['remove_logo']);
    return array_replace($s, $design);
}
/** The sidebar from the settings screen: checked here, saved with nl_sidebar_save after the other blocks. */
function nl_sidebar_input(): array
{
    $raw = $_POST['sidebar_items'] ?? '';
    if (!is_string($raw) || strlen($raw) > 1024 * 1024 || !mb_check_encoding($raw, 'UTF-8')) throw new UnexpectedValueException(nm_t('サイドバー全体のHTMLを1MB以内にしてください'));
    $items = nl_json_list($raw);
    nl_sidebar_validate($items);
    $revision = nm_str($_POST, 'sidebar_revision', 20);
    if (!preg_match('/\A[0-9]{1,10}\z/', $revision)) throw new UnexpectedValueException(nm_t('更新情報を確認して、画面を開き直してください'));
    if (nl_sidebar_settings()['revision'] !== (int)$revision) throw new UnexpectedValueException(nm_t('別の画面でサイドバーが更新されています。開き直してください'));
    return [$items, (int)$revision];
}
/** Image collector guard (kept in config.php). */
function nl_guard_input(array $cfg): array
{
    $burst = (int)nm_str($_POST, 'burst', 4); $minute = (int)nm_str($_POST, 'minute', 4);
    if ($burst < 10 || $burst > 1000 || $minute < 60 || $minute > 6000) throw new UnexpectedValueException(nm_t('画像の上限を指定された範囲で入力してください'));
    $agents = preg_split('/\r?\n/', nm_str($_POST, 'agents', 4000), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $agents = array_values(array_unique(array_map('trim', $agents)));
    if (count($agents) > 50) throw new UnexpectedValueException(nm_t('ツール名は50個まで指定できます'));
    foreach ($agents as $name) if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new UnexpectedValueException(nm_t('収集ツール名を確認してください'));
    $cfg['image_guard_enabled'] = !empty($_POST['enabled']); $cfg['image_guard_burst'] = $burst; $cfg['image_guard_minute'] = $minute; $cfg['image_guard_agents'] = $agents;
    return $cfg;
}
/** Line icons for the editor's tool row (24px grid, drawn with the current colour). */
function nl_compose_icon(string $name): string
{
    $paths = [
        'upload' => '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="m3.5 16 4.6-4.6a1.5 1.5 0 0 1 2.1 0L15 16"/><path d="M18 2.5v5M15.5 5h5"/>',
        'media' => '<rect x="7" y="3.5" width="13.5" height="13.5" rx="2.2"/><path d="M4 7.5v10.3A2.7 2.7 0 0 0 6.7 20.5H17"/><circle cx="11.5" cy="8" r="1.4"/><path d="m7 15 3.6-3.4a1.4 1.4 0 0 1 1.9 0L17 16"/>',
        'manga' => '<path d="M4 5.5c2.8-1.2 5.5-1 8 .8 2.5-1.8 5.2-2 8-.8v13c-2.8-1.2-5.5-1-8 .8-2.5-1.8-5.2-2-8-.8Z"/><path d="M12 6.3v13"/>',
        'bold' => '<path d="M7 4.5h5.8a3.6 3.6 0 0 1 0 7.2H7Zm0 7.2h6.6a3.9 3.9 0 0 1 0 7.8H7Z"/>',
        'tags' => '<path d="M9.5 4 7.5 20M16.5 4l-2 16M4.5 9h15M3.5 15.5h15"/>',
        'categories' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'rating' => '<path d="M10.3 4.2a2 2 0 0 1 3.4 0l7.6 13.1a2 2 0 0 1-1.7 3H4.4a2 2 0 0 1-1.7-3Z"/><path d="M12 9.5v4.2M12 16.9v.1"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1.1.9-1.1 1.6v.6M12 16.9v.1"/>',
        'preview' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg class="log-tool-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}
/**
 * The editor: the text, the pictures in it, chips for what is set, and one row of tools.
 * Hashtags, categories, the content warning and the help open one at a time under the row.
 */
function nl_editor(?array $p = null, bool $public = false): string
{
    $edit = $p !== null;
    $nonce = bin2hex(random_bytes(16));
    $categories = '';
    foreach (nl_taxonomy()['categories'] as $id => $label) $categories .= '<label class="log-category-choice"><input type="checkbox" name="categories[]" value="' . h((string)$id) . '"' . (in_array((string)$id, $p['categories'] ?? [], true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    $recent = '';
    foreach (nl_recent_hashtags() as $name) $recent .= '<button type="button" class="log-tag-chip" data-hashtag="' . h($name) . '">#' . h($name) . '</button>';
    // The pictures already in the post, so their thumbnails and ratings show before anything is added.
    $media = [];
    foreach ($p['media'] ?? [] as $mid) if ($m = nl_load_media((string)$mid)) $media[$m['id']] = ['thumb' => nl_media_url($m, true, true), 'alt' => $m['alt'], 'rating' => nl_rating($m['rating'] ?? '')];
    $rating = nl_rating($p['rating'] ?? '');
    $order = ['', 'sensitive', 'r18', 'r18g'];
    $ratings = '';
    foreach ($order as $value) $ratings .= '<label class="log-rating-choice" data-veil="' . h($value) . '"><input type="radio" name="rating" value="' . h($value) . '"' . ($value === $rating ? ' checked' : '') . '><span><b>' . h(nm_t(NL_RATINGS[$value])) . '</b><small>' . h(nm_t(NL_RATING_NOTES[$value])) . '</small></span></label>';
    $menu = '';
    foreach ($order as $value) $menu .= '<button type="button" role="menuitemradio" aria-checked="false" data-veil="' . h($value) . '" data-rate="' . h($value) . '">' . h(nm_t(NL_RATINGS[$value])) . '</button>';
    $tool = static fn(string $name, string $label, string $attrs) => '<button type="button" class="log-tool" ' . $attrs . ' aria-label="' . h($label) . '" title="' . h($label) . '">' . nl_compose_icon($name) . '</button>';
    $panel = static fn(string $name, string $label) => $tool($name, $label, 'data-panel="' . $name . '" aria-expanded="false" aria-controls="log-panel-' . $name . '"');
    return '<section class="log-compose' . ($edit ? ' log-edit active' : '') . '" id="log-compose" aria-labelledby="log-compose-title"' . ($edit ? ' data-edit="1"' : '') . '>'
        . '<div class="log-compose-head"><h2 id="log-compose-title">' . ($edit ? nm_t('投稿を編集') : nm_t('いま、何を残す？')) . '</h2><button type="button" class="btn log-close">' . nm_t('閉じる') . '</button></div>'
        . '<form method="post" action="' . ($public ? 'admin/index.php' : 'index.php') . '" data-log-editor' . ($public ? ' data-public="1"' : '') . '>' . nl_csrf_field()
        . '<input type="hidden" name="do" value="log_save"><input type="hidden" name="post_id" value="' . h($p['id'] ?? '') . '">'
        . '<input type="hidden" name="revision" value="' . (int)($p['revision'] ?? 0) . '"><input type="hidden" name="nonce" value="' . $nonce . '">'
        . '<input type="hidden" name="manga" value="' . h(json_encode($p['manga'] ?? (object)[], JSON_UNESCAPED_UNICODE)) . '"><input type="hidden" name="media_ratings" value="{}">'
        . '<label class="sr-only" for="log-body">' . nm_t('投稿本文') . '</label><textarea id="log-body" name="body" rows="5" maxlength="100000" placeholder="' . nm_t('1行目がタイトルになります。&#10;今日のこと、描いた絵、ふと思ったこと。') . '" required>' . h($p['body'] ?? '') . '</textarea>'
        . '<div class="log-attachments" data-attachments data-media="' . h(json_encode((object)$media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"></div>'
        . '<div class="log-att-menu" data-att-menu role="menu" aria-label="' . nm_t('この画像の閲覧注意') . '" hidden><p class="log-att-menu-title" aria-hidden="true">' . nm_t('この画像の閲覧注意') . '</p>' . $menu . '</div>'
        . '<div class="log-compose-chips" data-chips></div>'
        . '<div class="log-compose-bar" role="toolbar" aria-label="' . nm_t('投稿の道具') . '"><div class="log-compose-tools">'
        . $tool('upload', nm_t('画像を追加'), 'data-upload') . $tool('media', nm_t('画像一覧から選ぶ'), 'data-picker="media"') . $tool('manga', nm_t('漫画を選ぶ'), 'data-picker="manga"') . $tool('bold', nm_t('太字'), 'data-bold')
        . '<span class="log-tool-sep" aria-hidden="true"></span>' . $panel('tags', nm_t('ハッシュタグ')) . $panel('categories', nm_t('カテゴリ')) . $panel('rating', nm_t('閲覧注意')) . $panel('help', nm_t('使い方'))
        . '</div>' . $tool('preview', nm_t('プレビュー'), 'data-preview aria-pressed="false"') . '</div>'
        . '<input type="file" data-upload-input accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden>'
        . '<div class="log-compose-panel" id="log-panel-tags" data-panel-body="tags" hidden><p class="log-panel-title">' . nm_t('最近使ったハッシュタグ') . '</p><div class="log-chip-list">' . ($recent ?: '<span class="note">' . nm_t('本文に #らくがき のように書くと、ここに並びます。') . '</span>') . '</div></div>'
        . '<div class="log-compose-panel" id="log-panel-categories" data-panel-body="categories" hidden><p class="log-panel-title">' . nm_t('カテゴリ') . '</p><div class="log-chip-list">' . ($categories ?: '<p class="note">' . nm_t('下の欄から作れます。') . '</p>') . '</div><label class="log-panel-field">' . nm_t('新しいカテゴリ') . '<input name="new_categories" maxlength="800" placeholder="' . nm_t('例：日記、制作メモ（「、」で区切って複数）') . '"></label></div>'
        . '<div class="log-compose-panel" id="log-panel-rating" data-panel-body="rating" hidden><fieldset class="log-rating"><legend class="log-panel-title">' . nm_t('閲覧注意') . '</legend><div class="log-rating-choices">' . $ratings . '</div></fieldset>'
        . '<label class="log-panel-field">' . nm_t('注意書き（任意）') . '<input name="warning" maxlength="' . NL_WARNING_MAX . '" value="' . h(nl_warning_text($p['warning'] ?? '')) . '" placeholder="' . nm_t('例：流血表現があります') . '"></label>'
        . '<div class="log-chip-list log-warning-presets" role="group" aria-label="' . nm_t('注意書きの定型文') . '">' . implode('', array_map(fn($t) => '<button type="button" class="log-tag-chip" data-warning-preset="' . h($t) . '">' . h($t) . '</button>', nl_settings()['warning_presets'])) . '</div>'
        . '<label class="log-panel-check"><input type="checkbox" data-rate-all checked>' . nm_t('選んだとき、本文の画像にも同じ注意を付ける') . '</label><p class="note">' . nm_t('画像ごとの注意は、サムネイルの左下のボタンで変えられます。') . '</p></div>'
        . '<div class="log-compose-panel" id="log-panel-help" data-panel-body="help" hidden><ul class="log-help-list"><li>' . nm_t('1行目はタイトル、2行目以降は本文です。選んだ文字は「太字」にできます。') . '</li><li>' . nm_t('画像はここへドロップ、または貼り付けでも追加できます。本文の下に並ぶサムネイルをドラッグすると、並べ替えや別のまとまりへの移動ができます。×で外せます（スマホは長押ししてから動かします）。') . '</li><li>' . nm_t('画像はふつう本文の最後に並びます。文の途中に置くときは、まとまりの「カーソルの位置へ」で本文に「〔画像1〕」のような行を入れます。別の場所にも置くときは「別の場所にも画像を置く」です。本文の中の帯をドラッグすると、その行を動かせます。') . '</li><li>' . nm_t('URLだけを1行に貼ると、YouTubeやX、Amazon Music、SoundCloudなどは埋め込み、ほかのページはOGPのカードで表示します。文の途中のURLは普通のリンクです。') . '</li><li>' . nm_t('閲覧注意を付けると、読者にはセンシティブは画像をぼかし、R-18・R-18Gは本文を折りたたんで表示します。') . '</li></ul></div>'
        . '<div class="log-preview" data-preview-body hidden></div>'
        . '<input type="hidden" name="title" value="">'
        . '<p class="log-status" role="status" aria-live="polite"></p><div class="log-submit"><span class="note" data-character-count></span><button class="btn" name="status" value="draft">' . nm_t('下書き保存') . '</button><button class="btn primary" name="status" value="published">' . (!nl_settings()['public'] ? nm_t('メモを保存') : ($edit ? nm_t('公開して保存') : nm_t('投稿する'))) . '</button></div></form></section>'
        . '<button type="button" class="log-fab" aria-controls="log-compose" aria-expanded="' . ($edit ? 'true' : 'false') . '">' . nl_ui_icon() . '<span>' . nm_t('書く') . '</span></button>'
        . ($public ? '<a class="log-fab-admin" href="admin/index.php?p=log">' . nl_ui_icon('wrench') . '<span>' . nm_t('管理') . '</span></a>' : '')
        . '<dialog class="log-picker" aria-labelledby="log-picker-title"><div class="log-compose-head"><h2 id="log-picker-title">' . nm_t('選ぶ') . '</h2><button type="button" class="btn" data-picker-close>' . nm_t('閉じる') . '</button></div><label>' . nm_t('検索') . '<input type="search" data-picker-search></label><p class="note" data-picker-status role="status"></p><div class="log-picker-grid"></div><button type="button" class="btn" data-picker-more hidden>' . nm_t('さらに表示') . '</button></dialog>';
}
function nl_admin_post_card(array $p): string
{
    $published = $p['status'] === 'published';
    return '<article class="log-post"><div class="log-post-meta"><time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time><span class="badge">' . ($published ? nm_t('公開') : nm_t('下書き')) . '</span><a href="index.php?p=log_edit&id=' . h($p['id']) . '">' . nm_t('編集') . '</a>'
        . ($published ? '<a href="../?id=' . h($p['id']) . '">' . nm_t('個別ページ') . '</a>' : '') . '</div>'
        . '<h2>' . h(nl_post_title($p, 0)) . '</h2>' . nl_render_body($p, true) . nl_category_links($p, true) . '</article>';
}
function nl_view_log(): void
{
    $posts = nl_post_summaries(false);
    $q = trim((string)preg_replace('/[\s　]+/u', ' ', nm_str($_GET, 'q', 400)));
    $terms = nl_search_terms($q);
    $status = nm_str($_GET, 'status', 10);
    if (!in_array($status, ['published', 'draft'], true)) $status = '';
    $searching = $terms || $status !== '';
    if ($status !== '') $posts = array_values(array_filter($posts, static fn($p) => $p['status'] === $status));
    $posts = nl_search_posts($posts, $terms);
    $page = nl_page_number();
    $size = nm_str($_GET, 'per_page', 20);
    if (preg_match('/\A[0-9]{1,3}\z/', $size) && (int)$size >= 1 && (int)$size <= 100) $_SESSION['nl_admin_per_page'] = (int)$size;
    $perPage = max(1, min(100, (int)($_SESSION['nl_admin_per_page'] ?? 20)));
    $cards = '';
    // Where a published post sits in the public list (top page numbers), for search results.
    $publicIds = $searching ? array_flip(array_column(nl_post_summaries(), 'id')) : [];
    $publicPer = nl_settings()['posts_per_page'];
    foreach (array_slice($posts, ($page - 1) * $perPage, $perPage) as $summary) {
        $p = nl_load_post($summary['id']);
        if (!$p) continue;
        $thumb = '';
        foreach ($p['media'] ?? [] as $mid) if ($m = nl_load_media($mid)) { $thumb = '<img src="' . h(nl_media_url($m, true, true)) . '" alt="" loading="lazy">'; break; }
        if ($thumb === '') foreach ($p['manga'] ?? [] as $wid) if (($w = nm_load_work($wid)) && !empty($w['pages'])) { $thumb = '<img src="' . h(nm_thumb_url($w)) . '" alt="" loading="lazy">'; break; }
        $cards .= '<li class="log-list-row"><span class="log-list-time">' . nl_ui_icon('time') . '<time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time></span>'
            . '<a class="log-list-title" href="index.php?p=log_edit&id=' . h($p['id']) . '" title="' . h(nl_post_title($p, 0)) . '">' . nl_ui_icon('title') . '<span>' . nl_search_mark(nl_post_title($p), $terms) . '</span>' . ($p['status'] === 'draft' ? '<small class="badge">' . nm_t('下書き') . '</small>' : '') . nl_veil_badge(nl_rating($summary['rating'] ?? '')) . (($likes = nl_like_count($p['id'])) > 0 ? '<small class="log-list-likes" aria-label="' . nm_t('いいね{n}', ['n' => $likes]) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.9 4.9 0 0 1 6.9-6.9l.5.5.5-.5a4.9 4.9 0 0 1 6.9 6.9Z"/></svg>' . number_format($likes) . '</small>' : '') . '</a>'
            . ($searching ? nl_admin_search_where($p, $terms, $publicIds, $publicPer) : '')
            . '<span class="log-list-thumb">' . $thumb . '</span>' . ($p['status'] === 'published' ? '<a class="btn log-list-view" href="../?id=' . h($p['id']) . '">' . nl_ui_icon('view') . '<span>' . nm_t('記事を見る') . '</span></a>' : '<span class="log-list-view note">' . nm_t('下書き') . '</span>') . '<a class="btn log-list-edit" href="index.php?p=log_edit&id=' . h($p['id']) . '">' . nl_ui_icon() . '<span>' . nm_t('編集') . '</span></a>'
            . '<form method="post" action="index.php" class="js-confirm log-list-delete" data-confirm="' . nm_t('この記事を削除しますか？この記事だけで使う画像も削除します。') . '">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($p['id']) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">' . nl_ui_icon('delete') . '<span>' . nm_t('削除') . '</span></button></form><label class="log-bulk-choice" hidden><input type="checkbox" data-bulk-item value="' . h($p['id']) . '" data-revision="' . $p['revision'] . '" aria-label="' . nm_t('{title}を削除対象に選ぶ', ['title' => h(nl_post_title($p))]) . '"></label></li>';
    }
    $s = nl_settings();
    $saved = nm_str($_GET, 'saved', 24);
    $notice = $saved !== '' && nl_load_post($saved) ? '<p class="flash flash-ok">' . nm_t('保存しました') . '</p>' : '';
    $taxonomy = nm_str($_GET, 'view', 20) === 'taxonomy';
    $tabs = nl_pages_tabs($taxonomy ? 'taxonomy' : 'posts');
    $header = '<div class="log-heading"><div><h1>' . nm_t('LOG・投稿') . '</h1><p class="note">' . h($s['title']) . ' · ' . ($s['public'] ? nm_t('全体公開') : nm_t('自分専用Memo')) . '</p></div><div class="log-toolbar"><a class="btn" href="../">' . nl_ui_icon($s['public'] ? 'globe' : 'lock') . '<span>' . ($s['public'] ? nm_t('公開ページ') : nm_t('自分のMemo')) . '</span></a><a class="btn" href="index.php?p=log_media">' . nl_ui_icon('images') . '<span>' . nm_t('画像一覧・差し替え') . '</span></a>' . (!$taxonomy ? '<button type="button" class="btn primary" data-compose-open>' . nl_ui_icon() . ' ' . nm_t('新しく書く') . '</button>' : '') . '</div></div>';
    // NagiLog is updated together with NagiManga, so the same notice appears here.
    $header = nm_update_banner() . $header;
    if ($taxonomy) { nm_layout(nm_t('LOGの分類'), $header . $tabs . nl_taxonomy_panel()); return; }
    $pagerQuery = 'p=log' . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($status !== '' ? '&status=' . $status : '');
    $search = '<form class="log-admin-search" role="search" method="get" action="index.php"><input type="hidden" name="p" value="log">'
        . '<label class="log-admin-search-box"><span class="sr-only">' . nm_t('投稿を検索') . '</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 4.5 4.5"/></svg><input type="search" name="q" value="' . h($q) . '" maxlength="100" placeholder="' . nm_t('タイトル・本文・#タグ・カテゴリで探す') . '" enterkeyhint="search" autocomplete="off" data-log-search><kbd aria-hidden="true">/</kbd></label>'
        . '<label class="log-admin-search-status"><span class="sr-only">' . nm_t('公開状態') . '</span><select name="status"><option value="">' . nm_t('すべて') . '</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>' . nm_t('公開') . '</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>' . nm_t('下書き') . '</option></select></label>'
        . '<button class="btn primary">' . nm_t('探す') . '</button></form>'
        . ($searching ? '<p class="log-admin-search-result" role="status"><span>' . ($q !== '' ? nm_t('「{q}」で', ['q' => h($q)]) : '') . ($status !== '' ? nm_t($status === 'published' ? '公開の記録' : '下書きの記録') : '') . '<b>' . nm_t('{n}件', ['n' => count($posts)]) . '</b></span><a class="btn" href="index.php?p=log">' . nm_t('検索をやめる') . '</a></p>' : '');
    nm_layout('LOG', $header . $tabs . $notice
        . $search . '<div class="log-list-controls"><span>' . ($searching ? '' : nm_t('{n}件の記録', ['n' => count($posts)])) . '</span><div class="log-list-tools"><form method="get" action="index.php"><input type="hidden" name="p" value="log"><label>' . nm_t('1ページの表示件数') . '<input type="number" name="per_page" min="1" max="100" required value="' . $perPage . '"></label><button class="btn">' . nm_t('表示') . '</button></form><button class="btn" type="button" data-bulk-toggle aria-expanded="false" aria-controls="log-bulk-bar">' . nl_ui_icon('delete') . '<span data-bulk-label>' . nm_t('まとめて削除') . '</span></button></div></div>'
        . '<form id="log-bulk-bar" class="log-bulk-bar" data-bulk-form method="post" action="index.php" hidden>' . nl_csrf_field() . '<input type="hidden" name="do" value="log_bulk_delete"><input type="hidden" name="posts" value="[]"><label><input type="checkbox" data-bulk-all>' . nm_t('このページをすべて選ぶ') . '</label><span data-bulk-count aria-live="polite">' . nm_t('0件選択') . '</span><button class="btn danger" disabled data-bulk-submit>' . nm_t('選んだ記事を削除') . '</button><p class="note">' . nm_t('選んだ記事だけで使う画像も削除します。共有画像は残します。取り消しにはバックアップが必要です。') . '</p></form>'
        . '<div class="log-compose-slot log-compose-collapsed" data-compose-slot>' . nl_editor() . '</div><ul class="log-post-list' . ($searching ? ' is-search' : '') . '">' . ($cards ?: '<li class="log-empty">' . ($searching ? nm_t('見つかりませんでした。言葉を短くするか、公開状態を「すべて」にしてみてください。') : nm_t('まだ記録はありません。ひとことから、どうぞ。')) . '</li>') . '</ul>'
        . nl_pager(['posts_per_page' => $perPage, 'pager' => 'numbers', 'pager_status' => true], $pagerQuery, $page, count($posts), 'index.php'));
}
/** Search result details: the matching part of the body, and where the post is on the public site. */
function nl_admin_search_where(array $p, array $terms, array $publicIds, int $publicPer): string
{
    $snippet = $terms ? nl_search_snippet($p, $terms) : '';
    $where = isset($publicIds[$p['id']])
        ? '<a class="log-list-where" href="../' . h(substr(nl_page_url('', intdiv($publicIds[$p['id']], $publicPer) + 1), 2)) . '#log-main">' . nm_t('トップの{n}ページ目', ['n' => intdiv($publicIds[$p['id']], $publicPer) + 1]) . '</a>'
        : '<span class="log-list-where is-hidden">' . ($p['status'] === 'draft' ? nm_t('下書きのため非公開') : nm_t('一覧に出ていません')) . '</span>';
    $cats = nl_taxonomy()['categories']; $names = '';
    foreach ($p['categories'] ?? [] as $id) if (isset($cats[$id])) $names .= '<span class="log-list-cat">' . nl_search_mark($cats[$id], $terms) . '</span>';
    return '<span class="log-list-found">' . ($snippet !== '' ? '<span class="log-list-snippet">' . $snippet . '</span>' : '') . '<span class="log-list-meta">' . $where . $names . '</span></span>';
}
function nl_view_edit(string $id): void
{
    $p = nl_load_post($id);
    if (!$p) nm_not_found();
    nm_layout(nm_t('投稿を編集'), '<p class="crumb crumb-back"><a href="index.php?p=log">' . nm_t('投稿一覧に戻る') . '</a></p>' . nl_editor($p) . nl_like_panel($p)
        . '<form method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('この記事を削除しますか？この記事だけで使う画像も削除します。') . '">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($id) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">' . nm_t('投稿を削除') . '</button></form>');
}
/** Likes of one post: see, change or delete the count. */
function nl_like_panel(array $p): string
{
    $s = nl_settings();
    $n = nl_like_count($p['id']);
    $state = !$s['public'] ? nm_t('自分専用のMemoのため、ボタンは表示していません。') : (!$s['likes'] ? nm_t('いいねボタンは「設定 › LOG › 一覧の見せ方・記事の下」でオフになっています。数は残しています。') : ($p['status'] !== 'published' ? nm_t('下書きのため、ボタンは表示していません。') : nm_t('公開ページの記事の下に表示しています。')));
    return '<section class="card log-like-panel" id="log-likes"><h2><svg class="log-like-panel-heart" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.9 4.9 0 0 1 6.9-6.9l.5.5.5-.5a4.9 4.9 0 0 1 6.9 6.9Z"/></svg>' . nm_t('いいね') . ' <b>' . number_format($n) . '</b></h2><p class="note">' . $state . '</p>'
        . '<form method="post" action="index.php" class="form log-like-form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_like_set"><input type="hidden" name="post_id" value="' . h($p['id']) . '">'
        . '<label>' . nm_t('いいねの数') . '<input type="number" name="count" min="0" max="' . NL_LIKE_MAX . '" required value="' . $n . '" inputmode="numeric"></label><div class="log-like-actions"><button class="btn primary">' . nm_t('数を保存') . '</button><button class="btn danger" name="reset" value="1"' . ($n === 0 ? ' disabled' : '') . ' formnovalidate data-confirm-click="' . nm_t('この記事のいいねを削除して0にしますか？') . '">' . nm_t('いいねを削除') . '</button></div></form></section>';
}
/** The 画像一覧 filters from a query (GET, or the hidden "back" field after saving), checked; empty ones are left out. */
function nl_media_filters(array $in): array
{
    $f = ['q' => trim((string)preg_replace('/[\s　]+/u', ' ', nm_str($in, 'q', 400))), 'use' => nm_str($in, 'use', 10), 'rating' => nm_str($in, 'rating', 10), 'page' => (string)max(1, min(100000, (int)nm_str($in, 'page', 6)))];
    if (!in_array($f['use'], ['used', 'unused'], true)) $f['use'] = '';
    if (!in_array($f['rating'], ['set', 'none'], true)) $f['rating'] = '';
    if ($f['page'] === '1') $f['page'] = '';
    return array_filter($f, static fn($v) => $v !== '');
}
/** Saving or deleting a picture returns to the same search and page. */
function nl_media_back(): string
{
    parse_str(nm_str($_POST, 'back', 1000), $back);
    $query = http_build_query(nl_media_filters($back), '', '&', PHP_QUERY_RFC3986);
    return 'p=log_media' . ($query !== '' ? '&' . $query : '');
}
function nl_view_media(): void
{
    $all = nl_list_media();
    // Where each picture is used: posts (with their titles), the icon, the shared OGP image, the sidebar.
    $usedBy = [];
    foreach (nl_post_summaries(false) as $p) foreach ($p['media'] as $id) $usedBy[$id][] = $p;
    $icon = nl_settings()['icon'];
    $logo = nl_settings()['logo'];
    $ogImage = nl_settings()['og_image'];
    $sidebarImages = nl_sidebar_media_ids();
    $pageImages = nl_pages_media_ids();
    $places = static fn($id) => array_values(array_filter([$id === $icon ? nm_t('アイコン') : '', $id === $logo ? nm_t('タイトルロゴ') : '', $id === $ogImage ? nm_t('共通OGP') : '', in_array($id, $sidebarImages, true) ? nm_t('サイドバー') : '', in_array($id, $pageImages, true) ? nm_t('固定ページ') : '']));
    $filters = nl_media_filters($_GET);
    $q = $filters['q'] ?? ''; $use = $filters['use'] ?? ''; $rating = $filters['rating'] ?? '';
    $terms = nl_search_terms($q);
    $searching = $terms || $use !== '' || $rating !== '';
    // Words match the description (the file name until it is changed), the posts using it, where else it is used, and the date.
    $found = array_values(array_filter($all, static function ($m) use ($terms, $use, $rating, $usedBy, $places) {
        $count = count($usedBy[$m['id']] ?? []) + count($places($m['id']));
        if (($use === 'used' && $count === 0) || ($use === 'unused' && $count > 0)) return false;
        $rated = nl_rating($m['rating'] ?? '') !== '';
        if (($rating === 'set' && !$rated) || ($rating === 'none' && $rated)) return false;
        if (!$terms) return true;
        $text = nl_search_norm(implode("\n", array_merge([$m['alt'], $m['id'], nl_date($m['created'])], array_column($usedBy[$m['id']] ?? [], 'title'), $places($m['id']))));
        foreach ($terms as $t) if (!str_contains($text, $t)) return false;
        return true;
    }));
    $size = nm_str($_GET, 'per_page', 20);
    if (preg_match('/\A[0-9]{1,3}\z/', $size) && (int)$size >= 1 && (int)$size <= 100) $_SESSION['nl_admin_media_per_page'] = (int)$size;
    $perPage = max(1, min(100, (int)($_SESSION['nl_admin_media_per_page'] ?? 40)));
    $page = nl_page_number();
    $back = '<input type="hidden" name="back" value="' . h(http_build_query($filters, '', '&', PHP_QUERY_RFC3986)) . '">';
    $cards = '';
    foreach (array_slice($found, ($page - 1) * $perPage, $perPage) as $m) {
        $posts = $usedBy[$m['id']] ?? [];
        $locations = $places($m['id']);
        $count = count($posts) + count($locations);
        $list = '';
        foreach (array_slice($posts, 0, 3) as $p) $list .= '<li><a href="index.php?p=log_edit&id=' . h($p['id']) . '">' . nl_search_mark(mb_strimwidth((string)$p['title'], 0, 60, '…', 'UTF-8'), $terms) . '</a>' . ($p['status'] === 'draft' ? ' <small class="badge">' . nm_t('下書き') . '</small>' : '') . '</li>';
        if (count($posts) > 3) $list .= '<li class="note">' . nm_t('ほか{n}件', ['n' => count($posts) - 3]) . '</li>';
        $cards .= '<article class="log-media-card" data-media-card="' . h($m['id']) . '" data-revision="' . $m['revision'] . '">' . nl_veil_badge(nl_rating($m['rating'] ?? ''), 'log-veil-badge log-figure-badge') . '<a class="imagelink" href="' . h(nl_media_url($m, false, true)) . '"><img src="' . h(nl_media_url($m, true, true)) . '" alt="' . h($m['alt']) . '" loading="lazy"></a><p class="note">' . h(nl_date($m['created'])) . nm_t('・') . ($count ? nm_t('{n}件で使用', ['n' => $count]) : nm_t('未使用')) . ($locations ? nm_t('（') . nl_search_mark(implode(nm_t('・'), $locations), $terms) . nm_t('）') : '') . '</p>'
            . ($list !== '' ? '<ul class="log-media-used" aria-label="' . nm_t('この画像を使っている記事') . '">' . $list . '</ul>' : '')
            . '<form method="post" action="index.php" class="form">' . nm_csrf_field() . $back . '<input type="hidden" name="do" value="log_media_alt"><input type="hidden" name="media" value="' . h($m['id']) . '"><input type="hidden" name="revision" value="' . $m['revision'] . '"><label>' . nm_t('画像の説明') . '<input name="alt" value="' . h($m['alt']) . '" maxlength="300"></label>' . nl_rating_select(nl_rating($m['rating'] ?? '')) . '<button class="btn small">' . nm_t('説明と閲覧注意を保存') . '</button></form>'
            . '<div class="log-media-copy"><button type="button" class="btn small" data-copy-text="' . h(nl_media_url($m)) . '" title="' . nm_t('サイドバーのHTMLやトップメニューに使えるURL') . '">' . nl_ui_icon('copy') . '<span>URL</span></button><button type="button" class="btn small" data-copy-text="' . h('![' . str_replace([']', '['], '', $m['alt']) . '](' . nl_media_url($m) . ')') . '" title="' . nm_t('固定ページの本文に貼るMarkdown') . '">' . nl_ui_icon('copy') . '<span>Markdown</span></button>' . (nl_is_svg($m) ? '<span class="badge">SVG</span>' : '') . '</div>'
            . '<label class="btn log-replace-label">' . nm_t('画像を差し替える') . '<input type="file" class="log-replace" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp,image/svg+xml,.svg" hidden></label><p class="note log-drop-hint">' . nm_t('この枠に画像をドロップしても差し替えられます。') . '</p><p class="note" role="status" data-replace-status></p>'
            . '<form method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('この未使用画像を削除しますか？') . '">' . nm_csrf_field() . $back . '<input type="hidden" name="do" value="log_media_delete"><input type="hidden" name="media" value="' . h($m['id']) . '"><input type="hidden" name="revision" value="' . $m['revision'] . '"><button class="btn small danger"' . ($count ? ' disabled' : '') . '>' . nm_t('未使用画像を削除') . '</button></form></article>';
    }
    $option = static fn($value, $current, $label) => '<option value="' . $value . '"' . ($value === $current ? ' selected' : '') . '>' . $label . '</option>';
    $search = '<form class="log-admin-search log-media-search" role="search" method="get" action="index.php"><input type="hidden" name="p" value="log_media">'
        . '<label class="log-admin-search-box"><span class="sr-only">' . nm_t('画像を検索') . '</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 4.5 4.5"/></svg><input type="search" name="q" value="' . h($q) . '" maxlength="100" placeholder="' . nm_t('画像の説明・使っている記事・日付で探す') . '" enterkeyhint="search" autocomplete="off" data-log-search><kbd aria-hidden="true">/</kbd></label>'
        . '<label class="log-admin-search-status"><span class="sr-only">' . nm_t('使っているか') . '</span><select name="use">' . $option('', $use, nm_t('使用：すべて')) . $option('used', $use, nm_t('使用中')) . $option('unused', $use, nm_t('未使用')) . '</select></label>'
        . '<label class="log-admin-search-status"><span class="sr-only">' . nm_t('閲覧注意') . '</span><select name="rating">' . $option('', $rating, nm_t('注意：問わない')) . $option('set', $rating, nm_t('注意あり')) . $option('none', $rating, nm_t('注意なし')) . '</select></label>'
        . '<button class="btn primary">' . nm_t('探す') . '</button></form>'
        . ($searching ? '<p class="log-admin-search-result" role="status"><span>' . ($q !== '' ? nm_t('「{q}」で', ['q' => h($q)]) : '') . ['' => '', 'used' => nm_t('使用中の'), 'unused' => nm_t('未使用の')][$use] . ['' => '', 'set' => nm_t('閲覧注意ありの'), 'none' => nm_t('閲覧注意なしの')][$rating] . nm_t('画像') . '<b>' . nm_t('{n}枚', ['n' => count($found)]) . '</b></span>' . nm_t('<a class="btn" href="index.php?p=log_media">検索をやめる</a>') . '</p>' : '');
    $pagerQuery = http_build_query(['p' => 'log_media'] + array_diff_key($filters, ['page' => 1, 'per_page' => 1]), '', '&', PHP_QUERY_RFC3986);
    $keep = '';
    foreach (array_diff_key($filters, ['page' => 1]) as $key => $value) $keep .= '<input type="hidden" name="' . $key . '" value="' . h($value) . '">';
    $empty = $all ? nm_t('見つかりませんでした。言葉を短くするか、絞り込みを「すべて」にしてみてください。') : nm_t('投稿画面から画像を追加すると、ここに並びます。');
    $upload = '<section class="log-media-upload" data-media-upload aria-labelledby="log-media-upload-title"><div class="log-media-upload-icon" aria-hidden="true">' . nl_compose_icon('upload') . '</div><div><h2 id="log-media-upload-title">' . nm_t('画像だけを追加') . '</h2>'
        . '<p class="note">' . nm_t('ここ（またはこの画面のどこか）へ画像をドロップすると、投稿を書かずに画像一覧へ追加します。サイドバー・固定ページ・タイトルロゴなどに使えます。JPEG・PNG・WebP・GIF・AVIF・BMP・SVGに対応しています。カードの上にドロップした場合は、その画像の差し替えです。') . '</p>'
        . '<p class="note">' . nm_t('追加した画像は、投稿・サイドバー・固定ページ・設定のどこかで使うまでは公開ページに出ません（ログイン中は見られます）。') . '</p>'
        . '<label class="btn primary log-media-upload-pick">' . nm_t('画像を選ぶ') . '<input type="file" data-media-upload-input accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp,image/svg+xml,.svg" multiple hidden></label><p class="note log-media-upload-status" role="status" aria-live="polite" data-media-upload-status></p></div></section>';
    nm_layout(nm_t('画像一覧'), '<div class="log-heading"><h1>' . nm_t('画像一覧・差し替え') . '</h1>' . nm_t('<a class="btn" href="index.php?p=log">投稿一覧に戻る</a>') . '</div><p>' . nm_t('差し替えると、この画像を使うすべての投稿や設定に反映されます。') . '</p>'
        . $upload . $search
        . '<div class="log-list-controls"><span>' . ($searching ? '' : nm_t('{n}枚の画像', ['n' => count($all)])) . '</span><div class="log-list-tools"><form method="get" action="index.php"><input type="hidden" name="p" value="log_media">' . $keep
        . '<label>' . nm_t('1ページの表示件数') . '<input type="number" name="per_page" min="1" max="100" required value="' . $perPage . '"></label><button class="btn">' . nm_t('表示') . '</button></form></div></div>'
        . '<div class="log-media-grid">' . ($cards ?: '<p>' . $empty . '</p>') . '</div>'
        . nl_pager(['posts_per_page' => $perPage, 'pager' => 'numbers', 'pager_status' => true], $pagerQuery, $page, count($found), 'index.php', nm_t('画像')));
}
/** The rating of one image, as a short select (画像一覧). */
function nl_rating_select(string $current): string
{
    $options = '';
    foreach (NL_RATINGS as $value => $label) $options .= '<option value="' . h($value) . '"' . ($value === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    return '<label>' . nm_t('閲覧注意') . '<select name="rating">' . $options . '</select></label>';
}
function nl_backup_panel(): string
{
    return '<section class="card"><h2>' . nm_t('LOGのバックアップ') . '</h2><p>' . nm_t('投稿・下書き・LOGの画像・サイト名を、別のZIPにまとめます。漫画は上の作品バックアップに入ります。引っ越すときは両方を保存してください。') . '</p>'
        . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_backup"><button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>' . nm_t('LOGをダウンロード') . '</button></form><h3>' . nm_t('LOGを復元') . '</h3><form method="post" action="index.php" enctype="multipart/form-data" class="form" data-log-restore>' . nm_csrf_field() . '<input type="hidden" name="do" value="log_restore"><label>' . nm_t('LOGのバックアップZIP') . '<input type="file" name="backup" accept=".zip,application/zip" required></label><label class="check"><input type="checkbox" name="overwrite" value="1">' . nm_t('同じ投稿・画像を上書きする（LOGの設定も戻します）') . '</label><button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>' . nm_t('LOGを復元する') . '</button></form><p class="note">' . nm_t('漫画を含む場合は、先に作品バックアップを復元してください。ZipArchiveがないサーバーでは、FTPでdata/logフォルダを保存できます。画像の保存先は秘密鍵に結び付くため、FTPで引っ越す場合はdata/config.phpも一緒に保管してください。') . '</p></section>';
}

/**
 * Opens one block of the settings screen's single form (closed with </div>); the button at the
 * bottom saves every block. The marker names the block when the form is sent without JavaScript.
 */
function nl_settings_block(string $key, string $attrs = ''): string
{
    return '<div class="form" data-settings-section="' . $key . '"' . $attrs . '><input type="hidden" name="sections[]" value="' . $key . '">';
}
function nl_preferences_panel(): string
{
    $s = nl_settings();
    return '<section class="card"><h2>' . nm_t('公開範囲・表示件数・ページ送り') . '</h2>' . nl_settings_block('log_preferences')
        . '<fieldset class="log-visibility"><legend>' . nm_t('LOGをどう使いますか？') . '</legend><label class="check"><input type="radio" name="visibility" value="public"' . ($s['public'] ? ' checked' : '') . '>' . nm_t('全体公開のLOG') . '</label><p class="note">' . nm_t('保存済みの記事と投稿画像も、ログインしていない人が読めるようになります。下書きは公開しません。') . '</p><label class="check"><input type="radio" name="visibility" value="private"' . (!$s['public'] ? ' checked' : '') . '>' . nm_t('自分専用のMemo') . '</label><p class="note">' . nm_t('記事とLOGの画像は、管理者としてログインしたときだけ読めます。NagiMANGAの作品の公開範囲は、作品ごとに設定してください。') . '</p></fieldset>'
        . '<label>' . nm_t('1ページの投稿数（1〜100件）') . '<input type="number" name="posts_per_page" min="1" max="100" required value="' . $s['posts_per_page'] . '"></label><p class="note">' . nm_t('トップ・日付アーカイブ・カテゴリ・タグの一覧に使います。標準は10件です。一覧をタイルにする場合は2列で並べるので、偶数がおすすめです。管理画面の一覧は、一覧の上部で別に変えられます。') . '</p>'
        . nl_pager_fieldset($s)
        . '</div></section>';
}
function nl_pager_fieldset(array $s): string
{
    $notes = [
        'numbers' => nm_t('「新しい投稿」「過去の投稿」と、1・2・3…のページ番号を出します。何ページ目かが分かり、戻りたい場所へ飛べます。8ページ以上ある場合は、ページ番号を入力して移動できます。おすすめです。'),
        'simple' => nm_t('「新しい投稿」「過去の投稿」の2つだけです。投稿が少ないLOGや、すっきり見せたいときに向いています。'),
        'more' => nm_t('ボタンを押すと、同じページの下に続きを足します。スマホで続けて読みやすい形です。勝手に読み込む「無限スクロール」にはしないので、フッターやサイドメニューにも届きます。'),
    ];
    $radios = '';
    foreach (NL_PAGERS as $key => $label) {
        $radios .= '<label class="check"><input type="radio" name="pager" value="' . $key . '"' . ($s['pager'] === $key ? ' checked' : '') . '>' . h(nm_t($label)) . ($key === 'numbers' ? nm_t('（標準）') : '') . '</label><p class="note">' . $notes[$key] . '</p>';
    }
    return '<fieldset class="log-visibility"><legend>' . nm_t('一覧のページ送り') . '</legend><input type="hidden" name="pager_form" value="1">' . $radios
        . '<label class="check"><input type="checkbox" name="pager_status" value="1"' . ($s['pager_status'] ? ' checked' : '') . '>' . nm_t('「120件中 11〜20件目」のように、全体の件数と今の位置を出す') . '</label>'
        . '<label class="check"><input type="checkbox" name="post_nav" value="1"' . ($s['post_nav'] ? ' checked' : '') . '>' . nm_t('記事のページの下に、前後の投稿と「すべての投稿」を出す') . '</label>'
        . '<p class="note">' . nm_t('どの形式でも、ページ送りは普通のリンクです。検索エンジンも2ページ目以降をたどれ、ブラウザの「戻る」で元のページに戻れます。') . '</p></fieldset>';
}
function nl_warning_presets_panel(): string
{
    return '<section class="card" id="log-warning"><h2>' . nm_t('閲覧注意の定型文') . '</h2>' . nl_settings_block('log_warning')
        . '<label>' . nm_t('注意書きの定型文（1行に1つ）') . '<textarea name="warning_presets" rows="10">' . h(implode("
", nl_settings()['warning_presets'])) . '</textarea></label>'
        . '<p class="note">' . nm_t('投稿画面の「閲覧注意」（注意の三角）で、注意書きの欄の下に並ぶ文です。押すと欄に入り、そのあと書き足せます。1行{chars}文字まで、{n}個まで、上から順に並びます。', ['chars' => NL_WARNING_MAX, 'n' => NL_WARNING_PRESETS_MAX]) . '</p>'
        . '<p class="note">' . nm_t('空にして保存すると、標準の{n}つ（{list}）に戻ります。変えても、投稿済みの記事の注意書きはそのままです。', ['n' => count(NL_WARNING_PRESETS), 'list' => h(implode('・', NL_WARNING_PRESETS))]) . '</p></div></section>';
}
function nl_footer_panel(): string
{
    $s = nl_settings();
    return '<section class="card" id="log-footer"><h2>' . nm_t('サイト下部の表記') . '</h2>' . nl_settings_block('log_footer') . '<label class="check"><input type="checkbox" name="show_footer" value="1"' . ($s['show_footer'] ? ' checked' : '') . '>' . nm_t('フッターの文章を表示する') . '</label><label>' . nm_t('表示する文章') . '<input name="footer_text" maxlength="200" value="' . h($s['footer_text']) . '" placeholder="' . nm_t('例：自分の名前・サイトの案内') . '"></label><p class="note">' . nm_t('200文字までの1行で入力できます。HTMLは使いません。空欄の場合も表示しません。フッターのリンク（HOME・利用規約など）は、下の「フッターのリンク」で設定します。') . '</p></div></section>';
}
/** Choices as radio buttons with a note under each. */
function nl_radio_list(string $name, array $choices, string $current, array $notes = []): string
{
    $out = '';
    foreach ($choices as $key => $label) $out .= '<label class="check"><input type="radio" name="' . $name . '" value="' . h($key) . '"' . ($key === $current ? ' checked' : '') . '>' . h(nm_t($label)) . '</label>' . (isset($notes[$key]) ? '<p class="note">' . $notes[$key] . '</p>' : '');
    return $out;
}
function nl_display_panel(): string
{
    $s = nl_settings();
    $by = '';
    foreach (NL_RELATED_BY as $key => $label) $by .= '<option value="' . $key . '"' . ($s['related_by'] === $key ? ' selected' : '') . '>' . h(nm_t($label)) . '</option>';
    return '<section class="card" id="log-display"><h2>' . nm_t('一覧の見せ方・記事の下') . '</h2>' . nl_settings_block('log_display')
        . '<fieldset class="log-visibility"><legend>' . nm_t('トップ・カテゴリ・タグの一覧') . '</legend>' . nl_radio_list('layout', NL_LAYOUTS, $s['layout'], [
            'stream' => nm_t('今までの形です。記事を最初から最後まで並べ、一覧のページだけで読めます。'),
            'grid' => nm_t('WordPressのブログのように、サムネイル・投稿日・タイトルのタイルを並べます。記事は個別のページで読みます。タイルはPCでもスマホでも2列で並べるので、1ページの投稿数は偶数がおすすめです。奇数だと、各ページの最後の行が1つ空きます。サムネイルは本文の最初の画像・漫画・YouTube・ブログカードの順に探します。')])
        . '<p class="note">' . nm_t('どちらにするかで、検索エンジンへの指定も変わります（下の「検索エンジンとサイトマップ」）。') . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('新着の印') . '</legend><label>' . nm_t('印を付ける期間（投稿から何日）') . '<input type="number" name="new_days" min="0" max="' . NL_NEW_DAYS_MAX . '" value="' . $s['new_days'] . '" inputmode="numeric"></label>'
        . '<label>' . nm_t('印の文字') . '<input name="new_label" maxlength="' . NL_NEW_LABEL_MAX . '" value="' . h($s['new_label']) . '" placeholder="NEW"></label>'
        . '<p class="note">' . nm_t('期間内の記事に、タイルではサムネイルの左上、ミニブログでは記事の枠の左上に印を付けます。0日にすると付けません。文字は「新」「New!」のように{n}文字まで変えられます。空にすると「NEW」です。', ['n' => NL_NEW_LABEL_MAX]) . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('パンくずリストの先頭') . '</legend>' . nl_radio_list('crumb_home', NL_CRUMB_HOMES, $s['crumb_home'], [
            'site' => nm_t('今のサイト名「{title}」を出します。', ['title' => h($s['title'])])])
        . '<label>' . nm_t('任意の文字（「任意の文字」を選んだとき）') . '<input name="crumb_label" maxlength="' . NL_CRUMB_LABEL_MAX . '" value="' . h($s['crumb_label']) . '" placeholder="' . nm_t('トップ') . '"></label>'
        . '<p class="note">' . nm_t('カテゴリ・タグ・記事のページ上部にある、家のアイコンの文字です。任意の文字は{n}文字まで。空のままだと「HOME」です。', ['n' => NL_CRUMB_LABEL_MAX]) . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('いいねボタン') . '</legend><label class="check"><input type="checkbox" name="likes" value="1"' . ($s['likes'] ? ' checked' : '') . '>' . nm_t('記事の下の「Share」の左に、いいねボタンを出す') . '</label>'
        . '<p class="note">' . nm_t('タップで1つ、長押しすると10ずつ増えます。同じ回線・同じ端末（IPアドレスとブラウザーの端末情報）から1つの記事に押せるのは、1日{device}までです。端末情報は書き換えられるため、同じ回線全体でも1日{line}までにしています。数は記事の編集画面で確認・変更・削除できます。押されてもページのキャッシュは消さないため、表示の速さは変わりません。', ['device' => NL_LIKE_DAILY, 'line' => NL_LIKE_LINE_DAILY]) . '</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('関連記事') . '</legend><label class="check"><input type="checkbox" name="related" value="1"' . ($s['related'] ? ' checked' : '') . '>' . nm_t('記事の下に、関連記事を{n}件出す', ['n' => NL_RELATED_SHOWN]) . '</label>'
        . '<label>' . nm_t('関連とみなす分類') . '<select name="related_by">' . $by . '</select></label>'
        . nl_radio_list('related_order', NL_RELATED_ORDER, $s['related_order'], [
            'random' => nm_t('同じ分類の記事から、開くたびに違う{n}件を選びます。関係の深い記事（同じカテゴリ、重なるタグが多い記事）ほど選ばれやすくなります。', ['n' => NL_RELATED_SHOWN]),
            'updated' => nm_t('同じ分類の記事のうち、最近更新した{n}件を並べます。', ['n' => NL_RELATED_SHOWN])])
        . '<p class="note">' . nm_t('同じ分類の記事がない記事には出しません。ランダムでもページはキャッシュしたまま、ブラウザーで選び直します。') . '</p></fieldset>'
        . '</div></section>';
}
/** What search engines see, for the current settings. */
function nl_seo_panel(): string
{
    $s = nl_settings();
    $grid = $s['layout'] === 'grid';
    $rows = $grid
        ? [[nm_t('トップ・カテゴリ（2ページ目以降も）'), 'index,follow'], [nm_t('個別の記事'), 'index,follow'], [nm_t('ハッシュタグ・日付・月・検索結果'), 'noindex,follow'], [nm_t('ログイン・管理画面・404'), 'noindex,nofollow']]
        : [[nm_t('トップ・カテゴリの1ページ目'), 'index,follow'], [nm_t('個別の記事・2ページ目以降'), 'noindex,follow'], [nm_t('ハッシュタグ・日付・月・検索結果'), 'noindex,follow'], [nm_t('ログイン・管理画面・404'), 'noindex,nofollow']];
    $table = '';
    foreach ($rows as [$page, $rule]) $table .= '<tr><th scope="row">' . h($page) . '</th><td><code>' . ($s['search_engines'] ? $rule : 'noindex,nofollow') . '</code></td></tr>';
    $sitemap = !$s['public'] ? '<p class="note">' . nm_t('自分専用のMemoでは、サイトマップを出しません。') . '</p>'
        : (!$s['search_engines'] ? '<p class="note">' . nm_t('検索エンジンを避けている間は、サイトマップを出しません（404）。') . '</p>'
            : '<p class="log-copy-row"><input class="copy-src wide" readonly value="' . h(nl_sitemap_url()) . '" aria-label="' . nm_t('サイトマップのURL') . '"> <button type="button" class="btn js-copy">' . nm_t('コピー') . '</button></p>'
            . '<p class="note">' . ($grid ? nm_t('トップ（優先度1.0）、個別の記事（0.7）、記事のあるカテゴリ（0.5）を、最終更新日つきで載せます。') : nm_t('ミニブログでは個別の記事を検索に出さないため、トップ（優先度1.0）と、記事のあるカテゴリ（0.5）だけを載せます。'))
            . nm_t('Google Search ConsoleやBing Webmaster Toolsの「サイトマップ」にこのURLを登録してください。LOGをサブフォルダーに置いた場合、ドメイン直下のrobots.txtには自動で書き込めません。') . '</p>');
    return '<section class="card" id="log-seo"><h2>' . nm_t('検索エンジンとサイトマップ') . '</h2>' . nl_settings_block('log_seo')
        . '<fieldset class="log-visibility"><legend>' . nm_t('検索エンジンに載せますか？') . '</legend>'
        . nl_radio_list('search_engines', ['allow' => nm_t('載せる（標準）'), 'block' => nm_t('すべてのページを載せない（noindex,nofollow）')], $s['search_engines'] ? 'allow' : 'block', [
            'allow' => nm_t('下の表のとおり、検索の入口になるページだけを載せます。'),
            'block' => nm_t('二次創作のサイトや、公式と間違われたくない場合に。全ページと画像に「載せない・リンクをたどらない」と伝え、サイトマップも止めます。指定に従うのは、ルールを守る検索エンジンだけです。すでに載っているページは、検索エンジンが次に読みに来たときに消えます。')])
        . '</fieldset><table class="table log-seo-table"><caption>' . nm_t('今の設定での指定（{layout}）', ['layout' => h(nm_t(NL_LAYOUTS[$s['layout']]))]) . '</caption><tbody>' . $table . '</tbody></table>'
        . '</div><h3>' . nm_t('サイトマップ') . '</h3>' . $sitemap . '</section>';
}
/** RSS URLs for the whole LOG, one category or one hashtag; the select fills the field and Copy copies it. */
function nl_rss_panel(): string
{
    $s = nl_settings();
    if (!$s['public']) return '<section class="card" id="log-rss"><h2>RSS</h2><p class="note">' . nm_t('自分専用のMemoでは、RSSを出しません。') . '</p></section>';
    $options = '<option value="' . h(nl_feed_url()) . '">' . nm_t('LOG全体') . '</option>';
    $cats = '';
    foreach (nl_taxonomy()['categories'] as $id => $name) $cats .= '<option value="' . h(nl_feed_url('category=' . $id)) . '">' . h($name) . '</option>';
    $tags = '';
    foreach (nl_ordered_hashtags(true) as $name) $tags .= '<option value="' . h(nl_feed_url('tag=' . rawurlencode($name))) . '">#' . h($name) . '</option>';
    if ($cats !== '') $options .= '<optgroup label="' . nm_t('カテゴリ') . '">' . $cats . '</optgroup>';
    if ($tags !== '') $options .= '<optgroup label="' . nm_t('ハッシュタグ') . '">' . $tags . '</optgroup>';
    return '<section class="card" id="log-rss" data-rss-builder><h2>RSS</h2><p>' . nm_t('RSSリーダーでLOGを読みたい人向けのURLです。新しい{n}件の、タイトル・URL・投稿日・説明文（本文の最初の部分）を配信します。本文の全文は配信しません。', ['n' => NL_FEED_ITEMS]) . '</p>'
        . '<div class="form"><label>' . nm_t('配信する範囲') . '<select data-rss-select>' . $options . '</select></label>'
        . '<p class="log-copy-row"><input class="copy-src wide" readonly value="' . h(nl_feed_url()) . '" aria-label="' . nm_t('RSSのURL') . '" data-rss-url> <button type="button" class="btn js-copy">' . nm_t('コピー') . '</button></p></div>'
        . '<p class="note">' . nm_t('LOGのページには、全体のRSS（カテゴリやタグの一覧ではそのRSSも）を案内するタグを入れています。RSSリーダーにLOGのURLを入れるだけでも見つけられます。') . '</p></section>';
}
function nl_settings_panel(): string
{
    $s = nl_settings();
    $og = nl_load_media($s['og_image']);
    $choices = '';
    foreach (['light' => nm_t('ライト（標準）'), 'dark' => nm_t('ダーク')] as $group => $label) {
        $choices .= '<fieldset class="log-theme-group"><legend>' . $label . '</legend><div class="log-theme-choices">';
        foreach (NL_THEMES as $key => $name) if (str_starts_with($key, $group)) {
            $choices .= '<label class="log-theme-choice"><input type="radio" name="theme" value="' . h($key) . '"' . ($key === $s['theme'] ? ' checked' : '') . '><span class="log-theme-swatch" data-log-theme="' . h($key) . '" aria-hidden="true"><span></span><span></span><span></span></span><span>' . h(nm_t($name)) . '</span></label>';
        }
        $choices .= '</div></fieldset>';
    }
    return '<section class="card" id="log-settings"><h2>' . nm_t('LOGのデザイン・アイコン') . '</h2>' . nl_settings_block('log_design', ' data-log-settings')
        . '<label>' . nm_t('サイト名') . '<input name="title" maxlength="100" required value="' . h($s['title']) . '"></label><label>' . nm_t('紹介文') . '<textarea name="description" maxlength="300">' . h($s['description']) . '</textarea></label>'
        . '<input type="hidden" name="description_form" value="1"><label class="check"><input type="checkbox" name="show_description" value="1"' . ($s['show_description'] ? ' checked' : '') . '>' . nm_t('紹介文をサイト名の下に表示する') . '</label><p class="note">' . nm_t('オフにすると画面には出さず、検索結果や共有したときの説明文（meta description）としてだけ使います。') . '</p>'
        . '<label>' . nm_t('名前') . '<input name="name" maxlength="100" required value="' . h($s['name']) . '"></label>'
        . '<h3>' . nm_t('タイトルロゴ') . '</h3><p class="note">' . nm_t('サイト名の代わりに、画像のロゴを表示します。トップや一覧のページでは見出し（h1）になり、画像の説明（alt）にはサイト名を入れます。横長のバナー（例：200×40px）が向いています。SVGも使えます。高さはPCで64px・スマホで44pxまでに縮めて表示します。') . '</p><div class="log-logo-preview">' . (($logo = nl_load_media($s['logo'])) ? '<img src="' . h('../' . nl_media_url($logo)) . '" alt="' . h($s['title']) . '">' : '<span class="note">' . nm_t('未設定（アイコンとサイト名を表示しています）') . '</span>') . '</div>'
        . '<label>' . nm_t('ロゴの画像') . '<input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp,image/svg+xml,.svg"></label>'
        . ($s['logo'] !== '' ? '<label class="check"><input type="checkbox" name="remove_logo" value="1">' . nm_t('ロゴを外して、アイコンとサイト名に戻す') . '</label>' : '')
        . '<h3>' . nm_t('デザイン') . '</h3><p class="note">' . nm_t('色を選ぶと、この画面で見比べられます。保存すると公開サイトにも反映されます。') . '</p>' . $choices
        . '<h3>' . nm_t('アイコン') . '</h3><div class="log-icon-preview">' . nl_icon_html($s, '../', 'log-settings-avatar') . '</div><label>' . nm_t('新しいアイコン') . '<input type="file" name="icon" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp,image/svg+xml,.svg"></label>'
        . '<p class="note">' . nm_t('サイト名の横・投稿者表示・サイドバーのプロフィール・ログイン画面で使います。画像は丸く表示します。') . '</p>'
        . ($s['icon'] !== '' ? '<label class="check"><input type="checkbox" name="remove_icon" value="1">' . nm_t('今のアイコンを外す') . '</label>' : '')
        . '<h3>' . nm_t('共通のOGP画像') . '</h3><p class="note">' . nm_t('リンクを共有したときに表示される紹介画像です。投稿に画像がないときと、トップ・分類一覧で使います。横1200×縦630pxの画像が目安です。SNSが表示しないため、SVGは使えません。') . '</p><div class="log-og-preview">' . ($og ? '<img src="' . h('../' . nl_media_url($og, true)) . '" alt="' . nm_t('共通の紹介画像') . '">' : '') . '</div><label>' . nm_t('OGP画像') . '<input type="file" name="og_image" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp"></label>'
        . ($og ? '<label class="check"><input type="checkbox" name="remove_og_image" value="1">' . nm_t('共通のOGP画像を外す') . '</label>' : '')
        . '</div></section>';
}

function nl_taxonomy_panel(): string
{
    $tax = nl_taxonomy(); $tags = nl_ordered_hashtags();
    $groups = ['category' => $tax['categories'], 'hashtag' => array_combine($tags, $tags) ?: []];
    $html = '<section class="card" id="log-taxonomy" data-taxonomy-manager data-revision="' . $tax['revision'] . '"><h2>' . nm_t('カテゴリ・ハッシュタグを編集') . '</h2><p class="note">' . nm_t('名前を変えると、投稿の分類も変わります。ハッシュタグは公開済みの本文と下書きにも反映します。同じハッシュタグ名にすると、投稿をひとつの一覧にまとめます。') . '</p><p class="note">' . nm_t('左の持ち手をドラッグするか、上下ボタンで順番を変えられます。順番はその場で保存し、サイトのメニューにも反映します。投稿画面の「最近のタグ」は、使った順のままです。') . '</p><p class="note" data-taxonomy-status role="status" aria-live="polite"></p>';
    foreach ($groups as $kind => $items) {
        $html .= '<h3>' . ($kind === 'category' ? nm_t('カテゴリ') : nm_t('ハッシュタグ')) . '</h3>';
        if (!$items) $html .= '<p class="note">' . nm_t('投稿画面で作ると、ここに並びます。') . '</p>';
        $html .= '<div class="log-taxonomy-group" data-sort-kind="' . $kind . '">';
        foreach ($items as $id => $label) $html .= '<div class="log-sort-row" data-sort-id="' . h((string)$id) . '"><div class="log-sort-tools"><button type="button" class="btn log-sort-handle" draggable="true" aria-label="' . nm_t('{name}を並び替える', ['name' => h((string)$label)]) . '" title="' . nm_t('ドラッグで並び替え') . '">⠿</button><button type="button" class="btn" data-sort-step="-1" aria-label="' . nm_t('{name}を上へ', ['name' => h((string)$label)]) . '">↑</button><button type="button" class="btn" data-sort-step="1" aria-label="' . nm_t('{name}を下へ', ['name' => h((string)$label)]) . '">↓</button></div><form method="post" action="index.php" class="log-taxonomy-row">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_taxonomy_rename"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="old" value="' . h((string)$id) . '"><input type="hidden" name="revision" value="' . $tax['revision'] . '"><label>' . h(($kind === 'hashtag' ? '#' : '') . (string)$label) . '<input name="name" value="' . h((string)$label) . '" maxlength="' . ($kind === 'category' ? 40 : 60) . '" required></label><button class="btn">' . nm_t('名前を保存') . '</button></form></div>';
        $html .= '</div>';
    }
    return $html . '</section>';
}

function nl_guard_panel(): string
{
    $cfg = nm_config();
    return '<section class="card" id="log-guard"><h2>' . nm_t('画像収集BOTへの対策') . '</h2><p>' . nm_t('画像収集ツールと分かるアクセスや、同じIPからの大量取得を404で拒否します。文章を読むAIは一律に除外しません。ブラウザを装った低速な収集は、見分けられない場合があります。') . '</p>'
        . nl_settings_block('log_guard') . '<label class="check"><input type="checkbox" name="enabled" value="1"' . (($cfg['image_guard_enabled'] ?? true) ? ' checked' : '') . '>' . nm_t('画像収集BOTへの対策を使う') . '</label>'
        . '<label>' . nm_t('10秒あたりの画像取得数（10〜1000）') . '<input type="number" name="burst" min="10" max="1000" required value="' . (int)($cfg['image_guard_burst'] ?? 120) . '"></label><label>' . nm_t('1分あたりの画像取得数（60〜6000）') . '<input type="number" name="minute" min="60" max="6000" required value="' . (int)($cfg['image_guard_minute'] ?? 300) . '"></label>'
        . '<label>' . nm_t('拒否する画像収集ツール名（1行に1つ）') . '<textarea name="agents" rows="6" maxlength="4000">' . h(implode("\n", $cfg['image_guard_agents'] ?? NM_IMAGE_COLLECTORS)) . '</textarea></label><p class="note">' . nm_t('ツールが送るUser-Agentに、この文字が含まれると画像を渡しません。上限を超えたIPは5分間、画像を取得できなくなります。同じ回線の読者が多い場合は、上限を増やしてください。') . '</p></div></section>';
}
