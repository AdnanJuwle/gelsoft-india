<?php
declare(strict_types=1);

/**
 * One token per session, checked on every write. Without it another site can
 * make a signed-in person's browser post to ours without them knowing.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token = null): bool
{
    $token ??= (string) ($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $token !== '' && hash_equals(csrf_token(), $token);
}

/** Call at the top of anything that writes. Stops the request if the token is wrong. */
function csrf_require(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}
