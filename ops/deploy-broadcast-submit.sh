#!/usr/bin/env bash
set -euo pipefail
stage=/home/anwar/broadcast-submit-20260926
target=/var/www/html/3dradius/operators/nawa-notifications.php
php -l "$stage/nawa-notifications.php"
cmp "$target" "$stage/original.php"
backup="$target.before-submit-$(date -u +%Y%m%dT%H%M%SZ)"
cp -p "$target" "$backup"
install -o "$(stat -c %u "$target")" -g "$(stat -c %g "$target")" -m "$(stat -c %a "$target")" "$stage/nawa-notifications.php" "$target"
php -l "$target"
echo "Broadcast submit fix deployed. Backup: $backup"
