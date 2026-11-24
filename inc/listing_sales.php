<?php
/**
 * A marketplace sale, from paid to paid out.
 *
 * The money sits with us for the whole of it, so every move between states is
 * written down here rather than scattered through the pages. Two rules the
 * rest of the file follows:
 *
 *   1. Only `sale_mark_held()` decides a buyer has paid, and only the webhook
 *      calls it — the same rule as the shop.
 *   2. Only `sale_release()` decides the seller gets the money, and it will
 *      not run until the buyer has it or the window has closed.
 */
declare(strict_types=1);

require_once __DIR__ . '/offers.php';

/** How long a buyer has to raise a problem once it is delivered. */
const SALE_CONFIRM_HOURS = 48;

/**
 * How long a started-but-unpaid sale holds the item.
 *
 * A second-hand listing is one object, so while somebody is at the payment
 * screen it is theirs. Long enough for a slow bank page and a one-time
 * password; short enough that an abandoned checkout does not take a
 * one-of-a-kind item off the market for the evening.
 */
const SALE_CHECKOUT_MINUTES = 30;

/** Who may move a sale from one state to the next. */
const SALE_MOVES = [
    // from         => [to => who]
    'held'       => ['to_us' => 'seller', 'shipped' => 'seller', 'cancelled' => 'admin', 'refunded' => 'admin'],
    'to_us'      => ['inspecting' => 'admin', 'cancelled' => 'admin'],
    'inspecting' => ['shipped' => 'admin', 'failed' => 'admin'],
    'failed'     => ['refunded' => 'admin'],
    'shipped'    => ['delivered' => 'buyer', 'disputed' => 'buyer', 'refunded' => 'admin'],
    'delivered'  => ['disputed' => 'buyer', 'released' => 'admin', 'refunded' => 'admin'],
    'disputed'   => ['released' => 'admin', 'refunded' => 'admin'],
];

function sale_reference(): string
{
    $year = date('Y');
    $row = q1('SELECT reference FROM listing_orders WHERE reference LIKE ? ORDER BY id DESC LIMIT 1',
        ['MK-' . $year . '-%']);
    $n = ($row && preg_match('/-(\d+)$/', (string) $row['reference'], $m)) ? (int) $m[1] + 1 : 1;
    return sprintf('MK-%s-%04d', $year, $n);
}

/**
 * Start a sale. Returns ['sale' => row] or ['error' => '…'].
 *
 * Everything about the money is computed here from the listing row, not from
 * the form: the buyer's browser picks only *whether* they want an inspection.
 */
