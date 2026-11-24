<?php
/**
 * Buyer-to-seller conversations about a listing.
 *
 * One thread per listing per buyer. Questions and offers are both messages, in
 * one ordered list, so "we agreed a price in DMs" stops being something either
 * side can claim: if it is not in the thread, it did not happen.
 */
declare(strict_types=1);

require_once __DIR__ . '/offers.php';

/** The thread between this buyer and this listing, made if it is not there. */
function thread_open(int $listing_id, int $buyer_id): ?array
{
    if (!db_ready()) {
        return null;
    }

    $listing = q1('SELECT id, seller_id, status FROM listings WHERE id = ?', [$listing_id]);
    if (!$listing || $listing['seller_id'] == $buyer_id) {
        return null;  // nobody messages themself
    }

    /*
     * An existing conversation opens whatever the listing's status is now.
     *
     * A sale in flight moves the listing to `sold`, and that is exactly when
     * the two of them most need to talk to each other -- about the parcel,
     * the tracking, the inspection. Cutting them off at that moment would be
     * worse than the thing this guard is for.
     */
    $thread = q1('SELECT * FROM threads WHERE listing_id = ? AND buyer_id = ?', [$listing_id, $buyer_id]);
    if ($thread) {
        return $thread;
    }

    /*
     * A NEW one needs a listing that is actually on the marketplace.
     *
     * listing-message.php takes the id straight from the form, so without
     * this a signed-in person could start a conversation about any id: a
     * draft that was never submitted, one waiting for review, or one an
     * admin rejected for breaking the rules. The thread then shows that
     * listing's title and asking price, so it leaked unreviewed content as
     * well as bothering the seller about something the site had taken down.
     *
     * thread_open_game() has always refused anything that is not live. This
     * is the same rule; the status was being read here and not used.
     */
    if ($listing['status'] !== 'live') {
        return null;
    }

    exec_sql('INSERT INTO threads (listing_id, buyer_id, seller_id, last_message_at) VALUES (?, ?, ?, NOW())',
        [$listing_id, $buyer_id, $listing['seller_id']]);

    return q1('SELECT * FROM threads WHERE listing_id = ? AND buyer_id = ?', [$listing_id, $buyer_id]);
}

/**
 * The thread between this person and a game's host, made if it is not there.
 *
 * Threads carry either a listing or a game, never both. The columns are still
 * called buyer_id/seller_id because renaming them would mean touching every
 * query on the site; on a game thread read them as asker and host.
 */
function thread_open_game(int $game_id, int $asker_id): ?array
{
    if (!db_ready()) {
        return null;
    }

    $game = q1("SELECT id, host_id, status FROM games WHERE id = ?", [$game_id]);
    if (!$game || !$game['host_id'] || $game['status'] !== 'live') {
        return null;
    }
    if ((int) $game['host_id'] === $asker_id) {
        return null;  // nobody messages themself
    }

    $thread = q1('SELECT * FROM threads WHERE game_id = ? AND buyer_id = ?', [$game_id, $asker_id]);
    if ($thread) {
        return $thread;
    }

    exec_sql('INSERT INTO threads (game_id, buyer_id, seller_id, last_message_at) VALUES (?, ?, ?, NOW())',
        [$game_id, $asker_id, (int) $game['host_id']]);

    return q1('SELECT * FROM threads WHERE game_id = ? AND buyer_id = ?', [$game_id, $asker_id]);
}

/** True when this user is one of the two people in the thread. */
function thread_belongs_to(array $thread, int $user_id): bool
{
    return (int) $thread['buyer_id'] === $user_id || (int) $thread['seller_id'] === $user_id;
}

/**
 * Post a message. Returns an error string, or null on success.
 *
 * Membership is checked here as well as at the page, because an endpoint that
 * trusts the page that called it is an endpoint anyone can call directly.
 */
function message_send(int $thread_id, int $sender_id, string $body, string $kind = 'message', ?int $offer_paise = null): ?string
{
    if (!db_ready()) {
        return 'The message could not be saved just now.';
    }

    $thread = q1('SELECT * FROM threads WHERE id = ?', [$thread_id]);
    if (!$thread || !thread_belongs_to($thread, $sender_id)) {
        return 'That conversation is not yours.';
    }

    $body = trim($body);
    if ($kind === 'message') {
        if ($body === '') {
            return 'Write something first.';
        }
        if (mb_strlen($body) > 4000) {
            return 'That is longer than 4,000 characters.';
        }
    }

    if ($kind === 'offer') {
        // A game is not for sale, so an offer on one is meaningless. Refused
        // here and not only hidden in the UI, because a hidden form is still
        // a form somebody can post.
        if ($thread['game_id'] !== null) {
            return 'You cannot make an offer on a game.';
        }
        if ($offer_paise === null || $offer_paise < 100 || $offer_paise > 50_000_000) {
            return 'That is not a sensible offer.';
        }
        if ((int) $thread['buyer_id'] !== $sender_id) {
            return 'Only the buyer makes an offer.';
        }
    }

    // One message a few seconds, so nobody can paste a wall of them in.
    $recent = q1('SELECT COUNT(*) n FROM messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)',
        [$sender_id]);
    if ((int) ($recent['n'] ?? 0) >= 12) {
        return 'Slow down a moment — too many messages in the last minute.';
    }

    // An offer starts life waiting on the seller. Set here rather than left
    // to the column default, because 'pending' is only meaningful on an offer
    // and every other kind must keep NULL.
    exec_sql('INSERT INTO messages (thread_id, sender_id, kind, body, offer_paise, offer_status)
              VALUES (?, ?, ?, ?, ?, ?)',
        [$thread_id, $sender_id, $kind, $body !== '' ? $body : null, $offer_paise,
         $kind === 'offer' ? 'pending' : null]);

    if ($kind === 'offer') {
        // Only one offer from a buyer can be live in a conversation, or the
        // seller is shown two and accepts whichever the layout put first.
        offer_supersede_previous($thread_id, $sender_id, (int) db()->lastInsertId());
    }

    exec_sql('UPDATE threads SET last_message_at = NOW() WHERE id = ?', [$thread_id]);

    return null;
}

