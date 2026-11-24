<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

// A GET that signs you out can be triggered by any image tag on any site, so
// this takes a POST with a token like every other write.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    if (is_admin()) {
        admin_log('admin_logout', current_user()['email'] ?? null);
    }
    session_destroy_fully();
}

redirect('/admin/login');
