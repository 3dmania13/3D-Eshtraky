#!/usr/bin/env bash
set -euo pipefail
stage=/home/anwar/subscriber-broadband-services
node=/opt/3d-subscriber-api/runtime/bin/node
stamp=$(date -u +%Y%m%dT%H%M%SZ)
releases=(/opt/3d-subscriber-api/releases/20260830-1635-speed /opt/3d-subscriber-api/releases/20260922-push)
push=${releases[1]}
services=(3d-subscriber-speed-api 3d-subscriber-notifications-api 3d-subscriber-push-worker 3d-subscriber-device-speed-worker)
docker exec -i radius-old-db mariadb -uroot radius < "$stage/015_broadband_refresh_tokens.sql"
docker exec -i radius-old-db mariadb -uroot radius <<'SQL'
GRANT SELECT (id,username,full_name,mobilephone,address,status,radius_enabled,total_quota,used_quota,activated_at,expires_at,package_id), UPDATE (full_name,mobilephone,address) ON radius.nawa_pppoe_users TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT (id,name,upload_speed,download_speed,rate_limit) ON radius.nawa_pppoe_packages TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT ON radius.nawa_pppoe_recharges TO 'three_d_subscriber_api'@'172.17.0.1';
GRANT SELECT,INSERT,UPDATE ON radius.broadband_refresh_tokens TO 'three_d_subscriber_api'@'172.17.0.1';
SQL
for release in "${releases[@]}"; do
  cp -p "$release/dist/broadband/broadband-routes.js" "$release/dist/broadband/broadband-routes.js.before-services-$stamp"
  install -d "$release/dist/broadband-runtime-$stamp"
  cp -a "$stage/dist/." "$release/dist/broadband-runtime-$stamp/"
  # The host error handler uses instanceof AppError; share its module identity.
  printf "export * from '../errors.js';\n" > "$release/dist/broadband-runtime-$stamp/errors.js"
done
for file in notifications/push-worker.js devices/live-device-speed-service.js; do
  cp -p "$push/dist/$file" "$push/dist/$file.before-services-$stamp"
done
rollback() {
  for release in "${releases[@]}"; do
    cp -p "$release/dist/broadband/broadband-routes.js.before-services-$stamp" "$release/dist/broadband/broadband-routes.js"
  done
  for file in notifications/push-worker.js devices/live-device-speed-service.js; do
    cp -p "$push/dist/$file.before-services-$stamp" "$push/dist/$file"
  done
  systemctl restart "${services[@]}"
}
trap rollback ERR
for release in "${releases[@]}"; do
  printf "export { registerBroadbandRoutes } from '../broadband-runtime-%s/broadband/broadband-routes.js';\n" "$stamp" > "$release/dist/broadband/broadband-routes.js"
  "$node" --check "$release/dist/broadband/broadband-routes.js"
done
for file in notifications/push-worker.js devices/live-device-speed-service.js; do
  install -m 0644 "$stage/dist/$file" "$push/dist/$file"
  "$node" --check "$push/dist/$file"
done
systemctl restart "${services[@]}"
for port in 3085 3088; do
  curl --retry 8 --retry-connrefused --retry-delay 1 -fsS "http://192.168.230.111:$port/healthz"
done
systemctl is-active "${services[@]}"
trap - ERR
echo "Broadband services deployed; backup suffix: before-services-$stamp"
