-- Records only the threshold state needed to send one low-balance alert per
-- recharge cycle. It does not alter RADIUS, accounting, or subscriber tables.
CREATE TABLE IF NOT EXISTS subscriber_quota_alert_state (
  username VARCHAR(64) NOT NULL,
  is_low TINYINT(1) NOT NULL DEFAULT 0,
  generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
