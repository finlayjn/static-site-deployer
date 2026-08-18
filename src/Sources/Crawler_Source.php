<?php

namespace SSD\Sources;

use SSD\Settings;
use SSD\Status;

/**
 * Built-in browser crawler export source.
 *
 * Renders the site to static files from admin JavaScript using same-origin
 * `fetch`, so it works inside WordPress Playground (no PHP loopback required).
 * The crawl is driven client-side; PHP only supplies the seed URL list and
 * records the outcome.
 */
class Crawler_Source implements Export_Source
{
    const SEEDS_ACTION  = 'ssd_crawler_seeds';
    const RECORD_ACTION = 'ssd_crawler_record';
    const NONCE         = 'ssd_crawler';
    const SCRIPT_HANDLE = 'ssd-crawler';

    /** Transient flag: a content change is awaiting a browser-driven deploy. */
    const PENDING_KEY = 'ssd_pending_deploy';

    public function slug(): string
    {
        return 'crawler';
    }

    public function label(): string
    {
        return __('Built-in crawler', 'static-site-deployer');
    }

    public function is_available(): bool
    {
        return true;
    }

    public function unavailable_reason(): string
    {
        return '';
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::SEEDS_ACTION, [$this, 'ajax_seeds']);
        add_action('wp_ajax_' . self::RECORD_ACTION, [$this, 'ajax_record']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function can_start_server_side(): bool
    {
        return false;
    }

    /**
     * The crawler is browser-driven; there is nothing to start server-side.
     * Callers gate on {@see can_start_server_side()} and never reach this.
     */
    public function start(): bool
    {
        return false;
    }

    public function render_publish_control(bool $has_creds): void
    {
        ?>
        <p><?php esc_html_e('Render the site in your browser and deploy it. Runs entirely client-side, so it works in WordPress Playground.', 'static-site-deployer'); ?></p>
        <p>
            <button type="button" class="button button-secondary" id="ssd-crawler-publish">
                <?php esc_html_e('Publish now', 'static-site-deployer'); ?>
            </button>
            <button type="button" class="button" id="ssd-crawler-zip">
                <?php esc_html_e('Download ZIP (debug)', 'static-site-deployer'); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Enqueues the browser crawler where it can run: always on the settings
     * page, and on all admin pages when auto-publish is on (so a queued deploy
     * can fire from the editor). The localized Cloudflare token therefore never
     * loads on the front end, so a logged-in crawl can't bake it into exports.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue($hook = ''): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $is_settings = ('settings_page_' . Settings::MENU_SLUG === $hook);
        $auto_ready  = Settings::is_auto_publish() && Settings::has_browser_deploy_config();
        if (!$is_settings && !$auto_ready) {
            return;
        }
        wp_register_script(self::SCRIPT_HANDLE, SSD_PLUGIN_URL . 'assets/crawler/admin.js', [], SSD_VERSION, true);

        // Non-secret config is exposed on admin pages so the browser can deploy.
        // The token is optional: when blank, the user is prompted for a one-time
        // token at publish and it is never stored. The token is only localized
        // when already saved, and never on the front end.
        $token = (string) Settings::get('api_token');
        wp_localize_script(
            self::SCRIPT_HANDLE,
            'SSD_CRAWLER',
            [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'seedsAction'  => self::SEEDS_ACTION,
                'recordAction' => self::RECORD_ACTION,
                'statusAction' => Status::AJAX_ACTION,
                'statusNonce'  => wp_create_nonce(Status::AJAX_ACTION),
                'nonce'        => wp_create_nonce(self::NONCE),
                'baseUrl'      => home_url('/'),
                'canDeploy'    => Settings::has_browser_deploy_config(),
                'hasToken'     => '' !== $token,
                'accountId'    => (string) Settings::get('account_id'),
                'scriptName'   => (string) Settings::get('script_name'),
                'token'        => $token,
                'relayUrl'     => (string) Settings::get('relay_url'),
                'pending'      => (bool) get_transient(self::PENDING_KEY),
            ]
        );
        wp_enqueue_script(self::SCRIPT_HANDLE);
        add_filter('script_loader_tag', [$this, 'as_module'], 10, 2);
    }

    /**
     * Loads the crawler bundle as an ES module so it can import the core.
     *
     * @param string $tag
     * @param string $handle
     * @return string
     */
    public function as_module(string $tag, string $handle): string
    {
        if (self::SCRIPT_HANDLE !== $handle) {
            return $tag;
        }
        return str_replace('<script ', '<script type="module" ', $tag);
    }

    /**
     * Returns the list of URLs to seed the crawl with, derived from the
     * database so orphaned pages are still captured.
     */
    public function ajax_seeds(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        $urls = [home_url('/')];

        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);
        $post_ids = get_posts(
            [
                'post_type'   => array_values($types),
                'post_status' => 'publish',
                'numberposts' => 1000,
                'fields'      => 'ids',
            ]
        );
        foreach ($post_ids as $post_id) {
            $permalink = get_permalink($post_id);
            if (is_string($permalink) && '' !== $permalink) {
                $urls[] = $permalink;
            }
        }

        foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => 500]);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                }
            }
        }

        wp_send_json_success(['seeds' => array_values(array_unique($urls))]);
    }

    /**
     * Records the outcome of a browser crawl/deploy in the deploy history.
     */
    public function ajax_record(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        // A browser deploy has run (success or failure); clear any queued
        // pending deploy so it isn't retried on the next admin page load.
        delete_transient(self::PENDING_KEY);

        $status  = isset($_POST['status']) && 'error' === $_POST['status'] ? 'error' : 'success';
        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        if ('' === $message) {
            $message = 'success' === $status ? 'Deployed to Cloudflare.' : 'Deploy failed.';
        }

        Status::record_result($status, $message);
        wp_send_json_success();
    }
}
