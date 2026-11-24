-- The owner role: one level above admin.
--
-- Why it exists: every admin could remove every other admin, so one
-- compromised admin account could lock everybody else out of the panel and
-- keep it. Day-to-day admin work (orders, listings, the FAQ) does not need
-- that power, so it is split off.
--
--   customer  nothing in /admin/
--   admin     everything in /admin/ EXCEPT changing who has access
--   owner     that, plus adding and removing admins
--
-- Safe to re-run.

ALTER TABLE users
  MODIFY COLUMN role ENUM('customer','admin','owner') NOT NULL DEFAULT 'customer';

-- The existing admin account becomes the owner: on an upgrade there is
-- normally one, and it belongs to whoever set the site up. If you have
-- several and the wrong one is picked, promote the right one in /admin/staff
-- and demote this one.
UPDATE users SET role = 'owner'
 WHERE role = 'admin'
   AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM users) u WHERE u.role = 'owner')
 ORDER BY id
 LIMIT 1;
