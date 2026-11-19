# PressVitals Site Auditor

A **headless-first** WordPress diagnostic engine featuring **60+ proactive probes** for
performance, security, and DB health — extensible via REST API and custom filters.

[![PHPUnit](https://github.com/merolhack/pressvitals-site-auditor/actions/workflows/tests.yml/badge.svg)](https://github.com/merolhack/pressvitals-site-auditor/actions/workflows/tests.yml)
[![Code Quality](https://github.com/merolhack/pressvitals-site-auditor/actions/workflows/code-quality.yml/badge.svg)](https://github.com/merolhack/pressvitals-site-auditor/actions/workflows/code-quality.yml)
![WordPress 6.0 - 7.1.2+](https://img.shields.io/badge/WordPress-6.0%20--%207.1.2%2B-blue)
![PHP 7.4 - 8.4](https://img.shields.io/badge/PHP-7.4%20--%208.4-blue)
![License GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-green)

PressVitals runs read-only probes across performance, security, deliverability and
database health, assigns each a severity tier, rolls them up into a worst-of verdict,
and exposes the result where automation can consume it: a token-gated REST report, a
daily cron with email alerts, and a categorized admin dashboard.

## How is it different from core's Site Health?

WordPress core's **Tools → Site Health** is an *on-demand, admin-only* status screen
plus a static debug dump. PressVitals is built for **continuous, automated,
machine-readable monitoring and auditing**:

- **Headless / API-first** — three purpose-built REST endpoints under `/wp-json/pressvitals/v1`:
  - `GET /ping` — no-auth liveness probe (always HTTP `200`).
  - `GET /health?token=TOKEN[&fail_on=fail|warn]` — token-gated uptime-monitor endpoint (UptimeRobot, BetterStack, load balancers). Compact JSON payload; HTTP `200` when healthy, HTTP `503` when the verdict reaches `fail_on` (default: `fail`).
  - `GET /report?token=TOKEN[&always_200=1]` — token-gated full diagnostic JSON report for CI/CD and dashboards. For backward compatibility it still returns HTTP `503` (with the complete JSON body) on a `fail` verdict, along with `X-PVSA-Deprecation`. **Deprecated behaviour:** uptime monitors should migrate to `/health`, and CI/CD pipelines should pass `?always_200=1`; a future `2.0.0` major release will make `/report` return HTTP `200` by default.
  - Both `/health` and `/report` emit `X-PVSA-Verdict`, `X-PVSA-Fail-Count`, `X-PVSA-Warn-Count`, and `Cache-Control: no-store`. Status codes can be overridden via the `pvsa_report_http_status` filter.

  ```bash
  # Uptime monitor (HTTP 503 on fail; add &fail_on=warn to alert on warnings too)
  curl -sI "https://example.com/wp-json/pressvitals/v1/health?token=TOKEN"

  # CI/CD full audit (always HTTP 200; inspect verdict/checks in JSON)
  curl -s "https://example.com/wp-json/pressvitals/v1/report?token=TOKEN&always_200=1"
  ```

  ```python
  import json, urllib.request, urllib.error
  url = "https://example.com/wp-json/pressvitals/v1/report?token=TOKEN"
  try:
      resp = urllib.request.urlopen(url, timeout=30)
  except urllib.error.HTTPError as e:
      resp = e  # 503 still carries the JSON body
  report = json.load(resp)
  failed = [c for c in report["checks"] if c["status"] == "fail"]
  ```
- **Scheduled + alerting** — daily WP-Cron run that emails the admin on a fail verdict.
- **Severity tiers + worst-of verdict** — a single green/red signal.
- **Probes core does not run** — TLS certificate-expiry countdown, PHP micro-patch
  security advisory auditing (CVE-2026-109618 / PHP2Shell), PHP runtime hardening
  (`expose_php`, `disable_functions`, `allow_url_include`), `.env`/secret web
  exposure, a web-root stray-backup scanner, baseline security headers, forced-HTTPS,
  XML-RPC exposure, default-`admin` detection, SPF + DMARC email DNS, homepage
  indexability, OPcache status, cron loopback health, and database-bloat checks.
- **Pluggable + configurable** — register your own probes via `pvsa_registered_checks`
  and tune every threshold with filters.

## Compatibility

No plugin dependencies. Runs on single-site or multisite, **with or without**
WooCommerce, page builders, or a backup plugin. Optional PHP functions are guarded and
degrade to a neutral skip rather than erroring. The backup probe is backup-agnostic —
report any backup solution (plugin, host, or off-site) via `pvsa_last_backup_timestamp`.

## Install

Upload to `wp-content/plugins/`, or **Plugins → Add New → Upload Plugin** with a ZIP,
then activate. Configure at **Tools → PressVitals Site Auditor**.

## Extend it

```php
add_filter( 'pvsa_registered_checks', function ( array $checks ) {
    $checks['my_queue_backlog'] = array(
        'label'    => 'Job queue backlog',
        'group'    => 'Performance',
        'tier'     => 2,
        'callback' => function () {
            $pending = my_count_pending_jobs();
            return $pending > 1000
                ? array( 'status' => 'warn', 'detail' => "$pending jobs pending" )
                : array( 'status' => 'pass', 'detail' => "$pending jobs pending" );
        },
    );
    return $checks;
} );
```

A callback returns `array( 'status' => 'pass'|'warn'|'fail', 'detail' => '…' )`.

## Development

```bash
# Automated tests (Docker + Node) — mirrors CI
npm -g install @wordpress/env
wp-env start
wp-env run tests-cli --env-cwd=wp-content/plugins/pressvitals-site-auditor vendor/bin/phpunit

# …or the classic route
composer install
bin/install-wp-tests.sh wordpress_test root '' localhost
composer test

# Manual multi-version testing — four browsable installs
docker compose up -d
# - WP 7.1 / PHP 8.3  -> http://localhost:8071 (wp-71)
# - WP 7.0 / PHP 8.3  -> http://localhost:8083 (wp-latest)
# - WP 6.4 / PHP 8.1  -> http://localhost:8081 (wp-mid)
# - WP 6.0 / PHP 7.4  -> http://localhost:8074 (wp-legacy)
```

CI runs PHPUnit across PHP 7.4 / 8.0 / 8.2 / 8.3 / 8.4 and WordPress 6.3 through 7.1 + nightly.

## AI Agents / Assistants

> **Are you an AI, LLM, or coding assistant?**
> You **MUST** always consult the Karpathy-style modular wiki ([`wiki/index.md`](wiki/index.md)), [`AGENTS.md`](AGENTS.md), and use the `codebase-memory-mcp` knowledge graph tools (`search_graph`, `trace_path`, `get_code_snippet`) for repository knowledge and architecture constraints before making modifications. Also review the `pressvitals-architecture-rules` and `pressvitals-release-workflow` skills.

## License

[GPL-2.0-or-later](LICENSE).
