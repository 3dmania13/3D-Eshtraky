import type { SubscriberRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal } from '../domain/models.js';
import { AppError } from '../errors.js';
import { QuotaService } from '../services/quota-service.js';
import { SubscriptionService } from '../services/subscription-service.js';
import { asSafeNumber } from '../utils/values.js';

export class SubscriberService {
  constructor(
    private readonly repository: SubscriberRepository,
    private readonly subscriptions: SubscriptionService,
    private readonly quota: QuotaService,
    private readonly now: () => Date = () => new Date(),
  ) {}

  async profile(principal: SubscriberPrincipal) {
    const record = await this.repository.getProfile(principal.username);
    if (!record) throw new AppError(404, 'SUBSCRIBER_NOT_FOUND', 'الحساب غير موجود.');
    return {
      username: record.username,
      status: this.subscriptions.state(record, this.now()),
      package_name: record.packageName,
      expires_at: record.expiresAt?.toISOString() ?? null,
    };
  }

  async dashboard(principal: SubscriberPrincipal) {
    const record = await this.repository.getDashboard(principal.username);
    if (!record) throw new AppError(404, 'SUBSCRIBER_NOT_FOUND', 'الحساب غير موجود.');
    const quota = this.quota.calculate(record.totalBytes, record.usedBytes);
    const expiresAt = record.expiresAt ?? new Date(0);
    return {
      id: record.id,
      username: record.username,
      status: this.subscriptions.state(record, this.now()),
      connection_status: record.isConnected ? 'connected' : 'disconnected',
      active_device_count: record.activeDeviceCount,
      remaining_bytes: asSafeNumber(quota.remainingBytes, 'remaining_bytes'),
      remaining_gib: quota.remainingGib,
      usage_percentage: quota.usagePercentage,
      days_remaining: this.subscriptions.daysRemaining(record.expiresAt, this.now()),
      subscription: {
        id: record.packageId ?? record.packageName,
        package_name: record.packageName,
        total_bytes: asSafeNumber(quota.totalBytes, 'total_bytes'),
        used_bytes: asSafeNumber(quota.usedBytes, 'used_bytes'),
        started_at: (record.startedAt ?? this.now()).toISOString(),
        expires_at: expiresAt.toISOString(),
      },
    };
  }
}
