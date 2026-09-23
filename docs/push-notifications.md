# Subscriber push notifications

## Deployment on 2026-09-22

The public tunnel's unchanged origin address, `192.168.230.111:3085`, forwards
new connections to port 3088. The API runs as
`3d-subscriber-notifications-api.service` from
`/opt/3d-subscriber-api/releases/20260922-push`.
`3d-subscriber-push-worker.service` polls every 10 seconds, without overlapping
polls. Both units are enabled at boot. Previously serving API, RADIUS, database,
and tunnel processes were left running. Before-change routing files are saved
in the release directory with the suffix `.before-push`.

Apply `backend/migrations/003_subscriber_push_delivery.sql` and the matching
application-table grants before starting these services. The migration creates
only subscriber-owned tables. Firebase credentials remain in
`/etc/3d-subscriber-api/firebase/eshtraky-firebase-admin.json`, readable by the
service account; they are never included in the APK or returned through the API.
Set `GOOGLE_APPLICATION_CREDENTIALS` in the service environment file. The worker
uses Firebase HTTP v1 with short-lived OAuth credentials.

## Events and delivery

- First installation registration creates a welcome notification. A previously
  unseen installation on an existing account creates a new-phone login alert.
  Token refreshes and repeated app launches do not create duplicate events.
- New successful operator audit events `card.recharge`, `user.package_settle`,
  and `user.settle`, and completed dealer recharge transactions create recharge
  notifications. Failed and still-processing transactions do not notify.
- Permanent initial ID boundaries exclude historical payments. Boundaries do
  not advance past transactions that have yet to commit; persistent event keys
  prevent duplicate notifications on repeated polling and restarts.
- Notifications appear in the existing in-app list. Push delivery is queued for
  devices registered when the event is created. Unregistered phones can still
  see the history in the app; installing later does not replay old pushes.
- Delivery retries use exponential backoff, up to eight attempts. Unregistered
  Firebase tokens are disabled. Pending pushes older than 24 hours are skipped.
  FCM TTL is one hour. A fixed notification tag limits duplicate presentations
  if a process fails after Firebase accepts a message but before DB commit;
  transport delivery is not an exactly-once guarantee.
- Registration is bound to the authenticated username, and transfer/revocation
  advances the device generation. The worker rechecks ownership under a lock
  before sending. Logout also attempts Firebase token deletion. An entirely
  offline logout cannot guarantee immediate remote revocation until connectivity
  returns. Inactive devices expire from delivery after 30 days without renewal.

## Client and verification

The updated client registers on authenticated startup/login, retries after
connectivity failures, renews on resume, follows FCM token refresh, and revokes
on logout. Enable Android notifications. Tapping a notification opens the
notification list. The old APK has no registration binding and must be updated.

Validation performed before cutover:

- TypeScript checks and backend tests.
- A disposable MariaDB schema exercised registration, token rotation, duplicate
  prevention, account switching, logout, recharge status transitions, historical
  event exclusion, transient retry, invalid-token handling, and JWT scoping.
- Firebase OAuth and HTTP v1 validation accepted the credentials and rejected
  the intentionally invalid test token, without sending to a real device.
- Public health and protected registration/revocation routes were checked.
- All 40 previously running service PIDs and active states were checked unchanged.
- Flutter analysis passed, all eight Flutter tests passed, and the release APK
  built successfully as version 1.0.1 (build 2). Lower Gradle memory/worker limits
  resolved the initial local Android resource-link timeout.

To verify a handset: install the new APK over the existing app, sign in, and
confirm the welcome notification. Check `subscriber_push_devices` by username
without printing its token. Inspect counts/statuses in `subscriber_push_outbox`
and the worker journal. `sent` means Firebase accepted the message, not that a
human saw it. Real handset receipt still needs confirmation after installation.

The optional `test/push-integration.test.ts` runs only when
`PUSH_TEST_DATABASE` names a dedicated `subscriber_push_test_*` database with
separate credentials. Never run its fixture setup against production.

For rollback to the pre-push API, restore the two `.before-push` routing files,
replace only the two `subscriber-speed-fix` NAT destinations with
`192.168.230.111:3087`, and reload systemd metadata. The API on 3087 remains
running. Keep the new API available while existing connections drain. Stopping
only the push worker pauses delivery without affecting subscriber connectivity.