/** Every message in a thread, oldest first. */
function thread_messages(int $thread_id): array
{
    if (!db_ready()) {
        return [];
    }
    return q('SELECT m.*, u.name AS sender_name
              FROM messages m JOIN users u ON u.id = m.sender_id
              WHERE m.thread_id = ? ORDER BY m.created_at, m.id', [$thread_id]);
}

/** The threads this person is in, either side. */
function threads_for_user(int $user_id): array
{
    if (!db_ready()) {
        return [];
    }
    // LEFT joins, deliberately: a game thread has no listing and an INNER
    // join would drop it from the list entirely rather than show it wrong.
    return q(
        "SELECT t.*, l.title AS listing_title, l.slug AS listing_slug, l.price_paise,
                g.title AS game_title, g.city AS game_city, g.starts_at AS game_starts_at,
                CASE WHEN t.game_id IS NOT NULL THEN 'game' ELSE 'listing' END AS subject_kind,
                COALESCE(l.title, g.title) AS subject_title,
                CASE WHEN t.buyer_id = ? THEN s.name ELSE b.name END AS other_name,
                CASE WHEN t.buyer_id = ? THEN 1 ELSE 0 END AS i_am_buyer,
                (SELECT COUNT(*) FROM messages m
                  WHERE m.thread_id = t.id AND m.sender_id <> ? AND m.read_at IS NULL) AS unread,
                (SELECT m.body FROM messages m WHERE m.thread_id = t.id
                  ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_body,
                -- An offer's AMOUNT is the news, and it is not in `body` --
                -- which is often empty, so an offer showed in the inbox as a
                -- name and nothing else, looking like any other message.
                (SELECT m.kind FROM messages m WHERE m.thread_id = t.id
                  ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_kind,
                (SELECT m.offer_paise FROM messages m WHERE m.thread_id = t.id
                  ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_offer_paise
         FROM threads t
         LEFT JOIN listings l ON l.id = t.listing_id
         LEFT JOIN games g ON g.id = t.game_id
         JOIN users b ON b.id = t.buyer_id
         JOIN users s ON s.id = t.seller_id
         WHERE t.buyer_id = ? OR t.seller_id = ?
         ORDER BY t.last_message_at DESC",
        [$user_id, $user_id, $user_id, $user_id, $user_id]
    );
}

function thread_mark_read(int $thread_id, int $user_id): void
{
    if (!db_ready()) {
        return;
    }
    // Never fatal: this runs when somebody opens a thread, and a read
    // receipt is not worth being unable to read your own conversation over.
    try {
        exec_sql('UPDATE messages SET read_at = NOW()
                  WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL',
            [$thread_id, $user_id]);
    } catch (Throwable $e) {
        error_log('thread_mark_read failed for ' . $thread_id . ': ' . $e->getMessage());
    }
}

function unread_count(int $user_id): int
{
    if (!db_ready()) {
        return 0;
    }
    $row = q1('SELECT COUNT(*) n FROM messages m
               JOIN threads t ON t.id = m.thread_id
               WHERE (t.buyer_id = ? OR t.seller_id = ?)
                 AND m.sender_id <> ? AND m.read_at IS NULL', [$user_id, $user_id, $user_id]);
    return (int) ($row['n'] ?? 0);
}

/**
 * The one line that stands for a thread in the inbox: what was said, or what
 * happened when nothing was said. Built from message_line() so the list and
 * the conversation can never describe the same message differently.
 */
function thread_preview(array $t): string
{
    $kind = (string) ($t['last_kind'] ?? 'message');

    if ($kind !== 'message') {
        $line = message_line([
            'kind'        => $kind,
            'offer_paise' => $t['last_offer_paise'] ?? 0,
            'body'        => $t['last_body'] ?? '',
        ]);
        if ($line !== '') {
            return $line;
        }
    }

    return (string) ($t['last_body'] ?? '');
}

/** How a message reads in the thread. */
/**
 * One line of plain text describing what a message DID, for the list view.
 *
 * Returns TEXT, not markup, and the caller escapes it -- both of them do:
 * `<?= e(message_line($m)) ?>`. Worth saying out loud because the 'system'
 * branch hands back $m['body'] verbatim, so a caller that printed this raw
 * would be printing a database field raw. Nothing writes a system message
 * today; that is not a reason to leave the contract unstated.
 */
function message_line(array $m): string
{
    return match ($m['kind']) {
        'offer'          => 'offered ' . money((int) $m['offer_paise'])
                            . offer_line_suffix($m),
        'offer_accepted' => 'accepted the offer',
        'offer_declined' => 'declined the offer',
        'system'         => (string) $m['body'],
        default          => '',
    };
}

/**
 * What became of an offer, appended to the amount.
 *
 * Empty while it is pending: a thread that reads "offered 7,500 - waiting on
 * the seller" is noise to the seller, who is the one being waited on.
 */
function offer_line_suffix(array $m): string
{
    $status = (string) ($m['offer_status'] ?? 'pending');
    if ($status === 'pending' || $status === '') {
        return '';
    }
    return " \u{2014} " . offer_label($status);
}
