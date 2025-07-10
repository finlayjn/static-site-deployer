<?php

namespace SSD;

use Exception;

class Deployer
{
    private static $lock_key = 'ssd_deploy_lock';

    public static function maybe_run($post_ID)
    {
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) return;
        if (get_transient(self::$lock_key)) return;
        set_transient(self::$lock_key, true, 60);

        try {
            \Simply_Static\Plugin::instance()->run_static_export();
        } catch (\Throwable $e) {
            error_log("Error triggering export: " . $e->getMessage());
        }
    }

    private static function rename_404_asset($export_dir)
    {
        $src = "$export_dir/404/index.html";
        $dest = "$export_dir/404.html";
        if (file_exists($src)) {
            rename($src, $dest);
            \SSD\FolderHelper::delete_folder("$export_dir/404");
        }
    }

    public static function on_export_completed($status)
    {
        if ($status !== 'success') return;

        $temp_dir = \Simply_Static\Util::get_temp_dir();
        // Find the only subdirectory (the unzipped export)
        $dirs = array_filter(glob(rtrim($temp_dir, '/') . '/*'), 'is_dir');
        if (empty($dirs)) {
            error_log("No export directory found in temp_dir.");
            return;
        }
        // Use the most recent directory
        usort($dirs, fn($a, $b) => filemtime($b) - filemtime($a));
        $export_dir = $dirs[0];

        // Rename 404 asset in place
        self::rename_404_asset($export_dir);

        // Deploy to Cloudflare directly from export_dir
        self::deploy_to_cloudflare($export_dir);
    }

    private static function deploy_to_cloudflare(string $export_dir): void
    {
        $required = [
            'SSD_CLOUDFLARE_ACCOUNT_ID',
            'SSD_CLOUDFLARE_API_TOKEN',
            'SSD_CLOUDFLARE_SCRIPT_NAME',
        ];

        foreach ($required as $key) {
            if (!defined($key) || !constant($key)) {
                error_log("Required constant $key is not defined or empty.");
                return;
            }
        }

        try {
            $uploader = new \SSD\CloudflareAssetsDeployer(
                constant('SSD_CLOUDFLARE_ACCOUNT_ID'),
                constant('SSD_CLOUDFLARE_SCRIPT_NAME'),
                $export_dir,
                constant('SSD_CLOUDFLARE_API_TOKEN')
            );
            $uploader->uploadAssets();
            error_log('Cloudflare deployment succeeded.');
        } catch (Exception $e) {
            error_log('Cloudflare deployment failed: ' . $e->getMessage());
        }
    }
}
