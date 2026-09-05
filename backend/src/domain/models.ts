export type SubscriberState = 'active' | 'expired' | 'disabled';

export interface AuthSubscriberRecord {
  readonly id: string;
  readonly username: string;
  readonly rawStatus: string;
  readonly isDisabled: boolean;
  readonly isInDisabledGroup: boolean;
  readonly expiresAt: Date | null;
  readonly cleartextPassword: string | null;
}

export interface SubscriberPrincipal {
  readonly username: string;
  readonly status: SubscriberState;
}

export interface DashboardRecord extends Omit<AuthSubscriberRecord, 'cleartextPassword'> {
  readonly packageId: string | null;
  readonly packageName: string;
  readonly totalBytes: bigint;
  readonly usedBytes: bigint;
  readonly startedAt: Date | null;
  readonly activeDeviceCount: number;
  readonly isConnected: boolean;
}

export interface SubscriberProfileRecord {
  readonly username: string;
  readonly rawStatus: string;
  readonly isDisabled: boolean;
  readonly isInDisabledGroup: boolean;
  readonly expiresAt: Date | null;
  readonly packageName: string;
}

export interface UsageBreakdownRecord {
  readonly downloadBytes: bigint;
  readonly uploadBytes: bigint;
}

export interface UsageSummaryRecord {
  readonly today: UsageBreakdownRecord;
  readonly yesterday: UsageBreakdownRecord;
  readonly week: UsageBreakdownRecord;
  readonly month: UsageBreakdownRecord;
}

export interface DailyUsageRecord extends UsageBreakdownRecord {
  readonly date: string;
}

export interface SessionRecord {
  readonly sessionId: string;
  readonly startTime: Date;
  readonly stopTime: Date | null;
  readonly durationSeconds: number;
  readonly uploadBytes: bigint;
  readonly downloadBytes: bigint;
  readonly framedIp: string;
  readonly callingStationId: string;
  readonly networkIdentifier: string;
  readonly isActive: boolean;
}

export interface DeviceRecord {
  readonly id: string;
  readonly friendlyName: string | null;
  readonly callingStationId: string;
  readonly ipAddress: string;
  readonly connectionStartedAt: Date;
  readonly sessionDurationSeconds: number;
  readonly firstSeenAt: Date;
  readonly lastSeenAt: Date;
  readonly currentSessionBytes: bigint;
  readonly isOnline: boolean;
}

export interface RechargeRecord {
  readonly id: string;
  readonly source: 'dealer' | 'operator' | 'legacy';
  readonly createdAt: Date;
  readonly amount: number | null;
  readonly packageName: string;
  readonly addedBytes: bigint;
  readonly validityDays: number | null;
  readonly generatedExpiry: Date | null;
  readonly status: 'successful' | 'pending' | 'failed';
}

export interface NotificationRecord {
  readonly id: string;
  readonly type: string;
  readonly title: string;
  readonly body: string;
  readonly createdAt: Date;
  readonly isRead: boolean;
}
