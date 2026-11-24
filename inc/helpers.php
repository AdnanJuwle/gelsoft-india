<?php
declare(strict_types=1);

/** Read a config value by dot path: cfg('db.host'), cfg('marketplace.fee_pct'). */
function cfg(string $path, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        // config.local.php wins when it is there, and it only ever is on a
        // development machine. The point is that a laptop then has NO
        // config.php at all -- so copying inc/ up to the server cannot
        // overwrite the live credentials with localhost ones, which is a
        // mistake that is otherwise very easy to make and very confusing to
        // diagnose: every page still renders, and only the forms break.
        $file = is_file(__DIR__ . '/config.local.php')
            ? __DIR__ . '/config.local.php'
            : __DIR__ . '/config.php';
        $config = is_file($file) ? require $file : [];
    }
    $node = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

function is_local(): bool
{
    return cfg('env', 'production') === 'local';
}

/**
 * Escape for HTML. Everything a person typed goes through this on the way out —
 * product names, listing descriptions, their own address.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Paise to a displayed rupee amount, grouped the Indian way: last three digits,
 * then pairs. 1249900 becomes "12,499" and 123456789 becomes "12,34,567".
 */
function money(int $paise, bool $symbol = true): string
{
    $digits = (string) intdiv(abs($paise), 100);

    if (strlen($digits) > 3) {
        $last = substr($digits, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($digits, 0, -3));
        $digits = $rest . ',' . $last;
    }

    $out = ($paise < 0 ? '-' : '') . $digits;

    // The literal character, not the &#8377; entity. An entity only survives
    // being printed raw: the moment a figure goes through e() -- as an offer
    // in a conversation did -- the & is escaped and the seller reads
    // "offered &#8377;7,500". It also makes money() safe in a plain-text
    // email or a JSON payload, and matches what site.js already emits.
    // Every page is <meta charset="utf-8"> and mail.php sends UTF-8.
    return $symbol ? "\u{20B9}" . $out : $out;
}

/** A site URL. Works whether you pass the leading slash or not. */
function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

/**
 * An asset URL carrying the file's modification time, so a changed stylesheet
 * reaches people who already have the old one cached.
 */
/**
 * A site-absolute https:// URL for a path the site stores relative.
 *
 * Both the Open Graph tags and the structured data have to name pictures and
 * pages absolutely -- a crawler or a chat app resolves them from its own
 * origin, not ours -- and they must agree, so there is one function rather
 * than the same three lines twice.
 */
function abs_url(string $path): string
{
    if (preg_match('~^https?://~', $path)) {
        return $path;
    }
    return rtrim((string) cfg('site_url', 'https://gelsoft.in'), '/') . '/' . ltrim($path, '/');
}

/**
 * A price as schema.org wants it: a plain decimal, no symbol, no grouping.
 *
 * money() is for people and puts a rupee sign and Indian digit grouping on
 * it, which is exactly what a parser must not be given.
 */
/**
 * Is this database page actually what its URL serves?
 *
 * A row in `pages` is only reachable if nothing else answers at /<slug>.
 * The rewrite serves <slug>.php whenever that file exists, so a row whose
 * slug collides with a real page is shadowed: editable, saveable, and
 * invisible. 'policy' is exactly that -- policy.php is the live page and the
 * row beside it is a second copy of the refund policy that nobody reads.
 *
 * Decided by looking for the file rather than by keeping a list, because a
 * list is the thing that goes stale: the next hand-built page to take a slug
 * off this table would shadow it the same way and nobody would edit the list.
 */
function page_shadowed(string $slug): bool
{
    // Never let a slug out of the database reach the filesystem as a path.
    if (!preg_match('~^[a-z0-9][a-z0-9-]*$~', $slug)) {
        return false;
    }
    return is_file(dirname(__DIR__) . '/' . $slug . '.php');
}

/**
 * A link somebody typed, if it is safe to put in an href. Null otherwise.
 *
 * FILTER_VALIDATE_URL answers "is this a URL?", which is not the question.
 * It accepts `javascript://comment%0Aalert(1)` -- the `//` satisfies its idea
 * of an authority and the `%0A` comments out the rest in JavaScript -- and it
 * accepts file: and ftp: too. So the scheme is checked separately, and only
 * the two that belong on a web page are allowed.
 *
 * Used both when saving and when rendering. The second one matters: a row
 * written before this existed, or edited straight in the database, must not
 * be able to produce the href either.
 */
function safe_external_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
    return ($scheme === 'http' || $scheme === 'https') ? $url : null;
}

function schema_price(int $paise): string
{
    return number_format($paise / 100, 2, '.', '');
}

/**
 * One <script type="application/ld+json"> block.
 *
 * The HEX flags are the point: they escape <, >, &, ' and " so that no
 * product name and no seller-written description can close the script
 * element early. The CSP here is `script-src 'self'` with no
 * 'unsafe-inline', and a ld+json block is data rather than code, so browsers
 * parse it without executing anything -- verified in the console rather than
 * assumed.
 */
function jsonld(array $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($json === false) {
        return '';
    }
    return '<script type="application/ld+json">' . $json . '</script>';
}

/**
 * A schema.org BreadcrumbList from the trail a page already draws.
 *
 * $trail is [label => path]; the last entry is the page itself.
 */
function breadcrumb_schema(array $trail): array
{
    $items = [];
    $i = 0;
    foreach ($trail as $label => $path) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => ++$i,
            'name'     => $label,
            'item'     => abs_url($path),
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/' . $path;
    // The WHOLE mtime, not the last six digits of it.
    //
    // Those six digits roll over every 10^6 seconds -- 11.6 days -- so two
    // uploads of the same file that happen to land at the same point in that
    // cycle produce the same ?v=. The headers say
    // `max-age=31536000, immutable`, which is exactly the promise that a URL
    // this stale can never be re-fetched: a visitor would hold the old
    // stylesheet for a year with nothing to dislodge it.
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return '/' . $path . '?v=' . $version;
}

/**
 * A site photo's URL, or null while it has not been uploaded yet.
 *
 * The photo slots on the homepage and the safety page call this, so a photo
 * goes live by uploading assets/img/<name> and by nothing else -- no template
 * to edit on a live server. Delete the file and the placeholder comes back.
 *
 * basename() so a slot can only ever name a file in assets/img/. The URL is
 * asset()'s, versioned by mtime, so replacing a photo with a better one shows
 * the new one at once instead of after a year of cache.
 */
function site_photo(string $name): ?string
{
    $path = 'assets/img/' . basename($name);
    return is_file(dirname(__DIR__) . '/' . $path) ? asset($path) : null;
}

function redirect(string $path, int $status = 302): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, $status);
    exit;
}

/** True when $name is the page being viewed, for nav highlighting. */
function is_nav(string $name): bool
{
    return ($GLOBALS['page']['nav'] ?? '') === $name;
}

function nav_current(string $name): string
{
    return is_nav($name) ? ' aria-current="page"' : '';
}
