// Execute from the notifications release with its normal environment loaded.
// The registration is rolled back; no notification is delivered to a device.
import { createPool } from '/opt/3d-subscriber-api/releases/20260922-push/dist/database/pool.js';
import { loadConfig } from '/opt/3d-subscriber-api/releases/20260922-push/dist/config/index.js';
import { PushStore } from '/opt/3d-subscriber-api/releases/20260922-push/dist/notifications/push-store.js';
import { readFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';
import assert from 'node:assert/strict';
const pool = createPool(loadConfig().database);
const connection = await pool.getConnection();
try {
  const [users] = await connection.query('SELECT username FROM nawa_pppoe_users LIMIT 1');
  assert.ok(users.length);
  const installation = randomBytes(16).toString('hex');
  const proxy = new Proxy(connection, { get(target, key) {
    if (key === 'commit' || key === 'release') return async () => {};
    const value = target[key];
    return typeof value === 'function' ? value.bind(target) : value;
  }});
  const result = await new PushStore({ getConnection: async () => proxy }).register(
    users[0].username, installation, 'smoke-test-' + randomBytes(30).toString('hex'));
  assert.equal(result.registered, true);
  const [rows] = await connection.execute(`SELECT subscription_alerts_enabled,device_alerts_enabled,
    system_messages_enabled FROM subscriber_push_devices WHERE installation_id=?`, [installation]);
  assert.equal(Number(rows[0].subscription_alerts_enabled), 1);
  assert.equal(Number(rows[0].device_alerts_enabled), 1);
  assert.equal(Number(rows[0].system_messages_enabled), 1);
  await connection.rollback();
  const [remaining] = await connection.execute('SELECT installation_id FROM subscriber_push_devices WHERE installation_id=?', [installation]);
  assert.equal(remaining.length, 0);
  const source = await readFile('/opt/3d-subscriber-api/releases/20260922-push/dist/notifications/push-worker.js', 'utf8');
  const sql = source.match(/`(SELECT app_users.username,[\s\S]+?)`/)[1].replace('${quotaBatchSize}', '250');
  await connection.query(sql, ['']);
  console.log('Broadband push registration, preferences, rollback and live quota query: OK');
} finally {
  await connection.rollback();
  connection.release();
  await pool.end();
}
