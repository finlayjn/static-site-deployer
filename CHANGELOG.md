# Changelog

All notable changes to this project are documented here. This project adheres
to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-08-17

### Added

- Settings page under **Settings → Static Site Deployer** for Cloudflare
  credentials, with wp-config constant overrides that take precedence.
- Manual **Publish now** mode as an alternative to auto-publishing on save.
- Admin notice when auto-publish is on but Cloudflare credentials are missing;
  such saves skip the export instead of running one that cannot deploy (e.g.
  after importing a Playground archive without the token).
- Optional cleanup of the local export directory (and leftover export zips)
  after a successful deploy.
- Last-deploy status shown on the settings page.
- Exclusion of the stored API token from WordPress Playground exports via the
  `wp2p_excluded_option_names` filter. The token lives in its own
  `ssd_api_token` option; other settings travel with the archive.
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
- Corrected the composer PSR-4 namespace mapping.
