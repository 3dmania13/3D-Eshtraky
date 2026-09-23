import { createHash } from 'node:crypto';
import { asBigInt, asDate, requiredDate } from '../utils/values.js';
const accountSelect = `
  SELECT ui.id, ui.username, COALESCE(ui.status,'active') AS raw_status,
         COALESCE(ui.is_disabled,0) AS is_disabled,
         COALESCE(ui.expiry_date,ui.expires_at) AS expires_at,
         (SELECT rc.value
            FROM radcheck rc USE INDEX (idx_radcheck_user_attr)
           WHERE rc.username=ui.username AND rc.attribute='Cleartext-Password'
           ORDER BY rc.id DESC LIMIT 1) AS cleartext_password,
         EXISTS(
           SELECT 1 FROM radusergroup rug
            WHERE rug.username=ui.username
              AND rug.groupname='daloRADIUS-Disabled-Users'
         ) OR EXISTS(
           SELECT 1 FROM radcheck reject_check USE INDEX (idx_radcheck_user_attr)
            WHERE reject_check.username=ui.username
              AND reject_check.attribute='Auth-Type'
              AND LOWER(reject_check.value)='reject'
         ) AS is_in_disabled_group
    FROM userinfo ui
   WHERE ui.username=?
   LIMIT 1`;
