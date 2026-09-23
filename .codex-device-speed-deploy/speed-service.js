import { AppError } from '../errors.js';
const selections = {
    '512K': '512K/512K', '1M': '1M/1M', '2M': '2M/2M', '3M': '3M/3M',
    '4M': '4M/4M', '5M': '5M/5M', open: 'open',
};
export class SpeedService {
    repository;
    live;
    pending = new Set();
    constructor(repository, live) {
        this.repository = repository;
        this.live = live;
    }
    async get(principal) {
        const stored = await this.repository.getSpeedSelection(principal.username);
        const selection = Object.entries(selections).find(([, value]) => value === stored)?.[0] ?? 'open';
        return { selection, options: Object.keys(selections) };
    }
    async set(principal, selection) {
        const rate = selections[selection];
        if (!rate)
            throw new AppError(400, 'INVALID_SPEED', 'السرعة المختارة غير صحيحة.');
        if (this.pending.has(principal.username)) {
            throw new AppError(409, 'SPEED_UPDATE_IN_PROGRESS', 'جارٍ تطبيق تغيير السرعة السابق.');
        }
        this.pending.add(principal.username);
        try {
            await this.repository.setSpeedSelection({ username: principal.username, selection: rate });
            // A global speed update can temporarily replace a device-specific CoA.
            // Clearing only application state makes the device worker restore each
            // saved device override without changing RADIUS or subscriber data.
            await this.repository.invalidateDeviceSpeedApplications(principal.username);
            let live = { status: 'pending', active_sessions: 0, updated_sessions: 0 };
            try {
                if (this.live)
                    live = await this.live.apply(principal.username, rate);
            }
            catch { /* The saved selection still applies on the next connection. */ }
            return {
                selection, ...live,
                applied_immediately: live.status === 'applied',
                applies_on_next_connection: live.status !== 'applied',
            };
        }
        finally {
            this.pending.delete(principal.username);
        }
    }
}
//# sourceMappingURL=speed-service.js.map