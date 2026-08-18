<?php

namespace SSD;

use SSD\Sources\Source_Registry;

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

        // Let the active export source register its own hooks (e.g. Simply
        // Static's completion event and loopback handling).
        Source_Registry::active()->register();

        // Warn when auto-publish is on but credentials are missing (e.g. after
        // importing a Playground zip that excluded the token).
        add_action('admin_notices', [Settings::class, 'maybe_render_missing_creds_notice']);

        // "Publish now" button handler.
        add_action('admin_post_' . Settings::PUBLISH_ACTION, [Deployer::class, 'handle_publish_now']);

        // "Publish to Cloudflare" button in the admin toolbar.
        add_action('admin_bar_menu', [self::class, 'add_admin_bar_button'], 100);

        // "Settings" link on the Plugins list row.
        add_filter('plugin_action_links_' . plugin_basename(SSD_PLUGIN_FILE), [self::class, 'add_settings_link']);

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

    /**
     * Adds a "Settings" link to the plugin's row on the Plugins screen.
     *
     * @param string[] $links
     * @return string[]
     */
    public static function add_settings_link($links): array
    {
        $url  = admin_url('options-general.php?page=' . Settings::MENU_SLUG);
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'static-site-deployer') . '</a>';
        array_unshift($links, $link);
        return $links;
    }

    /**
     * Adds a "Publish to Cloudflare" node to the admin toolbar that triggers
     * the active export source.
     *
     * For Simply Static this is a nonced link to the server-side handler. For
     * the browser crawler the node is bound by admin.js (which is enqueued only
     * when the crawler is active).
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public static function add_admin_bar_button($wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $node = [
            'id'    => 'ssd-publish',
            'title' => __('Publish to Cloudflare', 'static-site-deployer'),
        ];

        if ('simply_static' === Source_Registry::active()->slug()) {
            $node['href'] = wp_nonce_url(
                admin_url('admin-post.php?action=' . Settings::PUBLISH_ACTION),
                Settings::PUBLISH_ACTION
            );
        } else {
            // The crawler runs on its settings page; link there and auto-start
            // so the token-bearing script never loads elsewhere.
            $node['href'] = admin_url('options-general.php?page=' . Settings::MENU_SLUG . '&ssd_start=crawler');
        }

        $wp_admin_bar->add_node($node);
    }
}
