<?php
/**
 * Copy this to inc/config.php ON THE SERVER and fill it in.
 *
 * On a development machine, copy it to inc/config.local.php instead. That
 * name is loaded in preference, and it means a laptop has no config.php at
 * all -- so copying inc/ up to the server cannot overwrite the live database
 * credentials with localhost ones. That mistake is easy to make and nasty to
 * find: every page still renders, and only the forms break.
 *
 * Neither file is ever committed.
 */

return [
    'site_name' => 'Gelsoft India',
    'site_url'  => 'https://gelsoft.in',

    // 'local' prints errors on the page. 'production' hides them and logs instead.
    'env' => 'production',

    'app' => [
        // Signs the ?t= link that lets a guest open their own order without
        // an account. Generate it ONCE and never change it: changing it
        // breaks the link in every receipt already sent.
        //
        //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        //
        // Left empty, the database password is used instead, which works but
        // ties those links to a password you may one day want to rotate.
        'secret' => '',
    ],

    'db' => [
        'host'    => 'localhost',
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Razorpay dashboard > Settings > API Keys. Start in test mode.
    'razorpay' => [
        'key_id'         => '',
        'key_secret'     => '',
        'webhook_secret' => '',
    ],

    // A Hostinger mailbox, used over SMTP rather than mail().
    'mail' => [
        'host'      => 'smtp.hostinger.com',
        'port'      => 465,
        'secure'    => 'ssl',
        'user'      => 'support@gelsoft.in',
        'pass'      => '',
        'from_name' => 'Gelsoft India',
    ],

    // The marketplace cut, in percent and paise. The sell page and its payout
    // preview both read these, so the rate is set here and nowhere else.
    'marketplace' => [
        'fee_pct'      => 8,
        'handling_pct' => 2,
        'flat_paise'   => 300,

        // Paid by the BUYER, when they ask for the blaster to be sent to us
        // and checked before it reaches them. The seller's fee is unchanged
        // either way — this is a service the buyer is buying, not a cut of
        // the sale.
        'inspection_fee_pct' => 8,
    ],

    // Shown on the policy page, the FAQ and in transactional mail. Change it
    // here and it changes everywhere.
    'contact' => [
        'email'     => 'support@gelsoft.in',
        'instagram' => 'gelsoftindia',
        // The shop's WhatsApp, e.g. '+91 98765 43210'. While Razorpay is not
        // set up, the order page offers "Send us your order on WhatsApp" to
        // this number; left empty, that button simply is not shown.
        'whatsapp'  => '',
        'discord'   => '',
    ],

    // While Razorpay is not set up, orders are paid over WhatsApp and marked
    // paid in admin. upi_id is put into the payment message admin sends the
    // customer; left empty, admin types the payment details in by hand.
    'payments' => [
        'upi_id' => '',
    ],
];
