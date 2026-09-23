import type { Pool, RowDataPacket } from "mysql2/promise";

import {
  sendDisconnect,
  type DisconnectRequest,
  type DisconnectSender,
} from "../speed/live-speed-service.js";

export interface LiveDeviceDisconnectResult {
  status: "disconnected" | "offline" | "pending";
  activeSessions: number;
  disconnectedSessions: number;
}

export interface LiveDeviceDisconnector {
  disconnect(
    username: string,
    callingStationId: string,
  ): Promise<LiveDeviceDisconnectResult>;
}

interface ActiveDeviceSession extends RowDataPacket {
  nasipaddress: string;
  acctsessionid: string;
  framedipaddress: string;
  callingstationid: string;
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

/** Sends an authenticated RADIUS Disconnect-Request for one physical device. */
export class MySqlLiveDeviceDisconnector implements LiveDeviceDisconnector {
  constructor(
    private readonly pool: Pool,
    private readonly send: DisconnectSender = sendDisconnect,
  ) {}

  async disconnect(
    username: string,
    callingStationId: string,
  ): Promise<LiveDeviceDisconnectResult> {
    const [sessions] = await this.pool.execute<ActiveDeviceSession[]>(
      `SELECT DISTINCT nasipaddress,acctsessionid,framedipaddress,callingstationid
         FROM radacct
        WHERE username=? AND callingstationid=? AND acctstoptime IS NULL
          AND COALESCE(acctupdatetime,acctstarttime)>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE
          AND NULLIF(acctsessionid,'') IS NOT NULL
        LIMIT 5`,
      [username, callingStationId],
    );
    if (sessions.length === 0) {
      return { status: "offline", activeSessions: 0, disconnectedSessions: 0 };
    }
    // A device normally has exactly one session. Do not issue ambiguous bulk
    // disconnects if accounting reports more than four current sessions.
    if (sessions.length > 4) {
      return {
        status: "pending",
        activeSessions: sessions.length,
        disconnectedSessions: 0,
      };
    }
    const [routers] = await this.pool.execute<NasRecord[]>(
      `SELECT nasname,shortname,secret FROM nas
        WHERE COALESCE(enabled,1)=1 AND secret<>''`,
    );
    const results = await Promise.all(
      sessions.map(async (session) => {
        const router = routerFor(session, routers);
        if (!router) return false;
        try {
          const request: DisconnectRequest = {
            host: String(router.nasname),
            port: coaPort(router),
            secret: String(router.secret),
            username,
            sessionId: String(session.acctsessionid),
            address: String(session.framedipaddress),
            callingStationId: String(session.callingstationid),
          };
          return await this.send(request);
        } catch {
          return false;
        }
      }),
    );
    const disconnectedSessions = results.filter(Boolean).length;
    return {
      status:
        disconnectedSessions === sessions.length ? "disconnected" : "pending",
      activeSessions: sessions.length,
      disconnectedSessions,
    };
  }
}
