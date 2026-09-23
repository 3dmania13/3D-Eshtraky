#!/usr/bin/env bash
set -euo pipefail
release=/opt/3d-subscriber-api/releases/20260922-push
stage=/home/anwar/subscriber-push-20260922
web=/var/www/html/3dradius/operators
stamp=$(date -u +%Y%m%dT%H%M%SZ)

cp "$release/dist/app.js" "$release/dist/app.js.before-feedback-resolution-$stamp"
cp "$release/dist/notifications/push-worker.js" "$release/dist/notifications/push-worker.js.before-feedback-resolution-$stamp"
cp "$web/nawa-user-messages.php" "$web/nawa-user-messages.php.before-feedback-resolution-$stamp"
install -m 0644 "$stage/app.js" "$release/dist/app.js"
install -m 0644 "$stage/feedback-routes.js" "$release/dist/feedback/feedback-routes.js"
install -m 0644 "$stage/push-worker.js" "$release/dist/notifications/push-worker.js"
install -m 0644 "$stage/006_feedback_contact_and_resolution.sql" "$release/migrations/006_feedback_contact_and_resolution.sql"
install -m 0644 "$stage/nawa-user-messages.php.remote" "$web/nawa-user-messages.php"
docker exec -i radius-old-db mariadb -uroot radius < "$release/migrations/006_feedback_contact_and_resolution.sql"
docker exec radius-old-db mariadb -uroot radius -e "GRANT SELECT,INSERT,UPDATE ON radius.subscriber_feedback TO 'three_d_subscriber_api'@'172.17.0.1'; FLUSH PRIVILEGES;"
php -l "$web/nawa-user-messages.php"
systemctl restart 3d-subscriber-notifications-api.service
systemctl restart 3d-subscriber-push-worker.service
systemctl is-active --quiet 3d-subscriber-notifications-api.service
systemctl is-active --quiet 3d-subscriber-push-worker.service
systemctl is-active --quiet mariadb.service
systemctl is-active --quiet docker.service
echo feedback_resolution_deployment_ok
