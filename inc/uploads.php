<?php
/**
 * Image uploads.
 *
 * The rule that matters: the file type is decided by reading the image, never
 * by its name or by the Content-Type the browser sent — both come from
 * whoever is uploading. A file called cat.jpg that is really PHP is the
 * oldest trick there is, and `uploads/.htaccess` is the second line of
 * defence behind this one.
 */
declare(strict_types=1);

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
const UPLOAD_DIR       = 'uploads';

/**
 * The most pixels we will open at once, whatever the host's memory says.
 *
 * A bitmap costs four bytes a pixel, so 40 megapixels is about 160 MB -- more
 * than a 24 MP camera photo and well past anything a phone produces. It is
 * the backstop for a host with no memory_limit at all; the real guard is
 * image_memory_budget_px(), which asks how much room there actually is.
 */
const UPLOAD_MAX_PIXELS = 40000000;

/** Bytes a truecolor bitmap costs per pixel, in GD. */
const UPLOAD_BYTES_PER_PIXEL = 4;

/**
 * What each kind of image is allowed to cost.
 *
 * Not a disk-space measure — the hosting has 50 GB and these images will
 * never come near it. This is about the PAGE: most of this site is read on a
 * phone on Indian mobile data, where a gallery is the heaviest thing on the
 * screen by a wide margin.
 *
 *   product — our own shots, few of them, the only images anyone looks at
 *             closely. Worth the bytes.
 *   listing — a seller's phone photos. Nobody studies the grain on a
 *             second-hand blaster, and two thirds of the width is about a
 *             third of the weight.
 *
 * `per_item` is the one that bounds a page, and it is a judgement about
 * listings rather than about storage: five photos chosen to show the wear and
 * what is in the box beat fifty from a burst. It also stops one seller
 * turning a listing page into a 15 MB download.
 *
 * Note the 5 MB cap above is not part of this. Every upload is re-encoded, so
 * what the phone sends has almost no bearing on what is kept or served.
 *
 * Measured on a deliberately noisy 4000x3000 test image, which compresses
 * worse than any real photo, so treat these as ceilings:
 *   1600px q82 -> 320 KB      1200px q78 -> 108 KB
 */
const UPLOAD_PROFILES = [
    'product' => ['max' => 1600, 'quality' => 82, 'per_item' => 6],
    'listing' => ['max' => 1200, 'quality' => 78, 'per_item' => 5],

    // A game banner is one wide strip across a card, never looked at closely.
    // `aspect` crops it to 3:1 at upload rather than leaving CSS to hide the
    // rest: a portrait photo scaled to 1200 wide would be 1600 tall and cost
    // four times the bytes to show the same strip.
    'banner'  => ['max' => 1200, 'quality' => 78, 'per_item' => 1, 'aspect' => 3.0],
];

/** One profile, falling back to the product settings for an unknown name. */
function upload_profile(string $name): array
{
    return UPLOAD_PROFILES[$name] ?? UPLOAD_PROFILES['product'];
}

/** Extensions we are willing to write, keyed by what getimagesize() reports. */
const UPLOAD_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * Take one $_FILES entry and store it. Returns ['path' => …] or
 * ['error' => …]. The path is relative to the site root, ready for the src.
 */
