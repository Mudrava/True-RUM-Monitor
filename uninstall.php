<?php
/**
 * Uninstall cleanup for True RUM Monitor.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'true_rum_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'trm_settings' );
delete_option( 'trm_last_interval' );
delete_option( 'trm_last_alert_ts' );
