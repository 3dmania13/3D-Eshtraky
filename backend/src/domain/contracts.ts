import type {
  AuthSubscriberRecord,
  DailyUsageRecord,
  DashboardRecord,
  DeviceRecord,
  NotificationRecord,
  RechargeRecord,
  SessionRecord,
  SubscriberPrincipal,
  SubscriberProfileRecord,
  UsageSummaryRecord,
} from './models.js';

export interface AuthRepository {
  findForAuthentication(username: string): Promise<AuthSubscriberRecord | null>;
  issueRefreshToken(input: {
    subscriberUsername: string;
    tokenHash: string;
    expiresAt: Date;
  }): Promise<void>;
  rotateRefreshToken(input: {
    currentHash: string;
    replacementHash: string;
    replacementExpiresAt: Date;
  }): Promise<AuthSubscriberRecord | null>;
  revokeRefreshToken(tokenHash: string): Promise<void>;
}

export interface SubscriberRepository {
  getProfile(username: string): Promise<SubscriberProfileRecord | null>;
  getDashboard(username: string): Promise<DashboardRecord | null>;
  getUsageSummary(
    username: string,
    ranges: UsageDateRanges,
  ): Promise<UsageSummaryRecord>;
  getDailyUsage(username: string, from: string, to: string): Promise<DailyUsageRecord[]>;
  getSessions(username: string, limit: number): Promise<SessionRecord[]>;
  getDevices(subscriber: SubscriberPrincipal, limit: number): Promise<DeviceRecord[]>;
  renameDevice(input: {
    subscriber: SubscriberPrincipal;
    deviceId: string;
    friendlyName: string;
  }): Promise<DeviceRecord | null>;
  getRecharges(username: string, limit: number): Promise<RechargeRecord[]>;
  upsertDerivedNotifications(input: {
    username: string;
    notifications: readonly DerivedNotification[];
  }): Promise<void>;
  getNotifications(username: string, limit: number): Promise<NotificationRecord[]>;
  markNotificationRead(username: string, notificationId: string): Promise<boolean>;
}

export interface UsageDateRanges {
  readonly today: string;
  readonly yesterday: string;
  readonly weekFrom: string;
  readonly monthFrom: string;
}

export interface DerivedNotification {
  readonly type: string;
  readonly title: string;
  readonly body: string;
}
