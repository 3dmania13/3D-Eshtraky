import assert from 'node:assert/strict';
import { test } from 'node:test';

import { AuthService } from '../src/auth/auth-service.js';
import { createApp } from '../src/app.js';
import type { AppConfig } from '../src/config/index.js';
import type {
  AuthRepository,
  DerivedNotification,
  SubscriberRepository,
  UsageDateRanges,
} from '../src/domain/contracts.js';
import type {
  AuthSubscriberRecord,
  DailyUsageRecord,
  DashboardRecord,
  DeviceRecord,
  NotificationRecord,
  RechargeRecord,
  SessionRecord,
  SubscriberPrincipal,
  SubscriberProfileRecord,
  UsageSummaryRecord,
} from '../src/domain/models.js';
import { DeviceService } from '../src/devices/device-service.js';
import { DeviceSpeedService } from '../src/devices/device-speed-service.js';
import { AppError } from '../src/errors.js';
import { NotificationService } from '../src/notifications/notification-service.js';
import { RechargeService } from '../src/recharges/recharge-service.js';
import { QuotaService } from '../src/services/quota-service.js';
import { SubscriptionService } from '../src/services/subscription-service.js';
import { SessionService } from '../src/sessions/session-service.js';
import { SubscriberService } from '../src/subscriber/subscriber-service.js';
import { SpeedService } from '../src/speed/speed-service.js';
import { ConnectionLimitService } from '../src/connection-limits/connection-limit-service.js';
import { UsageService } from '../src/usage/usage-service.js';

const now = new Date('2026-08-30T12:00:00.000Z');

function authRecord(overrides: Partial<AuthSubscriberRecord> = {}): AuthSubscriberRecord {
  return {
    id: '7',
    username: 'alice',
    rawStatus: 'active',
    isDisabled: false,
    isInDisabledGroup: false,
    expiresAt: new Date('2026-09-30T00:00:00.000Z'),
    cleartextPassword: 'correct-password',
    ...overrides,
  };
}

class FakeAuthRepository implements AuthRepository {
  record: AuthSubscriberRecord | null = authRecord();
  issued: { subscriberUsername: string; tokenHash: string; expiresAt: Date }[] = [];

  async findForAuthentication(): Promise<AuthSubscriberRecord | null> {
    return this.record;
  }

  async issueRefreshToken(input: {
    subscriberUsername: string;
    tokenHash: string;
    expiresAt: Date;
  }): Promise<void> {
    this.issued.push(input);
  }

  async rotateRefreshToken(): Promise<AuthSubscriberRecord | null> {
    return this.record;
  }

  async revokeRefreshToken(): Promise<void> {}
}

class FakeSubscriberRepository implements SubscriberRepository {
  readonly dashboardUsernames: string[] = [];
  connectionLimit: number | null = 2;

  async getProfile(username: string): Promise<SubscriberProfileRecord> {
    return {
      username,
      rawStatus: 'active',
      isDisabled: false,
      isInDisabledGroup: false,
      expiresAt: new Date('2026-09-30T00:00:00.000Z'),
      packageName: '10 GB',
    };
  }

  async getDashboard(username: string): Promise<DashboardRecord> {
    this.dashboardUsernames.push(username);
    return {
      ...authRecord({ username, cleartextPassword: null }),
      packageId: '10',
      packageName: '10 GB',
      totalBytes: 10n * 1024n ** 3n,
      usedBytes: 4n * 1024n ** 3n,
      startedAt: new Date('2026-08-01T00:00:00.000Z'),
      activeDeviceCount: 1,
      isConnected: true,
    };
  }

  async getUsageSummary(
    _username: string,
    _ranges: UsageDateRanges,
  ): Promise<UsageSummaryRecord> {
    const empty = { downloadBytes: 0n, uploadBytes: 0n };
    return { today: empty, yesterday: empty, week: empty, month: empty };
  }

