# 3D Subscriber

Arabic-first Flutter self-service application backed by a dedicated Fastify subscriber API. Mock mode remains the safe default; real mode reads existing subscriber, RADIUS accounting, usage and recharge data without modifying legacy tables.

## Project layout

```text
backend/                 Fastify + TypeScript subscriber API
  migrations/            manual app-owned schema and grant templates
  src/auth/               RADIUS login and refresh-token rotation
  src/database/           bounded MariaDB repository queries
  src/{subscriber,usage,sessions,devices,recharges,notifications}/
  test/                   service, API security and migration tests
lib/                     existing Flutter application
docs/backend_api.md      endpoint and authentication contract
docs/radius_integration_analysis.md
```

## Run the backend locally

Requirements: Node.js 24.7 or newer and access to a non-production/test copy of the RADIUS MariaDB database.

```bash
cd backend
cp .env.example .env
npm install
npm run typecheck
npm test
npm run dev
```

The service defaults to `127.0.0.1:3081`. Required environment variables are:

- `SUBSCRIBER_DB_HOST`, `SUBSCRIBER_DB_PORT`, `SUBSCRIBER_DB_NAME`
- `SUBSCRIBER_DB_USER`, `SUBSCRIBER_DB_PASSWORD`
- `SUBSCRIBER_JWT_SECRET` with at least 32 random bytes

Optional values include API host/port/log level/trusted proxy, access-token TTL, refresh-token lifetime, database pool limit, and local timezone. See [backend/.env.example](backend/.env.example). Never commit `.env` or reuse the existing all-privilege RADIUS database account.

For production, keep the Node service bound to loopback and put it behind an approved HTTPS reverse proxy/hostname. Do not point Flutter at the service over plain HTTP.

## Migrations

Application startup does not run migrations. After backup and review, a database administrator can manually apply:

```bash
mariadb --host=127.0.0.1 --port=3307 --user=root --password radius \
  < backend/migrations/001_subscriber_app_tables_forward.sql
```

The command is an example only; use the approved administrative path and do not place a real password in shell history. The migration creates exactly three app-owned tables. Review `002_subscriber_api_grants.template.sql`, confirm the Docker bridge source host, replace its placeholder password, then grant the dedicated API user only the listed permissions. No migration was executed against production as part of this implementation.

## Run Flutter

Mock/demo mode:

```bash
flutter pub get
flutter run
```

Demo credentials are `demo001` / `123456`.

Real API mode:

```bash
flutter run
```

The default build connects to the deployed subscriber API over HTTPS and works
from mobile data or any Wi-Fi network. For local development, the endpoint can
still be overridden with `--dart-define=API_BASE_URL=http://<local-ip>:<port>`.

Flutter switches repository bindings only; the existing Riverpod providers, domain models and screens remain in place. Access and refresh tokens are stored with platform secure storage. A `401` triggers one refresh/rotation attempt; refresh failure clears the session and routes to login.

`LOCAL_SPEEDTEST_BASE_URL` remains available for the future local speed-test phase. Speed-test, FCM, 3D Share, IPTV, device blocking and background notification workers are intentionally not implemented in Phase 2.1.

## Checks

```bash
cd backend
npm run typecheck
npm test
npm run build

cd ..
flutter analyze
flutter test
```

The API contract and byte-direction decision are documented in [docs/backend_api.md](docs/backend_api.md).
