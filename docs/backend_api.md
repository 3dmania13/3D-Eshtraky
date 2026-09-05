# 3D Subscriber Backend API

Phase 2.1 provides a dedicated Fastify service for subscribers. It is separate from the dealer/admin API on port 3080. All protected identities come from the signed JWT; protected endpoints do not accept a subscriber username.

## Base URL and errors

Production must expose the service through HTTPS, for example:

```text
https://subscriber.example.com/api/v1
```

Errors have one stable shape:

```json
{
  "error": {
    "code": "INVALID_CREDENTIALS",
    "message": "اسم المستخدم أو كلمة المرور غير صحيحة."
  }
}
```

Validation uses `400`, missing/invalid access tokens use `401`, disabled or expired accounts use `403`, missing owned records use `404`, and rate limiting uses `429`.

## Authentication flow

1. Flutter sends the RADIUS username and password to `POST /api/v1/auth/login` over HTTPS.
2. The API looks up the confirmed `radcheck` `Cleartext-Password` record using the existing `(username, attribute)` index and compares it in constant time. It does not copy, return or log the password.
3. The API returns a short-lived JWT access token and a random refresh token. Only the SHA-256 hash of the refresh token is stored.
4. Flutter stores both tokens in platform secure storage and sends `Authorization: Bearer <accessToken>` on protected calls.
5. On a `401`, Flutter calls the refresh endpoint once. Refresh tokens are rotated atomically; the old token is revoked. If refresh fails, local tokens are deleted and the user is returned to login.

### Login

`POST /api/v1/auth/login`

```json
{
  "username": "subscriber001",
  "password": "subscriber-password"
}
```

```json
{
  "accessToken": "eyJ...",
  "refreshToken": "random-opaque-token",
  "subscriber": {
    "username": "subscriber001",
    "status": "active"
  }
}
```

Disabled and expired subscribers are rejected even when their password is correct.

### Refresh and logout

- `POST /api/v1/auth/refresh` with `{"refreshToken":"..."}` returns a rotated token pair and subscriber summary.
- `POST /api/v1/auth/logout` with `{"refreshToken":"..."}` revokes that refresh session and returns `204`.

## Protected endpoints

Every endpoint below requires the bearer access token.

### Profile

`GET /api/v1/subscriber/profile`

```json
{
  "username": "subscriber001",
  "status": "active",
  "package_name": "10 GB",
  "expires_at": "2026-09-30T20:59:59.000Z"
}
```

### Dashboard

`GET /api/v1/subscriber/dashboard`

```json
{
  "id": "123",
  "username": "subscriber001",
  "status": "active",
  "connection_status": "connected",
  "active_device_count": 2,
  "remaining_bytes": 6442450944,
  "remaining_gib": 6,
  "usage_percentage": 40,
  "days_remaining": 31,
  "subscription": {
    "id": "10",
    "package_name": "10 GB",
    "total_bytes": 10737418240,
    "used_bytes": 4294967296,
    "started_at": "2026-08-01T00:00:00.000Z",
    "expires_at": "2026-09-30T20:59:59.000Z"
  }
}
```

Quota totals and cached usage come from the subscriber profile maintained by the existing settlement system. Active device count uses only indexed, active `radacct` rows.

### Usage

- `GET /api/v1/subscriber/usage/summary`
- `GET /api/v1/subscriber/usage/daily?from=2026-08-01&to=2026-08-30`

Summary contains `today`, `yesterday`, `week`, and `month`; each has `download_bytes`, `upload_bytes`, and `total_bytes`. The current week starts on Monday. Daily ranges default to the latest 30 local calendar days and are limited to 366 days. Both endpoints read the indexed `nawa_usage_daily` aggregate rather than scanning `radacct`.

### Sessions

`GET /api/v1/subscriber/sessions`

Returns up to 100 recent indexed rows with:

```json
{
  "session_id": "unique-session-id",
  "start_time": "2026-08-30T08:00:00.000Z",
  "stop_time": null,
  "duration_seconds": 14400,
  "upload_bytes": 120000,
  "download_bytes": 4500000,
  "framed_ip": "10.0.0.10",
  "calling_station_id": "AA:BB:CC:DD:EE:FF",
  "network_identifier": "nas-1",
  "is_active": true
}
```

Byte direction was confirmed against the existing PHP accounting implementation: `input_octets64`/`acctinputoctets` is subscriber upload; `output_octets64`/`acctoutputoctets` is subscriber download. Timestamps are returned as UTC ISO-8601 values.

### Devices

- `GET /api/v1/subscriber/devices`
- `PATCH /api/v1/subscriber/devices/:id` with `{"friendly_name":"هاتف المنزل"}`

The list groups current and historical indexed `radacct` sessions by calling-station ID. It returns MAC/opaque calling-station ID, current IP, online state, current-session usage, connection start, first seen and last seen. Device IDs are deterministic SHA-256 identifiers scoped by subscriber. PATCH accepts only `friendly_name`; MAC and IP cannot be changed.

### Recharges

`GET /api/v1/subscriber/recharges`

The API normalizes immutable dealer transactions from `three_d_net_recharge_transactions` and operator settlement actions from `nawa_audit_log`. It does not create recharge logic. Records contain date/time, package label, amount, currency, bytes added, validity, generated expiry, source-prefixed reference, and status. Values not persisted immutably by a legacy source are returned as `null`, never fabricated.

### Notifications

- `GET /api/v1/subscriber/notifications`
- `PATCH /api/v1/subscriber/notifications/:id/read`

Reading notifications invokes the prepared synchronous checks `checkExpiryWarnings()`, `checkQuotaWarnings()`, and `detectNewDevice()`, then returns stored history. Duplicate derived warnings are suppressed for 24 hours. No worker, FCM integration, or push delivery is enabled in this phase.

## Database ownership and performance

The only application-owned tables are:

- `subscriber_refresh_tokens`
- `subscriber_devices`
- `subscriber_notifications`

The migration contains no `ALTER` for `radcheck`, `radacct`, recharge tables, or any other legacy table. Usage reads the `(username, usage_date)` primary key on `nawa_usage_daily`; authentication uses the indexed RADIUS credential lookup; session/device/recharge queries are bounded and username-scoped.

See `backend/migrations/001_subscriber_app_tables_forward.sql` and the least-privilege grant template. Migrations are never run by application startup.
