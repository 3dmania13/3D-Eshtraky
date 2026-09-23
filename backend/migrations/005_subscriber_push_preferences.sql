-- These switches affect Firebase delivery only. Every notification remains in
-- subscriber_notifications so it is always available inside the app.
ALTER TABLE subscriber_push_devices
  ADD COLUMN IF NOT EXISTS subscription_alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS device_alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS system_messages_enabled TINYINT(1) NOT NULL DEFAULT 1;
