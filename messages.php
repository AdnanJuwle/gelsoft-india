<?php
/**
 * /messages — the conversations you are in.
 * /messages?thread=N — one of them.
 *
 * This is the page the account tab pointed at and that nothing could reach,
 * because there was no way to start a conversation from a listing.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/messages.php';

$me = require_login('/messages');

$error = '';
$thread = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $thread_id = (int) ($_POST['thread_id'] ?? 0);

    // Answering an offer is its own action, not a kind of message: it changes
    // what the buyer may pay, so it goes through offer_decide(), which owns
    // every check. The buttons being hidden from a buyer is not one of them.
    if (isset($_POST['decision'])) {
        $error = offer_decide((int) ($_POST['offer_id'] ?? 0), (int) $me['id'],
            (string) $_POST['decision']) ?? '';
        if ($error === '') {
            redirect('/messages?thread=' . $thread_id);
        }
    }

    $kind      = ($_POST['kind'] ?? 'message') === 'offer' ? 'offer' : 'message';

    $offer = null;
    if ($kind === 'offer') {
        $rupees = (int) ($_POST['offer'] ?? 0);
        $offer  = $rupees > 0 ? $rupees * 100 : null;
    }

    if ($error === '') {
        $error = message_send($thread_id, (int) $me['id'], (string) ($_POST['body'] ?? ''), $kind, $offer) ?? '';
    }

    if ($error === '') {
        redirect('/messages?thread=' . $thread_id);
    }
}

$threads = threads_for_user((int) $me['id']);

if (isset($_GET['thread'])) {
    // LEFT joins: a game thread has no listing, and an INNER join would turn
    // it into a 404 rather than a conversation.
    $thread = q1("SELECT t.*, l.title AS listing_title, l.slug AS listing_slug, l.price_paise,
                         g.title AS game_title, g.city AS game_city, g.starts_at AS game_starts_at,
                         CASE WHEN t.game_id IS NOT NULL THEN 'game' ELSE 'listing' END AS subject_kind,
                         b.name AS buyer_name, s.name AS seller_name
                  FROM threads t
                  LEFT JOIN listings l ON l.id = t.listing_id
                  LEFT JOIN games g ON g.id = t.game_id
                  JOIN users b ON b.id = t.buyer_id
                  JOIN users s ON s.id = t.seller_id
                  WHERE t.id = ?", [(int) $_GET['thread']]);

    // Belonging to the thread is checked here, not taken from the list above.
    if (!$thread || !thread_belongs_to($thread, (int) $me['id'])) {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    thread_mark_read((int) $thread['id'], (int) $me['id']);

    // Make the labels agree with the clock before anything is drawn. Not a
    // correctness step -- offer_agreed() tests the time itself -- but a
    // thread that still says "accepted" after the hold ran out is a lie.
    offer_expire_stale((int) $thread['id']);
}

$messages = $thread ? thread_messages((int) $thread['id']) : [];
$i_am_buyer = $thread && (int) $thread['buyer_id'] === (int) $me['id'];

// The seller's side of an offer: one waiting to be answered.
$pending_offer = ($thread && !$i_am_buyer && $thread['subject_kind'] !== 'game')
    ? offer_latest_pending((int) $thread['id'])
    : null;

// The buyer's side: a price they can go and pay. Resolved through the same
// function the buy page and sale_create() use, so all three agree or none do.
$agreed_offer = ($thread && $i_am_buyer && $thread['subject_kind'] !== 'game')
    ? offer_agreed((int) $thread['listing_id'], (int) $me['id'])
    : null;

$page = [
    'title'   => $thread ? 'Conversation' : 'Messages',
    'nav'     => 'marketplace',
    'noindex' => true,
];
require __DIR__ . '/inc/header.php';
?>
  <div class="wrap layout section" style="padding-top:28px">

    <aside class="rail" style="max-width:320px" aria-label="Conversations">
      <h1 class="h-kicker" style="margin-bottom:16px">Messages</h1>
      <?php if (!$threads): ?>
        <div class="card card--pad">
          <p class="small muted">Nothing yet. Open a listing and ask the seller a question &mdash; it starts a conversation here.</p>
          <a class="btn btn--sm btn--block" href="/marketplace" style="margin-top:14px">Browse listings</a>
        </div>
      <?php else: ?>
        <div class="card" style="overflow:hidden">
          <?php foreach ($threads as $t): ?>
            <a class="thread-row<?= $thread && (int) $t['id'] === (int) $thread['id'] ? ' is-current' : '' ?>" href="/messages?thread=<?= (int) $t['id'] ?>">
              <span class="thread-row__top">
                <strong><?= e($t['other_name']) ?></strong>
                <?php if ((int) $t['unread'] > 0): ?><span class="thread-row__dot"><?= (int) $t['unread'] ?></span><?php endif; ?>
              </span>
              <span class="tiny muted"><?= e((string) $t['subject_title']) ?><?= $t['subject_kind'] === 'game' ? ' &middot; game' : '' ?></span>
              <?php $preview = thread_preview($t); ?>
              <?php if ($preview !== ''): ?>
                <span class="tiny muted thread-row__last"><?= e(mb_substr($preview, 0, 60)) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </aside>

    <div class="results">
      <?php if ($error && !$thread): ?>
        <p class="notice notice--paper" role="alert" style="margin-bottom:18px;color:var(--bad)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 16v.1" stroke-linecap="round"/></svg>
          <span class="small"><?= e($error) ?></span>
        </p>
      <?php endif; ?>

      <?php if (!$thread): ?>
        <div class="empty">
          <span class="empty__icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--muted-2)" stroke-width="1.8"><path d="M4 5h16v11H9l-5 4z" stroke-linejoin="round"/></svg>
          </span>
          <h2>Pick a conversation</h2>
          <p>Everything about a sale stays on its listing, where we can read it if the sale goes wrong. That is the whole reason it is not in DMs.</p>
        </div>

      <?php else: ?>
        <div class="card card--pad" style="margin-bottom:18px">
          <div class="row row--between row--center">
            <div style="min-width:0">
              <p class="tiny muted" style="margin-bottom:4px">About</p>
              <?php if ($thread['subject_kind'] === 'game'): ?>
                <a style="font-weight:700" href="/games"><?= e((string) $thread['game_title']) ?></a>
                <p class="small muted" style="margin-top:4px">
                  <?= e((string) $thread['game_city']) ?>,
                  <?= e(date('j M Y', strtotime((string) $thread['game_starts_at']))) ?> &middot;
                  you are the <?= $i_am_buyer ? 'player' : 'host' ?>, talking to <?= e($i_am_buyer ? $thread['seller_name'] : $thread['buyer_name']) ?>
                </p>
              <?php else: ?>
                <a style="font-weight:700" href="/listing?slug=<?= e(urlencode((string) $thread['listing_slug'])) ?>"><?= e($thread['listing_title']) ?></a>
                <p class="small muted" style="margin-top:4px">
                  <?= money((int) $thread['price_paise']) ?> &middot;
                  you are the <?= $i_am_buyer ? 'buyer' : 'seller' ?>, talking to <?= e($i_am_buyer ? $thread['seller_name'] : $thread['buyer_name']) ?>
                </p>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($error): ?>
          <p class="notice notice--paper" role="alert"><span class="small"><?= e($error) ?></span></p>
        <?php endif; ?>

        <div class="thread">
          <?php foreach ($messages as $m): $mine = (int) $m['sender_id'] === (int) $me['id']; ?>
            <?php if ($m['kind'] === 'message'): ?>
              <div class="bubble<?= $mine ? ' bubble--mine' : '' ?>">
                <p class="tiny bubble__who"><?= e($mine ? 'You' : $m['sender_name']) ?> &middot; <?= e(date('j M, H:i', strtotime((string) $m['created_at']))) ?></p>
                <p class="small"><?= nl2br(e((string) $m['body'])) ?></p>
              </div>
            <?php else: ?>
              <p class="thread__event tiny">
                <?= e($mine ? 'You' : $m['sender_name']) ?> <?= e(message_line($m)) ?>
                &middot; <?= e(date('j M, H:i', strtotime((string) $m['created_at']))) ?>
              </p>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if (!$messages): ?>
            <p class="small muted" style="text-align:center;padding:24px 0">No messages yet. Say what you want to know.</p>
          <?php endif; ?>
        </div>

        <?php if ($pending_offer): ?>
          <div class="card card--pad offer-box" style="margin-top:18px">
            <h2 class="h-kicker" style="margin-bottom:10px">An offer to answer</h2>
            <p class="offer-box__amount"><?= money((int) $pending_offer['offer_paise']) ?></p>
            <p class="small muted" style="margin-top:4px">
              Asking <?= money((int) $thread['price_paise']) ?> &middot;
              <?= e(date('j M, H:i', strtotime((string) $pending_offer['created_at']))) ?>
            </p>

            <div class="row row--tight" style="margin-top:16px">
              <form method="post" action="/messages">
                <?= csrf_field() ?>
                <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
                <input type="hidden" name="offer_id" value="<?= (int) $pending_offer['id'] ?>">
                <button class="btn btn--primary btn--sm" type="submit" name="decision" value="accept">
                  Accept <?= money((int) $pending_offer['offer_paise']) ?>
                </button>
              </form>
              <form method="post" action="/messages">
                <?= csrf_field() ?>
                <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
                <input type="hidden" name="offer_id" value="<?= (int) $pending_offer['id'] ?>">
                <button class="btn btn--sm" type="submit" name="decision" value="decline"
                        data-confirm="Decline this offer?">Decline</button>
              </form>
            </div>

            <!-- Said plainly, because a seller who believes the item is held
                 will stop showing it to anyone else. -->
            <p class="tiny muted" style="margin-top:14px;line-height:1.6">
              Accepting opens this price to <strong><?= e($thread['buyer_name']) ?></strong> for
              <?= OFFER_HOLD_HOURS ?> hours. <strong>It does not reserve the blaster</strong> &mdash;
              the listing stays up and whoever pays first gets it. You can still reply here to
              haggle instead.
            </p>
          </div>
        <?php endif; ?>

        <?php if ($agreed_offer): ?>
          <div class="card card--pad offer-box" style="margin-top:18px">
            <h2 class="h-kicker" style="margin-bottom:10px">Your offer was accepted</h2>
            <p class="offer-box__amount"><?= money((int) $agreed_offer['offer_paise']) ?></p>
            <p class="small muted" style="margin-top:4px">
              Instead of <?= money((int) $thread['price_paise']) ?>, until
              <?= e(date('j M, H:i', strtotime((string) $agreed_offer['offer_expires_at']))) ?>
            </p>
            <a class="btn btn--primary btn--sm btn--block" style="margin-top:16px"
               href="/buy?listing=<?= (int) $thread['listing_id'] ?>">
              Buy it at <?= money((int) $agreed_offer['offer_paise']) ?>
            </a>
            <p class="tiny muted" style="margin-top:12px;line-height:1.6">
              The listing is still up at the asking price, so this is not held for you &mdash;
              it is yours when it is paid for.
            </p>
          </div>
        <?php endif; ?>

        <form class="card card--pad" method="post" action="/messages" style="margin-top:18px">
          <?= csrf_field() ?>
          <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
          <input type="hidden" name="kind" value="message">
          <label class="sr-only" for="body">Your message</label>
          <textarea class="textarea" id="body" name="body" rows="3" maxlength="4000" required placeholder="<?= $thread['subject_kind'] === 'game' ? 'Ask about the cap, the ground, parking, whether eye protection is provided&hellip;' : 'Ask about the internals, the wear, what is included&hellip;' ?>"></textarea>
          <div class="row row--tight row--center" style="margin-top:12px">
            <button class="btn btn--primary btn--sm" type="submit">Send</button>
            <span class="tiny muted">Keep it here, not in DMs &mdash; a conversation on the listing is what we can look at if a sale goes wrong.</span>
          </div>
        </form>

        <?php if ($i_am_buyer && $thread['subject_kind'] !== 'game'): ?>
          <form class="card card--pad" method="post" action="/messages" style="margin-top:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
            <input type="hidden" name="kind" value="offer">
            <h2 class="h-kicker" style="margin-bottom:12px">Make an offer</h2>
            <div class="row row--tight row--center">
              <div class="input--money" style="max-width:200px">
                <span>&#8377;</span>
                <label class="sr-only" for="offer">Your offer</label>
                <input id="offer" name="offer" type="number" min="1" max="500000" required
                       placeholder="<?= intdiv((int) $thread['price_paise'], 100) ?>">
              </div>
              <button class="btn btn--sm" type="submit">Send offer</button>
            </div>
            <p class="tiny muted" style="margin-top:10px;line-height:1.6">An offer is recorded in this conversation. Nothing is charged until the seller accepts and you pay &mdash; and an accepted price stands for <?= OFFER_HOLD_HOURS ?> hours without holding the item, so pay promptly if you want it.</p>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php require __DIR__ . '/inc/footer.php';