  async getDailyUsage(): Promise<DailyUsageRecord[]> {
    return [];
  }

  async getSessions(): Promise<SessionRecord[]> {
    return [];
  }

  async getDevices(
    _subscriber: SubscriberPrincipal,
    _limit: number,
  ): Promise<DeviceRecord[]> {
    return [];
  }

  async renameDevice(): Promise<DeviceRecord | null> {
    return null;
  }

  async setDeviceSpeed(): Promise<DeviceRecord | null> {
    return null;
  }

  async invalidateDeviceSpeedApplications(): Promise<void> {}

  async getRecharges(): Promise<RechargeRecord[]> {
    return [];
  }

  async upsertDerivedNotifications(_input: {
    username: string;
    notifications: readonly DerivedNotification[];
  }): Promise<void> {}

  async getNotifications(): Promise<NotificationRecord[]> {
    return [];
  }

  async markNotificationRead(): Promise<boolean> {
    return false;
  }

  async markAllNotificationsRead(): Promise<number> {
    return 0;
  }

  async registerPushToken(): Promise<void> {}

  async getSpeedSelection(): Promise<string> { return 'open'; }

  async setSpeedSelection(): Promise<void> {}

  async getConnectionLimit(): Promise<number | null> {
    return this.connectionLimit;
  }

  async setConnectionLimit(input: {
    username: string;
    limit: number;
  }): Promise<void> {
    this.connectionLimit = input.limit;
  }
}

function authService(repository: FakeAuthRepository): AuthService {
  return new AuthService(
    repository,
    new SubscriptionService(),
    { sign: (principal) => `access-for-${principal.username}` },
    30,
    () => now,
  );
}

test('valid login issues access and stores only the refresh-token hash', async () => {
  const repository = new FakeAuthRepository();
  const result = await authService(repository).login('alice', 'correct-password');
  assert.equal(result.accessToken, 'access-for-alice');
  assert.equal(result.subscriber.username, 'alice');
  assert.equal(result.subscriber.status, 'active');
  assert.equal(repository.issued.length, 1);
  assert.equal(repository.issued[0]?.tokenHash.length, 64);
  assert.notEqual(repository.issued[0]?.tokenHash, result.refreshToken);
});

test('invalid password is rejected', async () => {
  const repository = new FakeAuthRepository();
  await assert.rejects(
    authService(repository).login('alice', 'wrong-password'),
    (error: unknown) => error instanceof AppError && error.code === 'INVALID_CREDENTIALS',
  );
  assert.equal(repository.issued.length, 0);
});

test('subscriber code logs in using the username without a second password', async () => {
  const repository = new FakeAuthRepository();
  const result = await authService(repository).loginWithCode('alice');
  assert.equal(result.accessToken, 'access-for-alice');
  assert.equal(result.subscriber.username, 'alice');
  assert.equal(repository.issued.length, 1);
});

test('unknown subscriber code is rejected', async () => {
  const repository = new FakeAuthRepository();
  repository.record = null;
  await assert.rejects(
    authService(repository).loginWithCode('missing-code'),
    (error: unknown) => error instanceof AppError && error.code === 'INVALID_CODE',
  );
  assert.equal(repository.issued.length, 0);
});

test('disabled subscriber is rejected after successful password verification', async () => {
  const repository = new FakeAuthRepository();
  repository.record = authRecord({ isDisabled: true });
  await assert.rejects(
    authService(repository).login('alice', 'correct-password'),
    (error: unknown) => error instanceof AppError && error.code === 'ACCOUNT_DISABLED',
  );
});

test('quota calculation clamps remaining bytes and reports remaining GiB', () => {
  const quota = new QuotaService();
  const result = quota.calculate(10n * 1024n ** 3n, 4n * 1024n ** 3n);
  assert.equal(result.remainingBytes, 6n * 1024n ** 3n);
  assert.equal(result.remainingGib, 6);
  assert.equal(result.usagePercentage, 40);
  assert.equal(quota.calculate(10n, 15n).remainingBytes, 0n);
});

