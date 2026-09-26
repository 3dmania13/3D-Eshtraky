import assert from 'node:assert/strict';
import test from 'node:test';
import Fastify from 'fastify';
import jwt from '@fastify/jwt';
import rateLimit from '@fastify/rate-limit';
import type { Pool } from 'mysql2/promise';
import { registerBroadbandRoutes } from '../src/broadband/broadband-routes.js';
import { requireSubscriber } from '../src/middleware/subscriber-auth.js';
import { AppError } from '../src/errors.js';

async function fixture() {
  const updates: unknown[][] = [];
  const pushWrites: unknown[][] = [];
  const tokens = new Map<string, { id: string; subscriber_username: string }>();
  const row = { username: 'broadband1', full_name: 'Test', mobilephone: '', address: '',
    status: 'active', radius_enabled: 1, total_quota: '10737418240', used_quota: '1073741824',
    expires_at: '2099-01-01 00:00:00', activated_at: null, package_name: 'Fiber',
    upload_speed: '30M', download_speed: '200M', rate_limit: '30M/200M',
    cleartext_password: 'must-never-be-returned' };
  const pool = { execute: async (sql: string, values: unknown[]) => {
    if (sql.includes('INSERT INTO broadband_refresh_tokens')) {
      tokens.set(String(values[1]), { id: String(values[1]), subscriber_username: String(values[0]) });
      return [{ affectedRows: 1 }];
    }
    if (sql.includes('UPDATE broadband_refresh_tokens')) { tokens.delete(String(values[0])); return [{ affectedRows: 1 }]; }
    if (sql.includes('subscriber_push_') || sql.includes('subscriber_notifications')) {
      if (sql.trim().startsWith('SELECT')) return [[]];
      pushWrites.push(values);
      return [{ affectedRows: 1, insertId: 42 }];
    }
    if (sql.includes('UPDATE')) { updates.push(values); return [{ affectedRows: 1 }]; }
    assert.ok(sql.includes('nawa_pppoe_users'));
    assert.ok(!sql.includes('cleartext_password'));
    return [values[0] === row.username ? [row] : []];
  }, query: async (sql: string, values: unknown[]) => {
    if (sql.includes('FROM broadband_refresh_tokens')) return [tokens.has(String(values[0])) ? [tokens.get(String(values[0]))] : []];
    if (sql.includes('FROM nawa_pppoe_users')) return [[{ ...row, id: 1, package_id: 1, active_devices: 1 }]];
    return [[]];
  }, getConnection: async () => ({ execute: pool.execute.bind(pool), query: pool.query.bind(pool),
    beginTransaction: async () => {}, commit: async () => {}, rollback: async () => {}, release: () => {} }),
  } as unknown as Pool;
  const app = Fastify();
  await app.register(jwt, { secret: 'broadband-test-secret-at-least-32-characters' });
  await app.register(rateLimit, { global: false });
  app.setErrorHandler((error, _request, reply) => {
    reply.code(error instanceof AppError ? error.statusCode : (error as { statusCode?: number }).statusCode ?? 500).send({ error: 'rejected' });
  });
  registerBroadbandRoutes(app, pool);
  app.get('/hotspot', { preHandler: requireSubscriber }, () => ({ ok: true }));
  await app.ready();
  return { app, updates, row, pushWrites };
}

test('username-only broadband login exposes package data, never passwords; rejects unknown users', async t => {
  const { app } = await fixture(); t.after(() => app.close());
  const result = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: ' broadband1 ' } });
  assert.equal(result.statusCode, 200);
  assert.equal(result.json().subscriber.remainingBytes, '9663676416');
  assert.equal(result.json().subscriber.packageName, 'Fiber');
  assert.ok(!result.body.includes('must-never'));
  const missing = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'hotspot-only' } });
  assert.equal(missing.statusCode, 401);
});

test('tokens isolate broadband and hotspot; profile writes use signed identity only', async t => {
  const { app, updates } = await fixture(); t.after(() => app.close());
  const login = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'broadband1' } });
  const headers = { authorization: `Bearer ${login.json().accessToken}` };
  assert.equal((await app.inject({ url: '/hotspot', headers })).statusCode, 403);
  assert.equal((await app.inject({ url: '/api/v1/broadband/profile' })).statusCode, 401);
  const hotspot = app.jwt.sign({ sub: 'broadband1', username: 'broadband1', role: 'subscriber', status: 'active' });
  assert.equal((await app.inject({ url: '/api/v1/broadband/profile', headers: { authorization: `Bearer ${hotspot}` } })).statusCode, 403);
  const save = await app.inject({ method: 'PATCH', url: '/api/v1/broadband/profile', headers,
    payload: { fullName: ' New name ', phone: '123', address: 'Street' } });
  assert.equal(save.statusCode, 200);
  assert.deepEqual(updates, [['New name', '123', 'Street', 'broadband1']]);
  const oversized = await app.inject({ method: 'PATCH', url: '/api/v1/broadband/profile', headers,
    payload: { fullName: 'x'.repeat(151), phone: '', address: '' } });
  assert.equal(oversized.statusCode, 400);
  assert.equal(updates.length, 1);
});

