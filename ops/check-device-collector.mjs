// Run from a staged directory on the RADIUS host with the worker environment.
const release = '/opt/3d-subscriber-api/releases/20260922-push/dist';
const { loadConfig } = await import(`${release}/config/index.js`);
const { createPool } = await import(`${release}/database/pool.js`);
const { PushWorker } = await import(`${release}/notifications/push-worker.js`);
const { MySqlSubscriberRepository } = await import(`${release}/database/mysql-subscriber-repository.js`);
const pool = createPool(loadConfig().database);
try {
  await new PushWorker(pool, {}).collectNewDeviceConnections({ query: async (sql) => {
    const [rows] = await pool.query(sql);
    console.log(`device_collection_query_ok candidates=${rows.length}`);
    return [[]]; // Do not enqueue or deliver anything during verification.
  }});
  const [accounts] = await pool.query('SELECT DISTINCT username FROM subscriber_push_devices LIMIT 10');
  const repository = new MySqlSubscriberRepository(pool);
  let devices = 0;
  for (const account of accounts) {
    const rows = await repository.getDevices({ username: String(account.username) }, 100);
    devices += rows.length;
    if (rows.some(row => row.lastSeenAt.getTime() < Date.now() - 3 * 86400000 - 5000)) {
      throw new Error('A stale device was returned');
    }
  }
  console.log(`device_list_query_ok accounts=${accounts.length} visible_devices=${devices}`);
} catch (error) {
  console.error(error.code, error.message);
  process.exitCode = 1;
} finally { await pool.end(); }