function sale_create(array $listing, int $buyer_id, array $ship, bool $inspected): array
{
    if ((int) $listing['seller_id'] === $buyer_id) {
        return ['error' => 'That is your own listing.'];
    }
    if ($listing['status'] !== 'live') {
        return ['error' => 'That listing is not for sale.'];
    }
    if ($inspected && empty($listing['allow_inspection'])) {
        return ['error' => 'That seller has not offered an inspection on this one.'];
    }

    // What this buyer pays, decided here rather than by the caller. The buy
    // page resolves the same offer for display; this is the one that counts.
    $agreed  = offer_agreed((int) $listing['id'], $buyer_id);
    $price   = $agreed ? (int) $agreed['offer_paise'] : (int) $listing['price_paise'];
    $postage = (int) $listing['postage_paise'];
    $buyer   = buyer_total($price, $postage, $inspected);
    $seller  = seller_payout($price);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Lock the listing: two people hitting Buy at the same moment must
        // not both get a sale on one second-hand item.
        $fresh = q1('SELECT status FROM listings WHERE id = ? FOR UPDATE', [(int) $listing['id']]);
        if (!$fresh || $fresh['status'] !== 'live') {
            $pdo->rollBack();
            return ['error' => 'Somebody just bought that one.'];
        }

        /*
         * Is somebody else already at the payment screen for it?
         *
         * The lock above re-reads a status that nothing in here changes -- a
         * listing does not become 'sold' until the money clears, in
         * sale_mark_held() -- so on its own it let two buyers through and
         * both of them paid. This is the check that makes the lock mean
         * something: the second attempt waits for the first to commit, then
         * sees its pending row.
         *
         * Bounded in time on purpose. Without the window one abandoned
         * checkout would hold a one-of-a-kind item for good.
         */
        $busy = q1(
            "SELECT reference FROM listing_orders
              WHERE listing_id = ? AND buyer_id <> ? AND status = 'pending'
                AND created_at > (NOW() - INTERVAL " . SALE_CHECKOUT_MINUTES . " MINUTE)
              LIMIT 1",
            [(int) $listing['id'], $buyer_id]
        );
        if ($busy) {
            $pdo->rollBack();
            return ['error' => 'Somebody is paying for that one right now. '
                             . 'Give it a few minutes; if they do not finish, it comes back.'];
        }

        if ($agreed) {
            // Spend the offer under the lock. The WHERE clause is the guard:
            // two sales racing for one accepted offer means the second UPDATE
            // matches nothing, and we would rather refuse than quietly charge
            // this buyer the asking price after showing them the agreed one.
            $still = q1("SELECT offer_status FROM messages
                          WHERE id = ? AND offer_expires_at > NOW() FOR UPDATE",
                [(int) $agreed['id']]);
            if (!$still || $still['offer_status'] !== 'accepted') {
                $pdo->rollBack();
                return ['error' => 'That agreed price has just run out. Open the listing again for the current one.'];
            }
            offer_mark_used((int) $agreed['id']);
        }

        $reference = sale_reference();

        exec_sql(
            'INSERT INTO listing_orders
               (reference, listing_id, buyer_id, seller_id,
                price_paise, postage_paise, inspection_paise, total_paise,
                seller_fee_paise, handling_paise, payout_paise, inspected,
                status, ship_name, ship_phone, ship_line1, ship_line2,
                ship_city, ship_state, ship_pin)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $reference, (int) $listing['id'], $buyer_id, (int) $listing['seller_id'],
                $price, $postage, $buyer['inspection'], $buyer['total'],
                $seller['fee'], $seller['handling'], $seller['payout'], $inspected ? 1 : 0,
                'pending', $ship['name'], $ship['phone'], $ship['line1'], $ship['line2'] ?: null,
                $ship['city'], $ship['state'], $ship['pin'],
            ]
        );

        $id = (int) $pdo->lastInsertId();
        $pdo->commit();

        sale_log($id, null, null, 'pending', 'created');

        return ['sale' => q1('SELECT * FROM listing_orders WHERE id = ?', [$id])];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('sale_create failed: ' . $e->getMessage());
        return ['error' => 'The sale could not be started. Nothing has been charged.'];
    }
}

/**
 * The buyer has paid. Called from the webhook and nowhere else.
 *
 * Idempotent: a webhook delivered twice must not take the listing off sale
 * twice or send two sets of email.
 */
