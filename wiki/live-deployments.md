# Live Deployments & Field Validations

> Part of the [PressVitals Site Auditor LLM Wiki](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/index.md).

## 1. Overview & Monitored Production Sites

PressVitals Site Auditor is deployed in production across heterogeneous hosting architectures. This document serves as the master index and comparative synthesis of active live installations, their hosting environments, configuration baselines, and audit results.

### Monitored Sites Directory

| Site Identifier | Production URL | Hosting Provider & Infrastructure | Control Panel & Stack | Detailed Dossier |
| :--- | :--- | :--- | :--- | :--- |
| **dqro.mx** | `https://dqro.mx` | **IONOS** (Cloud Managed WAAS) | Cloud Panel / Nginx + Apache + PHP 8.3.36 + MariaDB 10.11 | [**deployments-dqro.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/deployments-dqro.md) |
| **Freelance México** | `https://freelancemexico.com.mx` | **Hostgator** (Shared Web Hosting) | cPanel Jupiter (`mx98`) / Apache 2.4.69 + PHP 8.3.36 + MySQL 5.7 | [**deployments-freelancemexico.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/deployments-freelancemexico.md) |

---

## 2. Cross-Environment Comparison Matrix (60 Probes Suite)

Field diagnostic comparison between IONOS Cloud WAAS and Hostgator Shared architectures:

| Diagnostic Dimension | dqro.mx (IONOS) | Freelance México (Hostgator) | Analytical Insight |
| :--- | :--- | :--- | :--- |
| **Installed Plugin Version** | 1.5.4 | 1.5.4 | Both sites run v1.5.4 with decoupled CLI/Web runtime diagnosis. |
| **Total Probes / Verdict** | 60 Probes / ⚠️ `WARN` (55 P / 5 W / 0 F) | 60 Probes / ⚠️ `WARN` (49 P / 10 W / 1 F*) | *Hostgator F was transient HTTP 503 during active plugin update. |
| **Web Server** | Nginx Proxy / Apache 2.4 (WAAS) | Apache 2.4.69 | Both environments enforce standard security rules and 403s on dot-files. |
| **Active PHP Version** | **PHP 8.3.36** | **PHP 8.3.36** | Both hosts operate on latest micro-release of PHP 8.3. |
| **PHP Runtime Decoupling** | Handled (`warn` on CLI EOL) | Handled (`warn` on CLI EOL) | Differential diagnosis eliminates false positive fail on crontabs. |
| **PHP EOL Horizon Status** | ⚠️ `WARN` (EOL 2026-12-31) | ⚠️ `WARN` (EOL 2026-12-31) | Valid preventive alert: PHP 8.3 reaches EOL in < 2.5 months (Q4 2026). |
| **Zend OPcache** | ⚠️ `WARN` (Disabled / Not loaded) | ✅ `PASS` (Active, 98.5% Hit Rate) | Hostgator provides active bytecode cache; IONOS requires manual `php.ini` config. |
| **Object Cache** | ⚠️ `WARN` (No persistent cache) | ✅ `PASS` (Persistent Cache Roundtrip OK) | Hostgator site uses APCu / Redis cache; IONOS relies on default WP transient DB store. |
| **Database Table Prefix** | ✅ `PASS` (`dqro_` custom prefix) | ⚠️ `WARN` (Standard `wp_` prefix) | dqro.mx hardened; Freelance México retains default prefix from initial install. |
| **Non-Core DB Tables** | ✅ `PASS` (Clean schema) | ⚠️ `WARN` (93 non-core tables) | Freelance México runs HivePress marketplace engine (filter via `pvsa_known_tables`). |
| **Database Collation** | ✅ `PASS` (100% utf8mb4) | ⚠️ `WARN` (27 tables non-utf8mb4) | Freelance México has legacy plugin tables requiring charset modernization. |
| **Secret File Protection** | ✅ `PASS` (HTTP 403 on `.env`) | ✅ `PASS` (HTTP 403 on `.env`) | Web exposure blocked; disk presence flagged as defensive warning. |

---

## 3. Discrepancy & False Positive Analysis

During field evaluations across both hosting platforms, two key validation questions were investigated:

### A. The PHP 8.3.35 Warning (`php_eol_horizon`)
- **Reported Diagnostic**: `WARN: PHP 8.3.35 is nearing its end-of-life date — plan an upgrade.`
- **Validation Verdict**: **VALID (Intended Diagnostic Behavior)**.
- **Architectural Mechanics**:
  - The probe `php_eol_horizon` evaluates `$version = PHP_VERSION;` against the official PHP EOL table in `includes/class-pvsa-engine.php`.
  - PHP 8.3 official security support concludes on **2026-12-31**.
  - The default warning horizon is **6 months** (`pvsa_php_eol_horizon_months = 6`).
  - Given the runtime date of **October 2026**, PHP 8.3 is 2.4 months away from EOL. The warning proactively alerts administrators to prepare migration to PHP 8.4.

### B. The Initial PHP 8.0 Discrepancy on dqro.mx
- **Reported Diagnostic**: Plugin UI initially reported `PHP 8.0.30 FAIL`, while IONOS control panel showed `PHP 8.3`.
- **Validation Verdict**: **FALSE POSITIVE CAUSED BY CACHED AUDIT REPORT**.
- **Root Cause & Resolution**:
  - PressVitals stores the last completed audit in `wp_options` under `pvsa_last_report`.
  - The previous audit had executed prior to the PHP version upgrade in the IONOS Cloud Panel.
  - Triggering an immediate execution via `pvsa_run_now` ("Run now" button) or waiting for the autonomous daily WP-Cron cycle instantly refreshed the report, detecting the live **PHP 8.3.35** runtime.

---

## 4. Maintenance & Upgrade Playbook

1. **Plugin Version Synchronization**:
   - Access **Dashboard → Updates** (`update-core.php`) on both sites.
   - Click **"Check Again"** ("Comprobar de nuevo") to invalidate WordPress core's 12-hour plugin update transient cache (`_site_transient_update_plugins`).
   - Upgrade `pressvitals-site-auditor` from `1.5.1` to `1.5.2`.
2. **Filter Integration for Custom Marketplace Tables**:
   - On Freelance México, register known HivePress tables in `functions.php` or `fm-core-engine` using `pvsa_known_tables` to keep the database diagnostic clean.
3. **IONOS OPcache Activation**:
   - Enable `opcache.enable=1` and `opcache.memory_consumption=128` in IONOS PHP settings to clear the performance warning.
