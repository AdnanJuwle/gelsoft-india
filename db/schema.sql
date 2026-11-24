-- Gelsoft India — phase 2 schema: the shop catalogue.
--
-- Run once, on a database you created in hPanel > Databases > MySQL:
--   phpMyAdmin > your database > Import > choose this file
-- or from a shell:
--   mysql -u USER -p DBNAME < db/schema.sql
--
-- Safe to re-run: every statement is IF NOT EXISTS and nothing drops data.
--
-- Money is stored as an INTEGER number of paise. A float would eventually bill
-- someone ₹12,499.99 for a ₹12,500 blaster.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id            INT AUTO_INCREMENT PRIMARY KEY,

  -- The URL: /blasters/tg-gen8-m4. Unique, lowercase, generated from the name
  -- but editable, because changing a name should not break a shared link.
  slug          VARCHAR(140)  NOT NULL UNIQUE,

  category      ENUM('blaster','gear','part','ammo') NOT NULL,
  brand         VARCHAR(80)   NULL,            -- optional, by design
  name          VARCHAR(160)  NOT NULL,
  summary       VARCHAR(255)  NULL,            -- the one line on a card
  description   TEXT          NULL,

  -- Blasters ------------------------------------------------------------
  grade         ENUM('toy','mid','premium') NULL,
  type          ENUM('rifle','pistol','smg','bolt') NULL,
  gears         ENUM('metal','nylon','manual') NULL,
  -- Deliberately separate from gears: a nylon gearbox shell can hold metal
  -- gears, and conflating the two is the single most common spec mistake.
  gearbox       VARCHAR(80)   NULL,
  fps_min       SMALLINT UNSIGNED NULL,
  fps_max       SMALLINT UNSIGNED NULL,
  battery       VARCHAR(120)  NULL,
  magazine      VARCHAR(120)  NULL,
  needs_to_run  VARCHAR(255)  NULL,            -- "battery, charger and gel"

  -- Gear and parts -------------------------------------------------------
  fits          VARCHAR(160)  NULL,            -- 'gen8, gen9, v2' or 'any'
  sizes         VARCHAR(80)   NULL,            -- 'S, M, L' or 'one size'
  part_group    VARCHAR(60)   NULL,            -- internals, optics, mags…
  gear_group    VARCHAR(60)   NULL,            -- eye, head, body, hands…

  -- Commerce -------------------------------------------------------------
  price_paise   INT UNSIGNED  NOT NULL,
  was_paise     INT UNSIGNED  NULL,            -- strike-through, when on offer
  stock_qty     INT           NOT NULL DEFAULT 0,
  -- What the card says. Kept apart from stock_qty so you can show "Pre-order"
  -- on something you have not received yet without faking the count.
  stock_label   ENUM('in','low','pre','soon') NOT NULL DEFAULT 'in',

  status        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  featured      TINYINT(1)    NOT NULL DEFAULT 0,
  sort_order    INT           NOT NULL DEFAULT 0,

  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_shelf  (category, status, sort_order),
  INDEX idx_grade  (grade),
  INDEX idx_brand  (brand),
  INDEX idx_price  (price_paise),
  FULLTEXT KEY ft_search (name, summary, description, brand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT          NOT NULL,
  path        VARCHAR(255) NOT NULL,           -- 'uploads/tg-gen8-1.webp'
  alt         VARCHAR(255) NULL,
  sort_order  TINYINT      NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_product (product_id, sort_order),
  CONSTRAINT fk_image_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Someone asking to hear when a thing comes back. Phase 6 sends the mails;
-- the table is here now because the shop pages already collect addresses.
CREATE TABLE IF NOT EXISTS restock_alerts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT          NULL,               -- null = "tell me about anything"
  category    VARCHAR(20)  NULL,
  contact     VARCHAR(190) NOT NULL,           -- email or a phone number
  notified_at DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_pending (notified_at, product_id),
  CONSTRAINT fk_alert_product FOREIGN KEY (product_id)
    REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