function sale_mark_held(int $sale_id, string $payment_id): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $sale = q1('SELECT * FROM listing_orders WHERE id = ? FOR UPDATE', [$sale_id]);
        if (!$sale || $sale['status'] !== 'pending') {
            $pdo->commit();
            return false;
        }

        /*
         * Has another sale already taken this item?
         *
         * The reservation in sale_create() makes this rare, not impossible:
         * the window expires, a webhook is slow, somebody pays late. The
         * money is real either way -- Razorpay has taken it and this is the
         * event saying so -- so it is recorded honestly and then flagged,
         * because deciding which buyer gets the blaster and which gets a
         * refund is a person's job, not a database's.
         */
        $taken = q1(
            "SELECT reference FROM listing_orders
              WHERE listing_id = ? AND id <> ?
                AND status NOT IN ('pending', 'cancelled', 'refunded')
              LIMIT 1",
            [(int) $sale['listing_id'], $sale_id]
        );

        // `AND status = 'pending'` for the same reason sale_move() and
        // sale_release() pin theirs: a write must not assume the row still
        // looks the way it did when it was read. The lock above makes that
        // true already; this makes it true from the statement itself, which
        // is one less thing depending on the isolation level behaving as
        // expected. Razorpay delivers the same event twice.
        $took = exec_sql(
            "UPDATE listing_orders SET status = 'held', payment_id = ?, paid_at = NOW()
              WHERE id = ? AND status = 'pending'",
            [$payment_id, $sale_id]
        );

        if ($took === 0) {
            // Somebody else confirmed it between the read and here. Nothing
            // downstream may run: no second listing sold, no second email.
            $pdo->rollBack();
            return false;
        }

        if ($taken) {
            exec_sql(
                "UPDATE listing_orders SET admin_note = ? WHERE id = ?",
                [mb_substr('Paid for an item already sold on ' . $taken['reference']
                    . '. One of these two needs refunding.', 0, 500), $sale_id]
            );
        }

        // Off the market. A second-hand item is one item. `AND status <>
        // 'sold'` so a late second payment cannot rewrite the moment the
        // first buyer took it.
        exec_sql("UPDATE listings SET status = 'sold', sold_at = NOW()
                   WHERE id = ? AND status <> 'sold'",
            [(int) $sale['listing_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('sale_mark_held failed for ' . $sale_id . ': ' . $e->getMessage());
        return false;
    }

    sale_log($sale_id, null, 'pending', 'held',
        'payment ' . $payment_id . ($taken ? '; ' . $taken['reference'] . ' already took this item' : ''));

    try {
        sale_notify($sale_id, 'held');
    } catch (Throwable $e) {
        error_log('sale email failed: ' . $e->getMessage());
    }

    return true;
}

/**
 * Move a sale on. Returns an error string, or null.
 *
 * The table at the top of this file is the authority on who may do what, so a
 * page cannot invent a transition by posting a different status.
 */
function sale_move(int $sale_id, string $to, array $actor, string $note = ''): ?string
{
    $sale = q1('SELECT * FROM listing_orders WHERE id = ?', [$sale_id]);
    if (!$sale) {
        return 'No such sale.';
    }

    $from = (string) $sale['status'];
    $allowed = SALE_MOVES[$from][$to] ?? null;
    if ($allowed === null) {
        return 'A sale cannot go from ' . sale_status_label($from) . ' to ' . sale_status_label($to) . '.';
    }

    $is_admin  = user_is_staff($actor);
    $is_buyer  = (int) $sale['buyer_id'] === (int) $actor['id'];
    $is_seller = (int) $sale['seller_id'] === (int) $actor['id'];

    $may = match ($allowed) {
        'admin'  => $is_admin,
        'buyer'  => $is_buyer || $is_admin,
        'seller' => $is_seller || $is_admin,
        default  => false,
    };
    if (!$may) {
        return 'That is not yours to do.';
    }

    // An inspection has to actually happen before the item can be sent on.
    if ($to === 'to_us' && !$sale['inspected']) {
        return 'This sale has no inspection on it.';
    }
    if ($to === 'shipped' && $sale['inspected'] && $from === 'held') {
        return 'This one goes to us first. Mark it as sent to us.';
    }

    $extra = '';
    $params = [];

    if ($to === 'shipped') {
        $extra = ', shipped_at = NOW()';
    }
    if ($to === 'delivered') {
        // The clock the release depends on starts here.
        $extra = ', confirm_by = DATE_ADD(NOW(), INTERVAL ' . SALE_CONFIRM_HOURS . ' HOUR)';
    }
    if ($to === 'disputed' && $note !== '') {
        $extra = ', dispute_note = ?';
        $params[] = mb_substr($note, 0, 1000);
    }
    if (($to === 'inspecting' || $to === 'failed') && $note !== '') {
        $extra = ', inspection_note = ?';
        $params[] = mb_substr($note, 0, 1000);
    }

    // A failed inspection or a refund puts the listing back on the shelf, so
    // the seller is not left with a sold item they still have.
    try {
        /*
         * `AND status = ?` is what makes this safe against a second actor,
         * and rowCount() is what makes us NOTICE. Without the count, a write
         * that changed nothing still logged the move and emailed about it --
         * an audit trail saying a thing happened that did not.
         */
        $changed = exec_sql("UPDATE listing_orders SET status = ?{$extra} WHERE id = ? AND status = ?",
            [$to, ...$params, $sale_id, $from]);

        if ($changed === 0) {
            return 'That sale moved while you were looking at it. Open it again.';
        }

        if (in_array($to, ['failed', 'refunded', 'cancelled'], true)) {
            exec_sql("UPDATE listings SET status = 'live', sold_at = NULL WHERE id = ? AND status = 'sold'",
                [(int) $sale['listing_id']]);
        }
    } catch (Throwable $e) {
        error_log('sale_move failed: ' . $e->getMessage());
        return 'That could not be saved.';
    }

    sale_log($sale_id, (int) $actor['id'], $from, $to, $note);

    try {
        sale_notify($sale_id, $to);
    } catch (Throwable $e) {
        error_log('sale email failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Pay the seller.
 *
 * Deliberately separate from `sale_move`, and deliberately refuses to run
 * early: releasing money the buyer has not confirmed, inside a window we
 * promised them, is the one mistake this whole feature exists to prevent.
 *
 * The transfer itself is still made by hand — see BACKEND.md. This records
 * that it was made.
 */
function sale_release(int $sale_id, array $actor, bool $force = false): ?string
{
    if (!user_is_staff($actor)) {
        return 'That is not yours to do.';
    }

    /*
     * Read and write in one transaction, with the row locked.
     *
     * This used to read the sale, decide on what it read, and then write with
     * `WHERE id = ? AND status <> 'released'` -- which does not pin the status
     * it checked. A buyer raising a dispute between the two had it overwritten
     * and the seller was paid for an item the buyer had just said arrived
     * broken; the "settle it first" guard never fired, because it was
     * evaluated against a stale read.
     *
     * FOR UPDATE is what fixes it: the read cannot be stale, because nothing
     * else can change the row between it and the write.
     *
     * Pinning the UPDATE to the status that was read is a backstop, and worth
     * being accurate about -- measured against a real concurrent dispute,
     * removing the pin changes nothing at all while the lock is there, and
     * removing the lock breaks it. The pin is here for the day somebody calls
     * this inside an outer transaction, or at an isolation level where the
     * lock does not mean what it means now. Do not mistake it for the thing
     * doing the work, and do not delete the lock because the pin looks like
     * it has it covered.
     */
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }

    $fail = static function (?string $message) use ($pdo, $own): ?string {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $message;
    };

    $sale = q1('SELECT * FROM listing_orders WHERE id = ? FOR UPDATE', [$sale_id]);
    if (!$sale) {
        return $fail('No such sale.');
    }
    if ($sale['status'] === 'released') {
        return $fail('That one is already paid out.');
    }
    if (!in_array($sale['status'], ['delivered', 'disputed'], true)) {
        return $fail('Nothing is released until the buyer has it. This one is: ' . sale_status_label((string) $sale['status']));
    }
    if ($sale['status'] === 'disputed' && !$force) {
        return $fail('That sale is disputed. Settle it first, or release it deliberately.');
    }
    if ($sale['status'] === 'delivered' && !$force
        && $sale['confirm_by'] && strtotime((string) $sale['confirm_by']) > time()) {
        return $fail('The buyer still has until '
            . date('j M, H:i', strtotime((string) $sale['confirm_by']))
            . ' to raise a problem.');
    }

    $was = (string) $sale['status'];

    $changed = exec_sql(
        "UPDATE listing_orders SET status = 'released', released_at = NOW()
          WHERE id = ? AND status = ?",
        [$sale_id, $was]
    );

    if ($changed === 0) {
        // Nothing downstream may run: no audit row saying a payout happened,
        // no "your money is on the way", no completed-sale tick.
        return $fail('That sale moved while you were looking at it. Open it again.');
    }

    if ($own) {
        $pdo->commit();
    }

    sale_log($sale_id, (int) $actor['id'], $was, 'released',
        $force ? 'released early' : '');

    // The moment money actually reaches a seller is the moment "they have
    // completed a sale" becomes true, so the check is ticked here rather than
    // by anybody remembering to.
    if (function_exists('seller_note_completed_sale')) {
        seller_note_completed_sale((int) $sale['seller_id']);
    }

    try {
        sale_notify($sale_id, 'released');
    } catch (Throwable $e) {
        error_log('sale email failed: ' . $e->getMessage());
    }

    return null;
}

function sale_log(int $sale_id, ?int $actor_id, ?string $was, string $became, string $note = ''): void
{
    try {
        exec_sql('INSERT INTO listing_order_events (order_id, actor_id, was, became, note) VALUES (?,?,?,?,?)',
            [$sale_id, $actor_id, $was, $became, $note !== '' ? mb_substr($note, 0, 500) : null]);
    } catch (Throwable $e) {
        error_log('sale_log failed: ' . $e->getMessage());
    }
}

function sale_events(int $sale_id): array
{
    return q('SELECT e.*, u.name AS actor_name
              FROM listing_order_events e LEFT JOIN users u ON u.id = e.actor_id
              WHERE e.order_id = ? ORDER BY e.created_at, e.id', [$sale_id]);
}

function sale_by_payment_order(string $payment_order_id): ?array
{
    return db_ready()
        ? q1('SELECT * FROM listing_orders WHERE payment_order_id = ?', [$payment_order_id])
        : null;
}

function sale_by_reference(string $reference): ?array
{
    return db_ready()
        ? q1('SELECT * FROM listing_orders WHERE reference = ?', [$reference])
        : null;
}

function sale_viewable(array $sale, ?array $user): bool
{
    if (!$user) {
        return false;
    }
    return user_is_staff($user)
        || (int) $sale['buyer_id'] === (int) $user['id']
        || (int) $sale['seller_id'] === (int) $user['id'];
}

/** Plain-text mail at each step. Quiet, and only to the person it concerns. */
function sale_notify(int $sale_id, string $state): void
{
    $sale = q1('SELECT lo.*, l.title, b.email AS buyer_email, b.name AS buyer_name,
                       s.email AS seller_email, s.name AS seller_name
                FROM listing_orders lo
                JOIN listings l ON l.id = lo.listing_id
                JOIN users b ON b.id = lo.buyer_id
                JOIN users s ON s.id = lo.seller_id
                WHERE lo.id = ?', [$sale_id]);
    if (!$sale) {
        return;
    }

    $site = rtrim((string) cfg('site_url'), '/');
    $link = $site . '/sale?ref=' . urlencode((string) $sale['reference']);
    $what = $sale['title'];
    $ref  = $sale['reference'];

    [$to, $subject, $body] = match ($state) {
        'held' => [
            $sale['seller_email'],
            'Sold: ' . $what,
            "{$sale['buyer_name']} bought {$what}.\n\n"
            . "We are holding " . money((int) $sale['total_paise'], false) . " until they confirm it arrived.\n"
            . "You will receive " . money((int) $sale['payout_paise'], false) . " after that.\n\n"
            . ($sale['inspected']
                ? "They asked for an inspection, so this one comes to US first, not to them.\nThe address is on the sale page.\n\n"
                : "Post it within three days and add the tracking number here:\n")
            . "{$link}\n",
        ],
        'shipped' => [
            $sale['buyer_email'],
            'On its way: ' . $what,
            "{$what} is on its way to you.\n\n"
            . ($sale['tracking'] ? "{$sale['courier']} — {$sale['tracking']}\n\n" : '')
            . "Film the unboxing, uncut. You have " . SALE_CONFIRM_HOURS . " hours from delivery to raise a\n"
            . "problem, and that video is what we can act on.\n\n{$link}\n",
        ],
        'failed' => [
            $sale['buyer_email'],
            'Refunding: ' . $what,
            "We checked {$what} and it is not what the listing described.\n\n"
            . ($sale['inspection_note'] ? "What we found:\n{$sale['inspection_note']}\n\n" : '')
            . "The sale is cancelled and you are being refunded in full — the inspection fee too.\n\n{$link}\n",
        ],
        'released' => [
            $sale['seller_email'],
            'Paid out: ' . $ref,
            "The money for {$what} has been released.\n\n"
            . money((int) $sale['payout_paise'], false) . " is on its way to your UPI.\n\n{$link}\n",
        ],
        'refunded' => [
            $sale['buyer_email'],
            'Refunded: ' . $ref,
            "Your money for {$what} is on its way back.\n\n{$link}\n",
        ],
        default => [null, '', ''],
    };

    if ($to) {
        send_mail((string) $to, $subject, $body . "\nGelsoft India\n{$site}\n", 'sale_' . $state);
    }
}
