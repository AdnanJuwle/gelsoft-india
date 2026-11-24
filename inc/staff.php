<?php
/**
 * Who is an admin.
 *
 * Admin is the highest privilege in the system — it reads every conversation,
 * changes prices, and releases held marketplace money. So the rules here are
 * about the two ways that goes wrong:
 *
 *   - granting it too easily. A borrowed or stolen session is enough to click
 *     a button, so every change here asks for the signed-in owner's own
 *     password again. A session alone cannot mint a second permanent account.
 *   - losing it entirely. Nothing may remove the last owner, and nobody may
 *     remove themselves, because either leaves the panel unreachable from the
 *     browser and the only way back is the shell or phpMyAdmin.
 *
 * Only the OWNER can change any of it. An admin who is compromised can do
 * real damage to the shop, but cannot lock the owner out, cannot remove the
 * other admins, and cannot quietly add one of their own. That is the whole
 * reason the two roles are separate.
 *
 * Note what is NOT here: creating an account. The person registers themselves
 * at /register and chooses their own password, and you promote the account
 * afterwards. That way a password for an admin account is never typed by
 * somebody else, never sent over WhatsApp, and never known to two people.
 */
declare(strict_types=1);

/** Everyone who can reach the panel, oldest first. */
function staff_list(): array
{
    if (!db_ready()) {
        return [];
    }
    return q("SELECT id, name, email, role, created_at, last_login_at
              FROM users WHERE role IN ('admin','owner')
              ORDER BY role = 'owner' DESC, created_at");
}

/** How many accounts reach the panel at all, of either role. */
function staff_count(): int
{
    if (!db_ready()) {
        return 0;
    }
    return (int) (q1("SELECT COUNT(*) n FROM users WHERE role IN ('admin','owner')")['n'] ?? 0);
}

/**
 * Check the signed-in admin's own password.
 *
 * Re-authentication, not authorisation: they are already signed in. This asks
 * whether the person at the keyboard is the account holder, which a cookie
 * cannot answer.
 */
function staff_confirm_password(int $admin_id, string $password): bool
{
    if ($password === '' || !db_ready()) {
        return false;
    }
    $row = q1('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$admin_id]);
    if (!$row) {
        return false;
    }
    return password_verify($password, (string) $row['password_hash']);
}

/** How many owners exist. Nothing is allowed to take this to zero. */
function owner_count(): int
{
    if (!db_ready()) {
        return 0;
    }
    return (int) (q1("SELECT COUNT(*) n FROM users WHERE role = 'owner'")['n'] ?? 0);
}

/**
 * Give an existing account access. $role is 'admin' or 'owner'.
 *
 * It will not create an account, and says so plainly rather than silently
 * doing nothing -- "no such account" here is not an enumeration risk, because
 * only an owner can get this far.
 */
function staff_promote(string $email, string $role = 'admin'): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }
    if (!in_array($role, ROLE_STAFF, true)) {
        return 'That is not a role.';
    }

    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'That does not look like an email address.';
    }

    $user = q1('SELECT id, name, role FROM users WHERE email = ? LIMIT 1', [$email]);
    if (!$user) {
        return 'No account uses that address. Ask them to register at /register first, '
             . 'then give the account access -- that way they choose their own password.';
    }
    if ($user['role'] === $role) {
        return $user['name'] . ' already has that access.';
    }

    // Making a second owner is deliberate and worth saying out loud: an owner
    // can remove you.
    try {
        exec_sql('UPDATE users SET role = ? WHERE id = ?', [$role, (int) $user['id']]);
    } catch (Throwable $e) {
        error_log('staff promote failed: ' . $e->getMessage());
        return 'That could not be saved. Try again in a minute.';
    }

    admin_log('staff_' . $role, (string) $user['id'], $email);
    return null;
}

/**
 * Take access away again. Returns an error, or null.
 *
 * The account itself is untouched -- they keep their orders, listings and
 * conversations, and go back to being an ordinary customer.
 */
function staff_demote(int $user_id, int $by): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }

    if ($user_id === $by) {
        return 'You cannot remove your own access. Ask another owner to do it, '
             . 'so you cannot lock yourself out by accident.';
    }

    $user = q1('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1', [$user_id]);
    if (!$user || !user_is_staff($user)) {
        return 'That account does not have access.';
    }

    // The last owner is the one account that can hand access back out. Losing
    // it means the shell or phpMyAdmin is the only way in.
    if (user_is_owner($user) && owner_count() <= 1) {
        return 'That is the only owner. Make somebody else an owner first.';
    }

    try {
        $n = exec_sql("UPDATE users SET role = 'customer'
                       WHERE id = ?
                         AND role IN ('admin','owner')
                         AND (role = 'admin'
                              OR (SELECT n FROM (SELECT COUNT(*) n FROM users WHERE role = 'owner') c) > 1)",
            [$user_id]);
    } catch (Throwable $e) {
        error_log('staff demote failed: ' . $e->getMessage());
        return 'That could not be saved. Try again in a minute.';
    }

    if ($n === 0) {
        return 'That did not take effect -- they may be the last owner now. Reload and look again.';
    }

    admin_log('staff_remove', (string) $user_id, (string) $user['email'] . ' (was ' . $user['role'] . ')');
    return null;
}
