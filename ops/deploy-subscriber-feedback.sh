#!/usr/bin/env bash
set -euo pipefail

release=/opt/3d-subscriber-api/releases/20260922-push
stage=/home/anwar/subscriber-push-20260922
web=/var/www/html/3dradius/operators
stamp=$(date -u +%Y%m%dT%H%M%SZ)

cp "$release/dist/app.js" "$release/dist/app.js.before-feedback-$stamp"
cp "$web/home-modern.php" "$web/home-modern.php.before-feedback-$stamp"
install -m 0644 "$stage/app.js" "$release/dist/app.js"
install -d -m 0755 "$release/dist/feedback"
install -m 0644 "$stage/feedback-routes.js" "$release/dist/feedback/feedback-routes.js"
install -m 0644 "$stage/005_subscriber_feedback.sql" "$release/migrations/005_subscriber_feedback.sql"
install -m 0644 "$stage/nawa-user-messages.php.remote" "$web/nawa-user-messages.php"
install -m 0644 "$stage/home-modern.remote.php" "$web/home-modern.php"
install -m 0644 "$stage/nawa-sidebar.remote.php" "$web/include/menu/sidebar/nawa/default.php"

docker exec -i radius-old-db mariadb -uroot radius < "$release/migrations/005_subscriber_feedback.sql"
docker exec radius-old-db mariadb -uroot radius -e "GRANT SELECT,INSERT ON radius.subscriber_feedback TO 'three_d_subscriber_api'@'172.17.0.1'; FLUSH PRIVILEGES;"
php -l "$web/nawa-user-messages.php"
systemctl restart 3d-subscriber-notifications-api.service
systemctl is-active --quiet 3d-subscriber-notifications-api.service
systemctl is-active --quiet 3d-subscriber-push-worker.service
systemctl is-active --quiet mariadb.service
systemctl is-active --quiet docker.service
echo feedback_deployment_ok
