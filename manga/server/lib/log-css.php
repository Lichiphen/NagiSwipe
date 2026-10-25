<?php
/* Personal LOG additional CSS: storage, syntax check, delivery and the editor page. MIT License (c) 2026 Lichiphen. */
declare(strict_types=1);
if (!defined('NAGIMANGA')) { http_response_code(404); exit; }

/** 256 KiB of UTF-8, checked before a file is read in the browser and again here. */
const NL_CSS_MAX = 262144;
const NL_CSS_HASH_PATTERN = '/\A[a-f0-9]{16}\z/';
const NL_CSS_ERRORS_MAX = 50;
/** The catalog's screens and kinds of change (translated where shown). */
const NL_CSS_PAGES = ['all' => 'すべてのページ', 'list' => '投稿一覧', 'post' => '記事のページ', 'page' => '固定ページ', 'sidebar' => 'サイドバー', 'tiles' => 'タイル表示の一覧', 'footer' => 'サイト下部'];
const NL_CSS_CHANGES = ['added' => '追加', 'changed' => '変更あり', 'deprecated' => '非推奨', 'renamed' => '改名', 'removed' => '廃止'];

/** A syntax error stops the save: the stored CSS stays as it was. */
final class NlCssSyntaxError extends UnexpectedValueException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(nm_t('構文エラーがあるため保存していません（{n}件）。公開中のCSSはそのままです', ['n' => count($errors)]));
    }
}
/** Another tab or device saved first. */
final class NlCssConflict extends UnexpectedValueException
{
    public function __construct(public readonly int $revision)
    {
        parent::__construct(nm_t('別の画面で追加CSSが保存されています。編集中の内容は残しています'));
    }
}

/*
 * Storage: data/log/custom-css/<hash>.css holds each saved text; state.php names the one in use and the one before it.
 * A new version is written first and state.php switched last, so a failed write leaves the last good CSS in place.
 * The file names come from the content hash, never from an uploaded file's name.
 */
function nl_css_dir(): string { return nl_root() . '/custom-css'; }
function nl_css_file(string $hash): string
{
    if (!preg_match(NL_CSS_HASH_PATTERN, $hash)) throw new InvalidArgumentException('bad css');
    return nl_css_dir() . '/' . $hash . '.css';
}
function nl_css_hash(string $css): string { return $css === '' ? '' : substr(hash('sha256', $css), 0, 16); }
/**
 * revision counts saves of the text; switching it on or off does not change it. previous: null when nothing was saved before.
 * layouts: for each list layout (NL_LAYOUTS), whether the CSS is used while the LOG is set to it; older states use it with both.
 */
function nl_css_state_read(): array
{
    $s = nl_read_record(nl_css_dir() . '/state.php') ?? [];
    $hash = static fn($v) => is_string($v) && ($v === '' || preg_match(NL_CSS_HASH_PATTERN, $v)) ? $v : null;
    return ['revision' => max(0, (int)($s['revision'] ?? 0)), 'enabled' => (bool)($s['enabled'] ?? true), 'hash' => $hash($s['hash'] ?? '') ?? '',
        'previous' => $hash($s['previous'] ?? null), 'updated' => max(0, (int)($s['updated'] ?? 0)), 'size' => max(0, (int)($s['size'] ?? 0)),
        'layouts' => nl_css_layouts($s['layouts'] ?? null)];
}
/** Every layout on, unless the stored list says otherwise. */
function nl_css_layouts(mixed $raw): array
{
    $out = [];
    foreach (NL_LAYOUTS as $layout => $_) $out[$layout] = !is_array($raw) || !array_key_exists($layout, $raw) || (bool)$raw[$layout];
    return $out;
}
/** Whether the public pages load the CSS now: switched on, saved, and on for the LOG's current list layout. */
function nl_css_active(array $state): bool
{
    return $state['enabled'] && $state['hash'] !== '' && ($state['layouts'][nl_settings()['layout']] ?? true);
}
function nl_css_state(): array { return nl_memo('custom-css', 'nl_css_state_read'); }
function nl_css_read(string $hash): string
{
    if ($hash === '') return '';
    $raw = @file_get_contents(nl_css_file($hash));
    return is_string($raw) ? $raw : '';
}
function nl_css_write_state(array $state): void
{
    nl_write_record(nl_css_dir() . '/state.php', $state);
    // Only the text in use and the one before it are kept.
    foreach (glob(nl_css_dir() . '/*.css') ?: [] as $file) {
        $name = basename($file, '.css');
        if (preg_match(NL_CSS_HASH_PATTERN, $name) && $name !== $state['hash'] && $name !== $state['previous']) @unlink($file);
    }
}
function nl_css_store(string $css): string
{
    $hash = nl_css_hash($css);
    if ($hash !== '' && @file_get_contents(nl_css_file($hash)) !== $css) nm_write_atomic(nl_css_file($hash), $css);
    return $hash;
}

