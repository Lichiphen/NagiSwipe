<?php
/*
 * Development only: draws sample manga pages (panels + big page numbers).
 *   php make_pages.php <outdir> <count> [landscapeEvery]
 */
[$_, $out, $count] = $argv + [null, __DIR__ . '/results/pages', 12];
$landscapeAt = (int)($argv[3] ?? 0);
@mkdir($out, 0755, true);
$font = is_file('C:/Windows/Fonts/arialbd.ttf') ? 'C:/Windows/Fonts/arialbd.ttf' : null;

for ($i = 1; $i <= (int)$count; $i++) {
    $wide = $landscapeAt > 0 && $i === $landscapeAt;
    [$w, $h] = $wide ? [2400, 1700] : [1200, 1700];
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 250, 250, 248);
    $black = imagecolorallocate($im, 20, 20, 20);
    $gray = imagecolorallocate($im, 200, 200, 200);
    imagefill($im, 0, 0, $white);
    imagesetthickness($im, 8);

    // Panels
    mt_srand($i * 97);
    $m = 60;
    $rows = $wide ? 2 : mt_rand(3, 4);
    $y = $m;
    $rowH = (int)(($h - $m * 2 - ($rows - 1) * 30) / $rows);
    for ($r = 0; $r < $rows; $r++) {
        $cols = mt_rand(1, $wide ? 3 : 2);
        $x = $m;
        $colW = (int)(($w - $m * 2 - ($cols - 1) * 24) / $cols);
        for ($c = 0; $c < $cols; $c++) {
            imagerectangle($im, $x, $y, $x + $colW, $y + $rowH, $black);
            for ($k = 0; $k < 6; $k++) {
                imageline($im, $x + mt_rand(0, $colW), $y + mt_rand(0, $rowH), $x + mt_rand(0, $colW), $y + mt_rand(0, $rowH), $gray);
            }
            $x += $colW + 24;
        }
        $y += $rowH + 30;
    }

    $label = (string)$i;
    if ($font) {
        imagettftext($im, 260, 0, (int)($w / 2 - 90 * strlen($label)), (int)($h / 2 + 130), $black, $font, $label);
        imagettftext($im, 36, 0, $w - 260, $h - 18, $black, $font, $wide ? "p.$i (wide)" : "p.$i");
    } else {
        imagestring($im, 5, (int)($w / 2), (int)($h / 2), $label, $black);
    }
    imagepng($im, sprintf('%s/%03d.png', $out, $i));
    imagedestroy($im);
}
echo "ok\n";
