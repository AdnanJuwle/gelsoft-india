<?php
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/uploads.php';
$dir = sys_get_temp_dir() . '/bannertest'; @mkdir($dir);
$r = upload_profile('banner');
foreach ([[3000,2000,'landscape'],[1200,1600,'portrait'],[4000,1000,'already wide']] as [$w,$h,$what]) {
    $im = imagecreatetruecolor($w,$h);
    for ($y=0;$y<$h;$y+=4) for ($x=0;$x<$w;$x+=4)
        imagefilledrectangle($im,$x,$y,$x+3,$y+3, imagecolorallocate($im,
            ($x*7)%255, ($y*5)%255, (($x+$y)*3)%255));
    $src = "$dir/in.jpg"; imagejpeg($im,$src,90); imagedestroy($im);
    $out = image_resave($src,$dir,'b-'.md5($what),IMAGETYPE_JPEG,$r['max'],$r['quality'],$r['aspect']);
    $i = getimagesize("$dir/$out");
    printf("%-14s %dx%d -> %dx%d  ratio %.2f  %s\n", $what, $w, $h, $i[0], $i[1], $i[0]/$i[1],
        number_format(filesize("$dir/$out")/1024,0).' KB');
    @unlink("$dir/$out");
}
