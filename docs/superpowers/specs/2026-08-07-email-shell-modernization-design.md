# Email HTML shell modernization — design

Source: `AUDIT_REPORT.md` RENEW items:
- `includes/email-notifications.php:369-667`
- `includes/orphan-cleanup.php:344-434`

## Problem

Both files hand-roll a full HTML email shell (`<!DOCTYPE html>`, inline-styled header band with an accent colour, footer with site name + settings link) instead of using WooCommerce's own themed, filterable `WC_Emails::wrap_message()` header/footer. The shell is duplicated near-identically between the two files.

## Approach

Light-weight reuse of WooCommerce's mailer wrapper, not a full `WC_Email` subclass per notification type (rejected: would require the plugin's existing "Email Settings" admin page to be dropped or duplicated inside WooCommerce → Settings → Emails — out of scope for this pass).

### 1. Shared trait: `includes/email-shell-trait.php`

```php
trait WC_Multi_Store_Email_Shell {
    protected function wrap_email(string $heading, string $badge_label, string $badge_color, string $body_html): string;
}
```

- Renders a small coloured badge/pill (`$badge_label` on `$badge_color` background) and prepends it to `$body_html`.
- Delegates the outer shell to `WC()->mailer()->wrap_message($heading, $badge_pill . $body_html)`.
- `$heading` carries the same text previously shown in the custom header band (e.g. "Product Sync Failed") — WooCommerce's own template renders it as the H1, so per-type differentiation by text is preserved. Per-type differentiation by *colour* moves from the header band to the badge pill in the body (user decision — WC's header/footer colour comes from WooCommerce → Settings → Emails and is shared across all mail).
- Follows the same "shared trait for one small piece of duplicated logic" convention already used by `includes/csv-sanitize-trait.php`.

### 2. `includes/email-notifications.php`

- Remove `email_layout()`.
- `use WC_Multi_Store_Email_Shell;`.
- Each of the 4 `get_*_template()` methods calls `$this->wrap_email($title, $badge, $accent, $lead . $table . ...)` instead of `$this->email_layout(...)`.
- `data_row()` and `action_button()` are unchanged — they only build body content, not shell.

### 3. `includes/orphan-cleanup.php`

- `send_scan_complete_email()` stops hand-building `<!DOCTYPE html>...</html>` inline.
- `use WC_Multi_Store_Email_Shell;`.
- Calls `$this->wrap_email($title, $badge, $accent, $lead . $table . $button)` instead.

### 4. Wiring

- `wc-multi-store-sync.php` and `tests/php/bootstrap.php` add `require_once 'includes/email-shell-trait.php'` (loaded before `email-notifications.php` and `orphan-cleanup.php`).

## Test impact

- `tests/php/Unit/EmailNotificationsRateLimitTest.php` reflection-tests the old `email_layout()` directly (asserts `<!DOCTYPE html>`, exact header hex colours, footer markup). These tests are rewritten to target the new `wrap_email()` trait method instead, with `WC()->mailer()->wrap_message()` mocked in the test (deterministic stub, since we are not testing WooCommerce's own template rendering).
- `tests/php/bootstrap.php` needs a `WC()` stub/mock point added (does not exist yet — grepped, confirmed no existing `WC()`/`WC_Emails`/`wrap_message` usage anywhere in the codebase).
- Content-level assertions (product name, SKU, amounts, store URLs, etc.) in `EmailNotificationsTest.php`, `EmailNotificationsExtendedTest.php`, and `OrphanCleanupTest.php` are unaffected — the body content builders (`data_row`, `action_button`, table/lead paragraph construction) are untouched.
- Run `composer test` after each file change, per `AUDIT_REPORT.md`'s own instruction.

## Out of scope

- Turning notifications into full `WC_Email` subclasses registered via `woocommerce_email_classes` (would surface them in WooCommerce → Settings → Emails and let themes override templates, but conflicts with the plugin's own "Email Settings" page — rejected by user for this pass).
- Any change to *when* emails are sent, rate limiting, or recipient/settings logic.
