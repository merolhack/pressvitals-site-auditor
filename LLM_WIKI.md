# PressVitals Site Auditor — LLM Wiki

This document serves as the project's **LLM Wiki** (per [Karpathy's LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f)). It contains essential context, architectural decisions, testing paradigms, probe definitions, and release workflows.

> [!IMPORTANT]
> **MANDATORY FOR ALL AI AGENTS:** AI agents interacting with this repository **MUST** read and consult this LLM Wiki (`LLM_WIKI.md`, `index.md`, `AGENTS.md`, and `HISTORY.md`) before performing research, making architectural decisions, or modifying code.

---

## 1. Core Architecture & Philosophy
- **Headless-First:** The plugin focuses on headless, scheduled execution via `wp-cron`. It generates a JSON report that can be exported or queried via a token-gated REST API endpoint (`/wp-json/pressvitals/v1/report`).
- **Probe Registry:** Probes are defined in `includes/class-pvsa-engine.php` and managed via a registry hook (`pvsa_registered_checks`). 
- **Check Anatomy:** Each probe callback must return an array with `status` (`pass`, `warn`, `fail`) and `detail` (a localized human-readable string). The engine automatically appends `duration_ms` and `tier` to each executed check.

### Built-in Probes (45 Total as of v1.4.0)
Probes are categorized into 8 functional groups:
- **Availability**: `db_connection`, `https_home`
- **Security**: `debug_display_off`, `env_file_exposed`, `stray_files`, `ssl_cert_expiry`, `security_headers`, `https_forced`, `xmlrpc_status`, `admin_username`, `https_mixed_content`, `env_file_on_disk`, `wp_config_permissions`, `user_enumeration`, `secret_keys_defined`, `file_editing_disabled`, `directory_listing_off`, `force_ssl_admin`, **`debug_log_not_public`** (v1.4.0)
- **Errors**: `error_log_size`, `php_fatal_errors_recent`
- **Database**: `autoloaded_options_size`, `db_overhead`, `core_tables_present`, `orphaned_tables`, `table_storage_engine`, `table_collation`, `largest_tables`, `db_charset_client`, `expired_transients`, `db_index_health`, `postmeta_orphans`, **`heavy_autoloaded_options`** (v1.4.0), **`revision_and_trash_bloat`** (v1.4.0)
- **Files**: `disk_free`, `uploads_writable`, `backup_recency`
- **Email**: `email_dns`
- **SEO**: `homepage_indexable`
- **Performance**: `memory_limit`, `object_cache`, `cron_overdue`, `transient_api_backed`, `php_execution_limits`, **`cron_loopback_health`** (v1.4.0), **`opcache_status`** (v1.4.0)
- **Environment**: `php_version`, `rest_api_reachable`, `core_update_available`, `plugin_updates_pending`, `theme_updates_pending`, `inactive_plugins_themes`

---

## 2. Codebase Knowledge Graph (`codebase-memory-mcp`)
This project integrates `codebase-memory-mcp` for structural codebase queries.
> [!IMPORTANT]
> **Code Discovery Rule:** BEFORE reading large files or performing `grep` searches across the repository, agents **MUST** query the knowledge graph first using MCP tools:
> 1. `search_graph` — Find functions, classes, routes by name pattern (e.g. `search_graph(project="home-merolhack-fl-pressvitals-site-auditor", name_pattern=".*check_.*")`).
> 2. `trace_path` — Trace caller/callee relationships.
> 3. `get_code_snippet` — Fetch exact source code for a symbol by `qualified_name`.
> 4. `query_graph` — Run Cypher queries for complex structural dependencies.

---

## 3. LLM-Wiki Architecture Integration
This repository fully implements the [LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) to provide a compiled knowledge layer for agents:
- **`index.md`**: Content-oriented catalog indexing the codebase and wiki.
- **`HISTORY.md`**: Serves as the chronological `log.md`. Always append new changes, bug discoveries, and design shifts here.
- **`AGENTS.md`**: Serves as the schema document. Defines rules, boundaries, and expected workflows for agents operating in this workspace.

---

## 4. i18n, Localization & PHPCS Formatting (CRITICAL)
- **WP.org Compliance:** The WordPress Plugin Check (PCP) scanner and PHPCS are extremely strict.
- You MUST use `__()` or `esc_html__()` with the text domain `'pressvitals-site-auditor'`.
- If using `sprintf()` with placeholders, you **MUST** include a `/* translators: ... */` comment **exactly** on the line preceding the string definition.
- **Array Alignment:** Double arrows (`=>`) in `register_core_checks()` must align across all keys (e.g., matching length of `'inactive_plugins_themes'` at 23 chars).
- **SQL Prepare Placeholders:** When using dynamic `$placeholders` inside `$wpdb->prepare()`, wrap the query with `// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` and `// phpcs:enable ...` to prevent PHPCS false positives.
- **Pipeline:** `.pot` files are generated via `composer make-pot` (`wp i18n make-pot . languages/pressvitals-site-auditor.pot`).

---

