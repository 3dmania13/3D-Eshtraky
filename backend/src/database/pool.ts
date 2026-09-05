import mysql, { type Pool } from 'mysql2/promise';

import type { AppConfig } from '../config/index.js';

export function createPool(config: AppConfig['database']): Pool {
  return mysql.createPool({
    host: config.host,
    port: config.port,
    database: config.name,
    user: config.user,
    password: config.password,
    charset: 'utf8mb4',
    timezone: 'Z',
    dateStrings: false,
    supportBigNumbers: true,
    bigNumberStrings: true,
    connectionLimit: config.connectionLimit,
    enableKeepAlive: true,
    waitForConnections: true,
    queueLimit: 0,
  });
}
