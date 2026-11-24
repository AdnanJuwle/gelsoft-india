<?php
/**
 * Find uploaded files that nothing in the database points at.
 *
 *   php tools/orphan-uploads.php            # list them, delete nothing
 *   php tools/orphan-uploads.php --delete   # remove them
 *
 * Why this exists. Deleting a product or a listing DOES remove its files:
 * both paths call upload_delete() before the row goes, and they are correct.
 * What they cannot cover is everything that leaves a file behind without ever
 * reaching those paths --
 *
 *   an upload that succeeds and whose INSERT then fails,
 *   a row removed straight out of SQL (which is how the end-to-end suites
 *   clean up, and why this folder had seventy-odd test images in it),
 *   a restore of the database from a backup taken before an upload.
 *
 * None of those is a bug anyone can fix once and for all; they are the normal
 * ways a file and a row drift apart. On a shared plan the disk is the thing
 * that runs out, and uploads_usage() already counts the folder rather than the
 * table precisely because an orphan costs the same as a photo in use.
 *
 * It refuses to run over the web, like every other tool here.
 *
 * THE AGE GUARD IS THE IMPORTANT PART. An image is written to disk before the
 * row that references it exists, so for the moment in between, a perfectly
 * good upload looks exactly like an orphan. Anything touched in the last
 * --min-age-hours (default 24) is therefore left alone. Lower it only if you
 * know nobody is uploading.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/uploads.php';   // UPLOAD_DIR, upload_delete()

$argvFlags = array_slice($argv, 1);
$delete    = in_array('--delete', $argvFlags, true);

$minAgeHours = 24.0;
foreach ($argvFlags as $i => $a) {
    if ($a === '--min-age-hours' && isset($argvFlags[$i + 1])) {
        $minAgeHours = max(0.0, (float) $argvFlags[$i + 1]);
    }
}

if (!db_ready()) {
    fwrite(STDERR, "No database. Refusing to decide what is an orphan without one.\n");
    exit(1);
}

/*
 * Every column anywhere that holds an uploads/ path. Listed explicitly rather
 * than discovered, because the cost of missing one is deleting a photo that is
 * on the site -- and a new table with images in it should be a deliberate line
 * here, not something this script silently guesses right or wrong.
 */
$referenced = [];
foreach ([
    ['product_images', 'path'],
    ['listing_images', 'path'],
    ['games', 'banner'],
] as [$table, $column]) {
    foreach (q("SELECT `$column` AS p FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''") as $row) {
        $referenced[basename((string) $row['p'])] = true;
    }
}

$dir   = dirname(__DIR__) . '/' . UPLOAD_DIR;
$now   = time();
$cutoff = $now - (int) round($minAgeHours * 3600);

$orphans = [];
$bytes   = 0;
$young   = 0;

foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..' || str_starts_with($f, '.')) {
        continue;
    }
    $full = $dir . '/' . $f;
    if (!is_file($full)) {
        continue;
    }
    if (isset($referenced[$f])) {
        continue;
    }
    if (filemtime($full) > $cutoff) {
        $young++;          // too new to judge: it may be mid-upload
        continue;
    }
    $orphans[] = $f;
    $bytes += (int) filesize($full);
}

printf("%d referenced file%s in the database\n", count($referenced), count($referenced) === 1 ? '' : 's');
printf("%d orphan%s, %s\n", count($orphans), count($orphans) === 1 ? '' : 's', bytes_human($bytes));
if ($young) {
    printf("%d file%s skipped as newer than %s hours\n", $young, $young === 1 ? '' : 's', rtrim(rtrim(number_format($minAgeHours, 1), '0'), '.'));
}

foreach ($orphans as $f) {
    echo '  ', $f, "\n";
}

if (!$orphans) {
    exit(0);
}

if (!$delete) {
    echo "\nNothing was deleted. Re-run with --delete to remove them.\n";
    exit(0);
}

$gone = 0;
foreach ($orphans as $f) {
    // Through upload_delete(), not unlink(), so the one function that knows
    // what a safe path looks like stays the only one that removes a file.
    upload_delete(UPLOAD_DIR . '/' . $f);
    if (!is_file($dir . '/' . $f)) {
        $gone++;
    }
}
printf("\nDeleted %d of %d.\n", $gone, count($orphans));
