# PressVitals Site Auditor - Agent Schema

This document defines the schema and operating rules for any LLM agent interacting with the PressVitals Site Auditor project. It follows the principles of the [LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f).

> [!IMPORTANT]
> **MANDATORY RULE FOR ALL AGENTS:** Always consult and use the LLM Wiki ([LLM_WIKI.md](LLM_WIKI.md), [index.md](index.md), [AGENTS.md](AGENTS.md), and [HISTORY.md](HISTORY.md)) as the single source of truth for repository knowledge, architecture constraints, translation standards, and environment workflows.

> [!CAUTION]
> **MANDATORY DEPLOYMENT RULE:** 
> Whenever ANY code change or version bump is performed, the agent **MUST** complete the full release lifecycle to publish the update to WordPress.org Plugins via GitHub Actions and SVN:
> 1. Run Docker test suite (`wp-71`, `wp-latest`) & verify PHPCS is 100% clean (0 errors, 0 warnings).
> 2. Synchronize versions in `pressvitals-site-auditor.php` (header + `PVSA_VERSION`) and `readme.txt` (`Stable tag:`).
> 3. Generate production `.zip` via Python `shutil.make_archive` with `.distignore`.
> 4. Commit and push `main` to GitHub using PAT URL.
> 5. Create and **publish a GitHub Release** (`gh release create <version> --title "<version>" --notes "<notes>"`).
> 6. Verify GitHub Actions workflow (`deploy.yml`) runs and deploys trunk + `tags/<version>` to WordPress.org SVN.
> 7. Confirm deployment with `curl -s https://plugins.svn.wordpress.org/pressvitals-site-auditor/tags/`.
> **NEVER leave code modifications or version bumps unpushed or unreleased on GitHub / SVN.**

---

# Codebase Context & Tool Usage Rules

1. **Prioritize Knowledge Graph:** 
   - ALWAYS query `codebase-memory` MCP tools (`search_graph`, `query_graph`, `trace`, `impact_analysis`) BEFORE reading large source files or performing global greps.
   - Use `index_status` and `detect_changes` to verify project graph freshness before starting structural tasks.

2. **LLM Wiki & Architecture Alignment:**
   - Always cross-reference architectural decisions with `LLM_WIKI.md` and validate code relationships using graph node signatures.

3. **Post-Task Sync:**
   - After completing edits, run `detect_changes` to ensure the codebase graph reflects all newly created or modified functions/routes.

---

## Wiki Architecture
This repository implements the LLM-Wiki structure to manage project knowledge alongside code:
1.  **[index.md](index.md)**: The content catalog. Agents must read this to understand the layout of the repository and the wiki.
2.  **[LLM_WIKI.md](LLM_WIKI.md)**: The central knowledge base detailing architecture, probe registry (45 probes), localization rules, PHPCS formatting, and local environment quirks.
3.  **[HISTORY.md](HISTORY.md)**: Acts as the chronological `log.md`. Every significant session, ingestion, or structural change must be appended here.
4.  **[AGENTS.md](AGENTS.md)**: This file. It is the schema that dictates agent behavior.

---

## Operating Procedures
When answering questions, building features, or fixing bugs, agents MUST follow this workflow:

1.  **Consult the LLM Wiki:** Review [LLM_WIKI.md](LLM_WIKI.md) for architectural constraints (e.g., how the 4 Docker environments function, WP.org translation rules, direct access protection, probe structure).
2.  **Query Codebase Memory First:** Before reading large files or grepping across the repository, use `codebase-memory` MCP tools (`search_graph`, `trace_path`, `get_code_snippet`, `query_graph`) to inspect the knowledge graph.
3.  **Analyze the Log:** Check [HISTORY.md](HISTORY.md) to see recent changes and ensure you don't overwrite past bugfixes or architectural improvements.
4.  **Execute Safely:**
    *   Do not test changes by running `composer` in raw WSL environment; use Docker containers (`docker compose exec wp-71 vendor/bin/phpunit` or `wp-latest` and `vendor/bin/phpcs`).
    *   Be aware of `chmod` and file permission differences between WSL and Docker volume mounts.
5.  **Update the Wiki & History:** After solving a problem or making a significant code change, append a record of the change to `HISTORY.md`. If a new architectural rule is discovered, integrate it into `LLM_WIKI.md`.
6.  **Publish Release to WordPress.org via GitHub Actions & SVN:** 
    *   Build the release ZIP using Python `shutil.make_archive` with `.distignore`.
    *   Verify PHPCS passes with 0 errors/warnings.
    *   Commit, push to `main` with explicit PAT URL.
    *   Tag and publish GitHub Release (`gh release create <version>`).
    *   Confirm automated SVN deployment completes in GitHub Actions.
