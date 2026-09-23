-- Administrative broadcast jobs. These tables are owned by the subscriber app;
-- no RADIUS, accounting, or session tables are modified.
CREATE TABLE IF NOT EXISTS subscriber_broadcasts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  audience VARCHAR(24) NOT NULL,
  ip_prefix VARCHAR(15) NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  processing_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  KEY queued_broadcasts (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriber_broadcast_recipients (
  broadcast_id BIGINT UNSIGNED NOT NULL,
  username VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  queued_at DATETIME(6) NULL,
  PRIMARY KEY (broadcast_id, username),
  KEY pending_recipients (broadcast_id, status),
  CONSTRAINT subscriber_broadcast_recipients_broadcast
    FOREIGN KEY (broadcast_id) REFERENCES subscriber_broadcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
