=== OmniHealth: Deep Site Auditor ===
Contributors: yourwporgusername
Tags: monitoring, site health, security, rest api, uptime
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A headless-first diagnostic engine featuring 22+ proactive probes for performance, security, and DB health — extensible to 48+ via REST API and custom filters.

== Description ==

OmniHealth: Deep Site Auditor is a **headless-first** diagnostic engine. It runs a
suite of read-only probes across performance, security, deliverability and
database health, assigns each a severity tier, rolls them up into a worst-of
verdict, and exposes the result where automation can actually consume it: a
token-gated REST report, a daily cron with email alerts, and a categorized admin
dashboard.

It is **dependency-free** — no WooCommerce, no page builder, no other plugin
required — and **pluggable**: every probe is registered through a filter, so the
**22+ built-in probes** are just the starting point. The architecture is designed
to scale to **48+ probes** in production; register your own via
`ohsa_registered_checks`.

= How is this different from the built-in Site Health? =

WordPress core's **Tools → Site Health** is excellent, but it is an *on-demand,
admin-only* tool: you open a screen, it runs its status tests, and the Debug tab
prints a static environment dump for support. OmniHealth is built for a different
job — **continuous, automated, machine-readable monitoring and auditing**:

* **Headless / API-first.** Core Site Health has no public report API. OmniHealth
  exposes a no-auth `/ping` liveness probe and a token-gated `/report` JSON
  endpoint (HTTP **503** on a failing verdict) so an external uptime monitor,
  status page, or CI pipeline can read site health without logging in.
* **Scheduled + alerting.** Core never runs on a schedule and never tells you
  when something breaks. OmniHealth runs daily via WP-Cron and **emails the admin
  when the verdict is *fail***.
* **Severity tiers + worst-of verdict.** Core labels results good/recommended/
  critical. OmniHealth assigns each probe a 1–5 severity tier and computes a
  single rolled-up verdict suitable for a green/red status signal.
* **Probes core does not have.** TLS *certificate-expiry countdown* (core only
  checks that HTTPS works today), `.env`/secret-file web exposure, a web-root
  stray-backup scanner, baseline security headers (HSTS / X-Frame-Options / …),
  forced-HTTPS redirect, XML-RPC exposure, default-`admin`-username detection,
  SPF + DMARC email-DNS records, homepage indexability, and database-bloat
  (expired transients / revisions / spam) checks.
* **Pluggable + configurable.** Core's tests are fixed. OmniHealth lets any plugin
  or theme register probes via a filter and tune every threshold via filters.

Think of it as the layer *on top of* Site Health: the same read-only philosophy,
re-pointed at automation, alerting, and security/ops auditing.

= Built-in probes =

OmniHealth ships **25 built-in probes**, grouped by functional category:

* **Availability** — database connectivity, homepage HTTPS reachability.
* **Security** — `.env` not web-accessible (HTTP) and not exposed on disk,
  web-root stray/backup file scan, TLS certificate expiry, baseline security
  headers, forced HTTPS, XML-RPC exposure, no default `admin` user,
  wp-config.php permissions, error-display off.
* **Errors** — error-log size, recent PHP fatal errors.
* **Database** — core tables present, autoloaded-options size, database bloat.
* **Files** — free disk space, uploads-directory writability, recent backup.
* **Email** — SPF + DMARC DNS records for the sending domain.
* **SEO** — homepage is indexable (not noindex).
* **Performance** — PHP memory limit, persistent object cache.
* **Environment** — supported PHP version.

= Extend it with your own checks =

Probes are not hardcoded — the engine collects them from a filter, so any plugin
or theme can register its own:

`
add_filter( 'ohsa_registered_checks', function ( array $checks ) {
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
`

A callback returns `array( 'status' => 'pass'|'warn'|'fail', 'detail' => '…' )`.

= Developer filters =

* `ohsa_registered_checks` — register/override probes.
* `ohsa_setting_{key}` — override a stored threshold at read time.
* `ohsa_alert_email` — change the failure-alert recipient.
* `ohsa_http_timeout`, `ohsa_disk_free_min_bytes`, `ohsa_memory_min_bytes`,
  `ohsa_fatal_lookback_hours`, `ohsa_fatal_scan_max_bytes` — tune environment probes.
* `ohsa_ssl_warn_days`, `ohsa_ssl_fail_days` — TLS expiry thresholds.
* `ohsa_backup_warn_days`, `ohsa_backup_fail_days` — backup-recency thresholds.
* `ohsa_last_backup_timestamp` — report your last successful backup time (UNIX) so
  the backup probe works with *any* backup plugin, host, or off-site service.
