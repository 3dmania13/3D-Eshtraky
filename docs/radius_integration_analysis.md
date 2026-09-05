# RADIUS integration analysis

Inspection date: 2026-08-29 (Asia/Riyadh). The inspection was read-only. No production table, RADIUS setting, service, or server file was changed.

## Environment detected

- Host: `3dmania`, Ubuntu 26.04 LTS, Linux 7.0.0-14.
- Web portal: Apache 2.4.66 with PHP 8.5.4 at `/var/www/html/3dradius`.
- RADIUS: FreeRADIUS 3.2.8 using the SQL module.
- RADIUS database: database `radius`, MariaDB 11.4.8 inside Docker, exposed by Docker on host port 3307. Database and session time zone are UTC.
- The host also runs MariaDB 11.8.6 on localhost port 3306. This is not the database configured by daloRADIUS/FreeRADIUS.
- Existing API: `3D Net API`, Fastify 5 + TypeScript + Node 24, bound to `192.168.230.111:3080`. It implements dealer/admin wallets and recharges. Its `/api/v1/auth/login` is dealer-only and must not be reused for subscriber authentication.
- No Composer installation exists. The PHP portal is a customized daloRADIUS codebase using PEAR DB.

## Existing authentication

FreeRADIUS reads authentication check items from `radcheck` through the configured SQL authorize query. A recent 1,000-subscriber sample contained `Cleartext-Password :=` and `Auth-Type :=` for all sampled users, plus quota/session attributes. The relevant lookup uses the existing `idx_radcheck_user_attr (username, attribute)` index.

The legacy daloRADIUS subscriber portal instead compares `userinfo.portalloginpassword` directly. That portal field is not selected as the API authentication source because it is a separate portal credential and the current implementation performs a direct plaintext comparison.

The Subscriber API will validate against the existing `radcheck` `Cleartext-Password` value without copying, returning, logging, or persisting it. Subscriber identity will then be embedded in a short-lived signed token and derived only from that token on protected routes.

Pre-existing password-bearing columns include `radcheck.value` for `Cleartext-Password` and several `userinfo` password fields. Removing or hashing those values is outside this phase and could break current RADIUS authentication. The new API must never expose them.

## Relevant tables found

### Subscriber and package

- `userinfo` (~299k rows): unique `username`, `status`, `is_disabled`, `package_id`, `package_name`, `total_quota`, `used_quota`, `expiry_date`, `expires_at`, `activated_at`, `first_login_at`, speeds and legacy password fields.
- `packages` (17 rows): package `name`, byte quota, validity days, prices, speeds and recharge flags.
- `user_packages` (~5.9k rows): historical/active package assignment with consumed bytes/time.
- `radusergroup`: RADIUS group membership and disabled-user group.
- `radcheck`: RADIUS password, expiration and quota/session checks.
- `radreply`: per-user RADIUS reply attributes.

`userinfo` is the possible/canonical subscriber profile table. Current PHP package management updates `packages`; current recharge/settlement logic updates `userinfo`, RADIUS attributes, allowances and subscription cycles in a transaction.

### Accounting and usage

- `radacct` (~2.76m rows, ~983 MB data plus ~1.19 GB indexes): authoritative session accounting. It contains session IDs, start/update/stop times, 64-bit upload/download counters, IP, calling-station/MAC and NAS fields.
- `nawa_usage_daily` (~15.7k rows): daily pre-aggregation keyed by `(username, usage_date)` with upload, download and session count.
- `nawa_usage_session_sync` (~161k rows): incremental session reconciliation state.
- `nawa_subscription_cycles`: subscription-cycle boundaries.
- `nawa_live_accounts`: per-username live usage cache, not a per-device session source.

Existing PHP maps `radacct.input_octets64/acctinputoctets` to subscriber upload and `output_octets64/acctoutputoctets` to subscriber download. `nawa_usage_daily` uses the same mapping. Accounting timestamps are UTC and daily grouping is converted to the local UTC+3 day.

A systemd reconciliation timer refreshes usage state every 5 minutes. The service description says 30 seconds but the actual timer is `OnUnitInactiveSec=5min`.

