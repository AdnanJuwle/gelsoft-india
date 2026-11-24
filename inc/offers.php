<?php
/**
 * Offers a seller can answer.
 *
 * The shape of this, and why:
 *
 * ACCEPTING DOES NOT RESERVE THE ITEM. The listing stays live and whoever
 * pays first gets it. Reserving sounds kinder and is worse: it means a buyer
 * can freeze somebody else's blaster for two days by typing a number, and it
 * drags in a release-on-expiry job that shared hosting has nowhere to run.
 * So an accepted offer is a PRICE, open to that one buyer, for a while. The
 * seller's screen says so in those words, because a seller who thinks they
 * have reserved it will not list it elsewhere.
 *
 * THE AGREED PRICE IS RESOLVED FROM THE DATABASE, never from a form. There is
 * exactly one function that answers "what does this buyer pay for this
 * listing" -- offer_agreed() -- and both the buy page and sale_create() call
 * it. A price that arrives in a request is a price anyone can edit.
 *
 * IT IS SPENT WHEN IT IS USED. The sale marks the offer 'used' inside the
 * same transaction that creates the order, so one accepted offer cannot buy
 * two items, and a seller who accepts an offer, gets paid, and relists is not
 * still bound to the old number.
 *
 * EXPIRY IS LAZY. Nothing here needs a cron: offer_agreed() tests the clock,
 * and offer_expire_stale() tidies the label when a conversation is read. A
 * row that says 'accepted' with a date in the past is simply not agreed.
 */
declare(strict_types=1);

/**
 * How long an accepted price stands.
 *
 * Two days, the same window a buyer gets to check a delivery. Long enough to
 * arrange money over a weekend, short enough that a seller is not holding a
 * price they named in a different week. It is never extended -- a seller with
 * more patience accepts the next offer.
 */
const OFFER_HOLD_HOURS = 48;

/** How each state reads on screen. */
const OFFER_LABELS = [
    'pending'    => 'waiting on the seller',
    'accepted'   => 'accepted',
    'declined'   => 'declined',
    'superseded' => 'replaced by a later offer',
    'expired'    => 'expired',
    'used'       => 'bought at this price',
];

function offer_label(?string $status): string
{
    return OFFER_LABELS[(string) $status] ?? '';
}

/**
 * Flip accepted offers whose hold has run out.
 *
 * Called when a conversation is read, so the label a person sees matches what
 * offer_agreed() would decide. Correctness does not depend on this running --
 * every read of an agreed price tests the clock itself.
 */
function offer_expire_stale(?int $thread_id = null): void
{
    if (!db_ready()) {
        return;
    }
    $sql = "UPDATE messages SET offer_status = 'expired'
             WHERE kind = 'offer' AND offer_status = 'accepted'
               AND offer_expires_at IS NOT NULL AND offer_expires_at <= NOW()";

    /*
     * Never fatal. This runs when a thread is opened, and by the note above
     * it is cosmetic -- offer_agreed() tests the time itself, so an offer
     * that is still labelled 'accepted' is a stale word on a screen, not a
     * price anybody can hold us to. Losing the whole conversation to tidy up
     * a label is the wrong trade.
     */
    try {
        if ($thread_id !== null) {
            exec_sql($sql . ' AND thread_id = ?', [$thread_id]);
        } else {
            exec_sql($sql);
        }
    } catch (Throwable $e) {
        error_log('offer_expire_stale failed: ' . $e->getMessage());
    }
}

