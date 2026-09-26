# Device notification and visibility lifecycle

Deployed 2026-09-25 to the notifications API and push worker in
`/opt/3d-subscriber-api/releases/20260922-push`.

Physical-device notifications now come only from the push worker. Reading the
notification list no longer creates another device alert. Permanent
`subscriber_push_events` keys remember each subscriber/device pair, independent
of sessions and the visible device list. MAC case and colon, hyphen, dotted, or
compact formatting normalize to the same identity. Opaque station IDs retain
their spelling. A device using a different randomized MAC is a different
identity; RADIUS cannot establish that it is the same physical device.

Known historical devices for existing app accounts were backfilled into event
keys without creating or sending notifications. Keep these keys when retaining
or cleaning notification history. Collection excludes known devices before its
batch limit so reconnects cannot block detection of new devices.

`getDevices` includes devices seen within the last 72 hours, inclusive of the
boundary. Last seen is the latest start, interim update, or stop timestamp.
Currently active long sessions remain visible; stale unclosed sessions age out.
Accounting records, friendly names, and notification identities are preserved.
A returning device becomes visible again without another new-device alert.
These server changes work with existing apps after refreshing.

Validation: TypeScript build/typecheck and 24 backend tests passed, with two
optional integration tests skipped. `test/device-lifecycle-sql.mjs` ran the actual
compiled SQL against temporary MariaDB tables and returned `1`, `0`, `1`, and
`active-long,boundary,recent-stop`. This checks MAC normalization, permanent
dedupe, new-device detection, subscriber isolation, and the three-day boundary.
The fixture uses the production event-key `ascii_bin` collation; the lookup
explicitly converts its computed ASCII hash key to avoid a MariaDB collation
conflict. `ops/check-device-collector.mjs` checks the deployed read queries with
the service credentials without enqueuing or delivering notifications.

Deployment is recorded in `ops/deploy-device-lifecycle.sh`. Before-change
compiled modules are retained beside each target with a timestamped
`.before-device-lifecycle-*` suffix. No RADIUS accounting records were deleted.
