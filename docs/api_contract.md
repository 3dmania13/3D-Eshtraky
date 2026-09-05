# 3D Subscriber API contract (Flutter payload reference)

The implemented Phase 2.1 endpoint guide and authentication flow are in `docs/backend_api.md`.

The Flutter client never accepts a subscriber username on authenticated data routes. The backend must derive the subscriber identity from the bearer token, authorize every request, and return JSON over HTTPS. Byte values are integers and dates are ISO-8601 strings.

## Configuration

- `API_BASE_URL`: authenticated REST API origin.
- `LOCAL_SPEEDTEST_BASE_URL`: ISP-local speed-test origin.
- `USE_MOCK_DATA`: defaults to `true` in Phase 1.

## Authentication

`POST /api/v1/auth/login`

Request:

```json
{"username":"demo001","password":"..."}
```

Response:

```json
{
  "accessToken": "signed-access-token",
  "refreshToken": "rotating-refresh-token",
  "subscriber": {"username":"demo001","status":"active"}
}
```

Passwords must never be logged or persisted by the client. The returned token is stored using platform secure storage.

## Subscriber resources

- `GET /api/v1/subscriber/usage/summary`
- `GET /api/v1/subscriber/usage/daily?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `GET /api/v1/subscriber/sessions`
- `GET /api/v1/subscriber/devices`
- `PATCH /api/v1/subscriber/devices/{deviceId}` with `{"friendly_name":"..."}`
- `GET /api/v1/subscriber/recharges`
- `GET /api/v1/subscriber/notifications`
- `PATCH /api/v1/subscriber/notifications/{id}/read`
- `POST /api/v1/subscriber/speedtests`
- `GET /api/v1/subscriber/speedtests`

Session/accounting payloads use `session_id`, `start_time`, nullable `stop_time`, `duration_seconds`, `upload_bytes`, `download_bytes`, `framed_ip`, `calling_station_id`, `network_identifier`, and `is_active`. The backend may source these fields from FreeRADIUS accounting data such as `radacct`, but that database detail is never exposed to Flutter.

## Local speed test

- `GET /speedtest/ping`
- `GET /speedtest/download`
- `POST /speedtest/upload`

These endpoints must be hosted inside the ISP network. They must not proxy or use a public speed-test provider. Completed results can then be persisted through the authenticated subscriber speed-test endpoints.

## Errors

Non-2xx responses should use a stable code and a safe user-facing message:

```json
{"error":{"code":"invalid_credentials","message":"Invalid credentials"}}
```

Recommended status codes: `400` invalid input, `401` invalid/expired token, `403` unauthorized resource, `404` missing resource, `422` validation failure, `429` rate limited, and `5xx` server failure.
