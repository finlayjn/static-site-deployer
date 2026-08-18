# Static Site Deployer

This WordPress plugin renders your site to static files — with a **built-in
browser crawler** or an existing [Simply Static](https://wordpress.org/plugins/simply-static/)
export — and deploys the result to a Cloudflare Worker's static assets,
automatically when you edit content or on demand. It gives you free, serverless
static hosting for a WordPress site you edit locally (for example in Local WP or
[WordPress Playground](https://wordpress.github.io/wordpress-playground/)).

The built-in crawler renders each page in your browser using same-origin
`fetch`, so it needs no server-side loopback and works even inside WordPress
Playground — no other plugin required.

> [!NOTE]
> This functionality is also provided by Simply Static Pro, which is more
> polished. Please support them if you can.

## Features & To-Do

- [x] Built-in browser crawler (no Simply Static / server loopback; works in Playground)
- [x] Deploy an existing Simply Static export on a normal server
- [x] Choose the export source, or auto-select per environment
- [x] Deploy to Cloudflare Workers static assets (browser → CORS relay Worker → Cloudflare)
- [x] Automatic deployment on create/update/delete post
- [x] "Publish now" from the settings page or the admin toolbar (with live percent)
- [x] Live deployment status and history without a page refresh
- [x] Configure via a settings page or wp-config.php constants
- [x] Optional, unstored API token (prompted once per session)
- [ ] OAuth "Deploy to Cloudflare" (no token in the browser at all)
- [ ] Deploy to GitHub Pages

## Installation

1. Install and activate this plugin (download a release zip, or build one — see below).
   The built-in crawler needs no other plugin; to deploy Simply Static output instead, also install Simply Static.
2. Create a Cloudflare API token with the **Workers Scripts: Edit** permission.
3. Create a Cloudflare Worker (the Hello World template is fine).
4. Deploy the CORS relay Worker (see [Deploying from the browser](#deploying-from-the-browser)) and copy its URL.
5. Go to **Settings → Static Site Deployer** and enter your Account ID, Worker
   name, relay URL, and (optionally) API token.

### Configuring credentials

Enter credentials on the settings page, or define them in `wp-config.php`
(these always win and are never stored in the database):

```php
define( 'SSD_CLOUDFLARE_ACCOUNT_ID', 'your_account_id' );
define( 'SSD_CLOUDFLARE_API_TOKEN', 'your_api_token' );
define( 'SSD_CLOUDFLARE_SCRIPT_NAME', 'your_worker_name' );
```

## Usage

- **Auto-publish** (default): create, update, or delete a post and the site is
  exported and deployed automatically after a short debounce. With the built-in
  crawler this runs in your browser — in the block editor it deploys on save
  (saving is locked until the deploy finishes), or a queued deploy runs the next
  time you open an admin page.
- **Manual**: turn off auto-publish and use the **Publish now** button on the
  settings page, or the **Publish to Cloudflare** item in the admin toolbar.

### Choosing an export source

Under **Settings → Static Site Deployer → Export source** you can pick the
**built-in crawler**, **Simply Static**, or **Automatic**. Automatic prefers
Simply Static when it is installed on a normal server and falls back to the
crawler otherwise. WordPress Playground always uses the crawler (it has no
server-side loopback for Simply Static's crawl).

### The API token

Store the token on the settings page or in a wp-config constant, **or leave it
blank** to be prompted for a one-time token at publish. A prompted token is kept
in browser memory for that session only and is never stored. Use **Clear stored
token** to remove a saved token.

### Deploying from the browser

The built-in crawler uploads to Cloudflare from your browser. Because
`api.cloudflare.com` does not send CORS headers, requests go through a small
relay Worker you deploy once. It **stores no credentials** — your token is passed
through per request. See [`assets/crawler/worker/README.md`](assets/crawler/worker/README.md)
for deployment, then paste the Worker URL into the **Cloudflare API relay URL**
setting.

## Using with WordPress Playground

This plugin is designed to pair with
[WordPress to Playground](https://github.com/finlayjn/wordpress-to-playground):
import a Playground archive, edit the site, deploy changes to Cloudflare with
this plugin, then export the archive again for backup or committing to GitHub.

**Your Cloudflare token stays out of the exported archive.** The API token is
stored in its own option (`ssd_api_token`) that the plugin registers with the
exporter's `wp2p_excluded_option_names` filter, so it is never written to the
exported SQLite database. Your other settings — account ID, worker name, and the
auto-publish/cleanup toggles — live in `ssd_settings` and **do** travel with the
archive. After importing an archive into a fresh Playground, you only need to
re-enter the **token** (or provide it via an uncommitted `wp-config.php`
constant). This makes the exported zip safe to store in a public GitHub
repository.

Exporting from Playground does not remove the token from the running site — it
is simply omitted from the zip — so you only re-enter it when starting a new
Playground from a committed archive.

Exports run **synchronously** in Playground automatically: Simply Static's normal
background queue relies on loopback HTTP requests, which Playground does not
provide, so without this it would loop forever and never finish. On a normal host
you can enable the same mode under **Settings → Synchronous export** if your host
blocks loopback requests.

## Architecture

| Concern | Class |
| --- | --- |
| Bootstrap / hooks | `SSD\Plugin` |
| Settings + credential resolution | `SSD\Settings` |
| Export orchestration + Cloudflare directory deploy | `SSD\Deployer` |
| Export source interface + registry | `SSD\Sources\Export_Source`, `SSD\Sources\Source_Registry` |
| Built-in crawler source | `SSD\Sources\Crawler_Source` |
| Simply Static source | `SSD\Sources\Simply_Static_Source` |
| Cloudflare assets upload/deploy (server-side) | `SSD\CloudflareAssetsDeployer` |
| Deploy status + history | `SSD\Status` |
| MIME type resolution | `SSD\MimeHelper` |
| Recursive folder deletion | `SSD\FolderHelper` |

Browser-side crawler and deploy code lives in `assets/crawler/` (`crawler.js`
core, `admin.js` entry, `cloudflare.js` uploader, and the `worker/` CORS relay).

## Development

```bash
tests/run.sh   # lint + unit tests (no WordPress or dependencies required)
bin/build.sh   # produce dist/static-site-deployer.zip
node bin/crawl.mjs <baseUrl> [outDir]   # run the crawler against a live site (dev)
```

The plugin has no runtime dependencies, so no composer/vendor step is needed.
(`package.json` holds dev-only tooling for the crawler test harness.)

## Motivation

Static hosting is a great way to cut costs when your content isn't interactive.
This plugin lets you edit WordPress locally and deploy to Cloudflare for free.

## License

GPL-2.0-or-later.
