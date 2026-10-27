<?php
/*
 * Ed25519 signature check (RFC 8032) for servers whose PHP has no sodium extension.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Only verification, and only of public data (a release and its signature), so plain arithmetic is fine here:
 * nothing secret passes through it. Numbers mod p = 2^255 - 19 are 16 limbs of 16 bits in PHP's 64-bit ints;
 * points are extended coordinates (X, Y, Z, T) with the complete addition law, so doubling is the same call.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

/** True when $sig (64 bytes) is $pk's (32 bytes) signature of $msg. Uses sodium when PHP has it. */
function nm_ed25519_verify(string $sig, string $msg, string $pk): bool
{
    if (strlen($sig) !== 64 || strlen($pk) !== 32) return false;
    if (function_exists('sodium_crypto_sign_verify_detached')) {
        try {
            return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
        } catch (Throwable) {
            return false;
        }
    }
    return nm_ed25519_verify_php($sig, $msg, $pk);
}

/** The same check without sodium: [S]B - [k]A must encode to R, with k = SHA-512(R || A || M). */
function nm_ed25519_verify_php(string $sig, string $msg, string $pk): bool
{
    if (PHP_INT_SIZE < 8 || strlen($sig) !== 64 || strlen($pk) !== 32) return false;
    $r = substr($sig, 0, 32);
    $s = substr($sig, 32);
    // S must be below the group order L (no second encoding of the same signature).
    $order = "\xed\xd3\xf5\x5c\x1a\x63\x12\x58\xd6\x9c\xf7\xa2\xde\xf9\xde\x14" . str_repeat("\0", 15) . "\x10";
    if (strcmp(strrev($s), strrev($order)) >= 0) return false;
    $a = nm_ed_decode($pk);
    $rp = nm_ed_decode($r);
    // Like sodium: no key or R of small order (eight of them times 8 give nothing), which would pass for many messages.
    $none = "\x01" . str_repeat("\0", 31);
    if ($a === null || $rp === null || nm_ed_encode(nm_ed_mul("\x08", $a)) === $none || nm_ed_encode(nm_ed_mul("\x08", $rp)) === $none) return false;
    $k = hash('sha512', $r . $pk . $msg, true);
    // A key from a real key pair lies in the group of order L, where [k]A = [k mod L]A: k needs no reduction.
    $check = nm_ed_add(nm_ed_mul($s, nm_ed_base()), nm_ed_mul($k, nm_ed_neg($a)));
    return hash_equals($r, nm_ed_encode($check));
}

// --- Numbers mod p ------------------------------------------------------------------------------------------

function nm_fe(int $n = 0): array
{
    return [$n & 0xffff, $n >> 16] + array_fill(0, 16, 0);
}

function nm_fe_add(array $a, array $b): array
{
    for ($i = 0; $i < 16; $i++) $a[$i] += $b[$i];
    return $a;
}

function nm_fe_sub(array $a, array $b): array
{
    for ($i = 0; $i < 16; $i++) $a[$i] -= $b[$i];
    return $a;
}

/** Move each limb's overflow up; what leaves the top comes back to limb 0 times 38, since 2^256 = 38 (mod p). */
function nm_fe_carry(array $a): array
{
    for ($i = 0; $i < 16; $i++) {
        $c = $a[$i] >> 16;
        $a[$i] -= $c << 16;
        if ($i < 15) $a[$i + 1] += $c;
        else $a[0] += 38 * $c;
    }
    return $a;
}

function nm_fe_mul(array $a, array $b): array
{
    $t = array_fill(0, 31, 0);
    for ($i = 0; $i < 16; $i++) {
        $ai = $a[$i];
        for ($j = 0; $j < 16; $j++) $t[$i + $j] += $ai * $b[$j];
    }
    for ($i = 0; $i < 15; $i++) $t[$i] += 38 * $t[$i + 16];
    return nm_fe_carry(nm_fe_carry(array_slice($t, 0, 16)));
}

/** $a to the power of a little-endian byte string. */
function nm_fe_pow(array $a, string $exp): array
{
    $r = nm_fe(1);
    for ($bit = strlen($exp) * 8 - 1; $bit >= 0; $bit--) {
        $r = nm_fe_mul($r, $r);
        if ((ord($exp[$bit >> 3]) >> ($bit & 7)) & 1) $r = nm_fe_mul($r, $a);
    }
    return $r;
}

function nm_fe_inv(array $a): array
{
    return nm_fe_pow($a, "\xeb" . str_repeat("\xff", 30) . "\x7f");   // p - 2
}

/** The 32 little-endian bytes of the number's smallest form (below p). */
function nm_fe_bytes(array $a): string
{
    // Carry until every limb is 16 bits: the number is then below 2^256 = 2p + 38, so two subtractions of p will do.
    do {
        $a = nm_fe_carry($a);
        $done = true;
        foreach ($a as $limb) if ($limb < 0 || $limb > 0xffff) $done = false;
    } while (!$done);
    $p = [0xffed] + array_fill(0, 15, 0xffff);
    $p[15] = 0x7fff;
    for ($round = 0; $round < 2; $round++) {
        $m = [];
        $borrow = 0;
        for ($i = 0; $i < 16; $i++) {
            $v = $a[$i] - $p[$i] - $borrow;
            $borrow = $v < 0 ? 1 : 0;
            $m[$i] = $v + ($borrow << 16);
        }
        if ($borrow === 0) $a = $m;
    }
    $out = '';
    for ($i = 0; $i < 16; $i++) $out .= chr($a[$i] & 0xff) . chr(($a[$i] >> 8) & 0xff);
    return $out;
}