/** The text as typed or read from a file: UTF-8 within 256 KiB, line ends unified, no BOM or control characters. */
function nl_css_input(mixed $css): string
{
    if (!is_string($css)) throw new UnexpectedValueException(nm_t('CSSを受け取れませんでした'));
    if (strlen($css) > NL_CSS_MAX) throw new UnexpectedValueException(nm_t('追加CSSは256KiB（UTF-8）以内にしてください'));
    if (!mb_check_encoding($css, 'UTF-8')) throw new UnexpectedValueException(nm_t('CSSはUTF-8で保存してください'));
    if (str_starts_with($css, "\xEF\xBB\xBF")) $css = substr($css, 3);
    $css = str_replace(["\r\n", "\r"], "\n", $css);
    // Tab, line feed and form feed are CSS white space; nothing else below U+0020 belongs in a stylesheet.
    if (preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css)) throw new UnexpectedValueException(nm_t('CSSに使えない制御文字が入っています'));
    return $css;
}

/*
 * Syntax check, the same rules as admin/css-editor.js (dev/log_css_browser_test.py compares the two):
 * unclosed comments, strings, url() and brackets, closing brackets without a partner, a "{" with no selector,
 * an item ending in ";" that is neither an at-rule nor "property: value", and a selector left without "{ }".
 * Unknown properties, values and selectors are not errors here: the editor shows them as warnings.
 */
