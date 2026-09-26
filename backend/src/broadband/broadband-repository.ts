import type { Pool, RowDataPacket } from 'mysql2/promise';
import { MySqlSubscriberRepository } from '../database/mysql-subscriber-repository.js';
import type { DashboardRecord, RechargeRecord } from '../domain/models.js';
import { asBigInt, asDate, requiredDate } from '../utils/values.js';

// RADIUS accounting and app preferences share the network's unique username.
// Package, balance and recharge records belong to the PPPoE account tables.
export class BroadbandRepository extends MySqlSubscriberRepository {
  constructor(private readonly broadbandPool: Pool) { super(broadbandPool); }

  override async getDashboard(username: string): Promise<DashboardRecord | null> {
    const [rows] = await this.broadbandPool.query<RowDataPacket[]>(
      `SELECT u.id,u.username,u.status,u.radius_enabled,u.package_id,u.total_quota,
              u.used_quota,u.activated_at,u.expires_at,p.name AS package_name,
              (SELECT COUNT(DISTINCT NULLIF(r.callingstationid,'')) FROM radacct r
                WHERE r.username=CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_general_ci
                  AND r.acctstoptime IS NULL
                  AND COALESCE(r.acctupdatetime,r.acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE) AS active_devices
         FROM nawa_pppoe_users u LEFT JOIN nawa_pppoe_packages p ON p.id=u.package_id
        WHERE u.username=? LIMIT 1`, [username]);
    const r = rows[0];
    if (!r) return null;
    return { id: String(r.id), username: String(r.username), rawStatus: String(r.status),
      isDisabled: r.status === 'disabled', isInDisabledGroup: false,
      expiresAt: asDate(r.expires_at), packageId: r.package_id == null ? null : String(r.package_id),
      packageName: String(r.package_name ?? 'بدون باقة'),
      totalBytes: asBigInt(r.total_quota, 'total_quota'), usedBytes: asBigInt(r.used_quota, 'used_quota'),
      startedAt: asDate(r.activated_at), activeDeviceCount: Number(r.active_devices ?? 0),
      isConnected: Number(r.active_devices ?? 0) > 0 };
  }

  override async getProfile(username: string) { return this.getDashboard(username); }

  override async setSpeedSelection(input: { username: string; selection: string }) {
    if (input.selection !== 'open') return super.setSpeedSelection(input);
    const [rows] = await this.broadbandPool.execute<RowDataPacket[]>(
      `SELECT p.rate_limit,p.upload_speed,p.download_speed
         FROM nawa_pppoe_users u JOIN nawa_pppoe_packages p ON p.id=u.package_id
        WHERE u.username=? LIMIT 1`, [input.username]);
    const plan = rows[0];
    const rate = String(plan?.rate_limit ?? '').trim() ||
      `${String(plan?.upload_speed ?? '').trim() || '0'}/${String(plan?.download_speed ?? '').trim() || '0'}`;
    // PPPoE packages are written to radreply, not hotspot radusergroup.
    await super.setSpeedSelection({ username: input.username, selection: rate });
  }

  override async getRecharges(username: string, limit: number): Promise<RechargeRecord[]> {
    const [rows] = await this.broadbandPool.query<RowDataPacket[]>(
      `SELECT r.id,r.created_at,r.price,r.quota_bytes,r.validity_days,r.new_expires_at,p.name
         FROM nawa_pppoe_recharges r LEFT JOIN nawa_pppoe_packages p ON p.id=r.package_id
        WHERE r.username=? ORDER BY r.created_at DESC,r.id DESC LIMIT ?`, [username, limit]);
    return rows.map(r => ({ id: String(r.id), source: 'operator',
      createdAt: requiredDate(r.created_at, 'created_at'), amount: Number(r.price),
      packageName: String(r.name ?? 'شحن برودباند'), addedBytes: asBigInt(r.quota_bytes, 'quota_bytes'),
      validityDays: Number(r.validity_days), generatedExpiry: asDate(r.new_expires_at), status: 'successful' }));
  }
}
