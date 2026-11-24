<?php
require __DIR__ . '/inc/bootstrap.php';

// POST only: a GET that signs you out can be fired by an <img> on any site.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    session_destroy_fully();
}

redirect('/');
