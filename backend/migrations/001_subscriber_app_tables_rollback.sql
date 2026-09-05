-- Destructive rollback. Review and execute manually only when the stored app data
-- is no longer needed. RADIUS, accounting and recharge tables are untouched.

DROP TABLE IF EXISTS subscriber_notifications;
DROP TABLE IF EXISTS subscriber_devices;
DROP TABLE IF EXISTS subscriber_refresh_tokens;