function nl_css_errors(string $css): array
{
    $errors = [];
    $add = static function (int $at, string $message) use (&$errors): void { if (count($errors) < NL_CSS_ERRORS_MAX) $errors[] = [$at, $message]; };
    $n = strlen($css); $i = 0;
    $tokens = [];
    $nameChar = static fn(string $c): bool => $c !== '' && (ctype_alnum($c) || $c === '-' || $c === '_' || ord($c) >= 0x80);
    $nameStart = static fn(string $c): bool => $c !== '' && (ctype_alpha($c) || $c === '_' || ord($c) >= 0x80);
    $ws = static fn(string $c): bool => $c === ' ' || $c === "\t" || $c === "\n" || $c === "\f";
    $at = static fn(int $k): string => $k < $n ? $css[$k] : '';
    $name = static function (int $k) use ($css, $n, $nameChar): int {
        while ($k < $n) {
            if ($css[$k] === '\\' && $k + 1 < $n && $css[$k + 1] !== "\n") { $k += 2; continue; }
            if (!$nameChar($css[$k])) break;
            $k++;
        }
        return $k;
    };
    $identStart = static fn(int $k): bool => $nameStart($at($k)) || ($at($k) === '\\' && $at($k + 1) !== "\n" && $at($k + 1) !== '')
        || ($at($k) === '-' && ($nameStart($at($k + 1)) || $at($k + 1) === '-' || ($at($k + 1) === '\\' && $at($k + 2) !== "\n" && $at($k + 2) !== '')));
    while ($i < $n) {
        $c = $css[$i];
        if ($ws($c)) { $i++; continue; }
        if ($c === '/' && $at($i + 1) === '*') {
            $end = strpos($css, '*/', $i + 2);
            if ($end === false) { $add($i, nm_t('コメント（/*）が閉じていません')); break; }
            $i = $end + 2; continue;
        }
        if ($c === '"' || $c === "'") {
            $start = $i; $i++;
            while (true) {
                if ($i >= $n) { $add($start, nm_t('文字列の引用符が閉じていません')); break; }
                $d = $css[$i];
                if ($d === '\\') { $i += 2; continue; }
                if ($d === "\n") { $add($start, nm_t('文字列の引用符が閉じていません')); break; }
                $i++;
                if ($d === $c) break;
            }
            $tokens[] = ['str', $start]; continue;
        }
        if ($c === '@' && $identStart($i + 1)) { $end = $name($i + 1); $tokens[] = ['at', $i]; $i = $end; continue; }
        if ($identStart($i)) {
            $start = $i; $i = $name($i);
            if ($at($i) === '(') {
                if (strtolower(substr($css, $start, $i - $start)) === 'url') {
                    $k = $i + 1;
                    while ($ws($at($k))) $k++;
                    if ($at($k) !== '"' && $at($k) !== "'") {
                        // An unquoted url(): no quotes, "(" or white space inside.
                        $bad = false;
                        while (true) {
                            $d = $at($k);
                            if ($d === '') { $add($start, nm_t('url( ) が閉じていません')); $i = $n; break; }
                            if ($d === ')') { $i = $k + 1; break; }
                            if ($ws($d)) { while ($ws($at($k))) $k++; if ($at($k) !== ')') $bad = true; continue; }
                            if ($d === '"' || $d === "'" || $d === '(') $bad = true;
                            $k += $d === '\\' ? 2 : 1;
                        }
                        if ($bad) $add($start, nm_t('url( ) の中身が正しくありません。空白や引用符を含むときは url("…") と書きます'));
                        $tokens[] = ['other', $start]; continue;
                    }
                }
                $tokens[] = ['(', $start]; $i++; continue;
            }
            $tokens[] = ['ident', $start]; continue;
        }
        if (ctype_digit($c) || (($c === '.' || $c === '+' || $c === '-') && (ctype_digit($at($i + 1)) || ($at($i + 1) === '.' && ctype_digit($at($i + 2)))))) {
            $start = $i; $i++;
            while ($i < $n && (ctype_digit($css[$i]) || $css[$i] === '.')) $i++;
            if (($at($i) === 'e' || $at($i) === 'E') && (ctype_digit($at($i + 1)) || (($at($i + 1) === '+' || $at($i + 1) === '-') && ctype_digit($at($i + 2))))) { $i += 2; while ($i < $n && ctype_digit($css[$i])) $i++; }
            if ($at($i) === '%') $i++; elseif ($identStart($i)) $i = $name($i);
            $tokens[] = ['other', $start]; continue;
        }
        if ($c === '#') { $tokens[] = ['other', $i]; $i = $name($i + 1); continue; }
        if ($c === '\\') { $tokens[] = ['other', $i]; $i += 2; continue; }
        $tokens[] = [str_contains('{}()[];:', $c) ? $c : 'other', $i];
        $i++;
    }
    // Structure: a stack of open brackets; items are read only directly inside the sheet or a { } block.
    $stack = [['top', -1, false]];
    $item = null;
    $close = ['}' => '{', ')' => '(', ']' => '['];
    $endItem = static function (bool $semicolon) use (&$item, &$stack, $add): void {
        if ($item === null) return;
        [$start, $first, $colon] = $item; $item = null;
        if ($first === 'at') return;
        if (end($stack)[0] === 'top') { $add($start, $semicolon ? nm_t('ここには宣言を書けません。セレクター { } の中に書きます') : nm_t('セレクターのあとに { } がありません')); return; }
        if ($first === ':') $add($start, nm_t('プロパティ名がありません'));
        elseif (!$colon) $add($start, nm_t('宣言に「:」がありません（プロパティ名: 値; の形で書きます）'));
    };
    foreach ($tokens as [$type, $pos]) {
        $top = end($stack);
        $items = $top[0] === 'top' || ($top[0] === '{' && !$top[2]);
        if (isset($close[$type])) {
            $match = null;
            for ($k = count($stack) - 1; $k > 0; $k--) if ($stack[$k][0] === $close[$type]) { $match = $k; break; }
            // A "}" closes the nearest "{" and reports the brackets left open inside it; ")" and "]" do not reach past a "{".
            if ($match !== null && $type !== '}') for ($k = count($stack) - 1; $k > $match; $k--) if ($stack[$k][0] === '{') { $match = null; break; }
            if ($match === null) { $add($pos, $type === '}' ? nm_t('対応する「{」がない「}」です') : nm_t('対応する開きかっこがない「{c}」です', ['c' => $type])); continue; }
            for ($k = count($stack) - 1; $k > $match; $k--) $add($stack[$k][1], nm_t('「{c}」が閉じていません', ['c' => $stack[$k][0]]));
            array_splice($stack, $match + 1);
            // The last declaration of a block may go without ";".
            if ($type === '}' && !$stack[$match][2]) $endItem(false);
            array_pop($stack);
            continue;
        }
        if (!$items) { if ($type === '{' || $type === '(' || $type === '[') $stack[] = [$type, $pos, true]; continue; }
        if ($type === ';') { $endItem(true); continue; }
        if ($type === '{') {
            // A custom property may hold a { } block in its value.
            if ($item !== null && $item[2] && $item[3]) { $stack[] = ['{', $pos, true]; continue; }
            if ($item === null) $add($pos, nm_t('「{」の前にセレクターがありません'));
            $item = null;
            $stack[] = ['{', $pos, false];
            continue;
        }
        if ($item === null) { $item = [$pos, $type === 'at' ? 'at' : ($type === ':' ? ':' : $type), false, false, 1]; if ($type === 'ident') $item[3] = substr($css, $pos, 2) === '--'; if ($type === '(' || $type === '[') $stack[] = [$type, $pos, true]; continue; }
        $item[4]++;
        if ($type === ':' && $item[4] === 2 && $item[1] === 'ident') $item[2] = true;
        if ($type === '(' || $type === '[') $stack[] = [$type, $pos, true];
    }
    if ($item !== null && end($stack)[0] === 'top') $endItem(false);
    for ($k = count($stack) - 1; $k > 0; $k--) $add($stack[$k][1], nm_t('「{c}」が閉じていません', ['c' => $stack[$k][0]]));
    usort($errors, static fn($a, $b) => $a[0] <=> $b[0]);
    return array_map(static fn($e) => nl_css_place($css, $e[0]) + ['message' => $e[1]], $errors);
}
/** Line and column (in characters, from 1) of a byte offset. */
function nl_css_place(string $css, int $offset): array
{
    $before = substr($css, 0, $offset);
    $line = substr_count($before, "\n") + 1;
    $start = strrpos($before, "\n");
    return ['line' => $line, 'column' => mb_strlen($start === false ? $before : substr($before, $start + 1), 'UTF-8') + 1, 'offset' => mb_strlen($before, 'UTF-8')];
}

