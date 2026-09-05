-- Replace the host and password before manual execution.
-- SELECT is limited to the confirmed legacy sources used by this API.

CREATE USER IF NOT EXISTS 'three_d_subscriber_api'@'172.17.0.1'
    IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';

GRANT SELECT ON radius.userinfo TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.packages TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.radcheck TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.radusergroup TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.radacct TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.nawa_usage_daily TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.three_d_net_recharge_transactions TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.nawa_audit_log TO 'three_d_subscriber_api'@'172.17.0.1';

GRANT SELECT,INSERT,UPDATE ON radius.subscriber_refresh_tokens
    TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT,INSERT,UPDATE ON radius.subscriber_devices
    TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT,INSERT,UPDATE ON radius.subscriber_notifications
    TO 'three_d_subscriber_api'@'172.17.0.1';

FLUSH PRIVILEGES;
