<?php
/** Seller verification: the checks, the derived badge, and the gate. */
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/seller_checks.php';

$pass = $fail = 0;
function check($l, $c, $d = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l $d\n"; }
}

$email = 'verify.seller@example.com';
exec_sql('DELETE FROM users WHERE email = ?', [$email]);
exec_sql('INSERT INTO users (name,email,password_hash,phone) VALUES (?,?,?,?)',
    ['Verify Seller', $email, password_hash('a long enough phrase', PASSWORD_DEFAULT), '9000000001']);
$uid = (int) q1('SELECT id FROM users WHERE email = ?', [$email])['id'];
$admin = (int) q1("SELECT id FROM users WHERE role = 'owner' LIMIT 1")['id'];

echo "--- no ID anywhere in the design ---\n";
$cols = q("SHOW COLUMNS FROM seller_checks");
$names = array_map(fn($c) => strtolower($c['Field']), $cols);
$banned = ['aadhaar', 'aadhar', 'pan', 'id_number', 'id_proof', 'document', 'passport'];
$hits = array_filter($names, fn($n) => (bool) array_filter($banned, fn($b) => str_contains($n, $b)));
check('the table stores no ID field', !$hits, implode(',', $hits));
check('a check records only kind, who and a short note',
      in_array('kind', $names, true) && in_array('checked_by', $names, true)
      && in_array('note', $names, true));
$note = q1("SHOW COLUMNS FROM seller_checks WHERE Field = 'note'");
check('the note is too short to hold a document number or a URL',
      str_contains(strtolower((string) $note['Type']), '160'), (string) $note['Type']);

echo "\n--- earning the badge ---\n";
check('a new seller is not verified', !seller_is_verified($uid));
check('...and both checks are outstanding', seller_missing($uid) === ['phone', 'payout']);

check('one check is not enough', seller_check_set($uid, 'phone', $admin, 'answered on WhatsApp') === null
      && !seller_is_verified($uid));
check('...and the badge is still off',
      q1('SELECT seller_verified_at FROM users WHERE id = ?', [$uid])['seller_verified_at'] === null);

check('both checks earn it', seller_check_set($uid, 'payout', $admin, 'UPI name matches') === null
      && seller_is_verified($uid));
check('...and the badge turns itself on',
      q1('SELECT seller_verified_at FROM users WHERE id = ?', [$uid])['seller_verified_at'] !== null);
check('nothing is outstanding', seller_missing($uid) === []);

echo "\n--- the badge follows the evidence ---\n";
seller_check_clear($uid, 'payout');
check('undoing a check takes the badge away', !seller_is_verified($uid));
check('...in the users table too',
      q1('SELECT seller_verified_at FROM users WHERE id = ?', [$uid])['seller_verified_at'] === null,
      'a badge that can drift from its evidence is worth nothing');

echo "\n--- a completed sale ---\n";
seller_note_completed_sale($uid);
check('a released sale ticks the sale check', isset(seller_checks($uid)['sale']));
check('...but a sale alone does not verify', !seller_is_verified($uid));
seller_note_completed_sale($uid);
check('ticking it twice is harmless', count(seller_checks($uid)) === 2);

echo "\n--- rubbish in ---\n";
check('an unknown check is refused', seller_check_set($uid, 'aadhaar', $admin) !== null);
check('an unknown account is refused', seller_check_set(999999, 'phone', $admin) !== null);
$long = str_repeat('x', 500);
seller_check_set($uid, 'payout', $admin, $long);
$saved = seller_checks($uid)['payout']['note'] ?? '';
check('a long note is cut to 160', mb_strlen((string) $saved) <= 160, (string) mb_strlen((string) $saved));

exec_sql('DELETE FROM users WHERE id = ?', [$uid]);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
