import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { Pool, RowDataPacket } from 'mysql2/promise';
import { AppError } from '../errors.js';
import { asDate, normalizeUsername } from '../utils/values.js';
import { createRefreshToken, hashRefreshToken } from '../auth/tokens.js';
import { registerBroadbandServices } from './broadband-services.js';

// Broadband identities must never enter the hotspot subscriber routes.
export function registerBroadbandRoutes(app: FastifyInstance, pool: Pool, timezone = 'Asia/Aden'): void {
  const rateLimitError = () => new AppError(429, 'TOO_MANY_REQUESTS', 'محاولات كثيرة. انتظر دقيقة ثم حاول مجددًا.');
  async function profile(username: string) {
    const [rows] = await pool.execute<RowDataPacket[]>(
      `SELECT u.username,u.full_name,u.mobilephone,u.address,u.status,
              u.radius_enabled,u.total_quota,u.used_quota,u.activated_at,
              u.expires_at,p.name AS package_name,p.upload_speed,
              p.download_speed,p.rate_limit
         FROM nawa_pppoe_users u
         LEFT JOIN nawa_pppoe_packages p ON p.id=u.package_id
        WHERE u.username=? LIMIT 1`, [username]);
    const row = rows[0];
    if (!row) throw new AppError(401, 'INVALID_BROADBAND_USERNAME', 'اسم مستخدم البرودباند غير صحيح.');
    const total = BigInt(row.total_quota ?? 0);
    const used = BigInt(row.used_quota ?? 0);
    const expiry = asDate(row.expires_at);
    return {
      username: String(row.username),
      fullName: String(row.full_name ?? ''),
      phone: String(row.mobilephone ?? ''),
      address: String(row.address ?? ''),
      status: row.status === 'disabled' ? 'disabled'
        : row.status === 'expired' || (expiry && expiry.getTime() <= Date.now()) ? 'expired'
        : total > 0n && used >= total ? 'exhausted' : 'active',
      radiusEnabled: Boolean(Number(row.radius_enabled)),
      packageName: String(row.package_name ?? 'بدون باقة'),
      totalBytes: total.toString(), usedBytes: used.toString(),
      remainingBytes: total === 0n ? null : (total > used ? total - used : 0n).toString(),
      uploadSpeed: String(row.upload_speed ?? ''),
      downloadSpeed: String(row.download_speed ?? ''),
      rateLimit: String(row.rate_limit ?? ''),
      activatedAt: asDate(row.activated_at)?.toISOString() ?? null,
      expiresAt: expiry?.toISOString() ?? null,
    };
  }

  async function requireBroadband(request: FastifyRequest) {
    try { await request.jwtVerify(); }
    catch { throw new AppError(401, 'INVALID_TOKEN', 'انتهت الجلسة. سجل الدخول مجددًا.'); }
    if (request.user.role !== 'broadband' || !request.user.username ||
        request.user.sub !== request.user.username) {
      throw new AppError(403, 'BROADBAND_ACCESS_REQUIRED', 'غير مصرح بالوصول.');
    }
    await profile(request.user.username);
  }

  const expiry = () => new Date(Date.now() + 30 * 86400000);
  async function issue(username: string) {
    const subscriber = await profile(username);
    const refreshToken = 'bb_' + createRefreshToken();
    await pool.execute(`INSERT INTO broadband_refresh_tokens(subscriber_username,token_hash,expires_at)
      VALUES (?,?,?)`, [username, hashRefreshToken(refreshToken), expiry()]);
    return { accessToken: access(subscriber), refreshToken, subscriber };
  }
  function access(subscriber: Awaited<ReturnType<typeof profile>>) {
    return app.jwt.sign({ sub: subscriber.username, username: subscriber.username,
      role: 'broadband', status: subscriber.status === 'exhausted' ? 'active' : subscriber.status as 'active' | 'expired' | 'disabled' },
      { expiresIn: '1h' });
  }
  const refreshBody = { body: { type: 'object', additionalProperties: false,
    required: ['refreshToken'], properties: { refreshToken: { type: 'string', pattern: '^bb_[A-Za-z0-9_-]{32,200}$' } } } };
  app.post<{ Body: { refreshToken: string } }>('/api/v1/auth/broadband-refresh', {
    schema: refreshBody, config: { rateLimit: { max: 20, timeWindow: '1 minute' } },
  }, async request => {
    const connection = await pool.getConnection();
    try {
      await connection.beginTransaction();
      const [rows] = await connection.query<RowDataPacket[]>(
        `SELECT id,subscriber_username FROM broadband_refresh_tokens
          WHERE token_hash=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE`,
        [hashRefreshToken(request.body.refreshToken)]);
      if (!rows[0]) throw new AppError(401, 'INVALID_TOKEN', 'انتهت الجلسة. سجل الدخول مجددًا.');
      const subscriber = await profile(String(rows[0].subscriber_username));
      const refreshToken = 'bb_' + createRefreshToken();
      await connection.execute('UPDATE broadband_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE id=?', [rows[0].id]);
      await connection.execute(`INSERT INTO broadband_refresh_tokens(subscriber_username,token_hash,expires_at)
        VALUES (?,?,?)`, [subscriber.username, hashRefreshToken(refreshToken), expiry()]);
      await connection.commit();
      return { accessToken: access(subscriber), refreshToken, subscriber };
    } catch (error) { await connection.rollback(); throw error; }
    finally { connection.release(); }
  });
  app.post<{ Body: { refreshToken: string } }>('/api/v1/auth/broadband-logout', { schema: refreshBody }, async request => {
    await pool.execute('UPDATE broadband_refresh_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE token_hash=?',
      [hashRefreshToken(request.body.refreshToken)]);
    return { loggedOut: true };
  });

  app.post<{ Body: { username: string } }>('/api/v1/auth/broadband-login', {
    config: { rateLimit: { max: 5, timeWindow: '1 minute', errorResponseBuilder: rateLimitError } },
    schema: { body: { type: 'object', additionalProperties: false,
      required: ['username'], properties: { username: { type: 'string', minLength: 1, maxLength: 64 } } } },
  }, async request => {
    return issue(normalizeUsername(request.body.username));
  });

  app.get('/api/v1/broadband/profile', { preHandler: requireBroadband },
    request => profile(request.user.username));

  app.patch<{ Body: { fullName: string; phone: string; address: string } }>(
    '/api/v1/broadband/profile', {
      preHandler: requireBroadband,
      config: { rateLimit: { max: 10, timeWindow: '1 minute', errorResponseBuilder: rateLimitError } },
      schema: { body: { type: 'object', additionalProperties: false,
        required: ['fullName', 'phone', 'address'], properties: {
          fullName: { type: 'string', maxLength: 150 },
          phone: { type: 'string', maxLength: 50 },
          address: { type: 'string', maxLength: 255 },
        } } },
    }, async request => {
      await profile(request.user.username);
      await pool.execute(`UPDATE nawa_pppoe_users SET full_name=?,mobilephone=?,address=?
        WHERE username=?`, [request.body.fullName.trim(), request.body.phone.trim(),
        request.body.address.trim(), request.user.username]);
      return profile(request.user.username);
    });
  registerBroadbandServices(app, pool, requireBroadband, timezone);
}
