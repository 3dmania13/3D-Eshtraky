# Network 7 broadband setup — 2026-09-23

Server: `192.168.230.111`. Router: `192.168.230.246` (`nas.id=12`, `network7`).

## Completed

- FreeRADIUS default virtual server reads broadband credentials, package speed, enabled state, expiry and quota from `nawa_pppoe_users` / `nawa_pppoe_packages`, before legacy `radcheck` processing.
- Broadband authentication is limited to PPP on Network 7. Legacy hotspot authentication remains on its existing path.
- Reply supplies `PPPOE-POOL`, `PPPOE` profile, package rate, DNS, 60-second accounting and expiry-based session timeout.
- Accounting updates broadband usage from Network 7 sessions in the current billing cycle.
- `/usr/local/sbin/nawa-pppoe-network7-enforce.php` checks active broadband accounts every minute through `nawa-pppoe-network7-enforce.timer` and requests disconnection on expiry, disabled state or exhausted quota. Router RADIUS incoming disconnects are enabled on port 1700. Actual disconnect delivery has not been tested with a live subscriber.
- New accounts created in `operators/nawa-pppoe-user-new.php` now enable RADIUS automatically; explanatory UI text was updated.

## Verification

- FreeRADIUS configuration validation and reload passed; service and enforcement timer active.
- Account `asd`: PAP and CHAP Access-Accept, `30M/200M`.
- Wrong password, another NAS address and a non-PPP request: Access-Reject.
- PHP syntax checks passed and enforcement service completed successfully with no active broadband sessions.
- Router RADIUS shared secret matches its registered NAS secret (values not recorded).

## Still blocked

Router API account `3DRadiusRead` rejected configuration writes (`not enough permissions`). No router settings were changed. Expanding PPPoE to every physical port and configured VLAN still requires a router account with write permission. A prepared script exists at `/tmp/pppoe-net7-router.php`; review and use appropriate credentials before running. It adds listeners without moving interfaces or changing their IP configuration.

Existing PPPoE server `service1` uses `bridge1`: ether2, ether3, ether4, ether6, ether7, ether8, ether9. Existing VLAN777 test service is invalid while its underlying interface is down. There were no active PPP sessions at inspection. End-to-end modem connectivity and internet access remain unverified.

## Backups on server

- `/root/pppoe-network7-20260923-170649/default`: original FreeRADIUS virtual server.
- `/root/pppoe-network7-user-new.before.php`: original account creation page.
- `/root/pppoe-network7-router-before.json`: original PPP server/profile settings (router writes failed).

Rollback: restore the two source files, validate FreeRADIUS with `freeradius -XC`, reload the service, and disable `nawa-pppoe-network7-enforce.timer`. Do not restore router settings: no router mutation succeeded.
