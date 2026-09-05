import helmet from '@fastify/helmet';
import jwt from '@fastify/jwt';
import rateLimit from '@fastify/rate-limit';
import Fastify, { type FastifyInstance } from 'fastify';
import type { Pool } from 'mysql2/promise';

import { AuthService, type AccessTokenSigner } from './auth/auth-service.js';
import type { AppConfig } from './config/index.js';
import { createPool } from './database/pool.js';
import { MySqlSubscriberRepository } from './database/mysql-subscriber-repository.js';
import { DeviceService } from './devices/device-service.js';
import { AppError, toPublicError } from './errors.js';
import { registerRoutes } from './modules/routes.js';
import type { AppServices } from './modules/services.js';
import { NotificationService } from './notifications/notification-service.js';
import { RechargeService } from './recharges/recharge-service.js';
import { QuotaService } from './services/quota-service.js';
import { SubscriptionService } from './services/subscription-service.js';
import { SessionService } from './sessions/session-service.js';
import { SubscriberService } from './subscriber/subscriber-service.js';
import { UsageService } from './usage/usage-service.js';

export interface AppOverrides {
  readonly services?: AppServices;
  readonly pool?: Pool;
}

export async function createApp(
  config: AppConfig,
  overrides: AppOverrides = {},
): Promise<FastifyInstance> {
  const app = Fastify({
    trustProxy: config.trustProxy,
    bodyLimit: 32 * 1024,
    logger: {
      level: config.logLevel,
      redact: {
        paths: [
          'req.headers.authorization',
          'req.body.password',
          'req.body.code',
          'req.body.refreshToken',
          'res.headers.authorization',
        ],
        censor: '[REDACTED]',
      },
    },
  });

  await app.register(helmet, { global: true });
  await app.register(rateLimit, { global: false });
  await app.register(jwt, { secret: config.auth.jwtSecret });

  let ownedPool: Pool | undefined;
  let services = overrides.services;
  if (!services) {
    ownedPool = overrides.pool ?? createPool(config.database);
    const repository = new MySqlSubscriberRepository(ownedPool);
    const subscriptions = new SubscriptionService();
    const quota = new QuotaService();
    const signer: AccessTokenSigner = {
      sign: (principal) =>
        app.jwt.sign(
          {
            sub: principal.username,
            username: principal.username,
            role: 'subscriber',
            status: principal.status,
          },
          { expiresIn: config.auth.accessTokenTtl },
        ),
    };
    services = {
      auth: new AuthService(
        repository,
        subscriptions,
        signer,
        config.auth.refreshTokenDays,
      ),
      subscriber: new SubscriberService(repository, subscriptions, quota),
      usage: new UsageService(repository, config.localTimezone),
      sessions: new SessionService(repository),
      devices: new DeviceService(repository),
      recharges: new RechargeService(repository),
      notifications: new NotificationService(repository, subscriptions, quota),
    };
  }

  if (ownedPool && !overrides.pool) {
    app.addHook('onClose', async () => ownedPool?.end());
  }

  app.setNotFoundHandler(async (_request, reply) =>
    reply.code(404).send({ error: { code: 'NOT_FOUND', message: 'المسار غير موجود.' } }),
  );
  app.setErrorHandler(async (error, request, reply) => {
    const validation =
      typeof error === 'object' &&
      error !== null &&
      'validation' in error &&
      Boolean(error.validation);
    const publicError = validation
      ? new AppError(400, 'VALIDATION_ERROR', 'بيانات الطلب غير صالحة.')
      : toPublicError(error);
    if (publicError.statusCode >= 500) request.log.error({ err: error }, 'request failed');
    return reply.code(publicError.statusCode).send({
      error: {
        code: publicError.code,
        message: publicError.message,
        ...(publicError.details ? { details: publicError.details } : {}),
      },
    });
  });

  registerRoutes(app, services);
  return app;
}
