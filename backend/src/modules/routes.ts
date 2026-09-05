import type { FastifyInstance } from 'fastify';

import { requireSubscriber, subscriberPrincipal } from '../middleware/subscriber-auth.js';
import type { AppServices } from './services.js';

const usernameSchema = { type: 'string', minLength: 1, maxLength: 64 } as const;
const codeSchema = { type: 'string', minLength: 1, maxLength: 64 } as const;
const passwordSchema = { type: 'string', minLength: 1, maxLength: 253 } as const;
const refreshSchema = { type: 'string', minLength: 32, maxLength: 256 } as const;

export function registerRoutes(app: FastifyInstance, services: AppServices): void {
  app.get('/healthz', async () => ({ status: 'ok' }));

  app.post<{ Body: { username: string; password: string } }>(
    '/api/v1/auth/login',
    {
      config: { rateLimit: { max: 10, timeWindow: '1 minute' } },
      schema: {
        body: {
          type: 'object',
          additionalProperties: false,
          required: ['username', 'password'],
          properties: { username: usernameSchema, password: passwordSchema },
        },
      },
    },
    async (request) => services.auth.login(request.body.username, request.body.password),
  );

  app.post<{ Body: { code: string } }>(
    '/api/v1/auth/code-login',
    {
      config: { rateLimit: { max: 5, timeWindow: '1 minute' } },
      schema: {
        body: {
          type: 'object',
          additionalProperties: false,
          required: ['code'],
          properties: { code: codeSchema },
        },
      },
    },
    async (request) => services.auth.loginWithCode(request.body.code),
  );

  app.post<{ Body: { refreshToken: string } }>(
    '/api/v1/auth/refresh',
    {
      config: { rateLimit: { max: 20, timeWindow: '1 minute' } },
      schema: {
        body: {
          type: 'object',
          additionalProperties: false,
          required: ['refreshToken'],
          properties: { refreshToken: refreshSchema },
        },
      },
    },
    async (request) => services.auth.refresh(request.body.refreshToken),
  );

  app.post<{ Body: { refreshToken: string } }>(
    '/api/v1/auth/logout',
    {
      schema: {
        body: {
          type: 'object',
          additionalProperties: false,
          required: ['refreshToken'],
          properties: { refreshToken: refreshSchema },
        },
      },
    },
    async (request, reply) => {
      await services.auth.logout(request.body.refreshToken);
      return reply.code(204).send();
    },
  );

  app.get('/api/v1/subscriber/profile', { preHandler: requireSubscriber }, async (request) =>
    services.subscriber.profile(subscriberPrincipal(request)),
  );

  app.get('/api/v1/subscriber/dashboard', { preHandler: requireSubscriber }, async (request) =>
    services.subscriber.dashboard(subscriberPrincipal(request)),
  );

  app.get('/api/v1/subscriber/usage/summary', { preHandler: requireSubscriber }, async (request) =>
    services.usage.summary(subscriberPrincipal(request)),
  );

  app.get<{ Querystring: { from?: string; to?: string } }>(
    '/api/v1/subscriber/usage/daily',
    {
      preHandler: requireSubscriber,
      schema: {
        querystring: {
          type: 'object',
          additionalProperties: false,
          properties: {
            from: { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' },
            to: { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' },
          },
        },
      },
    },
    async (request) =>
      services.usage.daily(subscriberPrincipal(request), request.query.from, request.query.to),
  );

  app.get('/api/v1/subscriber/sessions', { preHandler: requireSubscriber }, async (request) =>
    services.sessions.list(subscriberPrincipal(request)),
  );

  app.get('/api/v1/subscriber/devices', { preHandler: requireSubscriber }, async (request) =>
    services.devices.list(subscriberPrincipal(request)),
  );

  app.patch<{ Params: { id: string }; Body: { friendly_name: string } }>(
    '/api/v1/subscriber/devices/:id',
    {
      preHandler: requireSubscriber,
      schema: {
        params: {
          type: 'object',
          additionalProperties: false,
          required: ['id'],
          properties: { id: { type: 'string', pattern: '^[a-f0-9]{64}$' } },
        },
        body: {
          type: 'object',
          additionalProperties: false,
          required: ['friendly_name'],
          properties: { friendly_name: { type: 'string', minLength: 1, maxLength: 80 } },
        },
      },
    },
    async (request) =>
      services.devices.rename(
        subscriberPrincipal(request),
        request.params.id,
        request.body.friendly_name,
      ),
  );

  app.get('/api/v1/subscriber/recharges', { preHandler: requireSubscriber }, async (request) =>
    services.recharges.list(subscriberPrincipal(request)),
  );

  app.get('/api/v1/subscriber/notifications', { preHandler: requireSubscriber }, async (request) =>
    services.notifications.list(subscriberPrincipal(request)),
  );

  app.patch<{ Params: { id: string } }>(
    '/api/v1/subscriber/notifications/:id/read',
    {
      preHandler: requireSubscriber,
      schema: {
        params: {
          type: 'object',
          additionalProperties: false,
          required: ['id'],
          properties: { id: { type: 'string', pattern: '^\\d{1,20}$' } },
        },
      },
    },
    async (request) =>
      services.notifications.markRead(subscriberPrincipal(request), request.params.id),
  );
}
