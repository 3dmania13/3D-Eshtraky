-- Per-device speed rules are application-owned. RADIUS, accounting, and
-- subscriber package tables remain unchanged.
CREATE TABLE IF NOT EXISTS subscriber_device_speed_limits (
  username VARCHAR(64) NOT NULL,
  mac_address VARCHAR(64) NOT NULL,
  selection VARCHAR(16) NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (username,mac_address),
  KEY device_speed_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS subscriber_device_speed_applications (
  username VARCHAR(64) NOT NULL,
  mac_address VARCHAR(64) NOT NULL,
  acct_session_id VARCHAR(128) NOT NULL,
  selection VARCHAR(16) NOT NULL,
  configured_at DATETIME(6) NOT NULL,
  last_attempt_at DATETIME(6) NOT NULL,
  applied_at DATETIME(6) NULL,
  PRIMARY KEY (username,mac_address,acct_session_id),
  KEY device_speed_retry (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

GRANT SELECT,INSERT,UPDATE,DELETE ON radius.subscriber_device_speed_limits
  TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON radius.subscriber_device_speed_applications
  TO 'three_d_subscriber_api'@'172.17.0.1';
FLUSH PRIVILEGES;
