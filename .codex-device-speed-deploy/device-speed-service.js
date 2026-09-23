import { AppError } from "../errors.js";
const selections = {
    default: "open",
    "512K": "512K/512K",
    "1M": "1M/1M",
    "2M": "2M/2M",
    "3M": "3M/3M",
    "4M": "4M/4M",
    "5M": "5M/5M",
};
export class DeviceSpeedService {
    repository;
    live;
    pending = new Set();
    constructor(repository, live) {
        this.repository = repository;
        this.live = live;
    }
    async set(principal, deviceId, selection) {
        const rate = selections[selection];
        if (!rate) {
            throw new AppError(400, "INVALID_DEVICE_SPEED", "السرعة المختارة غير صحيحة.");
        }
        const key = `${principal.username}:${deviceId}`;
        if (this.pending.has(key)) {
            throw new AppError(409, "DEVICE_SPEED_UPDATE_IN_PROGRESS", "جارٍ تطبيق تغيير سرعة هذا الجهاز.");
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
            let live = {
                status: "pending",
                activeSessions: 0,
                updatedSessions: 0,
            };
            try {
                if (this.live) {
                    live = await this.live.apply(principal.username, device.callingStationId, rate);
                }
            }
            catch {
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
        }
        finally {
            this.pending.delete(key);
        }
    }
}
//# sourceMappingURL=device-speed-service.js.map