const config: AppConfig = {
  host: '127.0.0.1',
  port: 3081,
  logLevel: 'silent',
  trustProxy: false,
  localTimezone: 'Asia/Aden',
  database: {
    host: '127.0.0.1',
    port: 3307,
    name: 'radius',
    user: 'unused',
    password: 'unused',
    connectionLimit: 1,
  },
  auth: {
    jwtSecret: 'test-secret-that-is-definitely-at-least-32-bytes',
    accessTokenTtl: '15m',
    refreshTokenDays: 30,
  },
};

async function testApp(repository: FakeSubscriberRepository) {
  const authRepository = new FakeAuthRepository();
  const subscriptions = new SubscriptionService();
  const quota = new QuotaService();
  return createApp(config, {
    services: {
      auth: authService(authRepository),
      subscriber: new SubscriberService(repository, subscriptions, quota, () => now),
      usage: new UsageService(repository, 'Asia/Aden', () => now),
      sessions: new SessionService(repository),
      devices: new DeviceService(repository),
      deviceSpeeds: new DeviceSpeedService(repository),
      recharges: new RechargeService(repository),
      notifications: new NotificationService(repository, subscriptions, quota, () => now),
      speed: new SpeedService(repository),
      connectionLimits: new ConnectionLimitService(repository),
    },
  });
}

test('protected API rejects missing and invalid access tokens', async (context) => {
  const app = await testApp(new FakeSubscriberRepository());
  context.after(() => app.close());
  const missing = await app.inject({ method: 'GET', url: '/api/v1/subscriber/dashboard' });
  assert.equal(missing.statusCode, 401);
  assert.equal(missing.json().error.code, 'INVALID_TOKEN');
  const invalid = await app.inject({
    method: 'GET',
    url: '/api/v1/subscriber/dashboard',
    headers: { authorization: 'Bearer invalid-token' },
  });
  assert.equal(invalid.statusCode, 401);
  assert.equal(invalid.json().error.code, 'INVALID_TOKEN');
});

test('subscriber identity comes from JWT and cannot be replaced by query data', async (context) => {
  const repository = new FakeSubscriberRepository();
  const app = await testApp(repository);
  context.after(() => app.close());
  const token = app.jwt.sign({
    sub: 'alice',
    username: 'alice',
    role: 'subscriber',
    status: 'active',
  });
  const response = await app.inject({
    method: 'GET',
    url: '/api/v1/subscriber/dashboard?username=bob',
    headers: { authorization: `Bearer ${token}` },
  });
  assert.equal(response.statusCode, 200);
  assert.equal(response.json().username, 'alice');
  assert.deepEqual(repository.dashboardUsernames, ['alice']);
});

test('subscriber can save the RADIUS concurrent-connection limit', async (context) => {
  const repository = new FakeSubscriberRepository();
  const app = await testApp(repository);
  context.after(() => app.close());
  const token = app.jwt.sign({
    sub: 'alice',
    username: 'alice',
    role: 'subscriber',
    status: 'active',
  });
  const headers = { authorization: `Bearer ${token}` };

  const current = await app.inject({
    method: 'GET',
    url: '/api/v1/subscriber/connection-limit',
    headers,
  });
  assert.equal(current.statusCode, 200);
  assert.equal(current.json().limit, 2);

  const saved = await app.inject({
    method: 'PUT',
    url: '/api/v1/subscriber/connection-limit',
    headers,
    payload: { limit: 3 },
  });
  assert.equal(saved.statusCode, 200);
  assert.equal(saved.json().limit, 3);
  assert.equal(repository.connectionLimit, 3);

  const invalid = await app.inject({
    method: 'PUT',
    url: '/api/v1/subscriber/connection-limit',
    headers,
    payload: { limit: 11 },
  });
  assert.equal(invalid.statusCode, 400);
});
