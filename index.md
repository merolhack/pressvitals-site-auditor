# PressVitals Site Auditor LLM Wiki Index

This file acts as the content-oriented index of the project's LLM Wiki architecture, as described by the [LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f).

## Project Documentation
*   **[LLM_WIKI.md](LLM_WIKI.md)**: The core LLM Wiki file containing architectural philosophy, i18n rules, and release workflows.
*   **[AGENTS.md](AGENTS.md)**: The schema and behavioral instructions for LLMs operating in this repository.
*   **[HISTORY.md](HISTORY.md)**: Acts as the `log.md` equivalent. An append-only record of changes, ingests, and modifications to the project and wiki.
*   **[README.md](README.md)**: The human-facing marketing and installation documentation.

## Source Code & Logic
*   **[pressvitals-site-auditor.php](pressvitals-site-auditor.php)**: The main plugin file and entry point.
*   **[includes/class-pvsa-engine.php](includes/class-pvsa-engine.php)**: The core diagnostic engine containing all the health checks.
*   **[includes/class-pvsa-admin.php](includes/class-pvsa-admin.php)**: The WordPress admin UI rendering logic.
*   **[includes/class-pvsa-cron.php](includes/class-pvsa-cron.php)**: The scheduled WP-Cron alerting mechanism.
*   **[includes/class-pvsa-rest.php](includes/class-pvsa-rest.php)**: The REST API endpoints (`/ping`, `/report`).

## Testing & Automation
*   **[tests/](tests/)**: PHPUnit test suites.
*   **[.github/workflows/](.github/workflows/)**: CI/CD pipelines (PHPUnit, PHPCS, Deploy).
*   **[docker-compose.yml](docker-compose.yml)**: Multi-version WordPress testing environment.
*   **[.distignore](.distignore)**: Exclusions for the final production ZIP build.
