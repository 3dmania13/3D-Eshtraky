# Subscriber HTTPS connectivity

The former `counter-intention-march-poker.trycloudflare.com` hostname returned
NXDOMAIN on 2026-09-19. The subscriber API at `192.168.230.111:3085` remained healthy.

The temporary replacement is:
`https://possess-defense-validity-generating.trycloudflare.com`.

The RADIUS server runs `3d-subscriber-public-tunnel.service`, installed from
`ops/3d-subscriber-public-tunnel.service`, enabled at boot. It forwards HTTPS
requests to the existing subscriber API without changing the RADIUS database.

This is still a Cloudflare Quick Tunnel: restarting its process can change the
hostname. Automatic service restart does **not** preserve the public address.
For a permanent fix, configure a named tunnel with a user-owned domain, replace
`AppConfig.apiBaseUrl`, and rebuild the app. Existing installed APKs retain their
compiled endpoint and must be updated.

Check service status and the current URL with:

```sh
systemctl status 3d-subscriber-public-tunnel.service
journalctl -u 3d-subscriber-public-tunnel.service -n 40 --no-pager
```

`GET /healthz` should return HTTP 200 with `{"status":"ok"}`. An empty JSON
request to `POST /api/v1/auth/code-login` should return HTTP 400 with a validation
error; this checks routing without signing into a subscriber account.

## Speed selection deployment (2026-09-21)

The public origin on port 3085 was still running the August cycle release,
which returned 404 for `/api/v1/subscriber/speed`. The project already contained
the speed implementation, but it had not been deployed to that origin.

A parallel release at `/opt/3d-subscriber-api/releases/20260921-speed-fix`
copies the running cycle release and adds only the speed service, its repository
methods, routes, and service registration. It shares the original dependencies
and JWT configuration. `3d-subscriber-speed-fix.service` listens on port 3086.

`3d-subscriber-speed-routing.service` installs narrowly scoped IPv4 NAT rules
for new connections to `192.168.230.111:3085`, forwarding them to port 3086.
Its script is `/usr/local/sbin/3d-subscriber-speed-routing`. Both new units are
enabled at boot. The public tunnel and original API continue running; their
configuration and public URL were not changed. No original service was stopped
or restarted, and all original running service PIDs were verified unchanged.

Validation passed for all seven speed choices, unauthenticated rejection,
invalid selection rejection, and authenticated GET/PUT/readback over public
HTTPS. Tests used temporary synthetic usernames; their rows were removed.
Existing database privileges already included the required radreply access.
Speed changes are stored for the next connection; this implementation does
not disconnect active subscriber sessions or send a live router update.

To roll back routing without stopping the original services:

```sh
sudo systemctl disable --now 3d-subscriber-speed-routing.service
```

This removes only the two tagged forwarding rules. New connections then reach
the original API (which does not support speed selection); already established
connections may continue using the parallel release until they close. Keep the
parallel API running while those connections drain.

## Immediate speed updates (2026-09-21)

The current public target is now port **3087**, served by
`3d-subscriber-live-speed.service` from
`/opt/3d-subscriber-api/releases/20260921-live-speed`. The same tagged routing
rules and boot routing unit now target this release. Ports 3085 and 3086 remain
running to preserve established connections. All service PIDs that were running
before this deployment were verified unchanged.

Saving a speed now sends a session-specific RADIUS CoA request with
`User-Name`, `Acct-Session-Id`, `Framed-IP-Address`, and `Mikrotik-Rate-Limit`.
It never sends Disconnect-Request. This uses the existing NAS addresses,
legacy NAS aliases, shared secrets, and incoming ports (3799 for networks 4/6,
1700 for the others). See [MikroTik's CoA documentation](https://help.mikrotik.com/docs/spaces/ROS/pages/328097/RADIUS).

Only authenticated CoA-ACK responses count as immediate success. Offline,
partial, rejected, and timed-out updates are reported separately from successful
live application. The saved selection remains available for future connections.
The open option restores a unique group rate or the known package rate; an
explicitly uncapped package uses `0/0`. Unknown or ambiguous policies remain
pending instead of guessing a cap. The API needs `/usr/bin/radclient` and narrow
read grants on `radgroupreply` and the RADIUS routing columns of `nas`.

Validation: 17 local backend tests passed; the Linux-only transport test passed
on the server as the service user, exercising authenticated ACK, NAK, and forged
ACK rejection against a local UDP fixture. Read-only real-session routing and
authenticated public HTTPS save/readback were checked. Synthetic rows were
removed. No real subscriber's speed was changed for verification because a test
subscriber was not identified. A real subscriber CoA-ACK remains to be confirmed.

The Flutter success message now uses `applied_immediately` and `status` from the
API. An older installed APK retains its hardcoded next-connection message until
rebuilt and installed (or hot reloaded when running from the IDE).

Before-change routing files are preserved inside the live-speed release with
the suffix `.before-live-speed`. Disabling the routing unit as described above
still returns new connections to the original port 3085 API, which lacks speed
selection. To roll back only live application while keeping saved selection,
restore those two routing files and replace the two tagged NAT destinations with
`192.168.230.111:3086`; leave both newer API services running for connection draining.
