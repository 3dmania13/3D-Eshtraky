import { AppError } from '../errors.js';

export function asBigInt(value: unknown, field: string): bigint {
  try {
    return BigInt(typeof value === 'string' || typeof value === 'number' ? value : 0);
  } catch {
    throw new AppError(500, 'INVALID_DATABASE_VALUE', `Invalid ${field}.`);
  }
}

export function asSafeNumber(value: bigint, field: string): number {
  if (value > BigInt(Number.MAX_SAFE_INTEGER) || value < 0n) {
    throw new AppError(500, 'INVALID_DATABASE_VALUE', `Unsafe ${field}.`);
  }
  return Number(value);
}

export function asDate(value: unknown): Date | null {
  if (value === null || value === undefined || value === '') return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const parsed = new Date(`${String(value).replace(' ', 'T')}Z`);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function requiredDate(value: unknown, field: string): Date {
  const date = asDate(value);
  if (!date) throw new AppError(500, 'INVALID_DATABASE_VALUE', `Invalid ${field}.`);
  return date;
}

export function normalizeUsername(value: string): string {
  const username = value.trim();
  if (!/^[^\u0000-\u001f\u007f]{1,64}$/u.test(username)) {
    throw new AppError(400, 'INVALID_USERNAME', 'اسم المستخدم غير صالح.');
  }
  return username;
}
