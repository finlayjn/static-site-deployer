# Static Site Deployer

This WordPress plugin runs a [Simply Static](https://wordpress.org/plugins/simply-static/)
export — automatically when you edit content, or on demand — and deploys the
result to a Cloudflare Worker's static assets. It gives you free, serverless
static hosting for a WordPress site you edit locally (for example in Local WP or
[WordPress Playground](https://wordpress.github.io/wordpress-playground/)).

> [!NOTE]
> This functionality is also provided by Simply Static Pro, which is more
> polished. Please support them if you can.

## Features & To-Do

- [x] Automatic deployment on create/update/delete post
- [x] Automatic deployment after a Simply Static export
- [x] Deploy to Cloudflare Workers static assets
- [x] Configure via wp-config.php constants
- [x] Configure via a settings page
- [x] Manual "Publish now" mode
- [x] Clean up the export directory after a successful deploy
- [ ] Deploy to GitHub Pages

## Installation

1. Install and activate the free Simply Static plugin.
2. Install and activate this plugin (download a release zip, or build one — see below).
3. Create a Cloudflare API token with the **Workers Scripts: Edit** permission.
4. Create a Cloudflare Worker (the Hello World template is fine).
5. Go to **Settings → Static Site Deployer** and enter your Account ID, Worker
   name, and API token.

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
  exported and deployed automatically after a short debounce.
- **Manual**: turn off auto-publish and use the **Publish now** button on the
  settings page.

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

## Architecture

| Concern | Class |
| --- | --- |
| Bootstrap / hooks | `SSD\Plugin` |
| Settings + credential resolution | `SSD\Settings` |
| Export trigger, deploy, cleanup | `SSD\Deployer` |
| Cloudflare assets upload/deploy | `SSD\CloudflareAssetsDeployer` |
| MIME type resolution | `SSD\MimeHelper` |
| Recursive folder deletion | `SSD\FolderHelper` |

## Development

```bash
composer install       # dev dependencies (WordPress core stubs, Simply Static)
tests/run.sh           # lint + unit tests (no WordPress required)
bin/build.sh           # produce dist/static-site-deployer.zip with vendor bundled
```

## Motivation

Static hosting is a great way to cut costs when your content isn't interactive.
This plugin lets you edit WordPress locally and deploy to Cloudflare for free.

## License

GPL-2.0-or-later.
