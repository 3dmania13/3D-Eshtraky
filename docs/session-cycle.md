# Current-package sessions

The session endpoint now returns only sessions starting at or after the latest
successful `user.package_settle` or `user.settle` audit event for that subscriber.
The cutoff is applied in SQL before the 100-session limit. A card top-up does
not reset this list. Accounts without a settlement keep their existing history.
Sessions already open before settlement are excluded; their accounting records
and the subscriber's quota remain unchanged.

The Flutter usage screen builds history and session rows lazily and labels the
list as current-package sessions. The UI improvement requires a new app build.
The server filter also works with existing app installations after refreshing.

Deployed on 2026-09-25 to `3d-subscriber-notifications-api.service`, release
`/opt/3d-subscriber-api/releases/20260922-push`. Only the compiled `getSessions`
method was patched using `ops/patch-session-cycle.py`. The original repository
module is saved alongside it as
`mysql-subscriber-repository.js.before-session-cycle-20260925`.

Validation: TypeScript typecheck and build, 22 backend tests passed (2 optional
integration tests skipped), Flutter analysis passed. The SQL regression fixture
in `backend/test/session-cycle-sql.py` ran on MariaDB using temporary tables:
old sessions excluded, inclusive settlement boundary, newest-first limit,
subscriber isolation, top-up ignored, no-settlement fallback, and empty new cycle.
It does not write to production accounting or audit tables.
