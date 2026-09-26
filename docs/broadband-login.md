# Broadband access in Eshtraky

Updated and deployed on 2026-09-26.

Broadband username login now uses the same Flutter authentication controller, persistent token storage, dashboard, navigation, services and notification preferences as card/subscriber login. The API client and push binding map subscriber paths to broadband paths according to the signed account role. The server still validates the role and signed identity independently.

## Services

- Dashboard, subscription, usage summary/history, sessions, devices, renaming/disconnection/device speeds, recharge history, notifications/read state, account speed, connection limit and feedback use shared service implementations and request validation.
- Broadband balances/packages and recharges come from `nawa_pppoe_users`, `nawa_pppoe_packages` and `nawa_pppoe_recharges`; no hotspot account is created or modified to enable login.
- RADIUS accounting, device preferences and notification storage use the network username. The deployment check found zero usernames shared by hotspot and broadband accounts. The network must continue enforcing unique usernames across account types.
- Existing personal-profile read/edit endpoints remain available.
- Access tokens retain role `broadband`. Random refresh tokens carry prefix `bb_`, are stored only as hashes in `broadband_refresh_tokens`, rotate transactionally and can be revoked at logout.
- Expired/disabled broadband accounts can inspect their subscription, as before.
- Push registration uses the same preferences, installation ownership, broadcasts, device alerts and feedback notification pipeline. Quota scans include broadband balances, and recharge collection includes `pppoe.user.recharge` audit events. Notification-list expiry/quota warnings use broadband package data.
- Package-default speed resolution includes PPPoE packages; it does not fall back to an unrelated hotspot plan.

## Deployment

`ops/deploy-broadband-services.sh` installs a self-contained shared-service runtime behind the existing broadband route entry point on both API releases, preserving the host application and hotspot routes. The runtime re-exports the host error class so error status codes remain correct. The push worker and device-speed resolver are updated in the notifications release.

Migration: `backend/migrations/015_broadband_refresh_tokens.sql`. Database privileges are additive, with no access to broadband passwords.

Deployed runtime suffix: `20260926T113509Z`. Backups use `.before-services-20260926T113509Z` for the broadband entry modules in both releases, plus the push worker and device-speed resolver in the notifications release. Restore these files and restart the four services named in the deployment script to roll back. The additive refresh table can remain.

## Validation

- Backend typecheck/build passed; 31 tests passed, 2 environment-dependent tests skipped.
- Flutter analysis passed; 9 tests passed; release APK built.
- Live checks passed on ports 3085, 3088 and public HTTPS for all read services, role isolation, refresh rotation/replay rejection and logout.
- Live database push registration/default preferences and quota query passed; test registration was rolled back, leaving no test installation or outgoing notification.
- No real device disconnection, speed change or recharge was performed during validation. End-to-end FCM display on a handset still requires installing the updated APK and logging in with notification permission enabled.

Username-only access remains the requested login model. Existing username knowledge grants account access, just as in code login; no new ownership proof is introduced by this change.
