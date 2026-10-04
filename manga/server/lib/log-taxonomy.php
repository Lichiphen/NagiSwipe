<?php
/* File-based LOG categories and inline hashtags. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_HASHTAG_PATTERN = '/(?<![A-Za-z0-9_\/#])#([\p{L}\p{M}\p{N}_]{1,60})(?![\p{L}\p{M}\p{N}_])/u';
function nl_taxonomy(): array
{
    return array_replace(['categories' => [], 'hashtag_order' => [], 'revision' => 0, 'updated' => 0], nl_read_record(nl_root() . '/taxonomy.php') ?? []);
}
/** URLs, image tokens and manga labels are never parsed as hashtags. */
function nl_hashtag_segments(string $body): array
{
    return preg_split('~(https?://[^\s<>"\[\]]+|\[(?:Manga[^\]\r\n]{1,230}|Image:[a-f0-9]{16})\])~u', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
}
function nl_hashtags(string $body): array
{
    $tags = [];
    foreach (nl_hashtag_segments($body) as $i => $text) if ($i % 2 === 0) {
        preg_match_all(NL_HASHTAG_PATTERN, $text, $matches);
        foreach ($matches[1] as $name) $tags[$name] = true;
    }
    return array_map('strval', array_keys($tags));
}
function nl_recent_hashtags(bool $public = false, int $limit = 8): array
{
    $posts = nl_post_summaries($public);
    usort($posts, static fn($a, $b) => ($b['updated'] <=> $a['updated']) ?: strnatcmp($b['id'], $a['id']));
    $out = [];
    foreach ($posts as $p) foreach ($p['hashtags'] ?? [] as $name) {
        if (!in_array($name, $out, true)) $out[] = $name;
        if (count($out) >= $limit) return $out;
    }
    return $out;
}
function nl_category_name(string $name): string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 40 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new UnexpectedValueException('カテゴリ名は1〜40文字にしてください');
    return $name;
}
function nl_ordered_hashtags(bool $public = false): array
{
    $recent = nl_recent_hashtags($public, PHP_INT_MAX);
    $ordered = array_values(array_filter(nl_taxonomy()['hashtag_order'], static fn($name) => is_string($name) && in_array($name, $recent, true)));
    return array_values(array_unique(array_merge($ordered, $recent)));
}
function nl_taxonomy_reorder(string $kind, mixed $order, int $revision): int
{
    return nm_with_lock('personal-log', static function () use ($kind, $order, $revision) {
        $tax = nl_taxonomy();
        if ($revision !== (int)$tax['revision']) throw new UnexpectedValueException('別の画面で分類を更新しています。開き直してください');
        $known = match ($kind) { 'category' => array_map('strval', array_keys($tax['categories'])), 'hashtag' => nl_ordered_hashtags(), default => throw new UnexpectedValueException('分類を選び直してください') };
        if (!is_array($order) || !array_is_list($order) || count($order) !== count($known) || count($order) > 10000) throw new UnexpectedValueException('分類の一覧を開き直してください');
        foreach ($order as $id) if (!is_string($id) || !in_array($id, $known, true)) throw new UnexpectedValueException('分類の一覧を開き直してください');
        if (count(array_unique($order)) !== count($order)) throw new UnexpectedValueException('分類が重複しています');
        if ($kind === 'category') {
            $categories = []; foreach ($order as $id) $categories[$id] = $tax['categories'][$id]; $tax['categories'] = $categories;
        } else $tax['hashtag_order'] = $order;
        $tax['revision']++; $tax['updated'] = time();
        nl_write_record(nl_root() . '/taxonomy.php', $tax);
        return $tax['revision'];
    });
}
/** Prepare under the shared lock; write alongside the post for rollback. */
function nl_post_categories(array $input, array &$taxonomy): array
{
    $ids = $input['categories'] ?? [];
    if (!is_array($ids) || count($ids) > 20) throw new UnexpectedValueException('カテゴリは20個まで選べます');
    $out = [];
    foreach ($ids as $id) {
        if (!is_string($id) || !isset($taxonomy['categories'][$id])) throw new UnexpectedValueException('カテゴリを選び直してください');
        $out[$id] = true;
    }
    $names = preg_split('/[,、\r\n]+/u', (string)($input['new_categories'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($names as $name) {
        $name = nl_category_name($name);
        $id = array_search($name, $taxonomy['categories'], true);
        if ($id === false) { $id = bin2hex(random_bytes(6)); $taxonomy['categories'][$id] = $name; }
        $out[$id] = true;
    }
    if (count($out) > 20) throw new UnexpectedValueException('カテゴリは20個まで選べます');
    return array_map('strval', array_keys($out));
}
/** The folder mark used for categories everywhere (post footers and the sidebar). */
function nl_category_icon(): string
{
    return '<svg class="log-cat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>';
}
/** "カテゴリ  [folder]日記, [folder]制作" under a post. */
function nl_category_links(array $p, bool $admin = false): string
{
    $taxonomy = nl_taxonomy(); $links = [];
    foreach ($p['categories'] ?? [] as $id) if (isset($taxonomy['categories'][$id])) $links[] = '<a href="' . ($admin ? '../' : '') . './?category=' . h((string)$id) . '">' . nl_category_icon() . '<span>' . h($taxonomy['categories'][$id]) . '</span></a>';
    return $links ? '<nav class="log-categories" aria-label="カテゴリ"><span class="log-categories-label">カテゴリ</span>' . implode('<span class="log-cat-sep" aria-hidden="true">,</span>', $links) . '</nav>' : '';
}
function nl_taxonomy_rename(array $input): void
{
    nm_with_lock('personal-log', static function () use ($input) {
        $taxonomy = nl_taxonomy();
        if ((int)$input['revision'] !== (int)$taxonomy['revision']) throw new UnexpectedValueException('別の画面で分類を更新しています。開き直してください');
        $changed = []; $kind = $input['kind']; $old = $input['old']; $name = trim($input['name']);
        if ($kind === 'category') {
            $name = nl_category_name($name);
            if (!isset($taxonomy['categories'][$old])) throw new UnexpectedValueException('カテゴリが見つかりません');
            foreach ($taxonomy['categories'] as $id => $label) if ((string)$id !== $old && $label === $name) throw new UnexpectedValueException('同じ名前のカテゴリがあります');
            $taxonomy['categories'][$old] = $name;
        } elseif ($kind === 'hashtag') {
            $name = ltrim($name, '#');
            if (!preg_match('/\A[\p{L}\p{M}\p{N}_]{1,60}\z/u', $name)) throw new UnexpectedValueException('ハッシュタグは60文字以内の文字・数字・_で入力してください');
            foreach (nl_list_posts(false) as $p) {
                $parts = nl_hashtag_segments($p['body']);
                foreach ($parts as $i => $text) if ($i % 2 === 0) $parts[$i] = preg_replace_callback(NL_HASHTAG_PATTERN, static fn($m) => $m[1] === $old ? '#' . $name : $m[0], $text);
                $body = implode('', $parts);
                if ($body === $p['body']) continue;
                nl_validate_body($body);
                $p['body'] = $body; $p['revision']++; $p['updated'] = time();
                $changed[nl_post_file($p['id'])] = $p;
            }
            if (!$changed) throw new UnexpectedValueException('そのハッシュタグを使う投稿がありません。画面を開き直してください');
            $taxonomy['hashtag_order'] = array_values(array_unique(array_map(static fn($tag) => $tag === $old ? $name : $tag, $taxonomy['hashtag_order'])));
        } else throw new UnexpectedValueException('分類を選び直してください');
        $taxonomy['revision']++; $taxonomy['updated'] = time();
        $changed[nl_root() . '/taxonomy.php'] = $taxonomy;
        $original = [];
        try {
            foreach ($changed as $file => $data) {
                $original[$file] = is_file($file) ? file_get_contents($file) : null;
                nl_write_record($file, $data);
            }
            $file = nl_root() . '/index.php'; $original[$file] = is_file($file) ? file_get_contents($file) : null;
            nl_rebuild_index();
        } catch (Throwable $e) {
            foreach ($original as $file => $bytes) {
                if (is_string($bytes)) nm_write_atomic($file, $bytes); else @unlink($file);
                if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
            }
            nl_search_index_drop();
            throw $e;
        }
    });
}
