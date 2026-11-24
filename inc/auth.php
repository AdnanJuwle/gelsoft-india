<?php
/**
 * Sign-in, sessions and the two guards every protected page calls.
 *
 * Passwords are stored with password_hash() and nothing else. Sessions carry
 * an id, never a role — a role in the session is a role an attacker can edit
 * if they ever get at the session store, and re-reading it costs one query.
 */
declare(strict_types=1);

/** How many failures before an account, or an address, is made to wait. */
const AUTH_MAX_FAILS   = 5;
const AUTH_LOCK_MIN    = 15;
const AUTH_IP_WINDOW_M = 15;
const AUTH_IP_MAX      = 20;

/*
 * What to say when the accounts database cannot be reached.
 *
 * Shared by the four pages that ask somebody for a password, so an outage
 * reads the same whichever one they are on. The second half of the sentence
 * is the point: without it, a person is left believing their own password is
 * wrong, and the next thing they do is try to reset it -- which, in the same
 * outage, tells them to check an email that is never sent.
 */
const AUTH_DB_DOWN = 'We cannot reach the accounts database just now. '
    . 'Nothing is wrong with what you typed — give it a minute and try again.';

// Sign-ups from one address. The same 20 as AUTH_IP_MAX above, and loose on
// purpose: see auth_signup_blocked().
const AUTH_SIGNUP_WINDOW_M = 60;
const AUTH_SIGNUP_MAX      = 20;

// How long an attempt is kept. The throttles need an hour; a week is what
// makes "was someone guessing on Tuesday night?" answerable.
const AUTH_ATTEMPT_KEEP_D = 7;

function client_ip(): string
{
    // REMOTE_ADDR only. X-Forwarded-For is sent by the client and can say
    // anything, so trusting it hands anyone an unlimited supply of addresses.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function ip_binary(): ?string
{
    $packed = @inet_pton(client_ip());
    return $packed === false ? null : $packed;
}

/** The signed-in user, re-read each request, or null. */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id <= 0 || !db_ready()) {
        return $user = null;
    }

    $user = q1('SELECT id, name, email, role, seller_verified_at FROM users WHERE id = ? LIMIT 1', [$id]);
    if (!$user) {
        // The account was deleted while the session lived on.
        session_destroy_fully();
    }
    return $user;
}

/**
 * The two roles that can open the panel.
 *
 * `owner` is `admin` plus one thing: changing who has access. Everywhere else
 * in the site an owner IS an admin, which is why nothing compares `role` to
 * 'admin' directly any more — a comparison like that quietly locks the owner
 * out of the very screens they are meant to run.
 */
const ROLE_STAFF = ['admin', 'owner'];

/** Does this user row reach the admin panel at all? */
function user_is_staff(?array $user): bool
{
    return $user !== null && in_array((string) ($user['role'] ?? ''), ROLE_STAFF, true);
}

/** Is this user row the owner? */
function user_is_owner(?array $user): bool
{
    return $user !== null && (string) ($user['role'] ?? '') === 'owner';
}

function is_admin(): bool
{
    return user_is_staff(current_user());
}

function is_owner(): bool
{
    return user_is_owner(current_user());
}

/**
 * Stop here unless the person signed in is the owner.
 *
 * Used by the screens that hand out access. An admin reading them is fine;
 * an admin changing them is the thing this prevents.
 */
function require_owner(): array
{
    $user = require_admin();
    if (!user_is_owner($user)) {
        http_response_code(403);
        exit('Only the owner can change who has access.');
    }
    return $user;
}

function require_login(string $return = ''): array
{
    $user = current_user();
    if ($user === null) {
        $return = $return !== '' ? $return : (string) ($_SERVER['REQUEST_URI'] ?? '/');
        redirect('/login?next=' . urlencode(safe_path($return)));
    }
    return $user;
}

function require_admin(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/admin/login?next=' . urlencode(safe_path((string) ($_SERVER['REQUEST_URI'] ?? '/admin/'))));
    }
    if (!user_is_staff($user)) {
        // Not "no such page" — they are signed in, just not as an admin. A
        // 403 is honest and does not tempt anyone into hunting for the URL.
        http_response_code(403);
        exit('Not yours to open.');
    }
    return $user;
}

/**
 * Only ever redirect to a path on this site, never to wherever ?next says.
 *
 * The host is dropped rather than compared, so an absolute URL to another site
 * cannot survive this. Anything with .. in it, or characters a real path on
 * this site never has, becomes the root instead.
 */
function safe_path(string $url): string
{
    $path  = parse_url($url, PHP_URL_PATH) ?: '/';
    $query = parse_url($url, PHP_URL_QUERY);

    // Collapse a leading run of slashes: "//evil.example" is a URL, not a path.
    $path = '/' . ltrim($path, '/');

    if (str_contains($path, '..') || !preg_match('~^/[A-Za-z0-9/_.\-]*$~', $path)) {
        return '/';
    }
    if ($query !== null && !preg_match('~^[A-Za-z0-9=&%_.\-\[\]+]*$~', $query)) {
        $query = null;
    }

    return $query ? $path . '?' . $query : $path;
}

/**
 * Check an email and password. Returns the user, or null — deliberately
 * without saying which half was wrong, because "no such account" tells
 * somebody which addresses are worth attacking.
 */
