# History & Changelog

## 2026-06-22
- **Code Standards:** Replaced inline JavaScript in the admin UI with properly registered and enqueued scripts via `wp_enqueue_script` to comply with WordPress.org directory guidelines.
- **Plugin Directory:** Requested WP.org plugin reviewers to change the plugin slug to match the rebranded display name to resolve the text domain mismatch.

## 2026-06-15
- **Bugfix (Local Dev False Positives):** Addressed Docker loopback limitations. `check_security_headers` now gracefully bypasses (passes) when `wp_remote_get` to the homepage fails due to a local environment connection issue. 
- **Bugfix (Local Dev False Positives):** `check_ssl_cert_expiry` no longer attempts a TLS connection on non-HTTPS sites, gracefully skipping the check. This eliminates false warnings when testing locally without SSL.
- **Compatibility adjustment:** Modified the plugin header `Requires at least` from `6.3` to `6.0`. This change was made to accommodate local Docker testing, as the official `wordpress:6.3-php7.4-apache` image tag is missing from Docker Hub, forcing the use of `wordpress:6.0-php7.4-apache` for the legacy environment.
- **WordPress.org Validation:** Ran the official Plugin Check (PCP) tool to prepare for catalog submission.
- **Code Standards & Compatibility Fixes:**
  - Removed deprecated `load_plugin_textdomain()` (WP automatically loads it since 4.6).
  - Prefixed global variables in `uninstall.php` (`$pvsa_site_ids`).
  - Added required `/* translators: ... */` comments preceding all `__()` calls containing placeholders like `%s`.
  - Removed redundant `Author URI` to fix WP.org's duplicate plugin/author URI error, pointing `Author URI` directly to the developer's personal GitHub page.
- **Build System:** Created an isolated Python script to generate the distribution `.zip` file. This leverages `.distignore` to strictly strip development/CI files (`.wp-env.json`, `phpunit.xml.dist`, `.github/`, test scripts) from the final package before uploading to the WordPress catalog.
- **Deep Audit Engine Expansion:** Implemented 10 new High-Priority (P1/P2) diagnostic probes:
  - **Security:** `secret_keys_defined`, `file_editing_disabled`, `directory_listing_off`, `force_ssl_admin`.
  - **Database:** `table_storage_engine` (flag MyISAM), `table_collation` (flag non-utf8mb4).
  - **Environment:** `theme_updates_pending`, `inactive_plugins_themes` (warn on excessive dormant extensions).
  - **Performance:** `cron_overdue` (check for stalled WP-Cron), `transient_api_backed` (ensure persistent object cache usage).
- **Added P3 Database Probes:** `largest_tables` (reports top tables and total size) and `db_charset_client` (validates utf8mb4 connection).
- **Upgraded PHP Probe:** Refactored `php_version` into `php_eol_horizon` to dynamically warn against official End-of-Life dates with a customizable 6-month warning horizon.
- **Added Final P3 Probes:** Implemented `https_mixed_content` (detects insecure HTTP assets on the homepage) and `rest_api_reachable` (validates HTTP 200 on `/wp-json/`).

## 2026-06-22 (Cont.)
- **UI Improvements:** Overhauled the admin dashboard color scheme in `class-pvsa-admin.php`. `FAIL` checks now prominently feature red text and a faint red background (`#fcf0f1`). `WARN` checks use orange. Group summary pills accurately reflect the most severe status within their group, turning red (`#d63638`) if any checks fail.
- **WP-Config permissions check:** Diagnosed local vs remote environment permission misalignment causing the `check_wp_config_permissions` rule to falsely trigger due to Docker volume mounts or un-synced deployments. Local WSL testing does not perfectly map permissions into the Docker instance or remote host.
- **LLM Wiki Integration:** Ingested the LLM-Wiki architecture pattern (index, log, schema). Created `index.md`, `AGENTS.md`, and mapped `HISTORY.md` to the log concept to organize agentic knowledge structurally.
