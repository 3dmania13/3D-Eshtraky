import { AppError } from '../errors.js';
const minimumLimit = 1;
const maximumLimit = 10;
/** Manages the RADIUS Simultaneous-Use value for one subscriber account. */
export class ConnectionLimitService {
    repository;
    constructor(repository) {
        this.repository = repository;
    }
    async get(principal) {
        const limit = await this.repository.getConnectionLimit(principal.username);
        return {
            limit,
            options: Array.from({ length: maximumLimit - minimumLimit + 1 }, (_, index) => index + minimumLimit),
        };
    }
    async set(principal, limit) {
        if (!Number.isInteger(limit) || limit < minimumLimit || limit > maximumLimit) {
            throw new AppError(400, 'INVALID_CONNECTION_LIMIT', 'اختر عددًا من 1 إلى 10 أجهزة.');
        }
        await this.repository.setConnectionLimit({
            username: principal.username,
            limit,
        });
        return { limit };
    }
}
//# sourceMappingURL=connection-limit-service.js.map