# OmniHealth: Deep Site Auditor — Roadmap / TODO

Planned probes and engineering work. Probes follow the existing pattern: register
through the `ohsa_registered_checks` filter with `label` / `group` / `tier` (1 = most
critical … 5 = informational) / `callback` returning
`array( 'status' => 'pass'|'warn'|'fail', 'detail' => '…' )`. Keep every check
read-only, guarded (skip gracefully when a dependency is missing), PCP-clean
(prepared/literal `$wpdb`, full escaping, nonces + capability checks), and i18n-ready.

Legend: **P1** ship next · **P2** soon · **P3** nice-to-have.

---

## Already shipped (for reference — do NOT re-add)

22 built-in probes across Availability / Security / Errors / Database / Files / Email /
SEO / Performance / Environment, including: `db_connection`, `https_home`,
`env_file_exposed` (HTTP probe), `stray_files`, `ssl_cert_expiry`, `security_headers`,
`https_forced`, `xmlrpc_status`, `admin_username`, `debug_display_off`,
`error_log_size`, `php_fatal_errors_recent`, `autoloaded_options_size`, `db_overhead`,
`disk_free`, `uploads_writable`, `backup_recency` (filterable), `email_dns`
(SPF + DMARC), `homepage_indexable`, `memory_limit`, `object_cache`, `php_version`.

---

## New probes — Security

- [ ] **P1 `env_file_on_disk`** — complement the existing HTTP probe: detect a `.env`
  in `ABSPATH` (and one level up) on the filesystem; warn if present and **fail** if it
  is also world-readable or served over HTTP. Covers CLI/headless contexts where the
  HTTP probe can't reach. (group: Security, tier 1)
- [ ] **P1 `wp_config_permissions`** — `wp-config.php` should not be world-readable
  (warn on `0644`+, recommend `0640`/`0600`). Use `WP_Filesystem`/`fileperms()`. (tier 1)
- [ ] **P1 `secret_keys_defined`** — `AUTH_KEY`/`SECURE_AUTH_KEY`/… are defined and not
  the literal `put your unique phrase here` placeholders. (tier 2)
- [ ] **P2 `file_editing_disabled`** — recommend `DISALLOW_FILE_EDIT` (and flag
  `DISALLOW_FILE_MODS` awareness). (tier 3)
- [ ] **P2 `user_enumeration_blocked`** — `?author=1` does not 301 to `/author/<login>/`
  and `GET /wp-json/wp/v2/users` doesn't leak logins to anonymous requests. (tier 3)
- [ ] **P2 `directory_listing_off`** — a known directory (e.g. `/wp-content/uploads/`)
  does not return an Apache/nginx autoindex. (tier 3)
- [ ] **P2 `force_ssl_admin`** — `FORCE_SSL_ADMIN` is on when the site is HTTPS. (tier 3)
- [ ] **P3 `debug_log_not_public`** — `wp-content/debug.log` returns 403/404, not 200.
- [ ] **P3 `login_protection_present`** — heuristic: a limit-login / 2FA / firewall
  plugin is active (filterable allow-list), else informational warn.
- [ ] **P3 `file_permissions_sane`** — spot-check dirs `0755` / files `0644` for
  `wp-content` (sampled, not a full walk).

## New probes — Database (base + extra tables)

- [ ] **P1 `core_tables_present`** — every base `$wpdb` table exists
  (`posts`, `postmeta`, `options`, `users`, `usermeta`, `terms`, `term_taxonomy`,
  `term_relationships`, `comments`, `commentmeta`, plus multisite `blogs`/`site` when
  applicable). **fail** on any missing. (group: Database, tier 1)
- [ ] **P2 `orphaned_tables`** — list non-core tables whose prefix-stripped name maps to
  no active plugin (heuristic, filterable allow-list via `ohsa_known_tables`); warn with
  count + total size so leftovers from removed plugins are visible. (tier 4)
