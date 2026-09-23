import type { FastifyInstance } from 'fastify';
import type { Pool } from 'mysql2/promise';
import { requireSubscriber, subscriberPrincipal } from '../middleware/subscriber-auth.js';

export function registerFeedbackRoutes(app: FastifyInstance, pool: Pool) {
  app.post<{ Body: { phone: string; network_name: string; message: string } }>(
    '/api/v1/subscriber/feedback',
    {
      preHandler: requireSubscriber,
      config: { rateLimit: { max: 5, timeWindow: '1 hour', keyGenerator: (request) => request.headers.authorization ?? request.ip } },
      schema: {
        body: {
          type: 'object', additionalProperties: false, required: ['phone', 'network_name', 'message'],
          properties: {
            phone: { type: 'string', minLength: 7, maxLength: 32, pattern: '^[0-9+() .-]+$' },
            network_name: { type: 'string', minLength: 2, maxLength: 120 },
            message: { type: 'string', minLength: 3, maxLength: 4000 },
          },
        },
      },
    },
    async (request) => {
      await pool.execute(
        'INSERT INTO subscriber_feedback(username,category,phone,network_name,message) VALUES (?,?,?,?,?)',
        [subscriberPrincipal(request).username, 'problem', request.body.phone.trim(), request.body.network_name.trim(), request.body.message.trim()],
      );
      return { submitted: true };
    },
  );
}
