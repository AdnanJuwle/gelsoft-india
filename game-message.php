<?php
/**
 * POST /game/message — open (or reopen) the conversation with a game's host
 * and go straight to it.
 *
 * The same shape as /listing/message, and for the same reason: a board where
 * anyone can message anonymously is a board full of spam. It also means a
 * player can ask about the cap or the parking without either of them handing
 * out a phone number.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/messages.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/games');
}

$game_id = (int) ($_POST['game_id'] ?? 0);

// require_login() before csrf_require(), so somebody who is simply signed out
// is sent to sign in rather than told their session expired.
$me = require_login('/games');
csrf_require();

if ($game_id <= 0) {
    redirect('/games');
}

$thread = thread_open_game($game_id, (int) $me['id']);

if (!$thread) {
    // The game is gone, is not on the board, has no account behind it, or is
    // your own. None of those is worth a different page.
    redirect('/games');
}

redirect('/messages?thread=' . (int) $thread['id']);
