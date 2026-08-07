# Logger consolidation to wc_get_logger() — design

Source: `AUDIT_REPORT.md` RENEW item `includes/logger.php:276-395`.

## Problem

`WC_Multi_Store_Logger::do_log()` fans every log line out to three destinations: a hand-rolled file (own buffering, size/time rotation, backup files, and a database-history archival trigger), `wc_get_logger()` (WooCommerce's own logger), and `error_log()` (only when `WP_DEBUG` is on and level is ERROR). The audit flags this as outdated vs. the WC standard of a single `wc_get_logger()` sink, but full consolidation is not mechanical — the own file backs a real, actively-used admin "Logs" tab (filter/search, warnings-only panel, clear buttons) and the DB-history archival job is (oddly) triggered by the file's own time-based rotation.

## Decisions (made with plugin owner)

1. **Canonical sink: `wc_get_logger()` only.** Own file writing, buffering, size/time rotation, and the `error_log()` branch are all removed.
2. **Logs admin tab is kept**, repointed to read WooCommerce's own log file instead of being removed in favor of WooCommerce → Status → Logs.
3. **DB-history archival becomes an independent recurring Action Scheduler job**, decoupled from log rotation entirely (rotation_days becomes purely the archival cutoff).
4. **`max_log_size` / `max_backup_files` (and `get_rotation_settings()`, both DEFAULT_* constants) are removed entirely** — dead once there's no more size-based rotation of an own file; nothing in admin UI ever exposed them.

## Changes

### `includes/logger.php`

- Constructor: no longer creates `wc-mss-logs/` directory, `.htaccess`, `nginx.conf`, `index.php`, or sets `$log_file` to an own path. Instead `$log_file` (rename candidate: keep the name, just repoint) becomes `WC_Log_Handler_File::get_log_file_path('wc-multi-store-sync')` — the same handle string already passed as `['source' => 'wc-multi-store-sync']` today.
- Remove: `$buffer`, `$buffer_size`, `write_to_file()`, `flush_buffer()`, `__destruct()`, `should_rotate_by_time()`, `rotate_log()`, `cleanup_old_logs()`, `max_log_size`, `max_backup_files`, `DEFAULT_MAX_LOG_SIZE`, `DEFAULT_MAX_BACKUP_FILES`, `get_rotation_settings()`.
- `load_rotation_settings()` shrinks to loading only `rotation_days` (still overridable via `WC_MSS_ROTATION_DAYS` constant, still clamped 1–30) — it now exists purely to configure the archival cutoff, not file rotation.
- `do_log()`: drop the file-write and `error_log()` branches. Keep only `wc_get_logger()?->log($level, $message, $context)`. **Bug fix included**: the real PSR-3 `$context` array (currently JSON-stringified only for the now-removed file line) is passed through to `wc_get_logger()` merged with `['source' => 'wc-multi-store-sync']` — today it never reaches WC's logger at all, only `['source' => ...]` does.
- `get_log(int $lines)`: unchanged logic, just reads from the repointed `$log_file`.
- `clear_log()`: use `WC_Log_Handler_File::remove('wc-multi-store-sync')` instead of manual `unlink()`.
- `clear_warnings_and_errors()`: same line-filtering approach, but matches WooCommerce's own line format (`{ISO8601} {LEVEL} {message} CONTEXT: {...}`, no brackets) instead of `[WARNING]`/`[ERROR]`.
- New `schedule_archival(): void`, modeled on `email-notifications.php::schedule_daily_summary()`: if not already scheduled, `as_schedule_recurring_action()` for `wc_mss_archive_history` on a daily interval. Hooked on `init` from `wc-multi-store-sync.php` (alongside the existing `add_action('wc_mss_archive_history', WC_Multi_Store_Logger::archive_database_history(...))` at line 355).
- `archive_database_history()`: body unchanged (still archives to JSON + deletes DB rows using `rotation_days` as cutoff).

### `admin/views/logs.php`

- Client-side JS filter matching `[WARNING]`/`[ERROR]` substrings updates to match WooCommerce's unbracketed level format.
- "About Logs" copy ("automatically rotated when they exceed 10MB") updated to describe WooCommerce's actual (age-based) retention instead.

### `wc-multi-store-sync.php`

- Add `add_action('init', WC_Multi_Store_Logger::schedule_archival(...));` near the existing archival hook wiring.

### Tests

- `tests/php/bootstrap.php`: add a `WC_Log_Handler_File` stub (`get_log_file_path()`, `remove()`) alongside the other WC/WP class stubs.
- `tests/php/Unit/LoggerTest.php` / `LoggerExtendedTest.php`: remove tests for buffer/flush/size-rotation/backup-file cleanup/own-directory-creation/`get_rotation_settings()`. `do_log()` tests switch from reflecting into the private `$buffer` to asserting on a mocked `wc_get_logger()->log()` call (level, message, context). `get_log()`/`clear_log()`/`clear_warnings_and_errors()` tests point at the stubbed WC log file path.

## Out of scope

- Any change to WooCommerce's own log retention/cleanup settings.
- Renaming `rotation_days` / `DEFAULT_ROTATION_DAYS` despite the semantic shift from "log rotation" to "archival cutoff" — avoids unnecessary churn on a name that still reads correctly.
