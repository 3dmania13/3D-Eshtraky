import type { AuthService } from '../auth/auth-service.js';
import type { DeviceService } from '../devices/device-service.js';
import type { DeviceSpeedService } from '../devices/device-speed-service.js';
import type { NotificationService } from '../notifications/notification-service.js';
import type { RechargeService } from '../recharges/recharge-service.js';
import type { SessionService } from '../sessions/session-service.js';
import type { SubscriberService } from '../subscriber/subscriber-service.js';
import type { UsageService } from '../usage/usage-service.js';
import type { SpeedService } from '../speed/speed-service.js';
import type { ConnectionLimitService } from '../connection-limits/connection-limit-service.js';

export interface AppServices {
  readonly auth: AuthService;
  readonly subscriber: SubscriberService;
  readonly usage: UsageService;
  readonly sessions: SessionService;
  readonly devices: DeviceService;
  readonly deviceSpeeds: DeviceSpeedService;
  readonly recharges: RechargeService;
  readonly notifications: NotificationService;
  readonly speed: SpeedService;
  readonly connectionLimits: ConnectionLimitService;
}
