=== Static Site Deployer ===
Contributors: finlayjn
Tags: static, cloudflare, simply static, deployment, serverless
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Render your site to static files (built-in crawler or Simply Static) and deploy to Cloudflare Workers static assets — automatically on save or on demand.

== Description ==

Static Site Deployer renders your WordPress site to static files and uploads the
result to a Cloudflare Worker's static assets, giving you free, serverless static
hosting for a site you edit locally (for example in Local WP or WordPress
Playground).

It can render the site two ways: a **built-in browser crawler** that needs no
other plugin and works even in WordPress Playground, or an existing
[Simply Static](https://wordpress.org/plugins/simply-static/) export on a normal
server. Either way it re-implements the "publish to Cloudflare" idea of Simply
Static Pro for free.

= Features =

* Built-in browser crawler — renders the site client-side, no server loopback, works in WordPress Playground.
* Or deploy an existing Simply Static export on a normal server.
* Direct deploy to Cloudflare Workers static assets (via a small CORS relay Worker you deploy once).
* Choose the export source, or let the plugin pick automatically per environment.
* Deploy automatically on create/update/delete — or manually with "Publish now" (button or admin toolbar).
* Live deployment status and history on the settings page.
* Store the Cloudflare token, or leave it blank to be prompted once per session (nothing stored).
* Keeps credentials out of WordPress Playground exports and off the front end.

== Installation ==

1. Install and activate Static Site Deployer. (The built-in crawler needs no other plugin. To deploy Simply Static output instead, also install Simply Static.)
2. Create a Cloudflare API token with the **Workers Scripts: Edit** permission.
3. Create a Cloudflare Worker (the Hello World template is fine).
4. Deploy the CORS relay Worker in `assets/crawler/worker/` (see its README) and copy its URL.
5. Go to **Settings → Static Site Deployer** and enter your Account ID, Worker name, relay URL, and (optionally) API token.

Credentials can alternatively be defined in wp-config.php, which always takes
precedence and is never stored in the database:

`define( 'SSD_CLOUDFLARE_ACCOUNT_ID', '...' );`
`define( 'SSD_CLOUDFLARE_API_TOKEN', '...' );`
`define( 'SSD_CLOUDFLARE_SCRIPT_NAME', '...' );`

== Frequently Asked Questions ==

= How do I keep my Cloudflare token out of a WordPress Playground export? =

The API token is stored in its own option (`ssd_api_token`), which the plugin
registers with the WordPress to Playground exporter's `wp2p_excluded_option_names`
filter, so exports made with that plugin never include the token. Your other
settings (account ID, worker name, toggles) travel with the archive; re-enter
just the token after importing.

Important: Playground's own built-in "Download as .zip" export is a separate
feature that does not apply this filter and will include the whole database,
including the token. When committing an archive to a public repository, export
with the WordPress to Playground plugin (not Playground's native download), or
provide the token via a wp-config constant you do not commit. Also make sure the
WordPress to Playground plugin is up to date — the exclusion filter is only
applied by recent versions.

= Nothing deploys on save. =

Check that credentials (or at least the account ID, worker name, and relay URL)
are set and "Auto-publish" is enabled. With the built-in crawler, an auto-publish
runs in your browser — open the editor (it deploys on save) or the settings page
to let a queued deploy run. With auto-publish off, use the "Publish now" button.

== Changelog ==

= 0.5.2 =
* Fixed a fatal "undefined method" error on admin pages when auto-publish was enabled (a leftover method name from the 0.5.0 rename).

= 0.5.1 =
* Added a Settings link to the plugin's row on the Plugins screen.

= 0.5.0 =
* The built-in crawler now deploys through the site's own backend on normal WordPress installs (the same server-side path Simply Static uses): the browser renders the site and hands it to PHP, which uploads to Cloudflare. No relay Worker is required on real installs, and the API token stays server-side. The relay is now only needed inside WordPress Playground.
* The Cloudflare API relay URL setting is only needed in Playground; on a normal install it can be left blank.

= 0.4.0 =
* Private/draft content no longer leaks into crawler exports. The crawler now renders every page as a logged-out visitor, so private posts, drafts, and admin-only chrome are excluded from pages, archives, and feeds — even in WordPress Playground, where the in-browser server keeps the editor's session across fetches.
* Fixed: a redirect (e.g. from a redirect plugin) sending `/` to another page no longer overwrites the wrong static file. Output is now keyed by the final URL after redirects, and redirects into skipped/off-site URLs are recorded instead of silently corrupting the export.
* Clearer API Token help: the "ask each time" (blank token) option only applies to the built-in crawler; Simply Static needs a stored token (or the SSD_CLOUDFLARE_API_TOKEN constant).

= 0.3.0 =
* Built-in browser crawler that renders the site client-side — no Simply Static or server loopback required; works in WordPress Playground.
* Direct Cloudflare deploy from the browser via a small CORS relay Worker (stores no credentials).
* Export source selector (Automatic / Built-in crawler / Simply Static) with an install-aware default.
* "Publish to Cloudflare" admin-toolbar button with live percent; live status and history without refresh.
* Auto-publish for the crawler runs in the editor on save (saving is locked until the deploy finishes).
* Optional unstored API token (prompted once per session) and a "Clear stored token" control.
* Security: the token is never loaded on the front end, so a logged-in crawl cannot bake it into exports.
* Fixed: auto-publish no longer triggers on plugin updates or internal post types.

= 0.2.0 =
* Deploy status panel and history on the settings page.
* WordPress Playground compatibility: run the export synchronously (no loopback).
* Removed the Symfony MIME dependency; the plugin is now dependency-free.
* Store the API token in a dedicated option excluded from Playground exports, and
  migrate any token left in the shared settings option by older versions.

= 0.1.0 =
* Settings page for Cloudflare credentials (with wp-config constant override).
* Manual "Publish now" mode in addition to auto-publish on save.
* Optional cleanup of the export directory after a successful deploy.
* Switched Cloudflare requests to the WordPress HTTP API with timeouts.
* Excludes credentials from WordPress Playground exports.

== Upgrade Notice ==

= 0.5.2 =
Fixes a fatal error on admin pages when auto-publish is enabled.

= 0.5.1 =
Adds a Settings link on the Plugins screen.

= 0.5.0 =
The crawler now deploys through your site's own backend on normal installs, so the relay Worker is only needed in WordPress Playground and your API token stays server-side.

= 0.4.0 =
Crawler exports now render as a guest, so private/draft content stays out of pages, archives, and feeds. Redirects no longer corrupt the exported homepage.

= 0.3.0 =
Built-in browser crawler with direct Cloudflare deploy (works in Playground), source selector, live status/history, safer token handling.

= 0.2.0 =
Playground compatibility, deploy status/history, dependency-free, and safer token storage.

= 0.1.0 =
Adds a settings page, manual publish mode, cleanup, and hardening.
