<?php

namespace SSD;

use Throwable;

/**
 * Triggers Simply Static exports and deploys the result to Cloudflare.
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
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
            return;
        }
        // This handler is only hooked when auto-publish is on. Without
        // credentials there is nothing to deploy to, so skip the export and let
        // the admin notice explain why (e.g. after importing a Playground zip).
        if (null === Settings::credentials()) {
            return;
        }
        if (get_transient(self::LOCK_KEY)) {
            return;
        }
        set_transient(self::LOCK_KEY, true, 60);

        self::run_export();
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

        self::run_export();

        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::MENU_SLUG));
        exit;
    }

    /**
     * Starts a Simply Static export.
     *
     * @return bool True when the export was triggered.
     */
    public static function run_export(): bool
    {
        if (!class_exists('\\Simply_Static\\Plugin')) {
            Status::record_result('error', 'Simply Static is not active; cannot export.');
            return false;
        }

        try {
            Status::set_progress(3, 'Exporting site…', 'running');
            \Simply_Static\Plugin::instance()->run_static_export();
            return true;
        } catch (Throwable $e) {
            Status::record_result('error', 'Error triggering export: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Runs when Simply Static finishes. Deploys on success.
     *
     * @param string $status
     */
    public static function on_export_completed($status): void
    {
        if ('success' !== $status) {
            return;
        }

        $export_dir = self::find_export_dir();
        if (null === $export_dir) {
            Status::record_result('error', 'No completed export directory was found.');
            return;
        }

        Status::set_progress(4, 'Processing export…', 'running');
        self::rename_404_asset($export_dir);
        self::deploy_to_cloudflare($export_dir);
    }

    /**
     * Locates the most recent Simply Static export directory.
     *
     * @return string|null
     */
    private static function find_export_dir(): ?string
    {
        if (!class_exists('\\Simply_Static\\Util')) {
            return null;
        }

        $temp_dir = \Simply_Static\Util::get_temp_dir();
        if (!is_string($temp_dir) || '' === $temp_dir) {
            return null;
        }

        $dirs = array_filter((array) glob(rtrim($temp_dir, '/') . '/*'), 'is_dir');
        if (empty($dirs)) {
            return null;
        }

        // Most recent first.
        usort($dirs, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        // Prefer a directory that looks like a rendered site.
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/index.html') || file_exists($dir . '/404/index.html')) {
                return $dir;
            }
        }

        return $dirs[0];
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

    /**
     * Deploys an export directory to Cloudflare and records the outcome.
     *
     * @param string $export_dir
     */
    private static function deploy_to_cloudflare(string $export_dir): void
    {
        $credentials = Settings::credentials();
        if (null === $credentials) {
            Status::record_result('error', 'Cloudflare credentials are not configured.');
            return;
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
            return;
        }

        if (Settings::is_cleanup_enabled()) {
            self::cleanup($export_dir);
        }
    }

    /**
     * Removes the deployed export directory and any leftover Simply Static
     * export zips in the temp directory.
     *
     * @param string $export_dir
     */
    private static function cleanup(string $export_dir): void
    {
        FolderHelper::delete_folder($export_dir);

        if (!class_exists('\\Simply_Static\\Util')) {
            return;
        }
        $temp_dir = \Simply_Static\Util::get_temp_dir();
        if (!is_string($temp_dir) || '' === $temp_dir) {
            return;
        }
        foreach ((array) glob(rtrim($temp_dir, '/') . '/*.zip') as $zip) {
            if (is_file($zip)) {
                @unlink($zip);
            }
        }
    }

    private static function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Static Site Deployer] ' . $message);
        }
    }
}
