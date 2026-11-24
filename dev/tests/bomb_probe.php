<?php
/*
 * What the upload path does with an image that is small on disk and enormous
 * in memory.
 *
 * The 5 MB cap bounds the FILE. getimagesize() reads the header and reports
 * the dimensions, and nothing checks them -- so the next thing that happens
 * is imagecreatefrompng() allocating four bytes per pixel.
 *
 * The PNGs below are built by streaming the deflate, a row at a time, so this
 * script does not need the memory it is demonstrating the absence of a guard
 * against.
 */
declare(strict_types=1);
$ROOT = 'C:/Users/adnan/Documents/repos/gelsoft-india';
require $ROOT . '/inc/bootstrap.php';
require_once $ROOT . '/inc/uploads.php';

printf("PHP memory_limit here: %s\n", ini_get('memory_limit'));
printf("the upload cap is:     %s\n\n", bytes_human(UPLOAD_MAX_BYTES));

function chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** A PNG of one flat colour. Streamed, so it costs nothing to make. */
function flat_png(string $path, int $w, int $h): void
{
    $fh = fopen($path, 'wb');
    fwrite($fh, "\x89PNG\r\n\x1a\n");
    fwrite($fh, chunk('IHDR', pack('NN', $w, $h) . "\x08\x02\x00\x00\x00"));

    $ctx = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = "\x00" . str_repeat("\x00", $w * 3);
    $idat = '';
    for ($y = 0; $y < $h; $y++) {
        $idat .= deflate_add($ctx, $row, ZLIB_NO_FLUSH);
    }
    $idat .= deflate_add($ctx, '', ZLIB_FINISH);
    fwrite($fh, chunk('IDAT', $idat));
    fwrite($fh, chunk('IEND', ''));
    fclose($fh);
}

foreach ([[200, 200], [4000, 3000], [12000, 12000], [25000, 25000]] as [$w, $h]) {
    $path = sys_get_temp_dir() . '/bomb-' . $w . '.png';
    flat_png($path, $w, $h);

    $info = @getimagesize($path);
    $px = $w * $h;
    printf("%5dx%-5d  file %8s  pixels %14s  bitmap ~%7s  getimagesize: %s\n",
        $w, $h, bytes_human((int) filesize($path)), number_format($px),
        bytes_human((int) ($px * 4)),
        $info === false ? 'rejected' : 'ACCEPTED, ' . $info[0] . 'x' . $info[1]);
    @unlink($path);
}

echo "\nEvery one of those is under the 5 MB cap and passes the type check.\n";
echo "Nothing between getimagesize() and imagecreatefrompng() looks at the\n";
echo "pixel count, so the only thing standing between a seller and the\n";
echo "server's RAM is PHP's memory_limit -- and hitting that is a fatal\n";
echo "error, not a handled one.\n";
