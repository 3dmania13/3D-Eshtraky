-- Subscriber-submitted support messages. Application-owned only.
CREATE TABLE IF NOT EXISTS subscriber_feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  category VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  reviewed_at DATETIME(6) NULL,
  reviewed_by VARCHAR(64) NULL,
  KEY feedback_inbox (status, created_at),
  KEY feedback_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
