import { createHash } from 'node:crypto';
export const defaultPushPreferences = {
    subscriptionAlerts: true,
    deviceAlerts: true,
    systemMessages: true,
};
export async function enqueueNotification(connection, key, username, message) {
    const [event] = await connection.execute('INSERT IGNORE INTO subscriber_push_events(event_key) VALUES (?)', [key]);
    if (!event.affectedRows)
        return false;
    const [notification] = await connection.execute(`INSERT INTO subscriber_notifications
      (username,type,title,message,link_title,link_url,is_read,created_at)
      VALUES (?,?,?,?,?,?,0,UTC_TIMESTAMP(6))`, [
        username,
        message.type,
        message.title,
        message.body,
        message.linkTitle ?? null,
        message.linkUrl ?? null,
    ]);
    await connection.execute('UPDATE subscriber_push_events SET notification_id=? WHERE event_key=?', [notification.insertId, key]);
    await connection.execute(`INSERT INTO subscriber_push_outbox(notification_id,installation_id,generation,username)
     SELECT ?,installation_id,generation,username FROM subscriber_push_devices
     WHERE username=? AND active=1 AND updated_at>UTC_TIMESTAMP()-INTERVAL 30 DAY
       AND CASE
         WHEN ? IN ('systemMessage','broadcast') THEN system_messages_enabled=1
         WHEN ? IN ('newDevice','unusualDeviceCount') THEN device_alerts_enabled=1
         ELSE subscription_alerts_enabled=1
       END`, [notification.insertId, username, message.type, message.type]);
    return true;
}
export class PushStore {
    pool;
    constructor(pool) {
        this.pool = pool;
    }
    async register(username, installation, token, preferences = defaultPushPreferences) {
        const connection = await this.pool.getConnection();
        try {
            await connection.beginTransaction();
            const hash = createHash('sha256').update(token).digest('hex');
            // A token can belong to only one installation/account at a time.
            await connection.execute('DELETE FROM subscriber_push_devices WHERE token_hash=? AND installation_id<>?', [hash, installation]);
            const [existing] = await connection.execute('SELECT username,active FROM subscriber_push_devices WHERE installation_id=? FOR UPDATE', [installation]);
            await connection.execute(`INSERT INTO subscriber_push_devices
          (installation_id,username,token,token_hash,subscription_alerts_enabled,device_alerts_enabled,system_messages_enabled)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE generation=generation+IF(username<>VALUES(username) OR active=0,1,0),
         username=VALUES(username),token=VALUES(token),token_hash=VALUES(token_hash),
         subscription_alerts_enabled=VALUES(subscription_alerts_enabled),
         device_alerts_enabled=VALUES(device_alerts_enabled),
         system_messages_enabled=VALUES(system_messages_enabled),active=1,updated_at=UTC_TIMESTAMP(6)`, [installation, username, token, hash,
                preferences.subscriptionAlerts ? 1 : 0,
                preferences.deviceAlerts ? 1 : 0,
                preferences.systemMessages ? 1 : 0]);
            const [known] = await connection.execute('SELECT installation_id FROM subscriber_push_known_devices WHERE username=? LIMIT 1', [username]);
            const [created] = await connection.execute('INSERT IGNORE INTO subscriber_push_known_devices(username,installation_id) VALUES (?,?)', [username, installation]);
            if (created.affectedRows) {
                const first = known.length === 0;
                await enqueueNotification(connection, `device:${createHash('sha256').update(username).digest('hex')}:${installation}`, username, {
                    type: first ? 'systemMessage' : 'newDevice',
                    title: first ? 'تم تفعيل الإشعارات' : 'تسجيل الدخول من جوال جديد',
                    body: first ? 'ستصلك هنا تنبيهات التسديد وتسجيل الدخول من جوال جديد.' : 'تم تسجيل الدخول إلى حسابك من جوال جديد باستخدام رمز الاشتراك.',
                });
            }
            await connection.commit();
            return { registered: true, new_device: created.affectedRows === 1, switched_account: existing[0]?.username !== username };
        }
        catch (error) {
            await connection.rollback();
            throw error;
        }
        finally {
            connection.release();
        }
    }
    async revoke(username, installation) {
        await this.pool.execute('UPDATE subscriber_push_devices SET active=0,generation=generation+1 WHERE installation_id=? AND username=?', [installation, username]);
        return { registered: false };
    }
}
//# sourceMappingURL=push-store.js.map