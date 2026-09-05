export interface QuotaCalculation {
  readonly totalBytes: bigint;
  readonly usedBytes: bigint;
  readonly remainingBytes: bigint;
  readonly usagePercentage: number;
  readonly remainingGib: number;
}

export class QuotaService {
  calculate(totalBytes: bigint, usedBytes: bigint): QuotaCalculation {
    const safeTotal = totalBytes < 0n ? 0n : totalBytes;
    const safeUsed = usedBytes < 0n ? 0n : usedBytes;
    const remainingBytes = safeUsed >= safeTotal ? 0n : safeTotal - safeUsed;
    const usagePercentage =
      safeTotal === 0n
        ? 0
        : Math.min(100, Number((safeUsed * 10_000n) / safeTotal) / 100);
    return {
      totalBytes: safeTotal,
      usedBytes: safeUsed,
      remainingBytes,
      usagePercentage,
      remainingGib: Number(remainingBytes) / 1024 ** 3,
    };
  }
}
