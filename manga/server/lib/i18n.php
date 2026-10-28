<?php
/*
 * UI language for NagiManga / NagiLog
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * The Japanese text in the code is the key: nm_t('保存しました') returns it as is in Japanese
 * and looks it up in lib/lang/<code>.json otherwise, falling back to the Japanese.
 * {name} in a text is filled from $vars. Scripts get the same table (nm_t_script()).
 * A language is added by placing lib/lang/<code>.json; its "_name" is shown in the settings.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

/** Japanese, then every lib/lang/<code>.json that names itself ("_name"). */
function nm_langs(): array
{
    static $langs = null;
    if ($langs !== null) return $langs;
    $langs = ['ja' => '日本語'];
    foreach (glob(__DIR__ . '/lang/*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        if (!preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $code) || $code === 'ja') continue;
        $name = nm_lang_file($code)['_name'] ?? '';
        if (is_string($name) && $name !== '') $langs[$code] = $name;
    }
    return $langs;
}

function nm_lang_file(string $code): array
{
    static $files = [];
    if (!isset($files[$code])) {
        $raw = @file_get_contents(__DIR__ . '/lang/' . $code . '.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $files[$code] = is_array($data) ? array_filter($data, 'is_string') : [];
    }
    return $files[$code];
}

/** The language of this response; pages that do not choose one stay Japanese. */
function nm_lang(?string $set = null): string
{
    static $lang = 'ja';
    if ($set !== null && isset(nm_langs()[$set])) $lang = $set;
    return $lang;
}

/** The admin's language: the setting, or with "auto" the browser's first choice among the installed languages. */
function nm_admin_lang(array $cfg): string
{
    $pick = (string)($cfg['admin_lang'] ?? 'auto');
    return isset(nm_langs()[$pick]) ? $pick : nm_browser_lang();
}

/** Accept-Language: the supported language with the highest weight (earlier wins a tie); Japanese when none is listed. */
function nm_browser_lang(): string
{
    $best = 'ja';
    $weight = -1.0;
    foreach (array_slice(explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 0, 20) as $i => $part) {
        if (!preg_match('/\A\s*([a-z]{1,8})(?:-([a-z0-9]{1,8}))?(?:-[a-z0-9]{1,8})*\s*(?:;\s*q\s*=\s*([01](?:\.[0-9]{0,3})?))?\s*\z/i', $part, $m)) continue;
        // zh-CN matches zh-CN.json first, then zh.json.
        $full = strtolower($m[1]) . (isset($m[2]) && $m[2] !== '' ? '-' . strtoupper($m[2]) : '');
        $code = isset(nm_langs()[$full]) ? $full : strtolower($m[1]);
        if (!isset(nm_langs()[$code])) continue;
        $w = (isset($m[3]) && $m[3] !== '' ? (float)$m[3] : 1.0) - $i / 1000;
        if ($w > 0 && $w > $weight) { $weight = $w; $best = $code; }
    }
    return $best;
}

/** The current language's texts; keys starting with "_" are notes for translators. */
function nm_lang_table(): array
{
    static $tables = [];
    $lang = nm_lang();
    if ($lang === 'ja') return [];
    return $tables[$lang] ??= array_filter(nm_lang_file($lang), static fn($k) => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY);
}

function nm_t(string $text, array $vars = []): string
{
    $out = nm_lang_table()[$text] ?? $text;
    if (!$vars) return $out;
    $pairs = [];
    foreach ($vars as $k => $v) $pairs['{' . $k . '}'] = (string)$v;
    return strtr($out, $pairs);
}

/** The texts the public page scripts (viewer/*.js) translate: sent with an English LOG or work page as #nm-i18n. dev/i18n_test.py keeps this list complete. */
const NM_FRONT_TEXTS = ['いいね（{n}）。今日はこの記事にもう押せません', 'いいね（{n}）', '今日はここまで。また明日どうぞ', 'タップで+1・長押しで+10ずつ', '読み込んでいます', 'メールアドレス', 'アドレスをコピー', 'メールアプリで送信', '閉じる', 'コピーしました。', 'コピーできませんでした。アドレスを選んでコピーしてください。', '{n}件中', '{start}〜{end}件目', '再生位置', '再生', '一時停止', 'ループ再生（曲の中のループ位置で）', 'ループ再生', 'ミュート', '音量', '全画面', 'ミュートを解除', '全画面を終了', '音を出す', '{name}を開く', '投稿やストーリーズに貼り付けてください。', '新しいポストに貼り付けてください。', 'シェア文をコピーしました。', 'コピーできませんでした。上の文を選んでコピーしてください。', 'センシティブ', 'このブラウザでは、次から{label}を確認せずに表示しますか？', '確認せずに表示する', '毎回確認する', '次から{label}を最初から表示します。', '性的な表現を含みます。', '強い流血・暴力・グロテスクな表現を含みます。', '18歳未満の方は閲覧できません。', '18歳以上ですか？', 'このブラウザでは次から確認しない', '18歳以上なので表示する', '表示しない', '{label}の内容です', '閲覧注意を毎回確認する', '{label}を確認せずに表示しています。', '・', '縮小（-）', '元の大きさ（0）', '拡大（+）', '読む', 'この作品を読むにはパスワードが必要です', '読み込めませんでした。時間をおいて、もう一度お試しください。', 'ページがありません。', '何度も間違えたため、しばらく入力できません。時間をおいてお試しください。', 'いまは入力が混み合っています。1分ほどしてから、もう一度お試しください。', 'パスワードが違います', '通信できませんでした', '前回の続き（{n} ページ目）から開きました', '最初から読む', 'おわり', '次のページ', '前のページ', '目次', '目次を開く', 'コードをコピー', '図', '拡大', '図を拡大して見る', '画面に合わせる', '図の書き方に誤りがあるため、元の文を表示しています。', 'この種類の図にはまだ対応していないため、元の文を表示しています。', '描けるのはフローチャート（graph・flowchart）とシーケンス図（sequenceDiagram）です。', '{n}行目が読めません'];

/** The table for scripts (log-editor.js and others read #nm-i18n); nothing in Japanese. */
function nm_t_script(array $only = []): string
{
    if (nm_lang() === 'ja') return '';
    // Public pages send only the texts their scripts use ($only), not the whole admin table.
    $table = $only ? array_intersect_key(nm_lang_table(), array_flip($only)) : nm_lang_table();
    return '<script type="application/json" id="nm-i18n">'
        . json_encode($table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
/** The language of the public pages: the admin language when one is chosen (English…), otherwise Japanese ("auto" follows each browser, which a shared, cached page cannot). */
function nm_site_lang(): string
{
    $pick = (string)((nm_config() ?? [])['admin_lang'] ?? 'auto');
    return isset(nm_langs()[$pick]) ? $pick : 'ja';
}
