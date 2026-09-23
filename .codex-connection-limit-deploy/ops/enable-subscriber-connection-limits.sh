#!/usr/bin/env bash
set -euo pipefail

# Enable SQL simultaneous-use checks only for accounts that explicitly chose a
# limit in the subscriber app. This does not change existing operator/package
# rows and does not stop the FreeRADIUS daemon.
config="$(readlink -f /etc/freeradius/3.0/sites-enabled/default)"
backup="/etc/freeradius/3.0/default.backup-before-subscriber-connection-limits-$(date -u +%Y%m%d-%H%M%S)"

cp -p "$config" "$backup"
python3 - "$config" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
source = path.read_text()
old = '''session {
#\tradutmp

\t# Apply SQL Simultaneous-Use only to Nawa packages 72-83
\tif ("%{sql:SELECT COALESCE(package_id,0) FROM userinfo WHERE username='%{SQL-User-Name}' LIMIT 1}" =~ /^(72|73|74|75|76|77|78|79|80|81|82|83)$/) {
\t\tsql
\t}
}
'''
new = '''session {
#\tradutmp

\t# Only self-service limits get a simultaneous-use check. Existing package
\t# and operator Simultaneous-Use rows retain their current behavior.
\tif ("%{sql:SELECT 1 FROM subscriber_connection_limits WHERE username='%{SQL-User-Name}' LIMIT 1}" == "1") {
\t\tsql
\t}
}
'''
if old not in source:
    raise SystemExit('Expected session block was not found; restored configuration was left untouched.')
path.write_text(source.replace(old, new, 1))
PY

if ! freeradius -XC; then
  cp -p "$backup" "$config"
  freeradius -XC
  echo "FreeRADIUS validation failed; original configuration restored." >&2
  exit 1
fi

systemctl reload freeradius
systemctl is-active --quiet freeradius
echo "Subscriber connection-limit checks enabled. Backup: $backup"
