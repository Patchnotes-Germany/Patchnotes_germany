#!/bin/sh
# Healthcheck for Messenger consumers (workers and the scheduler).
# App\Core\Messenger\WorkerHeartbeatSubscriber touches the heartbeat file on every worker loop.
set -e

HEARTBEAT_FILE="${WORKER_HEARTBEAT_FILE:-/tmp/messenger-worker.heartbeat}"
MAX_AGE="${WORKER_HEARTBEAT_MAX_AGE:-120}"

[ -f "$HEARTBEAT_FILE" ] || exit 1

age=$(( $(date +%s) - $(stat -c %Y "$HEARTBEAT_FILE") ))
[ "$age" -le "$MAX_AGE" ]
