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
        if (!is_array(json_decode($raw, false, 512, JSON_THROW_ON_ERROR))) throw new UnexpectedValueException('選んだ内容を確認してください');
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) { throw new UnexpectedValueException('選んだ内容を確認してください', 0, $e); }
}
function nl_revision_input(): int
{
    $revision = nm_str($_POST, 'revision', 20);
    if (!preg_match('/\A[0-9]{1,10}\z/', $revision)) throw new UnexpectedValueException('更新情報を確認して、画面を開き直してください');
    return (int)$revision;
}
function nl_admin_media_data(array $m): array
{
    return array_merge($m, ['url' => nl_media_url($m, false, true), 'thumb' => nl_media_url($m, true, true), 'tag' => '[Image:' . $m['id'] . ']']);
}
function nl_admin_catalog(bool $manga): never
{
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
        $label = mb_substr(trim((string)preg_replace('/[\[\]\r\n]/u', ' ', $i['title'])), 0, 200) ?: '無題';
        // Include the ID for same-title works and titles containing tag delimiters.
        if (($counts[$i['title']] ?? 0) > 1 || $label !== $i['title']) $label .= ' #' . $i['id'];
        $out[] = ['id' => $i['id'], 'title' => $i['title'], 'thumb' => nm_thumb_url($i), 'tag' => '[Manga' . $label . ']', 'locked' => !empty($i['password_hash'])];
    }
    nm_json(['items' => $out, 'more' => $page * 30 < $total]);
}
function nl_input_post(): array
{
    $body = $_POST['body'] ?? '';
    if (!is_string($body)) throw new UnexpectedValueException('本文を確認してください');
    $rawRefs = nm_str($_POST, 'manga', 50000);
    $refs = json_decode($rawRefs, true);
    return ['id' => nm_str($_POST, 'post_id', 24), 'revision' => (int)nm_str($_POST, 'revision', 10),
        'nonce' => nm_str($_POST, 'nonce', 32), 'title' => nm_str($_POST, 'title', 1000), 'body' => $body,
        'status' => nm_str($_POST, 'status', 20), 'manga' => is_array($refs) ? $refs : [], 'categories' => $_POST['categories'] ?? [], 'new_categories' => nm_str($_POST, 'new_categories', 4000)];
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
                nm_json(['html' => '<h2>' . h(nl_post_title($p, 0)) . '</h2>' . nl_render_body($p, true)]);
            case 'log_upload':
                $file = $_FILES['image'] ?? null;
                if (!is_array($file)) throw new UnexpectedValueException('画像を選んでください');
                $m = nl_upload_media($file, nm_str($_POST, 'media', 16), (int)nm_str($_POST, 'revision', 10));
                nm_json(['ok' => true, 'media' => nl_admin_media_data($m)]);
            case 'log_delete':
            case 'log_bulk_delete':
                $items = $do === 'log_delete' ? [['id' => nm_str($_POST, 'post_id', 24), 'revision' => nl_revision_input()]] : nl_json_list(nm_str($_POST, 'posts', 30000));
                $result = nl_delete_posts($items);
                nm_flash($result['clean'] ? 'ok' : 'err', $result['clean'] ? $result['posts'] . '件の記事と、専用画像' . $result['media'] . '件を削除しました。共有画像は残しています' : '記事は削除しましたが、画像の掃除が完了していません。保存先の権限を確認してください');
                nm_redirect('p=log');
            case 'log_media_alt':
                nm_with_lock('personal-log', static function () {
                    $m = nl_load_media(nm_str($_POST, 'media', 16));
                    if (!$m) throw new UnexpectedValueException('画像が見つかりません');
                    if ($m['revision'] !== (int)nm_str($_POST, 'revision', 10)) throw new UnexpectedValueException('別の画面で更新されています。開き直してください');
                    $m['alt'] = mb_substr(trim(nm_str($_POST, 'alt', 1500)), 0, 300);
                    $m['revision']++;
                    $m['updated'] = time();
                    nl_write_record(nl_media_dir($m['id']) . '/media.php', $m);
                    nm_touch_content();
                });
                nm_flash('ok', '画像の説明を保存しました');
                nm_redirect('p=log_media');
            case 'log_media_delete':
                nm_with_lock('personal-log', static function () {
                    $id = nm_str($_POST, 'media', 16);
                    $m = nl_load_media($id);
                    if (!$m) throw new UnexpectedValueException('画像が見つかりません');
                    if ($m['revision'] !== (int)nm_str($_POST, 'revision', 10)) throw new UnexpectedValueException('別の画面で更新されています。開き直してください');
                    if (nl_settings()['icon'] === $id) throw new UnexpectedValueException('アイコンで使っている画像は削除できません');
                    if (nl_settings()['og_image'] === $id) throw new UnexpectedValueException('OGPで使っている画像は削除できません');
                    if (in_array($id, nl_sidebar_media_ids(), true)) throw new UnexpectedValueException('サイドバーで使っている画像は削除できません');
                    foreach (nl_post_summaries(false) as $p) if (in_array($id, $p['media'], true)) throw new UnexpectedValueException('投稿や下書きで使っている画像は削除できません');
                    nm_rmdir_recursive(nl_media_dir($id));
                });
                nm_flash('ok', '未使用の画像を削除しました');
                nm_redirect('p=log_media');
            case 'log_settings':
                $s = ['title' => mb_substr(trim(nm_str($_POST, 'title', 600)), 0, 100), 'description' => mb_substr(trim(nm_str($_POST, 'description', 1500)), 0, 300), 'name' => mb_substr(trim(nm_str($_POST, 'name', 300)), 0, 100)];
                if ($s['title'] === '' || $s['name'] === '') throw new UnexpectedValueException('サイト名と名前を入力してください');
                $s['theme'] = nm_str($_POST, 'theme', 30) ?: nl_settings()['theme'];
                if (!isset(NL_THEMES[$s['theme']])) throw new UnexpectedValueException('デザインを6種類から選んでください');
                $icon = null;
                $file = $_FILES['icon'] ?? null;
                if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $icon = nl_upload_media($file)['id'];
                $ogImage = null; $file = $_FILES['og_image'] ?? null;
                if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $ogImage = nl_upload_media($file)['id'];
                nm_with_lock('personal-log', static function () use ($s, $icon, $ogImage) {
                    $s['icon'] = $icon ?? (!empty($_POST['remove_icon']) ? '' : nl_settings()['icon']);
                    $s['og_image'] = $ogImage ?? (!empty($_POST['remove_og_image']) ? '' : nl_settings()['og_image']);
                    $s = array_replace(nl_settings(), $s, ['updated' => time()]);
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', 'LOGの設定を保存しました');
                nm_redirect('p=settings&section=log');
            case 'log_preferences':
                $visibility = nm_str($_POST, 'visibility', 20);
                $count = nm_str($_POST, 'posts_per_page', 20);
                if (!in_array($visibility, ['public', 'private'], true) || !preg_match('/\A[0-9]{1,3}\z/', $count) || (int)$count < 1 || (int)$count > 100) throw new UnexpectedValueException('公開範囲と表示件数を確認してください');
                nm_with_lock('personal-log', static function () use ($visibility, $count) {
                    $s = nl_settings();
                    $s['public'] = $visibility === 'public';
                    // Older forms still post this; the sidebar's login row owns it now.
                    if (array_key_exists('show_login', $_POST)) $s['show_login'] = nm_str($_POST, 'show_login', 1) === '1';
                    $s['posts_per_page'] = (int)$count;
                    // Forms without the paging fieldset (older pages) leave these as they were.
                    if (isset($_POST['pager_form'])) {
                        $pager = nm_str($_POST, 'pager', 10);
                        if (!isset(NL_PAGERS[$pager])) throw new UnexpectedValueException('ページ送りの形式を選んでください');
                        $s['pager'] = $pager;
                        $s['pager_status'] = nm_str($_POST, 'pager_status', 1) === '1';
                        $s['post_nav'] = nm_str($_POST, 'post_nav', 1) === '1';
                    }
                    $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', $visibility === 'public' ? 'LOGを全体公開にしました' : 'LOGを自分専用のMemoにしました。画像もログイン時だけ読めます');
                nm_redirect('p=settings&section=log');
            case 'log_display_settings':
                $layout = nm_str($_POST, 'layout', 10); $by = nm_str($_POST, 'related_by', 10); $order = nm_str($_POST, 'related_order', 10);
                if (!isset(NL_LAYOUTS[$layout], NL_RELATED_BY[$by], NL_RELATED_ORDER[$order])) throw new UnexpectedValueException('一覧の形と関連記事の設定を選んでください');
                nm_with_lock('personal-log', static function () use ($layout, $by, $order) {
                    $s = nl_settings();
                    $s['layout'] = $layout; $s['related_by'] = $by; $s['related_order'] = $order;
                    $s['likes'] = nm_str($_POST, 'likes', 1) === '1'; $s['related'] = nm_str($_POST, 'related', 1) === '1';
                    $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', '一覧と記事の下の表示を保存しました'); nm_redirect('p=settings&section=log#log-display');
            case 'log_seo_settings':
                $engines = nm_str($_POST, 'search_engines', 10);
                if (!in_array($engines, ['allow', 'block'], true)) throw new UnexpectedValueException('検索エンジンに載せるかを選んでください');
                nm_with_lock('personal-log', static function () use ($engines) {
                    $s = nl_settings(); $s['search_engines'] = $engines === 'allow'; $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', $engines === 'allow' ? '検索エンジンに載せる設定にしました' : 'すべてのページを検索エンジンに載せない設定にしました');
                nm_redirect('p=settings&section=log#log-seo');
            case 'log_like_set':
                $id = nm_str($_POST, 'post_id', 24); $count = nm_str($_POST, 'count', 12);
                if (!nl_load_post($id)) throw new UnexpectedValueException('投稿が見つかりません');
                if (!empty($_POST['reset'])) $count = '0';
                if (!preg_match('/\A[0-9]{1,8}\z/', $count)) throw new UnexpectedValueException('いいねの数は0〜' . NL_LIKE_MAX . 'で入力してください');
                nl_like_set($id, (int)$count);
                nm_flash('ok', (int)$count > 0 ? 'いいねの数を' . (int)$count . 'にしました' : 'いいねを削除しました');
                nm_redirect('p=log_edit&id=' . rawurlencode($id) . '#log-likes');
            case 'log_sidebar_settings':
                $raw = $_POST['items'] ?? '';
                if (!is_string($raw) || strlen($raw) > 1024 * 1024 || !mb_check_encoding($raw, 'UTF-8')) throw new UnexpectedValueException('サイドバー全体のHTMLを1MB以内にしてください');
                $revision = nl_sidebar_save(nl_json_list($raw), nl_revision_input());
                nm_json(['ok' => true, 'revision' => $revision, 'items' => nl_sidebar_settings()['items']]);
            case 'log_backup': nl_backup_download();
            case 'log_footer_settings':
                $text = $_POST['footer_text'] ?? '';
                if (!is_string($text) || mb_strlen($text) > 200 || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $text)) throw new UnexpectedValueException('フッターは200文字以内の1行で入力してください');
                nm_with_lock('personal-log', static function () use ($text) {
                    $s = nl_settings(); $s['footer_text'] = trim($text); $s['show_footer'] = nm_str($_POST, 'show_footer', 1) === '1'; $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', 'フッターを保存しました'); nm_redirect('p=settings&section=log#log-footer');
            case 'log_taxonomy_rename':
                nl_taxonomy_rename(['kind' => nm_str($_POST, 'kind', 10), 'old' => nm_str($_POST, 'old', 250), 'name' => nm_str($_POST, 'name', 250), 'revision' => (int)nm_str($_POST, 'revision', 10)]);
                nm_flash('ok', '分類の名前を保存しました。ハッシュタグは本文にも反映しています');
                nm_redirect('p=log&view=taxonomy');
            case 'log_taxonomy_order':
                $order = nl_json_list(nm_str($_POST, 'order', 100000));
                $revision = nl_taxonomy_reorder(nm_str($_POST, 'kind', 10), $order, nl_revision_input());
                nm_json(['ok' => true, 'revision' => $revision]);
            case 'log_guard_settings':
                $burst = (int)nm_str($_POST, 'burst', 4); $minute = (int)nm_str($_POST, 'minute', 4);
                if ($burst < 10 || $burst > 1000 || $minute < 60 || $minute > 6000) throw new UnexpectedValueException('画像の上限を指定された範囲で入力してください');
                $agents = preg_split('/\r?\n/', nm_str($_POST, 'agents', 4000), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $agents = array_values(array_unique(array_map('trim', $agents)));
                if (count($agents) > 50) throw new UnexpectedValueException('ツール名は50個まで指定できます');
                foreach ($agents as $name) if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new UnexpectedValueException('収集ツール名を確認してください');
                $cfg = nm_config();
                $cfg['image_guard_enabled'] = !empty($_POST['enabled']); $cfg['image_guard_burst'] = $burst; $cfg['image_guard_minute'] = $minute; $cfg['image_guard_agents'] = $agents;
                nm_save_config($cfg);
                nm_flash('ok', '画像収集BOTへの対策を保存しました');
                nm_redirect('p=settings&section=common#log-guard');
            case 'log_restore':
                $f = $_FILES['backup'] ?? [];
                if (($f['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) throw new UnexpectedValueException('バックアップを受け取れませんでした');
                $r = nl_backup_restore($f['tmp_name'], !empty($_POST['overwrite']));
                nm_flash('ok', 'LOGを復元しました（投稿 ' . $r['posts'] . '件・画像 ' . $r['media'] . '件）');
                nm_redirect('p=log');
            default: nm_not_found();
        }
    } catch (Throwable $e) {
        $message = $e instanceof UnexpectedValueException ? $e->getMessage() : '保存できませんでした。空き容量や書き込み権限を確認してください';
        nm_log('log_error', $e->getMessage());
        if (in_array($do, ['log_save', 'log_preview', 'log_upload', 'log_taxonomy_order', 'log_sidebar_settings'], true)) nm_json(['error' => $message], 422);
        nm_flash('err', $message);
        nm_redirect(match ($do) { 'log_settings', 'log_preferences', 'log_footer_settings', 'log_display_settings', 'log_seo_settings' => 'p=settings&section=log', 'log_like_set' => 'p=log_edit&id=' . rawurlencode(nm_str($_POST, 'post_id', 24)) . '#log-likes', 'log_guard_settings' => 'p=settings&section=common#log-guard', 'log_taxonomy_rename' => 'p=log&view=taxonomy', default => str_starts_with($do, 'log_media_') ? 'p=log_media' : 'p=log' });
    }
}
function nl_editor(?array $p = null, bool $public = false): string
{
    $edit = $p !== null;
    $nonce = bin2hex(random_bytes(16));
    $categories = '';
    foreach (nl_taxonomy()['categories'] as $id => $label) $categories .= '<label class="log-category-choice"><input type="checkbox" name="categories[]" value="' . h((string)$id) . '"' . (in_array((string)$id, $p['categories'] ?? [], true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    $recent = '';
    foreach (nl_recent_hashtags() as $name) $recent .= '<button type="button" class="log-tag-chip" data-hashtag="' . h($name) . '">#' . h($name) . '</button>';
    return '<section class="log-compose' . ($edit ? ' log-edit active' : '') . '" id="log-compose" aria-labelledby="log-compose-title"' . ($edit ? ' data-edit="1"' : '') . '>'
        . '<div class="log-compose-head"><h2 id="log-compose-title">' . ($edit ? '投稿を編集' : 'いま、何を残す？') . '</h2><button type="button" class="btn log-close">閉じる</button></div>'
        . '<form method="post" action="' . ($public ? 'admin/index.php' : 'index.php') . '" data-log-editor' . ($public ? ' data-public="1"' : '') . '>' . nl_csrf_field()
        . '<input type="hidden" name="do" value="log_save"><input type="hidden" name="post_id" value="' . h($p['id'] ?? '') . '">'
        . '<input type="hidden" name="revision" value="' . (int)($p['revision'] ?? 0) . '"><input type="hidden" name="nonce" value="' . $nonce . '">'
        . '<input type="hidden" name="manga" value="' . h(json_encode($p['manga'] ?? (object)[], JSON_UNESCAPED_UNICODE)) . '">'
        . '<label class="sr-only" for="log-body">投稿本文</label><textarea id="log-body" name="body" rows="6" maxlength="100000" placeholder="今日のこと、描いた絵、ふと思ったこと。" required>' . h($p['body'] ?? '') . '</textarea>'
        . '<div class="log-toolbar"><button type="button" class="btn" data-bold><strong>B</strong> 太字</button><button type="button" class="btn" data-upload>画像を追加</button><button type="button" class="btn" data-picker="media">画像を選ぶ</button><button type="button" class="btn" data-picker="manga">漫画を選ぶ</button><button type="button" class="btn" data-preview>プレビュー</button></div>'
        . '<input type="file" data-upload-input accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" multiple hidden>'
        . '<p class="note">画像はここへドロップ、または貼り付けできます。タグは好きな位置へ移せます。<br>URLだけを1行に貼ると、YouTubeやXなどは埋め込み、ほかのページはOGPのカードで表示します。文の途中のURLは普通のリンクです。</p>'
        . '<div class="log-recent-tags"><span class="note">最近使ったハッシュタグ</span><div class="log-chip-list">' . ($recent ?: '<span class="note">本文に #らくがき のように書くと、ここに並びます。</span>') . '</div></div>'
        . '<details class="log-category-picker"><summary>カテゴリを選ぶ・作る</summary><div class="log-chip-list">' . ($categories ?: '<p class="note">新しいカテゴリから作れます。</p>') . '</div><label>新しいカテゴリ<input name="new_categories" maxlength="800" placeholder="例：日記、制作メモ"></label><p class="note">複数作るときは「、」で区切ります。</p></details>'
        . '<div class="log-attachments" data-attachments></div><div class="log-preview" data-preview-body hidden></div>'
        . '<input type="hidden" name="title" value=""><p class="note">1行目はタイトル、2行目以降は本文になります。</p>'
        . '<p class="log-status" role="status" aria-live="polite"></p><div class="log-submit"><span class="note" data-character-count></span><button class="btn" name="status" value="draft">下書き保存</button><button class="btn primary" name="status" value="published">' . (!nl_settings()['public'] ? 'メモを保存' : ($edit ? '公開して保存' : '投稿する')) . '</button></div></form></section>'
        . '<button type="button" class="log-fab" aria-controls="log-compose" aria-expanded="' . ($edit ? 'true' : 'false') . '">' . nl_ui_icon() . '<span>書く</span></button>'
        . ($public ? '<a class="log-fab-admin" href="admin/index.php?p=log">' . nl_ui_icon('wrench') . '<span>管理</span></a>' : '')
        . '<dialog class="log-picker" aria-labelledby="log-picker-title"><div class="log-compose-head"><h2 id="log-picker-title">選ぶ</h2><button type="button" class="btn" data-picker-close>閉じる</button></div><label>検索<input type="search" data-picker-search></label><p class="note" data-picker-status role="status"></p><div class="log-picker-grid"></div><button type="button" class="btn" data-picker-more hidden>さらに表示</button></dialog>';
}
function nl_admin_post_card(array $p): string
{
    $published = $p['status'] === 'published';
    return '<article class="log-post"><div class="log-post-meta"><time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time><span class="badge">' . ($published ? '公開' : '下書き') . '</span><a href="index.php?p=log_edit&id=' . h($p['id']) . '">編集</a>'
        . ($published ? '<a href="../?id=' . h($p['id']) . '">個別ページ</a>' : '') . '</div>'
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
            . '<a class="log-list-title" href="index.php?p=log_edit&id=' . h($p['id']) . '" title="' . h(nl_post_title($p, 0)) . '">' . nl_ui_icon('title') . '<span>' . nl_search_mark(nl_post_title($p), $terms) . '</span>' . ($p['status'] === 'draft' ? '<small class="badge">下書き</small>' : '') . (($likes = nl_like_count($p['id'])) > 0 ? '<small class="log-list-likes" aria-label="いいね' . $likes . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.9 4.9 0 0 1 6.9-6.9l.5.5.5-.5a4.9 4.9 0 0 1 6.9 6.9Z"/></svg>' . number_format($likes) . '</small>' : '') . '</a>'
            . ($searching ? nl_admin_search_where($p, $terms, $publicIds, $publicPer) : '')
            . '<span class="log-list-thumb">' . $thumb . '</span>' . ($p['status'] === 'published' ? '<a class="btn log-list-view" href="../?id=' . h($p['id']) . '">' . nl_ui_icon('view') . '<span>記事を見る</span></a>' : '<span class="log-list-view note">下書き</span>') . '<a class="btn log-list-edit" href="index.php?p=log_edit&id=' . h($p['id']) . '">' . nl_ui_icon() . '<span>編集</span></a>'
            . '<form method="post" action="index.php" class="js-confirm log-list-delete" data-confirm="この記事を削除しますか？この記事だけで使う画像も削除します。">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($p['id']) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">' . nl_ui_icon('delete') . '<span>削除</span></button></form><label class="log-bulk-choice" hidden><input type="checkbox" data-bulk-item value="' . h($p['id']) . '" data-revision="' . $p['revision'] . '" aria-label="' . h(nl_post_title($p)) . 'を削除対象に選ぶ"></label></li>';
    }
    $s = nl_settings();
    $saved = nm_str($_GET, 'saved', 24);
    $notice = $saved !== '' && nl_load_post($saved) ? '<p class="flash flash-ok">保存しました</p>' : '';
    $taxonomy = nm_str($_GET, 'view', 20) === 'taxonomy';
    $tabs = '<nav class="workspace-tabs" aria-label="LOGの管理"><a href="index.php?p=log"' . (!$taxonomy ? ' aria-current="page"' : '') . '>投稿一覧</a><a href="index.php?p=log&view=taxonomy"' . ($taxonomy ? ' aria-current="page"' : '') . '>カテゴリ・タグ</a><a href="index.php?p=settings&section=log">LOGの設定</a></nav>';
    $header = '<div class="log-heading"><div><h1>LOG・投稿</h1><p class="note">' . h($s['title']) . ' · ' . ($s['public'] ? '全体公開' : '自分専用Memo') . '</p></div><div class="log-toolbar"><a class="btn" href="../">' . ($s['public'] ? '公開ページ' : '自分のMemo') . '</a><a class="btn" href="index.php?p=log_media">画像一覧・差し替え</a>' . (!$taxonomy ? '<button type="button" class="btn primary" data-compose-open>' . nl_ui_icon() . ' 新しく書く</button>' : '') . '</div></div>';
    if ($taxonomy) { nm_layout('LOGの分類', $header . $tabs . nl_taxonomy_panel()); return; }
    $pagerQuery = 'index.php?p=log&per_page=' . $perPage . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($status !== '' ? '&status=' . $status : '') . '&page=';
    $search = '<form class="log-admin-search" role="search" method="get" action="index.php"><input type="hidden" name="p" value="log">'
        . '<label class="log-admin-search-box"><span class="sr-only">投稿を検索</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 4.5 4.5"/></svg><input type="search" name="q" value="' . h($q) . '" maxlength="100" placeholder="タイトル・本文・#タグ・カテゴリで探す" enterkeyhint="search" autocomplete="off" data-log-search><kbd aria-hidden="true">/</kbd></label>'
        . '<label class="log-admin-search-status"><span class="sr-only">公開状態</span><select name="status"><option value="">すべて</option><option value="published"' . ($status === 'published' ? ' selected' : '') . '>公開</option><option value="draft"' . ($status === 'draft' ? ' selected' : '') . '>下書き</option></select></label>'
        . '<button class="btn primary">探す</button></form>'
        . ($searching ? '<p class="log-admin-search-result" role="status"><span>' . ($q !== '' ? '「' . h($q) . '」で' : '') . ($status !== '' ? ($status === 'published' ? '公開' : '下書き') . 'の記録' : '') . '<b>' . count($posts) . '件</b></span><a class="btn" href="index.php?p=log">検索をやめる</a></p>' : '');
    nm_layout('LOG', $header . $tabs . $notice
        . $search . '<div class="log-list-controls"><span>' . ($searching ? '' : count($posts) . '件の記録') . '</span><div class="log-list-tools"><form method="get" action="index.php"><input type="hidden" name="p" value="log"><label>1ページの表示件数<input type="number" name="per_page" min="1" max="100" required value="' . $perPage . '"></label><button class="btn">表示</button></form><button class="btn" type="button" data-bulk-toggle aria-expanded="false" aria-controls="log-bulk-bar">' . nl_ui_icon('delete') . '<span data-bulk-label>まとめて削除</span></button></div></div>'
        . '<form id="log-bulk-bar" class="log-bulk-bar" data-bulk-form method="post" action="index.php" hidden>' . nl_csrf_field() . '<input type="hidden" name="do" value="log_bulk_delete"><input type="hidden" name="posts" value="[]"><label><input type="checkbox" data-bulk-all>このページをすべて選ぶ</label><span data-bulk-count aria-live="polite">0件選択</span><button class="btn danger" disabled data-bulk-submit>選んだ記事を削除</button><p class="note">選んだ記事だけで使う画像も削除します。共有画像は残します。取り消しにはバックアップが必要です。</p></form>'
        . '<div class="log-compose-slot log-compose-collapsed" data-compose-slot>' . nl_editor() . '</div><ul class="log-post-list' . ($searching ? ' is-search' : '') . '">' . ($cards ?: '<li class="log-empty">' . ($searching ? '見つかりませんでした。言葉を短くするか、公開状態を「すべて」にしてみてください。' : 'まだ記録はありません。ひとことから、どうぞ。') . '</li>') . '</ul>'
        . '<nav class="log-pagination" aria-label="投稿一覧のページ">' . ($page > 1 ? '<a class="btn" href="' . h($pagerQuery . ($page - 1)) . '">新しい投稿</a>' : '') . ($page * $perPage < count($posts) ? '<a class="btn" href="' . h($pagerQuery . ($page + 1)) . '">前の投稿</a>' : '') . '</nav>');
}
/** Search result details: the matching part of the body, and where the post is on the public site. */
function nl_admin_search_where(array $p, array $terms, array $publicIds, int $publicPer): string
{
    $snippet = $terms ? nl_search_snippet($p, $terms) : '';
    $where = isset($publicIds[$p['id']])
        ? '<a class="log-list-where" href="../' . h(substr(nl_page_url('', intdiv($publicIds[$p['id']], $publicPer) + 1), 2)) . '#log-main">トップの' . (intdiv($publicIds[$p['id']], $publicPer) + 1) . 'ページ目</a>'
        : '<span class="log-list-where is-hidden">' . ($p['status'] === 'draft' ? '下書きのため非公開' : '一覧に出ていません') . '</span>';
    $cats = nl_taxonomy()['categories']; $names = '';
    foreach ($p['categories'] ?? [] as $id) if (isset($cats[$id])) $names .= '<span class="log-list-cat">' . nl_search_mark($cats[$id], $terms) . '</span>';
    return '<span class="log-list-found">' . ($snippet !== '' ? '<span class="log-list-snippet">' . $snippet . '</span>' : '') . '<span class="log-list-meta">' . $where . $names . '</span></span>';
}
function nl_view_edit(string $id): void
{
    $p = nl_load_post($id);
    if (!$p) nm_not_found();
    nm_layout('投稿を編集', '<p class="crumb"><a href="index.php?p=log">LOGへ戻る</a></p>' . nl_editor($p) . nl_like_panel($p)
        . '<form method="post" action="index.php" class="js-confirm" data-confirm="この記事を削除しますか？この記事だけで使う画像も削除します。">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($id) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">投稿を削除</button></form>');
}
/** Likes of one post: see, change or delete the count. */
function nl_like_panel(array $p): string
{
    $s = nl_settings();
    $n = nl_like_count($p['id']);
    $state = !$s['public'] ? '自分専用のMemoのため、ボタンは表示していません。' : (!$s['likes'] ? 'いいねボタンは「設定 › LOG › 一覧の見せ方・記事の下」でオフになっています。数は残しています。' : ($p['status'] !== 'published' ? '下書きのため、ボタンは表示していません。' : '公開ページの記事の下に表示しています。'));
    return '<section class="card log-like-panel" id="log-likes"><h2><svg class="log-like-panel-heart" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.9 4.9 0 0 1 6.9-6.9l.5.5.5-.5a4.9 4.9 0 0 1 6.9 6.9Z"/></svg>いいね <b>' . number_format($n) . '</b></h2><p class="note">' . $state . '</p>'
        . '<form method="post" action="index.php" class="form log-like-form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_like_set"><input type="hidden" name="post_id" value="' . h($p['id']) . '">'
        . '<label>いいねの数<input type="number" name="count" min="0" max="' . NL_LIKE_MAX . '" required value="' . $n . '" inputmode="numeric"></label><div class="log-like-actions"><button class="btn primary">数を保存</button><button class="btn danger" name="reset" value="1"' . ($n === 0 ? ' disabled' : '') . ' formnovalidate data-confirm-click="この記事のいいねを削除して0にしますか？">いいねを削除</button></div></form></section>';
}
function nl_view_media(): void
{
    $all = nl_list_media();
    $usage = [];
    foreach (nl_post_summaries(false) as $p) foreach ($p['media'] as $id) $usage[$id] = ($usage[$id] ?? 0) + 1;
    $icon = nl_settings()['icon'];
    if ($icon !== '') $usage[$icon] = ($usage[$icon] ?? 0) + 1;
    $ogImage = nl_settings()['og_image'];
    if ($ogImage !== '') $usage[$ogImage] = ($usage[$ogImage] ?? 0) + 1;
    $sidebarImages = nl_sidebar_media_ids();
    foreach ($sidebarImages as $id) $usage[$id] = ($usage[$id] ?? 0) + 1;
    $page = nl_page_number();
    $cards = '';
    foreach (array_slice($all, ($page - 1) * 40, 40) as $m) {
        $locations = array_filter([$m['id'] === $icon ? 'アイコン' : '', $m['id'] === $ogImage ? '共通OGP' : '', in_array($m['id'], $sidebarImages, true) ? 'サイドバー' : '']);
        $cards .= '<article class="log-media-card" data-media-card="' . h($m['id']) . '" data-revision="' . $m['revision'] . '"><a class="imagelink" href="' . h(nl_media_url($m, false, true)) . '"><img src="' . h(nl_media_url($m, true, true)) . '" alt="' . h($m['alt']) . '" loading="lazy"></a><p class="note">' . h(nl_date($m['created'])) . '・' . ($usage[$m['id']] ?? 0) . '件で使用' . ($locations ? '（' . implode('・', $locations) . '）' : '') . '</p>'
            . '<form method="post" action="index.php" class="form">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_media_alt"><input type="hidden" name="media" value="' . h($m['id']) . '"><input type="hidden" name="revision" value="' . $m['revision'] . '"><label>画像の説明<input name="alt" value="' . h($m['alt']) . '" maxlength="300"></label><button class="btn small">説明を保存</button></form>'
            . '<label class="btn log-replace-label">画像を差し替える<input type="file" class="log-replace" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" hidden></label><p class="note log-drop-hint">この枠に画像をドロップしても差し替えられます。</p><p class="note" role="status" data-replace-status></p>'
            . '<form method="post" action="index.php" class="js-confirm" data-confirm="この未使用画像を削除しますか？">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_media_delete"><input type="hidden" name="media" value="' . h($m['id']) . '"><input type="hidden" name="revision" value="' . $m['revision'] . '"><button class="btn small danger"' . (isset($usage[$m['id']]) ? ' disabled' : '') . '>未使用画像を削除</button></form></article>';
    }
    nm_layout('画像一覧', '<div class="log-heading"><h1>画像一覧・差し替え</h1><a class="btn" href="index.php?p=log">LOGへ戻る</a></div><p>差し替えると、この画像を使うすべての投稿や設定に反映されます。</p><div class="log-media-grid">' . ($cards ?: '<p>投稿画面から画像を追加すると、ここに並びます。</p>') . '</div><nav class="log-pagination">' . ($page > 1 ? '<a class="btn" href="index.php?p=log_media&page=' . ($page - 1) . '">新しい画像</a>' : '') . ($page * 40 < count($all) ? '<a class="btn" href="index.php?p=log_media&page=' . ($page + 1) . '">前の画像</a>' : '') . '</nav>');
}
function nl_backup_panel(): string
{
    return '<section class="card"><h2>LOGのバックアップ</h2><p>投稿・下書き・LOGの画像・サイト名を、別のZIPにまとめます。漫画は上の作品バックアップに入ります。引っ越すときは両方を保存してください。</p>'
        . '<form method="post" action="index.php">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_backup"><button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>LOGをダウンロード</button></form><h3>LOGを復元</h3><form method="post" action="index.php" enctype="multipart/form-data" class="form">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_restore"><label>LOGのバックアップZIP<input type="file" name="backup" accept=".zip,application/zip" required></label><label class="check"><input type="checkbox" name="overwrite" value="1">同じ投稿・画像を上書きする（LOGの設定も戻します）</label><button class="btn"' . (nm_zip_available() ? '' : ' disabled') . '>LOGを復元する</button></form><p class="note">漫画を含む場合は、先に作品バックアップを復元してください。ZipArchiveがないサーバーでは、FTPでdata/logフォルダを保存できます。画像の保存先は秘密鍵に結び付くため、FTPで引っ越す場合はdata/config.phpも一緒に保管してください。</p></section>';
}

function nl_preferences_panel(): string
{
    $s = nl_settings();
    return '<section class="card"><h2>公開範囲・表示件数・ページ送り</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_preferences">'
        . '<fieldset class="log-visibility"><legend>LOGをどう使いますか？</legend><label class="check"><input type="radio" name="visibility" value="public"' . ($s['public'] ? ' checked' : '') . '>全体公開のLOG</label><p class="note">保存済みの記事と投稿画像も、ログインしていない人が読めるようになります。下書きは公開しません。</p><label class="check"><input type="radio" name="visibility" value="private"' . (!$s['public'] ? ' checked' : '') . '>自分専用のMemo</label><p class="note">記事とLOGの画像は、管理者としてログインしたときだけ読めます。NagiMANGAの作品の公開範囲は、作品ごとに設定してください。</p></fieldset>'
        . '<label>1ページの投稿数（1〜100件）<input type="number" name="posts_per_page" min="1" max="100" required value="' . $s['posts_per_page'] . '"></label><p class="note">トップ・日付アーカイブ・カテゴリ・タグの一覧に使います。標準は10件です。管理画面の一覧は、一覧の上部で別に変えられます。</p>'
        . nl_pager_fieldset($s)
        . '<button class="btn primary">公開範囲と表示を保存</button></form></section>';
}
function nl_pager_fieldset(array $s): string
{
    $notes = [
        'numbers' => '「新しい投稿」「過去の投稿」と、1・2・3…のページ番号を出します。何ページ目かが分かり、戻りたい場所へ飛べます。8ページ以上ある場合は、ページ番号を入力して移動できます。おすすめです。',
        'simple' => '「新しい投稿」「過去の投稿」の2つだけです。投稿が少ないLOGや、すっきり見せたいときに向いています。',
        'more' => 'ボタンを押すと、同じページの下に続きを足します。スマホで続けて読みやすい形です。勝手に読み込む「無限スクロール」にはしないので、フッターやサイドメニューにも届きます。',
    ];
    $radios = '';
    foreach (NL_PAGERS as $key => $label) {
        $radios .= '<label class="check"><input type="radio" name="pager" value="' . $key . '"' . ($s['pager'] === $key ? ' checked' : '') . '>' . h($label) . ($key === 'numbers' ? '（標準）' : '') . '</label><p class="note">' . $notes[$key] . '</p>';
    }
    return '<fieldset class="log-visibility"><legend>一覧のページ送り</legend><input type="hidden" name="pager_form" value="1">' . $radios
        . '<label class="check"><input type="checkbox" name="pager_status" value="1"' . ($s['pager_status'] ? ' checked' : '') . '>「120件中 11〜20件目」のように、全体の件数と今の位置を出す</label>'
        . '<label class="check"><input type="checkbox" name="post_nav" value="1"' . ($s['post_nav'] ? ' checked' : '') . '>記事のページの下に、前後の投稿と「すべての投稿」を出す</label>'
        . '<p class="note">どの形式でも、ページ送りは普通のリンクです。検索エンジンも2ページ目以降をたどれ、ブラウザの「戻る」で元のページに戻れます。</p></fieldset>';
}
function nl_footer_panel(): string
{
    $s = nl_settings();
    return '<section class="card" id="log-footer"><h2>サイト下部の表記</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_footer_settings"><label class="check"><input type="checkbox" name="show_footer" value="1"' . ($s['show_footer'] ? ' checked' : '') . '>フッターを表示する</label><label>表示する文章<input name="footer_text" maxlength="200" value="' . h($s['footer_text']) . '" placeholder="例：自分の名前・サイトの案内"></label><p class="note">200文字までの1行で入力できます。HTMLは使いません。空欄の場合も表示しません。</p><button class="btn">フッターを保存</button></form></section>';
}
/** Choices as radio buttons with a note under each. */
function nl_radio_list(string $name, array $choices, string $current, array $notes = []): string
{
    $out = '';
    foreach ($choices as $key => $label) $out .= '<label class="check"><input type="radio" name="' . $name . '" value="' . h($key) . '"' . ($key === $current ? ' checked' : '') . '>' . h($label) . '</label>' . (isset($notes[$key]) ? '<p class="note">' . $notes[$key] . '</p>' : '');
    return $out;
}
function nl_display_panel(): string
{
    $s = nl_settings();
    $by = '';
    foreach (NL_RELATED_BY as $key => $label) $by .= '<option value="' . $key . '"' . ($s['related_by'] === $key ? ' selected' : '') . '>' . h($label) . '</option>';
    return '<section class="card" id="log-display"><h2>一覧の見せ方・記事の下</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_display_settings">'
        . '<fieldset class="log-visibility"><legend>トップ・カテゴリ・タグの一覧</legend>' . nl_radio_list('layout', NL_LAYOUTS, $s['layout'], [
            'stream' => '今までの形です。記事を最初から最後まで並べ、一覧のページだけで読めます。',
            'grid' => 'WordPressのブログのように、サムネイル・投稿日・タイトルのタイルを並べます。記事は個別のページで読みます。トップの1ページ目だけ、最新の記事を大きく出します。サムネイルは本文の最初の画像・漫画・YouTube・ブログカードの順に探します。'])
        . '<p class="note">どちらにするかで、検索エンジンへの指定も変わります（下の「検索エンジンとサイトマップ」）。</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>いいねボタン</legend><label class="check"><input type="checkbox" name="likes" value="1"' . ($s['likes'] ? ' checked' : '') . '>記事の下の「Share」の左に、いいねボタンを出す</label>'
        . '<p class="note">タップで1つ、長押しすると10ずつ増えます。同じ人（IPアドレス）が1つの記事に押せるのは、1日' . NL_LIKE_DAILY . 'までです。数は記事の編集画面で確認・変更・削除できます。押されてもページのキャッシュは消さないため、表示の速さは変わりません。</p></fieldset>'
        . '<fieldset class="log-visibility"><legend>関連記事</legend><label class="check"><input type="checkbox" name="related" value="1"' . ($s['related'] ? ' checked' : '') . '>記事の下に、関連記事を' . NL_RELATED_SHOWN . '件出す</label>'
        . '<label>関連とみなす分類<select name="related_by">' . $by . '</select></label>'
        . nl_radio_list('related_order', NL_RELATED_ORDER, $s['related_order'], [
            'random' => '同じ分類の記事から、開くたびに違う' . NL_RELATED_SHOWN . '件を選びます。関係の深い記事（同じカテゴリ、重なるタグが多い記事）ほど選ばれやすくなります。',
            'updated' => '同じ分類の記事のうち、最近更新した' . NL_RELATED_SHOWN . '件を並べます。'])
        . '<p class="note">同じ分類の記事がない記事には出しません。ランダムでもページはキャッシュしたまま、ブラウザーで選び直します。</p></fieldset>'
        . '<button class="btn primary">一覧と記事の下の表示を保存</button></form></section>';
}
/** What search engines see, for the current settings. */
function nl_seo_panel(): string
{
    $s = nl_settings();
    $grid = $s['layout'] === 'grid';
    $rows = $grid
        ? [['トップ・カテゴリ（2ページ目以降も）', 'index,follow'], ['個別の記事', 'index,follow'], ['ハッシュタグ・日付・月・検索結果', 'noindex,follow'], ['ログイン・管理画面・404', 'noindex,nofollow']]
        : [['トップ・カテゴリの1ページ目', 'index,follow'], ['個別の記事・2ページ目以降', 'noindex,follow'], ['ハッシュタグ・日付・月・検索結果', 'noindex,follow'], ['ログイン・管理画面・404', 'noindex,nofollow']];
    $table = '';
    foreach ($rows as [$page, $rule]) $table .= '<tr><th scope="row">' . h($page) . '</th><td><code>' . ($s['search_engines'] ? $rule : 'noindex,nofollow') . '</code></td></tr>';
    $sitemap = !$s['public'] ? '<p class="note">自分専用のMemoでは、サイトマップを出しません。</p>'
        : (!$s['search_engines'] ? '<p class="note">検索エンジンを避けている間は、サイトマップを出しません（404）。</p>'
            : '<p class="log-copy-row"><input class="copy-src wide" readonly value="' . h(nl_sitemap_url()) . '" aria-label="サイトマップのURL"> <button type="button" class="btn js-copy">コピー</button></p>'
            . '<p class="note">' . ($grid ? 'トップ（優先度1.0）、個別の記事（0.7）、記事のあるカテゴリ（0.5）を、最終更新日つきで載せます。' : 'ミニブログでは個別の記事を検索に出さないため、トップ（優先度1.0）と、記事のあるカテゴリ（0.5）だけを載せます。')
            . 'Google Search ConsoleやBing Webmaster Toolsの「サイトマップ」にこのURLを登録してください。LOGをサブフォルダーに置いた場合、ドメイン直下のrobots.txtには自動で書き込めません。</p>');
    return '<section class="card" id="log-seo"><h2>検索エンジンとサイトマップ</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_seo_settings">'
        . '<fieldset class="log-visibility"><legend>検索エンジンに載せますか？</legend>'
        . nl_radio_list('search_engines', ['allow' => '載せる（標準）', 'block' => 'すべてのページを載せない（noindex,nofollow）'], $s['search_engines'] ? 'allow' : 'block', [
            'allow' => '下の表のとおり、検索の入口になるページだけを載せます。',
            'block' => '二次創作のサイトや、公式と間違われたくない場合に。全ページと画像に「載せない・リンクをたどらない」と伝え、サイトマップも止めます。指定に従うのは、ルールを守る検索エンジンだけです。すでに載っているページは、検索エンジンが次に読みに来たときに消えます。'])
        . '</fieldset><table class="table log-seo-table"><caption>今の設定での指定（' . h(NL_LAYOUTS[$s['layout']]) . '）</caption><tbody>' . $table . '</tbody></table>'
        . '<button class="btn primary">検索エンジンの設定を保存</button></form><h3>サイトマップ</h3>' . $sitemap . '</section>';
}
/** RSS URLs for the whole LOG, one category or one hashtag; the select fills the field and Copy copies it. */
function nl_rss_panel(): string
{
    $s = nl_settings();
    if (!$s['public']) return '<section class="card" id="log-rss"><h2>RSS</h2><p class="note">自分専用のMemoでは、RSSを出しません。</p></section>';
    $options = '<option value="' . h(nl_feed_url()) . '">LOG全体</option>';
    $cats = '';
    foreach (nl_taxonomy()['categories'] as $id => $name) $cats .= '<option value="' . h(nl_feed_url('category=' . $id)) . '">' . h($name) . '</option>';
    $tags = '';
    foreach (nl_ordered_hashtags(true) as $name) $tags .= '<option value="' . h(nl_feed_url('tag=' . rawurlencode($name))) . '">#' . h($name) . '</option>';
    if ($cats !== '') $options .= '<optgroup label="カテゴリ">' . $cats . '</optgroup>';
    if ($tags !== '') $options .= '<optgroup label="ハッシュタグ">' . $tags . '</optgroup>';
    return '<section class="card" id="log-rss" data-rss-builder><h2>RSS</h2><p>RSSリーダーでLOGを読みたい人向けのURLです。新しい' . NL_FEED_ITEMS . '件の、タイトル・URL・投稿日・説明文（本文の最初の部分）を配信します。本文の全文は配信しません。</p>'
        . '<div class="form"><label>配信する範囲<select data-rss-select>' . $options . '</select></label>'
        . '<p class="log-copy-row"><input class="copy-src wide" readonly value="' . h(nl_feed_url()) . '" aria-label="RSSのURL" data-rss-url> <button type="button" class="btn js-copy">コピー</button></p></div>'
        . '<p class="note">LOGのページには、全体のRSS（カテゴリやタグの一覧ではそのRSSも）を案内するタグを入れています。RSSリーダーにLOGのURLを入れるだけでも見つけられます。</p></section>';
}
function nl_settings_panel(): string
{
    $s = nl_settings();
    $og = nl_load_media($s['og_image']);
    $choices = '';
    foreach (['light' => 'ライト（標準）', 'dark' => 'ダーク'] as $group => $label) {
        $choices .= '<fieldset class="log-theme-group"><legend>' . $label . '</legend><div class="log-theme-choices">';
        foreach (NL_THEMES as $key => $name) if (str_starts_with($key, $group)) {
            $choices .= '<label class="log-theme-choice"><input type="radio" name="theme" value="' . h($key) . '"' . ($key === $s['theme'] ? ' checked' : '') . '><span class="log-theme-swatch" data-log-theme="' . h($key) . '" aria-hidden="true"><span></span><span></span><span></span></span><span>' . h($name) . '</span></label>';
        }
        $choices .= '</div></fieldset>';
    }
    return '<section class="card" id="log-settings"><h2>LOGのデザイン・アイコン</h2><form method="post" action="index.php" enctype="multipart/form-data" class="form" data-log-settings>' . nm_csrf_field()
        . '<input type="hidden" name="do" value="log_settings"><label>サイト名<input name="title" maxlength="100" required value="' . h($s['title']) . '"></label><label>紹介文<textarea name="description" maxlength="300">' . h($s['description']) . '</textarea></label><label>名前<input name="name" maxlength="100" required value="' . h($s['name']) . '"></label>'
        . '<h3>デザイン</h3><p class="note">色を選ぶと、この画面で見比べられます。保存すると公開サイトにも反映されます。</p>' . $choices
        . '<h3>アイコン</h3><div class="log-icon-preview">' . nl_icon_html($s, '../', 'log-settings-avatar') . '</div><label>新しいアイコン<input type="file" name="icon" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp"></label>'
        . '<p class="note">サイト名の横・投稿者表示・サイドバーのプロフィール・ログイン画面で使います。画像は丸く表示します。</p>'
        . ($s['icon'] !== '' ? '<label class="check"><input type="checkbox" name="remove_icon" value="1">今のアイコンを外す</label>' : '')
        . '<h3>共通のOGP画像</h3><p class="note">リンクを共有したときに表示される紹介画像です。投稿に画像がないときと、トップ・分類一覧で使います。横1200×縦630pxの画像が目安です。</p><div class="log-og-preview">' . ($og ? '<img src="' . h('../' . nl_media_url($og, true)) . '" alt="共通の紹介画像">' : '') . '</div><label>OGP画像<input type="file" name="og_image" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp"></label>'
        . ($og ? '<label class="check"><input type="checkbox" name="remove_og_image" value="1">共通のOGP画像を外す</label>' : '')
        . '<button class="btn primary">LOGの設定を保存</button></form></section>';
}

function nl_taxonomy_panel(): string
{
    $tax = nl_taxonomy(); $tags = nl_ordered_hashtags();
    $groups = ['category' => $tax['categories'], 'hashtag' => array_combine($tags, $tags) ?: []];
    $html = '<section class="card" id="log-taxonomy" data-taxonomy-manager data-revision="' . $tax['revision'] . '"><h2>カテゴリ・ハッシュタグを編集</h2><p class="note">名前を変えると、投稿の分類も変わります。ハッシュタグは公開済みの本文と下書きにも反映します。同じハッシュタグ名にすると、投稿をひとつの一覧にまとめます。</p><p class="note">左の持ち手をドラッグするか、上下ボタンで順番を変えられます。順番はその場で保存し、サイトのメニューにも反映します。投稿画面の「最近のタグ」は、使った順のままです。</p><p class="note" data-taxonomy-status role="status" aria-live="polite"></p>';
    foreach ($groups as $kind => $items) {
        $html .= '<h3>' . ($kind === 'category' ? 'カテゴリ' : 'ハッシュタグ') . '</h3>';
        if (!$items) $html .= '<p class="note">投稿画面で作ると、ここに並びます。</p>';
        $html .= '<div class="log-taxonomy-group" data-sort-kind="' . $kind . '">';
        foreach ($items as $id => $label) $html .= '<div class="log-sort-row" data-sort-id="' . h((string)$id) . '"><div class="log-sort-tools"><button type="button" class="btn log-sort-handle" draggable="true" aria-label="' . h((string)$label) . 'を並び替える" title="ドラッグで並び替え">⠿</button><button type="button" class="btn" data-sort-step="-1" aria-label="' . h((string)$label) . 'を上へ">↑</button><button type="button" class="btn" data-sort-step="1" aria-label="' . h((string)$label) . 'を下へ">↓</button></div><form method="post" action="index.php" class="log-taxonomy-row">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_taxonomy_rename"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="old" value="' . h((string)$id) . '"><input type="hidden" name="revision" value="' . $tax['revision'] . '"><label>' . h(($kind === 'hashtag' ? '#' : '') . (string)$label) . '<input name="name" value="' . h((string)$label) . '" maxlength="' . ($kind === 'category' ? 40 : 60) . '" required></label><button class="btn">名前を保存</button></form></div>';
        $html .= '</div>';
    }
    return $html . '</section>';
}

function nl_guard_panel(): string
{
    $cfg = nm_config();
    return '<section class="card" id="log-guard"><h2>画像収集BOTへの対策</h2><p>画像収集ツールと分かるアクセスや、同じIPからの大量取得を404で拒否します。文章を読むAIは一律に除外しません。ブラウザを装った低速な収集は、見分けられない場合があります。</p>'
        . '<form method="post" action="index.php" class="form">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_guard_settings"><label class="check"><input type="checkbox" name="enabled" value="1"' . (($cfg['image_guard_enabled'] ?? true) ? ' checked' : '') . '>画像収集BOTへの対策を使う</label>'
        . '<label>10秒あたりの画像取得数（10〜1000）<input type="number" name="burst" min="10" max="1000" required value="' . (int)($cfg['image_guard_burst'] ?? 120) . '"></label><label>1分あたりの画像取得数（60〜6000）<input type="number" name="minute" min="60" max="6000" required value="' . (int)($cfg['image_guard_minute'] ?? 300) . '"></label>'
        . '<label>拒否する画像収集ツール名（1行に1つ）<textarea name="agents" rows="6" maxlength="4000">' . h(implode("\n", $cfg['image_guard_agents'] ?? NM_IMAGE_COLLECTORS)) . '</textarea></label><p class="note">ツールが送るUser-Agentに、この文字が含まれると画像を渡しません。上限を超えたIPは5分間、画像を取得できなくなります。同じ回線の読者が多い場合は、上限を増やしてください。</p><button class="btn">BOT対策を保存</button></form></section>';
}