### Devices

No canonical friendly-device-name table exists. Current and historical devices can be derived from `radacct.callingstationid`, with IP, start/stop/update time, counters and NAS data. A new app-owned table is required to persist friendly names without altering RADIUS tables.

### Recharge history

Three existing sources were found:

- `three_d_net_recharge_transactions`: immutable dealer recharge snapshots with public transaction ID, customer username, bytes, amount, validity, before/after expiration, status and indexed customer/date lookup.
- `nawa_audit_log`: current operator actions such as `card.recharge`, `user.package_settle`, and `user.settle`; details are JSON and the subject is indexed.
- `user_recharges`: legacy recharge rows with username, quota/time/expiry added and source package, but no immutable amount/status snapshot.

The API should read and normalize these existing sources. It must not create another recharge workflow. Records retain a source-prefixed reference ID so different legacy sources are not incorrectly merged.

### Notifications

- `notifications` exists but has only `user_id`, title, message, read flag and timestamp; it lacks a notification type and a user index.
- `notification_rules`, `notification_sent`, templates and thresholds also exist for existing operational messaging.

An app-owned notification table is recommended for typed subscriber notifications and read state. Firebase is not configured in this phase.

## Existing APIs and backend logic

- daloRADIUS has PHP pages for subscriber login, usage, packages, cards, settlements and reporting, but no safe subscriber REST API.
- 3D Net API is a hardened Fastify/TypeScript service for dealer/admin roles. It uses JWT, JSON-schema validation, Helmet, rate limiting, a dedicated systemd user and environment configuration.
- A dedicated `3D Subscriber API` should use the same Fastify/TypeScript operational pattern but run as a separate service and identity boundary. Adding subscriber behavior to the dealer login route would create an unsafe role ambiguity.

## Query-plan observations

- Subscriber lookup uses the unique `userinfo.username` key (`const`, one row).
- Credential lookup uses `idx_radcheck_user_attr` (`ref`, approximately two rows for the inspected account).
- Daily usage uses the `nawa_usage_daily` primary key (`range`).
- Active sessions use the existing username/active indexes but still filesort.
- Recent sessions and grouped device history use `radacct.username` and then filesort; the inspected account had only a small per-user row estimate.
- 3D Net recharge history uses `(customer_username, created_at, id)` efficiently.
- Audit and legacy recharge sources use their username/subject indexes but filesort by date.

No index was added during inspection. Recommendations are documented separately in `database_performance_notes.md`.

## Security findings

- The daloRADIUS database account currently has all privileges on the `radius` database. The Subscriber API must receive a separate least-privilege account.
- Docker publishes database port 3307 on all IPv4/IPv6 interfaces. Firewall/rebinding review is required before broader network exposure.
- Apache currently exposes HTTP only; a production mobile API requires HTTPS and an approved hostname/certificate before enabling real mode.
- Existing RADIUS cleartext-compatible credentials are sensitive legacy data. The API must use constant-time comparison and redact request bodies, tokens and password values from logs.

## Unknown items requiring confirmation

1. Production API hostname, TLS certificate and reverse-proxy port/path.
2. Approved access-token TTL and whether refresh tokens are required. This implementation supports refresh-token rotation, but deployment policy must confirm lifetimes.
3. Whether all three recharge sources should be visible, or whether the business wants only dealer transactions plus operator audit settlements.
4. Currency label for legacy package/audit records whose source does not persist an immutable currency/amount snapshot.
5. Retention period for app notifications, refresh-token records and device friendly names.
6. Whether inactive/expired subscribers may log in to see status or must always be rejected. The implementation rejects disabled/expired accounts by default.
7. Whether calling-station IDs are consistently MAC addresses across every NAS. Non-MAC identifiers will be preserved as opaque device identifiers.

## Integration decision

Create a new Fastify + TypeScript service under `backend/`, using MySQL/MariaDB repositories isolated behind service contracts. It will remain undeployed until environment variables, a least-privilege database user, TLS and migration approval are supplied. Flutter keeps mock mode as the safe default and switches to REST only through `USE_MOCK_DATA=false` plus `API_BASE_URL`.
