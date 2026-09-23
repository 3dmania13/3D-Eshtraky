#!/usr/bin/env bash
set -euo pipefail

release=/opt/3d-subscriber-api/releases/20260922-push
stage=/home/anwar/subscriber-push-20260922
web=/var/www/html/3dradius/operators
stamp=$(date -u +%Y%m%dT%H%M%SZ)

install -d -m 0755 "$release/migrations"
cp "$release/dist/notifications/push-worker.js" "$release/dist/notifications/push-worker.js.before-broadcast-$stamp"
cp "$web/home-modern.php" "$web/home-modern.php.before-broadcast-$stamp"
cp "$web/include/menu/sidebar/nawa/default.php" "$web/include/menu/sidebar/nawa/default.php.before-broadcast-$stamp"
install -m 0644 "$stage/push-worker.js" "$release/dist/notifications/push-worker.js"
install -m 0644 "$stage/004_subscriber_broadcasts.sql" "$release/migrations/004_subscriber_broadcasts.sql"
install -m 0644 "$stage/nawa-notifications.php.remote" "$web/nawa-notifications.php"
install -m 0644 "$stage/home-modern.remote.php" "$web/home-modern.php"
install -m 0644 "$stage/nawa-sidebar.remote.php" "$web/include/menu/sidebar/nawa/default.php"

docker exec -i radius-old-db mariadb -uroot radius < "$release/migrations/004_subscriber_broadcasts.sql"
docker exec radius-old-db mariadb -uroot radius -e "GRANT SELECT,INSERT,UPDATE,DELETE ON radius.subscriber_broadcasts TO 'three_d_subscriber_api'@'172.17.0.1'; GRANT SELECT,INSERT,UPDATE,DELETE ON radius.subscriber_broadcast_recipients TO 'three_d_subscriber_api'@'172.17.0.1'; FLUSH PRIVILEGES;"

php -l "$web/nawa-notifications.php"
systemctl restart 3d-subscriber-push-worker.service
systemctl is-active --quiet 3d-subscriber-push-worker.service
systemctl is-active --quiet 3d-subscriber-notifications-api.service
systemctl is-active --quiet mariadb.service
systemctl is-active --quiet docker.service
echo broadcast_deployment_ok
