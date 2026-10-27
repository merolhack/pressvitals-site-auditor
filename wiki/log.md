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


## 2026-10-26: Production Audit & Field Inspection (dqro.mx & freelancemexico.com.mx)

### Summary
Conducted end-to-end live field audit of PressVitals Site Auditor v1.5.4 across two distinct hosting architectures:
1. **IONOS Cloud WAAS (`https://dqro.mx`)**:
   - Total probes: 60 (Verdict: `WARN`, 55 PASS, 5 WARN, 0 FAIL).
   - Validated that the runtime decoupling architecture eliminated the crontab false positive `[FAIL]`.
   - Identified 5 legitimate operational warnings: `env_file_on_disk`, `object_cache` (no Redis/Memcached), `password_hashes_modern` (1 legacy hash), `php_hardening` (`expose_php` enabled), and `php_version` (preventive EOL notice for PHP 8.3 nearing Dec 2026).
2. **HostGator Shared cPanel (`https://freelancemexico.com.mx`)**:
   - Total probes: 60 (Verdict: `WARN` / transient `FAIL` during automated plugin update, 49 PASS, 10 WARN, 1 transient FAIL).
   - Diagnosed transient `https_home` HTTP 503 caused by `.maintenance` file written during a background plugin update.
   - Identified and analyzed the `orphaned_tables` warning (93 tables) as a manageable false positive resulting from HivePress, WooCommerce, and Action Scheduler custom schemas. Documented the `pvsa_known_tables` filter solution.
   - Verified that other warnings (`db_prefix_customized`, `table_collation`, `table_storage_engine`, `php_hardening`) are true positives reflecting shared hosting defaults and historical migrations.

