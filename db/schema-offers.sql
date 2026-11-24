-- Offers a seller can actually answer.
--
-- Before this, a buyer could send an offer and the seller could read it, and
-- that was the whole feature: `offer_accepted` and `offer_declined` existed in
-- the enum and message_line() knew how to render both, but nothing in the
-- codebase ever wrote either one. The buy form meanwhile promised "nothing is
-- charged until the seller accepts and you pay".
--
-- The state lives on the OFFER ROW itself rather than in a table of its own.
-- An offer is already a message with an amount on it; giving it a second home
-- would mean two rows that can disagree about the same offer, and the thread
-- is where anyone looks for what was agreed anyway.
--
-- Re-runnable: every ALTER is guarded, so running this twice is harmless.

-- offer_status: NULL on anything that is not an offer.
--   pending     waiting on the seller
--   accepted    the price is agreed, until offer_expires_at
--   declined    the seller said no
--   superseded  the buyer sent a newer offer in the same conversation
--   expired     accepted, but the hold ran out before the buyer paid
--   used        the buyer bought at this price; it cannot be spent twice
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE messages
       ADD COLUMN offer_status ENUM(''pending'',''accepted'',''declined'',
                                    ''superseded'',''expired'',''used'') NULL
         AFTER offer_paise',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'offer_status');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- When an accepted price stops being good. Set on accept, never extended --
-- a seller who wants to give more time accepts the next offer.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE messages ADD COLUMN offer_expires_at DATETIME NULL AFTER offer_status',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'offer_expires_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Who answered it and when. Always the seller today, recorded anyway: an
-- argument about a price is answered by a row, not by a recollection.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE messages ADD COLUMN offer_decided_by INT NULL AFTER offer_expires_at',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'offer_decided_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE messages ADD COLUMN offer_decided_at DATETIME NULL AFTER offer_decided_by',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
    AND COLUMN_NAME = 'offer_decided_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The hot lookup is "has this buyer got an accepted price on this listing?",
-- which runs on every view of the buy page.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE messages ADD INDEX idx_offer (offer_status, offer_expires_at)',
    'DO 0')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
    AND INDEX_NAME = 'idx_offer');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Offers that existed before this file did are waiting on their seller.
UPDATE messages SET offer_status = 'pending'
 WHERE kind = 'offer' AND offer_status IS NULL;
