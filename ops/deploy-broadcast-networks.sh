#!/usr/bin/env bash
set -euo pipefail
stage=/home/anwar/broadcast-networks-20260926
target=/var/www/html/3dradius/operators/nawa-notifications.php
php -l "$stage/nawa-notifications.php"
# Refuse to overwrite changes made after the page was fetched.
cmp "$target" "$stage/original.php"
backup="$target.before-networks-$(date -u +%Y%m%dT%H%M%SZ)"
cp -p "$target" "$backup"
install -o "$(stat -c %u "$target")" -g "$(stat -c %g "$target")" -m "$(stat -c %a "$target")" "$stage/nawa-notifications.php" "$target"
php -l "$target"
echo "Network selector deployed. Backup: $backup"