function account(row) {
    return {
        id: String(row.id),
        username: String(row.username),
        rawStatus: String(row.raw_status ?? 'active'),
        isDisabled: Boolean(row.is_disabled),
        isInDisabledGroup: Boolean(row.is_in_disabled_group),
        expiresAt: asDate(row.expires_at),
        cleartextPassword: row.cleartext_password === null || row.cleartext_password === undefined
            ? null
            : String(row.cleartext_password),
    };
}
function deviceId(username, callingStationId) {
    return createHash('sha256').update(username).update('\0').update(callingStationId).digest('hex');
}
export class MySqlSubscriberRepository {
    pool;
    constructor(pool) {
        this.pool = pool;
    }
    async getUsageCycle(username) {
        const [rows] = await this.pool.query(`SELECT a.created_at AS reset_at,
              GREATEST(
                COALESCE(NULLIF(p.max_total_bytes,0),0),
                CASE
                  WHEN p.quota_unit='Giga' AND p.total_data>0 AND p.total_data<1073741824
                    THEN p.total_data*1073741824
                  ELSE COALESCE(p.total_data,0)
                END
              ) AS total_bytes
         FROM nawa_audit_log a
         LEFT JOIN packages p
           ON p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.package_id')) AS UNSIGNED)
        WHERE a.subject_type='user'
          AND a.subject_id=?
          AND a.action_name='user.package_settle'
          AND CAST(REGEXP_SUBSTR(
                COALESCE(
                  NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.package_name')),''),
                  p.name,
                  ''
                ),
                '[0-9]+'
              ) AS UNSIGNED)>=2000
        ORDER BY a.created_at DESC,a.id DESC
        LIMIT 1`, [username]);
        const row = rows[0];
        if (!row)
            return null;
        return {
            resetAt: requiredDate(row.reset_at, 'reset_at'),
            totalBytes: asBigInt(row.total_bytes, 'total_bytes'),
        };
    }
    async findForAuthentication(username) {
        return this.findAccount(this.pool, username);
    }
    async issueRefreshToken(input) {
        await this.pool.execute(`INSERT INTO subscriber_refresh_tokens
        (subscriber_username,token_hash,created_at,expires_at,revoked_at)
       VALUES (?,?,UTC_TIMESTAMP(6),?,NULL)`, [input.subscriberUsername, input.tokenHash, input.expiresAt]);
    }
    async rotateRefreshToken(input) {
        const connection = await this.pool.getConnection();
        try {
            await connection.beginTransaction();
            const [tokens] = await connection.query(`SELECT id,subscriber_username
           FROM subscriber_refresh_tokens
          WHERE token_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP(6)
          LIMIT 1 FOR UPDATE`, [input.currentHash]);
            const token = tokens[0];
            if (!token) {
                await connection.rollback();
                return null;
            }
            await connection.execute('UPDATE subscriber_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?', [token.id]);
            const subscriber = await this.findAccount(connection, String(token.subscriber_username));
            if (!subscriber) {
                await connection.rollback();
                return null;
            }
            await connection.execute(`INSERT INTO subscriber_refresh_tokens
          (subscriber_username,token_hash,created_at,expires_at,revoked_at)
         VALUES (?,?,UTC_TIMESTAMP(6),?,NULL)`, [subscriber.username, input.replacementHash, input.replacementExpiresAt]);
            await connection.commit();
            return subscriber;
        }
        catch (error) {
            await connection.rollback();
            throw error;
        }
        finally {
            connection.release();
        }
    }
    async revokeRefreshToken(tokenHash) {
        await this.pool.execute(`UPDATE subscriber_refresh_tokens
          SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6))
        WHERE token_hash=?`, [tokenHash]);
    }
    async getProfile(username) {
        const [rows] = await this.pool.query(`SELECT ui.username,COALESCE(ui.status,'active') AS raw_status,
              COALESCE(ui.is_disabled,0) AS is_disabled,
              COALESCE(ui.expiry_date,ui.expires_at) AS expires_at,
              COALESCE(NULLIF(ui.package_name,''),NULLIF(p.name,''),'غير محددة') AS package_name,
              EXISTS(SELECT 1 FROM radusergroup rug
                      WHERE rug.username=ui.username
                        AND rug.groupname='daloRADIUS-Disabled-Users') AS is_in_disabled_group
         FROM userinfo ui
         LEFT JOIN packages p ON p.id=ui.package_id
        WHERE ui.username=? LIMIT 1`, [username]);
        const row = rows[0];
        return row
            ? {
                username: String(row.username),
                rawStatus: String(row.raw_status),
                isDisabled: Boolean(row.is_disabled),
                isInDisabledGroup: Boolean(row.is_in_disabled_group),
                expiresAt: asDate(row.expires_at),
                packageName: String(row.package_name),
            }
            : null;
    }
    async getDashboard(username) {
        const [rows] = await this.pool.query(`SELECT ui.id,ui.username,COALESCE(ui.status,'active') AS raw_status,
              COALESCE(ui.is_disabled,0) AS is_disabled,
              COALESCE(ui.expiry_date,ui.expires_at) AS expires_at,
              ui.package_id,
              COALESCE(NULLIF(ui.package_name,''),NULLIF(p.name,''),'غير محددة') AS package_name,
              GREATEST(COALESCE(ui.total_quota,0),COALESCE(ui.byte_limit,0),
                       COALESCE(ui.total_limit,0)) AS total_bytes,
              COALESCE(ui.used_quota,0) AS used_bytes,
              COALESCE(ui.activated_at,ui.first_login_at,ui.creationdate) AS started_at,
              (SELECT COUNT(DISTINCT NULLIF(ra.callingstationid,''))
                 FROM radacct ra USE INDEX (username)
                WHERE ra.username=ui.username
                  AND ra.acctstoptime IS NULL
                  AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE) AS active_device_count,
              EXISTS(SELECT 1 FROM radacct active_ra USE INDEX (username)
                      WHERE active_ra.username=ui.username
                        AND active_ra.acctstoptime IS NULL
                        AND COALESCE(active_ra.acctupdatetime,active_ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
                      LIMIT 1) AS is_connected,
              EXISTS(SELECT 1 FROM radusergroup rug
                      WHERE rug.username=ui.username
                        AND rug.groupname='daloRADIUS-Disabled-Users') AS is_in_disabled_group
         FROM userinfo ui
         LEFT JOIN packages p ON p.id=ui.package_id
        WHERE ui.username=? LIMIT 1`, [username]);
        const row = rows[0];
        if (!row)
            return null;
        // userinfo is the canonical live balance. Recharge and settlement flows
        // persist the accumulated quota and usage here. A package audit entry only
        // describes the base package and must not replace a later/top-up balance.
        const totalBytes = asBigInt(row.total_bytes, 'total_bytes');
        const usedBytes = asBigInt(row.used_bytes, 'used_bytes');
        return {
            id: String(row.id),
            username: String(row.username),
            rawStatus: String(row.raw_status),
            isDisabled: Boolean(row.is_disabled),
            isInDisabledGroup: Boolean(row.is_in_disabled_group),
            expiresAt: asDate(row.expires_at),
            packageId: row.package_id === null ? null : String(row.package_id),
            packageName: String(row.package_name),
            totalBytes,
            usedBytes,
            startedAt: asDate(row.started_at),
            activeDeviceCount: Number(row.active_device_count ?? 0),
            isConnected: Boolean(row.is_connected),
        };
    }
    async getUsageSummary(username, ranges) {
        const from = [ranges.weekFrom, ranges.monthFrom].reduce((earliest, date) => (date < earliest ? date : earliest), ranges.yesterday);
        const records = await this.getDailyUsage(username, from, ranges.today);
        let todayDownload = 0n;
        let todayUpload = 0n;
        let yesterdayDownload = 0n;
        let yesterdayUpload = 0n;
        let weekDownload = 0n;
        let weekUpload = 0n;
        let monthDownload = 0n;
        let monthUpload = 0n;
        for (const record of records) {
            if (record.date === ranges.today) {
                todayDownload += record.downloadBytes;
                todayUpload += record.uploadBytes;
            }
            if (record.date === ranges.yesterday) {
                yesterdayDownload += record.downloadBytes;
                yesterdayUpload += record.uploadBytes;
            }
            if (record.date >= ranges.weekFrom) {
                weekDownload += record.downloadBytes;
                weekUpload += record.uploadBytes;
            }
            if (record.date >= ranges.monthFrom) {
                monthDownload += record.downloadBytes;
                monthUpload += record.uploadBytes;
            }
        }
        return {
            today: { downloadBytes: todayDownload, uploadBytes: todayUpload },
            yesterday: { downloadBytes: yesterdayDownload, uploadBytes: yesterdayUpload },
            week: { downloadBytes: weekDownload, uploadBytes: weekUpload },
            month: { downloadBytes: monthDownload, uploadBytes: monthUpload },
        };
    }
    async getDailyUsage(username, from, to) {
        const cycle = await this.getUsageCycle(username);
        if (cycle) {
            const resetDate = cycle.resetAt.toISOString().slice(0, 10);
            if (resetDate > to)
                return [];
            const effectiveStart = resetDate >= from ? cycle.resetAt : `${from} 00:00:00`;
            const [cycleRows] = await this.pool.query(`SELECT DATE_FORMAT(DATE(acctstarttime),'%Y-%m-%d') AS usage_date,
                COALESCE(SUM(COALESCE(output_octets64,acctoutputoctets,0)),0) AS download_bytes,
                COALESCE(SUM(COALESCE(input_octets64,acctinputoctets,0)),0) AS upload_bytes
           FROM radacct USE INDEX (username)
          WHERE username=?
            AND acctstarttime>=?
            AND acctstarttime<DATE_ADD(CONCAT(?,' 00:00:00'),INTERVAL 1 DAY)
          GROUP BY DATE(acctstarttime)
          ORDER BY usage_date`, [username, effectiveStart, to]);
            return cycleRows.map((row) => ({
                date: String(row.usage_date),
                downloadBytes: asBigInt(row.download_bytes, 'download_bytes'),
                uploadBytes: asBigInt(row.upload_bytes, 'upload_bytes'),
            }));
        }
        const [rows] = await this.pool.query(`SELECT usage_date,
              COALESCE(SUM(download_bytes),0) AS download_bytes,
              COALESCE(SUM(upload_bytes),0) AS upload_bytes
         FROM (
           SELECT DATE_FORMAT(d.usage_date,'%Y-%m-%d') AS usage_date,
                  COALESCE(d.download_bytes,0) AS download_bytes,
                  COALESCE(d.upload_bytes,0) AS upload_bytes
             FROM nawa_usage_daily d
            WHERE d.username=? AND d.usage_date BETWEEN ? AND ?
           UNION ALL
           SELECT DATE_FORMAT(DATE(ra.acctstarttime),'%Y-%m-%d') AS usage_date,
                  COALESCE(SUM(COALESCE(ra.output_octets64,ra.acctoutputoctets,0)),0) AS download_bytes,
                  COALESCE(SUM(COALESCE(ra.input_octets64,ra.acctinputoctets,0)),0) AS upload_bytes
             FROM radacct ra USE INDEX (username)
            WHERE ra.username=?
              AND ra.acctstarttime>=CONCAT(?,' 00:00:00')
              AND ra.acctstarttime<DATE_ADD(CONCAT(?,' 00:00:00'),INTERVAL 1 DAY)
              AND NOT EXISTS (
                SELECT 1
                  FROM nawa_usage_daily existing
                 WHERE existing.username=?
                   AND existing.usage_date=DATE(ra.acctstarttime)
              )
            GROUP BY DATE(ra.acctstarttime)
         ) combined_usage
        GROUP BY usage_date
        ORDER BY usage_date`, [username, from, to, username, from, to, username]);
        return rows.map((row) => ({
            date: String(row.usage_date),
            downloadBytes: asBigInt(row.download_bytes, 'download_bytes'),
            uploadBytes: asBigInt(row.upload_bytes, 'upload_bytes'),
        }));
    }
    async getSessions(username, limit) {
        const [rows] = await this.pool.query(`SELECT COALESCE(NULLIF(acctuniqueid,''),NULLIF(acctsessionid,''),CAST(radacctid AS CHAR)) AS session_id,
              acctstarttime,acctstoptime,
              COALESCE(acctsessiontime,TIMESTAMPDIFF(SECOND,acctstarttime,
                COALESCE(acctstoptime,acctupdatetime,UTC_TIMESTAMP()))) AS duration_seconds,
              COALESCE(input_octets64,acctinputoctets,0) AS upload_bytes,
              COALESCE(output_octets64,acctoutputoctets,0) AS download_bytes,
              COALESCE(framedipaddress,'') AS framed_ip,
              COALESCE(callingstationid,'') AS calling_station_id,
              COALESCE(NULLIF(nasipaddress,''),NULLIF(calledstationid,''),'') AS nas_info,
              (acctstoptime IS NULL
                AND COALESCE(acctupdatetime,acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE) AS is_active
         FROM radacct USE INDEX (username)
        WHERE username=?
        ORDER BY acctstarttime DESC,radacctid DESC LIMIT ?`, [username, limit]);
        return rows.map((row) => ({
            sessionId: String(row.session_id),
            startTime: requiredDate(row.acctstarttime, 'acctstarttime'),
            stopTime: asDate(row.acctstoptime),
            durationSeconds: Math.max(0, Number(row.duration_seconds ?? 0)),
            uploadBytes: asBigInt(row.upload_bytes, 'upload_bytes'),
            downloadBytes: asBigInt(row.download_bytes, 'download_bytes'),
            framedIp: String(row.framed_ip),
            callingStationId: String(row.calling_station_id),
            networkIdentifier: String(row.nas_info),
            isActive: Boolean(row.is_active),
        }));
    }
    async getDevices(subscriber, limit) {
        const [rows] = await this.pool.query(`SELECT ra.callingstationid,
              COALESCE(
                MAX(sd.friendly_name),
                (SELECT NULLIF(cd.device_name,'')
                   FROM communication_devices cd
                  WHERE BINARY cd.mac_address=BINARY ra.callingstationid
                    AND NULLIF(cd.device_name,'') IS NOT NULL
                  ORDER BY cd.last_seen DESC,cd.id DESC
                  LIMIT 1)
              ) AS friendly_name,
              COALESCE(MAX(CASE WHEN ra.acctstoptime IS NULL
                    AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
                    THEN ra.framedipaddress END),
                       SUBSTRING_INDEX(GROUP_CONCAT(ra.framedipaddress ORDER BY ra.acctstarttime DESC),',',1),'') AS ip_address,
              COALESCE(MAX(CASE WHEN ra.acctstoptime IS NULL
                    AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
                    THEN ra.acctstarttime END),
                       MAX(ra.acctstarttime)) AS connection_started_at,
              MAX(CASE WHEN ra.acctstoptime IS NULL
                    AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE THEN
                    COALESCE(ra.acctsessiontime,TIMESTAMPDIFF(SECOND,ra.acctstarttime,
                      COALESCE(ra.acctupdatetime,UTC_TIMESTAMP()))) ELSE 0 END) AS duration_seconds,
              MIN(ra.acctstarttime) AS first_seen_at,
              MAX(COALESCE(ra.acctupdatetime,ra.acctstoptime,ra.acctstarttime)) AS last_seen_at,
              SUM(CASE WHEN ra.acctstoptime IS NULL
                    AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE THEN
                    COALESCE(ra.input_octets64,ra.acctinputoctets,0)+
                    COALESCE(ra.output_octets64,ra.acctoutputoctets,0) ELSE 0 END) AS current_bytes,
              MAX(ra.acctstoptime IS NULL
                  AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE) AS is_online
         FROM radacct ra USE INDEX (username)
         LEFT JOIN subscriber_devices sd
           ON sd.username=? AND sd.mac_address=ra.callingstationid
        WHERE ra.username=? AND NULLIF(ra.callingstationid,'') IS NOT NULL
        GROUP BY ra.callingstationid
        ORDER BY last_seen_at DESC LIMIT ?`, [subscriber.username, subscriber.username, limit]);
        return rows.map((row) => this.mapDevice(subscriber.username, row));
    }
    async renameDevice(input) {
        if (!/^[a-f0-9]{64}$/.test(input.deviceId))
            return null;
        const devices = await this.getDevices(input.subscriber, 100);
        const existing = devices.find((device) => device.id === input.deviceId);
        if (!existing)
            return null;
        await this.pool.execute(`INSERT INTO subscriber_devices
        (username,mac_address,friendly_name,created_at,updated_at)
       VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
       ON DUPLICATE KEY UPDATE friendly_name=VALUES(friendly_name),updated_at=UTC_TIMESTAMP(6)`, [input.subscriber.username, existing.callingStationId, input.friendlyName]);
        return { ...existing, friendlyName: input.friendlyName };
    }
    async getRecharges(username, limit) {
        const [dealerRows, auditRows] = await Promise.all([
            this.pool.query(`SELECT public_id,created_at,total_amount_yer,bytes_added,validity_days_granted,
                customer_expiration_after,status
           FROM three_d_net_recharge_transactions
          WHERE customer_username=?
          ORDER BY created_at DESC,id DESC LIMIT ?`, [username, limit]),
            this.pool.query(`SELECT CAST(a.id AS CHAR) AS audit_id,a.created_at,a.action_name,a.details,
                COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.package_name')),''),
                         NULLIF(p.name,''),'تسوية اشتراك') AS package_name,
                COALESCE(
                  NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.amount_yer')) AS DECIMAL(16,2)),0),
                  NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.amount')) AS DECIMAL(16,2)),0),
                  NULLIF(p.sell_price,0),NULLIF(p.price,0),
                  NULLIF(CAST(REGEXP_SUBSTR(
                    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.package_name')),''),p.name,''),
                    '[0-9]+$') AS DECIMAL(16,2)),0)
                ) AS amount,
                CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.bytes_added')),
                              JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.quota_added')),
                              CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.quota_gb')) AS DECIMAL(16,3))*1073741824,
                              0) AS UNSIGNED) AS bytes_added,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.validity_days')) AS UNSIGNED) AS validity_days,
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.expiration_after')),
                         JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.expiration'))) AS expiration_after
           FROM nawa_audit_log a
           LEFT JOIN packages p
             ON p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.package_id')) AS UNSIGNED)
          WHERE a.subject_type='user' AND a.subject_id=?
            AND a.action_name IN ('card.recharge','user.package_settle','user.settle')
          ORDER BY a.created_at DESC,a.id DESC LIMIT ?`, [username, limit]),
        ]);
        const dealer = dealerRows[0].map((row) => ({
            id: String(row.public_id),
            source: 'dealer',
            createdAt: requiredDate(row.created_at, 'created_at'),
            amount: row.total_amount_yer === null ? null : Number(row.total_amount_yer),
            packageName: 'شحن بيانات',
            addedBytes: asBigInt(row.bytes_added, 'bytes_added'),
            validityDays: row.validity_days_granted === null ? null : Number(row.validity_days_granted),
            generatedExpiry: asDate(row.customer_expiration_after),
            status: row.status === 'completed' ? 'successful' : row.status === 'processing' ? 'pending' : 'failed',
        }));
        const audit = auditRows[0].map((row) => ({
            id: String(row.audit_id),
            source: 'operator',
            createdAt: requiredDate(row.created_at, 'created_at'),
            amount: row.amount === null ? null : Number(row.amount),
            packageName: String(row.package_name),
            addedBytes: asBigInt(row.bytes_added, 'bytes_added'),
            validityDays: row.validity_days === null ? null : Number(row.validity_days),
            generatedExpiry: asDate(row.expiration_after),
            status: 'successful',
        }));
        return [...dealer, ...audit]
            .sort((left, right) => right.createdAt.getTime() - left.createdAt.getTime())
            .slice(0, limit);
    }
    async upsertDerivedNotifications(input) {
        for (const notification of input.notifications) {
            await this.pool.execute(`INSERT INTO subscriber_notifications
          (username,type,title,message,is_read,created_at)
         SELECT ?,?,?,?,0,UTC_TIMESTAMP(6)
          WHERE NOT EXISTS (
            SELECT 1 FROM subscriber_notifications
             WHERE username=? AND type=? AND title=?
               AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 1 DAY
          )`, [
                input.username,
                notification.type,
                notification.title,
                notification.body,
                input.username,
                notification.type,
                notification.title,
            ]);
        }
    }
    async getNotifications(username, limit) {
        const [rows] = await this.pool.query(`SELECT id,type,title,message,link_title,link_url,is_read,created_at
         FROM subscriber_notifications
        WHERE username=? ORDER BY created_at DESC,id DESC LIMIT ?`, [username, limit]);
        return rows.map((row) => ({
            id: String(row.id),
            type: String(row.type),
            title: String(row.title),
            body: String(row.message),
            linkTitle: row.link_title === null ? null : String(row.link_title),
            linkUrl: row.link_url === null ? null : String(row.link_url),
            isRead: Boolean(row.is_read),
            createdAt: requiredDate(row.created_at, 'created_at'),
        }));
    }
    async markNotificationRead(username, notificationId) {
        if (!/^\d{1,20}$/.test(notificationId))
            return false;
        const [result] = await this.pool.execute('UPDATE subscriber_notifications SET is_read=1 WHERE id=? AND username=?', [notificationId, username]);
        return result.affectedRows === 1;
    }
    async markAllNotificationsRead(username) {
        const [result] = await this.pool.execute('UPDATE subscriber_notifications SET is_read=1 WHERE username=? AND is_read=0', [username]);
        return result.affectedRows;
    }
    async registerPushToken(input) {
        await this.pool.execute(`INSERT INTO subscriber_push_tokens (username,token,platform)
       VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE username=VALUES(username), platform=VALUES(platform), updated_at=UTC_TIMESTAMP(6)`, [input.username, input.token, input.platform]);
    }
    async getSpeedSelection(username) {
        const [rows] = await this.pool.execute(`SELECT value FROM radreply WHERE username=? AND attribute='Mikrotik-Rate-Limit'
       ORDER BY id DESC LIMIT 1`, [username]);
        const value = rows[0]?.value;
        return typeof value === 'string' && value !== '' ? value : 'open';
    }
    async setSpeedSelection(input) {
        const connection = await this.pool.getConnection();
        try {
            await connection.beginTransaction();
            await connection.execute("DELETE FROM radreply WHERE username=? AND attribute='Mikrotik-Rate-Limit'", [input.username]);
            if (input.selection !== 'open') {
                await connection.execute("INSERT INTO radreply (username,attribute,op,value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)", [input.username, input.selection]);
            }
            await connection.commit();
        }
        catch (error) {
            await connection.rollback();
            throw error;
        }
        finally {
            connection.release();
        }
    }
    async findAccount(connection, username) {
        const [rows] = await connection.query(accountSelect, [username]);
        return rows[0] ? account(rows[0]) : null;
    }
    mapDevice(username, row) {
        const callingStationId = String(row.callingstationid);
        return {
            id: deviceId(username, callingStationId),
            friendlyName: row.friendly_name === null ? null : String(row.friendly_name),
            callingStationId,
            ipAddress: String(row.ip_address ?? ''),
            connectionStartedAt: requiredDate(row.connection_started_at, 'connection_started_at'),
            sessionDurationSeconds: Math.max(0, Number(row.duration_seconds ?? 0)),
            firstSeenAt: requiredDate(row.first_seen_at, 'first_seen_at'),
            lastSeenAt: requiredDate(row.last_seen_at, 'last_seen_at'),
            currentSessionBytes: asBigInt(row.current_bytes, 'current_bytes'),
            isOnline: Boolean(row.is_online),
        };
    }
}
//# sourceMappingURL=mysql-subscriber-repository.js.map