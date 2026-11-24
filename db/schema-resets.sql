-- Gelsoft India — password reset tokens. Run after schema-auth.sql.
-- Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,

  -- The SHA-256 of the token, never the token itself. The link in the email
  -- is the only place the real value exists: if this table leaks, nobody can
  -- reset anything with it, which is the same reason passwords are hashed.
  token_hash CHAR(64) NOT NULL UNIQUE,

  -- Short on purpose. A reset link is a key to the account, and one sitting
  -- in a mailbox for a week is a key left under the mat.
  expires_at DATETIME NOT NULL,

  -- Set the moment it is spent, so a link cannot be used twice — not by
  -- somebody reading the mailbox later, and not by a mail scanner that
  -- follows links.
  used_at    DATETIME NULL,

  requested_ip VARBINARY(16) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user (user_id, created_at),
  INDEX idx_live (expires_at, used_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
