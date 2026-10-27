# Deployment Dossier: Freelance México (Hostgator Shared)

> Part of the [PressVitals Site Auditor LLM Wiki](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/index.md) &rarr; [Live Deployments](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/live-deployments.md).

## 1. Environment & Infrastructure Profile

- **Domain**: `https://freelancemexico.com.mx`
- **Application**: Spanish-language freelance marketplace connecting businesses with independent Mexican professionals (built on WordPress + HivePress).
- **Hosting Provider**: **Hostgator**
- **Hosting Infrastructure**: Shared Web Hosting (Server hostname: `mx98.hostgator.mx`)
- **Control Panel**: cPanel Jupiter interface (`cpsess3866292535`)
- **Web Server**: Apache 2.4.69 / Linux x86_64
- **Active PHP Runtime**: **PHP 8.3.36** (MultiPHP Handler)
- **Database Server**: MySQL 5.7.44-48-log
- **Plugin Version Installed**: `1.5.4`

---

## 2. Comprehensive Audit Probe Results (60 Probes Suite)

Audit performed via PressVitals Site Auditor diagnostic engine (`tools.php?page=pressvitals-site-auditor`):

### Summary Breakdown
- **Verdict**: `FAIL` (Transient during active plugin/core update) &rarr; reverts to `WARN` once maintenance mode clears.
- **Passed Checks**: 49
- **Warning Checks**: 10
- **Failing Checks**: 1 (`https_home` transiently returned HTTP 503 due to active `.maintenance` file during background update).
- **Total Probes**: 60
- **Duration**: ~2,800 ms

### Critical Incident Analysis & Transient State
- `https_home`: ❌ **FAIL** (HTTP 503). At the exact moment of execution, WordPress core/plugin auto-updater wrote `.maintenance`. The probe surfaced that the public site was temporarily serving HTTP 503.
  - *Remediation Applied in Core*: `check_https_home()` now detects `wp_is_maintenance_mode()` and `.maintenance` files during HTTP 503, converting the false alarm failure into an informative `[WARN]`.

### False Positive & Warning Investigation
1. **`orphaned_tables` (93 non-core tables)**:
   - *Status*: ⚠️ **WARN**
   - *Analysis*: **False Positive of Application Architecture**. Freelance México is an active marketplace built on HivePress + WooCommerce + Action Scheduler, creating custom database tables (`wp_hp_*`, `wp_actionscheduler_*`, `wp_woocommerce_*`).
   - *Remediation Applied in Core*: `check_orphaned_tables()` now automatically recognizes active plugin table prefixes (HivePress `hp_`, WooCommerce `woocommerce_`/`wc_`, Action Scheduler `actionscheduler_`, Wordfence `wf`, Yoast `yoast_`, Rank Math `rank_math_`, WPForms `wpforms_`, Elementor `e_`). Furthermore, `pvsa_known_tables` supports wildcard patterns (e.g., `'hp_*'`) and `pvsa_known_table_prefixes` allows registering custom prefixes without enumerating every table.
2. **`db_prefix_customized`**:
   - *Status*: ⚠️ **WARN** (*Database is using default "wp_" table prefix*). Legitimate security finding.
3. **`table_collation`**:
   - *Status*: ⚠️ **WARN** (*27 tables use legacy latin1/utf8 collations*). Legitimate database finding from historical migrations.
4. **`table_storage_engine`**:
   - *Status*: ⚠️ **WARN** (*1 table uses MyISAM*). Legitimate engine finding.
5. **`php_hardening`**:
   - *Status*: ⚠️ **WARN** (*expose_php enabled, exec unconstrained*). Legitimate shared hosting finding.
6. **`php_version`**:
   - *Status*: ⚠️ **WARN** (*PHP 8.3.36 is nearing its end-of-life date*). Legitimate preventive notice.
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
