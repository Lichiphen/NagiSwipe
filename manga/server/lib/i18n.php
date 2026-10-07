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

/** The table for scripts (log-editor.js and others read #nm-i18n); nothing in Japanese. */
function nm_t_script(): string
{
    if (nm_lang() === 'ja') return '';
    return '<script type="application/json" id="nm-i18n">'
        . json_encode(nm_lang_table(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