/** The offer this thread is waiting on, if any. */
function offer_latest_pending(int $thread_id): ?array
{
    if (!db_ready()) {
        return null;
    }
    return q1("SELECT * FROM messages
                WHERE thread_id = ? AND kind = 'offer' AND offer_status = 'pending'
                ORDER BY created_at DESC, id DESC LIMIT 1", [$thread_id]) ?: null;
}

/**
 * The price this buyer may pay for this listing right now, or null.
 *
 * The single answer to that question. `offer_expires_at > NOW()` is tested
 * here rather than trusted from the status column, so a missed tidy-up can
 * never let a stale price through.
 */
function offer_agreed(int $listing_id, int $buyer_id): ?array
{
    if (!db_ready() || $listing_id <= 0 || $buyer_id <= 0) {
        return null;
    }
    return q1("SELECT m.* FROM messages m
                 JOIN threads t ON t.id = m.thread_id
                WHERE t.listing_id = ? AND t.buyer_id = ?
                  AND m.kind = 'offer' AND m.offer_status = 'accepted'
                  AND m.offer_expires_at > NOW()
                ORDER BY m.offer_decided_at DESC, m.id DESC LIMIT 1",
        [$listing_id, $buyer_id]) ?: null;
}

/**
 * Accept or decline. Returns null on success, or why not.
 *
 * Every check is here rather than in the page, because a hidden button is
 * still a form somebody can post.
 */
function offer_decide(int $message_id, int $by, string $decision): ?string
{
    if (!db_ready()) {
        return 'That could not be saved just now.';
    }
    if ($decision !== 'accept' && $decision !== 'decline') {
        return 'That is not a decision.';
    }

    $offer = q1("SELECT * FROM messages WHERE id = ? AND kind = 'offer'", [$message_id]);
    if (!$offer) {
        return 'That offer is gone.';
    }

    $thread = q1('SELECT * FROM threads WHERE id = ?', [(int) $offer['thread_id']]);
    if (!$thread) {
        return 'That conversation is gone.';
    }
    // A game is not for sale, so it can carry no offer to answer.
    if ($thread['game_id'] !== null) {
        return 'There is nothing to buy in this conversation.';
    }
    if ((int) $thread['seller_id'] !== $by) {
        return 'Only the seller answers an offer.';
    }

    $status = (string) ($offer['offer_status'] ?? 'pending');
    if ($status !== 'pending') {
        // Two taps, or a stale page. Say which, so it does not read as a bug.
        return 'That offer was already ' . (offer_label($status) ?: $status) . '.';
    }

    if ($decision === 'accept') {
        $listing = q1('SELECT * FROM listings WHERE id = ?', [(int) $thread['listing_id']]);
        if (!$listing || $listing['status'] !== 'live') {
            return 'That listing is not on the marketplace, so there is no price to agree.';
        }
    }

    $new = $decision === 'accept' ? 'accepted' : 'declined';

    try {
        exec_sql('UPDATE messages
                     SET offer_status = ?, offer_decided_by = ?, offer_decided_at = NOW(),
                         offer_expires_at = ' . ($decision === 'accept'
                            ? 'DATE_ADD(NOW(), INTERVAL ' . OFFER_HOLD_HOURS . ' HOUR)'
                            : 'NULL') . '
                   WHERE id = ? AND offer_status = \'pending\'',
            [$new, $by, $message_id]);

        // The thread is where people look for what was agreed, so the
        // decision is a line in it, not only a column.
        exec_sql('INSERT INTO messages (thread_id, sender_id, kind) VALUES (?, ?, ?)',
            [(int) $offer['thread_id'], $by, 'offer_' . $new]);
        exec_sql('UPDATE threads SET last_message_at = NOW() WHERE id = ?',
            [(int) $offer['thread_id']]);
    } catch (Throwable $e) {
        error_log('offer_decide failed: ' . $e->getMessage());
        return 'That could not be saved. Try again in a minute.';
    }

    return null;
}

/**
 * Spend an accepted offer, inside the caller's transaction.
 *
 * Guarded on `offer_status = 'accepted'` in the WHERE clause rather than in
 * PHP, so two sales racing for one offer cannot both claim it: the second
 * UPDATE matches no rows.
 */
function offer_mark_used(int $message_id): void
{
    exec_sql("UPDATE messages SET offer_status = 'used'
               WHERE id = ? AND offer_status = 'accepted'", [$message_id]);
}

/**
 * A newer offer from the same buyer replaces the one before it.
 *
 * Without this a seller could be shown two live offers in one conversation
 * and accept the older, higher-or-lower one by mistake.
 */
function offer_supersede_previous(int $thread_id, int $sender_id, int $keep_id): void
{
    exec_sql("UPDATE messages SET offer_status = 'superseded'
               WHERE thread_id = ? AND sender_id = ? AND kind = 'offer'
                 AND offer_status = 'pending' AND id <> ?",
        [$thread_id, $sender_id, $keep_id]);
}
