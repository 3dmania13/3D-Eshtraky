-- This table marks only subscribers who deliberately selected a limit in the
-- app. FreeRADIUS uses that marker before applying Simultaneous-Use, so
-- existing operator/package rows remain unaffected.
CREATE TABLE IF NOT EXISTS radius.subscriber_connection_limits (
  username VARCHAR(64) NOT NULL,
  connection_limit TINYINT UNSIGNED NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (username),
  CONSTRAINT subscriber_connection_limit_range
    CHECK (connection_limit BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

GRANT SELECT,INSERT,UPDATE,DELETE ON radius.radcheck
  TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE ON radius.subscriber_connection_limits
  TO 'three_d_subscriber_api'@'172.17.0.1';
FLUSH PRIVILEGES;
