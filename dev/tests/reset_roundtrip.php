<?php
// The one thing an HTTP test cannot see: that the token handed to the email
// is the token whose hash was stored.
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/bootstrap.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/mail.php';
require 'C:/Users/adnan/Documents/repos/gelsoft-india/inc/resets.php';

$email = 'rt.round@example.com';
exec_sql('DELETE FROM users WHERE email = ?', [$email]);
exec_sql('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)',
    [ 'RT Round', $email, password_hash('a decent long phrase', PASSWORD_DEFAULT) ]);

$token = reset_begin($email);
echo "token looks right: ", (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? "yes" : "NO"), "\n";

$row = q1('SELECT token_hash FROM password_resets r JOIN users u ON u.id = r.user_id
           WHERE u.email = ? ORDER BY r.id DESC LIMIT 1', [$email]);
echo "stored hash is the hash of that token: ",
     (hash('sha256', (string) $token) === $row['token_hash'] ? "yes" : "NO"), "\n";
echo "the raw token is nowhere in the table: ",
     (q1('SELECT COUNT(*) n FROM password_resets WHERE token_hash = ?', [$token])['n'] == 0 ? "yes" : "NO"), "\n";

$found = reset_user_for($token);
echo "reset_user_for finds the account: ", ($found && $found['email'] === $email ? "yes" : "NO"), "\n";

$err = reset_complete($token, 'a brand new long phrase');
echo "reset_complete accepts it: ", ($err === null ? "yes" : "NO ($err)"), "\n";
echo "and refuses it a second time: ", (reset_complete($token, 'yet another phrase') !== null ? "yes" : "NO"), "\n";

$u = q1('SELECT password_hash FROM users WHERE email = ?', [$email]);
echo "the new password verifies: ", (password_verify('a brand new long phrase', $u['password_hash']) ? "yes" : "NO"), "\n";

exec_sql('DELETE FROM mail_log WHERE recipient = ?', [$email]);
exec_sql('DELETE FROM users WHERE email = ?', [$email]);
