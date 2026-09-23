import type { SubscriberRepository } from "../domain/contracts.js";
import type { DeviceRecord, SubscriberPrincipal } from "../domain/models.js";
import { AppError } from "../errors.js";
import { asSafeNumber } from "../utils/values.js";
import type { LiveDeviceDisconnector } from "./live-device-disconnect-service.js";

function present(device: DeviceRecord) {
  return {
    id: device.id,
    friendly_name: device.friendlyName ?? device.callingStationId,
    mac_address: device.callingStationId,
    ip_address: device.ipAddress,
    connection_started_at: device.connectionStartedAt.toISOString(),
    session_duration_seconds: device.sessionDurationSeconds,
    first_seen_at: device.firstSeenAt.toISOString(),
    last_seen_at: device.lastSeenAt.toISOString(),
    current_session_bytes: asSafeNumber(
      device.currentSessionBytes,
      "current_session_bytes",
    ),
    is_online: device.isOnline,
  };
}

export class DeviceService {
  private readonly pendingDisconnects = new Set<string>();

  constructor(
    private readonly repository: SubscriberRepository,
    private readonly live?: LiveDeviceDisconnector,
  ) {}

  async list(principal: SubscriberPrincipal) {
    return (await this.repository.getDevices(principal, 100)).map(present);
  }

  async rename(
    principal: SubscriberPrincipal,
    id: string,
    friendlyNameInput: string,
  ) {
    const friendlyName = friendlyNameInput.trim();
    if (friendlyName.length < 1 || friendlyName.length > 80) {
      throw new AppError(
        400,
        "INVALID_FRIENDLY_NAME",
        "اسم الجهاز يجب أن يكون بين 1 و80 حرفاً.",
      );
    }
    const device = await this.repository.renameDevice({
      subscriber: principal,
      deviceId: id,
      friendlyName,
    });
    if (!device)
      throw new AppError(404, "DEVICE_NOT_FOUND", "الجهاز غير موجود.");
    return present(device);
  }

  async disconnect(principal: SubscriberPrincipal, id: string) {
    const key = `${principal.username}:${id}`;
    if (this.pendingDisconnects.has(key)) {
      throw new AppError(
        409,
        "DEVICE_DISCONNECT_IN_PROGRESS",
        "جارٍ فصل هذا الجهاز.",
      );
    }
    const device = (await this.repository.getDevices(principal, 100)).find(
      (item) => item.id === id,
    );
    if (!device)
      throw new AppError(404, "DEVICE_NOT_FOUND", "الجهاز غير موجود.");
    if (!device.isOnline) {
      throw new AppError(409, "DEVICE_OFFLINE", "هذا الجهاز غير متصل حاليًا.");
    }
    if (!this.live) {
      throw new AppError(
        503,
        "DEVICE_DISCONNECT_UNAVAILABLE",
        "فصل الأجهزة غير متاح حاليًا.",
      );
    }
    this.pendingDisconnects.add(key);
    try {
      const result = await this.live.disconnect(
        principal.username,
        device.callingStationId,
      );
      if (result.status === "offline") {
        throw new AppError(409, "DEVICE_OFFLINE", "هذا الجهاز لم يعد متصلًا.");
      }
      if (result.status !== "disconnected") {
        throw new AppError(
          503,
          "DEVICE_DISCONNECT_NOT_CONFIRMED",
          "لم يتأكد فصل الجهاز. حاول مرة أخرى.",
        );
      }
      return {
        disconnected: true,
        active_sessions: result.activeSessions,
        disconnected_sessions: result.disconnectedSessions,
      };
    } finally {
      this.pendingDisconnects.delete(key);
    }
  }
}
