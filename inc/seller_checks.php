<?php
/**
 * Seller verification, without collecting anybody's ID.
 *
 * The reasoning, because it will look like a shortcut otherwise:
 *
 * Asking a seller for a government ID is not allowed for Aadhaar (UIDAI's
 * position is that an unlicensed private entity may not collect or keep
 * copies), and for anything else it makes this site the custodian of the most
 * sensitive document a person owns, under the DPDP Act, for no real gain.
 *
 * No gain, because the marketplace already holds the strongest lever there
 * is: the buyer's money sits with us until delivery is confirmed, and with
 * the inspection option the item passes through us as well. A seller who
 * never receives a payout has nothing to take. An ID copy would not improve
 * on that; it would only move risk from the seller to us.
 *
 * So the badge records CHECKS WE DID, never documents we hold:
 *
 *   phone   we reached them on the number on their account.
 *   payout  the name their bank reports on the UPI id matches the account
 *           name they gave. The bank did the KYC; we compare two strings.
 *   sale    a completed sale, confirmed by the buyer. The strongest signal of
 *           the three, and the only one that cannot be faked up front.
 *
 * PHONE and PAYOUT together earn the badge. SALE is extra standing.
 */
declare(strict_types=1);

/** The checks that must be present before a seller counts as verified. */
const SELLER_REQUIRED = ['phone', 'payout'];

/** All three, with what each one means on screen. */
const SELLER_CHECKS = [
    'phone' => [
        'label' => 'Phone reached',
        'how'   => 'Call or WhatsApp the number on their account and get an answer.',
    ],
    'payout' => [
        'label' => 'Payout name matches',
        'how'   => 'Send ₹1 to their UPI id. The app shows the name their bank holds. '
                 . 'It must match the account name they gave.',
    ],
    'sale' => [
        'label' => 'Completed a sale',
        'how'   => 'Ticked once a buyer has confirmed delivery. Not required for the badge.',
    ],
];

/** Which checks this seller has, as kind => row. */
function seller_checks(int $user_id): array
{
    if (!db_ready() || $user_id <= 0) {
        return [];
    }
    $out = [];
    foreach (q('SELECT c.*, u.name AS by_name
                FROM seller_checks c LEFT JOIN users u ON u.id = c.checked_by
                WHERE c.user_id = ?', [$user_id]) as $row) {
        $out[(string) $row['kind']] = $row;
    }
    return $out;
}

/** True when every required check is present. */
function seller_is_verified(int $user_id): bool
{
    $have = seller_checks($user_id);
    foreach (SELLER_REQUIRED as $kind) {
        if (!isset($have[$kind])) {
            return false;
        }
    }
    return true;
}

/** What is still missing, for a message that tells somebody what to do. */
function seller_missing(int $user_id): array
{
    $have = seller_checks($user_id);
    return array_values(array_filter(SELLER_REQUIRED, fn($k) => !isset($have[$k])));
}

/**
 * Record a check, and set or clear the badge to match.
 *
 * `seller_verified_at` stays the single thing the rest of the site reads, so
 * nothing else has to know how the badge is earned.
 */
function seller_check_set(int $user_id, string $kind, int $by, string $note = ''): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }
    if (!isset(SELLER_CHECKS[$kind])) {
        return 'That is not a check.';
    }
    if (!q1('SELECT id FROM users WHERE id = ?', [$user_id])) {
        return 'No such account.';
    }

    try {
        exec_sql('INSERT INTO seller_checks (user_id, kind, checked_by, note)
                  VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE checked_by = VALUES(checked_by),
                                          note = VALUES(note), created_at = NOW()',
            [$user_id, $kind, $by, blank_null(mb_substr(trim($note), 0, 160))]);
    } catch (Throwable $e) {
        error_log('seller check failed: ' . $e->getMessage());
        return 'That could not be saved. Try again in a minute.';
    }

    seller_badge_sync($user_id);
    admin_log('seller_check_' . $kind, (string) $user_id, $note !== '' ? $note : null);
    return null;
}

/** Undo a check. The badge follows. */
function seller_check_clear(int $user_id, string $kind): ?string
{
    if (!db_ready() || !isset(SELLER_CHECKS[$kind])) {
        return 'That is not a check.';
    }
    exec_sql('DELETE FROM seller_checks WHERE user_id = ? AND kind = ?', [$user_id, $kind]);
    seller_badge_sync($user_id);
    admin_log('seller_check_undo_' . $kind, (string) $user_id);
    return null;
}

/**
 * Make `users.seller_verified_at` agree with the checks.
 *
 * Derived, never set by hand: a badge that can drift from its evidence is
 * worth nothing, and the point of the checks is that the badge means
 * something specific.
 */
function seller_badge_sync(int $user_id): void
{
    if (!db_ready()) {
        return;
    }

    /*
     * Deliberately NOT wrapped in a try/catch, unlike the other housekeeping
     * in this codebase.
     *
     * Those are cosmetic and must not break the page they run on. This is the
     * opposite: the badge is derived from the checks, and a badge that can
     * drift from its evidence is worth nothing. If it cannot be written, the
     * admin should see that rather than be told the check was saved while the
     * badge still says something else.
     */
    if (seller_is_verified($user_id)) {
        exec_sql('UPDATE users SET seller_verified_at = COALESCE(seller_verified_at, NOW())
                  WHERE id = ?', [$user_id]);
    } else {
        exec_sql('UPDATE users SET seller_verified_at = NULL WHERE id = ?', [$user_id]);
    }
}

/**
 * Tick the 'sale' check automatically once a sale is released.
 *
 * Called from the release path rather than a cron: the moment money reaches a
 * seller is exactly when "they have completed a sale" becomes true.
 */
function seller_note_completed_sale(int $seller_id): void
{
    if (!db_ready() || $seller_id <= 0) {
        return;
    }
    try {
        exec_sql('INSERT IGNORE INTO seller_checks (user_id, kind, note)
                  VALUES (?, \'sale\', \'a buyer confirmed delivery\')', [$seller_id]);
    } catch (Throwable $e) {
        error_log('seller sale check failed: ' . $e->getMessage());
    }
}

/** Sellers with a listing waiting, and where each one stands. */
function sellers_awaiting_checks(): array
{
    if (!db_ready()) {
        return [];
    }
    return q("SELECT DISTINCT u.id, u.name, u.email, u.phone, u.seller_verified_at,
                     p.upi_id, p.account_name
              FROM users u
              JOIN listings l ON l.seller_id = u.id
              LEFT JOIN payout_details p ON p.user_id = u.id
              WHERE l.status IN ('pending','live')
              ORDER BY u.seller_verified_at IS NOT NULL, u.name");
}
