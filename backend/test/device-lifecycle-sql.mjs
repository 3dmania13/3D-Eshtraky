// Run after npm run build. Pipe output to MariaDB; all test tables are temporary.
// --backfill instead emits the one-time known-device baseline for existing app users.
import { MySqlSubscriberRepository } from '../dist/database/mysql-subscriber-repository.js';
import { PushWorker } from '../dist/notifications/push-worker.js';
import { radiusDeviceIdentitySql } from '../dist/devices/radius-device-identity.js';

const eventKey = `CONCAT('radius-device:',SHA2(CONCAT(ra.username,CHAR(0),${radiusDeviceIdentitySql}),256))`;
if (process.argv.includes('--backfill')) {
  console.log(`INSERT IGNORE INTO subscriber_push_events(event_key)
    SELECT DISTINCT ${eventKey} FROM radacct ra USE INDEX(username)
    JOIN (SELECT DISTINCT username FROM subscriber_push_devices) d
      ON ra.username=CONVERT(d.username USING utf8mb4) COLLATE utf8mb4_general_ci
    WHERE NULLIF(TRIM(ra.callingstationid),'') IS NOT NULL;`);
} else {
  let devicesSql, connectionsSql;
  await new MySqlSubscriberRepository({ query: async (sql) => { devicesSql = sql; return [[]]; } })
    .getDevices({ username: 'alice' }, 100);
  await new PushWorker({}, {}).collectNewDeviceConnections({
    query: async (sql) => { connectionsSql = sql; return [[]]; },
  });
  for (const value of ["'alice'", "'alice'", "'alice'", '100']) devicesSql = devicesSql.replace('?', value);
  console.log(`SET time_zone='+00:00';
SET timestamp=UNIX_TIMESTAMP('2026-09-25 00:00:00');
CREATE TEMPORARY TABLE radacct (
 username VARCHAR(64),callingstationid VARCHAR(64),acctstarttime DATETIME,
 acctupdatetime DATETIME,acctstoptime DATETIME,framedipaddress VARCHAR(64),
 acctsessiontime INT,input_octets64 BIGINT,output_octets64 BIGINT,
 acctinputoctets BIGINT,acctoutputoctets BIGINT,
 INDEX username(username),INDEX idx_radacct_active_user(username,acctstoptime)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TEMPORARY TABLE subscriber_push_devices(username VARCHAR(64),active INT,updated_at DATETIME) DEFAULT CHARSET=utf8mb4;
CREATE TEMPORARY TABLE subscriber_push_events(event_key VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY);
CREATE TEMPORARY TABLE communication_devices(id INT,mac_address VARCHAR(64),device_name VARCHAR(64),last_seen DATETIME);
CREATE TEMPORARY TABLE subscriber_devices(username VARCHAR(64),mac_address VARCHAR(64),friendly_name VARCHAR(64));
CREATE TEMPORARY TABLE subscriber_device_speed_limits(username VARCHAR(64),mac_address VARCHAR(64),selection VARCHAR(64));
INSERT INTO subscriber_push_devices VALUES('alice',1,UTC_TIMESTAMP());
INSERT INTO radacct(username,callingstationid,acctstarttime,acctupdatetime) VALUES
 ('alice','aa:bb:cc:dd:ee:ff',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('alice','AA-BB-CC-DD-EE-FF',UTC_TIMESTAMP(),UTC_TIMESTAMP()),
 ('alice','aabb.ccdd.eeff',UTC_TIMESTAMP(),UTC_TIMESTAMP());
SELECT COUNT(DISTINCT device_identity) AS expected_1 FROM (${connectionsSql}) devices;
INSERT IGNORE INTO subscriber_push_events SELECT ${eventKey} FROM radacct ra;
SELECT COUNT(*) AS expected_0 FROM (${connectionsSql}) devices;
INSERT INTO radacct(username,callingstationid,acctstarttime,acctupdatetime)
 VALUES('alice','11:22:33:44:55:66',UTC_TIMESTAMP(),UTC_TIMESTAMP());
SELECT COUNT(*) AS expected_1 FROM (${connectionsSql}) devices;
DELETE FROM radacct;
INSERT INTO radacct(username,callingstationid,acctstarttime,acctupdatetime,acctstoptime) VALUES
 ('alice','expired',UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY-INTERVAL 1 SECOND),
 ('alice','boundary',UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL,UTC_TIMESTAMP()-INTERVAL 3 DAY),
 ('alice','stale-open',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,NULL),
 ('alice','active-long',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP(),NULL),
 ('alice','recent-stop',UTC_TIMESTAMP()-INTERVAL 5 DAY,UTC_TIMESTAMP()-INTERVAL 4 DAY,UTC_TIMESTAMP()-INTERVAL 2 DAY),
 ('bob','other-account',UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL);
SELECT GROUP_CONCAT(callingstationid ORDER BY callingstationid) AS expected_active_boundary_recent FROM (${devicesSql}) devices;
`);
}
