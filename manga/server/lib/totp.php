<?php
/*
 * Two-step login for the admin: 6-digit codes from an authenticator app (TOTP, RFC 6238), and one-time recovery codes.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Off by default. The secret is kept sealed (nm_seal) in data/config.php as "totp"; removing that key over FTP turns it off again.
 * A code is taken once: the last accepted 30-second step is kept, so a code seen over someone's shoulder cannot be used again.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_TOTP_STEP = 30;
const NM_TOTP_DIGITS = 6;
const NM_TOTP_DRIFT = 1;            // also the step before and after (phone clocks run a little off)
const NM_TOTP_WAIT = 300;           // seconds between the password and the code at login
const NM_RECOVERY_COUNT = 8;
/** The name the authenticator app lists the code under; the site's host is the account, so several installs stay apart. */
const NM_TOTP_ISSUER = 'NagiSeries NagiLog';
const NM_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function nm_base32_encode(string $raw): string
{
    $bits = '';
    foreach (str_split($raw) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= NM_BASE32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function nm_base32_decode(string $text): ?string
{
    $text = strtoupper((string)preg_replace('/[\s=-]+/', '', $text));
    if ($text === '' || strspn($text, NM_BASE32) !== strlen($text)) return null;
    $bits = '';
    foreach (str_split($text) as $ch) $bits .= str_pad(decbin(strpos(NM_BASE32, $ch)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) $out .= chr(bindec($byte));
    return $out;
}

/** A new secret (160 bits, as RFC 4226 recommends), in Base32 for the app. */
function nm_totp_new_secret(): string
{
    return nm_base32_encode(random_bytes(20));
}
function nm_totp_code(string $secret, int $step): string
{
    $key = nm_base32_decode($secret) ?? '';
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $n = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($n % 10 ** NM_TOTP_DIGITS), NM_TOTP_DIGITS, '0', STR_PAD_LEFT);
}
/** What was typed, with full-width digits and letters (a Japanese keyboard on the phone) and spaces made plain. */
function nm_otp_clean(string $input): string
{
    return (string)preg_replace('/\s+/u', '', mb_convert_kana($input, 'as', 'UTF-8'));
}
/** The step a code matches (now, or one step off), later than $after; null when it matches none. */
function nm_totp_match(string $secret, string $code, int $after = 0): ?int
{
    $code = nm_otp_clean($code);
    if (!preg_match('/\A[0-9]{' . NM_TOTP_DIGITS . '}\z/', $code)) return null;
    $now = intdiv(time(), NM_TOTP_STEP);
    for ($d = -NM_TOTP_DRIFT; $d <= NM_TOTP_DRIFT; $d++) {
        $step = $now + $d;
        if ($step > $after && hash_equals(nm_totp_code($secret, $step), $code)) return $step;
    }
    return null;
}
/**
 * The address an authenticator app reads (from the QR code, or tapped on the phone itself), in the Key URI format
 * Google Authenticator documents: "Issuer:account" in the path (spaces as %20, never "+") and the same issuer= in the query.
 * $account is the site's host; $image is the site's icon for the apps that read an image= field (FreeOTP and others).
 * Only the secret makes the codes: changing these names never breaks a code that is already registered.
 */
function nm_totp_uri(string $secret, string $account, string $image = ''): string
{
    $issuer = NM_TOTP_ISSUER;
    // A colon would end the issuer part of the label
    $account = str_replace(':', '', mb_substr($account, 0, 60));
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . NM_TOTP_DIGITS . '&period=' . NM_TOTP_STEP
        . ($image !== '' ? '&image=' . rawurlencode($image) : '');
}
/** The secret in groups of four, easier to type by hand. */
function nm_totp_grouped(string $secret): string
{
    return implode(' ', str_split($secret, 4));
}

// --- The saved setting ---------------------------------------------------------

/** The two-step settings in config.php, or null while it is off (or the saved secret cannot be opened). */
function nm_totp_config(?array $cfg = null): ?array
{
    $t = ($cfg ?? nm_config() ?? [])['totp'] ?? null;
    if (!is_array($t) || !is_string($t['secret'] ?? null)) return null;
    $secret = nm_unseal($t['secret']);
    if ($secret === null || nm_base32_decode($secret) === null) return null;
    return ['secret' => $secret, 'last' => (int)($t['last'] ?? 0), 'recovery' => array_values(array_filter((array)($t['recovery'] ?? []), 'is_string')), 'since' => (int)($t['since'] ?? 0)];
}
function nm_totp_on(?array $cfg = null): bool
{
    return nm_totp_config($cfg) !== null;
}
/** New one-time recovery codes ("abcd-efgh-jkmn"); the config keeps only their hashes. */
function nm_recovery_codes(): array
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    for ($i = 0; $i < NM_RECOVERY_COUNT; $i++) {
        $c = '';
        for ($j = 0; $j < 12; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $codes[] = implode('-', str_split($c, 4));
    }
    return $codes;
}
function nm_recovery_hash(string $code): string
{
    return hash_hmac('sha256', strtolower((string)preg_replace('/[\s-]+/', '', $code)), 'recovery|' . (string)(nm_config()['secret'] ?? ''));
}
/** Turn it on with a confirmed secret; returns the recovery codes to show once. */
function nm_totp_enable(array $cfg, string $secret, int $step): array
{
    $codes = nm_recovery_codes();
    $cfg['totp'] = ['secret' => nm_seal($secret), 'last' => $step, 'recovery' => array_map('nm_recovery_hash', $codes), 'since' => time()];
    nm_save_config($cfg);
    return $codes;
}
function nm_totp_disable(array $cfg): void
{
    unset($cfg['totp']);
    nm_save_config($cfg);
}

/**
 * Check a code at login or when changing the setting: a 6-digit code (taken once), or a recovery code (removed once used).
 * Returns 'code', 'recovery' or null.
 */
function nm_totp_verify(string $input): ?string
{
    $cfg = nm_config();
    $t = nm_totp_config($cfg);
    if ($t === null) return null;
    $input = nm_otp_clean($input);
    $step = nm_totp_match($t['secret'], $input, $t['last']);
    if ($step !== null) {
        $cfg['totp']['last'] = $step;
        nm_save_config($cfg);
        return 'code';
    }
    if (preg_match('/\A[a-z0-9]{4}-?[a-z0-9]{4}-?[a-z0-9]{4}\z/i', $input)) {
        $hash = nm_recovery_hash($input);
        foreach ($t['recovery'] as $i => $saved) {
            if (hash_equals($saved, $hash)) {
                unset($t['recovery'][$i]);
                $cfg['totp']['recovery'] = array_values($t['recovery']);
                nm_save_config($cfg);
                nm_log('totp_recovery_used', (string)count($t['recovery']));
                return 'recovery';
            }
        }
    }
    return null;
}
