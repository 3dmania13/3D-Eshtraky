const rates = {
    "512K": "512K/512K",
    "1M": "1M/1M",
    "2M": "2M/2M",
    "3M": "3M/3M",
    "4M": "4M/4M",
    "5M": "5M/5M",
};
/** Re-applies saved device limits after reconnects without touching RADIUS data. */
export class DeviceSpeedWorker {
    pool;
    live;
    nextCleanupAt = 0;
    constructor(pool, live) {
        this.pool = pool;
        this.live = live;
    }
    async tick() {
        const connection = await this.pool.getConnection();
        let locked = false;
        try {
            const [locks] = await connection.query("SELECT GET_LOCK(CONCAT('subscriber-device-speed:',DATABASE()),0) AS acquired");
            locked = Number(locks[0]?.acquired) === 1;
            if (!locked)
                return;
            if (Date.now() >= this.nextCleanupAt) {
                await connection.execute("DELETE FROM subscriber_device_speed_applications WHERE last_attempt_at<UTC_TIMESTAMP()-INTERVAL 30 DAY");
                this.nextCleanupAt = Date.now() + 3_600_000;
            }
            const [rules] = await connection.query(`SELECT DISTINCT limit_rule.username,limit_rule.mac_address,
                limit_rule.selection,limit_rule.updated_at AS configured_at
           FROM subscriber_device_speed_limits limit_rule
           JOIN radacct session
             ON session.username=limit_rule.username
            AND BINARY session.callingstationid=BINARY limit_rule.mac_address
            AND session.acctstoptime IS NULL
            AND COALESCE(session.acctupdatetime,session.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
            AND NULLIF(session.acctsessionid,'') IS NOT NULL
           LEFT JOIN subscriber_device_speed_applications application
             ON application.username=limit_rule.username
            AND BINARY application.mac_address=BINARY limit_rule.mac_address
            AND application.acct_session_id=session.acctsessionid
          WHERE application.acct_session_id IS NULL
             OR application.selection<>limit_rule.selection
             OR application.configured_at<limit_rule.updated_at
             OR (application.applied_at IS NULL
                 AND application.last_attempt_at<UTC_TIMESTAMP()-INTERVAL 15 SECOND)
          ORDER BY limit_rule.updated_at ASC LIMIT 8`);
            for (const rule of rules) {
                const rate = rates[String(rule.selection)];
                if (!rate)
                    continue;
                await this.recordAttempt(connection, rule);
                try {
                    const result = await this.live.apply(String(rule.username), String(rule.mac_address), rate);
                    if (result.status === "applied") {
                        await connection.execute(`UPDATE subscriber_device_speed_applications
                  SET applied_at=UTC_TIMESTAMP(6)
                WHERE username=? AND BINARY mac_address=BINARY ?
                  AND selection=? AND configured_at=?`, [
                            rule.username,
                            rule.mac_address,
                            rule.selection,
                            rule.configured_at,
                        ]);
                    }
                }
                catch {
                    // The timestamp written above rate-limits retries for unreachable NASes.
                }
            }
        }
        finally {
            if (locked) {
                await connection.query("SELECT RELEASE_LOCK(CONCAT('subscriber-device-speed:',DATABASE()))");
            }
            connection.release();
        }
    }
    async recordAttempt(connection, rule) {
        await connection.execute(`INSERT INTO subscriber_device_speed_applications
        (username,mac_address,acct_session_id,selection,configured_at,last_attempt_at,applied_at)
       SELECT ?,?,session.acctsessionid,?,?,UTC_TIMESTAMP(6),NULL
         FROM radacct session
        WHERE session.username=? AND BINARY session.callingstationid=BINARY ?
          AND session.acctstoptime IS NULL
          AND COALESCE(session.acctupdatetime,session.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
          AND NULLIF(session.acctsessionid,'') IS NOT NULL
       ON DUPLICATE KEY UPDATE selection=VALUES(selection),configured_at=VALUES(configured_at),
         last_attempt_at=VALUES(last_attempt_at),applied_at=NULL`, [
            rule.username,
            rule.mac_address,
            rule.selection,
            rule.configured_at,
            rule.username,
            rule.mac_address,
        ]);
    }
}
//# sourceMappingURL=device-speed-worker.js.map