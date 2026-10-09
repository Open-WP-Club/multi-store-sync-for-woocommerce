#!/usr/bin/env bash
set -euo pipefail
export WC_MSS_REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
compose=(docker compose -p "wc-mss-test-$$" -f "$WC_MSS_REPO_ROOT/tests/integration/compose.yaml")
cleanup() {
    result=$?
    if (( result != 0 )); then "${compose[@]}" logs --tail=20; fi
    "${compose[@]}" down --volumes --remove-orphans
    exit "$result"
}
trap cleanup EXIT
"${compose[@]}" up -d --wait
"${compose[@]}" exec -T db mariadb -uroot -pintegration-only -e 'CREATE DATABASE source; CREATE DATABASE target;'
wp() { "${compose[@]}" exec -T "$1" php /tmp/wp-cli.phar --allow-root "${@:2}"; }
for site in source target; do
    "${compose[@]}" exec -T "$site" curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
    wp "$site" core install --url="http://$site" --title="Integration $site" --admin_user=integration --admin_password=integration-only --admin_email=integration@example.test --skip-email
    wp "$site" rewrite structure '/%postname%/' --hard
    wp "$site" plugin install woocommerce --activate
    wp "$site" plugin activate multi-store-sync-for-woocommerce
    wp "$site" core version
    wp "$site" plugin get woocommerce --field=version
 done
scenario=wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/scenario.php
wp target eval-file "$scenario" setup-target
wp source eval-file "$scenario" setup-source
for stage in create update delete; do
    wp source eval-file "$scenario" "$stage"
    wp source action-scheduler run --group=wc_multi_store_sync --batch-size=50 --batches=1
    wp target eval-file "$scenario" "verify-$stage"
done
wp source eval-file "$scenario" verify-jobs