* `ohsa_backup_plugins` — list of backup-plugin basenames recognised by presence.
* `ohsa_max_expired_transients`, `ohsa_max_revisions`, `ohsa_max_spam_comments` —
  database-bloat thresholds.
* `ohsa_sending_domain` — domain used for the SPF/DMARC lookup.

= Compatibility =

OmniHealth has **no plugin dependencies** and runs on virtually any WordPress
install — single-site or multisite, **with or without** WooCommerce, page builders,
or a backup plugin. It calls only core WordPress APIs and guards every optional PHP
function (`disk_free_space`, `stream_socket_client`/OpenSSL, `dns_get_record`,
`WP_Filesystem`), degrading a probe to a neutral *pass/skip* when something isn't
available rather than erroring. The backup probe is backup-agnostic: it reads
UpdraftPlus directly, recognises other common backup plugins, and lets any other
backup solution (including host-level backups) report in via
`ohsa_last_backup_timestamp`.

== Installation ==

1. Upload the `omnihealth-site-auditor` folder to `/wp-content/plugins/`, or install
   it from the Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Tools → OmniHealth: Deep Site Auditor** to view the report and configure
   thresholds.
4. (Optional) Copy the probe URL shown on that page into your external uptime or
   CI monitor.

== Frequently Asked Questions ==

= Does it replace the built-in Site Health? =

No — it complements it. Site Health is the on-demand, admin-facing status screen;
OmniHealth adds headless REST reporting, scheduled monitoring with email alerts,
severity tiers, and security/ops audit probes that core does not run.

= Does it require WooCommerce or any other plugin? =

No. It runs on any standard WordPress install with no dependencies.

= Is the report endpoint safe to expose? =

`/report` requires either a logged-in administrator or a secret token compared
with `hash_equals()`. Rotate the token any time from the settings page. `/ping`
returns only a tiny liveness payload with no sensitive data.

= Does it write to my database or files? =

The probes are read-only. The plugin stores only its own options (settings, last
report, token) and reads log files solely from standard WordPress locations.

= Is it tested? =

Yes. It ships with a PHPUnit suite built on the WordPress core test framework
(`WP_UnitTestCase`) covering the check registry, the worst-of verdict aggregation,
defensive execution of a throwing/malformed check, the settings filters, the
backup-recency filter, and the REST routes (public `/ping`, token-gated `/report`,
and the 503-on-fail behaviour). Tests run in CI and locally via `composer test`
(see `bin/install-wp-tests.sh`); they are excluded from the distributed package and
never run on a live site. Activation performs only a lightweight requirements gate
(PHP version + engine load), not the test suite.

== Development ==

Two local workflows are scaffolded (both Docker-based; neither ships in the
package):

**Automated tests — wp-env (recommended):** requires Docker + Node.js.
`npm -g install @wordpress/env`, then `wp-env start` and
`wp-env run tests-cli --env-cwd=wp-content/plugins/omnihealth-site-auditor vendor/bin/phpunit`.
Switch versions by editing `core` / `phpVersion` in `.wp-env.json` and running
`wp-env start --update`. Without Docker, run the suite the classic way:
`composer install`, `bin/install-wp-tests.sh wordpress_test root '' localhost`,
`composer test`. A GitHub Actions workflow runs PHPUnit across PHP
7.4 / 8.0 / 8.2 / 8.3.

**Manual multi-version testing — docker-compose:** `docker compose up -d` boots
three browsable installs at fixed WordPress x PHP combos (WP 6.7/PHP 8.3,
WP 6.4/PHP 8.1, WP 6.3/PHP 7.4) on ports 8083 / 8081 / 8074, each with the plugin
mounted. See `docker-compose.yml` for details.

== Screenshots ==

1. The admin report grouped by category with the summary box.
2. The settings page (thresholds and alert email).

== Changelog ==

= 1.1.0 =
* Add 3 security/database probes: `.env` file detected on disk (with permission
  check), wp-config.php permissions, and core database tables present. Total
  built-in probes: 25.

= 1.0.0 =
* Initial release: pluggable probe engine (`ohsa_registered_checks` filter), 22
  built-in probes across performance, security, deliverability and database
  health, categorized admin report with summary box, daily cron with email
  alerts, severity tiers with a worst-of verdict, and `/ping` + token-gated
  `/report` REST endpoints.

== Upgrade Notice ==

= 1.1.0 =
Adds three new security/database probes (.env on disk, wp-config.php permissions,
core tables present).

= 1.0.0 =
Initial release.
