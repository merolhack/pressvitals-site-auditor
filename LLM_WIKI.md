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

## 5. WordPress.org SVN & Repository Information
- **SVN Repository URL:** `https://plugins.svn.wordpress.org/pressvitals-site-auditor`
- **Public Directory URL:** `https://wordpress.org/plugins/pressvitals-site-auditor`
- **SVN Account & Credentials:**
  - SVN Username: `merolhack` (case-sensitive; WP.org usernames must be used exactly as registered, e.g., never use email address).
  - SVN Password: Managed separately from main WP.org login password under Account & Security: `https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password`.
  - Credentials must be configured in GitHub Repository Secrets (`SVN_USERNAME` and `SVN_PASSWORD`) for automated CI deployments.
- **Asset Placement & Formatting:**
  - Directory assets (`banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`, `icon-128x128.png`, and screenshots) live in `.wordpress-org/` in Git.
  - In SVN, these map to the repository root `/assets/` directory (`https://plugins.svn.wordpress.org/pressvitals-site-auditor/assets/`), separate from `/trunk/`.
  - **72-Hour Directory Propagation:** Due to WordPress.org CDN and directory caching, it may take up to **72 hours** after an SVN upload for search results, user profiles, and branding images (logos/banners) to fully update across the website.
- **Key WordPress.org Developer Resources:**
  - [Using SVN with Plugin Directory](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
  - [SVN Access & Passwords](https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/)
  - [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)
  - [Readme.txt Standard](https://wordpress.org/plugins/developers/#readme) | [Validator](https://wordpress.org/plugins/developers/readme-validator/)
  - [Plugin Assets Specifications](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
  - [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
  - [Block Specific Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/block-specific-plugin-guidelines/)

## 6. Release Workflow & Automated Deployment
We use a robust automated pipeline combining GitHub Actions and a Python-based zip archiver because standard git archivers bypass `.distignore`.
1. Run `bin/bump-version.sh <version>` to synchronize versions across headers and `readme.txt`.
2. Update the `readme.txt` changelog manually.
3. Generate the ZIP (using `rsync` and Python `shutil.make_archive` honoring `.distignore`).
4. Commit, create a tag, and push.
5. **SVN Deployment Architecture (`deploy.yml`):**
   - **Full Plugin Release:** Creating and publishing a GitHub Release (e.g. tag `v1.2.5`) triggers `10up/action-wordpress-plugin-deploy@stable`. This pushes `/trunk` and `/tags/<version>` to WordPress.org SVN.
   - **Asset & Readme Sync:** Pushing changes to `.wordpress-org/**` or `readme.txt` on branch `main` (or executing `workflow_dispatch`) triggers `10up/action-wordpress-plugin-asset-update@stable`. This syncs logos, banners, and documentation directly to `/assets/` in SVN without attempting to create an invalid SVN release tag.