function nl_css_save(mixed $css, int $revision): array
{
    $css = nl_css_input($css);
    if ($errors = nl_css_errors($css)) throw new NlCssSyntaxError($errors);
    return nm_with_lock('custom-css', static function () use ($css, $revision): array {
        $old = nl_css_state_read();
        if ($old['revision'] !== $revision) throw new NlCssConflict($old['revision']);
        $hash = nl_css_store($css);
        $state = ['revision' => $revision + 1, 'enabled' => $old['enabled'], 'hash' => $hash,
            // The text before this one, for "restore"; saving the same text again keeps it.
            'previous' => $old['revision'] === 0 ? null : ($old['hash'] === $hash ? $old['previous'] : $old['hash']), 'updated' => time(), 'size' => strlen($css), 'layouts' => $old['layouts']];
        nl_css_write_state($state);
        nm_log('custom_css_saved', $hash === '' ? 'empty' : $hash);
        return $state;
    });
}
function nl_css_set_enabled(bool $enabled): array
{
    return nm_with_lock('custom-css', static function () use ($enabled): array {
        $state = nl_css_state_read();
        $state['enabled'] = $enabled;
        nl_write_record(nl_css_dir() . '/state.php', $state);
        nm_log('custom_css_' . ($enabled ? 'on' : 'off'));
        return $state;
    });
}
/** The list layouts the CSS is used with ($on: layout => bool). */
function nl_css_set_layouts(array $on): array
{
    return nm_with_lock('custom-css', static function () use ($on): array {
        $state = nl_css_state_read();
        $state['layouts'] = nl_css_layouts($on);
        nl_write_record(nl_css_dir() . '/state.php', $state);
        nm_log('custom_css_layouts', implode(',', array_keys(array_filter($state['layouts']))) ?: 'none');
        return $state;
    });
}
/** Swap the text in use with the one saved before it. */
function nl_css_restore_previous(int $revision): array
{
    return nm_with_lock('custom-css', static function () use ($revision): array {
        $old = nl_css_state_read();
        if ($old['revision'] !== $revision) throw new NlCssConflict($old['revision']);
        $prev = $old['previous'];
        if ($prev === null || ($prev !== '' && !is_file(nl_css_file($prev)))) throw new UnexpectedValueException(nm_t('戻せる保存がありません'));
        $state = ['revision' => $revision + 1, 'enabled' => $old['enabled'], 'hash' => $prev, 'previous' => $old['hash'], 'updated' => time(), 'size' => strlen(nl_css_read($prev)), 'layouts' => $old['layouts']];
        nl_css_write_state($state);
        nm_log('custom_css_restored', $prev === '' ? 'empty' : $prev);
        return $state;
    });
}
/** A backup's CSS replaces the one here only when overwriting or when none was ever saved; a backup without it changes nothing. */
function nl_css_restore_plan(array $mark): ?array
{
    if (!array_key_exists('custom_css', $mark)) return null;
    $c = $mark['custom_css'];
    if (!is_array($c) || !is_bool($c['enabled'] ?? null) || !is_string($c['css'] ?? null)) throw new UnexpectedValueException(nm_t('追加CSSの設定が壊れています'));
    $css = nl_css_input($c['css']);
    if (nl_css_errors($css)) throw new UnexpectedValueException(nm_t('追加CSSの設定が壊れています'));
    // Backups made before the per-layout switch have no list: the CSS is used with every layout.
    if (isset($c['layouts']) && !is_array($c['layouts'])) throw new UnexpectedValueException(nm_t('追加CSSの設定が壊れています'));
    return ['enabled' => $c['enabled'], 'css' => $css, 'layouts' => nl_css_layouts($c['layouts'] ?? null)];
}
/** Inside the restore: returns the state file's old text for the rollback, or false when nothing changed. */
function nl_css_restore_apply(?array $plan, bool $overwrite): string|false|null
{
    if ($plan === null) return false;
    return nm_with_lock('custom-css', static function () use ($plan, $overwrite): string|false|null {
        $old = nl_css_state_read();
        if (!$overwrite && $old['revision'] > 0) return false;
        $file = nl_css_dir() . '/state.php';
        $before = is_file($file) ? (string)file_get_contents($file) : null;
        $hash = nl_css_store($plan['css']);
        nl_css_write_state(['revision' => $old['revision'] + 1, 'enabled' => $plan['enabled'], 'hash' => $hash,
            'previous' => $old['revision'] === 0 ? null : ($old['hash'] === $hash ? $old['previous'] : $old['hash']), 'updated' => time(), 'size' => strlen($plan['css']), 'layouts' => $plan['layouts']]);
        return $before;
    });
}
function nl_css_backup(): array
{
    $state = nl_css_state_read();
    return ['enabled' => $state['enabled'], 'css' => nl_css_read($state['hash']), 'layouts' => $state['layouts']];
}

