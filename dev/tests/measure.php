<?php
// A synthetic "photo": smooth gradients plus fine noise and some hard edges.
// Flat colour would compress unrealistically well and flatter the numbers.
function fake_photo(int $w, int $h) {
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $r = (int) (128 + 110 * sin($x / 180) * cos($y / 240) + random_int(-26, 26));
            $g = (int) (120 + 100 * sin(($x + $y) / 200) + random_int(-26, 26));
            $b = (int) (130 + 95 * cos($x / 150) + random_int(-26, 26));
            $c = imagecolorallocate($im, max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
            imagefilledrectangle($im, $x, $y, $x+1, $y+1, $c);
        }
    }
    // Hard edges: a real product shot has them, and they cost bits.
    $wht = imagecolorallocate($im, 250, 250, 248);
    imagefilledrectangle($im, (int)($w*0.1), (int)($h*0.55), (int)($w*0.9), (int)($h*0.68), $wht);
    return $im;
}

$dir = sys_get_temp_dir() . '/pxmeasure';
@mkdir($dir);
$src = fake_photo(4000, 3000);

printf("%-8s %-8s %s\n", "maxpx", "quality", "size");
foreach ([2000, 1600, 1400, 1200, 1000, 900] as $max) {
    foreach ([86, 82, 78, 72] as $q) {
        $w = imagesx($src); $h = imagesy($src);
        $scale = $max / max($w, $h);
        $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh, $w,$h);
        $f = "$dir/t.webp";
        imagewebp($dst, $f, $q);
        printf("%-8s %-8s %s KB\n", $max . 'px', 'q' . $q, number_format(filesize($f) / 1024, 0));
        imagedestroy($dst);
    }
}
