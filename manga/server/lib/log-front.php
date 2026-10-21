<?php
/*
 * A fixed page as the top page, and the posts under their own URL (./blog). MIT License (c) 2026 Lichiphen.
 * Off by default: ./ lists the posts as before. When it is on, ./ shows the chosen fixed page and every list
 * (all posts, categories, hashtags, dates, search, later pages) moves to ./blog?… (./?pg=blog&… without clean URLs).
 * The blog sits beside the fixed pages (./blog, like ./privacy), so the LOG's relative links keep working; ./blog/ moves there.
 * The settings screen turns it on and off, and can add the blog to the top menu and the footer links.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_BLOG_LABEL_MAX = 30;
/** Query fields that make a list page; with the fixed top page they belong to the blog. */
const NL_LIST_KEYS = ['q', 'category', 'tag', 'date', 'month', 'page'];

function nl_blog_label_clean(string $text): string
{
    return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', mb_check_encoding($text, 'UTF-8') ? $text : '')), 0, NL_BLOG_LABEL_MAX);
}
/** The blog's URL name while the fixed top page is on, else ''. */
function nl_blog_slug(): string
{
    $s = nl_settings();
    return $s['front'] ? $s['blog_slug'] : '';
}
/** The blog's name in the menus and the breadcrumb ("ブログ" unless the owner wrote one). */
function nl_blog_label(array $s): string
{
    return $s['blog_label'] !== '' ? $s['blog_label'] : nm_t('ブログ');
}
/** The fixed page shown at ./, or null (turned off, deleted, or a draft someone other than the owner asks for). */
function nl_front_page(bool $owner = false): ?array
{
    $s = nl_settings();
    if (!$s['front'] || $s['front_page'] === '') return null;
    $p = nl_load_page($s['front_page']);
    return $p && ($p['status'] === 'published' || $owner) ? $p : null;
}
function nl_is_front(array $page): bool
{
    $s = nl_settings();
    return $s['front'] && $s['front_page'] !== '' && $s['front_page'] === ($page['id'] ?? '');
}
/** A fixed page's address after the LOG's folder; the top page's is '' (./). */
function nl_page_path(array $page): string
{
    return nl_is_front($page) ? '' : nl_fixed_path($page['slug']);
}
/** A list's address after the LOG's folder: '' or "?category=…" at the top; "blog?category=…" (or "?pg=blog&category=…") with the fixed top page. */
function nl_list_path(string $query = ''): string
{
    $blog = nl_blog_slug();
    if ($blog === '') return $query !== '' ? '?' . $query : '';
    if (nl_pretty()) return rawurlencode($blog) . ($query !== '' ? '?' . $query : '');
    return '?pg=' . rawurlencode($blog) . ($query !== '' ? '&' . $query : '');
}
/** A list's link from a page in the LOG's folder ('./'), or from the admin ('../'). */
function nl_list_url(string $query = '', string $base = './'): string { return $base . nl_list_path($query); }
/** The hidden field a GET form to the list needs (the blog's ?pg= without clean URLs). */
function nl_list_fields(): string
{
    $blog = nl_blog_slug();
    return $blog !== '' && !nl_pretty() ? '<input type="hidden" name="pg" value="' . h($blog) . '">' : '';
}
/** The blog link the settings add to a menu ('top': first item; 'foot': after HOME), or null. */
function nl_blog_menu_item(string $kind): ?array
{
    $s = nl_settings();
    if (nl_blog_slug() === '' || !$s[$kind === 'top' ? 'blog_topmenu' : 'blog_footer']) return null;
    return ['id' => 'blog', 'label' => nl_blog_label($s), 'url' => nl_list_url(), 'enabled' => true];
}
/** A permanent move to another address of the LOG (GET and HEAD only); the lock form's answer stays in the query. */
function nl_move_to(string $path): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
    if (isset($_GET['locked']) && is_string($_GET['locked']) && preg_match('/\A[a-z]{1,10}\z/', $_GET['locked'])) $path .= (str_contains($path, '?') ? '&' : '?') . 'locked=' . $_GET['locked'];
    header('Location: ' . nl_base_url() . '/' . $path, true, 301);
    header('Cache-Control: no-cache');
    exit;
}
/** The list fields of this request as a query (for moving a list between ./ and the blog). */
function nl_list_query(array $get): string
{
    $keep = [];
    foreach (NL_LIST_KEYS as $key) if (isset($get[$key]) && is_string($get[$key])) $keep[$key] = $get[$key];
    return http_build_query($keep, '', '&', PHP_QUERY_RFC3986);
}

