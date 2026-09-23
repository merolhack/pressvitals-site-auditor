# Tools, Operations & Testing Playbooks

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. Codebase Knowledge Graph (`codebase-memory-mcp`)

This project integrates `codebase-memory-mcp` for structural codebase queries.

> [!IMPORTANT]
> **Code Discovery Priority Order:**
> BEFORE reading large source files or performing global `grep` searches across the repository, agents **MUST** query the knowledge graph first using MCP tools:
> 1. `search_graph` — Find functions, classes, routes by name pattern (e.g. `search_graph(project="home-merolhack-fl-pressvitals-site-auditor", name_pattern=".*check_.*")`).
> 2. `trace_path` — Trace caller/callee relationships.
> 3. `get_code_snippet` — Fetch exact source code for a symbol by `qualified_name`.
> 4. `query_graph` — Run Cypher queries for complex structural dependencies.

### WordPress File Inspection & Live Site Analysis
- **DO NOT USE WP FILE MANAGER** in the WordPress browser admin to inspect files.
- All WordPress core, plugin, and theme files from the live environment (Freelance México) are available locally in the synchronized repository:
  - Local Path: `\\wsl$\Ubuntu-20.04\home\merolhack\fl\freelancemexico` (or `/home/merolhack/fl/freelancemexico` in WSL).
- To inspect code structures, functions, and plugin architecture of the live site, query the codebase memory knowledge graph directly with:
  - Project: `home-merolhack-fl-freelancemexico` (e.g. `search_graph(project="home-merolhack-fl-freelancemexico", name_pattern=".*")`).

---

## 2. Environment & Docker Operations

Running Composer or PHPCS directly in the raw WSL environment can fail due to PHP extension discrepancies. Always use the provided multi-version Docker containers:

### Docker Testing Commands
```bash
# Run PHPUnit test suite inside bleeding-edge container
docker compose exec wp-71 vendor/bin/phpunit

# Run PHPUnit test suite inside current stable container
docker compose exec wp-latest vendor/bin/phpunit

# Run PHPCS checks (WordPress-Core ruleset)
docker compose exec wp-latest vendor/bin/phpcs
```

### Git Push Authentication Constraint
The local terminal environment can hang indefinitely on interactive credential prompts. Always use the explicit PAT URL when pushing to GitHub:
```bash
git push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git main
```

---

## 3. Testing & CI/CD Pipeline

### Local Unit Testing (PHPUnit)
- Tests reside in `tests/test-engine.php`.
- **Mocking External HTTP**: Do **NOT** make live external network requests for probes such as `check_security_headers`, `check_stray_files`, or `check_homepage_indexable`. Always use WordPress core's `pre_http_request` filter to mock HTTP responses deterministically.

### Continuous Integration Workflows
Located in `.github/workflows/`:
1. `tests.yml`: Runs PHPUnit across WordPress matrix versions (6.3 through latest).
2. `code-quality.yml`: Executes PHPCS and official `wordpress/plugin-check-action@v1`.
3. `deploy.yml`: Pushes tags to WordPress.org SVN upon GitHub Release publication.
