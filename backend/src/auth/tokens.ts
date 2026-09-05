import { createHash, randomBytes, timingSafeEqual } from 'node:crypto';

export function passwordMatches(input: string, expected: string): boolean {
  const inputDigest = createHash('sha256').update(input, 'utf8').digest();
  const expectedDigest = createHash('sha256').update(expected, 'utf8').digest();
  return timingSafeEqual(inputDigest, expectedDigest);
}

export function createRefreshToken(): string {
  return randomBytes(48).toString('base64url');
}

export function hashRefreshToken(token: string): string {
  return createHash('sha256').update(token, 'utf8').digest('hex');
}
