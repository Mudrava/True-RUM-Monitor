<?php
/**
 * Plugin Name: True RUM Monitor
 * Description: Real User Monitoring (RUM) for WordPress websites to track performance metrics and user experience.
 * Version: 0.1.0
 * Author: Mudrava
 * Author URI: https://mudrava.com/
 * License: GPL-2.0+
 * Text Domain: true-rum-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TRM_VERSION', '0.1.8' );
define( 'TRM_PLUGIN_FILE', __FILE__ );
define( 'TRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

global $wpdb;
define( 'TRM_TABLE', $wpdb->prefix . 'true_rum_logs' );

require_once TRM_PLUGIN_DIR . 'includes/class-trm-plugin.php';

// Bootstrap plugin.
add_action( 'plugins_loaded', array( 'TRM_Plugin', 'instance' ) );

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'TRM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TRM_Plugin', 'deactivate' ) );
