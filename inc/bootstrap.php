<?php
/**
 * Required first thing by every page. Loads config and helpers, starts the
 * session, and decides whether errors are shown or logged.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

if (!is_file(__DIR__ . '/config.php') && !is_file(__DIR__ . '/config.local.php')) {
    http_response_code(500);
    exit('Not configured yet: copy inc/config.example.php to inc/config.php.');
}

error_reporting(E_ALL);
ini_set('display_errors', is_local() ? '1' : '0');
ini_set('log_errors', '1');

/*
 * When something breaks on the live site.
 *
 * display_errors is off in production, correctly: a visitor must never see a
 * file path or a database message. But that left the OWNER with a blank
 * browser "500 Internal Server Error" and the real cause in a PHP error log
 * on the host that nobody opens -- which is how "every Save on the order page
 * crashes the site" arrived with nothing to go on.
 *
 * So: always logged; a visitor gets a plain apology; a signed-in member of
 * staff on an /admin/ page gets the actual message and the file and line it
 * came from (file name only, no full path), to read out or screenshot. The
 * staff check is itself wrapped -- if the database is what failed, finding
 * out who is signed in can fail too, and then it falls back to the apology.
 */
function crash_page(string $what): void
{
    $show = is_local();
    if (!$show) {
        try {
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $show = str_starts_with($path, '/admin/') && user_is_staff(current_user());
        } catch (Throwable) {
            $show = false;
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Something went wrong</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:40em;margin:3em auto;padding:0 16px;line-height:1.6;color:#15171A">'
       . '<h1 style="font-size:20px">Something went wrong</h1>';
    if ($show) {
        echo '<p>The error, shown because you are signed in as staff:</p>'
           . '<pre style="white-space:pre-wrap;word-break:break-word;background:#F4F2ED;padding:12px;border-radius:8px;font-size:13px">'
           . htmlspecialchars($what, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
           . '<p>Screenshot this for whoever looks after the site. <a href="javascript:history.back()">Go back</a></p>';
    } else {
        echo '<p>That did not work. Please try again in a minute.</p><p><a href="/">Back to the shop</a></p>';
    }
    echo '</div>';
}

set_exception_handler(static function (Throwable $e): void {
    $what = get_class($e) . ': ' . $e->getMessage() . "\n" . basename($e->getFile()) . ' line ' . $e->getLine();
    error_log('Uncaught ' . str_replace("\n", ' at ', $what));
    crash_page($what);
});

// The few errors that are not exceptions -- running out of memory, mostly.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        crash_page($err['message'] . "\n" . basename((string) $err['file']) . ' line ' . $err['line']);
    }
});

date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');

if (PHP_SESSION_NONE === session_status()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // https only, except on a local http dev server.
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('gsid');
    session_start();

    // A session made in another browser is not this one.
    if (!auth_session_valid()) {
        session_destroy_fully();
        session_start();
    }
}

// PHP announces its exact version by default, which is a free hint about
// which published bugs to try. expose_php=Off does the same on the server.
header_remove('X-Powered-By');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

/**
 * Scripts and styles come from here and the two hosts the fonts live on, and
 * nowhere else. If an injection ever does land a <script> on a page, this is
 * what stops it loading a payload.
 *
 * $extra widens it for one page — the checkout needs Razorpay's script and
 * its iframe, and only the checkout should get them.
 */
function send_csp(array $extra = []): void
{
    $policy = [
        'default-src'     => ["'self'"],
        'script-src'      => ["'self'"],
        'style-src'       => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
        'font-src'        => ["'self'", 'https://fonts.gstatic.com'],
        'img-src'         => ["'self'", 'data:'],
        'connect-src'     => ["'self'"],
        'frame-src'       => ["'none'"],
        'form-action'     => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'base-uri'        => ["'self'"],
        'object-src'      => ["'none'"],
    ];

    foreach ($extra as $directive => $sources) {
        $policy[$directive] = $policy[$directive] === ["'none'"]
            ? $sources
            : array_values(array_unique([...$policy[$directive], ...$sources]));
    }

    $out = [];
    foreach ($policy as $directive => $sources) {
        $out[] = $directive . ' ' . implode(' ', $sources);
    }

    header('Content-Security-Policy: ' . implode('; ', $out));
}

/**
 * The checkout, and only the checkout. Razorpay's widget loads its script
 * from their CDN, opens an iframe, and calls their API from the page.
 */
function csp_allow_payments(): void
{
    send_csp([
        'script-src'  => ['https://checkout.razorpay.com'],
        'frame-src'   => ['https://api.razorpay.com', 'https://checkout.razorpay.com'],
        'connect-src' => ['https://api.razorpay.com', 'https://lumberjack.razorpay.com'],
        'img-src'     => ['https://cdn.razorpay.com'],
    ]);
}

send_csp();

if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
