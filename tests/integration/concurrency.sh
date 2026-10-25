#!/usr/bin/env bash
# Called by run.sh: bash tests/integration/concurrency.sh "${compose[@]}"
set -euo pipefail
compose=("$@")
if (( ${#compose[@]} == 0 )); then
    echo 'Pass the docker compose command and its project/file arguments.' >&2
    exit 2
fi
scenario=wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/concurrency.php
mode=${WC_MSS_CACHE_MODE:-none}
barrier="/tmp/wc-mss-concurrency-$mode"
owner_pid=
owner_log=$(mktemp)
wp_stage() {
    "${compose[@]}" exec -T -e "WC_MSS_CACHE_MODE=$mode" source php /tmp/wp-cli.phar --allow-root eval-file "$scenario" "$1"
}
cleanup() {
    result=$?
    trap - EXIT
    # Release a waiting worker even when a contender assertion fails.
    "${compose[@]}" exec -T source sh -c 'touch "$1/release" "$1/release-claim"' _ "$barrier" >/dev/null 2>&1 || true
    if [[ -n "$owner_pid" ]]; then
        wait "$owner_pid" || true
    fi
    cat "$owner_log"
    rm -f "$owner_log"
    exit "$result"
}
trap cleanup EXIT
wp_stage setup
wp_stage held >"$owner_log" 2>&1 &
owner_pid=$!
# Bounded container-side wait; the worker also has an independent 40-second deadline.
"${compose[@]}" exec -T source sh -c '
    count=0
    until test -f "$1/held"; do
        count=$((count + 1))
        if test "$count" -ge 150; then echo "Claim owner did not reach barrier" >&2; exit 1; fi
        sleep 0.1
    done
' _ "$barrier"
wp_stage contender
"${compose[@]}" exec -T source sh -c 'touch "$1/release"' _ "$barrier"
wait "$owner_pid"
owner_pid=
wp_stage verify
# A second overlap covers a request whose INSERT arrives after another
# process has already completed the receipt: it must acknowledge the duplicate.
wp_stage setup
wp_stage late-claim >>"$owner_log" 2>&1 &
owner_pid=$!
"${compose[@]}" exec -T source sh -c '
    count=0
    until test -f "$1/late-claim"; do
        count=$((count + 1))
        if test "$count" -ge 150; then echo "Late claimant did not reach barrier" >&2; exit 1; fi
        sleep 0.1
    done
' _ "$barrier"
wp_stage deliver
"${compose[@]}" exec -T source sh -c 'touch "$1/release-claim"' _ "$barrier"
wait "$owner_pid"
owner_pid=
wp_stage verify
wp_stage rate-prime
wp_stage rate-check
