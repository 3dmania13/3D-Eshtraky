import type { SubscriberRepository } from "../domain/contracts.js";
import type { SubscriberPrincipal } from "../domain/models.js";
import { AppError } from "../errors.js";
import type {
  LiveDeviceSpeedApplier,
  LiveDeviceSpeedResult,
} from "./live-device-speed-service.js";

const selections = {
  default: "open",
  "512K": "512K/512K",
  "1M": "1M/1M",
  "2M": "2M/2M",
  "3M": "3M/3M",
  "4M": "4M/4M",
  "5M": "5M/5M",
} as const;

export class DeviceSpeedService {
  private readonly pending = new Set<string>();

  constructor(
    private readonly repository: SubscriberRepository,
    private readonly live?: LiveDeviceSpeedApplier,
  ) {}

  async set(
    principal: SubscriberPrincipal,
    deviceId: string,
    selection: string,
  ) {
    const rate = selections[selection as keyof typeof selections];
    if (!rate) {
      throw new AppError(400, "INVALID_DEVICE_SPEED", "السرعة المختارة غير صحيحة.");
    }
    const key = `${principal.username}:${deviceId}`;
    if (this.pending.has(key)) {
      throw new AppError(
        409,
        "DEVICE_SPEED_UPDATE_IN_PROGRESS",
        "جارٍ تطبيق تغيير سرعة هذا الجهاز.",
      );
    }
    this.pending.add(key);
    try {
      const device = await this.repository.setDeviceSpeed({
        subscriber: principal,
        deviceId,
        selection: selection === "default" ? null : selection,
      });
      if (!device) {
        throw new AppError(404, "DEVICE_NOT_FOUND", "الجهاز غير موجود.");
      }
      let live: LiveDeviceSpeedResult = {
        status: "pending",
        activeSessions: 0,
        updatedSessions: 0,
      };
      try {
        if (this.live) {
          live = await this.live.apply(
            principal.username,
            device.callingStationId,
            rate,
          );
        }
      } catch {
        // The saved rule is picked up as soon as the device has a live session.
      }
      return {
        selection,
        options: Object.keys(selections),
        active_sessions: live.activeSessions,
        updated_sessions: live.updatedSessions,
        applied_immediately: live.status === "applied",
        applies_on_next_connection: live.status !== "applied",
      };
    } finally {
      this.pending.delete(key);
    }
  }
}
