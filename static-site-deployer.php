<?php
/**
 * Plugin Name:       Static Site Deployer
 * Plugin URI:        https://github.com/finlayjn/static-site-deployer
 * Description:       Render your site to static files (with a built-in browser crawler or Simply Static) and deploy to Cloudflare Workers static assets — automatically on save or on demand. Works in WordPress Playground.
 * Version:           0.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Finlay Nathan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       static-site-deployer
 *
 * @package StaticSiteDeployer
 */

defined( 'ABSPATH' ) || exit;

define( 'SSD_VERSION', '0.3.0' );
define( 'SSD_PLUGIN_FILE', __FILE__ );
define( 'SSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for the plugin's own SSD\* classes. The plugin has no third-party
// runtime dependencies.
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'SSD\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( 'SSD\\' ) ) );
		$file     = SSD_PLUGIN_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		\SSD\Plugin::instance()->init();
	}
);