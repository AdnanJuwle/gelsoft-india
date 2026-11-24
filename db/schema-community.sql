-- Gelsoft India — sourcing requests and the games board.
-- Run after schema.sql and schema-auth.sql. Safe to re-run.

SET NAMES utf8mb4;

-- "Can you get me this?" A request is public once it is approved, so other
-- people can add themselves to it — a model with eight people behind it is
-- worth ordering, one with a single backer usually is not.
CREATE TABLE IF NOT EXISTS sourcing_requests (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT          NULL,             -- null for a guest request
  contact     VARCHAR(190) NOT NULL,         -- email or phone, how we answer
  name        VARCHAR(120) NULL,

  model       VARCHAR(160) NOT NULL,
  brand       VARCHAR(80)  NULL,
  details     TEXT         NULL,
  link        VARCHAR(500) NULL,             -- where they saw it

  budget_min_paise INT UNSIGNED NULL,
  budget_max_paise INT UNSIGNED NULL,
  wanted_by   VARCHAR(60)  NULL,
  notes       VARCHAR(500) NULL,

  public      TINYINT(1)   NOT NULL DEFAULT 1,  -- show it on the board

  /*
    new        just in, nobody has looked
    quoting    we are pricing it
    quoted     we answered with a price and a lead time
    ordered    a deposit is paid and it is on its way
    declined   we cannot get it, and we said why
    closed     done, or gone quiet
  */
  status ENUM('new','quoting','quoted','ordered','declined','closed')
         NOT NULL DEFAULT 'new',

  quote_paise INT UNSIGNED NULL,
  lead_time   VARCHAR(60)  NULL,
  reply       VARCHAR(1000) NULL,            -- what we told them

  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_status (status, created_at),
  INDEX idx_board  (public, status, created_at),
  CONSTRAINT fk_req_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Somebody else saying "me too". One per person per request — the unique key
-- is the whole mechanism, because a backing count that can be clicked twice
-- is not a number worth ordering stock against.
CREATE TABLE IF NOT EXISTS request_backers (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT          NOT NULL,
  user_id    INT          NULL,
  contact    VARCHAR(190) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uniq_backer (request_id, contact),
  INDEX idx_request (request_id),
  CONSTRAINT fk_back_request FOREIGN KEY (request_id) REFERENCES sourcing_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_back_user    FOREIGN KEY (user_id)    REFERENCES users (id)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Games people are hosting. Listed whether or not the host bought anything
-- from us, which is the point of having a board rather than a customer list.
CREATE TABLE IF NOT EXISTS games (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  host_id     INT          NULL,
  host_name   VARCHAR(120) NOT NULL,
  contact     VARCHAR(190) NOT NULL,         -- not shown publicly

  title       VARCHAR(160) NOT NULL,
  venue       VARCHAR(160) NULL,
  city        VARCHAR(90)  NOT NULL,
  state       VARCHAR(90)  NULL,
  region      ENUM('north','south','east','west','north-east','central') NULL,

  starts_at   DATETIME     NOT NULL,
  duration    VARCHAR(60)  NULL,

  fps_cap     SMALLINT UNSIGNED NULL,        -- the number people actually need
  fee_paise   INT UNSIGNED NULL,
  spaces      SMALLINT UNSIGNED NULL,
  rental      TINYINT(1)   NOT NULL DEFAULT 0,
  beginners   TINYINT(1)   NOT NULL DEFAULT 1,

  details     TEXT         NULL,
  signup_link VARCHAR(500) NULL,

  status ENUM('pending','live','rejected','past','cancelled') NOT NULL DEFAULT 'pending',
  reject_note VARCHAR(255) NULL,

  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_board (status, starts_at),
  INDEX idx_region (region, starts_at),
  CONSTRAINT fk_game_host FOREIGN KEY (host_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
