<?php
/*
 * What happens when the database server is not in Asia/Kolkata.
 *
 * bootstrap.php pins PHP to Asia/Kolkata. Nothing pins MySQL, so NOW() is
 * whatever the HOST decided -- and this laptop happens to be Asia/Calcutta,
 * which is why the two agree here and would not on a server set to UTC, the
 * usual default for shared hosting.
 *
 * Forcing the session to UTC below is exactly what such a host looks like.
 */
declare(strict_types=1);
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';

function show(string $label): void
{
    $now_sql = q1('SELECT NOW() n')['n'];
    $now_php = date('Y-m-d H:i:s');

    // A deadline the code sets IN SQL, exactly as listing_sales.php does.
    $deadline = q1('SELECT DATE_ADD(NOW(), INTERVAL 48 HOUR) d')['d'];

    // ...and the test the code then runs IN PHP, as admin/marketplace.php and
    // listing_sales.php both do: strtotime() on that string, against time().
    $hours_left = (strtotime((string) $deadline) - time()) / 3600;

    // A game time stored by PHP (games.php writes date('Y-m-d H:i:s', ...)),
    // then filtered in SQL by the board query in inc/community.php.
    $game = date('Y-m-d H:i:s', strtotime('today 09:00'));
    $on_board = q1(
        'SELECT (? >= (NOW() - INTERVAL 6 HOUR)) ok',
        [$game]
    )['ok'];

    printf("%s\n", $label);
    printf("  MySQL NOW()                 %s\n", $now_sql);
    printf("  PHP   date()                %s\n", $now_php);
    printf("  a 48h deadline set in SQL   %s\n", $deadline);
    printf("  ...measured back in PHP     %.1f hours left  (should be 48.0)\n", $hours_left);
    printf("  a 09:00 game still on board %s\n", $on_board ? 'yes' : 'no');
    echo "\n";
}

show('--- as it is on this laptop (host happens to be Asia/Calcutta) ---');

db()->exec("SET time_zone = '+00:00'");
show("--- same code, database server on UTC (ordinary shared hosting) ---");
