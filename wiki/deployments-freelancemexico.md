# Deployment Dossier: Freelance México (Hostgator Shared)

> Part of the [PressVitals Site Auditor LLM Wiki](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/index.md) &rarr; [Live Deployments](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/live-deployments.md).

## 1. Environment & Infrastructure Profile

- **Domain**: `https://freelancemexico.com.mx`
- **Application**: Spanish-language freelance marketplace connecting businesses with independent Mexican professionals (built on WordPress + HivePress).
- **Hosting Provider**: **Hostgator**
- **Hosting Infrastructure**: Shared Web Hosting (Server hostname: `mx98.hostgator.mx`)
- **Control Panel**: cPanel Jupiter interface (`cpsess3866292535`)
- **Web Server**: Apache 2.4.69 / Linux x86_64
- **Active PHP Runtime**: **PHP 8.3.35** (MultiPHP Handler)
- **Database Server**: MySQL 5.7.44-48-log
- **Plugin Version Installed**: `1.5.1` (upgrade pending to `1.5.2`)

---

## 2. Comprehensive Audit Probe Results

Audit performed via PressVitals Site Auditor diagnostic engine (`tools.php?page=pressvitals-site-auditor`) with fresh "Run now" execution:

### Summary Breakdown
- **Verdict**: `WARN`
- **Passed Checks**: 51
- **Warning Checks**: 6
- **Failing Checks**: 0

### Functional Group Breakdown

#### Availability (3/3 PASS)
- `db_connection`: ✅ PASS (Direct MySQL connection operational).
- `https_home`: ✅ PASS (HTTPS verified).
- `maintenance_mode_stuck`: ✅ PASS (No maintenance lock detected).

#### Security (18/20 PASS, 2 WARN)
- `env_file_exposed`: ✅ PASS (HTTP probe against `/.env` returns 403 Forbidden via Apache `.htaccess`).
- `env_file_disk`: ⚠️ **WARN** (*A .env file exists on disk — ensure it is outside the web root and not served.* Defensive check).
- `db_prefix_custom`: ⚠️ **WARN** (*Database is using the default "wp_" table prefix.* Recommended security hardening).
- `password_hashes_modern`: ✅ PASS (Modern password hashing active).
- `stray_files`: ✅ PASS (Zero exposed backup dumps).
- `ssl_cert_expiry`: ✅ PASS (Valid SSL certificate with automatic Let's Encrypt / cPanel AutoSSL renewal).
- `security_headers`: ✅ PASS (Standard security headers delivered).
- `https_forced`: ✅ PASS (Automatic HTTP &rarr; HTTPS redirection).

#### Errors (2/2 PASS)
- `error_log_size`: ✅ PASS (PHP error log within threshold).
- `recent_fatal_errors`: ✅ PASS (Zero recent PHP fatals).

#### Database (10/13 PASS, 3 WARN)
- `orphaned_tables`: ⚠️ **WARN** (*91 non-core or orphaned database tables detected.* Expected behavior: Marketplace tables from HivePress extensions, listings, orders, messages, and Action Scheduler).
- `db_collation`: ⚠️ **WARN** (*27 tables are using legacy collations (latin1 / utf8) instead of utf8mb4.*).
- `db_storage_engine`: ⚠️ **WARN** (*1 table is using MyISAM rather than InnoDB.*).
- `core_tables_present`: ✅ PASS (All standard WordPress core tables verified).
- `db_overhead`: ✅ PASS (Database fragmentation healthy).
- `autoload_size`: ✅ PASS (Autoloaded options size within limits).

#### Files (3/3 PASS)
- `disk_free_space`: ✅ PASS (Sufficient shared storage available).
- `uploads_writable`: ✅ PASS (Permissions verified).
- `recent_backup`: ✅ PASS (Recent backup confirmed).

#### Performance (9/9 PASS)
- `opcache_status`: ✅ PASS (*Zend OPcache is active with a 98.5% cache hit rate.* Outstanding bytecode performance in shared hosting).
- `object_cache`: ✅ PASS (*Persistent object cache roundtrip OK.*).
- `memory_limit`: ✅ PASS (Adequate memory allocation for HivePress marketplace workload).

#### Environment (6/7 PASS, 1 WARN)
- `php_eol_horizon`: ⚠️ **WARN** (*PHP 8.3.35 is nearing its end-of-life date — plan an upgrade.* Official EOL 2026-12-31, 2.4 months remaining).
- `core_update_available`: ✅ PASS (WordPress core up to date).
- `plugin_updates`: ✅ PASS (Plugin update status verified).

---

## 3. Discrepancy & Diagnostic Validation

### A. Non-Core Tables (Marketplace Architecture)
The probe `orphaned_tables` flags 91 tables. In standard blogs, this indicates leftover debris from deleted plugins. However, on **Freelance México**, these tables belong to the active business marketplace architecture:
- HivePress Core (`hp_listings`, `hp_vendors`, `hp_requests`, etc.)
- Action Scheduler queues
- Messaging & Review custom tables

**Resolution Mechanism**:
PressVitals provides the `pvsa_known_tables` filter specifically for this scenario. Developers can register known marketplace tables to keep the audit clean:

```php
add_filter( 'pvsa_known_tables', function( array $tables ) {
    global $wpdb;
    $hp_tables = array(
        $wpdb->prefix . 'hp_listings',
        $wpdb->prefix . 'hp_messages',
        $wpdb->prefix . 'actionscheduler_actions',
        $wpdb->prefix . 'actionscheduler_logs',
        // add additional known plugin tables
    );
    return array_merge( $tables, $hp_tables );
} );
```

### B. PHP 8.3 EOL Horizon
- cPanel Server Status reports **PHP 8.3.35** loaded via `fpm-fcgi`.
- The probe correctly flags the approaching EOL date (December 31, 2026) within the 6-month preventive horizon.

---

## 4. Remediation & Hardening Action Plan

1. **Table Collation Modernization**:
   - Convert the 27 legacy tables to `utf8mb4_unicode_520_ci` using WP-CLI or phpMyAdmin:
     ```sql
     ALTER TABLE wp_example_table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
     ```
2. **Convert Non-InnoDB Table**:
   - Alter the single MyISAM table to `ENGINE=InnoDB` for atomic transactions and row-level locking.
3. **Register `pvsa_known_tables` Filter**:
   - Add the filter snippet above to `fm-core-engine` to suppress false warnings for legitimate HivePress tables.
4. **Upgrade Plugin to v1.5.2**:
   - Perform update check in `update-core.php` and upgrade to version 1.5.2.
