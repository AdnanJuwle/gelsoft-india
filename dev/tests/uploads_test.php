<?php
/** The profiles, the slot counting, and what a real re-encode costs. */
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/uploads.php';

$pass = $fail = 0;
function check($l, $c, $d = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l $d\n"; }
}

echo "--- profiles ---\n";
check('product is the bigger one', upload_profile('product')['max'] === 1600);
check('listing is smaller', upload_profile('listing')['max'] === 1200);
check('an unknown name falls back to product',
      upload_profile('nonsense') === upload_profile('product'));
check('every profile caps the count',
      array_reduce(UPLOAD_PROFILES, fn($c, $p) => $c && ($p['per_item'] ?? 0) > 0, true));

echo "\n--- what the re-encode actually produces ---\n";
// A real photo-ish image, written out and run through the pipeline.
$src = imagecreatetruecolor(4000, 3000);
for ($y = 0; $y < 3000; $y += 3) {
    for ($x = 0; $x < 4000; $x += 3) {
        $c = imagecolorallocate($src,
            max(0, min(255, (int)(128 + 110 * sin($x/180) * cos($y/240) + random_int(-25, 25)))),
            max(0, min(255, (int)(120 + 100 * sin(($x+$y)/200) + random_int(-25, 25)))),
            max(0, min(255, (int)(130 + 95 * cos($x/150) + random_int(-25, 25)))));
        imagefilledrectangle($src, $x, $y, $x+2, $y+2, $c);
    }
}
$tmp = sys_get_temp_dir() . '/upl_src.jpg';
imagejpeg($src, $tmp, 92);
imagedestroy($src);
echo "  source: " . bytes_human(filesize($tmp)) . " at 4000x3000\n";

$dir = sys_get_temp_dir() . '/upl_out';
@mkdir($dir);
foreach (['product', 'listing'] as $p) {
    $r = upload_profile($p);
    $out = image_resave($tmp, $dir, 'test-' . $p, IMAGETYPE_JPEG, $r['max'], $r['quality']);
    $size = filesize("$dir/$out");
    $info = getimagesize("$dir/$out");
    printf("  %-8s -> %-9s %dx%d\n", $p, bytes_human($size), $info[0], $info[1]);
    check("$p fits its max edge", max($info[0], $info[1]) === $r['max'], "{$info[0]}x{$info[1]}");
    check("$p is stored as webp", str_ends_with($out, '.webp'), $out);
    $GLOBALS['size_' . $p] = $size;
    @unlink("$dir/$out");
}
check('a listing photo is lighter than a product photo',
      $GLOBALS['size_listing'] < $GLOBALS['size_product'],
      bytes_human($GLOBALS['size_listing']) . ' vs ' . bytes_human($GLOBALS['size_product']));
printf("  a 5-photo listing page: %s  (was %s before this change)\n",
    bytes_human($GLOBALS['size_listing'] * 5), bytes_human($GLOBALS['size_product'] * 5));

echo "\n--- slot counting ---\n";
exec_sql("DELETE FROM products WHERE slug = 'cap-test'");
exec_sql("INSERT INTO products (slug, name, category, price_paise, status) VALUES ('cap-test','Cap Test','blaster',100000,'draft')");
$pid = (int) q1("SELECT id FROM products WHERE slug='cap-test'")['id'];

check('an empty item has the full allowance',
      upload_slots_left('product_images', 'product_id', $pid, 'product') === 6);

for ($i = 0; $i < 4; $i++) {
    exec_sql('INSERT INTO product_images (product_id, path, sort_order) VALUES (?,?,?)',
        [$pid, 'uploads/fake-' . $i . '.webp', $i]);
}
check('four used leaves two',
      upload_slots_left('product_images', 'product_id', $pid, 'product') === 2);

for ($i = 4; $i < 6; $i++) {
    exec_sql('INSERT INTO product_images (product_id, path, sort_order) VALUES (?,?,?)',
        [$pid, 'uploads/fake-' . $i . '.webp', $i]);
}
check('a full item leaves none',
      upload_slots_left('product_images', 'product_id', $pid, 'product') === 0);

exec_sql('INSERT INTO product_images (product_id, path, sort_order) VALUES (?,?,?)',
    [$pid, 'uploads/fake-over.webp', 9]);
check('over the cap never goes negative',
      upload_slots_left('product_images', 'product_id', $pid, 'product') === 0);

check('a listing counts against the listing cap',
      upload_slots_left('listing_images', 'listing_id', 999999, 'listing') === 5);

echo "\n--- usage reporting ---\n";
$u = uploads_usage();
check('usage reports a byte count', is_int($u['bytes']) && $u['bytes'] >= 0, (string) $u['bytes']);
check('and a file count', is_int($u['files']), (string) $u['files']);
printf("  uploads/ holds %s across %d file(s)\n", bytes_human($u['bytes']), $u['files']);
check('bytes_human reads sensibly',
      bytes_human(500) === '500 B' && str_ends_with(bytes_human(900000), 'KB')
      && str_ends_with(bytes_human(5_000_000), 'MB'),
      bytes_human(500) . ' / ' . bytes_human(900000) . ' / ' . bytes_human(5_000_000));

exec_sql('DELETE FROM product_images WHERE product_id = ?', [$pid]);
exec_sql('DELETE FROM products WHERE id = ?', [$pid]);
@unlink($tmp);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
