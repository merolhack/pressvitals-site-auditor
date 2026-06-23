# PressVitals Site Auditor — LLM Wiki

This document serves as the project's **LLM Wiki**. It contains essential context, architectural decisions, testing paradigms, and release workflows. AI agents interacting with this repository should read this file before performing modifications to ensure they adhere to the established constraints.

## 1. Core Architecture & Philosophy
- **Headless-First:** The plugin focuses on headless, scheduled execution via `wp-cron`. It generates a JSON report that can be exported or queried via a token-gated REST API endpoint (`/wp-json/pressvitals/v1/report`).
- **Probe Registry:** Probes are defined in `includes/class-pvsa-engine.php` and managed via a registry hook (`pvsa_registered_checks`). 
- **Check Anatomy:** Each probe callback must return an array with `status` (`pass`, `warn`, `fail`) and `detail` (a localized human-readable string). The engine automatically appends `duration_ms` and `tier` to each executed check.

## 2. LLM-Wiki Architecture Integration
This repository fully implements the [LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) to provide a compiled knowledge layer for agents:
- **`index.md`**: Content-oriented catalog indexing the codebase and wiki.
- **`HISTORY.md`**: Serves as the chronological `log.md`. Always append new changes, bug discoveries, and design shifts here.
- **`AGENTS.md`**: Serves as the schema document. Defines rules, boundaries, and expected workflows for agents operating in this workspace.

## 2. i18n & Localization (CRITICAL)
- **WP.org Compliance:** The WordPress Plugin Check (PCP) scanner is extremely strict. 
- You MUST use `__()` or `esc_html__()` with the text domain `'pressvitals-site-auditor'`.
- If using `sprintf()` with placeholders, you **MUST** include a `/* translators: ... */` comment **exactly** on the line preceding the string definition, otherwise PHPCS/PCP will fail.
- **Pipeline:** `.pot` files are generated via `composer make-pot` (`wp i18n make-pot . languages/pressvitals-site-auditor.pot`). 

## 3. Environment & WSL Constraints
- **Docker Executions:** Running composer commands directly in the WSL container often fails due to `dubious ownership` errors. Use the WordPress docker container instead:
  ```bash
  docker compose exec -w /var/www/html/wp-content/plugins/pressvitals-site-auditor wp-latest php /var/www/html/composer.phar <command>
  ```
- **Git Push Authentication:** The environment frequently hangs on interactive authentication prompts. Use the explicit PAT URL when pushing:
  ```bash
  git push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git
  ```

## 4. Testing & CI Pipeline
- **PHPCS:** We enforce `WordPress-Core` coding standards, but explicitly ignore `WordPress.WP.I18n.MissingTranslatorsComment` and some pedantic docblock rules in `phpcs.xml` to reduce noise. Run `composer run phpcbf` to autofix.
- **Unit Testing (PHPUnit):** Tests are housed in `tests/test-engine.php`. 
  - Do **NOT** rely on external network calls for probes like `check_security_headers` or `check_stray_files`. We use the WP Core hook `pre_http_request` to mock HTTP responses in our tests.
- **CI Workflows:** `.github/workflows/` contains three distinct workflows:
  1. `tests.yml`: Runs PHPUnit tests across WP versions 6.3 - latest.
  2. `code-quality.yml`: Runs PHPCS and the official `wordpress/plugin-check-action@v1`.
  3. `deploy.yml`: Pushes tags to the WordPress.org SVN repository on release.

## 5. Release Workflow
We use a robust Python-based zip pipeline because standard git archivers bypass `.distignore`.
1. Run `bin/bump-version.sh <version>` to synchronize versions across headers and `readme.txt`.
2. Update the `readme.txt` changelog manually.
3. Generate the ZIP (using `rsync` and Python `shutil.make_archive` honoring `.distignore`).
4. Commit, create a tag, and push. (Creating a GitHub Release with the tag triggers the SVN deployment action).
