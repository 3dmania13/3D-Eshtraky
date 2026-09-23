import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import mysql, { type RowDataPacket } from "mysql2/promise";
import { createApp } from "../src/app.js";
import { loadConfig } from "../src/config/index.js";
import { PushStore } from "../src/notifications/push-store.js";
import { PushWorker } from "../src/notifications/push-worker.js";
import { PushSendError } from "../src/notifications/fcm-sender.js";

test(
  "push registration, account isolation, recharge collection, retries and protected routes",
  { skip: !process.env.PUSH_TEST_DATABASE },
  async () => {
    const database = process.env.PUSH_TEST_DATABASE!;
    assert.match(database, /^subscriber_push_test_[a-z0-9_]+$/);
    const config = loadConfig();
    const pool = mysql.createPool({
      host: config.database.host,
      port: config.database.port,
      user: config.database.user,
      password: config.database.password,
      database,
      multipleStatements: true,
      timezone: "Z",
    });
    const scalar = async (sql: string) =>
      Number((await pool.query<RowDataPacket[]>(sql))[0][0]?.n);
    const first = "a".repeat(32),
      second = "b".repeat(32);
    const sent: string[] = [];
    let failure: Error | undefined;
    const worker = new PushWorker(pool, {
      send: async (token) => {
        if (failure) throw failure;
        sent.push(token);
      },
    });
    try {
      for (const name of [
        "001_subscriber_app_tables_forward.sql",
        "003_subscriber_push_delivery.sql",
        "005_subscriber_push_preferences.sql",
        "007_subscriber_quota_alerts.sql",
      ]) {
        await pool.query(
          await readFile(
            new URL(`../migrations/${name}`, import.meta.url),
            "utf8",
          ),
        );
      }
      await pool.query(`CREATE TABLE nawa_audit_log(id BIGINT PRIMARY KEY AUTO_INCREMENT,subject_id VARCHAR(128),subject_type VARCHAR(50),action_name VARCHAR(100));
        CREATE TABLE three_d_net_recharge_transactions(id BIGINT PRIMARY KEY AUTO_INCREMENT,customer_username VARCHAR(64),status VARCHAR(20));
        CREATE TABLE userinfo(username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci PRIMARY KEY,status VARCHAR(20),is_disabled TINYINT,total_quota BIGINT,byte_limit BIGINT,total_limit BIGINT,used_quota BIGINT);
        INSERT INTO nawa_audit_log VALUES(1,'alice','user','user.package_settle');
        INSERT INTO userinfo VALUES('alice','active',0,10737418240,0,0,1073741824);`);
      await worker.initialize();
      const store = new PushStore(pool);
      await store.register("alice", first, "token-alice-1234567890");
      await store.register("alice", first, "token-alice-rotated-1234567890");
      assert.equal(
        await scalar("SELECT COUNT(*) n FROM subscriber_notifications"),
        1,
      );
      await worker.tick();
      assert.deepEqual(sent, ["token-alice-rotated-1234567890"]);
      await pool.query(
        "UPDATE userinfo SET used_quota=10200547328 WHERE username='alice'",
      );
      await worker.tick();
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_notifications WHERE type='lowBalance'",
        ),
        1,
      );
      await worker.tick();
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_notifications WHERE type='lowBalance'",
        ),
        1,
      );
      await store.register("alice", second, "token-second-1234567890");
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_notifications WHERE type='newDevice'",
        ),
        1,
      );
      await store.revoke("other-account", first);
      assert.equal(
        await scalar(
          `SELECT active n FROM subscriber_push_devices WHERE installation_id='${first}'`,
        ),
        1,
      );
      // Pending Alice alerts must not be delivered after a device switches to Bob.
      await store.register("bob", second, "token-second-1234567890");
      const before = sent.length;
      await worker.tick();
      assert.equal(sent.length - before, 2); // Alice's first phone and Bob's welcome.
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_push_outbox WHERE status='skipped'",
        ),
        1,
      );
      await pool.query(`INSERT INTO nawa_audit_log VALUES(2,'alice','user','user.package_settle');
        INSERT INTO three_d_net_recharge_transactions VALUES(1,'alice','failed'),(2,'alice','processing'),(3,'alice','completed');`);
      await worker.tick();
      await worker.tick();
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_notifications WHERE type='rechargeSuccessful'",
        ),
        2,
      );
      await pool.query(
        "UPDATE three_d_net_recharge_transactions SET status='completed' WHERE id=2",
      );
      failure = new Error("transient network failure");
      await worker.tick();
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_push_outbox WHERE status='pending' AND attempts=1",
        ),
        1,
      );
      failure = new PushSendError("UNREGISTERED", true, true);
      await pool.query(
        "UPDATE subscriber_push_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE status='pending'",
      );
      await worker.tick();
      assert.equal(
        await scalar(
          `SELECT active n FROM subscriber_push_devices WHERE installation_id='${first}'`,
        ),
        0,
      );
      await store.revoke("bob", second);
      assert.equal(
        await scalar(
          `SELECT active n FROM subscriber_push_devices WHERE installation_id='${second}'`,
        ),
        0,
      );

      const app = await createApp(config, { pool });
      await app.ready();
      const auth = `Bearer ${app.jwt.sign({ sub: "alice", username: "alice", role: "subscriber", status: "active" })}`;
      const route = "/api/v1/subscriber/notifications/push-token";
      assert.equal(
        (
          await app.inject({
            method: "POST",
            url: route,
            payload: { token: "token-http-1234567890", installation_id: first },
          })
        ).statusCode,
        401,
      );
      assert.equal(
        (
          await app.inject({
            method: "POST",
            url: route,
            headers: { authorization: auth },
            payload: {
              token: "token-http-1234567890",
              installation_id: first,
              username: "victim",
            },
          })
        ).statusCode,
        200,
      );
      assert.equal(
        await scalar(
          "SELECT COUNT(*) n FROM subscriber_push_devices WHERE username='victim'",
        ),
        0,
      );
      assert.equal(
        (
          await app.inject({
            method: "POST",
            url: route,
            headers: { authorization: auth },
            payload: { token: "token-http-1234567890", installation_id: first },
          })
        ).statusCode,
        200,
      );
      await app.close();
    } finally {
      await pool.end();
    }
  },
);
