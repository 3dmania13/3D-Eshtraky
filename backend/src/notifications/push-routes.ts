import type { FastifyInstance } from 'fastify';
import type { Pool } from 'mysql2/promise';
import { requireSubscriber, subscriberPrincipal } from '../middleware/subscriber-auth.js';
import { defaultPushPreferences, PushStore } from './push-store.js';

export function registerPushRoutes(app: FastifyInstance, pool: Pool) {
  const store = new PushStore(pool);
  const installation = { type: 'string', pattern: '^[a-f0-9]{32}$' };
  app.post<{ Body: {
    token: string;
    installation_id: string;
    subscription_alerts?: boolean;
    device_alerts?: boolean;
    system_messages?: boolean;
  } }>('/api/v1/subscriber/notifications/push-token', {
    preHandler: requireSubscriber,
    schema: { body: { type: 'object', additionalProperties: false, required: ['token', 'installation_id'], properties: {
      token: { type: 'string', minLength: 20, maxLength: 512, pattern: '^[A-Za-z0-9_:\\-]+$' }, installation_id: installation,
      platform: { type: 'string', enum: ['android'] },
      subscription_alerts: { type: 'boolean' },
      device_alerts: { type: 'boolean' },
      system_messages: { type: 'boolean' },
    } } },
  }, (request) => store.register(
    subscriberPrincipal(request).username,
    request.body.installation_id,
    request.body.token,
    {
      subscriptionAlerts: request.body.subscription_alerts ?? defaultPushPreferences.subscriptionAlerts,
      deviceAlerts: request.body.device_alerts ?? defaultPushPreferences.deviceAlerts,
      systemMessages: request.body.system_messages ?? defaultPushPreferences.systemMessages,
    },
  ));
  app.post<{ Body: { installation_id: string } }>('/api/v1/subscriber/notifications/push-token/revoke', {
    preHandler: requireSubscriber,
    schema: { body: { type: 'object', additionalProperties: false, required: ['installation_id'], properties: { installation_id: installation } } },
  }, (request) => store.revoke(subscriberPrincipal(request).username, request.body.installation_id));
}
