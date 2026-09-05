import type { FastifyRequest } from 'fastify';

import type { SubscriberPrincipal, SubscriberState } from '../domain/models.js';
import { AppError } from '../errors.js';

declare module '@fastify/jwt' {
  interface FastifyJWT {
    payload: {
      sub: string;
      username: string;
      role: 'subscriber';
      status: SubscriberState;
    };
    user: {
      sub: string;
      username: string;
      role: 'subscriber';
      status: SubscriberState;
    };
  }
}

export async function requireSubscriber(request: FastifyRequest): Promise<void> {
  try {
    await request.jwtVerify();
  } catch {
    throw new AppError(401, 'INVALID_TOKEN', 'رمز الدخول مفقود أو غير صالح.');
  }
  if (
    request.user.role !== 'subscriber' ||
    !request.user.username ||
    request.user.sub !== request.user.username
  ) {
    throw new AppError(403, 'SUBSCRIBER_ACCESS_REQUIRED', 'غير مصرح بالوصول.');
  }
}

export function subscriberPrincipal(request: FastifyRequest): SubscriberPrincipal {
  return {
    username: request.user.username,
    status: request.user.status,
  };
}
