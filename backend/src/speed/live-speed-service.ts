import { spawn } from "node:child_process";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { isIPv4 } from "node:net";
import { tmpdir } from "node:os";
import { join } from "node:path";
import type { Pool, RowDataPacket } from "mysql2/promise";

export interface LiveSpeedResult {
  status: "applied" | "offline" | "pending";
  active_sessions: number;
  updated_sessions: number;
}

export interface LiveSpeedApplier {
  apply(username: string, rate: string): Promise<LiveSpeedResult>;
}

export interface CoaRequest {
  host: string;
  port: number;
  secret: string;
  username: string;
  sessionId: string;
  address: string;
  rate: string;
}

export type CoaSender = (request: CoaRequest) => Promise<boolean>;

export interface DisconnectRequest {
  host: string;
  port: number;
  secret: string;
  username: string;
  sessionId: string;
  address: string;
  callingStationId: string;
}

export type DisconnectSender = (request: DisconnectRequest) => Promise<boolean>;

function quote(value: string): string {
  if (/[\x00-\x1f\x7f]/.test(value))
    throw new Error("Invalid RADIUS attribute");
  return `"${value.replaceAll("\\", "\\\\").replaceAll('"', '\\"')}"`;
}

// radclient validates the RADIUS response authenticator. The shared secret is
// stored in a private temporary directory, never in process arguments or logs.
export const sendCoa: CoaSender = async (request) => {
  if (
    !isIPv4(request.host) ||
    !request.sessionId ||
    /[\r\n\0]/.test(request.secret)
  )
    return false;
  const attributes =
    [
      `User-Name = ${quote(request.username)}`,
      `Acct-Session-Id = ${quote(request.sessionId)}`,
      ...(isIPv4(request.address)
        ? [`Framed-IP-Address = ${request.address}`]
        : []),
      `Mikrotik-Rate-Limit = ${quote(request.rate)}`,
    ].join("\n") + "\n";
  const directory = await mkdtemp(join(tmpdir(), "subscriber-coa-"));
  try {
    const secretFile = join(directory, "secret");
    await writeFile(secretFile, request.secret + "\n", { mode: 0o600 });
    return await new Promise<boolean>((resolve) => {
      const child = spawn(
        "/usr/bin/radclient",
        [
          "-r",
          "1",
          "-t",
          "2",
          "-S",
          secretFile,
          `${request.host}:${request.port}`,
          "coa",
        ],
        { stdio: ["pipe", "pipe", "pipe"] },
      );
      let output = "";
      let timedOut = false;
      const timer = setTimeout(() => {
        timedOut = true;
        child.kill("SIGKILL");
      }, 3500);
      child.on("error", () => {
        clearTimeout(timer);
        resolve(false);
      });
      child.on("close", (code) => {
        clearTimeout(timer);
        resolve(!timedOut && code === 0 && /Received CoA-ACK\b/.test(output));
      });
      for (const stream of [child.stdout, child.stderr]) {
        stream?.on("data", (chunk: Buffer) => {
          if (output.length < 16384) output += chunk.toString();
        });
      }
      child.stdin?.on("error", () => {});
      child.stdin?.end(attributes);
    });
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
};

// A Disconnect-Request is limited to the subscriber's exact RADIUS session.
// We only treat a verified Disconnect-ACK as a successful disconnection.
export const sendDisconnect: DisconnectSender = async (request) => {
  if (
    !isIPv4(request.host) ||
    !request.sessionId ||
    /[\r\n\0]/.test(request.secret)
  )
    return false;
  const attributes =
    [
      `User-Name = ${quote(request.username)}`,
      `Acct-Session-Id = ${quote(request.sessionId)}`,
      ...(isIPv4(request.address)
        ? [`Framed-IP-Address = ${request.address}`]
        : []),
      `Calling-Station-Id = ${quote(request.callingStationId)}`,
    ].join("\n") + "\n";
  const directory = await mkdtemp(join(tmpdir(), "subscriber-disconnect-"));
  try {
    const secretFile = join(directory, "secret");
    await writeFile(secretFile, request.secret + "\n", { mode: 0o600 });
    return await new Promise<boolean>((resolve) => {
      const child = spawn(
        "/usr/bin/radclient",
        [
          "-r",
          "1",
          "-t",
          "2",
          "-S",
          secretFile,
          `${request.host}:${request.port}`,
          "disconnect",
        ],
        { stdio: ["pipe", "pipe", "pipe"] },
      );
      let output = "";
      let timedOut = false;
      const timer = setTimeout(() => {
        timedOut = true;
        child.kill("SIGKILL");
      }, 3500);
      child.on("error", () => {
        clearTimeout(timer);
        resolve(false);
      });
      child.on("close", (code) => {
        clearTimeout(timer);
        resolve(
          !timedOut && code === 0 && /Received Disconnect-ACK\b/.test(output),
        );
      });
      for (const stream of [child.stdout, child.stderr]) {
        stream?.on("data", (chunk: Buffer) => {
          if (output.length < 16384) output += chunk.toString();
        });
      }
      child.stdin?.on("error", () => {});
      child.stdin?.end(attributes);
    });
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
};

export class MySqlLiveSpeedApplier implements LiveSpeedApplier {
  constructor(
    private readonly pool: Pool,
    private readonly send: CoaSender = sendCoa,
  ) {}

  async apply(username: string, selection: string): Promise<LiveSpeedResult> {
    const [sessions] = await this.pool.execute<RowDataPacket[]>(
      `SELECT DISTINCT nasipaddress,acctsessionid,framedipaddress
         FROM radacct WHERE username=? AND acctstoptime IS NULL
          AND acctupdatetime >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         LIMIT 17`,
      [username],
    );
    if (sessions.length === 0)
      return { status: "offline", active_sessions: 0, updated_sessions: 0 };
    const result: LiveSpeedResult = {
      status: "pending",
      active_sessions: sessions.length,
      updated_sessions: 0,
    };
    if (sessions.length > 16) return result;
    let rate = selection;
    if (selection === "open") {
      // Restore the subscription's group rate; never turn a capped package into
      // an unlimited live session. Ambiguous group policies require reconnect.
      const [rates] = await this.pool.execute<RowDataPacket[]>(
        `SELECT DISTINCT rgr.value FROM radusergroup rug
         JOIN radgroupreply rgr ON rgr.groupname=rug.groupname
         WHERE rug.username=? AND rgr.attribute='Mikrotik-Rate-Limit'`,
        [username],
      );
      if (rates.length > 1) return result;
      if (rates.length === 1) {
        rate = String(rates[0]?.value ?? "").trim();
        if (!rate) return result;
      } else {
        const [packages] = await this.pool.execute<RowDataPacket[]>(
          `SELECT p.rate_limit,p.upload_speed,p.download_speed
           FROM userinfo u JOIN packages p ON p.id=u.package_id
           WHERE u.username=? LIMIT 1`,
          [username],
        );
        const plan = packages[0];
        if (!plan) return result;
        // An explicitly uncapped package uses 0/0 to remove the live override.
        rate =
          String(plan.rate_limit ?? "").trim() ||
          `${String(plan.upload_speed ?? "").trim() || "0"}/${String(plan.download_speed ?? "").trim() || "0"}`;
      }
    }
    const [routers] = await this.pool.execute<RowDataPacket[]>(
      `SELECT nasname,shortname,secret FROM nas WHERE COALESCE(enabled,1)=1 AND secret<>''`,
    );
    for (let offset = 0; offset < sessions.length; offset += 4) {
      const outcomes = await Promise.all(
        sessions.slice(offset, offset + 4).map(async (session) => {
          const alias = /^33\.3\.3\.(11|22|33|44|55|66|77)$/.exec(
            String(session.nasipaddress),
          );
          const router =
            routers.find((row) => row.nasname === session.nasipaddress) ??
            (alias
              ? routers.find(
                  (row) =>
                    String(row.shortname).toLowerCase() ===
                    `network${Number(alias[1]) / 11}`,
                )
              : undefined);
          if (!router) return false;
          try {
            return await this.send({
              host: String(router.nasname),
              port: ["network4", "network6"].includes(
                String(router.shortname).toLowerCase(),
              )
                ? 3799
                : 1700,
              secret: String(router.secret),
              username,
              sessionId: String(session.acctsessionid),
              address: String(session.framedipaddress),
              rate,
            });
          } catch {
            return false;
          }
        }),
      );
      result.updated_sessions += outcomes.filter(Boolean).length;
    }
    if (result.updated_sessions === result.active_sessions)
      result.status = "applied";
    return result;
  }
}
