#!/usr/bin/env bash
set -euo pipefail
stage=/home/anwar/.codex-connection-limit-deploy
release=/opt/3d-subscriber-api/releases/20260922-push
install -D -m 0644 "$stage/dist/app.js" "$release/dist/app.js"
install -D -m 0644 "$stage/dist/database/mysql-subscriber-repository.js" "$release/dist/database/mysql-subscriber-repository.js"
install -D -m 0644 "$stage/dist/modules/routes.js" "$release/dist/modules/routes.js"
install -D -m 0644 "$stage/dist/connection-limits/connection-limit-service.js" "$release/dist/connection-limits/connection-limit-service.js"
docker exec -i radius-old-db mariadb radius < "$stage/migrations/010_subscriber_connection_limits.sql"
install -m 0755 "$stage/ops/enable-subscriber-connection-limits.sh" /usr/local/sbin/enable-subscriber-connection-limits
/usr/local/sbin/enable-subscriber-connection-limits
systemctl restart 3d-subscriber-notifications-api.service
systemctl is-active --quiet 3d-subscriber-notifications-api.service
curl --fail --silent --show-error http://192.168.230.111:3088/healthz
