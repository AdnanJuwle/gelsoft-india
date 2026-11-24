<?php
/**
 * POST /listing/message — open (or reopen) the conversation about a listing
 * and go straight to it.
 *
 * Signing in is required, which is the point: a marketplace where anyone can
 * message anonymously is a marketplace full of spam.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/messages.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/marketplace');
}

$listing_id = (int) ($_POST['listing_id'] ?? 0);

// require_login() before csrf_require(), so somebody who is simply signed out
// is sent to sign in rather than told their session expired.
$me = require_login('/listing?id=' . $listing_id);
csrf_require();

if ($listing_id <= 0) {
    redirect('/marketplace');
}

$thread = thread_open($listing_id, (int) $me['id']);

if (!$thread) {
    // Either the listing is gone, or it is your own.
    redirect('/marketplace');
}

redirect('/messages?thread=' . (int) $thread['id']);
