#!/usr/bin/env bash
set -euo pipefail
release=/opt/3d-subscriber-api/releases/20260922-push
stage=/home/anwar/device-lifecycle-20260925
stamp=$(date -u +%Y%m%dT%H%M%SZ)
files=(database/mysql-subscriber-repository.js notifications/notification-service.js notifications/push-worker.js)
for file in "${files[@]}"; do
  /opt/3d-subscriber-api/runtime/bin/node --check "$stage/$(basename "$file")"
  cp -p "$release/dist/$file" "$release/dist/$file.before-device-lifecycle-$stamp"
done
/opt/3d-subscriber-api/runtime/bin/node --check "$stage/radius-device-identity.js"
rollback() {
  for file in "${files[@]}"; do
    cp -p "$release/dist/$file.before-device-lifecycle-$stamp" "$release/dist/$file"
  done
  systemctl restart 3d-subscriber-notifications-api 3d-subscriber-push-worker
}
trap rollback ERR
systemctl stop 3d-subscriber-push-worker
# Permanent event keys are separate from the three-day visible device list.
docker exec -i radius-old-db mariadb -uroot radius < /home/anwar/device-lifecycle-backfill.sql
install -m 0644 "$stage/radius-device-identity.js" "$release/dist/devices/radius-device-identity.js"
for file in "${files[@]}"; do
  cat "$stage/$(basename "$file")" > "$release/dist/$file"
done
systemctl restart 3d-subscriber-notifications-api
systemctl start 3d-subscriber-push-worker
curl --retry 8 --retry-connrefused --retry-delay 1 -fsS http://192.168.230.111:3088/healthz
systemctl is-active 3d-subscriber-notifications-api 3d-subscriber-push-worker
trap - ERR
echo "device_lifecycle_deployed backup_suffix=before-device-lifecycle-$stamp"
