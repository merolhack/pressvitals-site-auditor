# Architecture & Core Diagnostic Engine

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. Core Architecture & Philosophy

PressVitals Site Auditor is designed with three core architectural pillars:

- **Headless-First**: The plugin focuses on headless, scheduled execution via `wp-cron`. It generates a JSON report that can be exported or queried remotely via a token-gated REST API endpoint (`/wp-json/pressvitals/v1/report`).
- **Probe Registry**: Probes are defined in `includes/class-pvsa-engine.php` and managed via a registry hook (`pvsa_registered_checks`). Custom probes or external integrations register callbacks through this filter.
- **Check Anatomy**: Each probe callback returns an associative array with:
  - `status`: `'pass'`, `'warn'`, or `'fail'`
  - `detail`: A localized, human-readable description explaining the result.
  The engine automatically measures and appends `duration_ms` (execution runtime in milliseconds) and `tier` (`core`, `extended`, `deep`) to each check result.

---

## 2. Built-in Probes (50 Total as of v1.5.0)

Probes are categorized into 8 functional diagnostic groups:

### 1. Availability (3 Probes)
- `db_connection`: Validates direct database connectivity via `$wpdb`.
- `https_home`: Ensures the home and site URL options enforce HTTPS.
- **`maintenance_mode_stuck`** *(New in v1.5.0)*: Audits presence of `.maintenance` file in WordPress root and flags persistent outages exceeding 10 minutes.

### 2. Security (19 Probes)
- `debug_display_off`: Verifies `WP_DEBUG_DISPLAY` is disabled in production.
- `env_file_exposed`: Tests whether `.env` or sensitive config files are publicly accessible via HTTP.
- `stray_files`: Scans for stray backup or dump files (e.g. `.sql`, `.zip`, `.tar.gz`, `phpinfo.php`).
- `ssl_cert_expiry`: Inspects the site's SSL certificate validity and warns if expiration is within 30 days.
- `security_headers`: Evaluates presence of standard HTTP security headers (HSTS, CSP, X-Frame-Options, etc.).
- `https_forced`: Checks whether HTTP traffic is automatically redirected to HTTPS.
- `xmlrpc_status`: Audits whether XML-RPC interface is active and recommends disabling if unused.
- `admin_username`: Checks whether default `admin` username exists with administrator privileges.
- `https_mixed_content`: Scans home page assets for insecure `http://` links.
- `env_file_on_disk`: Verifies whether `.env` files reside on disk in web root.
- `wp_config_permissions`: Audits file permissions on `wp-config.php`.
- `user_enumeration`: Checks whether author query string (`/?author=1`) exposes usernames.
- `secret_keys_defined`: Verifies all standard WordPress salt and secret keys are defined and non-default.
- `file_editing_disabled`: Ensures `DISALLOW_FILE_EDIT` is defined as `true`.
- `directory_listing_off`: Validates web server prevents open directory indexing.
- `force_ssl_admin`: Checks whether `FORCE_SSL_ADMIN` is enabled.
- `debug_log_not_public`: Verifies `wp-content/debug.log` cannot be read directly via HTTP request.
- **`db_prefix_customized`** *(New in v1.5.0)*: Audits table prefix against default "wp_" to harden against automated blind SQL injection attacks.
- **`uploads_php_execution`** *(New in v1.5.0)*: Verifies PHP script execution is blocked inside `wp-content/uploads` via .htaccess or web server rule.

### 3. Errors
- `error_log_size`: Inspects PHP error log file size and warns if bloated.
- `php_fatal_errors_recent`: Scans recent PHP error log entries for fatal errors in the past 24 hours.

### 4. Database (13 Probes)
- `autoloaded_options_size`: Measures total byte weight of autoloaded options in `wp_options`.
- `db_overhead`: Checks for database table fragmentation overhead across tables.
- `core_tables_present`: Confirms all standard WordPress core tables exist with expected prefix.
- `orphaned_tables`: Identifies database tables not associated with active core, plugins, or themes.
- `table_storage_engine`: Audits storage engine consistency (InnoDB vs MyISAM).
- `table_collation`: Validates collation and charset standards (`utf8mb4`).
- `largest_tables`: Identifies top 5 heaviest database tables by row count and data/index size.
- `db_charset_client`: Audits client connection charset alignment with MySQL server.
- `expired_transients`: Counts and flags orphaned/expired transients accumulating in `wp_options`.
- `db_index_health`: Analyzes missing or redundant indexes on critical post and meta tables.
- `postmeta_orphans`: Identifies orphaned rows in `wp_postmeta` pointing to deleted posts.
- `heavy_autoloaded_options`: Identifies the top individual heaviest options causing autoload bloat.
- `revision_and_trash_bloat`: Measures accumulated post revisions, auto-drafts, and trashed content.

### 5. Files
- `disk_free`: Measures available disk space on the WordPress server filesystem.
- `uploads_writable`: Confirms `wp-content/uploads` directory is writable by web server.
- `backup_recency`: Checks for presence of recent backup archives or snapshots.

### 6. Email
- `email_dns`: Verifies DNS records for outgoing email delivery (SPF, DKIM, DMARC alignment).

### 7. SEO
- `homepage_indexable`: Checks whether search engines are discouraged (`blog_public` option) or `noindex` headers are present.

### 8. Performance (8 Probes)
- `memory_limit`: Inspects PHP `memory_limit` against recommended thresholds.
- `object_cache`: Verifies whether a persistent object cache backend (Redis, Memcached) is active.
- `cron_overdue`: Identifies overdue scheduled WP-Cron tasks.
- `transient_api_backed`: Checks whether Transient API uses persistent caching or falls back to `wp_options`.
- `php_execution_limits`: Checks `max_execution_time` and `max_input_vars`.
- `cron_loopback_health`: Tests loopback HTTP connection required for `wp-cron.php` spawn.
- `opcache_status`: Checks PHP OPcache extension status, memory consumption, and hit rate.
- **`development_mode_off`** *(New in v1.5.0)*: Audits WP_DEVELOPMENT_MODE to verify block template, theme.json, and translation caches are active in production.

### 9. Environment (7 Probes)
- `php_version`: Evaluates current PHP runtime version against supported WordPress minimums.
- `rest_api_reachable`: Verifies the WordPress REST API root endpoint is reachable.
- `core_update_available`: Checks if WordPress core updates are pending.
- `plugin_updates_pending`: Audits pending plugin updates.
- `theme_updates_pending`: Audits pending theme updates.
- `inactive_plugins_themes`: Detects disabled plugins and unused themes cluttering the filesystem.
- **`env_type_production`** *(New in v1.5.0)*: Validates WP_ENVIRONMENT_TYPE is set to production to avoid staging behavior and debug disclosures.
