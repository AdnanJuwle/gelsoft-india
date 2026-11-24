-- Gelsoft India — the marketplace sale itself.
-- Run after schema-auth.sql (which creates `listings`) and schema-orders.sql.
-- Safe to re-run.

SET NAMES utf8mb4;

-- Columns the sell form needs that the first cut of `listings` did not have.
-- Written as a procedure because MySQL has no ADD COLUMN IF NOT EXISTS, and
-- this file has to be safe to run twice.
DROP PROCEDURE IF EXISTS add_listing_columns;
DELIMITER //
CREATE PROCEDURE add_listing_columns()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'listings'
                   AND column_name = 'fps') THEN
    ALTER TABLE listings
      ADD COLUMN fps           SMALLINT UNSIGNED NULL AFTER gears,
      ADD COLUMN gearbox       VARCHAR(80)  NULL AFTER fps,
      ADD COLUMN included      VARCHAR(500) NULL AFTER description,
      ADD COLUMN faults        VARCHAR(500) NULL AFTER included,
      ADD COLUMN bought_when   VARCHAR(60)  NULL AFTER faults,
      ADD COLUMN handover      ENUM('courier','pickup','both') NOT NULL DEFAULT 'courier' AFTER postage_paise,
      ADD COLUMN city          VARCHAR(90)  NULL AFTER handover,
      ADD COLUMN state         VARCHAR(90)  NULL AFTER city,
      ADD COLUMN sold_at       DATETIME     NULL AFTER reviewed_at,
      ADD COLUMN views         INT UNSIGNED NOT NULL DEFAULT 0 AFTER sold_at;
  END IF;
END //
DELIMITER ;
CALL add_listing_columns();
DROP PROCEDURE add_listing_columns;

-- One sale of one listing.
--
-- The money sits with us between `held` and `released`. That is the whole
-- promise of the marketplace, so the states are explicit and nothing moves
-- between them by accident.
CREATE TABLE IF NOT EXISTS listing_orders (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  reference  VARCHAR(20) NOT NULL UNIQUE,     -- MK-2026-0001

  listing_id INT NOT NULL,
  buyer_id   INT NOT NULL,
  seller_id  INT NOT NULL,

  -- Split out rather than summed, because the seller and the buyer are each
  -- owed a clear answer about their own side of it.
  price_paise      INT UNSIGNED NOT NULL,     -- what the buyer agreed to pay
  postage_paise    INT UNSIGNED NOT NULL DEFAULT 0,
  inspection_paise INT UNSIGNED NOT NULL DEFAULT 0,  -- buyer's option, our fee
  total_paise      INT UNSIGNED NOT NULL,     -- what the buyer is charged

  seller_fee_paise INT UNSIGNED NOT NULL DEFAULT 0,  -- our cut from the seller
  handling_paise   INT UNSIGNED NOT NULL DEFAULT 0,
  payout_paise     INT UNSIGNED NOT NULL DEFAULT 0,  -- what the seller gets

  inspected TINYINT(1) NOT NULL DEFAULT 0,

  /*
    pending          buyer started it, has not paid
    held             paid. We are holding the money
    to_us            inspection chosen: seller is sending it to us
    inspecting       it is on our bench
    failed           it is not what the listing said; going back to the seller
    shipped          on its way to the buyer
    delivered        buyer says it arrived
    released         paid out to the seller
    disputed         buyer raised a problem inside the window
    refunded         money went back to the buyer
    cancelled        nothing happened
  */
  status ENUM('pending','held','to_us','inspecting','failed','shipped',
              'delivered','released','disputed','refunded','cancelled')
         NOT NULL DEFAULT 'pending',

  ship_name  VARCHAR(120) NOT NULL,
  ship_phone VARCHAR(20)  NOT NULL,
  ship_line1 VARCHAR(190) NOT NULL,
  ship_line2 VARCHAR(190) NULL,
  ship_city  VARCHAR(90)  NOT NULL,
  ship_state VARCHAR(90)  NOT NULL,
  ship_pin   VARCHAR(10)  NOT NULL,

  courier   VARCHAR(60)  NULL,
  tracking  VARCHAR(120) NULL,

  payment_order_id VARCHAR(80) NULL,
  payment_id       VARCHAR(80) NULL,

  -- Set when it is marked delivered. After this passes with no dispute the
  -- money can be released.
  confirm_by  DATETIME NULL,
  inspection_note VARCHAR(1000) NULL,
  dispute_note    VARCHAR(1000) NULL,
  admin_note      VARCHAR(500)  NULL,

  paid_at     DATETIME NULL,
  shipped_at  DATETIME NULL,
  released_at DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status  (status, created_at),
  INDEX idx_buyer   (buyer_id, created_at),
  INDEX idx_seller  (seller_id, created_at),
  INDEX idx_payment (payment_order_id),

  CONSTRAINT fk_lo_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_lo_buyer   FOREIGN KEY (buyer_id)   REFERENCES users (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_lo_seller  FOREIGN KEY (seller_id)  REFERENCES users (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every state change, with who did it. When a sale goes wrong this is the
-- only thing that settles what actually happened and when.
CREATE TABLE IF NOT EXISTS listing_order_events (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  order_id  INT NOT NULL,
  actor_id  INT NULL,                        -- null = the system
  was       VARCHAR(20) NULL,
  became    VARCHAR(20) NOT NULL,
  note      VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_order (order_id, created_at),
  CONSTRAINT fk_loe_order FOREIGN KEY (order_id) REFERENCES listing_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where a seller's payout goes. Separate from the user row because it is the
-- one field worth treating carefully.
CREATE TABLE IF NOT EXISTS payout_details (
  user_id    INT PRIMARY KEY,
  upi_id     VARCHAR(120) NULL,
  account_name VARCHAR(120) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
