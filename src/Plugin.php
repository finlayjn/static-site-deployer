<?php

namespace SSD;

/**
 * Wires up the plugin: settings, export triggers, deploy handler, and the
 * credential-safety filter for WordPress Playground exports.
 */
class Plugin
{
    /** @var Plugin|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        Settings::register();
        Status::register_ajax();

        // Remove any token left in the shared settings option by older versions
        // so it cannot leak into exports.
        Settings::maybe_migrate_legacy_token();

        // In WordPress Playground (and other loopback-less hosts) Simply Static's
        // background queue never runs, causing an endless dispatch loop. Force
        // its inline (synchronous) processing instead.
        if (Settings::is_sync_export()) {
            add_filter('wp_archive_creation_job_loopback_available', '__return_false');
        }

        // Warn when auto-publish is on but credentials are missing (e.g. after
        // importing a Playground zip that excluded the token).
        add_action('admin_notices', [Settings::class, 'maybe_render_missing_creds_notice']);

        // Deploy whenever a Simply Static export finishes (auto or manual).
        add_action('ss_completed', [Deployer::class, 'on_export_completed']);

        // "Publish now" button handler.
        add_action('admin_post_' . Settings::PUBLISH_ACTION, [Deployer::class, 'handle_publish_now']);

        // Only hook post changes when auto-publish is enabled.
        if (Settings::is_auto_publish()) {
            add_action('save_post', [Deployer::class, 'maybe_run'], 20);
            add_action('delete_post', [Deployer::class, 'maybe_run'], 20);
        }

        // Keep secrets out of WordPress-to-Playground site archives.
        add_filter('wp2p_excluded_option_names', [self::class, 'exclude_secret_options']);
    }

    /**
     * Registers this plugin's secret-bearing option for export exclusion.
     *
     * Only the API token is excluded; the account ID, worker name, and toggles
     * are identifiers/preferences that survive a Playground round-trip.
     *
     * @param string[] $names
     * @return string[]
     */
    public static function exclude_secret_options($names): array
    {
        $names   = is_array($names) ? $names : [];
        $names[] = Settings::TOKEN_OPTION;
        return $names;
    }
}
