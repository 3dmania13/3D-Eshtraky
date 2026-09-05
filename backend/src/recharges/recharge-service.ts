import type { SubscriberRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal } from '../domain/models.js';
import { asSafeNumber } from '../utils/values.js';

export class RechargeService {
  constructor(private readonly repository: SubscriberRepository) {}

  async list(principal: SubscriberPrincipal) {
    const records = await this.repository.getRecharges(principal.username, 100);
    return records.map((record) => ({
      id: `${record.source}:${record.id}`,
      created_at: record.createdAt.toISOString(),
      date: record.createdAt.toISOString().slice(0, 10),
      time: record.createdAt.toISOString().slice(11, 19),
      amount: record.amount,
      currency: record.amount === null ? null : 'YER',
      package_name: record.packageName,
      added_bytes: asSafeNumber(record.addedBytes, 'added_bytes'),
      validity_days: record.validityDays,
      generated_expiry: record.generatedExpiry?.toISOString() ?? null,
      reference_id: `${record.source}:${record.id}`,
      status: record.status,
    }));
  }
}
