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
                $p = nl_save_post(nl_input_post());
                nm_json(['ok' => true, 'id' => $p['id'], 'redirect' => 'index.php?p=log&saved=' . rawurlencode($p['id'])]);
            case 'log_preview':
                $p = nl_input_post();
                nl_validate_body($p['body']);
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
                    $s['show_login'] = nm_str($_POST, 'show_login', 1) === '1';
                    $s['posts_per_page'] = (int)$count;
                    $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', $visibility === 'public' ? 'LOGを全体公開にしました' : 'LOGを自分専用のMemoにしました。画像もログイン時だけ読めます');
                nm_redirect('p=settings&section=log');
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
        nm_redirect(match ($do) { 'log_settings', 'log_preferences', 'log_footer_settings' => 'p=settings&section=log', 'log_guard_settings' => 'p=settings&section=common#log-guard', 'log_taxonomy_rename' => 'p=log&view=taxonomy', default => str_starts_with($do, 'log_media_') ? 'p=log_media' : 'p=log' });
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
        . '<p class="note">画像はここへドロップ、または貼り付けできます。タグは好きな位置へ移せます。</p>'
        . '<div class="log-recent-tags"><span class="note">最近使ったハッシュタグ</span><div class="log-chip-list">' . ($recent ?: '<span class="note">本文に #らくがき のように書くと、ここに並びます。</span>') . '</div></div>'
        . '<details class="log-category-picker"><summary>カテゴリを選ぶ・作る</summary><div class="log-chip-list">' . ($categories ?: '<p class="note">新しいカテゴリから作れます。</p>') . '</div><label>新しいカテゴリ<input name="new_categories" maxlength="800" placeholder="例：日記、制作メモ"></label><p class="note">複数作るときは「、」で区切ります。</p></details>'
        . '<div class="log-attachments" data-attachments></div><div class="log-preview" data-preview-body hidden></div>'
        . '<input type="hidden" name="title" value=""><p class="note">1行目はタイトル、2行目以降は本文になります。</p>'
        . '<p class="log-status" role="status" aria-live="polite"></p><div class="log-submit"><span class="note" data-character-count></span><button class="btn" name="status" value="draft">下書き保存</button><button class="btn primary" name="status" value="published">' . (!nl_settings()['public'] ? 'メモを保存' : ($edit ? '公開して保存' : '投稿する')) . '</button></div></form></section>'
        . '<button type="button" class="log-fab" aria-controls="log-compose" aria-expanded="' . ($edit ? 'true' : 'false') . '">' . nl_ui_icon() . '<span>書く</span></button>'
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
    $page = nl_page_number();
    $size = nm_str($_GET, 'per_page', 20);
    if (preg_match('/\A[0-9]{1,3}\z/', $size) && (int)$size >= 1 && (int)$size <= 100) $_SESSION['nl_admin_per_page'] = (int)$size;
    $perPage = max(1, min(100, (int)($_SESSION['nl_admin_per_page'] ?? 20)));
    $cards = '';
    foreach (array_slice($posts, ($page - 1) * $perPage, $perPage) as $summary) {
        $p = nl_load_post($summary['id']);
        if (!$p) continue;
        $thumb = '';
        foreach ($p['media'] ?? [] as $mid) if ($m = nl_load_media($mid)) { $thumb = '<img src="' . h(nl_media_url($m, true, true)) . '" alt="" loading="lazy">'; break; }
        if ($thumb === '') foreach ($p['manga'] ?? [] as $wid) if (($w = nm_load_work($wid)) && !empty($w['pages'])) { $thumb = '<img src="' . h(nm_thumb_url($w)) . '" alt="" loading="lazy">'; break; }
        $cards .= '<li class="log-list-row"><span class="log-list-time">' . nl_ui_icon('time') . '<time datetime="' . h(nl_date($p['created'], 'c')) . '">' . h(nl_date($p['created'])) . '</time></span>'
            . '<a class="log-list-title" href="index.php?p=log_edit&id=' . h($p['id']) . '" title="' . h(nl_post_title($p, 0)) . '">' . nl_ui_icon('title') . '<span>' . h(nl_post_title($p)) . '</span>' . ($p['status'] === 'draft' ? '<small class="badge">下書き</small>' : '') . '</a>'
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
    $pagerQuery = 'index.php?p=log&per_page=' . $perPage . '&page=';
    nm_layout('LOG', $header . $tabs . $notice
        . '<div class="log-list-controls"><span>' . count($posts) . '件の記録</span><div class="log-list-tools"><form method="get" action="index.php"><input type="hidden" name="p" value="log"><label>1ページの表示件数<input type="number" name="per_page" min="1" max="100" required value="' . $perPage . '"></label><button class="btn">表示</button></form><button class="btn" type="button" data-bulk-toggle aria-expanded="false" aria-controls="log-bulk-bar">' . nl_ui_icon('delete') . ' まとめて削除</button></div></div>'
        . '<form id="log-bulk-bar" class="log-bulk-bar" data-bulk-form method="post" action="index.php" hidden>' . nl_csrf_field() . '<input type="hidden" name="do" value="log_bulk_delete"><input type="hidden" name="posts" value="[]"><label><input type="checkbox" data-bulk-all>このページをすべて選ぶ</label><span data-bulk-count aria-live="polite">0件選択</span><button class="btn danger" disabled data-bulk-submit>選んだ記事を削除</button><p class="note">選んだ記事だけで使う画像も削除します。共有画像は残します。取り消しにはバックアップが必要です。</p></form>'
        . '<div class="log-compose-slot log-compose-collapsed" data-compose-slot>' . nl_editor() . '</div><ul class="log-post-list">' . ($cards ?: '<li class="log-empty">まだ記録はありません。ひとことから、どうぞ。</li>') . '</ul>'
        . '<nav class="log-pagination" aria-label="投稿一覧のページ">' . ($page > 1 ? '<a class="btn" href="' . h($pagerQuery . ($page - 1)) . '">新しい投稿</a>' : '') . ($page * $perPage < count($posts) ? '<a class="btn" href="' . h($pagerQuery . ($page + 1)) . '">前の投稿</a>' : '') . '</nav>');
}
function nl_view_edit(string $id): void
{
    $p = nl_load_post($id);
    if (!$p) nm_not_found();
    nm_layout('投稿を編集', '<p class="crumb"><a href="index.php?p=log">LOGへ戻る</a></p>' . nl_editor($p)
        . '<form method="post" action="index.php" class="js-confirm" data-confirm="この記事を削除しますか？この記事だけで使う画像も削除します。">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($id) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">投稿を削除</button></form>');
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
            . '<label class="btn log-replace-label">画像を差し替える<input type="file" class="log-replace" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,image/bmp" hidden></label><p class="note" role="status" data-replace-status></p>'
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
    return '<section class="card"><h2>公開範囲・表示件数</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_preferences">'
        . '<fieldset class="log-visibility"><legend>LOGをどう使いますか？</legend><label class="check"><input type="radio" name="visibility" value="public"' . ($s['public'] ? ' checked' : '') . '>全体公開のLOG</label><p class="note">保存済みの記事と投稿画像も、ログインしていない人が読めるようになります。下書きは公開しません。</p><label class="check"><input type="radio" name="visibility" value="private"' . (!$s['public'] ? ' checked' : '') . '>自分専用のMemo</label><p class="note">記事とLOGの画像は、管理者としてログインしたときだけ読めます。NagiMANGAの作品の公開範囲は、作品ごとに設定してください。</p></fieldset>'
        . '<label>1ページの投稿数（1〜100件）<input type="number" name="posts_per_page" min="1" max="100" required value="' . $s['posts_per_page'] . '"></label><p class="note">トップ・日付アーカイブ・カテゴリ・タグの一覧に使います。標準は10件です。管理画面の一覧は、一覧の上部で別に変えられます。</p>'
        . '<label class="check"><input type="checkbox" name="show_login" value="1"' . ($s['show_login'] ? ' checked' : '') . '>サイトのメニューにログイン・管理ページへのリンクを表示する</label><p class="note">オフにすると、このリンクを隠します。ログインするには管理画面をブックマークしてください。リンクを隠しても、パスワードによる保護はそのままです。</p>'
        . '<button class="btn primary">公開範囲と表示を保存</button></form></section>';
}
function nl_footer_panel(): string
{
    $s = nl_settings();
    return '<section class="card" id="log-footer"><h2>サイト下部の表記</h2><form method="post" action="index.php" class="form">' . nl_csrf_field() . '<input type="hidden" name="do" value="log_footer_settings"><label class="check"><input type="checkbox" name="show_footer" value="1"' . ($s['show_footer'] ? ' checked' : '') . '>フッターを表示する</label><label>表示する文章<input name="footer_text" maxlength="200" value="' . h($s['footer_text']) . '" placeholder="例：自分の名前・サイトの案内"></label><p class="note">200文字までの1行で入力できます。HTMLは使いません。空欄の場合も表示しません。</p><button class="btn">フッターを保存</button></form></section>';
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
        . '<p class="note">サイト名の横・投稿者表示・ログイン画面で使います。画像は丸く表示します。</p>'
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
