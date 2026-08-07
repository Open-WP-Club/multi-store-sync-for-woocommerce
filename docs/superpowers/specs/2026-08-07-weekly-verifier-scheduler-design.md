# Weekly Sync Verifier split — step 5 (final): Scheduler

Source: `AUDIT_REPORT.md` "Bigger, riskier item". Steps 1–4 (ReportRepository,
EmailNotifier, RemoteDataFetcher, Comparator) are done. This is the last piece —
everything remaining in the facade after step 4 is Scheduler-domain: there is no
more non-delegating logic left over once this moves.

## Scope: Scheduler

New file `includes/weekly-verification-scheduler.php`,
class `WC_Multi_Store_Weekly_Verification_Scheduler`. Moves:

- `const VERIFICATION_LOCK`, `const ASYNC_PROGRESS_TRANSIENT`, `const ASYNC_BATCH_HOOK`
- `run_verification()`, `auto_correct_discrepancies()` (private)
- `get_settings()`, `update_settings()`
- `schedule_verification()`, `unschedule_verification()`, `calculate_next_run_time()` (private), `get_next_scheduled_time()`
- `get_async_batch_size()` (private), `schedule_async_verification()`, `process_verification_batch()`, `finalize_async_verification()` (private), `get_verification_progress()`, `cancel_async_verification()`

## Constants: two different outcomes

- `VERIFICATION_LOCK` — no external (non-test) file reads it directly (only
  `run_verification()` itself and tests). Moves with no facade alias, same as
  `TABLE_NAME` in step 1. Tests that referenced it move to the new test file.
- `ASYNC_PROGRESS_TRANSIENT` — `includes/hooks.php:681` reads it directly
  (`delete_transient(WC_Multi_Store_Weekly_Sync_Verifier::ASYNC_PROGRESS_TRANSIENT)`
  in an exception handler). Per this refactor's standing rule ("13 external call
  sites are not touched"), the facade keeps
  `const string ASYNC_PROGRESS_TRANSIENT = WC_Multi_Store_Weekly_Verification_Scheduler::ASYNC_PROGRESS_TRANSIENT;`
  (constant-expression reference to another class's constant — valid, PHP 8.4 is
  the plugin's floor). `hooks.php` is untouched.
- `ASYNC_BATCH_HOOK` — only used internally by the three async methods being
  moved together. No facade alias needed.

## Internal dependency resolved

`weekly-verification-email-notifier.php:23` calls
`WC_Multi_Store_Weekly_Sync_Verifier::get_settings()` — flagged in step 2's audit
note as "temporary, resolves once Scheduler/Settings is extracted." Now that
`get_settings()` has a real home, `EmailNotifier` calls
`WC_Multi_Store_Weekly_Verification_Scheduler::get_settings()` directly instead
of routing through the facade. The facade's own `get_settings()` delegating
wrapper is unaffected (still used by `action-scheduler-manager.php`,
`wc-settings-integration.php`).

## Facade changes

All public methods above get thin delegating wrappers on
`WC_Multi_Store_Weekly_Sync_Verifier` (matches the 8 external call sites in
`wc-multi-store-sync.php`, `hooks.php`, `wc-settings-integration.php`,
`cli-commands.php`, `class-admin-ajax.php`, `action-scheduler-manager.php`,
`admin/views/weekly-verification.php`) — none of them change.

Private methods (`auto_correct_discrepancies`, `calculate_next_run_time`,
`get_async_batch_size`, `finalize_async_verification`) get no facade wrapper —
they're internal to the Scheduler now, same treatment as the private methods
already moved in steps 1–4.

## Separately found, out of scope for this step

- `includes/cli-commands.php:436` calls
  `WC_Multi_Store_Weekly_Sync_Verifier::run_verification_sync($limit ?: null)` —
  no such method exists anywhere (checked; not even before this refactor started,
  per `git log -S`). `wp mss verify` fatal-errors every time it's run. Pre-existing
  bug from the v4.0.0 commit, unrelated to this split. Flagged for the plugin
  owner to decide the fix (add a `$limit`-accepting entry point on Scheduler, or
  have the CLI command just call `run_verification()` and drop `--limit`) —
  not touched here.

## Tests

Both `WeeklySyncVerifierTest.php` and `WeeklySyncVerifierExtendedTest.php` are,
after steps 1–4 moved everything else out, **entirely** Scheduler-domain tests
already (settings, `VERIFICATION_LOCK`, `run_verification()` early-return paths
and happy path, schedule/unschedule, `calculate_next_run_time`). Both are
deleted and merged into one new `WeeklyVerificationSchedulerTest.php`, repointed
at the new class — same one-class-one-test-file convention as steps 1–4.

`AdminAjaxForceSyncTest.php`'s coverage of `schedule_async_verification()` /
`cancel_async_verification()` (via the real admin-ajax call path, not stubs)
keeps working unchanged — it calls through the facade, whose wrapper still
delegates.

No coverage exists today for `process_verification_batch()`,
`finalize_async_verification()`, or `auto_correct_discrepancies()` at the
implementation level (only indirectly, if at all) — a pre-existing gap, not
introduced by this move. Not backfilling it as part of a pure extraction pass.
