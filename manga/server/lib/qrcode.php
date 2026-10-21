<?php
/*
 * A small QR code encoder for the two-step login setup (an otpauth:// address), drawn as SVG.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Only what that address needs: byte mode, error correction level M, versions 1–20 (up to 666 bytes).
 * Written from the QR code specification (ISO/IEC 18004); no code from other encoders.
 * QRコードは株式会社デンソーウェーブの登録商標です (QR Code is a registered trademark of DENSO WAVE INCORPORATED).
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

/** Level M per version: [error correction codewords per block, [[blocks, data codewords per block], …]]. */
const NM_QR_BLOCKS = [
    1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
    6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    11 => [30, [[1, 50], [4, 51]]], 12 => [22, [[6, 36], [2, 37]]], 13 => [22, [[8, 37], [1, 38]]], 14 => [24, [[4, 40], [5, 41]]], 15 => [24, [[5, 41], [5, 42]]],
    16 => [28, [[7, 45], [3, 46]]], 17 => [28, [[10, 46], [1, 47]]], 18 => [26, [[9, 43], [4, 44]]], 19 => [26, [[3, 44], [11, 45]]], 20 => [26, [[3, 41], [13, 42]]],
];
const NM_QR_ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74], 17 => [6, 30, 54, 78],
    18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90]];

/**
 * The module grid (true = dark) for $text, or null when it is too long.
 * @return list<list<bool>>|null
 */
function nm_qr_matrix(string $text): ?array
{
    $len = strlen($text);
    $version = 0;
    foreach (NM_QR_BLOCKS as $v => [, $groups]) {
        $capacity = 0;
        foreach ($groups as [$n, $k]) $capacity += $n * $k;
        // 4 bits of mode, 8 (16 from version 10) of length, then the bytes
        if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= 8 * $capacity) { $version = $v; break; }
    }
    if ($version === 0) return null;
    [$ecLen, $groups] = NM_QR_BLOCKS[$version];
    $capacity = 0;
    foreach ($groups as [$n, $k]) $capacity += $n * $k;

    // Data bits: mode 0100 (byte), length, bytes, terminator, then the pad bytes 0xEC 0x11.
    $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, 8 * $capacity - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($i = 0; count($data) < $capacity; $i++) $data[] = $i % 2 ? 0x11 : 0xEC;

    // Split into blocks, add Reed–Solomon codewords, interleave.
    $blocks = []; $ecBlocks = []; $at = 0;
    foreach ($groups as [$n, $k]) {
        for ($b = 0; $b < $n; $b++) {
            $block = array_slice($data, $at, $k); $at += $k;
            $blocks[] = $block;
            $ecBlocks[] = nm_qr_rs($block, $ecLen);
        }
    }
    $codewords = [];
    $maxK = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxK; $i++) foreach ($blocks as $block) if (isset($block[$i])) $codewords[] = $block[$i];
    for ($i = 0; $i < $ecLen; $i++) foreach ($ecBlocks as $block) $codewords[] = $block[$i];

    $size = 17 + 4 * $version;
    $grid = array_fill(0, $size, array_fill(0, $size, false));
    $fixed = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $r, int $c, bool $dark) use (&$grid, &$fixed): void { $grid[$r][$c] = $dark; $fixed[$r][$c] = true; };

    // Finder patterns with their white separators.
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $rr = $r0 + $r; $cc = $c0 + $c;
            if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
            $ring = max(abs($r - 3), abs($c - 3));
            $set($rr, $cc, $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6 && $ring !== 2);
        }
    }
    // Alignment patterns (not over the finders).
    $align = NM_QR_ALIGN[$version];
    foreach ($align as $r) foreach ($align as $c) {
        if ($fixed[$r][$c]) continue;
        for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) $set($r + $dr, $c + $dc, max(abs($dr), abs($dc)) !== 1);
    }
    // Timing patterns.
    for ($i = 8; $i < $size - 8; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    // The dark module, and room for the format (and version) information.
    $set($size - 8, 8, true);
    for ($i = 0; $i < 9; $i++) { if (!$fixed[8][$i]) $set(8, $i, false); if (!$fixed[$i][8]) $set($i, 8, false); }
    for ($i = 0; $i < 8; $i++) { $set(8, $size - 1 - $i, false); if (!$fixed[$size - 1 - $i][8]) $set($size - 1 - $i, 8, false); }
    if ($version >= 7) {
        $info = nm_qr_bch($version, 0x1F25, 12);
        for ($i = 0; $i < 18; $i++) {
            $dark = (($info >> $i) & 1) === 1;
            $set(intdiv($i, 3), $size - 11 + $i % 3, $dark);
            $set($size - 11 + $i % 3, intdiv($i, 3), $dark);
        }
    }

    // Codeword bits in the zigzag, right to left in two-column strips, skipping the timing column.
    $stream = '';
    foreach ($codewords as $w) $stream .= str_pad(decbin($w), 8, '0', STR_PAD_LEFT);
    $pos = 0; $total = strlen($stream);
    $upward = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($step = 0; $step < $size; $step++) {
            $r = $upward ? $size - 1 - $step : $step;
            for ($dc = 0; $dc < 2; $dc++) {
                $c = $right - $dc;
                if ($fixed[$r][$c]) continue;
                $grid[$r][$c] = $pos < $total && $stream[$pos] === '1';
                $pos++;
            }
        }
        $upward = !$upward;
    }

    // Pick the mask with the lowest penalty.
    $best = null; $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $try = $grid;
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
            if (!$fixed[$r][$c] && nm_qr_mask($mask, $r, $c)) $try[$r][$c] = !$try[$r][$c];
        }
        nm_qr_format($try, $size, $mask);
        $score = nm_qr_penalty($try, $size);
        if ($score < $bestScore) { $bestScore = $score; $best = $try; }
    }
    return $best;
}