function upload_image(array $file, string $prefix = 'img', string $profile = 'product'): array
{
    $rules = upload_profile($profile);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'No file was chosen.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too big.',
            UPLOAD_ERR_PARTIAL => 'The upload was cut off. Try again.',
            default => 'The upload failed.',
        }];
    }
    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        return ['error' => 'Keep images under 5 MB. Most photos shrink a long way at no visible cost.'];
    }
    // Without this, a path in the form could point at any file on the server.
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'That was not an uploaded file.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !isset(UPLOAD_TYPES[$info[2]])) {
        return ['error' => 'That is not a JPEG, PNG or WebP image.'];
    }

    [$width, $height] = $info;
    if ($width < 200 || $height < 200) {
        return ['error' => 'That image is smaller than 200px. It will look soft on a product page.'];
    }

    /*
     * How big is it in MEMORY, not on disk?
     *
     * The file-size cap above says nothing about this: a flat-coloured
     * 25000x25000 PNG is 1.7 MB on disk and 2.4 GB once decoded, and
     * getimagesize() waves it through because it only reads the header.
     *
     * It is not only an attack. A 50 MP phone photo is 191 MB as a bitmap,
     * which is already more than a shared host's 128 MB limit -- so without
     * this, a seller with a recent phone uploading one honest picture gets a
     * fatal error and a white screen.
     */
    $budget = image_memory_budget_px();
    if ($width * $height > $budget) {
        // The LONG side, which is what the sentence below asks them for: the
        // scale factor that brings the pixel count inside the budget, applied
        // to whichever dimension is bigger.
        $shrink = sqrt($budget / ($width * $height));
        $side = (int) floor(max($width, $height) * $shrink);
        return ['error' => sprintf(
            'That photo is %d by %d, which is more than this server can open at once. '
            . 'Scale it to about %dpx on the long side first; it will look identical '
            . 'on the page, because we resize it anyway.',
            $width, $height, max(1200, $side)
        )];
    }

    $dir = dirname(__DIR__) . '/' . UPLOAD_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'The uploads folder is missing and could not be created.'];
    }
    if (!is_writable($dir)) {
        return ['error' => 'The uploads folder is not writable. Set it to 755.'];
    }

    // A random name: the original is chosen by the uploader, and two people
    // uploading "photo.jpg" should not fight over one file.
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($prefix)) ?: 'img';
    $name = trim($slug, '-') . '-' . bin2hex(random_bytes(6));

    $saved = image_resave($file['tmp_name'], $dir, $name, $info[2],
        (int) $rules['max'], (int) $rules['quality'],
        isset($rules['aspect']) ? (float) $rules['aspect'] : null);
    if ($saved === null) {
        return ['error' => 'The image could not be processed.'];
    }

    return ['path' => UPLOAD_DIR . '/' . $saved, 'width' => $width, 'height' => $height];
}

/**
 * How many pixels this server can afford to decode right now.
 *
 * Asked rather than assumed: the answer on a 128 MB shared host and on a
 * 512 MB one are different, and a constant would be wrong on one of them. GD
 * holds the source bitmap and, while resizing, a second one, so the budget is
 * deliberately a fraction of what is free rather than all of it.
 *
 * memory_limit of -1 means no limit, and then the only guard is the absolute
 * ceiling.
 */
function image_memory_budget_px(): int
{
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') {
        return UPLOAD_MAX_PIXELS;
    }

    $n = (int) $limit;
    $bytes = match (strtolower(substr($limit, -1))) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };

    $free = $bytes - memory_get_usage(true);
    // Half of what is free: GD needs the source and the destination at once,
    // and the rest of the request still has to run.
    $px = (int) (($free * 0.5) / UPLOAD_BYTES_PER_PIXEL);

    return max(1000000, min(UPLOAD_MAX_PIXELS, $px));
}

/**
 * Re-encode the image rather than copying the bytes across.
 *
 * This is a safety step as much as a size one: decoding to a bitmap and
 * writing a fresh file drops anything that was riding along in the original —
 * EXIF, comment blocks, appended archives, injected script — because none of
 * it survives the round trip. It also caps the dimensions, so a 6000px phone
 * photo does not get served to someone on mobile data.
 */
