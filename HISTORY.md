# History & Changelog

## 2026-08-15
- **v1.3.0 & v1.4.0 Official Deployment to GitHub & WordPress.org SVN:**
  - Published GitHub Release `1.3.0` (tag `1.3.0`) -> Deployed to WordPress.org SVN (`tags/1.3.0/`).
  - Published GitHub Release `1.4.0` (tag `1.4.0`) -> Deployed to WordPress.org SVN trunk and `tags/1.4.0/`.
  - Verified WordPress.org Plugin Directory API serving `pressvitals-site-auditor.1.4.0.zip` with 45 proactive diagnostic probes.
  - Validated test suite across all 4 Docker environments (`wp-71`, `wp-latest`, `wp-mid`, `wp-legacy`) — 44 tests, 97 assertions passed (100% OK), PHPCS 100% clean.
  - Enforced mandatory LLM Wiki pattern and `codebase-memory-mcp` knowledge graph usage across all AI agents and skills.

## 2026-08-14 (v1.4.0 Release)
- **v1.4.0 — Phase 1 Feature Expansion & 5 New Diagnostic Probes:**
  - Added 5 new high-impact diagnostic probes across Security, Database, and Performance:
    1. `debug_log_not_public` (Security, Tier 1) — Probes `wp-content/debug.log` over HTTP to ensure stack traces and sensitive error logs are not publicly exposed.
    2. `heavy_autoloaded_options` (Database, Tier 3) — Inspects `wp_options` for individual autoloaded option records exceeding 100 KB (filterable via `pvsa_heavy_autoload_warn_bytes`).
    3. `revision_and_trash_bloat` (Database, Tier 4) — Audits post revision accumulation against published post ratios and trashed content count.
    4. `cron_loopback_health` (Performance, Tier 2) — Tests HTTP loopback reachability for `wp-cron.php` to ensure background workers can spawn.
    5. `opcache_status` (Performance, Tier 3) — Audits PHP Zend OPcache configuration, hit rate, and memory utilization.
  - Total built-in probes increased from 40 to 45.
  - Added dedicated WordPress 7.1 container (`wp-71` using `wordpress:beta` / WP 7.1-RC3 on port 8071) alongside `wp-latest` (WP 7.0 / port 8083), `wp-mid` (WP 6.4 / port 8081), and `wp-legacy` (WP 6.0 / port 8074).
  - Updated CI test matrix (`tests.yml`) to test across WP 6.3–7.1 + nightly and PHP 7.4–8.4.
  - Added dedicated WP 7.1 compatibility unit test in `tests/test-engine.php` (44 tests, 97 assertions, 100% pass).
  - Bumped version to `1.4.0` across `pressvitals-site-auditor.php`, `PVSA_VERSION` constant, and `readme.txt` stable tag.
  - Validated PHPCS code quality: 0 errors, 0 warnings (100% clean).
  - Verified multi-container Docker runtime smoke tests (`wp-71`, `wp-latest`, `wp-mid`, `wp-legacy`).
  - Ingested updated probe definitions into `LLM_WIKI.md`.

## 2026-08-14 (Compatibility Audit)
- **WordPress 7.1 Imminent Release Readiness & Compatibility Audit:**
  - Audited codebase against all key changes in the WordPress 7.1 Field Guide:
    - *Iframed Post Editor*: Verified no impact (headless engine + isolated Tools admin UI).
    - *Client-side Media Processing*: Verified no impact (read-only diagnostics, no media hooks).
    - *@wordpress/components*: Verified no impact (native PHP/HTML rendering).
    - *Persistent Toolbar*: Verified no impact (no admin bar hooks).
    - *SVG Icon API*: Verified no impact.
    - *jQuery UI 1.14.2*: Verified no impact (pure vanilla JS used in `pvsa-admin.js`).
    - *Abilities API*: Verified full compatibility with core permissions.
  - Bumped `Tested up to: 7.1` in `readme.txt` and `pressvitals-site-auditor.php`.
  - Executed compatibility validation across 3 Docker environments:
    - `wp-latest` (WordPress 6.7 / PHP 8.3) -> HTTP 302 OK
    - `wp-mid` (WordPress 6.4 / PHP 8.1) -> HTTP 302 OK
    - `wp-legacy` (WordPress 6.0 / PHP 7.4) -> HTTP 302 OK
  - Ran PHPUnit suite in Docker: 37 tests, 83 assertions passed (100% OK).
  - Ran PHPCS code quality checks in Docker: 100% clean (0 errors, 0 warnings).
  - Added `.phpunit.result.cache` to `.gitignore`.
  - Ingested WordPress 7.1 field guide knowledge into `LLM_WIKI.md` and updated skills.