function nm_qr_mask(int $mask, int $r, int $c): bool
{
    return match ($mask) {
        0 => ($r + $c) % 2 === 0,
        1 => $r % 2 === 0,
        2 => $c % 3 === 0,
        3 => ($r + $c) % 3 === 0,
        4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
        5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
        6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
        default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
    };
}

/** The 15 format bits (level M = 00, then the mask) in both copies. */
function nm_qr_format(array &$grid, int $size, int $mask): void
{
    $bits = nm_qr_bch($mask, 0x537, 10) ^ 0x5412;
    for ($i = 0; $i < 15; $i++) {
        $dark = (($bits >> $i) & 1) === 1;
        // Around the top-left finder
        if ($i < 6) $grid[$i][8] = $dark;
        elseif ($i < 8) $grid[$i + 1][8] = $dark;
        elseif ($i === 8) $grid[8][7] = $dark;
        else $grid[8][14 - $i] = $dark;
        // Beside the other two finders
        if ($i < 8) $grid[8][$size - 1 - $i] = $dark;
        else $grid[$size - 15 + $i][8] = $dark;
    }
}

/** $value followed by its BCH remainder ($degree bits) for the generator $poly. */
function nm_qr_bch(int $value, int $poly, int $degree): int
{
    $rem = $value << $degree;
    $top = strlen(decbin($poly)) - 1;
    while ($rem > 0 && strlen(decbin($rem)) - 1 >= $top) $rem ^= $poly << (strlen(decbin($rem)) - 1 - $top);
    return ($value << $degree) | $rem;
}

/** Reed–Solomon error correction codewords over GF(256) (x^8 + x^4 + x^3 + x^2 + 1). */
function nm_qr_rs(array $data, int $n): array
{
    static $exp = [], $log = [];
    if (!$exp) {
        $x = 1;
        for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    }
    $mul = static fn(int $a, int $b): int => $a === 0 || $b === 0 ? 0 : $exp[$log[$a] + $log[$b]];
    // Generator: (x - a^0)(x - a^1)…(x - a^(n-1)), highest power first
    $gen = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) { $next[$j] ^= $g; $next[$j + 1] ^= $mul($g, $exp[$i]); }
        $gen = $next;
    }
    $rem = array_fill(0, $n, 0);
    foreach ($data as $d) {
        $factor = $d ^ $rem[0];
        array_shift($rem); $rem[] = 0;
        for ($j = 0; $j < $n; $j++) $rem[$j] ^= $mul($gen[$j + 1], $factor);
    }
    return $rem;
}

/** The four penalty rules of the specification. */
function nm_qr_penalty(array $g, int $size): int
{
    $score = 0; $dark = 0;
    for ($a = 0; $a < $size; $a++) {
        foreach ([true, false] as $rows) {
            $run = 1; $line = '';
            for ($b = 0; $b < $size; $b++) {
                $v = $rows ? $g[$a][$b] : $g[$b][$a];
                $line .= $v ? '1' : '0';
                if ($b > 0) {
                    $prev = $rows ? $g[$a][$b - 1] : $g[$b - 1][$a];
                    if ($v === $prev) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; } else $run = 1;
                }
            }
            $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
        for ($b = 0; $b < $size; $b++) {
            if ($g[$a][$b]) $dark++;
            if ($a < $size - 1 && $b < $size - 1 && $g[$a][$b] === $g[$a + 1][$b] && $g[$a][$b] === $g[$a][$b + 1] && $g[$a][$b] === $g[$a + 1][$b + 1]) $score += 3;
        }
    }
    return $score + 10 * intdiv(abs(intdiv(100 * $dark, $size * $size) - 50), 5);
}

/** The QR code as an SVG image (black on white, with the 4-module quiet zone), or '' when $text is too long. */
function nm_qr_svg(string $text, string $label): string
{
    $grid = nm_qr_matrix($text);
    if ($grid === null) return '';
    $size = count($grid);
    $path = '';
    foreach ($grid as $r => $row) foreach ($row as $c => $dark) if ($dark) $path .= 'M' . ($c + 4) . ' ' . ($r + 4) . 'h1v1h-1z';
    $box = $size + 8;
    return '<svg class="nm-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" width="' . ($box * 6) . '" height="' . ($box * 6) . '" shape-rendering="crispEdges" role="img" aria-label="' . h($label) . '">'
        . '<rect width="' . $box . '" height="' . $box . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}
