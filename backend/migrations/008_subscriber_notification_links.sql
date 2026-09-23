-- Optional web links attached to administrative broadcasts. These are
-- application-owned notification tables; RADIUS and accounting remain untouched.
ALTER TABLE subscriber_broadcasts
  ADD COLUMN IF NOT EXISTS link_title VARCHAR(180) NULL AFTER message,
  ADD COLUMN IF NOT EXISTS link_url VARCHAR(2048) NULL AFTER link_title;

ALTER TABLE subscriber_notifications
  ADD COLUMN IF NOT EXISTS link_title VARCHAR(180) NULL AFTER message,
  ADD COLUMN IF NOT EXISTS link_url VARCHAR(2048) NULL AFTER link_title;