function auth_attempt(string $email, string $password): ?array
{
    if (!db_ready()) {
        return null;
    }

    $email = mb_strtolower(trim($email));
    $user = q1('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);

    $ok = false;
    if ($user && (empty($user['locked_until']) || strtotime((string) $user['locked_until']) < time())) {
        $ok = password_verify($password, (string) $user['password_hash']);
    } elseif (!$user) {
        // Hash something anyway, so a missing account does not answer faster
        // than a wrong password and give the difference away.
        password_verify($password, '$2y$12$usesomesillystringfosomethingusesomesillystringfosome');
    }

    auth_log_attempt($email, $ok);

    if (!$ok) {
        if ($user) {
            $fails = (int) $user['failed_logins'] + 1;
            $lock = $fails >= AUTH_MAX_FAILS
                ? date('Y-m-d H:i:s', time() + AUTH_LOCK_MIN * 60)
                : null;
            exec_sql('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?',
                [$fails, $lock, $user['id']]);
        }
        return null;
    }

    exec_sql('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?',
        [$user['id']]);

    // A new id on sign-in, so a session id someone else already knows (from a
    // shared machine, a link, a sniffed request) is not the one that is now
    // signed in.
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['ua']  = auth_fingerprint();

    unset($user['password_hash']);
    return $user;
}

function auth_log_attempt(string $identifier, bool $ok): void
{
    try {
        exec_sql('INSERT INTO login_attempts (ip, identifier, ok) VALUES (?, ?, ?)',
            [ip_binary(), mb_substr($identifier, 0, 190), $ok ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('login_attempts insert failed: ' . $e->getMessage());
    }

    auth_prune_attempts();
}

/**
 * Drop attempts nobody will read again.
 *
 * Opportunistic, like reset_prune() in inc/resets.php: pruned where the rows
 * are made rather than by a cron this host may not have. Unlike that one it
 * fires on roughly one write in fifty -- sign-ins are frequent, and a DELETE
 * that removes nothing on every single one would cost more than the rows.
 *
 * Failure is swallowed on purpose. Housekeeping must never be the reason
 * somebody cannot sign in.
 */
function auth_prune_attempts(): void
{
    try {
        if (random_int(1, 50) !== 1) {
            return;
        }
        exec_sql(
            'DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL ? DAY)',
            [AUTH_ATTEMPT_KEEP_D]
        );
    } catch (Throwable $e) {
        error_log('login_attempts prune failed: ' . $e->getMessage());
    }
}

/** True when this address has failed too often lately. */
function auth_ip_blocked(): bool
{
    if (!db_ready()) {
        return false;
    }
    $row = q1(
        'SELECT COUNT(*) n FROM login_attempts
         WHERE ip = ? AND ok = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)',
        [ip_binary(), AUTH_IP_WINDOW_M]
    );
    return (int) ($row['n'] ?? 0) >= AUTH_IP_MAX;
}

/**
 * Remember that an account was opened from here.
 *
 * Recorded as a success, with a prefix on the identifier, so it sits in
 * login_attempts beside the sign-ins without being mistaken for one: a failed
 * sign-in is what auth_ip_blocked() counts, and a sign-up is neither failed
 * nor a sign-in.
 */
function auth_log_signup(string $email): void
{
    auth_log_attempt('signup:' . $email, true);
}

/**
 * True when this address has opened too many accounts lately.
 *
 * Twenty an hour. One connection can be a whole building behind carrier-grade
 * NAT, which is ordinary in India, so this is set where a genuine household,
 * office or club signing up together will never reach it and a script will
 * within seconds. It started at five and the end-to-end suites -- which open
 * a handful of accounts from one address in two minutes -- walked straight
 * into it, which is as close to a real burst as a test gets.
 *
 * The count includes accounts that have since been deleted: the row here
 * outlives the user, so create-delete-create is not a way around it. That is
 * the second reason the number is generous rather than tight.
 *
 * Returns false when the database is unreachable or the address could not be
 * parsed -- the same choice auth_ip_blocked() makes. A throttle that cannot
 * read its own records must not lock the door on everybody.
 */
function auth_signup_blocked(): bool
{
    if (!db_ready()) {
        return false;
    }
    $ip = ip_binary();
    if ($ip === null) {
        return false;
    }
    $row = q1(
        "SELECT COUNT(*) n FROM login_attempts
          WHERE ip = ? AND ok = 1 AND identifier LIKE 'signup:%'
            AND created_at > (NOW() - INTERVAL ? MINUTE)",
        [$ip, AUTH_SIGNUP_WINDOW_M]
    );
    return (int) ($row['n'] ?? 0) >= AUTH_SIGNUP_MAX;
}

/**
 * A weak tie between the session and the browser that made it. Not security on
 * its own — a stolen cookie usually comes with the user agent — but it costs
 * nothing and catches the lazier replays.
 */
function auth_fingerprint(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function auth_session_valid(): bool
{
    if (empty($_SESSION['uid'])) {
        return true;
    }
    return hash_equals((string) ($_SESSION['ua'] ?? ''), auth_fingerprint());
}

function session_destroy_fully(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** Record an admin action. Who changed the price is the first thing you want. */
function admin_log(string $action, ?string $target = null, ?string $detail = null): void
{
    try {
        exec_sql('INSERT INTO admin_log (user_id, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)', [
            current_user()['id'] ?? null,
            mb_substr($action, 0, 60),
            $target !== null ? mb_substr($target, 0, 120) : null,
            $detail !== null ? mb_substr($detail, 0, 500) : null,
            ip_binary(),
        ]);
    } catch (Throwable $e) {
        error_log('admin_log failed: ' . $e->getMessage());
    }
}

/**
 * Password rules. Length does far more than a mix of symbols, so this asks for
 * a long one and refuses the handful everybody tries first.
 */
function password_problem(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Use at least 10 characters. Length matters more than symbols.';
    }
    if (strlen($password) > 200) {
        return 'That is longer than 200 characters.';
    }
    $common = ['password', '1234567890', 'qwertyuiop', 'gelsoftindia', 'adminadmin'];
    foreach ($common as $bad) {
        if (stripos($password, $bad) !== false) {
            return 'That contains something people guess first. Pick something else.';
        }
    }
    return null;
}
