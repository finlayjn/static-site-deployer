<?php

namespace SSD\Sources;

use SSD\Deployer;
use SSD\FolderHelper;
use SSD\Settings;
use SSD\Status;
use Throwable;

/**
 * Export source backed by the free Simply Static plugin.
 *
 * Simply Static crawls the site over HTTP loopback and writes the result to a
 * temporary directory. This adapter triggers that export and, on completion,
 * hands the directory to {@see Deployer::deploy_directory()}.
 */
class Simply_Static_Source implements Export_Source
{
    public function slug(): string
    {
        return 'simply_static';
    }

    public function label(): string
    {
        return __('Simply Static', 'static-site-deployer');
    }

    public function is_available(): bool
    {
        return class_exists('\\Simply_Static\\Plugin');
    }

    public function unavailable_reason(): string
    {
        return __('Simply Static is not active. Install and activate the free Simply Static plugin to use this export source.', 'static-site-deployer');
    }

    public function register(): void
    {
        // Deploy whenever a Simply Static export finishes (auto or manual).
        add_action('ss_completed', [$this, 'on_completed']);

        // In WordPress Playground (and other loopback-less hosts) Simply Static's
        // background queue never runs, causing an endless dispatch loop. Force
        // its inline (synchronous) processing instead.
        if (Settings::is_sync_export()) {
            add_filter('wp_archive_creation_job_loopback_available', '__return_false');
        }
    }

    public function can_start_server_side(): bool
    {
        return true;
    }

    public function start(): bool
    {
        if (!$this->is_available()) {
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

    public function render_publish_control(bool $has_creds): void
    {
        ?>
        <p><?php esc_html_e('Run a Simply Static export and deploy immediately.', 'static-site-deployer'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(Settings::PUBLISH_ACTION); ?>" />
            <?php wp_nonce_field(Settings::PUBLISH_ACTION); ?>
            <?php
            submit_button(
                __('Publish now', 'static-site-deployer'),
                'secondary',
                'submit',
                false,
                ($has_creds && $this->is_available()) ? [] : ['disabled' => 'disabled']
            );
            ?>
            <?php if (!$has_creds) : ?>
                <p class="description"><?php esc_html_e('Enter your Cloudflare credentials above first.', 'static-site-deployer'); ?></p>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Runs when Simply Static finishes. Deploys on success.
     *
     * @param string $status
     */
    public function on_completed($status): void
    {
        if ('success' !== $status) {
            return;
        }

        $export_dir = $this->find_export_dir();
        if (null === $export_dir) {
            Status::record_result('error', 'No completed export directory was found.');
            return;
        }

        Deployer::deploy_directory($export_dir);

        if (Settings::is_cleanup_enabled()) {
            $this->cleanup_zips();
        }
    }

    /**
     * Locates the most recent Simply Static export directory.
     *
     * @return string|null
     */
    private function find_export_dir(): ?string
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
     * Removes leftover Simply Static export zips in the temp directory.
     */
    private function cleanup_zips(): void
    {
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
}
