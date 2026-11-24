-- Gelsoft India — orders and payments. Run after schema.sql and schema-auth.sql.
-- Safe to re-run.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS addresses (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  label      VARCHAR(40)  NULL,              -- Home, Work
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(20)  NOT NULL,
  line1      VARCHAR(190) NOT NULL,
  line2      VARCHAR(190) NULL,
  city       VARCHAR(90)  NOT NULL,
  state      VARCHAR(90)  NOT NULL,
  pin        VARCHAR(10)  NOT NULL,
  is_default TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_user (user_id, is_default),
  CONSTRAINT fk_address_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id        INT AUTO_INCREMENT PRIMARY KEY,

  -- What the customer quotes at you. Generated, not the row id, because the
  -- row id tells anyone who asks how many orders you have ever taken.
  reference VARCHAR(20) NOT NULL UNIQUE,

  user_id   INT          NULL,               -- null for a guest checkout
  email     VARCHAR(190) NOT NULL,
  phone     VARCHAR(20)  NOT NULL,

  -- Copied, not joined. An address edited next year must not rewrite where
  -- last year's parcel was actually sent.
  ship_name  VARCHAR(120) NOT NULL,
  ship_line1 VARCHAR(190) NOT NULL,
  ship_line2 VARCHAR(190) NULL,
  ship_city  VARCHAR(90)  NOT NULL,
  ship_state VARCHAR(90)  NOT NULL,
  ship_pin   VARCHAR(10)  NOT NULL,
  note       VARCHAR(500) NULL,

  subtotal_paise INT UNSIGNED NOT NULL,
  shipping_paise INT UNSIGNED NOT NULL DEFAULT 0,
  total_paise    INT UNSIGNED NOT NULL,

  status ENUM('pending','paid','packed','shipped','delivered','cancelled','refunded')
         NOT NULL DEFAULT 'pending',

  payment_provider VARCHAR(20) NULL,          -- razorpay
  payment_order_id VARCHAR(80) NULL,          -- order_xxx, ours at their end
  payment_id       VARCHAR(80) NULL,          -- pay_xxx, set when it clears
  paid_at          DATETIME    NULL,

  tracking   VARCHAR(120) NULL,
  courier    VARCHAR(60)  NULL,
  admin_note VARCHAR(500) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status  (status, created_at),
  INDEX idx_user    (user_id, created_at),
  INDEX idx_payment (payment_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  order_id   INT NOT NULL,

  -- SET NULL, not CASCADE: deleting a product must not quietly empty an old
  -- order. The name and price below are what the order actually was.
  product_id INT NULL,

  name_at_purchase  VARCHAR(160) NOT NULL,
  slug_at_purchase  VARCHAR(140) NULL,
  price_paise       INT UNSIGNED NOT NULL,
  qty               SMALLINT UNSIGNED NOT NULL,

  INDEX idx_order (order_id),
  CONSTRAINT fk_item_order   FOREIGN KEY (order_id)   REFERENCES orders (id)   ON DELETE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every webhook Razorpay sends, stored before it is acted on.
--
-- The unique event id is what makes the handler idempotent: a webhook that is
-- delivered twice (which Razorpay does, by design, when it is not sure the
-- first one landed) must not mark an order paid twice or decrement stock
-- twice. The insert fails the second time, and that is the whole mechanism.
CREATE TABLE IF NOT EXISTS payment_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  event_id   VARCHAR(80)  NOT NULL UNIQUE,
  event      VARCHAR(60)  NOT NULL,
  order_id   INT          NULL,
  payload    MEDIUMTEXT   NULL,
  handled_at DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_order (order_id),
  CONSTRAINT fk_event_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail we tried to send, so "they never got the confirmation" is answerable.
CREATE TABLE IF NOT EXISTS mail_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  recipient  VARCHAR(190) NOT NULL,
  subject    VARCHAR(190) NOT NULL,
  template   VARCHAR(40)  NULL,
  ok         TINYINT(1)   NOT NULL DEFAULT 0,
  error      VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_when (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
