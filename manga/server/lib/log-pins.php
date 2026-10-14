<?php
/*
 * Pinned posts: shown first on the top page (and first in the admin's list), in the owner's order.
 * MIT License (c) 2026 Lichiphen. The order lives in data/log/pins.php; posts keep their own dates.
 */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

const NL_PINS_MAX = 30;

function nl_pins_file(): string { return nl_root() . '/pins.php'; }
/** ['ids' => pinned post ids in order, 'revision' => n]. Ids of posts that are gone stay until the next change. */
function nl_pins_record(): array
{
    return nl_memo('pins', static function (): array {
        $r = nl_read_record(nl_pins_file()) ?? [];
        $ids = array_values(array_unique(array_filter(is_array($r['ids'] ?? null) ? $r['ids'] : [], static fn($id) => is_string($id) && nl_valid_post($id))));
        return ['ids' => array_slice($ids, 0, NL_PINS_MAX), 'revision' => max(0, (int)($r['revision'] ?? 0))];
    });
}
function nl_pins(): array { return nl_pins_record()['ids']; }
function nl_is_pinned(string $id): bool { return in_array($id, nl_pins(), true); }
/** Write the list; called under the LOG lock. Returns the new revision. */
function nl_pins_write(array $ids): int
{
    $revision = nl_pins_record()['revision'] + 1;
    nl_write_record(nl_pins_file(), ['ids' => array_values($ids), 'revision' => $revision, 'updated' => time()]);
    nm_touch_content();
    return $revision;
}
/** Pin (to the end of the pinned posts) or unpin one post. */
function nl_pin_set(string $id, bool $on): void
{
    nm_with_lock('personal-log', static function () use ($id, $on) {
        if (!nl_load_post($id)) throw new UnexpectedValueException(nm_t('投稿が見つかりません'));
        $ids = array_values(array_filter(nl_pins(), static fn($pid) => $pid !== $id && nl_load_post($pid)));
        if ($on) {
            if (count($ids) >= NL_PINS_MAX) throw new UnexpectedValueException(nm_t('ピン留めは{n}件までです', ['n' => NL_PINS_MAX]));
            $ids[] = $id;
        }
        nl_pins_write($ids);
        nm_log($on ? 'log_pinned' : 'log_unpinned', $id);
    });
}
/** A new order from the admin: the same posts, rearranged. */
function nl_pins_reorder(array $order, int $revision): int
{
    return nm_with_lock('personal-log', static function () use ($order, $revision) {
        $record = nl_pins_record();
        if ($record['revision'] !== $revision) throw new UnexpectedValueException(nm_t('別の画面でピン留めが変わっています。開き直してください'));
        $want = array_values(array_filter($order, 'is_string')); $have = $record['ids'];
        $a = $want; $b = $have; sort($a); sort($b);
        if (!array_is_list($order) || count($want) !== count($order) || $a !== $b) throw new UnexpectedValueException(nm_t('ピン留めの順番を確認して、画面を開き直してください'));
        return nl_pins_write($want);
    });
}
/** A draft gets a new id when it is published: its pin follows it. Called under the LOG lock. */
function nl_pins_rename(string $old, string $new): void
{
    $ids = nl_pins();
    $at = array_search($old, $ids, true);
    if ($at === false) return;
    $ids[$at] = $new;
    nl_pins_write(array_values(array_unique($ids)));
}
/** Deleted posts leave the list. Called under the LOG lock. */
function nl_pins_forget(array $removed): void
{
    $ids = nl_pins();
    $left = array_values(array_diff($ids, array_map('strval', $removed)));
    if ($left !== $ids) nl_pins_write($left);
}
/** The summaries with the pinned ones first (in pin order); for the top page and the admin list. */
function nl_pinned_first(array $summaries): array
{
    $pins = nl_pins();
    if (!$pins) return $summaries;
    $byId = [];
    foreach ($summaries as $s) $byId[$s['id']] = $s;
    $first = [];
    foreach ($pins as $id) if (isset($byId[$id])) { $first[] = $byId[$id]; unset($byId[$id]); }
    return $first ? array_merge($first, array_values($byId)) : $summaries;
}
/** The pin mark on a post or a tile. */
function nl_pin_icon(): string
{
    return '<svg class="log-pin-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3.5h6l-1 5.2 3.2 3.3v1.8H6.8V12L10 8.7Z"/><path d="M12 13.8V20.5"/></svg>';
}
function nl_pin_badge(string $class = 'log-pin-badge'): string
{
    return '<span class="' . $class . '">' . nl_pin_icon() . '<span>' . nm_t('固定') . '</span></span>';
}
