import { loadConfig } from "../config/index.js";
import { createPool } from "../database/pool.js";
import { FcmSender } from "./fcm-sender.js";
import { PushWorker } from "./push-worker.js";

const path = process.env.GOOGLE_APPLICATION_CREDENTIALS;
if (!path) throw new Error("GOOGLE_APPLICATION_CREDENTIALS is required");
const pool = createPool(loadConfig().database);
const worker = new PushWorker(pool, new FcmSender(path));
await worker.initialize();
let stopping = false;
process.on("SIGTERM", () => {
  stopping = true;
});
process.on("SIGINT", () => {
  stopping = true;
});
while (!stopping) {
  try {
    await worker.tick();
  } catch {
    console.error("Push worker tick failed; retrying.");
  }
  if (!stopping) await new Promise((resolve) => setTimeout(resolve, 250));
}
await pool.end();