## 5. Environment & WSL Constraints
- **Docker Executions:** Running composer or PHPCS directly in WSL can fail due to environment variances. Use the Docker container:
  ```bash
  docker compose exec wp-latest vendor/bin/phpunit
  docker compose exec wp-latest vendor/bin/phpcs
  ```
- **Git Push Authentication:** The environment frequently hangs on interactive authentication prompts. Use the explicit PAT URL when pushing:
  ```bash
  git push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git
  ```

---

## 6. Testing & CI Pipeline
- **PHPCS:** We enforce `WordPress-Core` coding standards. Run `docker compose exec wp-latest vendor/bin/phpcs` to verify 0 errors and 0 warnings before committing.
- **Unit Testing (PHPUnit):** Tests live in `tests/test-engine.php`. Run `docker compose exec wp-latest vendor/bin/phpunit`.
  - Do **NOT** rely on external network calls for probes like `check_security_headers` or `check_stray_files`. Use the WP Core hook `pre_http_request` to mock HTTP responses.
- **CI Workflows:** `.github/workflows/` contains three distinct workflows:
  1. `tests.yml`: Runs PHPUnit tests across WP versions 6.3 – latest.
  2. `code-quality.yml`: Runs PHPCS and official `wordpress/plugin-check-action@v1`.
  3. `deploy.yml`: Pushes tags to WordPress.org SVN on release.

---

## 7. WordPress.org SVN & Repository Information
- **SVN Repository URL:** `https://plugins.svn.wordpress.org/pressvitals-site-auditor`
- **Public Directory URL:** `https://wordpress.org/plugins/pressvitals-site-auditor`
- **SVN Account & Credentials:** Username `merolhack` (case-sensitive). SVN Password configured in GitHub Repository Secrets (`SVN_USERNAME` and `SVN_PASSWORD`).
- **Deployed SVN Tags:**
  - `tags/1.2.6/` (Initial release)
  - `tags/1.3.0/` (40 probes, performance & index audits)
  - `tags/1.4.0/` (45 probes, WP 7.1 readiness, OPcache, debug.log audit)
- **Directory Assets:** Banners and icons live in `.wordpress-org/` in Git and map to `/assets/` in SVN. Note 72-hour CDN propagation delay for directory images.

---

## 8. WordPress Compatibility & Field Guide Audits

### WordPress 7.1 Compatibility Audit (Verified 2026-08-14)
The plugin has been fully audited against the WordPress 7.1 Field Guide changes:
- **Iframed Post Editor**: No impact. PVSA is a headless-first diagnostic tool with an independent admin dashboard (`Tools -> PressVitals Site Auditor`), zero post editor canvas hooks, and no Gutenberg blocks.
- **Client-Side Media Processing**: No impact. PVSA performs read-only filesystem and diagnostic checks, without intercepting media uploads or image workflows.
- **@wordpress/components Updates**: No impact. Admin UI is rendered server-side in PHP with vanilla CSS/JS.
- **Persistent Toolbar**: No impact. PVSA does not add custom nodes or modify the admin toolbar.
- **Public SVG Icon API**: No impact. PVSA iconography is self-contained.
- **jQuery UI 1.14.2 Upgrade**: No impact. PVSA uses vanilla JavaScript (`pvsa-admin.js`) without jQuery or jQuery UI dependencies.
- **Abilities API Improvements**: Fully compatible with standard capability checks (`manage_options`).
- **Compatibility Status**: `Tested up to: 7.1` confirmed across plugin headers and `readme.txt`. Verified 100% pass across local multi-version Docker containers (`wp-71` on 8071, `wp-latest` on 8083, `wp-mid` on 8081, `wp-legacy` on 8074) and PHPUnit test suite.

---

## 9. Release Workflow & Automated Deployment
1. Synchronize versions across `pressvitals-site-auditor.php` (`Version:` header + `PVSA_VERSION` constant) and `readme.txt` (`Stable tag:`).
2. Update `readme.txt` changelog, `HISTORY.md`, and `LLM_WIKI.md`.
3. Generate the distribution ZIP using `rsync` and Python `shutil.make_archive` honoring `.distignore`.
4. Run local Docker PHPUnit (`docker compose exec wp-71 vendor/bin/phpunit`) and PHPCS checks (`docker compose exec wp-latest vendor/bin/phpcs` — 0 errors/0 warnings required).
5. Verify container health across all 4 environments (`wp-71`, `wp-latest`, `wp-mid`, `wp-legacy`).
6. Commit changes to Git (`git add . && git commit -m "..."`).
7. Push commits and tag to GitHub (`git push <PAT_URL> main` and `git push <PAT_URL> <version>`).
8. **Publish GitHub Release:** Create and publish a GitHub Release matching the version tag (e.g. `gh release create 1.4.0 --title "1.4.0" --notes "..."`). This triggers the `deploy` job in `.github/workflows/deploy.yml` (`10up/action-wordpress-plugin-deploy`), which pushes the codebase to `/trunk` and creates `/tags/<version>` on WordPress.org SVN.
9. **Verify SVN & WP.org API:** Check `curl -s https://plugins.svn.wordpress.org/pressvitals-site-auditor/tags/` and `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=pressvitals-site-auditor` to confirm the new version is live.
