<?php
/* LOG content warnings: センシティブ / R-18 / R-18G on posts and images. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/**
 * Ratings, weakest first. センシティブ blurs pictures and keeps the text; R-18 and R-18G fold the whole post.
 * R-18 and R-18G ask the reader's age once; R-18 also keeps the post out of search engines.
 */
const NL_RATINGS = ['' => 'なし', 'sensitive' => 'センシティブ', 'r18g' => 'R-18G', 'r18' => 'R-18'];
const NL_RATING_NOTES = ['' => '注意なし', 'sensitive' => '肌の露出・軽い流血など。画像をぼかします', 'r18g' => '強い流血・暴力・グロテスク。記事を折りたたみ、年齢を確認します', 'r18' => '性的な表現。記事を折りたたみ、年齢を確認します'];
const NL_WARNING_MAX = 40;
/** Notes the compose box offers as a start; the owner can edit the text after choosing one. */
const NL_WARNING_PRESETS = ['流血表現があります', '肌の露出があります', '性的な表現があります', '暴力表現があります', 'グロテスクな表現があります', 'ホラー表現があります', '死を扱う内容です', '虫が描かれています', '苦手な方はご注意ください'];

function nl_rating(mixed $value): string
{
    return is_string($value) && isset(NL_RATINGS[$value]) ? $value : '';
}
function nl_rating_rank(string $rating): int
{
    return (int)array_search(nl_rating($rating), array_keys(NL_RATINGS), true);
}
function nl_rating_max(string ...$ratings): string
{
    $out = '';
    foreach ($ratings as $r) if (nl_rating_rank($r) > nl_rating_rank($out)) $out = nl_rating($r);
    return $out;
}
/** R-18 and R-18G fold the post; センシティブ only veils its pictures. */
function nl_rating_folds(string $rating): bool { return nl_rating_rank($rating) >= nl_rating_rank('r18g'); }
function nl_rating_label(string $rating): string { return $rating === '' ? '' : NL_RATINGS[nl_rating($rating)]; }
/** What the reader sees of a post: its own rating, raised by the strongest of its images. */
function nl_post_rating(array $p): string
{
    $rating = nl_rating($p['rating'] ?? '');
    foreach ($p['media'] ?? [] as $id) if (is_string($id) && ($m = nl_load_media($id))) $rating = nl_rating_max($rating, nl_media_rating($p, $m));
    return $rating;
}
/** An image's rating; an editor preview passes the ratings not saved yet in _media_ratings. */
function nl_media_rating(array $p, array $m): string
{
    return nl_rating($p['_media_ratings'][$m['id']] ?? $m['rating'] ?? '');
}
function nl_warning_text(mixed $value): string
{
    $text = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($value) ? $value : ''));
    return mb_substr($text, 0, NL_WARNING_MAX);
}
/** The post's own rating and its optional short note (e.g. 流血表現があります). */
function nl_rating_input(array $input): array
{
    $raw = $input['rating'] ?? '';
    if (!is_string($raw) || ($raw !== '' && !isset(NL_RATINGS[$raw]))) throw new UnexpectedValueException('閲覧注意を選び直してください');
    $warning = $input['warning'] ?? '';
    if (!is_string($warning) || !mb_check_encoding($warning, 'UTF-8')) throw new UnexpectedValueException('注意書きを確認してください');
    return [$raw, nl_warning_text($warning)];
}
/** Image ratings sent with a post: {"<image id>": "<rating>"}, only for images in that post. */
function nl_media_ratings_input(mixed $raw, array $media): array
{
    if (is_string($raw)) $raw = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($raw) || count($raw) > 500) throw new UnexpectedValueException('画像の閲覧注意を選び直してください');
    $out = [];
    foreach ($raw as $id => $rating) {
        $id = (string)$id;
        if (!nl_valid_media($id) || !is_string($rating) || ($rating !== '' && !isset(NL_RATINGS[$rating]))) throw new UnexpectedValueException('画像の閲覧注意を選び直してください');
        if (in_array($id, $media, true)) $out[$id] = $rating;
    }
    return $out;
}
/**
 * Store image ratings (called under the LOG lock). The image's revision stays, so its URLs and the
 * browsers' copies stay valid. Returns the previous rating of each changed image, which undoes the change.
 */
