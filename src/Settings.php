<?php

namespace SSD;

use SSD\Sources\Source_Registry;

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
        'account_id'    => '',
        'script_name'   => '',
        'export_source' => '',
        'relay_url'     => '',
        'auto_publish'  => true,
        'cleanup'       => true,
        'sync_export'   => false,
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
        if (!self::is_auto_publish()) {
            return;
        }
        // The crawler can prompt for a one-time token, so it only needs the
        // non-secret config; Simply Static needs full stored credentials.
        $active = \SSD\Sources\Source_Registry::active();
        $configured = 'crawler' === $active->slug()
            ? self::has_browser_deploy_config()
            : null !== self::credentials();
        if ($configured) {
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
     * Moves a token stored under the legacy single-option scheme into the
     * dedicated (export-excluded) token option, and strips it from the shared
     * settings so it can no longer leak into exports.
     */
    public static function maybe_migrate_legacy_token(): void
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored) || !array_key_exists('api_token', $stored)) {
            return;
        }

        $legacy = (string) $stored['api_token'];
        if ('' !== $legacy && '' === (string) get_option(self::TOKEN_OPTION, '')) {
            update_option(self::TOKEN_OPTION, $legacy, false);
        }

        unset($stored['api_token']);
        update_option(self::OPTION, $stored, false);
    }

    /**
     * Whether the site is running inside WordPress Playground, which has no
     * working PHP loopback for background processing.
     */
    public static function is_playground(): bool
    {
        if (is_dir('/internal/shared')) {
            return true;
        }
        return defined('WP_HOME') && false !== strpos((string) constant('WP_HOME'), 'playground.wordpress.net');
    }

    /**
     * Whether the Simply Static export should run synchronously (inline) rather
     * than via background loopback requests. Always on in Playground.
     */
    public static function is_sync_export(): bool
    {
        return self::is_playground() || (bool) self::get('sync_export');
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

    /**
     * Whether the browser crawler has enough config to attempt a deploy. The
     * API token is optional here: it may be entered once at publish time (and
     * not stored) when left blank.
     */
    public static function has_browser_deploy_config(): bool
    {
        return '' !== trim((string) self::get('account_id'))
            && '' !== trim((string) self::get('script_name'))
            && '' !== trim((string) self::get('relay_url'));
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

        $export_source = (string) ($input['export_source'] ?? '');
        if (!in_array($export_source, ['', 'crawler', 'simply_static'], true)) {
            $export_source = '';
        }

        $relay_url = esc_url_raw(trim((string) ($input['relay_url'] ?? '')));

        // The token is stored separately (and excluded from exports). Clearing
        // takes precedence; otherwise only overwrite when a new value is
        // submitted, so saving other settings keeps the existing token.
        if (!empty($input['clear_api_token'])) {
            delete_option(self::TOKEN_OPTION);
        } elseif (isset($input['api_token'])) {
            $token = trim((string) $input['api_token']);
            if ('' !== $token) {
                update_option(self::TOKEN_OPTION, $token, false);
            }
        }

        return [
            'account_id'    => $account_id,
            'script_name'   => $script_name,
            'export_source' => $export_source,
            'relay_url'     => $relay_url,
            'auto_publish'  => !empty($input['auto_publish']),
            'cleanup'       => !empty($input['cleanup']),
            'sync_export'   => !empty($input['sync_export']),
        ];
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'static-site-deployer'));
        }

        $all       = self::all();
        $has_creds = null !== self::credentials();
        $source    = Source_Registry::active();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Static Site Deployer', 'static-site-deployer'); ?></h1>

            <?php if (!$source->is_available()) : ?>
                <div class="notice notice-error"><p>
                    <?php echo esc_html($source->unavailable_reason()); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <?php self::render_source_row($all); ?>
                    <?php self::render_credential_row('account_id', __('Cloudflare Account ID', 'static-site-deployer'), $all); ?>
                    <?php self::render_credential_row('script_name', __('Worker Name', 'static-site-deployer'), $all); ?>
                    <tr>
                        <th scope="row"><label for="ssd_api_token"><?php esc_html_e('API Token', 'static-site-deployer'); ?></label></th>
                        <td>
                            <?php if (self::constant_defined('api_token')) : ?>
                                <em><?php esc_html_e('Defined in wp-config.php.', 'static-site-deployer'); ?></em>
                            <?php else : ?>
                                <?php $has_stored_token = '' !== (string) get_option(self::TOKEN_OPTION, ''); ?>
                                <input type="password" autocomplete="new-password" id="ssd_api_token"
                                    name="<?php echo esc_attr(self::OPTION); ?>[api_token]"
                                    value="" class="regular-text"
                                    placeholder="<?php echo esc_attr($has_stored_token ? '••••••••  (leave blank to keep)' : 'Leave blank to be asked each time'); ?>" />
                                <p class="description">
                                    <?php
                                    if ($has_stored_token) {
                                        esc_html_e('Needs the Workers Scripts:Edit permission. Leave blank to keep the existing token.', 'static-site-deployer');
                                    } else {
                                        esc_html_e('Needs the Workers Scripts:Edit permission. Leave blank to be prompted for a one-time token each time you publish (nothing is stored).', 'static-site-deployer');
                                    }
                                    ?>
                                </p>
                                <?php if ('' !== (string) get_option(self::TOKEN_OPTION, '')) : ?>
                                    <p><label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[clear_api_token]" value="1" />
                                        <?php esc_html_e('Clear the stored token (deploy will prompt for a one-time token).', 'static-site-deployer'); ?>
                                    </label></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ssd_relay_url"><?php esc_html_e('Cloudflare API relay URL', 'static-site-deployer'); ?></label></th>
                        <td>
                            <input type="url" id="ssd_relay_url"
                                name="<?php echo esc_attr(self::OPTION); ?>[relay_url]"
                                value="<?php echo esc_attr((string) ($all['relay_url'] ?? '')); ?>" class="regular-text"
                                placeholder="https://ssd-cloudflare-relay.example.workers.dev" />
                            <p class="description"><?php esc_html_e('Only used by the built-in crawler. Deploy the relay Worker (see assets/crawler/worker/README.md) and paste its URL here so the browser can reach the Cloudflare API.', 'static-site-deployer'); ?></p>
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
                    <tr>
                        <th scope="row"><?php esc_html_e('Synchronous export', 'static-site-deployer'); ?></th>
                        <td>
                            <?php if (self::is_playground()) : ?>
                                <label><input type="checkbox" checked disabled />
                                    <?php esc_html_e('Automatically enabled in WordPress Playground.', 'static-site-deployer'); ?></label>
                            <?php else : ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[sync_export]" value="1" <?php checked(!empty($all['sync_export'])); ?> />
                                    <?php esc_html_e('Run the export in a single request instead of background loopback requests.', 'static-site-deployer'); ?>
                                </label>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Required for WordPress Playground and hosts without working loopback requests.', 'static-site-deployer'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Publish now', 'static-site-deployer'); ?></h2>
            <?php $source->render_publish_control($has_creds); ?>

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
            <div id="ssd-history">
                <p id="ssd-history-empty" <?php echo empty($history) ? '' : 'style="display:none;"'; ?>>
                    <?php esc_html_e('No deployments yet.', 'static-site-deployer'); ?>
                </p>
                <table class="widefat striped" id="ssd-history-table" style="max-width:720px;<?php echo empty($history) ? 'display:none;' : ''; ?>">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('When', 'static-site-deployer'); ?></th>
                            <th><?php esc_html_e('Status', 'static-site-deployer'); ?></th>
                            <th><?php esc_html_e('Message', 'static-site-deployer'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ssd-history-body">
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(human_time_diff((int) ($entry['time'] ?? 0)) . ' ' . __('ago', 'static-site-deployer')); ?></td>
                                <td><?php echo esc_html($entry['status'] ?? ''); ?></td>
                                <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

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
     * Renders the export-source selector.
     *
     * @param array<string,mixed> $all
     */
    private static function render_source_row(array $all): void
    {
        $stored       = (string) ($all['export_source'] ?? '');
        $active       = Source_Registry::active();
        $simply       = Source_Registry::get('simply_static');
        $ss_available = null !== $simply && $simply->is_available();
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Export source', 'static-site-deployer'); ?></th>
            <td>
                <?php if (self::is_playground()) : ?>
                    <p><em><?php esc_html_e('WordPress Playground always uses the built-in crawler.', 'static-site-deployer'); ?></em></p>
                <?php else : ?>
                    <fieldset>
                        <label>
                            <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[export_source]" value="" <?php checked('', $stored); ?> />
                            <?php printf(
                                /* translators: %s: the resolved source name. */
                                esc_html__('Automatic (currently: %s)', 'static-site-deployer'),
                                esc_html($active->label())
                            ); ?>
                        </label><br />
                        <label>
                            <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[export_source]" value="crawler" <?php checked('crawler', $stored); ?> />
                            <?php esc_html_e('Built-in crawler (renders in your browser)', 'static-site-deployer'); ?>
                        </label><br />
                        <label>
                            <input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[export_source]" value="simply_static" <?php checked('simply_static', $stored); ?> <?php echo $ss_available ? '' : 'disabled'; ?> />
                            <?php esc_html_e('Simply Static (crawls on the server)', 'static-site-deployer'); ?>
                            <?php if (!$ss_available) : ?>
                                <span class="description"><?php esc_html_e('— install the Simply Static plugin to use this', 'static-site-deployer'); ?></span>
                            <?php endif; ?>
                        </label>
                    </fieldset>
                <?php endif; ?>
                <p class="description"><?php esc_html_e('The built-in crawler needs no loopback and works in Playground. Simply Static is more battle-tested but requires working server-side loopback.', 'static-site-deployer'); ?></p>
            </td>
        </tr>
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
