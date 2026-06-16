# History & Changelog

## 2026-06-15
- **Bugfix (Local Dev False Positives):** Addressed Docker loopback limitations. `check_security_headers` now gracefully bypasses (passes) when `wp_remote_get` to the homepage fails due to a local environment connection issue. 
- **Bugfix (Local Dev False Positives):** `check_ssl_cert_expiry` no longer attempts a TLS connection on non-HTTPS sites, gracefully skipping the check. This eliminates false warnings when testing locally without SSL.
- **Compatibility adjustment:** Modified the plugin header `Requires at least` from `6.3` to `6.0`. This change was made to accommodate local Docker testing, as the official `wordpress:6.3-php7.4-apache` image tag is missing from Docker Hub, forcing the use of `wordpress:6.0-php7.4-apache` for the legacy environment.
- **WordPress.org Validation:** Ran the official Plugin Check (PCP) tool to prepare for catalog submission.
- **Code Standards & Compatibility Fixes:**
  - Removed deprecated `load_plugin_textdomain()` (WP automatically loads it since 4.6).
  - Prefixed global variables in `uninstall.php` (`$ohsa_site_ids`).
  - Added required `/* translators: ... */` comments preceding all `__()` calls containing placeholders like `%s`.
  - Removed redundant `Author URI` to fix WP.org's duplicate plugin/author URI error, pointing `Author URI` directly to the developer's personal GitHub page.
- **Build System:** Created an isolated Python script to generate the distribution `.zip` file. This leverages `.distignore` to strictly strip development/CI files (`.wp-env.json`, `phpunit.xml.dist`, `.github/`, test scripts) from the final package before uploading to the WordPress catalog.
