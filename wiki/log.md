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


## 2026-10-26: False-Positive Remediation & Multi-Hosting DevOps Test Matrix

### Summary
Engineered core fixes for field false positives identified during production audits on IONOS and HostGator, and established an extensive multi-hosting Docker matrix testing environment simulating top shared and dedicated hosting providers.

### False-Positive Fixes Applied to `PVSA_Engine`
1. **Scheduled Maintenance Detection in `check_https_home()`**:
   - Detects `wp_is_maintenance_mode()` and `.maintenance` files during HTTP 503 responses.
   - Automatically converts transient update-window errors from `fail` to `warn`, avoiding false downtime alarms.
   - Filterable via `pvsa_is_maintenance_mode`.
2. **Active Plugin Schema Auto-Discovery in `check_orphaned_tables()`**:
   - Implemented `get_active_plugin_table_prefixes()` detecting 9 major plugin ecosystems: Action Scheduler (`actionscheduler_`), WooCommerce (`woocommerce_`, `wc_`), HivePress (`hp_`), Yoast SEO (`yoast_`), Rank Math (`rank_math_`), Wordfence (`wf`), Redirection (`redirection_`), WPForms (`wpforms_`), and Elementor (`e_`).
   - Extended `pvsa_known_tables` to support wildcard patterns (`*`), base names without prefix, and added `pvsa_known_table_prefixes` filter.
   - Eliminates false warnings on sites running standard multi-table plugins.
3. **Plugin Description & Readme Update**:
   - Updated plugin metadata in `pressvitals-site-auditor.php` and `readme.txt` to highlight battle-tested verification across IONOS Cloud (WAAS) and HostGator (cPanel).

### Multi-Hosting Docker Matrix (`.docker/docker-compose.hosting.yml`)
Generated complete infrastructure configurations covering:
- **Shared Stacks**: cPanel Classic (`Apache 2.4 + PHP 8.2 + MySQL 8.0`), High-Performance LEMP (`Nginx + PHP-FPM 8.2 + MariaDB 10.11`), and OpenLiteSpeed (`LSPHP 8.1 + MariaDB 10.6`).
- **Dedicated Stacks**: LAMP Dedicated (`Apache 2.4 + PHP 8.3 + MySQL 8.0`), LEMP Dedicated (`Nginx + PHP-FPM 8.3 + MariaDB 11.4`), and WordPress Optimized (`Nginx + PHP-FPM 8.2 + MariaDB 10.11 + Redis 7.2`).
- **Community Stacks**: `vagkaefer/php-to-cpanel-website` and `wellwelwel/php:8-shared-based`.
- **Test Suite Results**: PHPUnit expanded to 65 tests (146 assertions, 100% pass) and PHPCS 100% clean.


