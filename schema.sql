-- ============================================================
--  Slipstream tunnel pong — WebRTC signaling schema
--  Run this once against the database you create for the game.
--  e.g.  mysql -u root -p tunnelpong < schema.sql
-- ============================================================
CREATE DATABASE tunnelpong;
-- One row per game "room" (an invite code).
CREATE TABLE IF NOT EXISTS rooms (
  code        VARCHAR(8)   NOT NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'waiting',  -- waiting | full
  created_at  INT UNSIGNED NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Signaling messages (SDP offer/answer + ICE candidates) exchanged
-- between the two peers while they establish the direct connection.
-- These are short-lived; the gameplay itself never touches this table.
CREATE TABLE IF NOT EXISTS signals (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(8)   NOT NULL,
  sender      VARCHAR(8)   NOT NULL,   -- host | guest
  type        VARCHAR(16)  NOT NULL,   -- offer | answer | ice
  payload     MEDIUMTEXT   NOT NULL,   -- JSON
  created_at  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_code_id (code, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
