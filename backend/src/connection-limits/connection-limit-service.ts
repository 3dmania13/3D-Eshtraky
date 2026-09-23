import type { SubscriberRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal } from '../domain/models.js';
import { AppError } from '../errors.js';

const minimumLimit = 1;
const maximumLimit = 10;

/** Manages the RADIUS Simultaneous-Use value for one subscriber account. */
export class ConnectionLimitService {
  constructor(private readonly repository: SubscriberRepository) {}

  async get(principal: SubscriberPrincipal) {
    const limit = await this.repository.getConnectionLimit(principal.username);
    return {
      limit,
      options: Array.from(
        { length: maximumLimit - minimumLimit + 1 },
        (_, index) => index + minimumLimit,
      ),
    };
  }

  async set(principal: SubscriberPrincipal, limit: number) {
    if (!Number.isInteger(limit) || limit < minimumLimit || limit > maximumLimit) {
      throw new AppError(
        400,
        'INVALID_CONNECTION_LIMIT',
        'اختر عددًا من 1 إلى 10 أجهزة.',
      );
    }
    await this.repository.setConnectionLimit({
      username: principal.username,
      limit,
    });
    return { limit };
  }
}
