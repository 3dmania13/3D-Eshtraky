import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Pool } from 'mysql2/promise';
import type { SubscriberRepository } from '../src/domain/contracts.js';
import { MySqlLiveSpeedApplier, type CoaRequest, type LiveSpeedResult } from '../src/speed/live-speed-service.js';
import { SpeedService } from '../src/speed/speed-service.js';
import { MySqlLiveDeviceSpeedApplier } from '../src/devices/live-device-speed-service.js';
import { DeviceSpeedService } from '../src/devices/device-speed-service.js';

function poolWith(...results: object[][]): Pool {
  return { execute: async () => [results.shift() ?? []] } as unknown as Pool;
}
const session = { nasipaddress: '33.3.3.44', acctsessionid: 'abc123', framedipaddress: '10.0.0.5' };
const router = { nasname: '192.168.230.217', shortname: 'network4', secret: 'test-only' };

test('live speed targets the exact session and resolves legacy NAS aliases', async () => {
  const sent: CoaRequest[] = [];
  const live = new MySqlLiveSpeedApplier(poolWith([session], [router]), async (r) => { sent.push(r); return true; });
  assert.deepEqual(await live.apply('alice', '2M/2M'), { status: 'applied', active_sessions: 1, updated_sessions: 1 });
  assert.deepEqual(sent[0], { host: router.nasname, port: 3799, secret: 'test-only', username: 'alice', sessionId: 'abc123', address: '10.0.0.5', rate: '2M/2M' });
});

test('open restores package group rate instead of removing the package cap', async () => {
  let rate = '';
  const live = new MySqlLiveSpeedApplier(poolWith([session], [{ value: '1M/4M' }], [router]), async (r) => { rate = r.rate; return true; });
  assert.equal((await live.apply('alice', 'open')).status, 'applied');
  assert.equal(rate, '1M/4M');
});

test('ambiguous open policy and unknown routers cannot report immediate success', async () => {
  const neverSend = async () => { assert.fail('must not send an unscoped update'); };
  assert.equal((await new MySqlLiveSpeedApplier(poolWith([session], [{ value: '1M/4M' }, { value: '2M/4M' }]), neverSend).apply('alice', 'open')).status, 'pending');
  assert.equal((await new MySqlLiveSpeedApplier(poolWith([session], []), neverSend).apply('alice', '1M/1M')).status, 'pending');
});

test('open handles uncapped packages without group rates and refuses unknown plans', async () => {
  let rate = '';
  const live = new MySqlLiveSpeedApplier(poolWith([session], [], [{ rate_limit: '', upload_speed: '0', download_speed: '0' }], [router]), async (r) => { rate = r.rate; return true; });
  assert.equal((await live.apply('alice', 'open')).status, 'applied');
  assert.equal(rate, '0/0');
  const unknown = new MySqlLiveSpeedApplier(poolWith([session], [], []), async () => assert.fail());
  assert.equal((await unknown.apply('alice', 'open')).status, 'pending');
});

test('NAK, timeout and partial acknowledgements remain pending', async () => {
  let call = 0;
  const live = new MySqlLiveSpeedApplier(poolWith([session, { ...session, acctsessionid: 'other' }], [router]), async () => ++call === 1);
  assert.deepEqual(await live.apply('alice', '1M/1M'), { status: 'pending', active_sessions: 2, updated_sessions: 1 });
  const failed = new MySqlLiveSpeedApplier(poolWith([session], [router]), async () => { throw new Error('timeout'); });
  assert.equal((await failed.apply('alice', '1M/1M')).status, 'pending');
});

test('offline subscribers do not send router commands', async () => {
  const live = new MySqlLiveSpeedApplier(poolWith([]), async () => assert.fail());
  assert.deepEqual(await live.apply('alice', 'open'), { status: 'offline', active_sessions: 0, updated_sessions: 0 });
});

test('save precedes live update and simultaneous writes for one user are rejected', async () => {
  const order: string[] = [];
  let finish!: (result: LiveSpeedResult) => void;
  const repository = {
    setSpeedSelection: async () => {
      order.push('saved');
    },
    invalidateDeviceSpeedApplications: async () => {},
  } as unknown as SubscriberRepository;
  const service = new SpeedService(repository, { apply: async () => { order.push('live'); return new Promise((resolve) => { finish = resolve; }); } });
  const principal = { username: 'alice', status: 'active' as const };
  const pending = service.set(principal, '2M');
  await new Promise((resolve) => setImmediate(resolve));
  await assert.rejects(service.set(principal, '3M'), { code: 'SPEED_UPDATE_IN_PROGRESS' });
  finish({ status: 'applied', active_sessions: 1, updated_sessions: 1 });
  const result = await pending;
  assert.deepEqual(order, ['saved', 'live']);
  assert.equal(result.applied_immediately, true);
  assert.equal(result.applies_on_next_connection, false);
});

test('live failure preserves saved selection and never claims immediate success', async () => {
  const repository = {
    setSpeedSelection: async () => {},
    invalidateDeviceSpeedApplications: async () => {},
  } as unknown as SubscriberRepository;
  const service = new SpeedService(repository, { apply: async () => { throw new Error('database unavailable'); } });
  const result = await service.set({ username: 'alice', status: 'active' }, '2M');
  assert.equal(result.applied_immediately, false);
  assert.equal(result.status, 'pending');
});

test('device speed limits target only the selected physical device session', async () => {
  const sent: CoaRequest[] = [];
  const deviceSession = {
    ...session,
    callingstationid: 'AA:BB:CC:DD:EE:FF',
  };
  const live = new MySqlLiveDeviceSpeedApplier(
    poolWith([deviceSession], [router]),
    async (request) => {
      sent.push(request);
      return true;
    },
  );
  assert.deepEqual(
    await live.apply('alice', deviceSession.callingstationid, '2M/2M'),
    { status: 'applied', activeSessions: 1, updatedSessions: 1 },
  );
  assert.deepEqual(sent[0], {
    host: router.nasname,
    port: 3799,
    secret: router.secret,
    username: 'alice',
    sessionId: deviceSession.acctsessionid,
    address: deviceSession.framedipaddress,
    rate: '2M/2M',
  });
});

test('device speed saves its rule before applying it to the current session', async () => {
  const order: string[] = [];
  const device = {
    id: 'a'.repeat(64),
    friendlyName: null,
    speedSelection: null,
    callingStationId: 'AA:BB:CC:DD:EE:FF',
    ipAddress: '10.0.0.5',
    connectionStartedAt: new Date(),
    sessionDurationSeconds: 1,
    firstSeenAt: new Date(),
    lastSeenAt: new Date(),
    currentSessionBytes: 0n,
    isOnline: true,
  };
  const repository = {
    setDeviceSpeed: async () => {
      order.push('saved');
      return device;
    },
  } as unknown as SubscriberRepository;
  const service = new DeviceSpeedService(repository, {
    apply: async (_username, _mac, rate) => {
      order.push(rate);
      return { status: 'applied', activeSessions: 1, updatedSessions: 1 };
    },
  });
  const result = await service.set(
    { username: 'alice', status: 'active' },
    device.id,
    '5M',
  );
  assert.deepEqual(order, ['saved', '5M/5M']);
  assert.equal(result.applied_immediately, true);
});