function image_resave(string $tmp, string $dir, string $name, int $type,
                      int $max = 1600, int $quality = 82, ?float $aspect = null): ?string
{
    if (!function_exists('imagecreatefromjpeg')) {
        /*
         * No GD, so no round trip -- and the round trip IS the safety.
         *
         * This used to move the file across unchanged, on the grounds that
         * "the checks above have already established is a real image". They
         * established that its HEADER is a real image. getimagesize() reads
         * the first few bytes and says nothing about what is appended after
         * the image data, so a valid PNG with PHP on the end passes it. The
         * promise in SECURITY.md -- that EXIF, comment blocks, appended
         * archives and injected script do not survive -- is only true because
         * of the decode, and quietly dropping it was the wrong way to fail.
         */
        error_log('upload refused: the gd extension is not available, so images cannot be re-encoded');
        return null;
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        default        => false,
    };
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Crop to shape first, from the middle, so the scale below works on what
    // will actually be kept.
    if ($aspect !== null && $aspect > 0) {
        $want_h = (int) round($w / $aspect);
        if ($want_h <= $h) {
            $cx = 0;
            $cy = (int) round(($h - $want_h) / 2);
            $cw = $w;
            $ch = $want_h;
        } else {
            $cw = (int) round($h * $aspect);
            $ch = $h;
            $cx = (int) round(($w - $cw) / 2);
            $cy = 0;
        }
        $cut = imagecreatetruecolor(max(1, $cw), max(1, $ch));
        imagecopy($cut, $src, 0, 0, $cx, $cy, $cw, $ch);
        imagedestroy($src);
        $src = $cut;
        $w = imagesx($src);
        $h = imagesy($src);
    }

    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    // WebP for everything: smaller than JPEG at the same quality, and every
    // browser this site cares about has read it for years.
    $out = $name . '.webp';
    $ok = function_exists('imagewebp')
        ? imagewebp($src, $dir . '/' . $out, $quality)
        : false;

    if (!$ok) {
        // JPEG needs roughly 4 more points for the same apparent quality.
        $out = $name . '.jpg';
        $ok = imagejpeg($src, $dir . '/' . $out, min(100, $quality + 4));
    }

    imagedestroy($src);

    if ($ok) {
        @chmod($dir . '/' . $out, 0644);
        return $out;
    }
    return null;
}

/** Remove an uploaded file, given the path stored in the database. */
function upload_delete(string $path): void
{
    // Only ever inside uploads/, and only a plain file name — so a crafted
    // row cannot talk this into deleting something else.
    if (!preg_match('~^uploads/[A-Za-z0-9._-]+$~', $path)) {
        return;
    }
    $full = dirname(__DIR__) . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * How much of the plan the uploads folder is using.
 *
 * Counted by walking the folder rather than summing the database, because the
 * disk is what runs out — an orphaned file nothing points at still costs the
 * same as one in use.
 */
function uploads_usage(): array
{
    $dir = dirname(__DIR__) . '/' . UPLOAD_DIR;
    $bytes = 0;
    $files = 0;

    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || str_starts_with($f, '.')) {
                continue;
            }
            $full = $dir . '/' . $f;
            if (is_file($full)) {
                $bytes += (int) filesize($full);
                $files++;
            }
        }
    }

    return ['bytes' => $bytes, 'files' => $files,
            'average' => $files > 0 ? (int) round($bytes / $files) : 0];
}

/** Bytes as something a person reads: 940 KB, 1.4 MB. */
function bytes_human(int $n): string
{
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1024 * 1024) {
        return number_format($n / 1024, 0) . ' KB';
    }
    return number_format($n / (1024 * 1024), $n < 100 * 1024 * 1024 ? 1 : 0) . ' MB';
}

/**
 * How many more images this item may have. Returns the number allowed, which
 * is 0 when it is already full.
 */
function upload_slots_left(string $table, string $column, int $id, string $profile): int
{
    $cap = (int) upload_profile($profile)['per_item'];
    if (!db_ready() || $id <= 0) {
        return $cap;
    }

    // Literals, never anything from a request.
    $table  = $table === 'listing_images' ? 'listing_images' : 'product_images';
    $column = $column === 'listing_id' ? 'listing_id' : 'product_id';

    $have = (int) (q1("SELECT COUNT(*) n FROM {$table} WHERE {$column} = ?", [$id])['n'] ?? 0);
    return max(0, $cap - $have);
}

/** Turn a name into a URL slug, and keep it unique in the products table. */
function unique_slug(string $text, string $table = 'products', ?int $ignore_id = null): string
{
    $base = strtolower(trim($text));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: '';
    $base = trim($base, '-');
    $base = $base !== '' ? mb_substr($base, 0, 120) : 'item';

    $table = $table === 'listings' ? 'listings' : 'products';

    $slug = $base;
    $n = 2;
    while (true) {
        $row = $ignore_id !== null
            ? q1("SELECT id FROM {$table} WHERE slug = ? AND id <> ?", [$slug, $ignore_id])
            : q1("SELECT id FROM {$table} WHERE slug = ?", [$slug]);
        if (!$row) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}
