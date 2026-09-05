import type { SubscriberRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal, UsageBreakdownRecord } from '../domain/models.js';
import { AppError } from '../errors.js';
import { asSafeNumber } from '../utils/values.js';
import { localIsoDate, shiftIsoDate, usageRanges } from '../utils/dates.js';

function breakdown(record: UsageBreakdownRecord) {
  const downloadBytes = asSafeNumber(record.downloadBytes, 'download_bytes');
  const uploadBytes = asSafeNumber(record.uploadBytes, 'upload_bytes');
  return {
    download_bytes: downloadBytes,
    upload_bytes: uploadBytes,
    total_bytes: downloadBytes + uploadBytes,
  };
}

export class UsageService {
  constructor(
    private readonly repository: SubscriberRepository,
    private readonly timeZone: string,
    private readonly now: () => Date = () => new Date(),
  ) {}

  async summary(principal: SubscriberPrincipal) {
    const result = await this.repository.getUsageSummary(
      principal.username,
      usageRanges(this.now(), this.timeZone),
    );
    return {
      today: breakdown(result.today),
      yesterday: breakdown(result.yesterday),
      week: breakdown(result.week),
      month: breakdown(result.month),
    };
  }

  async daily(principal: SubscriberPrincipal, from?: string, to?: string) {
    const defaultTo = localIsoDate(this.now(), this.timeZone);
    const effectiveFrom = from ?? shiftIsoDate(defaultTo, -29);
    const effectiveTo = to ?? defaultTo;
    this.validateRange(effectiveFrom, effectiveTo);
    const records = await this.repository.getDailyUsage(
      principal.username,
      effectiveFrom,
      effectiveTo,
    );
    return records.map((record) => ({ date: record.date, ...breakdown(record) }));
  }

  private validateRange(from: string, to: string): void {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) {
      throw new AppError(400, 'INVALID_DATE_RANGE', 'نطاق التاريخ غير صالح.');
    }
    const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000;
    if (!Number.isFinite(days) || days < 0 || days > 366) {
      throw new AppError(400, 'INVALID_DATE_RANGE', 'نطاق التاريخ يجب ألا يتجاوز 366 يوماً.');
    }
  }
}
