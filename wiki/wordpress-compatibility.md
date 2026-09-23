# WordPress Compatibility & Field Guide Audits

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. Compatibility Overview

PressVitals Site Auditor supports WordPress versions from **6.3 through 7.1+ (Bleeding Edge)**.
The plugin is tested continuously against four distinct Docker environments (`wp-legacy`, `wp-mid`, `wp-latest`, `wp-71`).

---

## 2. WordPress 7.1 Compatibility Audit (Verified 2026-08-14)

The plugin has been fully audited against all architectural shifts introduced in the **WordPress 7.1 Field Guide**:

| WordPress 7.1 Shift | Impact Analysis & Mitigation | Status |
| :--- | :--- | :---: |
| **Iframed Post Editor** | **No impact.** PVSA is a headless-first diagnostic tool with an independent admin dashboard (`Tools -> PressVitals Site Auditor`), zero post editor canvas hooks, and no custom Gutenberg blocks. | PASS |
| **Client-Side Media Processing** | **No impact.** PVSA performs read-only filesystem and diagnostic checks, without intercepting media uploads or image processing pipelines. | PASS |
| **@wordpress/components Updates** | **No impact.** Admin UI is rendered server-side in PHP with lightweight, vanilla CSS/JS. No direct npm dependency on `@wordpress/components`. | PASS |
| **Persistent Toolbar** | **No impact.** PVSA does not add custom nodes or modify the WordPress admin top toolbar. | PASS |
| **Public SVG Icon API** | **No impact.** All PVSA iconography is self-contained inline SVG within the admin template. | PASS |
| **jQuery UI 1.14.2 Upgrade** | **No impact.** PVSA uses modern vanilla JavaScript (`pvsa-admin.js`) without any jQuery or jQuery UI dependencies. | PASS |
| **Abilities API Improvements** | **Fully compatible.** PVSA strictly relies on standard WordPress capability checks (`manage_options`). | PASS |

### Compatibility Status & Verification
- `Tested up to: 7.1` confirmed in both plugin headers (`pressvitals-site-auditor.php`) and WordPress.org readme (`readme.txt`).
- **100% PASS** verified across local multi-version Docker containers:
  - `wp-71` on port `8071` (WordPress 7.1 Beta / Bleeding Edge)
  - `wp-latest` on port `8083` (WordPress Latest Stable)
  - `wp-mid` on port `8081` (WordPress Intermediate)
  - `wp-legacy` on port `8074` (WordPress 6.3 Minimum Supported)
- Automated PHPUnit test suite passes cleanly across all 4 environments without deprecation warnings.
