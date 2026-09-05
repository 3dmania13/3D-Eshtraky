import type { SubscriberRepository } from '../domain/contracts.js';
import type { DeviceRecord, SubscriberPrincipal } from '../domain/models.js';
import { AppError } from '../errors.js';
import { asSafeNumber } from '../utils/values.js';

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
    current_session_bytes: asSafeNumber(device.currentSessionBytes, 'current_session_bytes'),
    is_online: device.isOnline,
  };
}

export class DeviceService {
  constructor(private readonly repository: SubscriberRepository) {}

  async list(principal: SubscriberPrincipal) {
    return (await this.repository.getDevices(principal, 100)).map(present);
  }

  async rename(principal: SubscriberPrincipal, id: string, friendlyNameInput: string) {
    const friendlyName = friendlyNameInput.trim();
    if (friendlyName.length < 1 || friendlyName.length > 80) {
      throw new AppError(400, 'INVALID_FRIENDLY_NAME', 'اسم الجهاز يجب أن يكون بين 1 و80 حرفاً.');
    }
    const device = await this.repository.renameDevice({
      subscriber: principal,
      deviceId: id,
      friendlyName,
    });
    if (!device) throw new AppError(404, 'DEVICE_NOT_FOUND', 'الجهاز غير موجود.');
    return present(device);
  }
}
