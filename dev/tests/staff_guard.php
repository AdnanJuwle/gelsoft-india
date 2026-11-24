<?php
/**
 * The last-admin guard cannot be reached through the UI -- demoting someone
 * other than yourself means there are at least two admins. It exists for the
 * race: your own admin being taken away between require_admin() and the write.
 * So it is exercised here, at the model, where that state can be built.
 */
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/staff.php';

$pass = $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else       { $fail++; echo "  FAIL $label $detail\n"; }
}

$email = 'staff.solo@example.com';
exec_sql('DELETE FROM users WHERE email = ?', [$email]);
exec_sql("INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,'owner')",
    ['Solo Admin', $email, password_hash('a long enough phrase', PASSWORD_DEFAULT)]);
$solo = (int) q1('SELECT id FROM users WHERE email = ?', [$email])['id'];

// Park every other admin so this one is genuinely the last.
exec_sql("UPDATE users SET role = 'customer' WHERE role IN ('admin','owner') AND id <> ?", [$solo]);
$parked = q("SELECT id FROM users WHERE role = 'customer' AND id <> ?", [$solo]);

check('exactly one owner exists', owner_count() === 1, (string) staff_count());

// A different id as the actor: the race, where the caller is no longer an admin.
$err = staff_demote($solo, $solo + 99999);
check('the last owner is refused', $err !== null && str_contains($err, 'only owner'), (string) $err);
check('...and is still the owner', q1('SELECT role FROM users WHERE id = ?', [$solo])['role'] === 'owner');

// With a second admin present it goes through.
exec_sql("UPDATE users SET role = 'owner' WHERE email = 'admin@gelsoft.in'");
check('two owners now', owner_count() === 2, (string) staff_count());
$err = staff_demote($solo, $solo + 99999);
check('now it is allowed', $err === null, (string) $err);
check('...and the role changed', q1('SELECT role FROM users WHERE id = ?', [$solo])['role'] === 'customer');

exec_sql('DELETE FROM users WHERE id = ?', [$solo]);
exec_sql("DELETE FROM admin_log WHERE action LIKE 'staff_%'");
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
