#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Load .env vars
if [ -f "$SCRIPT_DIR/../.env" ]; then
  export $(grep -v '^\s*#' "$SCRIPT_DIR/../.env" | grep -v '^\s*$' | xargs)
fi
if [ -f "$SCRIPT_DIR/../.env.local" ]; then
  export $(grep -v '^\s*#' "$SCRIPT_DIR/../.env.local" | grep -v '^\s*$' | xargs)
fi

export DB_HOST="${DB_HOST:-localhost}"
export DB_PORT="${DB_PORT:-3306}"
export DB_USER="${DB_USER:-root}"
export DB_PASSWORD="${DB_PASSWORD:-}"
export DB_NAME="${DB_NAME:-dump_db}"
export WS_PORT="${WS_PORT:-9090}"

cd "$SCRIPT_DIR"
exec node server.js
