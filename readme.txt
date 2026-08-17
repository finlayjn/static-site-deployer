=== Static Site Deployer ===
Contributors: finlayjn
Tags: static, cloudflare, simply static, deployment, serverless
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your site with Simply Static and deploy it to Cloudflare Workers static assets — automatically on save or on demand.

== Description ==

Static Site Deployer runs a [Simply Static](https://wordpress.org/plugins/simply-static/)
export and uploads the result to a Cloudflare Worker's static assets, giving you
free, serverless static hosting for a WordPress site you edit locally (for
example in Local WP or WordPress Playground).

It re-implements the "publish to Cloudflare" idea of Simply Static Pro for free.

= Features =

* Deploy automatically when a post is created, updated, or deleted — or switch to manual "Publish now".
* Settings page for Cloudflare credentials (no need to edit wp-config.php).
* Optional cleanup of the local export directory after a successful deploy to save disk space.
* Uses the WordPress HTTP API (works without the cURL extension, honors proxies).
* Keeps credentials out of WordPress Playground exports (see FAQ).

== Installation ==

1. Install and activate the free Simply Static plugin.
2. Install and activate Static Site Deployer.
3. Create a Cloudflare API token with the **Workers Scripts: Edit** permission.
4. Create a Cloudflare Worker (the Hello World template is fine).
5. Go to **Settings → Static Site Deployer** and enter your Account ID, Worker name, and API token.

Credentials can alternatively be defined in wp-config.php, which always takes
precedence and is never stored in the database:

`define( 'SSD_CLOUDFLARE_ACCOUNT_ID', '...' );`
`define( 'SSD_CLOUDFLARE_API_TOKEN', '...' );`
`define( 'SSD_CLOUDFLARE_SCRIPT_NAME', '...' );`

== Frequently Asked Questions ==

= How do I keep my Cloudflare token out of a WordPress Playground export? =

The plugin stores its settings in a single option (`ssd_settings`) and registers
it with the WordPress to Playground exporter's `wp2p_excluded_option_names`
filter, so the token is never written to the exported database. After importing a
Playground archive, re-enter your credentials on the settings page (or provide
them via wp-config constants that are not committed).

= Nothing deploys on save. =

Check that Simply Static is active, credentials are set, and "Auto-publish" is
enabled. With auto-publish off, use the "Publish now" button.

== Changelog ==

= 0.1.0 =
* Settings page for Cloudflare credentials (with wp-config constant override).
* Manual "Publish now" mode in addition to auto-publish on save.
* Optional cleanup of the export directory after a successful deploy.
* Switched Cloudflare requests to the WordPress HTTP API with timeouts.
* Excludes credentials from WordPress Playground exports.

== Upgrade Notice ==

= 0.1.0 =
Adds a settings page, manual publish mode, cleanup, and hardening.
