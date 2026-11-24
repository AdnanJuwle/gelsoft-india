-- How a seller earns the "verified" badge, WITHOUT anyone handing over an ID.
--
-- Collecting government ID was considered and rejected. UIDAI's position is
-- that an unlicensed private entity may not collect or keep Aadhaar copies at
-- all, and under the DPDP Act 2025 holding ID documents would make this site
-- the custodian of the most sensitive thing a person owns, with penalties to
-- match. None of that is necessary here, because the marketplace already
-- holds the buyer's money until delivery -- a seller who never gets paid has
-- nothing to gain.
--
-- So verification records CHECKS WE PERFORMED, never documents we were given:
--
--   phone   we reached the seller on the number on their account
--   payout  the name on their UPI/bank account matches the account name they
--           gave us. The bank did the identity work; we only compare.
--   sale    they have completed a sale and the buyer confirmed delivery.
--
-- Nothing in here is a document, an ID number, or a copy of anything.

CREATE TABLE IF NOT EXISTS seller_checks (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,

  kind ENUM('phone','payout','sale') NOT NULL,

  -- Who ticked it, so the trail says a person did this and which person.
  checked_by INT NULL,

  -- Free text: "spoke to him on WhatsApp", "UPI name matches". Never an ID
  -- number -- the column is short on purpose.
  note VARCHAR(160) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_check (user_id, kind),
  CONSTRAINT fk_check_user FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_check_by   FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