// --- Settings ----------------------------------------------------------------

function nl_front_input(): Closure
{
    $on = nm_str($_POST, 'front', 1) === '1';
    $page = nm_str($_POST, 'front_page', 20);
    $slug = strtolower(trim(nm_str($_POST, 'blog_slug', 60)));
    if ($slug === '') $slug = 'blog';
    $label = nl_blog_label_clean(nm_str($_POST, 'blog_label', 200));
    $menu = nm_str($_POST, 'blog_topmenu', 1) === '1'; $foot = nm_str($_POST, 'blog_footer', 1) === '1';
    if ($page !== '' && !nl_load_page($page)) throw new UnexpectedValueException(nm_t('トップページに出す固定ページを選び直してください'));
    if ($on && $page === '') throw new UnexpectedValueException(nm_t('トップページに出す固定ページを選んでください'));
    if (!preg_match(NL_PAGE_SLUG_PATTERN, $slug)) throw new UnexpectedValueException(nm_t('ブログのURL名は半角の英小文字・数字・ハイフンで、40文字以内にしてください（例：blog）'));
    if (!nl_slug_free($slug)) throw new UnexpectedValueException(nm_t('ブログのURL名「{slug}」はLOGのフォルダー名や記事の番号と重なるため使えません', ['slug' => $slug]));
    // Only while it is on: a page called "blog" never blocks saving the other settings.
    if ($on) foreach (nl_pages() as $p) if ($p['slug'] === $slug) throw new UnexpectedValueException(nm_t('ブログのURL名「{slug}」は固定ページ「{title}」で使っています。別のURL名にしてください', ['slug' => $slug, 'title' => $p['title']]));
    return static function (array $s) use ($on, $page, $slug, $label, $menu, $foot): array {
        $s['front'] = $on; $s['front_page'] = $page; $s['blog_slug'] = $slug; $s['blog_label'] = $label;
        $s['blog_topmenu'] = $menu; $s['blog_footer'] = $foot;
        return $s;
    };
}
function nl_front_panel(): string
{
    $s = nl_settings();
    $options = '<option value="">' . nm_t('選んでください') . '</option>';
    foreach (nl_pages() as $p) $options .= '<option value="' . h($p['id']) . '"' . ($p['id'] === $s['front_page'] ? ' selected' : '') . '>' . h($p['title']) . ($p['status'] !== 'published' ? h(nm_t('（下書き）')) : '') . '</option>';
    $chosen = $s['front_page'] !== '' ? nl_load_page($s['front_page']) : null;
    $warn = '';
    if ($s['front'] && !$chosen) $warn = '<p class="warn">' . nm_t('選んでいた固定ページが見つかりません。トップページを開くと、ブログ（投稿の一覧）へ移ります。') . '</p>';
    elseif ($s['front'] && $chosen['status'] !== 'published') $warn = '<p class="warn">' . nm_t('「{title}」は下書きです。公開するまで、ログインしていない人がトップページを開くと、ブログ（投稿の一覧）へ移ります。', ['title' => h($chosen['title'])]) . '</p>';
    $blog = nl_base_url() . '/' . (nl_pretty() ? '' : '?pg=');
    return '<section class="card" id="log-front"><h2>' . nm_t('トップページとブログ') . '</h2>' . nl_settings_block('log_front')
        . '<p class="note">' . nm_t('WordPressの「ホームページの表示」のように、サイトのトップ（{top}）に固定ページを出し、投稿の一覧を「ブログ」として別のURLへ移せます。NagiLogをドメインの直下に置いた場合、example.com はプロフィールなどの固定ページ、example.com/blog は投稿の一覧になります。', ['top' => '<code>' . h(nl_base_url() . '/') . '</code>']) . '</p>'
        . '<fieldset class="log-visibility"><legend>' . nm_t('トップページ') . '</legend>'
        . '<label class="check"><input class="log-switch" type="checkbox" role="switch" name="front" value="1"' . ($s['front'] ? ' checked' : '') . '>' . nm_t('トップページに固定ページを出す（オン・オフ）') . '</label>'
        . '<p class="note">' . nm_t('オフのときは今までどおり、トップに投稿の一覧を出します。下の設定はオフにしても残ります。') . '</p>'
        . $warn
        . '<label>' . nm_t('トップページに出す固定ページ') . '<select name="front_page">' . $options . '</select></label>'
        . (nl_pages() ? '' : '<p class="note">' . nm_t('固定ページがまだありません。先に<a href="index.php?p=log_pages">固定ページ</a>で作ってください。') . '</p>')
        . '<p class="note">' . nm_t('選んだページは、自分のURL（./privacy など）ではなくトップのURLで表示します。元のURLを開くと、トップへ移ります。') . '</p>'
        . '</fieldset><fieldset class="log-visibility"><legend>' . nm_t('ブログのURLと表示名') . '</legend>'
        . '<label>' . nm_t('ブログ（投稿の一覧）のURL名') . '<span class="log-page-slug"><span aria-hidden="true">' . h($blog) . '</span><input name="blog_slug" maxlength="40" pattern="[a-z0-9](?:[a-z0-9\-]{0,38}[a-z0-9])?" value="' . h($s['blog_slug']) . '" placeholder="blog" autocapitalize="off" spellcheck="false"></span></label>'
        . '<p class="note">' . nm_t('半角の英小文字・数字・ハイフンで40文字まで。空にすると「blog」です。カテゴリ・ハッシュタグ・日付・検索・2ページ目以降の一覧も、このURLの下（{example}）に移ります。固定ページと同じURL名は使えません。', ['example' => '<code>' . h($blog) . h($s['blog_slug']) . (nl_pretty() ? '?' : '&amp;') . 'category=…</code>']) . '</p>'
        . '<label>' . nm_t('ブログの表示名') . '<input name="blog_label" maxlength="' . NL_BLOG_LABEL_MAX . '" value="' . h($s['blog_label']) . '" placeholder="' . nm_t('ブログ') . '"></label>'
        . '<p class="note">' . nm_t('メニューとパンくずリスト（{home} › ブログ › カテゴリ）に出す名前です。{n}文字まで。空のままだと「ブログ」です。', ['home' => h(nl_crumb_home($s)), 'n' => NL_BLOG_LABEL_MAX]) . '</p>'
        . '</fieldset><fieldset class="log-visibility"><legend>' . nm_t('ブログへのリンク') . '</legend>'
        . '<label class="check"><input type="checkbox" name="blog_topmenu" value="1"' . ($s['blog_topmenu'] ? ' checked' : '') . '>' . nm_t('トップメニュー（サイト名の下）の先頭に出す') . '</label>'
        . '<label class="check"><input type="checkbox" name="blog_footer" value="1"' . ($s['blog_footer'] ? ' checked' : '') . '>' . nm_t('フッターのリンクに出す（HOMEの次）') . '</label>'
        . '<p class="note">' . nm_t('URL名や表示名を変えても、ここで出すリンクは自動で変わります。好きな位置に置きたいときは、チェックを外して「トップメニュー」「フッターのリンク」で項目を追加し、表示名に「ブログ」と入れて候補から選んでください。') . '</p></fieldset>'
        . '</div></section>';
}
