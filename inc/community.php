<?php
/**
 * Sourcing requests and the games board.
 *
 * Both are "somebody tells us something and other people see it", so both go
 * through review before they are public — a board anyone can post to straight
 * away is a board full of spam within a week.
 */
declare(strict_types=1);

const REGIONS = [
    'north'      => 'North',
    'south'      => 'South',
    'east'       => 'East',
    'west'       => 'West',
    'north-east' => 'North-east',
    'central'    => 'Central',
];

/**
 * An email address or an Indian mobile number, normalised.
 * Returns null if it is neither.
 */
function normalise_contact(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || mb_strlen($raw) > 190) {
        return null;
    }
    if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        return mb_strtolower($raw);
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 10 && $digits[0] >= '6') {
        return '+91' . $digits;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return '+' . $digits;
    }
    return null;
}

/* --------------------------------------------------------- requests ----- */

function request_status_label(string $s): string
{
    return [
        'new'      => 'Just in',
        'quoting'  => 'Being priced',
        'quoted'   => 'Quoted',
        'ordered'  => 'On order',
        'declined' => 'We cannot get it',
        'closed'   => 'Closed',
    ][$s] ?? $s;
}

/** The public board: open requests, with how many people are behind each. */
function request_board(int $limit = 30): array
{
    if (!db_ready()) {
        return [];
    }
    return q(
        "SELECT r.id, r.model, r.brand, r.details, r.status, r.created_at,
                r.budget_min_paise, r.budget_max_paise, r.wanted_by,
                (SELECT COUNT(*) FROM request_backers b WHERE b.request_id = r.id) AS backers
         FROM sourcing_requests r
         WHERE r.public = 1 AND r.status IN ('new','quoting','quoted','ordered')
         ORDER BY backers DESC, r.created_at DESC
         LIMIT " . max(1, min(100, $limit))
    );
}

/**
 * Add somebody to a request. Returns an error string, or null.
 *
 * The unique key does the work: asking twice is not an error, it just does
 * not count twice.
 */
function request_back(int $request_id, string $contact, ?int $user_id): ?string
{
    if (!db_ready()) {
        return 'We could not save that just now.';
    }

    $contact = normalise_contact($contact) ?? '';
    if ($contact === '') {
        return 'Leave an email address or a phone number so we can tell you.';
    }

    $req = q1("SELECT id FROM sourcing_requests WHERE id = ? AND public = 1
               AND status IN ('new','quoting','quoted','ordered')", [$request_id]);
    if (!$req) {
        return 'That request is closed.';
    }

    try {
        exec_sql('INSERT INTO request_backers (request_id, user_id, contact) VALUES (?,?,?)',
            [$request_id, $user_id, $contact]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return null;   // already behind it; nothing to do and nothing wrong
        }
        error_log('request_back failed: ' . $e->getMessage());
        return 'We could not save that just now.';
    }

    return null;
}

/* ------------------------------------------------------------ games ----- */

function game_status_label(string $s): string
{
    return [
        'pending'   => 'Waiting for review',
        'live'      => 'On the board',
        'rejected'  => 'Sent back',
        'past'      => 'Finished',
        'cancelled' => 'Cancelled',
    ][$s] ?? $s;
}

/** Games that have not happened yet, optionally in one region. */
function games_upcoming(?string $region = null, int $limit = 40): array
{
    if (!db_ready()) {
        return [];
    }

    $sql = "SELECT * FROM games
            WHERE status = 'live' AND starts_at >= (NOW() - INTERVAL 6 HOUR)";
    $params = [];

    if ($region !== null && isset(REGIONS[$region])) {
        $sql .= ' AND region = ?';
        $params[] = $region;
    }

    return q($sql . ' ORDER BY starts_at LIMIT ' . max(1, min(100, $limit)), $params);
}

/** How many upcoming games each region has, for the filter row. */
function games_by_region(): array
{
    if (!db_ready()) {
        return [];
    }
    $rows = q("SELECT region, COUNT(*) n FROM games
               WHERE status = 'live' AND starts_at >= (NOW() - INTERVAL 6 HOUR)
                 AND region IS NOT NULL GROUP BY region");
    return array_column($rows, 'n', 'region');
}

/**
 * The FPS cap, said the way a player needs it.
 *
 * This is the one number somebody travelling to a game actually has to know,
 * which is why it is a column and not a line buried in the description.
 */
function fps_cap_label(?int $cap): string
{
    if (!$cap) {
        return 'No cap given — ask the host';
    }
    return $cap . ' FPS cap' . ($cap <= 230 ? ' (indoor-friendly)' : '');
}

/**
 * Delete a game outright.
 *
 * `rejected` and `cancelled` already keep a game off the board while leaving
 * the record, which is what you want for a real game that fell through -- the
 * host is told why and can ask. Deleting is for spam and duplicates, where
 * there is nothing worth keeping and nobody to tell.
 */
function game_delete(int $id): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }

    $game = q1('SELECT id, title, city, banner FROM games WHERE id = ?', [$id]);
    if (!$game) {
        return 'That game is already gone.';
    }

    try {
        exec_sql('DELETE FROM games WHERE id = ?', [$id]);
        // After the row, not before: a file with no row is litter nobody will
        // ever find, but a row whose file is already gone just shows no banner.
        if ($game['banner']) {
            upload_delete((string) $game['banner']);
        }
    } catch (Throwable $e) {
        error_log('game delete failed: ' . $e->getMessage());
        return 'That could not be deleted. The reason is in the server error log.';
    }

    admin_log('game_delete', (string) $id, $game['title'] . ' / ' . $game['city']);
    return null;
}

/**
 * Take a game off the board without destroying it.
 *
 * The host is told, which is the difference that matters: a game that simply
 * vanishes is how you lose the person who organised it. Deleting is for spam;
 * this is for a real game that fell through.
 */
function game_cancel(int $id): ?string
{
    if (!db_ready()) {
        return 'The database is not reachable just now.';
    }
    $n = exec_sql("UPDATE games SET status = 'cancelled' WHERE id = ? AND status IN ('live','pending')", [$id]);
    if ($n === 0) {
        return 'That game is not on the board.';
    }
    admin_log('game_cancel', (string) $id);
    return null;
}

/** Games currently on the board, soonest first. */
function games_live(): array
{
    if (!db_ready()) {
        return [];
    }
    return q("SELECT * FROM games WHERE status = 'live' ORDER BY starts_at");
}