- [ ] **P2 `table_storage_engine`** — flag any MyISAM tables (recommend InnoDB). (tier 4)
- [ ] **P2 `table_collation`** — flag tables not on `utf8mb4` (mojibake / emoji risk). (tier 4)
- [ ] **P3 `largest_tables`** — report the top N tables by size + total DB size, with a
  filterable warn threshold. (informational, tier 5)
- [ ] **P3 `db_charset_client`** — DB connection charset is `utf8mb4`.

## New probes — Updates & Versioning

- [ ] **P1 `core_update_available`** — WP core has a pending update; **fail** if it is a
  security/minor release, warn otherwise. (headless complement to core Site Health;
  group: Environment, tier 2)
- [ ] **P1 `plugin_updates_pending`** — count of plugins with updates; warn, and **fail**
  if any are flagged as security updates. (tier 2)
- [ ] **P2 `theme_updates_pending`** — same for themes. (tier 3)
- [ ] **P2 `inactive_plugins_themes`** — many deactivated plugins/themes = dormant attack
  surface; informational warn over a filterable threshold. (tier 4)
- [ ] **P3 `php_eol_horizon`** — extend `php_version` to warn ahead of the runtime's
  official EOL date, not just against fixed cutoffs.

## New probes — Performance / Ops

- [ ] **P2 `cron_overdue`** — any WP-Cron event overdue by > N minutes (DISABLE_WP_CRON
  awareness); warn. (group: Performance, tier 3)
- [ ] **P2 `transient_api_backed`** — detect transients silently falling back to the DB
  when a persistent object cache is expected. (tier 4)
- [ ] **P3 `https_mixed_content`** — homepage HTML has no `http://` asset references on an
  HTTPS site. (group: SEO/Security, tier 3)
- [ ] **P3 `rest_api_reachable`** — `GET /wp-json/` responds 200 (headless availability).

---

## Engineering / release

- [ ] **Versioning discipline** — keep `Version:` header, the `OHSA_VERSION` constant,
  readme `Stable tag`, and the `== Changelog ==` entry in sync; add a short
  `RELEASE.md` checklist (and/or a `bin/bump-version.sh`). Follow SemVer.
- [ ] **Settings/data versioning + migrations** — store an `ohsa_db_version` option and
  run an idempotent upgrade routine on `plugins_loaded` when it lags `OHSA_VERSION`
  (re-seed/migrate settings safely instead of relying only on the activation hook).
- [ ] **WordPress.org release** — add repo secrets `SVN_USERNAME` / `SVN_PASSWORD`,
  submit the slug for review, then tag a release so `deploy.yml` ships to SVN.
- [ ] **GitHub-sideload updates (optional)** — bundle `yahnis-elsts/plugin-update-checker`
  so installs sideloaded from GitHub get update notifications before the wp.org listing
  exists. Remove once on wp.org.
- [ ] **WP.org assets** — add `/.wordpress-org/` banner (1544×500), icon (256×256), and
  real screenshots referenced by `== Screenshots ==`.

## i18n

- [ ] Ship at least one locale (`es_ES` / `es_MX`) `.po`/`.mo` to prove the pipeline.
- [ ] Add a `composer make-pot` script (wp-cli `i18n make-pot`) to replace the bespoke
  PHP extractor once wp-cli is available in CI.

## Testing / CI

- [ ] **Per-probe unit tests** — extend coverage to the network/DB probes using
  `pre_http_request` mocks and `$wpdb` fixtures (security headers, https_forced,
  stray_files, db_overhead, core_tables_present).
- [ ] **WP version matrix** — add older WP majors (6.3 … 6.7) alongside the PHP matrix.
- [ ] **PHPCS (WordPress-Coding-Standards) + Plugin Check (PCP)** as CI gates.
- [ ] Bump `actions/checkout` and other actions off the Node-20 deprecation warning.

## Admin / UX

- [ ] Per-group "Run now" + last-run timestamp; remember collapsed groups.
- [ ] Optional Slack/webhook alert channel alongside email (`ohsa_alert_channels`).
- [ ] CSV/JSON export of the latest report from the admin page.
- [ ] Surface each probe's `tier` and `duration_ms` in the report table.
