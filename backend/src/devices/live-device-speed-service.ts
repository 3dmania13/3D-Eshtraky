import type { Pool, RowDataPacket } from "mysql2/promise";

import {
  sendCoa,
  type CoaRequest,
  type CoaSender,
} from "../speed/live-speed-service.js";

export interface LiveDeviceSpeedResult {
  status: "applied" | "offline" | "pending";
  activeSessions: number;
  updatedSessions: number;
}

export interface LiveDeviceSpeedApplier {
  apply(
    username: string,
    callingStationId: string,
    rate: string,
  ): Promise<LiveDeviceSpeedResult>;
}

interface ActiveDeviceSession extends RowDataPacket {
  nasipaddress: string;
  acctsessionid: string;
  framedipaddress: string;
}

interface NasRecord extends RowDataPacket {
  nasname: string;
  shortname: string;
  secret: string;
}

function routerFor(
  session: ActiveDeviceSession,
  routers: NasRecord[],
): NasRecord | undefined {
  const alias = /^33\.3\.3\.(11|22|33|44|55|66|77)$/.exec(
    String(session.nasipaddress),
  );
  return (
    routers.find((row) => row.nasname === session.nasipaddress) ??
    (alias
      ? routers.find(
          (row) =>
            String(row.shortname).toLowerCase() ===
            `network${Number(alias[1]) / 11}`,
        )
      : undefined)
  );
}

function coaPort(router: NasRecord): number {
  return ["network4", "network6"].includes(
    String(router.shortname).toLowerCase(),
  )
    ? 3799
    : 1700;
}

async function subscriptionRate(pool: Pool, username: string): Promise<string | null> {
  const [overrides] = await pool.execute<RowDataPacket[]>(
    `SELECT DISTINCT value FROM radreply
      WHERE username=? AND attribute='Mikrotik-Rate-Limit'
        AND NULLIF(value,'') IS NOT NULL`,
    [username],
  );
  if (overrides.length > 1) return null;
  if (overrides.length === 1) return String(overrides[0]?.value ?? "").trim() || null;
  const [groupRates] = await pool.execute<RowDataPacket[]>(
    `SELECT DISTINCT rgr.value FROM radusergroup rug
      JOIN radgroupreply rgr ON rgr.groupname=rug.groupname
     WHERE rug.username=? AND rgr.attribute='Mikrotik-Rate-Limit'
       AND NULLIF(rgr.value,'') IS NOT NULL`,
    [username],
  );
  if (groupRates.length > 1) return null;
  if (groupRates.length === 1) return String(groupRates[0]?.value ?? "").trim() || null;
  let [packages] = await pool.execute<RowDataPacket[]>(
    `SELECT p.rate_limit,p.upload_speed,p.download_speed
       FROM userinfo u JOIN packages p ON p.id=u.package_id
      WHERE u.username=? LIMIT 1`,
    [username],
  );
  if (!packages.length) {
    [packages] = await pool.execute<RowDataPacket[]>(
      `SELECT p.rate_limit,p.upload_speed,p.download_speed
         FROM nawa_pppoe_users u JOIN nawa_pppoe_packages p ON p.id=u.package_id
        WHERE u.username=? LIMIT 1`, [username]);
  }
  const plan = packages[0];
  if (!plan) return null;
  return (
    String(plan.rate_limit ?? "").trim() ||
    `${String(plan.upload_speed ?? "").trim() || "0"}/${String(plan.download_speed ?? "").trim() || "0"}`
  );
}

/** Applies a rate only to sessions belonging to one physical device. */
export class MySqlLiveDeviceSpeedApplier implements LiveDeviceSpeedApplier {
  constructor(
    private readonly pool: Pool,
    private readonly send: CoaSender = sendCoa,
  ) {}

  async apply(
    username: string,
    callingStationId: string,
    rate: string,
  ): Promise<LiveDeviceSpeedResult> {
    const resolvedRate = rate === "open" ? await subscriptionRate(this.pool, username) : rate;
    if (!resolvedRate) {
      return { status: "pending", activeSessions: 0, updatedSessions: 0 };
    }
    const [sessions] = await this.pool.execute<ActiveDeviceSession[]>(
      `SELECT DISTINCT nasipaddress,acctsessionid,framedipaddress
         FROM radacct
        WHERE username=? AND BINARY callingstationid=BINARY ?
          AND acctstoptime IS NULL
          AND COALESCE(acctupdatetime,acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
          AND NULLIF(acctsessionid,'') IS NOT NULL
        LIMIT 5`,
      [username, callingStationId],
    );
    if (sessions.length === 0) {
      return { status: "offline", activeSessions: 0, updatedSessions: 0 };
    }
    if (sessions.length > 4) {
      return {
        status: "pending",
        activeSessions: sessions.length,
        updatedSessions: 0,
      };
    }
    const [routers] = await this.pool.execute<NasRecord[]>(
      `SELECT nasname,shortname,secret FROM nas
        WHERE COALESCE(enabled,1)=1 AND secret<>''`,
    );
    const outcomes = await Promise.all(
      sessions.map(async (session) => {
        const router = routerFor(session, routers);
        if (!router) return false;
        try {
          const request: CoaRequest = {
            host: String(router.nasname),
            port: coaPort(router),
            secret: String(router.secret),
            username,
            sessionId: String(session.acctsessionid),
            address: String(session.framedipaddress),
            rate: resolvedRate,
          };
          return await this.send(request);
        } catch {
          return false;
        }
      }),
    );
    const updatedSessions = outcomes.filter(Boolean).length;
    return {
      status:
        updatedSessions === sessions.length && updatedSessions > 0
          ? "applied"
          : "pending",
      activeSessions: sessions.length,
      updatedSessions,
    };
  }
}
