#!/bin/bash
set -euo pipefail

LOG_DIR="/var/www/v2board/storage/logs"
TAIL_ACCESS_LOG="${TAIL_ACCESS_LOG:-false}"

mkdir -p "${LOG_DIR}"

# Ensure log files exist so tail can attach immediately.
touch "${LOG_DIR}/webman.log" \
      "${LOG_DIR}/horizon.log" \
      "${LOG_DIR}/nginx-error.log" \
      "${LOG_DIR}/laravel.log"

if [ "${TAIL_ACCESS_LOG}" = "true" ]; then
    touch "${LOG_DIR}/nginx-access.log"
fi

pids=()

stream_file() {
    local tag="$1"
    local file="$2"
    tail -n 0 -F "${file}" 2>/dev/null | sed -u "s/^/[${tag}] /" &
    pids+=("$!")
}

cleanup() {
    if [ "${#pids[@]}" -gt 0 ]; then
        kill "${pids[@]}" 2>/dev/null || true
        wait "${pids[@]}" 2>/dev/null || true
    fi
}

trap cleanup EXIT INT TERM

stream_file "webman" "${LOG_DIR}/webman.log"
stream_file "horizon" "${LOG_DIR}/horizon.log"
stream_file "nginx-error" "${LOG_DIR}/nginx-error.log"
stream_file "laravel" "${LOG_DIR}/laravel.log"

if [ "${TAIL_ACCESS_LOG}" = "true" ]; then
    stream_file "nginx-access" "${LOG_DIR}/nginx-access.log"
fi

wait
