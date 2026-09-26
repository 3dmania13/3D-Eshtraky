import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { Pool } from 'mysql2/promise';
import { BroadbandRepository } from './broadband-repository.js';
import { registerRoutes } from '../modules/routes.js';
import { registerPushRoutes } from '../notifications/push-routes.js';
import { registerFeedbackRoutes } from '../feedback/feedback-routes.js';
import { SubscriberService } from '../subscriber/subscriber-service.js';
import { UsageService } from '../usage/usage-service.js';
import { SessionService } from '../sessions/session-service.js';
import { DeviceService } from '../devices/device-service.js';
import { DeviceSpeedService } from '../devices/device-speed-service.js';
import { MySqlLiveDeviceDisconnector } from '../devices/live-device-disconnect-service.js';
import { MySqlLiveDeviceSpeedApplier } from '../devices/live-device-speed-service.js';
import { RechargeService } from '../recharges/recharge-service.js';
import { NotificationService } from '../notifications/notification-service.js';
import { SubscriptionService } from '../services/subscription-service.js';
import { QuotaService } from '../services/quota-service.js';
import { SpeedService } from '../speed/speed-service.js';
import { MySqlLiveSpeedApplier } from '../speed/live-speed-service.js';
import { ConnectionLimitService } from '../connection-limits/connection-limit-service.js';
import type { AppServices } from '../modules/services.js';

export function registerBroadbandServices(app: FastifyInstance, pool: Pool,
  guard: (request: FastifyRequest) => Promise<void>, timezone: string) {
  const repository = new BroadbandRepository(pool);
  const subscriptions = new SubscriptionService();
  const quota = new QuotaService();
  // Scoped registration keeps exactly the same validation and service routes.
  app.register(async scoped => {
    scoped.addHook('onRoute', route => {
      route.url = route.url.replace('/api/v1/subscriber/', '/api/v1/broadband/');
      // Preserve the existing editable broadband profile endpoint.
      if (route.url === '/api/v1/broadband/profile') route.url += '/summary';
      route.preHandler = guard;
    });
    registerRoutes(scoped, {
      subscriber: new SubscriberService(repository, subscriptions, quota),
      usage: new UsageService(repository, timezone), sessions: new SessionService(repository),
      devices: new DeviceService(repository, new MySqlLiveDeviceDisconnector(pool)),
      deviceSpeeds: new DeviceSpeedService(repository, new MySqlLiveDeviceSpeedApplier(pool)),
      recharges: new RechargeService(repository),
      notifications: new NotificationService(repository, subscriptions, quota),
      speed: new SpeedService(repository, new MySqlLiveSpeedApplier(pool)),
      connectionLimits: new ConnectionLimitService(repository),
    } as AppServices, true);
    registerPushRoutes(scoped, pool);
    registerFeedbackRoutes(scoped, pool);
  });
}