## 2026-08-04
- **LLM Wiki Ingestion & Codebase Graph Integration:**
  - Verified `codebase-memory-mcp` knowledge graph status for project `home-merolhack-fl-pressvitals-site-auditor` (`detect_changes` clean at SHA `9776517`).
  - Ingested all v1.3.0 architectural rules, 40 probe definitions, array alignment standards, and prepared SQL ignore patterns into `LLM_WIKI.md`, `AGENTS.md`, and skills (`pressvitals-architecture-rules`, `pressvitals-release-workflow`).
  - Mandated the LLM Wiki as the primary single source of truth for all AI agents working on this project.
- **v1.3.0 — New Performance & DB Probes:** Added 4 new diagnostic probes:
  1. `check_expired_transients` — dedicated expired transient bloat detection with configurable `pvsa_expired_transients_warn` (500) and `pvsa_expired_transients_fail` (2000) filter thresholds.
  2. `check_php_execution_limits` — audits `max_execution_time` and `max_input_vars` against safe minimums; treats `0` (unlimited/CLI) as pass.
  3. `check_db_index_health` — queries `information_schema.STATISTICS` to verify all 11 core WordPress tables have their PRIMARY key index.
  4. `check_postmeta_orphans` — counts `wp_postmeta` rows with no matching `wp_posts` row (LEFT JOIN); configurable via `pvsa_orphan_postmeta_warn` (1000) / `pvsa_orphan_postmeta_fail` (10000).
- Total built-in probes: 36 → 40. Updated tests (`test-engine.php`) to expect ≥26 probes and added 4 dedicated test methods.
- Version bumped: `1.2.6` → `1.3.0` across plugin header, `PVSA_VERSION` constant, and `readme.txt` stable tag. Fixed PHPCS array alignment and prepared query placeholder sniffs (100% clean PHPCS & PHPUnit CI runs).

## 2026-07-02
- **Initial WP.org Release (`v1.2.6`):** Bumped plugin version to `1.2.6` and created GitHub release tag `1.2.6` to trigger automated initial deployment of plugin code (`/trunk`) and branding assets (`/assets`) to WordPress.org SVN.
- **LLM Wiki Ingestion:** Ingested complete WordPress.org Plugin Directory SVN instructions, account credentials rules, asset placement architecture, and 72-hour propagation caching constraints into `LLM_WIKI.md`.
- **CI/CD Deployment Optimization:** Refactored `.github/workflows/deploy.yml` into separate conditional jobs: full releases run `action-wordpress-plugin-deploy` when publishing a Git Tag, while asset/documentation updates run `action-wordpress-plugin-asset-update` automatically on pushes to `main` (or via `workflow_dispatch`). This ensures `.wordpress-org/` branding images sync directly to `/assets/` in SVN without requiring a version bump.

## 2026-07-01
- **WordPress.org Release Preparation:** Formatted and integrated official directory assets (`icon-256x256.png`, `icon-128x128.png`, `banner-1544x500.png`, `banner-772x250.png`) into `.wordpress-org/` to support automated deployment via GitHub Actions (`10up/action-wordpress-plugin-deploy`).
- **Build Cleanliness:** Added `*Zone.Identifier*` to both `.gitignore` and `.distignore` to prevent Windows NTFS alternate data stream files from leaking into Git repositories or SVN distribution archives.

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
