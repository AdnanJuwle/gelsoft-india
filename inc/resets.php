<?php
/**
 * Password resets.
 *
 * A reset link is a key to somebody's account, so the rules here are tighter
 * than they look:
 *
 *   - the token is random, long, and stored only as a hash;
 *   - it lasts an hour, not a week;
 *   - it is spent the moment it is used, and every other live token for that
 *     account is spent with it;
 *   - asking for one tells you nothing about whether the account exists.
 */
declare(strict_types=1);

const RESET_TTL_MINUTES   = 60;
const RESET_MAX_PER_HOUR  = 4;

/**
 * Start a reset. Returns the token to put in the email, or null if nothing
 * should be sent.
 *
 * The caller must say the same thing either way — see the comment in
 * forgot.php about why.
 */
function reset_begin(string $email): ?string
{
    if (!db_ready()) {
        return null;
    }

    $email = mb_strtolower(trim($email));
    $user = q1('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
    if (!$user) {
        return null;
    }

    // Asking repeatedly must not let somebody flood an inbox, and must not
    // let them keep a supply of live tokens alive either.
    $recent = q1('SELECT COUNT(*) n FROM password_resets
                  WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
        [(int) $user['id']]);
    if ((int) ($recent['n'] ?? 0) >= RESET_MAX_PER_HOUR) {
        error_log('reset: too many requests for user ' . $user['id']);
        return null;
    }

    // 32 bytes of randomness. Guessing one is not a thing anybody does.
    $token = bin2hex(random_bytes(32));

    try {
        exec_sql(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
            [(int) $user['id'], hash('sha256', $token), RESET_TTL_MINUTES, ip_binary()]
        );
    } catch (Throwable $e) {
        error_log('reset insert failed: ' . $e->getMessage());
        return null;
    }

    return $token;
}

/**
 * The account a token belongs to, or null if it is wrong, spent or expired.
 *
 * Looked up by the hash, so a token read off the database is useless.
 */
function reset_user_for(string $token): ?array
{
    if (!db_ready() || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $row = q1('SELECT r.*, u.email, u.name
               FROM password_resets r JOIN users u ON u.id = r.user_id
               WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
               LIMIT 1',
        [hash('sha256', $token)]);

    return $row ?: null;
}

/**
 * Set the new password and spend the token. Returns an error, or null.
 *
 * Everything here happens in one transaction: a password changed but a token
 * left live is a link that still works, which is the whole thing this is
 * meant to prevent.
 */
function reset_complete(string $token, string $password): ?string
{
    $row = reset_user_for($token);
    if (!$row) {
        return 'That link has expired or has already been used. Ask for a new one.';
    }

    if ($problem = password_problem($password)) {
        return $problem;
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        exec_sql('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]), (int) $row['user_id']]);

        // This one is spent, and so is every other live token for the account:
        // somebody who asked twice should not be left holding a spare key.
        exec_sql('UPDATE password_resets SET used_at = NOW()
                  WHERE user_id = ? AND used_at IS NULL',
            [(int) $row['user_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('reset complete failed: ' . $e->getMessage());
        return 'That could not be saved. Try again in a minute.';
    }

    // Tell them it happened. If it was not them, this is how they find out.
    try {
        send_mail((string) $row['email'], 'Your password was changed',
            "The password on your Gelsoft India account was just changed.\n\n"
            . "If that was you, there is nothing to do.\n\n"
            . "If it was not, reply to this email straight away — somebody has access to\n"
            . "the inbox this was sent to.\n\nGelsoft India\n"
            . rtrim((string) cfg('site_url'), '/') . "\n",
            'password_changed');
    } catch (Throwable $e) {
        error_log('reset notice email failed: ' . $e->getMessage());
    }

    return null;
}

/** Throw away tokens nobody can use any more. Called from the forgot page. */
function reset_prune(): void
{
    if (!db_ready()) {
        return;
    }
    try {
        exec_sql('DELETE FROM password_resets
                  WHERE expires_at < (NOW() - INTERVAL 7 DAY)
                     OR (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY))');
    } catch (Throwable $e) {
        error_log('reset prune failed: ' . $e->getMessage());
    }
}
