import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Pool, PoolConnection } from 'mysql2/promise';
import type { SubscriberRepository } from '../src/domain/contracts.js';
import { PushWorker } from '../src/notifications/push-worker.js';
import { NotificationService } from '../src/notifications/notification-service.js';
import { QuotaService } from '../src/services/quota-service.js';
import { SubscriptionService } from '../src/services/subscription-service.js';

test('reconnects and concurrent sessions notify once per account and canonical device, even after absence', async () => {
  const keys = new Set<string>();
  let inserted = 0;
  let username = 'alice';
  let mac = 'aa:bb:cc:dd:ee:ff';
  let identity = 'AABBCCDDEEFF';
  const connection = {
    query: async () => [[{ username, callingstationid: mac, device_identity: identity }]],
    beginTransaction: async () => {}, commit: async () => {}, rollback: async () => {},
    execute: async (sql: string, parameters: unknown[]) => {
      if (sql.startsWith('INSERT IGNORE INTO subscriber_push_events')) {
        const key = String(parameters[0]);
        const fresh = !keys.has(key);
        keys.add(key);
        return [{ affectedRows: fresh ? 1 : 0 }];
      }
      if (sql.includes('INSERT INTO subscriber_notifications')) inserted++;
      return [{ insertId: inserted, affectedRows: 1 }];
    },
  } as unknown as PoolConnection;
  const worker = new PushWorker({} as Pool, { send: async () => {} });
  const collect = () => worker['collectNewDeviceConnections'](connection);
  await collect();
  await collect();
  mac = 'AA-BB-CC-DD-EE-FF';
  await collect();
  assert.equal(inserted, 1);
  // A worker restart or removal from the visible list cannot clear event keys.
  await new PushWorker({} as Pool, { send: async () => {} })['collectNewDeviceConnections'](connection);
  assert.equal(inserted, 1);
  username = 'bob';
  await collect();
  assert.equal(inserted, 2);
  identity = '112233445566';
  await collect();
  assert.equal(inserted, 3);
});

test('reading notifications never creates a second physical-device alert', async () => {
  const repository = {
    getDashboard: async () => null,
    getDevices: async () => { throw new Error('Device discovery belongs to the worker'); },
    upsertDerivedNotifications: async ({ notifications }: { notifications: unknown[] }) => {
      assert.deepEqual(notifications, []);
    },
    getNotifications: async () => [],
  } as unknown as SubscriberRepository;
  const service = new NotificationService(repository, new SubscriptionService(), new QuotaService());
  await service.list({ username: 'alice' } as never);
  await service.list({ username: 'alice' } as never);
});
