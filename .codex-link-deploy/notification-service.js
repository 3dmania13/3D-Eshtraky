import { AppError } from "../errors.js";
import { QuotaService } from "../services/quota-service.js";
import { SubscriptionService } from "../services/subscription-service.js";
export class NotificationService {
    repository;
    subscriptions;
    quota;
    now;
    constructor(repository, subscriptions, quota, now = () => new Date()) {
        this.repository = repository;
        this.subscriptions = subscriptions;
        this.quota = quota;
        this.now = now;
    }
    async list(principal) {
        const [dashboard, devices] = await Promise.all([
            this.repository.getDashboard(principal.username),
            this.repository.getDevices(principal, 100),
        ]);
        const notifications = [];
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
            link_title: item.linkTitle,
            link_url: item.linkUrl,
            created_at: item.createdAt.toISOString(),
            is_read: item.isRead,
        }));
    }
    async markRead(principal, id) {
        if (!(await this.repository.markNotificationRead(principal.username, id))) {
            throw new AppError(404, "NOTIFICATION_NOT_FOUND", "الإشعار غير موجود.");
        }
        return { id, is_read: true };
    }
    async markAllRead(principal) {
        const updated = await this.repository.markAllNotificationsRead(principal.username);
        return { updated };
    }
    async registerPushToken(principal, token) {
        await this.repository.registerPushToken({
            username: principal.username,
            token,
            platform: "android",
        });
        return { registered: true };
    }
    checkExpiryWarnings(dashboard) {
        const days = this.subscriptions.daysRemaining(dashboard.expiresAt, this.now());
        if (days === null || days > 7)
            return [];
        if (days === 0) {
            return [
                {
                    type: "packageExpired",
                    title: "انتهى الاشتراك",
                    body: "يرجى تجديد اشتراكك لاستعادة الخدمة.",
                },
            ];
        }
        return [
            {
                type: "expiringSoon",
                title: "الاشتراك يوشك على الانتهاء",
                body: `متبقي ${days} يوم على انتهاء الاشتراك.`,
            },
        ];
    }
    checkQuotaWarnings(dashboard) {
        const result = this.quota.calculate(dashboard.totalBytes, dashboard.usedBytes);
        if (result.totalBytes === 0n)
            return [];
        if (result.remainingBytes === 0n) {
            return [
                {
                    type: "dataExhausted",
                    title: "نفدت الباقة",
                    body: "تم استهلاك كامل رصيد البيانات.",
                },
            ];
        }
        if (result.remainingBytes <= 1024n ** 3n) {
            return [
                {
                    type: "lowBalance",
                    title: "رصيدك على وشك الانتهاء",
                    body: "لديك أقل من 1 جيجا من رصيد البيانات. اشحن رصيدك لتجنب انقطاع الخدمة.",
                },
            ];
        }
        if (result.usagePercentage < 75)
            return [];
        const threshold = result.usagePercentage >= 90 ? 90 : 75;
        return [
            {
                type: threshold === 90 ? "usage90" : "usage75",
                title: `استهلكت ${threshold}% من الباقة`,
                body: "يمكنك متابعة الاستهلاك أو شحن رصيد بيانات إضافي.",
            },
        ];
    }
    detectNewDevice(devices) {
        const cutoff = this.now().getTime() - 24 * 60 * 60 * 1000;
        return devices
            .filter((device) => device.firstSeenAt.getTime() >= cutoff)
            .map((device) => ({
            type: "newDevice",
            title: "جهاز جديد على الحساب",
            body: `تم رصد الجهاز ${device.callingStationId}.`,
        }));
    }
}
//# sourceMappingURL=notification-service.js.map