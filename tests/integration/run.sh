#!/usr/bin/env bash
set -euo pipefail
export WC_MSS_REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export WC_MSS_PHP_VERSION="${WC_MSS_PHP_VERSION:-8.5}"
export WC_MSS_CACHE_MODE="${WC_MSS_CACHE_MODE:-none}"
case "$WC_MSS_PHP_VERSION" in 8.3|8.4|8.5) ;; *) echo 'Supported PHP versions: 8.3, 8.4, 8.5' >&2; exit 1 ;; esac
case "$WC_MSS_CACHE_MODE" in none|redis) ;; *) echo 'Supported cache modes: none, redis' >&2; exit 1 ;; esac
runtime_directory=$(mktemp -d "${TMPDIR:-/tmp}/wc-mss-runtime.XXXXXX")
export WC_MSS_RUNTIME_VENDOR="$runtime_directory/vendor"
compose=(docker compose -p "wc-mss-test-$$" -f "$WC_MSS_REPO_ROOT/tests/integration/compose.yaml")
if [[ "$WC_MSS_CACHE_MODE" == redis ]]; then compose+=(--profile redis); fi
cleanup() {
    result=$?
    trap - EXIT
    if (( result != 0 )); then "${compose[@]}" logs --tail=20 || true; fi
    "${compose[@]}" down --volumes --remove-orphans || true
    rm -rf "$runtime_directory"
    exit "$result"
}
trap cleanup EXIT
cp "$WC_MSS_REPO_ROOT/composer.json" "$WC_MSS_REPO_ROOT/composer.lock" "$runtime_directory/"
cp -R "$WC_MSS_REPO_ROOT/includes" "$runtime_directory/includes"
composer install --working-dir="$runtime_directory" --no-dev --optimize-autoloader --no-interaction --no-progress
"${compose[@]}" up -d --wait
"${compose[@]}" exec -T source sh -ec '
    runtime_plugin_root=wp-content/plugins/multi-store-sync-for-woocommerce
    find "$runtime_plugin_root/includes" "$runtime_plugin_root/admin" "$runtime_plugin_root/wc-multi-store-sync.php" "$runtime_plugin_root/uninstall.php" -type f -name "*.php" -print0 |
        xargs -0 -n1 php -l > /tmp/wc-mss-runtime-lint.log || { cat /tmp/wc-mss-runtime-lint.log; exit 1; }
    php --version
'
"${compose[@]}" exec -T db mariadb -uroot -pintegration-only -e 'CREATE DATABASE source; CREATE DATABASE target;'
wp() { "${compose[@]}" exec -T "$1" php /tmp/wp-cli.phar --allow-root "${@:2}"; }
for site in source target; do
    "${compose[@]}" exec -T "$site" curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /tmp/wp-cli.phar
    wp "$site" core install --url="http://$site" --title="Integration $site" --admin_user=integration --admin_password=integration-only --admin_email=integration@example.test --skip-email
    wp "$site" rewrite structure '/%postname%/' --hard
    wp "$site" plugin install woocommerce --activate
    if [[ "$WC_MSS_CACHE_MODE" == redis ]]; then
        wp "$site" config set WP_REDIS_HOST redis
        wp "$site" config set WP_REDIS_CLIENT predis
        wp "$site" config set WP_REDIS_PREFIX "wc-mss-$site:"
        if [[ "$site" == source ]]; then redis_database=0; else redis_database=1; fi
        wp "$site" config set WP_REDIS_DATABASE "$redis_database" --raw
        wp "$site" plugin install redis-cache --version=3.0.0 --activate
        wp "$site" redis enable
        wp "$site" redis status
    fi
    wp "$site" plugin activate multi-store-sync-for-woocommerce
    wp "$site" core version
    wp "$site" plugin get woocommerce --field=version
 done
scenario=wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/scenario.php
wp target eval-file "$scenario" setup-target
wp source eval-file "$scenario" setup-source
for stage in create update delete; do
    wp source eval-file "$scenario" "$stage"
    # This disposable source has no production jobs. Run the real queue runner
    # directly so CLI group discovery does not depend on the scheduler store.
    wp source eval 'ActionScheduler::runner()->run("wc-mss-integration");'
    if ! wp target eval-file "$scenario" "verify-$stage"; then
        wp source eval 'foreach (as_get_scheduled_actions(["group" => "wc_multi_store_sync", "per_page" => 100]) as $id => $action) { WP_CLI::log($id . " " . $action->get_hook() . " " . ActionScheduler::store()->get_status($id)); }'
        exit 1
    fi
done
wp source eval 'foreach (as_get_scheduled_actions(["group" => "wc_multi_store_sync", "status" => "failed", "per_page" => 100]) as $id => $action) { WP_CLI::log("Failed action " . $id . " " . $action->get_hook()); foreach (ActionScheduler::logger()->get_logs($id) as $log) { WP_CLI::log($log->get_message()); } }'
wp source eval-file "$scenario" verify-jobs
# Receipt persistence is a separate scenario; its stock updates deliberately do
# not have a matching product on the target store.
wp source eval-file wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/security.php
wp source eval-file wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/http-security.php
wp source eval-file wp-content/plugins/multi-store-sync-for-woocommerce/tests/integration/upgrade.php
bash "$WC_MSS_REPO_ROOT/tests/integration/concurrency.sh" "${compose[@]}"
