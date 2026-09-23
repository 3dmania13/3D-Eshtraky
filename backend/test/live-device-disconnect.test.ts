import assert from "node:assert/strict";
import { test } from "node:test";
import type { Pool } from "mysql2/promise";

import { MySqlLiveDeviceDisconnector } from "../src/devices/live-device-disconnect-service.js";
import type { DisconnectRequest } from "../src/speed/live-speed-service.js";

function poolWith(...results: object[][]): Pool {
  return { execute: async () => [results.shift() ?? []] } as unknown as Pool;
}

const session = {
  nasipaddress: "33.3.3.44",
  acctsessionid: "session-123",
  framedipaddress: "10.10.10.4",
  callingstationid: "AA:BB:CC:DD:EE:FF",
};
const router = {
  nasname: "192.168.230.217",
  shortname: "network4",
  secret: "test-only",
};

test("device disconnect targets only the selected live MAC and exact RADIUS session", async () => {
  const sent: DisconnectRequest[] = [];
  const service = new MySqlLiveDeviceDisconnector(
    poolWith([session], [router]),
    async (request) => {
      sent.push(request);
      return true;
    },
  );

  assert.deepEqual(
    await service.disconnect("alice", session.callingstationid),
    { status: "disconnected", activeSessions: 1, disconnectedSessions: 1 },
  );
  assert.deepEqual(sent, [
    {
      host: router.nasname,
      port: 3799,
      secret: router.secret,
      username: "alice",
      sessionId: session.acctsessionid,
      address: session.framedipaddress,
      callingStationId: session.callingstationid,
    },
  ]);
});

test("offline, unknown, and partial disconnects never claim success", async () => {
  const neverSend = async () => assert.fail("no router command should be sent");
  assert.deepEqual(
    await new MySqlLiveDeviceDisconnector(poolWith([]), neverSend).disconnect(
      "alice",
      session.callingstationid,
    ),
    { status: "offline", activeSessions: 0, disconnectedSessions: 0 },
  );
  assert.deepEqual(
    await new MySqlLiveDeviceDisconnector(
      poolWith([session], []),
      neverSend,
    ).disconnect("alice", session.callingstationid),
    { status: "pending", activeSessions: 1, disconnectedSessions: 0 },
  );
  const partial = new MySqlLiveDeviceDisconnector(
    poolWith([session, { ...session, acctsessionid: "session-456" }], [router]),
    async (request) => request.sessionId === session.acctsessionid,
  );
  assert.deepEqual(
    await partial.disconnect("alice", session.callingstationid),
    { status: "pending", activeSessions: 2, disconnectedSessions: 1 },
  );
});
