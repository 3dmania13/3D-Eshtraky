import { loadConfig } from "../config/index.js";
import { createPool } from "../database/pool.js";
import { MySqlLiveDeviceSpeedApplier } from "./live-device-speed-service.js";
import { DeviceSpeedWorker } from "./device-speed-worker.js";

const pool = createPool(loadConfig().database);
const worker = new DeviceSpeedWorker(pool, new MySqlLiveDeviceSpeedApplier(pool));
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
    console.error("Device speed worker tick failed; retrying.");
  }
  if (!stopping) await new Promise((resolve) => setTimeout(resolve, 1000));
}
await pool.end();
