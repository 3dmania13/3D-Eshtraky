import { createHash } from "node:crypto";
import { PushSendError } from "./fcm-sender.js";
import { enqueueNotification } from "./push-store.js";
const oneGib = 1024n ** 3n;
const quotaScanCursor = "quota_scan_cursor";
const quotaBatchSize = 250;
const deliveryConcurrency = 8;
const maximumDeliveriesPerTick = 64;
function quotaBytes(value) {
    try {
        const parsed = BigInt(String(value ?? 0));
        return parsed < 0n ? 0n : parsed;
    }
    catch {
        return 0n;
    }
}
export class PushWorker {
    pool;
    sender;
    constructor(pool, sender) {
        this.pool = pool;
        this.sender = sender;
    }
    async initialize() {
        // The initial boundary is permanent. Do not advance it past transactions
        // that allocated IDs but have not committed yet. Event keys provide dedupe.
        await this.pool
            .execute(`INSERT IGNORE INTO subscriber_push_state(name,value)
      SELECT 'audit_floor',COALESCE(MAX(id),0)+1 FROM nawa_audit_log`);
        await this.pool
            .execute(`INSERT IGNORE INTO subscriber_push_state(name,value)
      SELECT 'dealer_floor',COALESCE(MIN(CASE WHEN status='processing' THEN id END),COALESCE(MAX(id),0)+1)
      FROM three_d_net_recharge_transactions`);
        await this.pool.execute("INSERT IGNORE INTO subscriber_push_state(name,value) VALUES ('quota_scan_cursor','')");
    }
    async tick() {
        const c = await this.pool.getConnection();
        let locked = false;
        try {
            const [lock] = await c.query("SELECT GET_LOCK(CONCAT('subscriber-push:',DATABASE()),0) AS acquired");
            locked = Number(lock[0]?.acquired) === 1;
            if (!locked)
                return;
            await this.deliverAvailable(deliveryConcurrency);
            await this.collect(c);
            await this.collectLowQuotaWarnings(c);
            await this.collectBroadcasts(c);
            await this.collectFeedbackStatusNotifications(c);
            await this.collectNewDeviceConnections(c);
            await this.deliverAvailable(maximumDeliveriesPerTick);
        }
        finally {
            if (locked)
                await c.query("SELECT RELEASE_LOCK(CONCAT('subscriber-push:',DATABASE()))");
            c.release();
        }
    }
    /**
     * Checks registered app subscribers in bounded pages. The alert state is
     * re-armed only after the balance rises above one GiB, so a subscriber gets
     * one alert as they cross the threshold in each recharge cycle.
     */
    async collectLowQuotaWarnings(c) {
        const [cursorRows] = await c.execute("SELECT value FROM subscriber_push_state WHERE name=? LIMIT 1", [quotaScanCursor]);
        const cursor = String(cursorRows[0]?.value ?? "");
        const [rows] = await c.query(`SELECT app_users.username,
              GREATEST(COALESCE(ui.total_quota,0),COALESCE(ui.byte_limit,0),COALESCE(ui.total_limit,0)) AS total_bytes,
              COALESCE(ui.used_quota,0) AS used_bytes
         FROM (
           SELECT username
             FROM subscriber_push_devices USE INDEX (user_active)
            WHERE active=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 30 DAY
              AND username>?
            GROUP BY username
            ORDER BY username
            LIMIT ${quotaBatchSize}
         ) AS app_users
         JOIN userinfo ui
           ON ui.username=CONVERT(app_users.username USING utf8mb4) COLLATE utf8mb4_general_ci
        WHERE COALESCE(ui.is_disabled,0)=0 AND COALESCE(ui.status,'active')='active'
        ORDER BY app_users.username`, [cursor]);
        if (rows.length === 0) {
            if (cursor) {
                await c.execute("UPDATE subscriber_push_state SET value='' WHERE name=?", [quotaScanCursor]);
            }
            return;
        }
        for (const row of rows) {
            const username = String(row.username);
            const total = quotaBytes(row.total_bytes);
            const used = quotaBytes(row.used_bytes);
            const remaining = used >= total ? 0n : total - used;
            if (total === 0n || remaining > oneGib || remaining === 0n) {
                await c.execute("UPDATE subscriber_quota_alert_state SET is_low=0 WHERE username=? AND is_low=1", [username]);
                continue;
            }
            await c.beginTransaction();
            try {
                const [states] = await c.execute("SELECT is_low,generation FROM subscriber_quota_alert_state WHERE username=? FOR UPDATE", [username]);
                const state = states[0];
                let generation = Number(state?.generation ?? 0);
                let crossedThreshold = false;
                if (!state) {
                    generation = 1;
                    crossedThreshold = true;
                    await c.execute("INSERT INTO subscriber_quota_alert_state(username,is_low,generation,updated_at) VALUES (?,1,?,UTC_TIMESTAMP(6))", [username, generation]);
                }
                else if (!Number(state.is_low)) {
                    generation += 1;
                    crossedThreshold = true;
                    await c.execute("UPDATE subscriber_quota_alert_state SET is_low=1,generation=?,updated_at=UTC_TIMESTAMP(6) WHERE username=?", [generation, username]);
                }
                if (crossedThreshold) {
                    const key = `quota-low:${createHash("sha256").update(username).digest("hex")}:${generation}`;
                    await enqueueNotification(c, key, username, {
                        type: "lowBalance",
                        title: "رصيدك على وشك الانتهاء",
                        body: "لديك أقل من 1 جيجا من رصيد البيانات. اشحن رصيدك لتجنب انقطاع الخدمة.",
                    });
                }
                await c.commit();
            }
            catch (error) {
                await c.rollback();
                throw error;
            }
        }
        const lastUsername = String(rows.at(-1)?.username ?? "");
        await c.execute("UPDATE subscriber_push_state SET value=? WHERE name=?", [
            rows.length < quotaBatchSize ? "" : lastUsername,
            quotaScanCursor,
        ]);
    }
    async collectFeedbackStatusNotifications(c) {
        await c.beginTransaction();
        try {
            const [rows] = await c.query(`SELECT f.id,f.username,f.network_name,f.status
        FROM subscriber_feedback f
        WHERE f.status IN ('reviewed','resolved')
        AND NOT EXISTS (SELECT 1 FROM subscriber_push_events e
          WHERE e.event_key=CONCAT('feedback-',f.status,':',f.id))
        ORDER BY f.reviewed_at,f.id LIMIT 50 FOR UPDATE`);
            for (const row of rows) {
                const id = Number(row.id);
                const network = String(row.network_name || "الشبكة");
                const status = String(row.status);
                const resolved = status === "resolved";
                await enqueueNotification(c, `feedback-${status}:${id}`, String(row.username), resolved
                    ? {
                        type: "systemMessage",
                        title: "تم حل مشكلة الشبكة",
                        body: `تم حل مشكلة شبكة ${network}. نعتذر عن التأخر، وشكرًا لتواصلك معنا.`,
                    }
                    : {
                        type: "systemMessage",
                        title: "مشكلتك قيد المراجعة",
                        body: `تم استلام مشكلة شبكة ${network} وهي الآن قيد المراجعة. سنشعرك عند حلها.`,
                    });
                if (resolved) {
                    await c.execute("UPDATE subscriber_feedback SET resolution_notified_at=UTC_TIMESTAMP(6) WHERE id=?", [id]);
                }
            }
            await c.commit();
        }
        catch (error) {
            await c.rollback();
            throw error;
        }
    }
    /** Sends one alert for each newly seen physical device on an app subscriber's
     * account. A fresh RADIUS accounting update is required, which excludes the
     * stale open sessions that can remain after a router loses its stop record. */
    async collectNewDeviceConnections(c) {
        const [rows] = await c.query(`SELECT ra.username,ra.callingstationid,
        COALESCE((SELECT NULLIF(cd.device_name,'') FROM communication_devices cd
          WHERE BINARY cd.mac_address=BINARY ra.callingstationid AND NULLIF(cd.device_name,'') IS NOT NULL
          ORDER BY cd.last_seen DESC,cd.id DESC LIMIT 1),'') AS device_name
      FROM subscriber_push_devices d
      JOIN radacct ra USE INDEX (idx_radacct_active_user)
        ON ra.username=CONVERT(d.username USING utf8mb4) COLLATE utf8mb4_general_ci
      WHERE d.active=1
        AND d.updated_at>UTC_TIMESTAMP()-INTERVAL 30 DAY
        AND ra.acctstoptime IS NULL
        AND NULLIF(ra.callingstationid,'') IS NOT NULL
        AND ra.acctstarttime>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
        AND COALESCE(ra.acctupdatetime,ra.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
      GROUP BY ra.username,ra.callingstationid
      ORDER BY MAX(ra.acctstarttime) LIMIT 50`);
        for (const row of rows) {
            const username = String(row.username);
            const mac = String(row.callingstationid);
            const deviceName = String(row.device_name || "").trim();
            const key = `radius-device:${createHash("sha256")
                .update(username)
                .update("\0")
                .update(mac)
                .digest("hex")}`;
            await c.beginTransaction();
            try {
                await enqueueNotification(c, key, username, {
                    type: "newDevice",
                    title: "جهاز جديد اتصل بالاشتراك",
                    body: deviceName
                        ? `تم اتصال ${deviceName} باشتراكك.`
                        : `تم اتصال جهاز جديد باشتراكك (${mac}).`,
                });
                await c.commit();
            }
            catch (error) {
                await c.rollback();
                throw error;
            }
        }
    }
    /** Queues a bounded part of an operator-created broadcast on each poll.
     * Recipient rows are snapshotted by the admin page, so a network reassignment
     * after pressing Send cannot change who receives the message. */
    async collectBroadcasts(c) {
        await c.beginTransaction();
        try {
            const [broadcasts] = await c.query(`SELECT id,title,message,link_title,link_url
        FROM subscriber_broadcasts
        WHERE status='queued' OR (status='processing' AND processing_at<UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE)
        ORDER BY created_at,id LIMIT 1 FOR UPDATE`);
            const broadcast = broadcasts[0];
            if (!broadcast) {
                await c.commit();
                return;
            }
            await c.execute("UPDATE subscriber_broadcasts SET status='processing',processing_at=UTC_TIMESTAMP(6) WHERE id=?", [broadcast.id]);
            const [recipients] = await c.query(`SELECT username FROM subscriber_broadcast_recipients
        WHERE broadcast_id=? AND status='pending' ORDER BY username LIMIT 100 FOR UPDATE`, [broadcast.id]);
            for (const recipient of recipients) {
                const username = String(recipient.username);
                await enqueueNotification(c, `broadcast:${broadcast.id}:${username}`, username, {
                    type: "broadcast",
                    title: String(broadcast.title),
                    body: String(broadcast.message),
                    ...(broadcast.link_title
                        ? { linkTitle: String(broadcast.link_title) }
                        : {}),
                    ...(broadcast.link_url ? { linkUrl: String(broadcast.link_url) } : {}),
                });
                await c.execute("UPDATE subscriber_broadcast_recipients SET status='queued',queued_at=UTC_TIMESTAMP(6) WHERE broadcast_id=? AND username=?", [broadcast.id, username]);
            }
            const [remaining] = await c.query(`SELECT COUNT(*) AS total FROM subscriber_broadcast_recipients
        WHERE broadcast_id=? AND status='pending'`, [broadcast.id]);
            if (Number(remaining[0]?.total) === 0) {
                await c.execute("UPDATE subscriber_broadcasts SET status='completed',completed_at=UTC_TIMESTAMP(6) WHERE id=?", [broadcast.id]);
            }
            await c.commit();
        }
        catch (error) {
            await c.rollback();
            throw error;
        }
    }
    async collect(c) {
        const [audits] = await c.query(`SELECT a.id,a.subject_id AS username
      FROM nawa_audit_log a WHERE a.id >= CAST((SELECT value FROM subscriber_push_state WHERE name='audit_floor') AS UNSIGNED)
      AND a.subject_type='user' AND CHAR_LENGTH(a.subject_id) BETWEEN 1 AND 64
      AND a.action_name IN ('card.recharge','user.package_settle','user.settle')
      AND NOT EXISTS (SELECT 1 FROM subscriber_push_events e WHERE e.event_key=CONCAT('recharge:audit:',a.id))
      ORDER BY a.id LIMIT 100`);
        const [dealer] = await c.query(`SELECT t.id,t.customer_username AS username
      FROM three_d_net_recharge_transactions t WHERE t.id >= CAST((SELECT value FROM subscriber_push_state WHERE name='dealer_floor') AS UNSIGNED)
      AND t.status='completed'
      AND NOT EXISTS (SELECT 1 FROM subscriber_push_events e WHERE e.event_key=CONCAT('recharge:dealer:',t.id))
      ORDER BY t.id LIMIT 100`);
        for (const [source, rows] of [
            ["audit", audits],
            ["dealer", dealer],
        ]) {
            for (const row of rows) {
                await c.beginTransaction();
                try {
                    await enqueueNotification(c, `recharge:${source}:${row.id}`, String(row.username), {
                        type: "rechargeSuccessful",
                        title: "تم تسديد اشتراكك بنجاح",
                        body: "تم تحديث رصيد اشتراكك. افتح التطبيق لمراجعة الرصيد وتفاصيل العملية.",
                    });
                    await c.commit();
                }
                catch (error) {
                    await c.rollback();
                    throw error;
                }
            }
        }
    }
    /**
     * Sends several independent devices in parallel. Each delivery retains its
     * own row lock while calling FCM, so an account switch or device revocation
     * cannot make a queued message cross from one subscriber to another.
     */
    async deliverAvailable(limit) {
        let delivered = 0;
        while (delivered < limit) {
            const slots = Math.min(deliveryConcurrency, limit - delivered);
            const results = await Promise.allSettled(Array.from({ length: slots }, () => this.deliverOne()));
            const failure = results.find((result) => result.status === "rejected");
            if (failure)
                throw failure.reason;
            const claimed = results.filter((result) => result.status === "fulfilled" && result.value).length;
            delivered += claimed;
            if (claimed < slots)
                return;
        }
    }
    async deliverOne() {
        const c = await this.pool.getConnection();
        await c.beginTransaction();
        try {
            const [rows] = await c.query(`SELECT o.*,n.type,n.title,n.message,n.link_title,n.link_url,n.created_at
        FROM subscriber_push_outbox o JOIN subscriber_notifications n ON n.id=o.notification_id
        WHERE o.status='pending' AND o.next_attempt_at<=UTC_TIMESTAMP(6)
        ORDER BY (n.type='broadcast') ASC,o.id DESC LIMIT 1 FOR UPDATE SKIP LOCKED`);
            const row = rows[0];
            if (!row) {
                await c.commit();
                return false;
            }
            // Hold the installation row through sending: account switches/revocation
            // cannot race an in-flight send to the previous account's installation.
            const [devices] = await c.execute(`SELECT token FROM subscriber_push_devices WHERE installation_id=? AND username=? AND generation=?
         AND active=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 30 DAY FOR UPDATE`, [row.installation_id, row.username, row.generation]);
            if (!devices[0] ||
                Date.now() - new Date(row.created_at).getTime() > 86_400_000) {
                await c.execute("UPDATE subscriber_push_outbox SET status='skipped' WHERE id=?", [row.id]);
            }
            else {
                try {
                    await this.sender.send(String(devices[0].token), {
                        id: String(row.notification_id),
                        type: String(row.type),
                        title: String(row.title),
                        body: String(row.message),
                        ...(row.link_title ? { linkTitle: String(row.link_title) } : {}),
                        ...(row.link_url ? { linkUrl: String(row.link_url) } : {}),
                    });
                    await c.execute("UPDATE subscriber_push_outbox SET status='sent',attempts=attempts+1,sent_at=UTC_TIMESTAMP(6),last_error=NULL WHERE id=?", [row.id]);
                }
                catch (error) {
                    const attempts = Number(row.attempts) + 1;
                    const failed = (error instanceof PushSendError && error.permanent) ||
                        attempts >= 8;
                    const reason = error instanceof PushSendError
                        ? error.code
                        : "NETWORK_OR_CREDENTIAL_ERROR";
                    await c.execute(`UPDATE subscriber_push_outbox SET status=?,attempts=?,last_error=?,
            next_attempt_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE id=?`, [
                        failed ? "failed" : "pending",
                        attempts,
                        reason,
                        Math.min(3600, 15 * 2 ** attempts),
                        row.id,
                    ]);
                    if (error instanceof PushSendError && error.invalidToken) {
                        await c.execute("UPDATE subscriber_push_devices SET active=0 WHERE installation_id=? AND generation=?", [row.installation_id, row.generation]);
                    }
                }
            }
            await c.commit();
            return true;
        }
        catch (error) {
            await c.rollback();
            throw error;
        }
        finally {
            c.release();
        }
    }
}
//# sourceMappingURL=push-worker.js.map