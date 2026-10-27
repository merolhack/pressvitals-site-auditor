# Deployment Dossier: dqro.mx (IONOS Cloud)

> Part of the [PressVitals Site Auditor LLM Wiki](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/index.md) &rarr; [Live Deployments](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/live-deployments.md).

## 1. Environment & Infrastructure Profile

- **Domain**: `https://dqro.mx`
- **Application**: Spanish-language local business directory for Querétaro, Mexico.
- **Hosting Provider**: **IONOS** Cloud
- **Hosting Platform**: Managed WordPress on WAAS (Web Application as a Service)
- **Control Panel**: IONOS Cloud Panel (`my.ionos.mx`)
- **Web Server**: Nginx Reverse Proxy / FastCGI
- **Active PHP Runtime**: **PHP 8.3.35** (configured with automatic security minor updates in IONOS)
- **Database Server**: MariaDB / MySQL (UTF-8 mb4 standard, customized secure table prefix)
- **Plugin Version Installed**: `1.5.1` (upgrade pending to `1.5.2`)

---

## 2. Comprehensive Audit Probe Results

Audit performed via PressVitals Site Auditor diagnostic engine (`tools.php?page=pressvitals-site-auditor`):

### Summary Breakdown
- **Verdict**: `WARN`
- **Passed Checks**: 52
- **Warning Checks**: 5
- **Failing Checks**: 0

### Functional Group Breakdown

#### Availability (3/3 PASS)
- `db_connection`: ✅ PASS (Direct `$wpdb` query connection operational).
- `https_home`: ✅ PASS (HTTPS enforced on home and site URL).
- `maintenance_mode_stuck`: ✅ PASS (No dangling `.maintenance` file).

#### Security (18/20 PASS, 2 WARN)
- `env_file_exposed`: ✅ PASS (HTTP probe against `/.env` returns 403 Forbidden).
- `env_file_disk`: ⚠️ **WARN** (*A .env file exists on disk — ensure it is outside the web root and not served.* Defensive check).
- `password_hashes_modern`: ⚠️ **WARN** (*1 user account uses a legacy MD5 password hash — will rehash on next login.*).
- `stray_files`: ✅ PASS (No stray SQL dumps, ZIP backups, or info scripts in web root).
- `ssl_cert_expiry`: ✅ PASS (Valid TLS certificate).
- `security_headers`: ✅ PASS (Baseline security headers active).
- `https_forced`: ✅ PASS (HTTP &rarr; HTTPS automatic redirection operational).
- `xmlrpc_status`: ✅ PASS (XML-RPC locked down).
- `admin_username`: ✅ PASS (No default `admin` superuser present).
- `wp_config_perms`: ✅ PASS (Config permissions secure).

#### Errors (2/2 PASS)
- `error_log_size`: ✅ PASS (Error log within threshold).
- `recent_fatal_errors`: ✅ PASS (Zero recent PHP fatal errors logged).

#### Database (13/13 PASS)
- `db_prefix_custom`: ✅ PASS (Secure non-default table prefix active).
- `core_tables_present`: ✅ PASS (All standard WordPress core tables present).
- `orphaned_tables`: ✅ PASS (Zero orphaned tables detected).
- `db_overhead`: ✅ PASS (Database overhead clean, zero table bloat).
- `autoload_size`: ✅ PASS (Autoloaded options size within safe limits).

#### Files (3/3 PASS)
- `disk_free_space`: ✅ PASS (Adequate disk space available).
- `uploads_writable`: ✅ PASS (Uploads directory writable).
- `recent_backup`: ✅ PASS (Valid recent backup detected).

#### Performance (7/9 PASS, 2 WARN)
- `object_cache`: ⚠️ **WARN** (*No persistent object cache is in use.* Standard for basic shared/managed hosting without Redis/Memcached).
- `opcache_status`: ⚠️ **WARN** (*Zend OPcache is disabled or not installed.*).
- `memory_limit`: ✅ PASS (PHP memory limit adequate).

#### Environment (6/7 PASS, 1 WARN)
- `php_eol_horizon`: ⚠️ **WARN** (*PHP 8.3.35 is nearing its end-of-life date — plan an upgrade.* Official EOL 2026-12-31, 2.4 months remaining).
- `core_update_available`: ✅ PASS (WordPress core up to date).
- `plugin_updates`: ✅ PASS (Zero critical core plugin deprecations).

---

## 3. Discrepancy Case Study: Initial PHP 8.0 Diagnostic

### Symptom
Upon initial inspection of the PressVitals admin dashboard on `dqro.mx`, the probe reported:
> `FAIL: PHP 8.0.30 is end-of-life — upgrade immediately.`

Simultaneously, the IONOS Cloud Panel explicitly reported PHP version **8.3**.

### Investigation Steps
1. Inspected WordPress Core built-in **Tools &rarr; Site Health &rarr; Debug &rarr; Server**.
2. Core Site Health returned:
   - `PHP version: 8.3.35 (Supports 64bit values)`
   - `PHP SAPI: fpm-fcgi`
3. Cross-referenced PressVitals storage model in `includes/class-pvsa-admin.php`:
   - PressVitals caches the complete audit payload in the database option `pvsa_last_report`.
   - The stored report had timestamp metadata prior to the PHP upgrade conducted in IONOS.
4. Executed **"Run now"** (`pvsa_run_now` action).
5. The probe dynamically executed `$version = PHP_VERSION;`, immediately resolving to **PHP 8.3.35**, clearing the `FAIL` verdict and transitioning to the expected preventive `WARN` (EOL Horizon).

---

## 4. Remediation & Hardening Action Plan

1. **Activate Zend OPcache**:
   - Access IONOS PHP Configuration / `php.ini`.
   - Enable `opcache.enable=1` and set `opcache.memory_consumption=128`. This resolves the performance audit warning and reduces server response times.
2. **Rehash Legacy Admin Password**:
   - Have the legacy administrator log in to `dqro.mx` to trigger automatic rehashing to modern bcrypt / WP 6.8+ standards.
3. **Upgrade Plugin to v1.5.4**:
   - Run WordPress update check to pull the latest 1.5.4 release featuring differential runtime diagnosis.

---

## 5. Local Docker Emulation Environment (`wp-ionos`)

To faithfully reproduce the IONOS WAAS production environment locally, a dedicated container service `wp-ionos` is defined in `docker-compose.yml`:

- **Service Name**: `wp-ionos` (`http://localhost:8084`)
- **Base Architecture**: Ubuntu 22.04 with dual PHP installations:
  - **Web Runtime**: Apache 2.4 + PHP 8.3.36 FPM (`proxy_fcgi`). Emits `X-Powered-By: PHP/8.3.36`.
  - **CLI Runtime**: PHP 8.0.30 via `update-alternatives --set php /usr/bin/php8.0` (matching the default OS binary in IONOS crontab).
  - **Selective CLI**: `/usr/bin/php8.3` available when explicitly invoked.
- **PHP Configuration**:
  - `memory_limit = 512M`
  - `upload_max_filesize = 64M`, `post_max_size = 64M`, `max_execution_time = 300`
  - `opcache.enable = 1`, `opcache.memory_consumption = 64`
- **Database**: Dedicated MariaDB database `wp_ionos` with table prefix `dqro_`.
- **Validation**:
  - `curl -sI http://localhost:8084/` verifies Web PHP 8.3.36.
  - `docker compose exec wp-ionos php -v` verifies CLI PHP 8.0.30.
  - `docker compose exec wp-ionos wp eval "print_r((new PVSA_Engine())->check_php_version());" --allow-root` verifies the differential `warn` diagnostic.
