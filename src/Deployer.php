<?php

namespace SSD;

use SSD\Sources\Source_Registry;
use Throwable;

/**
 * Orchestrates exports and deploys rendered static sites to Cloudflare.
 *
 * Export engines are pluggable {@see \SSD\Sources\Export_Source} implementations;
 * this class stays engine-agnostic and only knows how to deploy a finished
 * directory of static files.
 */
class Deployer
{
    const LOCK_KEY = 'ssd_deploy_lock';

    /**
     * Auto-publish handler for post changes. Debounced so a single edit does
     * not trigger multiple overlapping exports.
     *
     * @param int $post_ID
     */
    public static function maybe_run($post_ID): void
    {
        // Plugin/theme/core installs and updates run with this flag set and can
        // fire save_post (e.g. via Action Scheduler). Those are not content
        // edits, so never treat them as a publish trigger.
        if (wp_installing()) {
            return;
        }
        if (!self::is_publishable_save($post_ID)) {
            return;
        }

        $active = Source_Registry::active();

        if ($active->can_start_server_side()) {
            // Server-side sources (Simply Static) need full stored credentials.
            if (null === Settings::credentials()) {
                return;
            }
            if (get_transient(self::LOCK_KEY)) {
                return;
            }
            set_transient(self::LOCK_KEY, true, 60);
            $active->start();
            return;
        }

        // Browser-driven sources (the built-in crawler) cannot run headless on
        // save. Queue a pending deploy for the admin's browser to pick up (in
        // the editor via wp.data, or on the next admin page load). Only the
        // non-secret config is required; the token may be entered at publish.
        if (!Settings::has_crawler_deploy_config()) {
            return;
        }
        if (get_transient(self::LOCK_KEY)) {
            return;
        }
        set_transient(self::LOCK_KEY, true, 60);
        set_transient(\SSD\Sources\Crawler_Source::PENDING_KEY, time(), DAY_IN_SECONDS);
    }

    /**
     * Whether a save/delete represents a real content change worth deploying.
     *
     * Excludes autosaves, revisions, and internal/utility post types (menus,
     * customizer changesets, Action Scheduler jobs, etc.) that would otherwise
     * cause spurious publishes.
     *
     * @param int $post_ID
     */
    private static function is_publishable_save($post_ID): bool
    {
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
            return false;
        }

        $post_type = get_post_type($post_ID);
        if (!$post_type) {
            return false;
        }

        $ignored = [
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'scheduled-action',
            'action_scheduler_log',
        ];
        return !in_array($post_type, $ignored, true);
    }

    /**
     * Handles the "Publish now" button.
     */
    public static function handle_publish_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to publish.', 'static-site-deployer'));
        }
        check_admin_referer(Settings::PUBLISH_ACTION);

        $active = Source_Registry::active();
        if ($active->can_start_server_side()) {
            $active->start();
        }

        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::MENU_SLUG));
        exit;
    }

    /**
     * Deploys a rendered static site directory to Cloudflare and records the
     * outcome. Export sources call this when their output is ready.
     *
     * @param string     $export_dir
     * @param array{account_id:string,api_token:string,script_name:string}|null $credentials
     *        Explicit credentials (e.g. a one-time token from the crawler);
     *        falls back to the stored/constant credentials when null.
     * @return bool Whether the deploy succeeded.
     */
    public static function deploy_directory(string $export_dir, ?array $credentials = null): bool
    {
        Status::set_progress(4, 'Processing export…', 'running');
        self::rename_404_asset($export_dir);

        if (null === $credentials) {
            $credentials = Settings::credentials();
        }
        if (null === $credentials) {
            Status::record_result('error', 'Cloudflare credentials are not configured.');
            return false;
        }

        try {
            $deployer = new CloudflareAssetsDeployer(
                $credentials['account_id'],
                $credentials['script_name'],
                $export_dir,
                $credentials['api_token']
            );
            $deployer->setProgressCallback(static function ($percent, $message) {
                Status::set_progress((int) $percent, (string) $message, 'running');
            });
            $deployer->uploadAssets();
            Status::record_result('success', 'Deployed to Cloudflare.');
        } catch (Throwable $e) {
            Status::record_result('error', 'Cloudflare deployment failed: ' . $e->getMessage());
            return false;
        }

        if (Settings::is_cleanup_enabled()) {
            FolderHelper::delete_folder($export_dir);
        }

        return true;
    }

    /**
     * Moves 404/index.html to 404.html for Cloudflare's not_found handling.
     *
     * @param string $export_dir
     */
    private static function rename_404_asset(string $export_dir): void
    {
        $src  = $export_dir . '/404/index.html';
        $dest = $export_dir . '/404.html';
        if (file_exists($src)) {
            if (@rename($src, $dest)) {
                FolderHelper::delete_folder($export_dir . '/404');
            }
        }
    }
}
