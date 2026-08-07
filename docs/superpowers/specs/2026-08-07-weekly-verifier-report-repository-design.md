# Weekly Sync Verifier split — step 1: ReportRepository

Source: `AUDIT_REPORT.md` "Bigger, riskier item" — `includes/weekly-sync-verifier.php`
(2357 lines, ~50 static methods, 13 external files depend on the class). Candidate
split: `Scheduler` / `Comparator` / `ReportRepository` / `EmailNotifier` (+ a 5th,
unnamed-by-the-audit bucket for remote data fetching/product selection, identified
during this pass). Too large for one sitting — extracted incrementally, lowest-risk
class first. This spec covers only `ReportRepository`; `EmailNotifier`,
`RemoteDataFetcher`, `Comparator`, `Scheduler` are separate, later passes.

## Decisions (made with plugin owner)

1. **Incremental, one class per session** — not a single big-bang split.
2. **`WC_Multi_Store_Weekly_Sync_Verifier` stays as a permanent facade** for every
   externally-called method, delegating to the new focused classes. External call
   sites (13 files) are not touched. Matches this codebase's existing "supported
   API surface" convention (`test_has_required_methods()` guards elsewhere).

## Scope: ReportRepository

New file `includes/weekly-verification-report-repository.php`,
class `WC_Multi_Store_Weekly_Verification_Report_Repository`. Moves:

- `const string TABLE_NAME`
- `create_table()`, `save_report()` (was `private`, becomes `public` — it's the
  class's own API now, not an internal implementation detail), `get_reports()`,
  `get_report()`, `get_latest_report()`, `table_exists()`, `cleanup_old_reports()`,
  `get_orphan_products_from_report()`

All pure `$wpdb` CRUD + one in-memory report-data reader (`get_orphan_products_from_report`,
which calls `get_latest_report()` internally when passed no report — kept together
since it's tightly coupled to the report row shape, not worth a 6th class for one method).

`VERIFICATION_LOCK` stays on the facade (belongs to the Scheduler concern, next pass).

### Facade changes

`includes/weekly-sync-verifier.php` keeps thin delegating wrappers for the 7
externally-called methods (`create_table`, `get_reports`, `get_report`,
`get_latest_report`, `table_exists`, `cleanup_old_reports`,
`get_orphan_products_from_report`) — each just forwards to the new class.

`save_report()` was never external (only 2 internal call sites: `run_verification()`
line 225, `finalize_async_verification()` line 2140) — both updated to call
`WC_Multi_Store_Weekly_Verification_Report_Repository::save_report()` directly; no
facade wrapper needed.

### Wiring

- `tests/php/bootstrap.php`: add `require_once` for the new file before
  `weekly-sync-verifier.php`.
- Composer's classmap autoload (`composer.json`) picks up the new file in
  production automatically — no manual `require_once` needed in
  `wc-multi-store-sync.php`.

### Tests

New `tests/php/Unit/WeeklyVerificationReportRepositoryTest.php` — moves (not
duplicates) these existing tests, repointed at the new class:

- From `WeeklySyncVerifierTest.php`: `test_table_name_constant`,
  `test_table_exists_returns_true`, `test_table_exists_returns_false`,
  `test_get_reports_queries_database`, `test_get_latest_report_returns_null_when_no_table`,
  `test_cleanup_old_reports_default_days`, `test_cleanup_old_reports_custom_days`.
- From `WeeklySyncVerifierExtendedTest.php`: `test_get_report_returns_report`,
  `test_get_report_returns_null_when_not_found`,
  `test_get_latest_report_returns_report_when_exists`.

`SecurityRegressionTest.php`'s 3 ORDER-BY-whitelist regression tests for
`get_reports()` stay in that file (it aggregates security regressions across many
classes, not just this one) — only the class name in the call changes.

No other files change. `AdminAjaxForceSyncTest.php`'s `get_orphan_products_from_report`
coverage drives everything through a mocked `$wpdb` regardless of which class
implements the method, and the facade call site it exercises
(`class-admin-ajax.php:146`) is unchanged — verified no edit needed there.

## Out of scope (later passes)

- `EmailNotifier`: `send_email_notification`, `format_email_message`,
  `format_discrepancy_message` + helpers.
- `RemoteDataFetcher`/product selection: `get_remote_product`, `build_remote_index`,
  `prefetch_remote_batch_data`, `get_products_to_verify`, `scan_ghost_products`,
  `load_local_sku_set`/`load_local_slug_set`, `get_api_client`/`clear_api_client_pool`,
  `$api_client_pool`/`$batch_cache` static state.
- `Comparator`: `verify_product`, `check_full_product_fields`, all `compare_*`
  methods, `should_exclude_product`/`get_exclusion_reasons`, `tally_discrepancies_by_type`.
- `Scheduler`: `run_verification`, `schedule_verification`/`unschedule_verification`,
  the async batch machinery (`schedule_async_verification`, `process_verification_batch`,
  `finalize_async_verification`, `get_verification_progress`, `cancel_async_verification`),
  `auto_correct_discrepancies`, settings (`get_settings`/`update_settings`).