test('expired and exhausted accounts can still inspect their actual subscription state', async t => {
  const { app, row } = await fixture(); t.after(() => app.close());
  row.expires_at = '2020-01-01 00:00:00';
  const expired = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: row.username } });
  assert.equal(expired.json().subscriber.status, 'expired');
  row.expires_at = '2099-01-01 00:00:00'; row.used_quota = '20737418240';
  const exhausted = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: row.username } });
  assert.equal(exhausted.json().subscriber.status, 'exhausted');
  assert.equal(exhausted.json().subscriber.remainingBytes, '0');
});

test('broadband login rate limits attempts', async t => {
  const { app } = await fixture(); t.after(() => app.close());
  for (let i = 0; i < 5; i++) await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'missing' } });
  const result = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'missing' } });
  assert.equal(result.statusCode, 429);
  assert.ok(result.headers['retry-after']);
});

test('broadband opens the shared dashboard and account services while retaining role isolation', async t => {
  const { app } = await fixture(); t.after(() => app.close());
  const login = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'broadband1' } });
  const headers = { authorization: `Bearer ${login.json().accessToken}` };
  const dashboard = await app.inject({ url: '/api/v1/broadband/dashboard', headers });
  assert.equal(dashboard.statusCode, 200, dashboard.body);
  assert.equal(dashboard.json().subscription.total_bytes, 10737418240);
  assert.equal(dashboard.json().subscription.package_name, 'Fiber');
  for (const route of ['usage/summary', 'usage/daily', 'sessions', 'devices', 'recharges']) {
    const response = await app.inject({ url: `/api/v1/broadband/${route}`, headers });
    assert.equal(response.statusCode, 200, `${route}: ${response.body}`);
    assert.equal((await app.inject({ url: `/api/v1/broadband/${route}` })).statusCode, 401);
  }
  const forged = app.jwt.sign({ sub: 'broadband1', username: 'broadband1', role: 'subscriber', status: 'active' });
  assert.equal((await app.inject({ url: '/api/v1/broadband/dashboard', headers: { authorization: `Bearer ${forged}` } })).statusCode, 403);
  assert.equal((await app.inject({ method: 'POST', url: '/api/v1/broadband/notifications/push-token', headers, payload: { token: 'short' } })).statusCode, 400);
});

test('broadband refresh rotates once, logout revokes, and hotspot refresh tokens are rejected', async t => {
  const { app } = await fixture(); t.after(() => app.close());
  const login = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'broadband1' } });
  const first = login.json().refreshToken;
  assert.match(first, /^bb_/);
  const refresh = () => app.inject({ method: 'POST', url: '/api/v1/auth/broadband-refresh', payload: { refreshToken: first } });
  const rotated = await refresh();
  assert.equal(rotated.statusCode, 200, rotated.body);
  assert.notEqual(rotated.json().refreshToken, first);
  assert.equal((await refresh()).statusCode, 401);
  const payload = { refreshToken: rotated.json().refreshToken };
  assert.equal((await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-logout', payload })).statusCode, 200);
  assert.equal((await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-refresh', payload })).statusCode, 401);
  assert.equal((await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-refresh', payload: { refreshToken: 'x'.repeat(64) } })).statusCode, 400);
});

test('broadband registers all push categories using its signed account identity', async t => {
  const { app, pushWrites } = await fixture(); t.after(() => app.close());
  const login = await app.inject({ method: 'POST', url: '/api/v1/auth/broadband-login', payload: { username: 'broadband1' } });
  const headers = { authorization: `Bearer ${login.json().accessToken}` };
  const payload = { token: 'test-token-for-broadband-push', installation_id: 'a'.repeat(32) };
  const result = await app.inject({ method: 'POST', url: '/api/v1/broadband/notifications/push-token', headers, payload });
  assert.equal(result.statusCode, 200, result.body);
  assert.equal(result.json().registered, true);
  const registration = pushWrites.find(values => values[2] === payload.token);
  assert.ok(registration);
  assert.equal(registration[1], 'broadband1');
  assert.deepEqual(registration.slice(4), [1, 1, 1]);
  const revoke = await app.inject({ method: 'POST', url: '/api/v1/broadband/notifications/push-token/revoke', headers,
    payload: { installation_id: payload.installation_id } });
  assert.equal(revoke.statusCode, 200);
  assert.equal(revoke.json().registered, false);
});
