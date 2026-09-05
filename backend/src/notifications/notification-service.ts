import type { DerivedNotification, SubscriberRepository } from '../domain/contracts.js';
import type { DashboardRecord, DeviceRecord, SubscriberPrincipal } from '../domain/models.js';
import { AppError } from '../errors.js';
import { QuotaService } from '../services/quota-service.js';
import { SubscriptionService } from '../services/subscription-service.js';

export class NotificationService {
  constructor(
    private readonly repository: SubscriberRepository,
    private readonly subscriptions: SubscriptionService,
    private readonly quota: QuotaService,
    private readonly now: () => Date = () => new Date(),
  ) {}

  async list(principal: SubscriberPrincipal) {
    const [dashboard, devices] = await Promise.all([
      this.repository.getDashboard(principal.username),
      this.repository.getDevices(principal, 100),
    ]);
    const notifications: DerivedNotification[] = [];
    if (dashboard) {
      notifications.push(...this.checkExpiryWarnings(dashboard));
      notifications.push(...this.checkQuotaWarnings(dashboard));
    }
    notifications.push(...this.detectNewDevice(devices));
    await this.repository.upsertDerivedNotifications({
      username: principal.username,
      notifications,
    });
    return (await this.repository.getNotifications(principal.username, 100)).map((item) => ({
      id: item.id,
      type: item.type,
      title: item.title,
      body: item.body,
      created_at: item.createdAt.toISOString(),
      is_read: item.isRead,
    }));
  }

  async markRead(principal: SubscriberPrincipal, id: string) {
    if (!(await this.repository.markNotificationRead(principal.username, id))) {
      throw new AppError(404, 'NOTIFICATION_NOT_FOUND', 'الإشعار غير موجود.');
    }
    return { id, is_read: true };
  }

  checkExpiryWarnings(dashboard: DashboardRecord): DerivedNotification[] {
    const days = this.subscriptions.daysRemaining(dashboard.expiresAt, this.now());
    if (days === null || days > 7) return [];
    if (days === 0) {
      return [{ type: 'packageExpired', title: 'انتهى الاشتراك', body: 'يرجى تجديد اشتراكك لاستعادة الخدمة.' }];
    }
    return [{
      type: 'expiringSoon',
      title: 'الاشتراك يوشك على الانتهاء',
      body: `متبقي ${days} يوم على انتهاء الاشتراك.`,
    }];
  }

  checkQuotaWarnings(dashboard: DashboardRecord): DerivedNotification[] {
    const result = this.quota.calculate(dashboard.totalBytes, dashboard.usedBytes);
    if (result.totalBytes === 0n || result.usagePercentage < 75) return [];
    if (result.remainingBytes === 0n) {
      return [{ type: 'dataExhausted', title: 'نفدت الباقة', body: 'تم استهلاك كامل رصيد البيانات.' }];
    }
    const threshold = result.usagePercentage >= 90 ? 90 : 75;
    return [{
      type: threshold === 90 ? 'usage90' : 'usage75',
      title: `استهلكت ${threshold}% من الباقة`,
      body: 'يمكنك متابعة الاستهلاك أو شحن رصيد بيانات إضافي.',
    }];
  }

  detectNewDevice(devices: readonly DeviceRecord[]): DerivedNotification[] {
    const cutoff = this.now().getTime() - 24 * 60 * 60 * 1000;
    return devices
      .filter((device) => device.firstSeenAt.getTime() >= cutoff)
      .map((device) => ({
        type: 'newDevice',
        title: 'جهاز جديد على الحساب',
        body: `تم رصد الجهاز ${device.callingStationId}.`,
      }));
  }
}