function nm_fe_from_bytes(string $s): array
{
    $a = [];
    for ($i = 0; $i < 16; $i++) $a[$i] = ord($s[2 * $i]) | (ord($s[2 * $i + 1]) << 8);
    $a[15] &= 0x7fff;
    return $a;
}

function nm_fe_eq(array $a, array $b): bool
{
    return nm_fe_bytes($a) === nm_fe_bytes($b);
}

// --- Points -------------------------------------------------------------------------------------------------

/** The curve's d = -121665 / 121666. */
function nm_ed_d(): array
{
    static $d = null;
    return $d ??= nm_fe_mul(nm_fe_sub(nm_fe(), nm_fe(121665)), nm_fe_inv(nm_fe(121666)));
}

/** 2d, for the addition law. */
function nm_ed_d2(): array
{
    static $d2 = null;
    return $d2 ??= nm_fe_add(nm_ed_d(), nm_ed_d());
}

function nm_ed_add(array $p, array $q): array
{
    [$x1, $y1, $z1, $t1] = $p;
    [$x2, $y2, $z2, $t2] = $q;
    $a = nm_fe_mul(nm_fe_sub($y1, $x1), nm_fe_sub($y2, $x2));
    $b = nm_fe_mul(nm_fe_add($y1, $x1), nm_fe_add($y2, $x2));
    $c = nm_fe_mul(nm_fe_mul($t1, $t2), nm_ed_d2());
    $zz = nm_fe_mul($z1, $z2);
    $d = nm_fe_add($zz, $zz);
    $e = nm_fe_sub($b, $a);
    $f = nm_fe_sub($d, $c);
    $g = nm_fe_add($d, $c);
    $h = nm_fe_add($b, $a);
    return [nm_fe_mul($e, $f), nm_fe_mul($g, $h), nm_fe_mul($f, $g), nm_fe_mul($e, $h)];
}

function nm_ed_neg(array $p): array
{
    return [nm_fe_sub(nm_fe(), $p[0]), $p[1], $p[2], nm_fe_sub(nm_fe(), $p[3])];
}

/** [n]P for a little-endian byte string n. */
function nm_ed_mul(string $n, array $p): array
{
    $q = [nm_fe(), nm_fe(1), nm_fe(1), nm_fe()];
    for ($bit = strlen($n) * 8 - 1; $bit >= 0; $bit--) {
        $q = nm_ed_add($q, $q);
        if ((ord($n[$bit >> 3]) >> ($bit & 7)) & 1) $q = nm_ed_add($q, $p);
    }
    return $q;
}

function nm_ed_encode(array $p): string
{
    $zi = nm_fe_inv($p[2]);
    $y = nm_fe_bytes(nm_fe_mul($p[1], $zi));
    $x = nm_fe_bytes(nm_fe_mul($p[0], $zi));
    $y[31] = chr(ord($y[31]) | ((ord($x[0]) & 1) << 7));
    return $y;
}

/** RFC 8032 5.1.3: y and the sign of x; null when the bytes are not a point (or not in their smallest form). */
function nm_ed_decode(string $s): ?array
{
    if (strlen($s) !== 32) return null;
    $sign = ord($s[31]) >> 7;
    $s[31] = chr(ord($s[31]) & 0x7f);
    $y = nm_fe_from_bytes($s);
    if (nm_fe_bytes($y) !== $s) return null;
    $one = nm_fe(1);
    $yy = nm_fe_mul($y, $y);
    $u = nm_fe_sub($yy, $one);
    $v = nm_fe_add(nm_fe_mul($yy, nm_ed_d()), $one);
    $v3 = nm_fe_mul(nm_fe_mul($v, $v), $v);
    $x = nm_fe_mul(nm_fe_mul($u, $v3), nm_fe_pow(nm_fe_mul($u, nm_fe_mul($v3, nm_fe_mul($v3, $v))), "\xfd" . str_repeat("\xff", 30) . "\x0f"));   // (p - 5) / 8
    $vxx = nm_fe_mul($v, nm_fe_mul($x, $x));
    if (!nm_fe_eq($vxx, $u)) {
        if (!nm_fe_eq($vxx, nm_fe_sub(nm_fe(), $u))) return null;
        $x = nm_fe_mul($x, nm_fe_pow(nm_fe(2), "\xfb" . str_repeat("\xff", 30) . "\x1f"));   // sqrt(-1) = 2^((p - 1) / 4)
    }
    $xb = nm_fe_bytes($x);
    if ($xb === str_repeat("\0", 32) && $sign === 1) return null;
    if ((ord($xb[0]) & 1) !== $sign) $x = nm_fe_sub(nm_fe(), $x);
    return [$x, $y, $one, nm_fe_mul($x, $y)];
}

/** The base point B: y = 4/5, x even. */
function nm_ed_base(): array
{
    static $b = null;
    return $b ??= nm_ed_decode(nm_fe_bytes(nm_fe_mul(nm_fe(4), nm_fe_inv(nm_fe(5)))));
}
