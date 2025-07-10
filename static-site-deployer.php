<?php
/**
 * Plugin Name: Static Site Deployer
 * Description: Triggers Simply Static export on post save and deploys the result to a static assets host.
 * Version: 0.0.1
 * Author: Finlay Nathan
 */

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

foreach (glob(plugin_dir_path(__FILE__) . 'src/*.php') as $file) {
    require_once $file;
}

add_action('save_post', ['SSD\Deployer', 'maybe_run'], 20, 3);
add_action('wp_insert_post', ['SSD\Deployer', 'maybe_run'], 20, 3);
add_action('delete_post', ['SSD\Deployer', 'maybe_run'], 20);
add_action('ss_completed', ['SSD\Deployer', 'on_export_completed']);