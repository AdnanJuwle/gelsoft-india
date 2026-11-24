-- Games: a banner image, a host who must have an account, and conversations.
--
-- Safe to re-run: every statement is guarded or idempotent in effect.

-- 1. One banner per game. Stored like any other upload, as a path under
--    uploads/, re-encoded on the way in.
ALTER TABLE games ADD COLUMN IF NOT EXISTS banner VARCHAR(255) NULL AFTER details;

-- 2. Threads can now hang off a GAME as well as a listing.
--
--    Both columns are nullable and exactly one is set. The two UNIQUE keys
--    coexist happily because MySQL treats NULLs as distinct: a game thread has
--    listing_id NULL so it never collides on uniq_thread, and vice versa. Each
--    key therefore polices its own kind and ignores the other.
--
--    The column names stay buyer_id/seller_id rather than being renamed, which
--    would mean touching every query on the site. On a GAME thread read them
--    as: buyer_id = the person asking, seller_id = the host.
ALTER TABLE threads MODIFY COLUMN listing_id INT NULL;
ALTER TABLE threads ADD COLUMN IF NOT EXISTS game_id INT NULL AFTER listing_id;

-- Added separately so a re-run survives them already existing.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'threads'
              AND CONSTRAINT_NAME = 'fk_thread_game');
SET @sql := IF(@fk = 0,
  'ALTER TABLE threads ADD CONSTRAINT fk_thread_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @uk := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'threads'
              AND INDEX_NAME = 'uniq_game_thread');
SET @sql := IF(@uk = 0,
  'ALTER TABLE threads ADD UNIQUE KEY uniq_game_thread (game_id, buyer_id)',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
