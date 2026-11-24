<?php
/**
 * Create or promote an admin account. Run it once from a shell:
 *
 *   php tools/make-admin.php you@gelsoft.in "Your Name"
 *
 * It asks for the password rather than taking it as an argument, because an
 * argument lands in your shell history and in the process list.
 *
 * It refuses to run over the web: this file is not reachable from public_html
 * on a normal deploy, and the check below is what makes that true even if it
 * somehow is.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/inc/helpers.php';
require dirname(__DIR__) . '/inc/db.php';

$email = $argv[1] ?? '';
$name  = $argv[2] ?? '';

if ($email === '') {
    fwrite(STDERR, "Usage: php tools/make-admin.php <email> [name]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not an email address.\n");
    exit(1);
}

try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "No database: " . $e->getMessage() . "\n");
    exit(1);
}

$email = mb_strtolower(trim($email));
$existing = q1('SELECT id, name, role FROM users WHERE email = ?', [$email]);

// Read the password without echoing it, where the terminal allows that.
function ask_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $hidden = stripos(PHP_OS_FAMILY, 'Windows') === false && @shell_exec('stty -g 2>/dev/null');
    if ($hidden) {
        $before = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        shell_exec('stty ' . $before);
        fwrite(STDOUT, "\n");
    }
    return $value;
}

$password = ask_password('Password (at least 10 characters): ');
$again    = ask_password('Again: ');

if ($password !== $again) {
    fwrite(STDERR, "Those did not match.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Too short. Use at least 10 characters.\n");
    exit(1);
}

// PASSWORD_DEFAULT follows PHP's own recommendation as it changes; the cost is
// raised a little because this hash guards the whole shop.
$hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);

// OWNER, not admin. This is the rescue hatch: you reach for it when nobody
// can get into the panel, and what you need back is the level that can hand
// out access again -- an admin cannot undo its own lockout.
if ($existing) {
    exec_sql('UPDATE users SET password_hash = ?, role = ?, failed_logins = 0, locked_until = NULL WHERE id = ?',
        [$hash, 'owner', $existing['id']]);
    fwrite(STDOUT, "Updated {$email} — password reset, role is owner.\n");
} else {
    exec_sql('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
        [$name !== '' ? $name : 'Owner', $email, $hash, 'owner']);
    fwrite(STDOUT, "Created {$email} as the owner.\n");
}

fwrite(STDOUT, "Sign in at /admin/login\n");
