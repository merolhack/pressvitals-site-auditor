# PressVitals Site Auditor LLM Wiki & Knowledge Base

> **MANDATORY DIRECTIVE FOR ALL AI AGENTS & SKILLS**:
> Always consult this Karpathy-style LLM Wiki ([`wiki/index.md`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/index.md)) and the **codebase-memory-mcp** knowledge graph tools (`search_graph`, `trace_path`, `get_code_snippet`, `get_architecture`, `query_graph`) as the primary, single source of truth for plugin architecture, probe definitions, WordPress coding standards (PHPCS / i18n), local Docker workflows, WordPress 7.1 compatibility, and WordPress.org SVN release deployments before executing any tasks or modifying code.

---

## 1. Wiki Navigation & Core Modules

This LLM Wiki is structured according to the [Karpathy LLM Wiki Architecture](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) to provide structured, interconnected modular knowledge for both autonomous AI agents and human engineers.

| Document | Purpose & Key Topics |
| :--- | :--- |
| [**architecture.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/architecture.md) | Headless-first diagnostic philosophy, scheduled WP-Cron alerting mechanism, token-gated REST API endpoints (`/ping`, `/report`), check anatomy (`duration_ms`, `tier`), and full catalog of the 52 built-in probes across 8 functional groups. |
| [**wordpress-standards-and-i18n.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/wordpress-standards-and-i18n.md) | Strict WordPress.org Plugin Check (PCP) & PHPCS compliance rules: translation functions (`__()`, `esc_html__()`), mandatory `/* translators: ... */` comments on preceding lines, associative array alignment standards, prepared SQL placeholder ignore patterns, and POT file generation. |
| [**wordpress-compatibility.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/wordpress-compatibility.md) | Comprehensive compatibility audit against WordPress 7.1.2 Field Guide: bcrypt password hashing (WP 6.8+), modern image formats (AVIF/WebP), zero post editor iframe impact, zero Gutenberg dependencies, client-side media isolation, vanilla JS admin architecture without jQuery UI, and multi-container Docker test matrix. |
| [**wordpress-org-and-releases.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/wordpress-org-and-releases.md) | Official WordPress.org plugin directory info, SVN credentials & tags (1.2.6, 1.3.0, 1.4.0, 1.5.0, 1.5.1), mandatory 9-step release workflow, asset propagation delays (72h), and dual API verification (`plugin_information` + `update-check` 15-60m cache delay). |
| [**tools-and-operations.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/tools-and-operations.md) | Operational playbooks: multi-version Docker containers (`wp-71`, `wp-latest`, `wp-mid`, `wp-legacy`), PHPUnit & PHPCS execution inside containers, Git PAT push URL requirements, and `codebase-memory-mcp` knowledge graph discovery (including live Freelance México local codebase inspection). |
| [**ollama-delegation.md**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/ollama-delegation.md) | Mandatory secondary model delegation protocol via `consultar_modelo_local`: model hierarchy (`gemma4:cloud` prioritario si cuota < 90% &rarr; `qwen3:8b-8k` local de ~5.2 GB), tool test evidence, and canonical "MUST DELEGATE" vs "DO NOT DELEGATE" task matrix. |
| [**sources/**](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/wiki/sources/) | Directory containing raw diagnostic reports, audit outputs, and historical validation artifacts. |

---

## 2. Project Source & Automation Pointers

- **Main Plugin Entrypoint**: [`pressvitals-site-auditor.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/pressvitals-site-auditor.php)
- **Core Diagnostic Engine**: [`includes/class-pvsa-engine.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/includes/class-pvsa-engine.php)
- **Admin UI Rendering**: [`includes/class-pvsa-admin.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/includes/class-pvsa-admin.php)
- **Scheduled Alerts (WP-Cron)**: [`includes/class-pvsa-cron.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/includes/class-pvsa-cron.php)
- **REST API Endpoints**: [`includes/class-pvsa-rest.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/includes/class-pvsa-rest.php)
- **Agent Behavioral Schema**: [`AGENTS.md`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/AGENTS.md)
- **Changelog & Ingestion History**: [`HISTORY.md`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/HISTORY.md)
- **Human-Facing Documentation**: [`README.md`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/README.md) and [`readme.txt`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/readme.txt)

---

## 3. Quick Repository Metadata

- **Plugin Slug**: `pressvitals-site-auditor`
- **Current Version**: `1.5.1`
- **WordPress.org Directory URL**: [https://wordpress.org/plugins/pressvitals-site-auditor](https://wordpress.org/plugins/pressvitals-site-auditor)
- **SVN Repository URL**: `https://plugins.svn.wordpress.org/pressvitals-site-auditor`
- **Codebase Memory Graph**: `home-merolhack-fl-pressvitals-site-auditor`
- **Local WordPress Environments**:
  - `wp-71`: `http://localhost:8071` (WordPress 7.1 Beta / Bleeding Edge)
  - `wp-latest`: `http://localhost:8083` (WordPress Latest Stable)
  - `wp-mid`: `http://localhost:8081` (WordPress Intermediate)
  - `wp-legacy`: `http://localhost:8074` (WordPress 6.3 Minimum Supported)
