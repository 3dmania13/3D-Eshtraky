#!/usr/bin/env bash
set -euo pipefail

readonly cloudflared_bin="$HOME/.local/bin/cloudflared"
readonly origin_url="http://192.168.230.111:3085"
readonly state_dir="$HOME/.local/state/3d-subscriber-tunnel"
readonly log_file="$state_dir/cloudflared.log"

mkdir -p "$state_dir"
exec "$cloudflared_bin" tunnel \
  --no-autoupdate \
  --protocol http2 \
  --url "$origin_url" \
  >>"$log_file" 2>&1
