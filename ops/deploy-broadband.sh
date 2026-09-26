#!/usr/bin/env bash
set -euo pipefail
stage=/home/anwar/subscriber-broadband-20260926
node=/opt/3d-subscriber-api/runtime/bin/node
stamp=$(date -u +%Y%m%dT%H%M%SZ)
releases=(/opt/3d-subscriber-api/releases/20260830-1635-speed /opt/3d-subscriber-api/releases/20260922-push)
services=(3d-subscriber-speed-api 3d-subscriber-notifications-api)
"$node" --check "$stage/broadband-routes.js"
for release in "${releases[@]}"; do
  cp -p "$release/dist/app.js" "$release/dist/app.js.before-broadband-$stamp"
done
rollback() {
  for release in "${releases[@]}"; do
    cp -p "$release/dist/app.js.before-broadband-$stamp" "$release/dist/app.js"
  done
  systemctl restart "${services[@]}"
}
trap rollback ERR
# No credentials or account rows are copied. Grant only the profile columns used.
docker exec -i radius-old-db mariadb -uroot radius <<'SQL'
GRANT SELECT (username,full_name,mobilephone,address,status,radius_enabled,total_quota,used_quota,activated_at,expires_at,package_id), UPDATE (full_name,mobilephone,address) ON radius.nawa_pppoe_users TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT (id,name,upload_speed,download_speed,rate_limit) ON radius.nawa_pppoe_packages TO 'three_d_subscriber_api'@'172.17.0.1';
SQL
for release in "${releases[@]}"; do
  install -d -m 0755 "$release/dist/broadband"
  install -m 0644 "$stage/broadband-routes.js" "$release/dist/broadband/broadband-routes.js"
  python3 - "$release/dist/app.js" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
s = p.read_text()
if 'registerBroadbandRoutes' not in s:
    marker = '    registerRoutes(app, services);'
    if s.count(marker) != 1:
        raise RuntimeError('Unexpected app registration layout')
    s = "import { registerBroadbandRoutes } from './broadband/broadband-routes.js';\n" + s
    s = s.replace(marker, marker + '\n    if (ownedPool) registerBroadbandRoutes(app, ownedPool);')
    p.write_text(s)
PY
  "$node" --check "$release/dist/app.js"
done
systemctl restart "${services[@]}"
for port in 3085 3088; do
  curl --retry 8 --retry-connrefused --retry-delay 1 -fsS "http://192.168.230.111:$port/healthz"
done
systemctl is-active "${services[@]}"
trap - ERR
echo "Broadband deployed; rollback suffix: before-broadband-$stamp"
