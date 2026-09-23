# WordPress.org SVN & Release Deployment Workflow

> Part of the [PressVitals Site Auditor LLM Wiki](index.md).

## 1. WordPress.org Plugin Repository Details

- **SVN Repository URL**: `https://plugins.svn.wordpress.org/pressvitals-site-auditor`
- **Public Directory URL**: `https://wordpress.org/plugins/pressvitals-site-auditor`
- **SVN Username**: `merolhack` (case-sensitive)
- **SVN Credentials**: Configured via GitHub repository secrets (`SVN_USERNAME` and `SVN_PASSWORD`) for automated GitHub Actions releases.
- **Deployed SVN Tags**:
  - `tags/1.2.6/` (Initial public directory release)
  - `tags/1.3.0/` (40 probes, performance, index health audits)
  - `tags/1.4.0/` (45 probes, WP 7.1 readiness, OPcache, debug.log audit)
  - `tags/1.5.0/` (50 probes, maintenance mode stuck, dev mode, env type, db prefix, uploads PHP hardening)

### Directory Assets & Caching Delays
- **Directory Assets (`.wordpress-org/`)**: Banner and icon images have a **~72-hour CDN propagation delay** on `ps.w.org`.
- **WordPress Core Update-Check API Delay**:
  - While the Plugin Information endpoint (`api.wordpress.org/plugins/info/1.2/`) updates immediately upon tag release, the WordPress Core update notification check (`api.wordpress.org/plugins/update-check/1.1/`) uses an internal Redis/Varnish cache layer with an indexing delay of **15 to 60 minutes** before live WordPress dashboards display the update banner.

---

## 2. Mandatory Release Policy & 9-Step Workflow

> [!CAUTION]
> **CRITICAL RELEASE POLICY:**
> Any change of code and version **MUST** be deployed to WordPress.org Plugins via GitHub Actions and SVN. Never leave code changes unreleased or unverified.

Follow these 9 steps sequentially whenever a release is prepared:

### Step 1: Version Synchronization
Synchronize versions identically in:
- `pressvitals-site-auditor.php` (`Version: X.Y.Z` in header and `PVSA_VERSION` constant definition)
- `readme.txt` (`Stable tag: X.Y.Z`)

### Step 2: Documentation Updates
- Update `readme.txt` changelog section.
- Append session entry to [`HISTORY.md`](../HISTORY.md).
- Update [`wiki/`](index.md) if new probes, architectural changes, or release tags were introduced.

### Step 3: Distribution Archive Generation
Generate the clean production `.zip` using Python `shutil.make_archive` honoring exclusions in `.distignore`. Never include git history, tests, docker files, or dev tooling in the release ZIP.

### Step 4: Code Quality & Testing Validation
Run tests and standards checking inside the local Docker containers:
```bash
docker compose exec wp-71 vendor/bin/phpunit
docker compose exec wp-latest vendor/bin/phpcs
```
*Requirement: 100% PHPUnit pass, 0 errors, 0 warnings in PHPCS.*

### Step 5: Container Smoke Testing
Confirm container health and test UI rendering across all 4 local WordPress instances (`wp-71`, `wp-latest`, `wp-mid`, `wp-legacy`).

### Step 6: Git Commit
Stage and commit changes cleanly:
```bash
git add .
git commit -m "chore(release): bump version to X.Y.Z and sync documentation"
```

### Step 7: Git Push & Tagging
Push commits and tags to GitHub using the explicit Personal Access Token (PAT) URL to prevent interactive terminal hangs:
```bash
git push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git main
git push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git <version>
```

### Step 8: Publish GitHub Release (Triggers Automated SVN Deploy)
Create and publish a GitHub Release with matching tag:
```bash
gh release create <version> --title "<version>" --notes "<release notes>"
```
This triggers the `deploy` job in `.github/workflows/deploy.yml` (`10up/action-wordpress-plugin-deploy`), which syncs assets to `/assets/`, code to `/trunk/`, and commits `/tags/<version>/` to the WordPress.org SVN.

### Step 9: Verify SVN Tags & Both WordPress.org APIs
1. **SVN Tag Public Status**:
   ```bash
   curl -s https://plugins.svn.wordpress.org/pressvitals-site-auditor/tags/
   ```
   Confirm `/tags/<version>/` exists.
2. **WordPress.org Plugin Information API**:
   ```bash
   curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=pressvitals-site-auditor"
   ```
   Confirm `version` equals `<version>`, `download_link` serves the `<version>.zip`, and `versions` includes the tag.
3. **WordPress Core Update-Check API**:
   Test payload to `https://api.wordpress.org/plugins/update-check/1.1/` to observe broadcast state.
