export interface AppConfig {
  readonly host: string;
  readonly port: number;
  readonly logLevel: string;
  readonly trustProxy: boolean;
  readonly localTimezone: string;
  readonly database: {
    readonly host: string;
    readonly port: number;
    readonly name: string;
    readonly user: string;
    readonly password: string;
    readonly connectionLimit: number;
  };
  readonly auth: {
    readonly jwtSecret: string;
    readonly accessTokenTtl: string;
    readonly refreshTokenDays: number;
  };
}

function required(environment: NodeJS.ProcessEnv, name: string): string {
  const value = environment[name]?.trim();
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function integer(
  environment: NodeJS.ProcessEnv,
  name: string,
  fallback: number,
  minimum: number,
  maximum: number,
): number {
  const raw = environment[name]?.trim();
  if (!raw) return fallback;
  const value = Number(raw);
  if (!Number.isInteger(value) || value < minimum || value > maximum) {
    throw new Error(`Invalid integer environment variable: ${name}`);
  }
  return value;
}

export function loadConfig(environment: NodeJS.ProcessEnv = process.env): AppConfig {
  const jwtSecret = required(environment, 'SUBSCRIBER_JWT_SECRET');
  if (
    Buffer.byteLength(jwtSecret, 'utf8') < 32 ||
    jwtSecret.toLowerCase().includes('replace-with')
  ) {
    throw new Error('SUBSCRIBER_JWT_SECRET must be a non-placeholder secret of at least 32 bytes');
  }
  const databasePassword = required(environment, 'SUBSCRIBER_DB_PASSWORD');
  if (databasePassword.toLowerCase().includes('replace-with')) {
    throw new Error('SUBSCRIBER_DB_PASSWORD must not contain the example placeholder');
  }
  const accessTokenTtl = environment.SUBSCRIBER_ACCESS_TOKEN_TTL?.trim() || '15m';
  if (!/^\d+[smhd]$/.test(accessTokenTtl)) {
    throw new Error('SUBSCRIBER_ACCESS_TOKEN_TTL must look like 15m, 1h, or 1d');
  }
  const localTimezone = environment.SUBSCRIBER_LOCAL_TIMEZONE?.trim() || 'Asia/Aden';
  try {
    new Intl.DateTimeFormat('en', { timeZone: localTimezone }).format();
  } catch {
    throw new Error('SUBSCRIBER_LOCAL_TIMEZONE must be a valid IANA time zone');
  }
  return {
    host: environment.SUBSCRIBER_API_HOST?.trim() || '127.0.0.1',
    port: integer(environment, 'SUBSCRIBER_API_PORT', 3081, 1, 65535),
    logLevel: environment.SUBSCRIBER_API_LOG_LEVEL?.trim() || 'info',
    trustProxy: environment.SUBSCRIBER_API_TRUST_PROXY === 'true',
    localTimezone,
    database: {
      host: required(environment, 'SUBSCRIBER_DB_HOST'),
      port: integer(environment, 'SUBSCRIBER_DB_PORT', 3307, 1, 65535),
      name: required(environment, 'SUBSCRIBER_DB_NAME'),
      user: required(environment, 'SUBSCRIBER_DB_USER'),
      password: databasePassword,
      connectionLimit: integer(
        environment,
        'SUBSCRIBER_DB_CONNECTION_LIMIT',
        10,
        1,
        50,
      ),
    },
    auth: {
      jwtSecret,
      accessTokenTtl,
      refreshTokenDays: integer(
        environment,
        'SUBSCRIBER_REFRESH_TOKEN_DAYS',
        30,
        1,
        90,
      ),
    },
  };
}