/** The <link> after the LOG's own stylesheets, or nothing while it is off, empty or off for the current list layout. */
function nl_css_link(): string
{
    $state = nl_css_state();
    if (!nl_css_active($state) || !is_file(nl_css_file($state['hash']))) return '';
    return '<link rel="stylesheet" href="./?css=' . $state['hash'] . '" data-nl-custom-css>';
}
/** ./?css=<hash>: the CSS in use as text/css. The hash in the URL is the cache key; the stored text is never run as PHP. */
function nl_css_serve(string $hash, bool $public): never
{
    $state = nl_css_state();
    if (!nl_css_active($state)) nm_not_found();
    $css = nl_css_read($state['hash']);
    if ($css === '') nm_not_found();
    header('Content-Type: text/css; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($css));
    // An old address (an older page in a cache) gets today's CSS, briefly cached.
    header('Cache-Control: ' . ($hash === $state['hash'] ? ($public ? 'public' : 'private') . ', max-age=31536000, immutable' : 'no-cache'));
    header('ETag: "' . $state['hash'] . '"');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $css;
    exit;
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

function nl_css_wants_json(): bool { return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'); }
function nl_css_reply(array $state, string $message): never
{
    if (nl_css_wants_json()) nm_json(['ok' => true, 'message' => $message] + nl_css_public_state($state));
    nm_flash('ok', $message);
    nm_redirect('p=css');
}
function nl_css_public_state(array $state): array
{
    return ['revision' => $state['revision'], 'enabled' => $state['enabled'], 'layouts' => $state['layouts'], 'active' => nl_css_active($state), 'hash' => $state['hash'], 'size' => $state['size'], 'updated' => $state['updated'],
        'updated_text' => $state['updated'] ? nl_date($state['updated'], 'Y/m/d H:i') : '', 'restorable' => $state['previous'] !== null, 'css' => nl_css_read($state['hash'])];
}
/** log_css_save / log_css_enabled / log_css_layouts / log_css_restore (guests and bad CSRF tokens were turned away before this). */
function nl_css_handle_post(string $do): never
{
    try {
        switch ($do) {
            case 'log_css_save':
                $state = nl_css_save($_POST['css'] ?? null, (int)nm_str($_POST, 'revision', 10));
                unset($_SESSION['nl_css_draft']);
                nl_css_reply($state, nm_t('追加CSSを保存しました'));
            case 'log_css_enabled':
                $on = nm_str($_POST, 'enabled', 1) === '1';
                nl_css_reply(nl_css_set_enabled($on), $on ? nm_t('追加CSSを公開ページに適用しました') : nm_t('追加CSSの適用を止めました。保存した内容はそのままです'));
            case 'log_css_layouts':
                $on = [];
                foreach (NL_LAYOUTS as $layout => $_) $on[$layout] = in_array($layout, (array)($_POST['layouts'] ?? []), true);
                $state = nl_css_set_layouts($on);
                nl_css_reply($state, nl_css_active($state) || !$state['enabled'] || $state['hash'] === '' ? nm_t('一覧の形ごとの適用を保存しました') : nm_t('一覧の形ごとの適用を保存しました。今の一覧の形（{layout}）では追加CSSを読み込みません', ['layout' => nm_t(NL_LAYOUTS[nl_settings()['layout']])]));
            case 'log_css_restore':
                nl_css_reply(nl_css_restore_previous((int)nm_str($_POST, 'revision', 10)), nm_t('直前に保存した追加CSSに戻しました'));
        }
        nm_not_found();
    } catch (Throwable $e) {
        $message = $e instanceof UnexpectedValueException ? $e->getMessage() : nm_t('保存できませんでした。空き容量や書き込み権限を確認してください');
        if (!$e instanceof UnexpectedValueException) nm_log('custom_css_error', $e->getMessage());
        $extra = $e instanceof NlCssSyntaxError ? ['diagnostics' => $e->errors] : ($e instanceof NlCssConflict ? ['conflict' => true, 'revision' => $e->revision] : []);
        if (nl_css_wants_json()) nm_json(['error' => $message] + $extra, $e instanceof NlCssConflict ? 409 : ($e instanceof UnexpectedValueException ? 422 : 500));
        // Without scripts the page shows the text again, so a failed save loses nothing.
        if ($do === 'log_css_save' && is_string($_POST['css'] ?? null) && strlen($_POST['css']) <= NL_CSS_MAX) $_SESSION['nl_css_draft'] = $_POST['css'];
        nm_flash('err', $message . ($e instanceof NlCssSyntaxError ? ' ' . implode(' / ', array_map(static fn($d) => nm_t('{line}行{column}列：{message}', $d), array_slice($e->errors, 0, 5))) : ''));
        nm_redirect('p=css');
    }
}

/** The card in Settings > LOG. */
function nl_css_settings_card(): string
{
    $state = nl_css_state();
    $status = $state['hash'] === '' ? nm_t('まだ追加CSSはありません。') : (!$state['enabled'] ? nm_t('保存した追加CSSの適用を止めています（{size}）。', ['size' => nl_css_size_text($state['size'])])
        : (nl_css_active($state) ? nm_t('公開ページに適用しています（{size}）。', ['size' => nl_css_size_text($state['size'])]) : nm_t('今の一覧の形（{layout}）では読み込まない設定です（{size}）。', ['layout' => nm_t(NL_LAYOUTS[nl_settings()['layout']]), 'size' => nl_css_size_text($state['size'])])));
    return '<section class="card" id="log-custom-css"><h2>' . nm_t('追加CSS') . '</h2><p>' . nm_t('公開LOGの見た目を、自分で書いたCSSで調整できます。管理画面には適用しません。') . '</p><p class="note">' . h($status) . '</p>'
        . '<p><a class="btn" href="index.php?p=css">' . nm_t('追加CSSを編集') . '</a></p></section>';
}
function nl_css_size_text(int $bytes): string
{
    return $bytes < 1024 ? $bytes . ' B' : number_format($bytes / 1024, 1) . ' KiB';
}

/** index.php?p=css: the editor (its script and style load on this page only), or ?download=1 for the saved file. */
function nl_css_view(): void
{
    $state = nl_css_state();
    if (isset($_GET['download'])) {
        $css = nl_css_read($state['hash']);
        header('Content-Type: text/css; charset=UTF-8');
        header('Content-Disposition: attachment; filename="nagilog-custom-' . nl_date($state['updated'] ?: time(), 'Ymd-His') . '.css"');
        header('Content-Length: ' . strlen($css));
        echo $css;
        exit;
    }
    $draft = $_SESSION['nl_css_draft'] ?? null;
    unset($_SESSION['nl_css_draft']);
    $text = is_string($draft) ? $draft : nl_css_read($state['hash']);
    $csrf = nm_csrf_field();
    $body = '<section class="card css-page" data-css-editor data-revision="' . $state['revision'] . '" data-max="' . NL_CSS_MAX . '" data-saved-hash="' . h($state['hash']) . '"' . (is_string($draft) ? ' data-draft' : '') . '>'
        . '<p class="css-back"><a href="index.php?p=settings&amp;section=log#log-custom-css">' . nm_t('設定に戻る') . '</a></p><h1>' . nm_t('追加CSS') . '</h1>'
        . '<p>' . nm_t('公開LOGのページに、LOG本体とテーマのCSSのあとで読み込みます。管理画面には適用しないため、見た目が崩れてもここから直したり、適用を止めたりできます。') . '</p>'
        . '<form class="css-switch" method="post" action="index.php" data-css-enabled-form>' . $csrf . '<input type="hidden" name="do" value="log_css_enabled"><input type="hidden" name="enabled" value="' . ($state['enabled'] ? '0' : '1') . '" data-css-enabled-value>'
        . '<span class="css-switch-state" data-css-enabled-state>' . (!$state['enabled'] ? nm_t('適用を止めています') : ($state['hash'] === '' ? nm_t('保存すると公開ページに適用します') : (nl_css_active($state) ? nm_t('公開ページに適用中') : nm_t('今の一覧の形では読み込みません')))) . '</span>'
        . '<button class="btn" data-css-enabled-button>' . ($state['enabled'] ? nm_t('適用を止める') : nm_t('公開ページに適用する')) . '</button></form>'
        . nl_css_layouts_form($state, $csrf)
        . '<form class="css-form" method="post" action="index.php" data-css-form>' . $csrf . '<input type="hidden" name="do" value="log_css_save"><input type="hidden" name="revision" value="' . $state['revision'] . '" data-css-revision>'
        . '<div class="css-toolbar"><button class="btn primary" data-css-save>' . nm_t('保存') . '</button>'
        . '<label class="btn css-file-button">' . nm_t('CSSファイルを選ぶ') . '<input type="file" accept=".css,text/css" data-css-file></label>'
        . '<a class="btn" href="index.php?p=css&amp;download=1" data-css-download' . ($state['hash'] === '' ? ' hidden' : '') . '>' . nm_t('保存したCSSをダウンロード') . '</a>'
        . '<button class="btn" type="submit" form="css-restore" data-css-restore' . ($state['previous'] === null ? ' hidden' : '') . '>' . nm_t('直前の保存に戻す') . '</button>'
        . '<a class="btn" href="../" target="_blank" rel="noopener">' . nm_t('公開ページを開く') . '</a></div>'
        . '<div class="css-editor" data-css-drop><div class="css-gutter" aria-hidden="true" data-css-gutter></div><div class="css-code"><pre class="css-highlight" aria-hidden="true"><code data-css-highlight></code></pre>'
        . '<textarea name="css" data-css-text spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" wrap="off" aria-label="' . nm_t('追加CSS') . '" aria-describedby="css-help">' . h($text) . '</textarea></div>'
        . '<p class="css-drop-hint" data-css-drop-hint hidden>' . nm_t('ここに.cssファイルをドロップすると、編集中の内容と置き換えます') . '</p></div>'
        . '<p class="note" id="css-help">' . nm_t('.cssファイルをここへドラッグ＆ドロップするか、「CSSファイルを選ぶ」で読み込めます（256KiBまで・UTF-8）。読み込むと編集中の内容と置き換えます。Tabキーで字下げし、Escのあとに押すと次の項目へ移ります。') . '</p>'
        . '<p class="css-status" data-css-status role="status" aria-live="polite">' . h($state['updated'] ? nm_t('最終保存：{time}（{size}）', ['time' => nl_date($state['updated'], 'Y/m/d H:i'), 'size' => nl_css_size_text($state['size'])]) : nm_t('まだ保存していません')) . '</p>'
        . '<div class="css-diagnostics" data-css-diagnostics hidden><h2 data-css-diagnostics-title></h2><ol data-css-diagnostics-list></ol></div></form>'
        . '<form id="css-restore" method="post" action="index.php" class="js-confirm" data-confirm="' . nm_t('直前に保存した追加CSSに戻しますか？今の内容は「直前の保存」として残ります。') . '">' . $csrf . '<input type="hidden" name="do" value="log_css_restore"><input type="hidden" name="revision" value="' . $state['revision'] . '" data-css-revision></form>'
        . '<noscript><p class="note">' . nm_t('JavaScriptが無効なため、色分け・構文チェック・ファイルのドロップは使えません。入力欄に書いて「保存」を押すと、サーバーで構文を確かめてから保存します。') . '</p></noscript></section>'
        . nl_css_help_card() . nl_css_catalog_card();
    nm_layout(nm_t('追加CSS'), $body, true, '<link rel="stylesheet" href="' . nm_asset('css-editor.css') . '"><script src="' . nm_asset('css-editor.js') . '" defer></script>');
}
/** Which list layouts load the CSS: a sheet written for the tiles can be kept away from the mini blog, and the other way round. */
function nl_css_layouts_form(array $state, string $csrf): string
{
    $current = nl_settings()['layout'];
    $names = ['stream' => nm_t('ミニブログのとき'), 'grid' => nm_t('タイルのとき')];
    $boxes = '';
    foreach (NL_LAYOUTS as $layout => $_) $boxes .= '<label class="css-layout-choice"><input type="checkbox" name="layouts[]" value="' . h($layout) . '"' . ($state['layouts'][$layout] ? ' checked' : '') . ' data-css-layout>'
        . '<span>' . h($names[$layout] ?? nm_t(NL_LAYOUTS[$layout])) . '</span>' . ($layout === $current ? '<small class="badge">' . nm_t('今の設定') . '</small>' : '') . '</label>';
    return '<form class="css-layouts" method="post" action="index.php" data-css-layouts-form data-current="' . h($current) . '">' . $csrf . '<input type="hidden" name="do" value="log_css_layouts">'
        . '<fieldset><legend>' . nm_t('一覧の形ごとの適用') . '</legend><div class="css-layout-choices">' . $boxes . '<button class="btn" data-css-layouts-save>' . nm_t('保存') . '</button></div>'
        . '<p class="note">' . nm_t('設定の「一覧の形」がチェックした形のときだけ、追加CSSを公開ページ（一覧・記事・固定ページのすべて）に読み込みます。タイル用とミニブログ用でCSSを分けたいときや、一覧の形を試しに切り替えるときに使います。') . '</p></fieldset></form>';
}
function nl_css_help_card(): string
{
    $items = [
        nm_t('読み込む順番：LOG本体のCSS（log.css）・画像ビューアーのCSSのあとです。漫画ビューアーのCSSは、あとから読み込まれても追加CSSより前に入ります。'),
        nm_t('同じ詳細度なら追加CSSが勝ちます。本体のほうが詳細度の高いセレクターや!importantを使っている場合は、追加CSSが効かないことがあります。そのときは、セレクターをより具体的にします（例：.log-site .log-post）。'),
        nm_t('CSS変数（--log-accent など）を上書きすると、テーマの色をまとめて変えられます。テーマごとに変えるときは html[data-log-theme="dark-navy"] のように書きます。'),
        nm_t('url() の相対パスは、LOGのトップ（log.php のあるフォルダ）が基準です。例：url(viewer/og.jpg)。LOGに載せた画像は、画像一覧で表示されるURL（?media=…）を使えます。'),
        nm_t('安全のため、公開ページのCSP（Content-Security-Policy）はそのままです。画像はこのサイトとdata:、フォントと@importはこのサイトのファイルだけを読み込めます。ほかのサイトの画像・フォント・CSSは読み込まれません。'),
        nm_t('構文エラー（かっこや引用符の閉じ忘れなど）があると保存せず、公開中のCSSをそのまま使います。知らないプロパティ・値・セレクターは警告にとどめます。新しいCSSや、ブラウザーごとの接頭辞（-webkit- など）は、このブラウザーが知らなくても使えることがあります。'),
        nm_t('ページによって表示されない要素（記事の下・固定ページ・ログイン中だけの表示など）もあるため、このページではセレクターが「存在しない」とは判定しません。'),
        nm_t('更新（自動更新・元に戻す・FTPでの上書き）では、追加CSSを変更しません。保存先は data/log/custom-css/ です。LOGのバックアップにも含まれます。'),
    ];
    return '<section class="card css-help"><h2>' . nm_t('効き方と注意') . '</h2><ul>' . implode('', array_map(static fn($t) => '<li>' . h($t) . '</li>', $items)) . '</ul></section>';
}

/*
 * Selectors meant for customizing (lib/css-catalog.json). It is written by hand with each release and checked by
 * dev/css_catalog_check.py against the stylesheets and the code; this page only reads it.
 */
function nl_css_catalog(): array
{
    $raw = @file_get_contents(__DIR__ . '/css-catalog.json');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : ['selectors' => [], 'variables' => [], 'internal' => [], 'state' => [], 'history' => []];
}
function nl_css_catalog_text(mixed $text): string
{
    if (is_string($text)) return $text;
    if (!is_array($text)) return '';
    $lang = nm_lang();
    return (string)($text[$lang] ?? $text['en'] ?? $text['ja'] ?? '');
}
function nl_css_version_label(string $version): string
{
    return match ($version) { 'initial' => nm_t('初期一覧'), 'next' => nm_t('次のバージョン'), default => 'v' . $version };
}
function nl_css_catalog_card(): string
{
    $cat = nl_css_catalog();
    $pages = NL_CSS_PAGES;
    $row = static function (array $e) use ($pages): string {
        $where = implode('・', array_map(static fn($p) => nm_t($pages[$p] ?? $p), (array)($e['pages'] ?? [])));
        $search = strtolower($e['name'] . ' ' . nl_css_catalog_text($e['purpose'] ?? '') . ' ' . $where);
        return '<tr data-css-catalog-row data-search="' . h($search) . '"><th scope="row"><code>' . h($e['name']) . '</code>' . (!empty($e['deprecated']) ? ' <span class="badge">' . nm_t('非推奨') . '</span>' : '') . '</th><td>' . h(nl_css_catalog_text($e['purpose'] ?? '')) . '</td><td>' . h($where) . '</td>'
            . '<td><code class="css-example">' . h((string)($e['example'] ?? '')) . '</code></td><td>' . h(nl_css_version_label((string)($e['since'] ?? 'initial'))) . '</td></tr>';
    };
    $head = '<thead><tr><th scope="col">' . nm_t('名前') . '</th><th scope="col">' . nm_t('用途') . '</th><th scope="col">' . nm_t('対象の画面') . '</th><th scope="col">' . nm_t('記述例') . '</th><th scope="col">' . nm_t('追加') . '</th></tr></thead>';
    $selectors = implode('', array_map($row, $cat['selectors'] ?? []));
    $variables = implode('', array_map($row, $cat['variables'] ?? []));
    $history = $cat['history'] ?? [];
    $versions = '';
    foreach ($history as $i => $h) $versions .= '<option value="' . $i . '">' . h(nl_css_version_label((string)$h['version'])) . '</option>';
    $changes = '';
    foreach ($history as $i => $h) {
        $lines = '';
        foreach (NL_CSS_CHANGES as $kind => $label) {
            foreach ($h[$kind] ?? [] as $c) {
                $name = is_array($c) ? (isset($c['from']) ? $c['from'] . ' → ' . $c['to'] : (string)($c['name'] ?? '')) : (string)$c;
                $note = is_array($c) ? nl_css_catalog_text($c['note'] ?? '') : '';
                $lines .= '<li><span class="css-change css-change-' . $kind . '">' . nm_t($label) . '</span> <code>' . h($name) . '</code>' . ($note !== '' ? ' ' . h($note) : '') . '</li>';
            }
        }
        $changes .= '<li data-css-history="' . $i . '"><h3>' . h(nl_css_version_label((string)$h['version'])) . '</h3>' . ($lines !== '' ? '<ul>' . $lines . '</ul>' : '<p class="note">' . nm_t('公開セレクターの変更はありません。') . '</p>') . '</li>';
    }
    $names = static fn(array $list) => implode(' ', array_map(static fn($n) => '<code>' . h((string)$n) . '</code>', $list));
    return '<section class="card css-catalog" id="css-catalog"><h2>' . nm_t('カスタマイズに使えるクラス・要素') . '</h2>'
        . '<p class="note">' . nm_t('この一覧の名前は、今後のバージョンでもできるだけ同じ意味で残します。名前の変更や削除をするときは、下の「バージョンごとの変更」に書きます。一覧にない名前（内部用・状態用）は、予告なく変わることがあります。') . '</p>'
        . '<label class="css-catalog-search">' . nm_t('絞り込み') . '<input type="search" data-css-catalog-search placeholder="' . nm_t('例：サイドバー、post、--log') . '"></label>'
        . '<h3>' . nm_t('公開セレクター') . '</h3><div class="css-table-wrap"><table class="css-table">' . $head . '<tbody>' . $selectors . '</tbody></table></div>'
        . '<h3>' . nm_t('CSS変数') . '</h3><div class="css-table-wrap"><table class="css-table">' . $head . '<tbody>' . $variables . '</tbody></table></div>'
        . '<details class="css-internal"><summary>' . nm_t('内部用・状態用の名前（カスタマイズには向きません）') . '</summary><p class="note">' . nm_t('状態用のクラスは、表示の切り替えでスクリプトが付け外しします。内部用のクラスは、部品の作りに合わせて変わることがあります。') . '</p>'
        . '<h4>' . nm_t('状態用') . '</h4><p class="css-names">' . $names($cat['state'] ?? []) . '</p><h4>' . nm_t('内部用') . '</h4><p class="css-names">' . $names($cat['internal'] ?? []) . '</p></details>'
        . '<h3>' . nm_t('バージョンごとの変更') . '</h3><label class="css-since">' . nm_t('比較元のバージョン（今使っていたバージョン）') . '<select data-css-since><option value="-1">' . nm_t('すべて表示') . '</option>' . $versions . '</select></label>'
        . '<p class="note">' . nm_t('選んだバージョンより新しい変更を、途中のバージョンも含めてすべて表示します。今のバージョン：{v}', ['v' => 'v' . NM_VERSION]) . '</p><ol class="css-history" data-css-history-list>' . $changes . '</ol></section>';
}
