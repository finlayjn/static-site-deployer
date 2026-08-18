# Changelog

All notable changes to this project are documented here. This project adheres
to [Semantic Versioning](https://semver.org/).

## [0.4.0] - 2026-08-18

### Fixed

- **Private and draft content no longer leaks into crawler exports.** The
  browser crawler runs inside the admin session, and in WordPress Playground the
  in-browser server keeps that session across `fetch` calls regardless of
  `credentials`, so private posts, drafts, and the admin bar were being rendered
  into exported pages, archives, and feeds. The crawler now marks every fetch
  with an `X-SSD-Export` header, and the plugin forces a logged-out render for
  those requests (via `determine_current_user`), matching the guest view a real
  static host would serve. Admin-ajax calls (seed list, result recording) never
  carry the marker and stay authenticated.
- **Redirects no longer corrupt the export.** The crawler stored fetched bytes
  under the *requested* path, so a redirect — e.g. a redirect plugin sending `/`
  to a post — overwrote the wrong file (turning a private post into the exported
  homepage). Output is now keyed by the **final** URL after redirects; redirects
  into excluded or off-site URLs are recorded as errors instead of silently
  clobbering a file.

### Changed

- The API Token field now explains that the "ask each time" (blank token) flow
  only works with the built-in crawler; Simply Static deploys server-side and
  needs a stored token (or the `SSD_CLOUDFLARE_API_TOKEN` constant).

## [0.3.0] - 2026-08-18

### Added

- **Built-in browser crawler** export source that renders the site to static
  files client-side using same-origin `fetch`. It needs no Simply Static and no
  server-side loopback, so it works inside WordPress Playground. Crawl fidelity
  includes URL rewriting across attributes, `srcset`, inline CSS `url()`, and
  image meta tags; origin-to-relative rewriting across inline scripts, JSON-LD,
  and XML; sitemap/`robots.txt` seeding; XML feeds saved as `index.xml` with an
  `index.html` redirect stub; a generated `404.html`; and default exclusions for
  `wp-admin`, `wp-json`, and search feeds.
- **Direct Cloudflare deploy from the browser**, via a small CORS relay Worker
  (`assets/crawler/worker/`) that forwards to the Cloudflare API and stores no
  credentials. Set its URL under **Cloudflare API relay URL**.
- **Export source selector** (Automatic / Built-in crawler / Simply Static) with
  an install-aware default. WordPress Playground always uses the crawler.
- **Publish to Cloudflare** button in the admin toolbar, showing live percent.
- Live-updating deployment status **and** history on the settings page (no
  page refresh).
- Auto-publish for the crawler: a content change queues a deploy that runs in the
  block editor on save (or on the next admin page load). While it runs, saving is
  locked until the deploy finishes.
- Optional, unstored API token: leave the token blank to be prompted once per
  browser session at publish (nothing is stored), plus a **Clear stored token**
  control.
- **Download ZIP (debug)** of the crawler output for local inspection.

### Changed

- Deployment is now source-agnostic: the deployer accepts a rendered directory
  from any export source (Simply Static, or the built-in crawler).

### Fixed

- Auto-publish no longer triggers spurious deploys during plugin/theme/core
  updates or on internal post types (revisions, menu items, Action Scheduler,
  etc.).

### Security

- The Cloudflare API token is never enqueued on the front end — only on admin
  pages — so a logged-in crawl cannot bake it into exported HTML. The crawler
  also strips the admin bar and any plugin scripts from crawled pages as
  defense-in-depth.

## [0.2.0] - 2026-08-17

### Added

- Settings page under **Settings → Static Site Deployer** for Cloudflare
  credentials, with wp-config constant overrides that take precedence.
- Manual **Publish now** mode as an alternative to auto-publishing on save.
- Deployment **status panel** on the settings page with a progress bar and the
  latest step message, polled over AJAX during a deploy.
- Deployment **history** (last 20 results) shown on the settings page.
- Synchronous export mode (auto-enabled in WordPress Playground) that forces
  Simply Static's inline processing, fixing an endless background-dispatch loop
  where the export never completed on hosts without working loopback requests.
- Admin notice when auto-publish is on but Cloudflare credentials are missing;
  such saves skip the export instead of running one that cannot deploy (e.g.
  after importing a Playground archive without the token).
- Optional cleanup of the local export directory (and leftover export zips)
  after a successful deploy.
- Last-deploy status shown on the settings page.
- Exclusion of the stored API token from WordPress Playground exports via the
  `wp2p_excluded_option_names` filter. The token lives in its own
  `ssd_api_token` option; other settings travel with the archive.
- Migration that moves a token stored by older versions in the shared
  `ssd_settings` option into the dedicated `ssd_api_token` option and strips it
  from the exportable settings.
- Build script (`bin/build.sh`) that bundles production dependencies.
- Unit tests for the MIME helper, folder helper, and manifest builder.

### Changed

- Cloudflare API calls now use the WordPress HTTP API with timeouts instead of
  raw cURL.
- Restructured around a `Plugin` bootstrap with a PSR-4 `SSD\` namespace and a
  self-contained autoloader.
- Renamed `src/CloudflareDeployer.php` to `src/CloudflareAssetsDeployer.php` to
  match its class.

### Fixed

- Hardened export-directory detection and 404 asset renaming.

### Removed

- The `symfony/mime` runtime dependency. `MimeHelper` uses its built-in type map
  with a PHP `fileinfo` fallback, so the plugin is now dependency-free — no
  composer or `vendor/` required.
