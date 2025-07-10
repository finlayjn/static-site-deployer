# Static Site Deployer

This simple WordPress plugin triggers a Simply Static export upon creating, updating, or deleting a post and deploys the result to a static assets host.

> [!NOTE]
> This functionality is already provided by Simply Static Pro and is implemented much better than this plugin. I strongly suggest upgrading if you can afford it.

## Features & To-Do
-   [x] Automatic deployment on create/update/delete post
-   [x] Automatic deployment after Simply Static generate
-   [x] Deploy to Cloudflare Workers
-   [x] Configure via wp-config.php
-   [ ] Configure via settings page
-   [ ] Deploy to GitHub

## Installation & Usage

### Prepare

1. Install the free version of Simply Static. The default configuration is sufficient, but you should be able to configure it as desired without causing issues with deployment.
2. Download the repository zip from GitHub, then install and activate it on your WordPress installation.
3. Create an API key with permissions for Cloudflare Workers.
4. Create a Cloudflare Worker with the Hello World template.

### Define Variables

Add the following to your wp-config.php file, replacing the values as necessary.

```php
define('SSD_CLOUDFLARE_ACCOUNT_ID', 'your_account_id');
define('SSD_CLOUDFLARE_API_TOKEN', 'your_api_token');
define('SSD_CLOUDFLARE_SCRIPT_NAME', 'your_worker_name');
```

### Edit Your Site
Any time you create, update, or delete a post, wait a minute or two and you should see the changes reflected on your worker.

## Motivation
Static site hosting is a great way to reduce hosting costs when your website content isn't interactive. While the creators of Simply Static have done great work and deserve support, the yearly fee completely defeats the purpose of static hosting for my use case. With this plugin, you can edit your WordPress site locally with a tool like Local WP and automatically deploy it to the internet completely for free.

## License
This software is licensed under the GPLv2.