# WordPress Coding Standards, i18n & PHPCS Formatting

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. WordPress.org Compliance & Standards

The WordPress Plugin Check (PCP) scanner and PHPCS rules (`WordPress-Core`) applied to PressVitals Site Auditor are extremely strict. Any code submitted or committed must pass 100% clean with **0 errors and 0 warnings**.

---

## 2. Internationalization (i18n) Rules

- **Text Domain**: Always use `'pressvitals-site-auditor'` as the text domain.
- **Escaped Localization**: Prefer `esc_html__()`, `esc_attr__()`, or `__()` depending on HTML output context.
- **Translator Comments (MANDATORY)**:
  - If using `sprintf()` with placeholders (`%s`, `%d`, etc.), you **MUST** include a `/* translators: ... */` comment **exactly** on the line preceding the string definition.
  - Failure to put this comment on the immediate preceding line triggers a fatal violation in the WordPress.org Plugin Check scanner.
  - Example:
    ```php
    /* translators: %s: Human-readable file size threshold. */
    $message = sprintf( esc_html__( 'Error log size exceeds %s.', 'pressvitals-site-auditor' ), $size_label );
    ```

---

## 3. Formatting & Code Style Constraints

### Array Key Alignment
In associative arrays (particularly within `register_core_checks()` in `includes/class-pvsa-engine.php`), double arrows (`=>`) must align strictly across all keys.
For instance, if `'inactive_plugins_themes'` has 23 characters, all other array entries in that block must be padded with spaces so their `=>` symbols align in the exact same column:
```php
'inactive_plugins_themes' => array(
    'callback' => array( $this, 'check_inactive_plugins_themes' ),
    'tier'     => 'extended',
),
'php_version'             => array(
    'callback' => array( $this, 'check_php_version' ),
    'tier'     => 'core',
),
```

### SQL Prepared Query Placeholders
When dynamic `$placeholders` strings (e.g. `%s, %s, %s`) are interpolated into `$wpdb->prepare()`, PHPCS may trigger a false positive (`WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare`).
Wrap such queries with explicit PHPCS suppression comments:
```php
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
        $option_names
    )
);
// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
```

---

## 4. Translation Template (.pot) Pipeline

Translation templates must be recompiled whenever new probe descriptions or user-facing strings are added:
- Command:
  ```bash
  composer make-pot
  ```
  *(Equivalently: `wp i18n make-pot . languages/pressvitals-site-auditor.pot`)*
- Never edit the `.pot` file manually; always generate it via the pipeline to preserve headers and line numbers.

---

## 5. Scope of Scanning & Auxiliary Directory Exclusions

To maintain complete parity between the local `phpcs` task and the GitHub Actions CI workflows (`code-quality.yml` and `wordpress/plugin-check-action`):
- **Excluded Directories in [`phpcs.xml`](file:///home/merolhack/fl/pressvitals-site-auditor/phpcs.xml)**:
  - `/vendor/` and `/node_modules/` (dependencies)
  - `/tests/` (PHPUnit test classes)
  - `/\.docker/` (Docker compose, stack definitions, and auxiliary test scripts)
  - `/bin/` and `/wiki/` (documentation and deployment utilities)
- **Standards for Auxiliary & DevOps Scripts**:
  - Standalone scripts intended for container diagnostics (such as [`.docker/run-stack-audit.php`](file:///home/merolhack/fl/pressvitals-site-auditor/.docker/run-stack-audit.php)) must adhere strictly to `WordPress-Core` and `WordPress-Docs` standards (tabs for indentation, braced control structures, full docblocks, single quotes) ensuring compatibility and zero false alarms if scanned directly.

