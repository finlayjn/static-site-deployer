<?php

namespace SSD;

/**
 * Stores configuration and resolves Cloudflare credentials.
 *
 * Credentials may come from wp-config.php constants (which always win, and are
 * never written to the database) or from the settings page. Storing the token
 * only in an option keeps it out of the codebase; see the Playground export
 * note in the README for how it is excluded from portable site archives.
 */
class Settings
{
    const OPTION = 'ssd_settings';
    const TOKEN_OPTION = 'ssd_api_token';
    const MENU_SLUG = 'static-site-deployer';
    const PUBLISH_ACTION = 'ssd_publish_now';

    /** @var array<string,mixed> */
    private static $defaults = [
        'account_id'   => '',
        'script_name'  => '',
        'auto_publish' => true,
        'cleanup'      => true,
    ];

    /** wp-config.php constant overrides, keyed by setting. */
    private static $constants = [
        'account_id'  => 'SSD_CLOUDFLARE_ACCOUNT_ID',
        'api_token'   => 'SSD_CLOUDFLARE_API_TOKEN',
        'script_name' => 'SSD_CLOUDFLARE_SCRIPT_NAME',
    ];

    /**
     * Registers admin hooks.
     */
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    /**
     * Shows a warning when auto-publish is enabled but credentials are missing,
     * so saves are silently not being deployed.
     */
    public static function maybe_render_missing_creds_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!self::is_auto_publish() || null !== self::credentials()) {
            return;
        }

        $url = admin_url('options-general.php?page=' . self::MENU_SLUG);
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>' . esc_html__('Static Site Deployer', 'static-site-deployer') . ':</strong> ';
        echo esc_html__('Auto-publish is on, but Cloudflare credentials are missing, so your changes are not being deployed.', 'static-site-deployer');
        echo ' <a href="' . esc_url($url) . '">' . esc_html__('Add your API token', 'static-site-deployer') . '</a>.';
        echo '</p></div>';
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::$defaults, $stored);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public static function get(string $key)
    {
        // A defined constant always takes precedence over the stored value.
        if (isset(self::$constants[$key]) && self::constant_defined($key)) {
            return constant(self::$constants[$key]);
        }
        // The token lives in its own option so it can be excluded from exports
        // without discarding the other settings.
        if ('api_token' === $key) {
            return (string) get_option(self::TOKEN_OPTION, '');
        }
        $all = self::all();
        return $all[$key] ?? null;
    }

    /**
     * Whether a credential is provided via a wp-config constant.
     */
    public static function constant_defined(string $key): bool
    {
        return isset(self::$constants[$key])
            && defined(self::$constants[$key])
            && '' !== (string) constant(self::$constants[$key]);
    }

    public static function is_auto_publish(): bool
    {
        return (bool) self::get('auto_publish');
    }

    public static function is_cleanup_enabled(): bool
    {
        return (bool) self::get('cleanup');
    }

    /**
     * Returns resolved Cloudflare credentials, or null when incomplete.
     *
     * @return array{account_id:string,api_token:string,script_name:string}|null
     */
    public static function credentials(): ?array
    {
        $account_id  = trim((string) self::get('account_id'));
        $api_token   = trim((string) self::get('api_token'));
        $script_name = trim((string) self::get('script_name'));

        if ('' === $account_id || '' === $api_token || '' === $script_name) {
            return null;
        }

        return compact('account_id', 'api_token', 'script_name');
    }

    public static function add_menu(): void
    {
        add_options_page(
            __('Static Site Deployer', 'static-site-deployer'),
            __('Static Site Deployer', 'static-site-deployer'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting(
            self::OPTION,
            self::OPTION,
            ['sanitize_callback' => [self::class, 'sanitize']]
        );
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        $account_id  = sanitize_text_field($input['account_id'] ?? '');
        $script_name = sanitize_text_field($input['script_name'] ?? '');

        // The token is stored separately (and excluded from exports). Only
        // overwrite it when a new value is submitted, so saving other settings
        // or leaving the field blank keeps the existing token.
        if (isset($input['api_token'])) {
            $token = trim((string) $input['api_token']);
            if ('' !== $token) {
                update_option(self::TOKEN_OPTION, $token, false);
            }
        }

        return [
            'account_id'   => $account_id,
            'script_name'  => $script_name,
            'auto_publish' => !empty($input['auto_publish']),
            'cleanup'      => !empty($input['cleanup']),
        ];
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'static-site-deployer'));
        }

        $all           = self::all();

        $has_creds     = null !== self::credentials();
        $simply_static = class_exists('\\Simply_Static\\Plugin');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Static Site Deployer', 'static-site-deployer'); ?></h1>

            <?php if (!$simply_static) : ?>
                <div class="notice notice-error"><p>
                    <?php esc_html_e('Simply Static is not active. Install and activate the free Simply Static plugin to enable exports.', 'static-site-deployer'); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <?php self::render_credential_row('account_id', __('Cloudflare Account ID', 'static-site-deployer'), $all); ?>
                    <?php self::render_credential_row('script_name', __('Worker Name', 'static-site-deployer'), $all); ?>
                    <tr>
                        <th scope="row"><label for="ssd_api_token"><?php esc_html_e('API Token', 'static-site-deployer'); ?></label></th>
                        <td>
                            <?php if (self::constant_defined('api_token')) : ?>
                                <em><?php esc_html_e('Defined in wp-config.php.', 'static-site-deployer'); ?></em>
                            <?php else : ?>
                                <input type="password" autocomplete="new-password" id="ssd_api_token"
                                    name="<?php echo esc_attr(self::OPTION); ?>[api_token]"
                                    value="" class="regular-text"
                                    placeholder="<?php echo esc_attr('' !== (string) get_option(self::TOKEN_OPTION, '') ? '••••••••  (leave blank to keep)' : ''); ?>" />
                                <p class="description"><?php esc_html_e('Needs the Workers Scripts:Edit permission. Leave blank to keep the existing token.', 'static-site-deployer'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-publish', 'static-site-deployer'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_publish]" value="1" <?php checked(!empty($all['auto_publish'])); ?> />
                                <?php esc_html_e('Export and deploy automatically when a post is created, updated, or deleted.', 'static-site-deployer'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Disable to publish only via the button below.', 'static-site-deployer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Clean up after deploy', 'static-site-deployer'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[cleanup]" value="1" <?php checked(!empty($all['cleanup'])); ?> />
                                <?php esc_html_e('Delete the local export directory after a successful deploy to save disk space.', 'static-site-deployer'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Publish now', 'static-site-deployer'); ?></h2>
            <p><?php esc_html_e('Run a Simply Static export and deploy immediately.', 'static-site-deployer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::PUBLISH_ACTION); ?>" />
                <?php wp_nonce_field(self::PUBLISH_ACTION); ?>
                <?php
                submit_button(
                    __('Publish now', 'static-site-deployer'),
                    'secondary',
                    'submit',
                    false,
                    ($has_creds && $simply_static) ? [] : ['disabled' => 'disabled']
                );
                ?>
                <?php if (!$has_creds) : ?>
                    <p class="description"><?php esc_html_e('Enter your Cloudflare credentials above first.', 'static-site-deployer'); ?></p>
                <?php endif; ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Deployment status', 'static-site-deployer'); ?></h2>
            <?php $progress = Status::get_progress(); ?>
            <div id="ssd-status" data-nonce="<?php echo esc_attr(wp_create_nonce(Status::AJAX_ACTION)); ?>">
                <p><strong><?php esc_html_e('State:', 'static-site-deployer'); ?></strong>
                    <span id="ssd-status-state"><?php echo esc_html($progress['state']); ?></span></p>
                <div style="background:#e0e0e0;height:16px;border-radius:4px;overflow:hidden;max-width:420px;">
                    <div id="ssd-status-bar" style="height:100%;width:<?php echo (int) $progress['percent']; ?>%;background:#2271b1;transition:width .3s ease;"></div>
                </div>
                <p id="ssd-status-message"><?php echo esc_html('' !== $progress['message'] ? $progress['message'] : '—'); ?></p>
            </div>

            <h2><?php esc_html_e('History', 'static-site-deployer'); ?></h2>
            <?php $history = Status::get_history(); ?>
            <?php if (empty($history)) : ?>
                <p><?php esc_html_e('No deployments yet.', 'static-site-deployer'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:720px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('When', 'static-site-deployer'); ?></th>
                            <th><?php esc_html_e('Status', 'static-site-deployer'); ?></th>
                            <th><?php esc_html_e('Message', 'static-site-deployer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(human_time_diff((int) ($entry['time'] ?? 0)) . ' ' . __('ago', 'static-site-deployer')); ?></td>
                                <td><?php echo esc_html($entry['status'] ?? ''); ?></td>
                                <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <script>
            (function () {
                var el = document.getElementById('ssd-status');
                if (!el) { return; }
                var nonce = el.getAttribute('data-nonce');
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var action = <?php echo wp_json_encode(Status::AJAX_ACTION); ?>;
                function colorFor(state) {
                    if (state === 'error') { return '#d63638'; }
                    if (state === 'success') { return '#00a32a'; }
                    return '#2271b1';
                }
                function poll() {
                    fetch(ajaxUrl + '?action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res || !res.success) { return; }
                            var p = res.data || {};
                            document.getElementById('ssd-status-state').textContent = p.state || 'idle';
                            var bar = document.getElementById('ssd-status-bar');
                            bar.style.width = (p.percent || 0) + '%';
                            bar.style.background = colorFor(p.state);
                            document.getElementById('ssd-status-message').textContent = p.message || '—';
                            if (p.state === 'running') { setTimeout(poll, 2500); }
                        })
                        .catch(function () {});
                }
                poll();
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Renders a credential text row, honoring constant overrides.
     *
     * @param string               $key
     * @param string               $label
     * @param array<string,mixed>  $all
     */
    private static function render_credential_row(string $key, string $label, array $all): void
    {
        $field_id = 'ssd_' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <?php if (self::constant_defined($key)) : ?>
                    <em><?php esc_html_e('Defined in wp-config.php.', 'static-site-deployer'); ?></em>
                <?php else : ?>
                    <input type="text" id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]"
                        value="<?php echo esc_attr((string) $all[$key]); ?>" class="regular-text" />
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}
