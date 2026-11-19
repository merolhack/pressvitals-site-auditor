# REST API Architecture, Monitoring & CI/CD Integration

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. Endpoint Responsibility Matrix (`/wp-json/pressvitals/v1`)

Following RFC 9110 HTTP status semantics and separating **availability checks** from **diagnostic auditing**, PressVitals Site Auditor exposes three distinct REST routes in [`includes/class-pvsa-rest.php`](file:///ubuntu-20.04/home/merolhack/fl/pressvitals-site-auditor/includes/class-pvsa-rest.php):

| Endpoint | Auth | Purpose | Default HTTP Status | Payload |
| :--- | :--- | :--- | :--- | :--- |
| `GET /ping` | Public (none) | Lightweight liveness probe | Always `200` | Tiny JSON (`ok`, `plugin`, `time`) |
| `GET /health` | Token (`?token=` or `X-PVSA-Token`) or `manage_options` | Uptime monitors (UptimeRobot, BetterStack, load balancers) | `200` healthy; `503` when verdict reaches `fail_on` (`fail` default, or `warn`) | Compact JSON (`ok`, `verdict`, `fail_on`, `fail_count`, `warn_count`, `failing`, `time`) |
| `GET /report` | Token (`?token=` or `X-PVSA-Token`) or `manage_options` | Full diagnostic audit for CI/CD pipelines and dashboards | `200` on pass/warn; `503` on fail (deprecated; `?always_200=1` forces `200`) | Full engine report with all 60+ probes |

### Why `/health` and `/report` Are Separated (RFC 9110 Rationale)
- In production (`dqro.mx`), a single failing probe (e.g. `core_update_available`) caused `/report` to return `HTTP 503 Service Unavailable`.
- Standard HTTP clients (`urllib`, `curl -f`), proxies, and CDNs interpret `503` as an infrastructure outage, discarding the JSON body or triggering false 5xx alerts even though the audit engine succeeded.
- `/health` now owns the `200` / `503` health-check contract for uptime monitors, while `/report` transitions toward always returning `200` with the diagnostic verdict inside the JSON payload.

---

## 2. Diagnostic & Control Headers

Both `/health` and `/report` emit the following HTTP response headers:

- `X-PVSA-Verdict`: `pass` | `warn` | `fail`
- `X-PVSA-Fail-Count`: integer count of probes with status `fail`
- `X-PVSA-Warn-Count`: integer count of probes with status `warn`
- `Cache-Control: no-store`: prevents proxies, CDNs (Cloudflare/Varnish), or browsers from caching live diagnostic output.
- `X-PVSA-Deprecation`: emitted **only** on `/report` when it returns `503` (`report-503-on-fail; use /health for uptime monitoring or add always_200=1`).

---

## 3. Extensibility Filter (`pvsa_report_http_status`)

Introduced in `1.6.0`, the `pvsa_report_http_status` filter allows custom HTTP status mapping on both `/report` and `/health`:

```php
add_filter(
	'pvsa_report_http_status',
	function ( $status, $report, $request, $endpoint ) {
		// Example: always return 200 on /report even without ?always_200=1.
		if ( 'report' === $endpoint ) {
			return 200;
		}
		return $status;
	},
	10,
	4
);
```

**Parameters:**
1. `int $status` — Proposed HTTP status (`200` or `503`).
2. `array $report` — Complete report array returned by `PVSA_Engine::run()`.
3. `WP_REST_Request $request` — Current REST request object.
4. `string $endpoint` — Route identifier (`'report'` or `'health'`).

---

## 4. Integration Patterns

### Uptime Monitors (UptimeRobot / BetterStack / Load Balancers)
```bash
# Alert only on fail verdict (default):
curl -sI "https://example.com/wp-json/pressvitals/v1/health?token=<TOKEN>"

# Alert on warn OR fail verdict:
curl -sI "https://example.com/wp-json/pressvitals/v1/health?token=<TOKEN>&fail_on=warn"
```

### CI/CD Pipelines (GitHub Actions / Python)
```bash
curl -s "https://example.com/wp-json/pressvitals/v1/report?token=<TOKEN>&always_200=1"
```

```python
import json, urllib.request, urllib.error

url = "https://example.com/wp-json/pressvitals/v1/report?token=<TOKEN>&always_200=1"
try:
    resp = urllib.request.urlopen(url, timeout=30)
except urllib.error.HTTPError as e:
    resp = e  # Backward-compatible: 503 still carries the full JSON body

report = json.load(resp)
verdict = resp.headers.get("X-PVSA-Verdict", report.get("verdict"))
failed = [c for c in report.get("checks", []) if c.get("status") == "fail"]
```

---

## 5. Deprecation & Migration Roadmap (1.6.0 → 2.0.0)

1. **v1.5.5:** Added `X-PVSA-Verdict`, `X-PVSA-Fail-Count`, `X-PVSA-Warn-Count`, and `?always_200=1` as a backward-compatible bridge for CI/CD.
2. **v1.6.0 (Current):**
   - Introduced dedicated `GET /health` (`?fail_on=fail|warn`).
   - Added `pvsa_report_http_status` filter and `Cache-Control: no-store`.
   - Marked `/report` returning `503` on `fail` as **deprecated** (signals via `X-PVSA-Deprecation` header).
3. **v2.0.0 (Planned Major):**
   - `/report` defaults to `HTTP 200` regardless of verdict (`401` on bad auth, `5xx` only on genuine server/engine failures).
   - Uptime monitors exclusively use `/health`.
