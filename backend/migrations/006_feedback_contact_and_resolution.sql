ALTER TABLE subscriber_feedback
  ADD COLUMN phone VARCHAR(32) NULL AFTER username,
  ADD COLUMN network_name VARCHAR(120) NULL AFTER category,
  ADD COLUMN resolution_notified_at DATETIME(6) NULL AFTER reviewed_by;
