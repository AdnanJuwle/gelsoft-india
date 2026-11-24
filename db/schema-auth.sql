-- Gelsoft India — accounts, messages and the marketplace tables.
-- Run after db/schema.sql. Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  phone         VARCHAR(20)  NULL,

  -- password_hash(), never anything reversible. 255 so a future algorithm
  -- with a longer output still fits.
  password_hash VARCHAR(255) NOT NULL,

  role          ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  seller_verified_at DATETIME NULL,

  -- Brute-force throttle. Counted per account, and separately per IP in
  -- login_attempts below, because an attacker spreads across both.
  failed_logins SMALLINT  NOT NULL DEFAULT 0,
  locked_until  DATETIME  NULL,
  last_login_at DATETIME  NULL,

  created_at    DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every attempt, successful or not, so a flood from one address can be
-- throttled even when the account does not exist.
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARBINARY(16) NOT NULL,          -- inet6_aton, v4 and v6
  identifier VARCHAR(190)  NOT NULL,
  ok         TINYINT(1)    NOT NULL DEFAULT 0,
  created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip   (ip, created_at),
  INDEX idx_user (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marketplace listings.
CREATE TABLE IF NOT EXISTS listings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(140) NOT NULL UNIQUE,
  seller_id     INT          NOT NULL,

  title         VARCHAR(160) NOT NULL,
  description   TEXT         NULL,
  brand         VARCHAR(80)  NULL,
  grade         ENUM('toy','mid','premium') NULL,
  gears         ENUM('metal','nylon','manual') NULL,
  item_condition ENUM('like-new','good','fair','serviced') NOT NULL DEFAULT 'good',

  price_paise   INT UNSIGNED NOT NULL,
  postage_paise INT UNSIGNED NOT NULL DEFAULT 0,
  accepts_offers TINYINT(1)  NOT NULL DEFAULT 1,

  -- The seller leaves this on and a buyer may then pay us to check the item
  -- before it reaches them. The seller's fee does not change either way.
  allow_inspection TINYINT(1) NOT NULL DEFAULT 1,

  status ENUM('draft','pending','live','sold','withdrawn','rejected')
         NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  reject_note VARCHAR(255) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_browse (status, created_at),
  INDEX idx_seller (seller_id, status),
  CONSTRAINT fk_listing_seller FOREIGN KEY (seller_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listing_images (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT          NOT NULL,
  path       VARCHAR(255) NOT NULL,
  alt        VARCHAR(255) NULL,
  sort_order TINYINT      NOT NULL DEFAULT 0,
  INDEX idx_listing (listing_id, sort_order),
  CONSTRAINT fk_limage_listing FOREIGN KEY (listing_id)
    REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One thread per listing per buyer. Questions and offers both live here, so
-- "they agreed a price in DMs" stops being a thing anyone can claim.
CREATE TABLE IF NOT EXISTS threads (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT NOT NULL,
  buyer_id   INT NOT NULL,
  seller_id  INT NOT NULL,

  -- Raised by admin when someone reports it, so the moderation queue has
  -- something to show without reading every conversation on the site.
  flagged    TINYINT(1) NOT NULL DEFAULT 0,

  last_message_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_thread (listing_id, buyer_id),
  INDEX idx_seller (seller_id, last_message_at),
  INDEX idx_buyer  (buyer_id, last_message_at),
  INDEX idx_flagged (flagged, last_message_at),
  CONSTRAINT fk_thread_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE,
  CONSTRAINT fk_thread_buyer   FOREIGN KEY (buyer_id)   REFERENCES users (id)    ON DELETE CASCADE,
  CONSTRAINT fk_thread_seller  FOREIGN KEY (seller_id)  REFERENCES users (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  thread_id  INT NOT NULL,
  sender_id  INT NOT NULL,

  -- 'offer' carries a number; 'message' is just words. Kept in one table so
  -- the conversation reads in order, which is the point of having it.
  kind       ENUM('message','offer','offer_accepted','offer_declined','system')
             NOT NULL DEFAULT 'message',
  body       TEXT NULL,
  offer_paise INT UNSIGNED NULL,

  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_thread (thread_id, created_at),
  CONSTRAINT fk_message_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE,
  CONSTRAINT fk_message_sender FOREIGN KEY (sender_id) REFERENCES users (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editable site copy: the FAQ, and the policy pages.
CREATE TABLE IF NOT EXISTS faq_entries (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  question   VARCHAR(255) NOT NULL,
  answer     TEXT         NOT NULL,
  section    VARCHAR(60)  NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  published  TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_order (published, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  slug       VARCHAR(60)  PRIMARY KEY,         -- policy, safety-intro, …
  title      VARCHAR(160) NOT NULL,
  body       MEDIUMTEXT   NOT NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who changed what. The first thing you want when a price is suddenly wrong.
CREATE TABLE IF NOT EXISTS admin_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NULL,
  action     VARCHAR(60)  NOT NULL,
  target     VARCHAR(120) NULL,
  detail     VARCHAR(500) NULL,
  ip         VARBINARY(16) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_when (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
