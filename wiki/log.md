# PressVitals Site Auditor — Change Log & Development Journal

## 2026-10-26: Release 1.5.4 — Differential PHP Runtime Diagnosis (Web vs CLI/WP-Cron)

### Summary
Resolved runtime version divergence between Web/FPM servers and background CLI/WP-Cron environments. On managed and shared hosting platforms like IONOS Cloud, cPanel, and SiteGround, system CLI default binaries often point to legacy OS PHP versions (e.g., PHP 8.0.30) while the web server runs modern, supported PHP (e.g., PHP 8.3.36). Previously, WP-Cron scheduled checks triggered `[FAIL] Supported PHP version — PHP 8.0.30 is end-of-life`, causing administrative confusion and false alarm alerts.

### Architectural Additions in `PVSA_Engine`
1. **`is_cli_or_cron()`**:
   - Detects background execution via `php_sapi_name() === 'cli'`, `wp_doing_cron()`, and `DOING_CRON`.
   - Filterable via `pvsa_is_cli_or_cron`.
2. **`record_web_php_version()`**:
   - Automatically writes current `PHP_VERSION` into transient `pvsa_web_php_version` (TTL: 7 days) during standard web request lifecycle (admin, REST, frontend).
   - Skips writing when in background context or when cached value already matches.
3. **`get_detected_web_php_version()`**:
   - Reads `pvsa_web_php_version` transient.
   - Falls back to non-blocking HTTP HEAD request to `home_url('/')` inspecting `X-Powered-By` header when in CLI/Cron context without cached transient.
   - Filterable via `pvsa_detected_web_php_version`.
4. **Intelligent Differential Diagnosis in `check_php_version()`**:
   - Distinguishes between Web and CLI states when running in background with mismatched runtimes.
   - Emits `warn` (instead of critical failure) when Web PHP is modern and supported but CLI is EOL, instructing the administrator to update their crontab binary path.
   - Preserves critical `fail` when Web PHP itself is EOL or when both runtimes are EOL.

### Testing & Validation
- Added 5 new unit tests in `tests/test-engine.php` (total: 61 tests, 141 assertions, 100% pass).
- Full compliance with WordPress Coding Standards (PHPCS: 0 errors, 0 warnings).
- Multi-container verification across WordPress 6.0 through 7.1.
