<?php
/* Personal LOG admin screens. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

function nl_page_number(): int { return max(1, min(100000, (int)nm_str($_GET, 'page', 6))); }
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
                $id = nm_str($_POST, 'post_id', 24);
                nm_with_lock('personal-log', static function () use ($id) {
                    $p = nl_load_post($id);
                    if (!$p) throw new UnexpectedValueException('投稿が見つかりません');
                    if ($p['revision'] !== (int)nm_str($_POST, 'revision', 10)) throw new UnexpectedValueException('別の画面で更新されています。開き直してください');
                    nl_update_index(null, $id);
                    if (!unlink(nl_post_file($id))) { nl_update_index($p); throw new RuntimeException('delete failed'); }
                    nm_log('log_deleted', $id);
                });
                nm_flash('ok', '投稿を削除しました。画像は画像一覧に残しています');
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
                    $s['updated'] = time();
                    nl_write_record(nl_root() . '/settings.php', $s);
                });
                nm_flash('ok', 'LOGの設定を保存しました');
                nm_redirect('p=settings');
            case 'log_backup': nl_backup_download();
            case 'log_taxonomy_rename':
                nl_taxonomy_rename(['kind' => nm_str($_POST, 'kind', 10), 'old' => nm_str($_POST, 'old', 250), 'name' => nm_str($_POST, 'name', 250), 'revision' => (int)nm_str($_POST, 'revision', 10)]);
                nm_flash('ok', '分類の名前を保存しました。ハッシュタグは本文にも反映しています');
                nm_redirect('p=settings#log-taxonomy');
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
                nm_redirect('p=settings#log-guard');
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
        if (in_array($do, ['log_save', 'log_preview', 'log_upload'], true)) nm_json(['error' => $message], 422);
        nm_flash('err', $message);
        nm_redirect(in_array($do, ['log_settings', 'log_taxonomy_rename', 'log_guard_settings'], true) ? 'p=settings' : (str_starts_with($do, 'log_media_') ? 'p=log_media' : 'p=log'));
    }
}
function nl_editor(?array $p = null): string
{
    $edit = $p !== null;
    $nonce = bin2hex(random_bytes(16));
    $categories = '';
    foreach (nl_taxonomy()['categories'] as $id => $label) $categories .= '<label class="log-category-choice"><input type="checkbox" name="categories[]" value="' . h((string)$id) . '"' . (in_array((string)$id, $p['categories'] ?? [], true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    $recent = '';
    foreach (nl_recent_hashtags() as $name) $recent .= '<button type="button" class="log-tag-chip" data-hashtag="' . h($name) . '">#' . h($name) . '</button>';
    return '<section class="log-compose' . ($edit ? ' log-edit active' : '') . '" id="log-compose" aria-labelledby="log-compose-title"' . ($edit ? ' data-edit="1"' : '') . '>'
        . '<div class="log-compose-head"><h2 id="log-compose-title">' . ($edit ? '投稿を編集' : 'いま、何を残す？') . '</h2><button type="button" class="btn log-close">閉じる</button></div>'
        . '<form method="post" action="index.php" data-log-editor>' . nm_csrf_field()
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
        . '<p class="log-status" role="status" aria-live="polite"></p><div class="log-submit"><span class="note" data-character-count></span><button class="btn" name="status" value="draft">下書き保存</button><button class="btn primary" name="status" value="published">' . ($edit ? '公開して保存' : '投稿する') . '</button></div></form></section>'
        . '<button type="button" class="log-fab" aria-controls="log-compose" aria-expanded="' . ($edit ? 'true' : 'false') . '">書く</button>'
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
    $cards = '';
    foreach (array_slice($posts, ($page - 1) * 20, 20) as $summary) {
        $p = nl_load_post($summary['id']);
        if ($p) $cards .= nl_admin_post_card($p);
    }
    $s = nl_settings();
    $saved = nm_str($_GET, 'saved', 24);
    $notice = $saved !== '' && nl_load_post($saved) ? '<p class="flash flash-ok">保存しました</p>' : '';
    nm_layout('LOG', '<div class="log-heading"><div><h1>' . h($s['title']) . '</h1><p class="note">' . h($s['description']) . '</p></div><div class="log-toolbar"><a class="btn" href="../">公開サイト</a><a class="btn" href="index.php?p=log_media">画像一覧・差し替え</a></div></div>'
        . $notice . nl_editor() . '<div class="log-feed">' . ($cards ?: '<p class="log-empty">まだ記録はありません。ひとことから、どうぞ。</p>') . '</div>'
        . '<nav class="log-pagination" aria-label="投稿一覧のページ">' . ($page > 1 ? '<a class="btn" href="index.php?p=log&page=' . ($page - 1) . '">新しい投稿</a>' : '') . ($page * 20 < count($posts) ? '<a class="btn" href="index.php?p=log&page=' . ($page + 1) . '">前の投稿</a>' : '') . '</nav>'
        . '<p><a class="btn" href="index.php?p=settings#log-settings">デザイン・アイコン・LOGの設定</a></p>');
}
function nl_view_edit(string $id): void
{
    $p = nl_load_post($id);
    if (!$p) nm_not_found();
    nm_layout('投稿を編集', '<p class="crumb"><a href="index.php?p=log">LOGへ戻る</a></p>' . nl_editor($p)
        . '<form method="post" action="index.php" class="js-confirm" data-confirm="この投稿を削除しますか？画像は画像一覧に残ります。">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_delete"><input type="hidden" name="post_id" value="' . h($id) . '"><input type="hidden" name="revision" value="' . $p['revision'] . '"><button class="btn danger">投稿を削除</button></form>');
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
    $page = nl_page_number();
    $cards = '';
    foreach (array_slice($all, ($page - 1) * 40, 40) as $m) {
        $cards .= '<article class="log-media-card" data-media-card="' . h($m['id']) . '" data-revision="' . $m['revision'] . '"><a class="imagelink" href="' . h(nl_media_url($m, false, true)) . '"><img src="' . h(nl_media_url($m, true, true)) . '" alt="' . h($m['alt']) . '" loading="lazy"></a><p class="note">' . h(nl_date($m['created'])) . '・' . ($usage[$m['id']] ?? 0) . '件で使用' . ($m['id'] === $icon ? '（アイコン）' : '') . '</p>'
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
    $tax = nl_taxonomy(); $tags = [];
    foreach (nl_post_summaries(false) as $p) foreach ($p['hashtags'] as $name) $tags[$name] = ($tags[$name] ?? 0) + 1;
    $groups = ['category' => $tax['categories'], 'hashtag' => array_combine(array_keys($tags), array_keys($tags)) ?: []];
    $html = '<section class="card" id="log-taxonomy"><h2>カテゴリ・ハッシュタグを編集</h2><p class="note">名前を変えると、投稿の分類も変わります。ハッシュタグは公開済みの本文と下書きにも反映します。同じハッシュタグ名にすると、投稿をひとつの一覧にまとめます。</p>';
    foreach ($groups as $kind => $items) {
        $html .= '<h3>' . ($kind === 'category' ? 'カテゴリ' : 'ハッシュタグ') . '</h3>';
        if (!$items) $html .= '<p class="note">投稿画面で作ると、ここに並びます。</p>';
        foreach ($items as $id => $label) $html .= '<form method="post" action="index.php" class="log-taxonomy-row">' . nm_csrf_field() . '<input type="hidden" name="do" value="log_taxonomy_rename"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="old" value="' . h((string)$id) . '"><input type="hidden" name="revision" value="' . $tax['revision'] . '"><label>' . h(($kind === 'hashtag' ? '#' : '') . (string)$label) . '<input name="name" value="' . h((string)$label) . '" maxlength="' . ($kind === 'category' ? 40 : 60) . '" required></label><button class="btn">名前を保存</button></form>';
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
