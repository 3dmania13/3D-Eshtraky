import type {
  AuthSubscriberRecord,
  SubscriberState,
} from '../domain/models.js';

export class SubscriptionService {
  state(
    subscriber: Pick<
      AuthSubscriberRecord,
      'rawStatus' | 'isDisabled' | 'isInDisabledGroup' | 'expiresAt'
    >,
    now = new Date(),
  ): SubscriberState {
    const raw = subscriber.rawStatus.trim().toLowerCase();
    if (
      subscriber.isDisabled ||
      subscriber.isInDisabledGroup ||
      raw === 'disabled' ||
      raw === 'inactive'
    ) {
      return 'disabled';
    }
    if (raw === 'expired' || (subscriber.expiresAt?.getTime() ?? Infinity) <= now.getTime()) {
      return 'expired';
    }
    return 'active';
  }

  daysRemaining(expiresAt: Date | null, now = new Date()): number | null {
    if (!expiresAt) return null;
    const milliseconds = expiresAt.getTime() - now.getTime();
    return Math.max(0, Math.ceil(milliseconds / 86_400_000));
  }
}
