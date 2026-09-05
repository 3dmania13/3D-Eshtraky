import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const migrationUrl = new URL('../migrations/001_subscriber_app_tables_forward.sql', import.meta.url);

test('migration creates only subscriber application tables', async () => {
  const sql = await readFile(migrationUrl, 'utf8');
  const createdTables = [...sql.matchAll(/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/gi)].map(
    (match) => match[1],
  );
  assert.deepEqual(createdTables, [
    'subscriber_refresh_tokens',
    'subscriber_devices',
    'subscriber_notifications',
  ]);
  assert.doesNotMatch(sql, /ALTER\s+TABLE\s+(?:radcheck|radacct|three_d_net_recharge_transactions)/i);
});
