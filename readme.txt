=== OmniHealth: Deep Site Auditor ===
Contributors: yourwporgusername
Tags: monitoring, site health, security, rest api, uptime
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
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

OmniHealth ships **22 built-in probes**, grouped by functional category:

* **Availability** — database connectivity, homepage HTTPS reachability.
* **Security** — `.env` not web-accessible, web-root stray/backup file scan, TLS
  certificate expiry, baseline security headers, forced HTTPS, XML-RPC exposure,
  no default `admin` user, error-display off.
* **Errors** — error-log size, recent PHP fatal errors.
* **Database** — autoloaded-options size, database bloat.
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
* `ohsa_max_expired_transients`, `ohsa_max_revisions`, `ohsa_max_spam_comments` —
  database-bloat thresholds.
* `ohsa_sending_domain` — domain used for the SPF/DMARC lookup.

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

== Screenshots ==

1. The admin report grouped by category with the summary box.
2. The settings page (thresholds and alert email).

== Changelog ==

= 1.0.0 =
* Initial release: pluggable probe engine (`ohsa_registered_checks` filter), 22
  built-in probes across performance, security, deliverability and database
  health, categorized admin report with summary box, daily cron with email
  alerts, severity tiers with a worst-of verdict, and `/ping` + token-gated
  `/report` REST endpoints.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