function nl_media_ratings_apply(array $ratings): array
{
    $before = [];
    foreach ($ratings as $id => $rating) {
        $m = nl_load_media((string)$id);
        if (!$m || nl_rating($m['rating'] ?? '') === $rating) continue;
        $before[$m['id']] = nl_rating($m['rating'] ?? '');
        $m['rating'] = $rating; $m['updated'] = time();
        nl_write_record(nl_media_dir($m['id']) . '/media.php', $m);
    }
    if ($before) nm_touch_content();
    return $before;
}
/** A small warning-sign icon. */
function nl_veil_icon(): string
{
    return '<svg class="log-veil-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.3 4.2a2 2 0 0 1 3.4 0l7.6 13.1a2 2 0 0 1-1.7 3H4.4a2 2 0 0 1-1.7-3Z"/><path d="M12 9.5v4.2M12 16.9v.1"/></svg>';
}
function nl_veil_badge(string $rating, string $class = 'log-veil-badge'): string
{
    return $rating === '' ? '' : '<span class="' . $class . '" data-veil="' . h($rating) . '">' . h(nl_rating_label($rating)) . '</span>';
}
/** The cover text: badge, the post's note (or the default for the rating) and what tapping does. */
function nl_veil_label(string $rating, string $warning, string $action): string
{
    $note = $warning !== '' ? $warning : ['sensitive' => 'センシティブな内容を含みます', 'r18g' => 'グロテスクな表現を含みます', 'r18' => '18歳未満の方は閲覧できません'][$rating];
    return '<span class="log-veil-label">' . nl_veil_icon() . nl_veil_badge($rating) . '<span class="log-veil-note">' . h($note) . '</span><span class="log-veil-show">' . $action . '</span></span>';
}
/**
 * A picture under a センシティブ veil. <details> opens without script; the full-size link waits in
 * data-veil-href until it is open, so the image viewer never pages to a picture that is still veiled.
 */
function nl_veil_figure(array $im, string $rating, string $warning, bool $admin): string
{
    $size = ' width="' . (int)$im['w'] . '" height="' . (int)$im['h'] . '"';
    return '<details class="log-veil log-veil-image" data-veil="' . h($rating) . '"><summary class="log-veil-cover"><img class="log-veil-shade" src="' . h(nl_media_url($im, true, $admin)) . '"' . $size . ' alt="" loading="lazy" decoding="async">'
        . nl_veil_label($rating, $warning, '押して表示') . '</summary>'
        . '<figure class="log-figure"><a class="imagelink" data-veil-href="' . h(nl_media_url($im, false, $admin)) . '" data-ns-width="' . (int)$im['w'] . '" data-ns-height="' . (int)$im['h'] . '"><img src="' . h(nl_media_url($im, true, $admin)) . '"' . $size . ' alt="' . h($im['alt']) . '" loading="lazy"></a></figure></details>';
}
/** R-18 / R-18G: the whole body folds under one cover. Its image links wait like nl_veil_figure's. */
function nl_veil_post(string $rating, string $warning, string $body): string
{
    $body = (string)preg_replace('/<a class="imagelink" href=/', '<a class="imagelink" data-veil-href=', $body);
    return '<details class="log-veil log-veil-post" data-veil="' . h($rating) . '"><summary class="log-veil-cover">' . nl_veil_label($rating, $warning, '本文と画像を表示する') . '</summary>' . $body . '</details>';
}
/** In the admin screens and previews: a plain line saying what readers will see first. */
function nl_veil_admin_note(array $p): string
{
    $rating = nl_post_rating($p);
    if ($rating === '') return '';
    $how = nl_rating_folds($rating) ? '読者には本文を折りたたんで表示します' : '読者には画像をぼかして表示します';
    return '<p class="log-veil-admin" data-veil="' . h($rating) . '">' . nl_veil_icon() . nl_veil_badge($rating) . '<span>' . h(nl_warning_text($p['warning'] ?? '')) . '</span><small>' . $how . '</small></p>';
}
/** The description of a folded post for share cards, search results and RSS (its text stays folded). */
function nl_veil_description(array $p): string
{
    $rating = nl_post_rating($p);
    if (!nl_rating_folds($rating)) return '';
    $warning = nl_warning_text($p['warning'] ?? '');
    return '閲覧注意（' . nl_rating_label($rating) . '）の記事です。' . ($warning !== '' ? $warning : '');
}
