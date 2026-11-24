<?php
/**
 * How long a typed-in field may be, and what to say when it is longer.
 *
 * Why this file exists. The widths live in db/schema*.sql, and until now every
 * page that writes to those columns carried its own copy of the numbers --
 * written by hand, four different ways:
 *
 *   sell.php       a per-field limit for each one.          Right.
 *   account.php    a per-field limit, applied with mb_substr and no message.
 *                  The row saves; the person is never told their delivery
 *                  address was cut, and the courier gets half of it.
 *   request.php    ONE limit, 500, for every field -- including `model`,
 *   games.php      which is VARCHAR(160). Anything between 161 and 500
 *                  characters passes the clamp and is then refused by the
 *                  database, and the page says "try again in a minute",
 *                  which will never be true.
 *   checkout.php   nothing at all. A long address line reaches the INSERT,
 *   buy.php        the transaction rolls back, and the person is told "The
 *                  order could not be created" with no indication of which
 *                  field to shorten. Verified: six fields do this.
 *
 * sql_mode is STRICT_TRANS_TABLES, so the database REFUSES an over-long value
 * rather than cutting it. That is the right behaviour and it is why none of
 * the above loses data. What it cannot do is tell anybody which field to fix.
 *
 * So: one table of widths, one function that produces a message naming the
 * field, and a test that asserts this table still equals information_schema.
 * The numbers cannot drift from the schema without the suite saying so, which
 * is the part a comment could not have done.
 */
declare(strict_types=1);

/**
 * Column widths, mirrored from db/schema*.sql.
 *
 * Only the columns a visitor's form writes to. Adding a column here that does
 * not exist, or getting a width wrong, fails e2e_fields.py.
 */
const COL_MAX = [
    'orders.email'              => 190,
    'orders.phone'              => 20,
    'orders.ship_name'          => 120,
    'orders.ship_line1'         => 190,
    'orders.ship_line2'         => 190,
    'orders.ship_city'          => 90,
    'orders.ship_state'         => 90,
    'orders.ship_pin'           => 10,
    'orders.note'               => 500,

    'addresses.name'            => 120,
    'addresses.phone'           => 20,
    'addresses.line1'           => 190,
    'addresses.line2'           => 190,
    'addresses.city'            => 90,
    'addresses.state'           => 90,
    'addresses.pin'             => 10,

    'users.name'                => 120,
    'users.email'               => 190,
    'users.phone'               => 20,

    'sourcing_requests.contact' => 190,
    'sourcing_requests.name'    => 120,
    'sourcing_requests.model'   => 160,
    'sourcing_requests.brand'   => 80,
    'sourcing_requests.link'    => 500,
    'sourcing_requests.wanted_by' => 60,
    'sourcing_requests.notes'   => 500,

    'games.host_name'           => 120,
    'games.contact'             => 190,
    'games.title'               => 160,
    'games.venue'               => 160,
    'games.city'                => 90,
    'games.state'               => 90,
    'games.duration'            => 60,
    'games.signup_link'         => 500,

    'listing_orders.ship_name'  => 120,
    'listing_orders.ship_phone' => 20,
    'listing_orders.ship_line1' => 190,
    'listing_orders.ship_line2' => 190,
    'listing_orders.ship_city'  => 90,
    'listing_orders.ship_state' => 90,
    'listing_orders.ship_pin'   => 10,

    'payout_details.upi_id'       => 120,
    'payout_details.account_name' => 120,
];

/**
 * The width of one column.
 *
 * Throws on an unknown key rather than returning a default. A silent fallback
 * is how a typo in a column name turns into "no limit at all", which is the
 * bug this file exists to remove.
 */
function col_max(string $key): int
{
    if (!isset(COL_MAX[$key])) {
        throw new InvalidArgumentException('No width recorded for ' . $key);
    }
    return COL_MAX[$key];
}

/**
 * Check posted values against the columns they are going to land in.
 *
 * $map is ['form field' => ['table.column', 'What to call it']].
 * Returns the messages to show, or [] when everything fits.
 *
 * Counted in CHARACTERS with mb_strlen, because that is what VARCHAR(n)
 * counts in utf8mb4 -- n characters, not n bytes. Measuring in bytes would
 * reject a perfectly legal Devanagari address at a third of its real length.
 */
function length_errors(array $values, array $map): array
{
    $out = [];
    foreach ($map as $field => [$column, $label]) {
        $value = (string) ($values[$field] ?? '');
        if ($value === '') {
            continue;
        }
        $max = col_max($column);
        $len = mb_strlen($value);
        if ($len > $max) {
            $out[] = sprintf(
                '%s is a bit long — %d characters, and %d is the most that fits.',
                $label,
                $len,
                $max
            );
        }
    }
    return $out;
}
