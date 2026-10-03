#!/usr/bin/env bash
set -euo pipefail

export WORKER_PORT="${WORKER_PORT:-3000}"

exec python3 -m uvicorn worker.server:app --host 0.0.0.0 --port "${WORKER_PORT}"
