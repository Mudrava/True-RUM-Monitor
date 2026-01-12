<?php
/**
 * Reports and alerts for True RUM Monitor.
 *
 * @package TrueRUMMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRM_Reports {

    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'trm_reports_cron';

    /**
     * Plugin reference.
     *
     * @var TRM_Plugin
     */
    protected $plugin;

    /**
     * Constructor.
     *
     * @param TRM_Plugin $plugin Plugin instance.
     */
    public function __construct( TRM_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Hook actions.
     */
    public function hook() {
        add_action( 'init', array( $this, 'register_cron' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
    }

    /**
     * Register cron schedule.
     */
    public function register_cron() {
        add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
        $this->ensure_scheduled();
    }

    /**
     * Add custom cron schedule.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function register_schedule( $schedules ) {
        $interval = $this->get_interval_seconds();

        $schedules['trm_interval'] = array(
            'interval' => $interval,
            'display'  => __( 'True RUM Monitor interval', 'true-rum-monitor' ),
        );

        return $schedules;
    }

    /**
     * Ensure cron is scheduled with current interval; reschedule if changed.
     */
    protected function ensure_scheduled() {
        $interval       = $this->get_interval_seconds();
        $last_interval  = (int) get_option( 'trm_last_interval', 0 );
        $needs_reschedule = ( $last_interval !== $interval );

        if ( $needs_reschedule ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'trm_interval', self::CRON_HOOK );
        }

        if ( $needs_reschedule ) {
            update_option( 'trm_last_interval', $interval );
        }
    }

    /**
     * Resolve current reporting interval in seconds.
     *
     * @return int
     */
    protected function get_interval_seconds() {
        $settings = $this->plugin->settings()->all();
        return ( 'daily' === $settings['report_schedule'] ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
    }

    /**
     * Cron task runner.
     */
    public function run_cron() {
        $this->send_report();
        $this->check_alerts();
    }

    /**
     * Send summary email.
     * 
     * @return bool Success status.
     */
    public function send_report() {
        global $wpdb;

        $settings = $this->plugin->settings()->all();
        $recipient = $settings['alert_recipient'];
        if ( ! $recipient || ! is_email( $recipient ) ) {
            return false;
        }

        $table = TRM_TABLE;
        $avg   = $wpdb->get_row( "SELECT AVG(ttfb) as ttfb, AVG(lcp) as lcp, AVG(total_load) as total_load FROM {$table}", ARRAY_A );

        $avg_ttfb  = isset( $avg['ttfb'] ) ? floatval( $avg['ttfb'] ) : 0;
        $avg_lcp   = isset( $avg['lcp'] ) ? floatval( $avg['lcp'] ) : 0;
        $avg_total = isset( $avg['total_load'] ) ? floatval( $avg['total_load'] ) : 0;

        $top_pages = $wpdb->get_results( "SELECT url, AVG(ttfb) as ttfb, AVG(lcp) as lcp, COUNT(*) as hits FROM {$table} GROUP BY url HAVING hits > 1 ORDER BY lcp DESC LIMIT 10", ARRAY_A );

        $devices = $wpdb->get_results( "SELECT device, COUNT(*) as hits FROM {$table} GROUP BY device", ARRAY_A );

        $site_name = get_bloginfo( 'name' );
        $body  = "True RUM Monitor Report for {$site_name}\n";
        $body .= "--------------------------------------------------\n";
        $body .= sprintf( "Avg LCP (User Exp):  %.3fs\nAvg TTFB (Server):   %.3fs\nAvg Total Load:      %.3fs\n\n", $avg_lcp, $avg_ttfb, $avg_total );
        
        $body .= "Top Problematic Pages (Sort by LCP, min 2 views):\n";
        $body .= "--------------------------------------------------\n";
        if ( $top_pages ) {
           foreach ( (array) $top_pages as $row ) {
               $body .= sprintf( "[LCP: %.3fs | TTFB: %.3fs] %s (%d hits)\n", $row['lcp'], $row['ttfb'], $row['url'], $row['hits'] );
           }
        } else {
            $body .= "Not enough data yet.\n";
        }

        $body .= "\nDevice Usage:\n";
        foreach ( (array) $devices as $row ) {
            $body .= sprintf( "%s: %d\n", $row['device'] ?: 'unknown', $row['hits'] );
        }

        return wp_mail( $recipient, "True RUM Report: {$site_name}", $body );
    }

    /**
     * Check for TTFB alerts.
     */
    protected function check_alerts() {
        global $wpdb;

        $settings   = $this->plugin->settings()->all();
        $threshold  = floatval( $settings['alert_ttfb_threshold'] );
        $consecutive = absint( $settings['alert_consecutive'] );
        $recipient  = $settings['alert_recipient'];

        if ( $threshold <= 0 || ! $recipient ) {
            return;
        }

        $rows = $wpdb->get_col( $wpdb->prepare( 'SELECT ttfb FROM ' . TRM_TABLE . ' ORDER BY event_time DESC LIMIT %d', max( 20, $consecutive ) ) );

        $streak = 0;
        foreach ( (array) $rows as $ttfb ) {
            if ( floatval( $ttfb ) > $threshold ) {
                $streak++;
            } else {
                break;
            }
        }

        if ( $streak < $consecutive ) {
            return;
        }

        $last_alert = get_option( 'trm_last_alert_ts', 0 );
        $now        = time();
        if ( $now - $last_alert < absint( $settings['alert_min_interval'] ) ) {
            return;
        }

        $subject = sprintf( 'TTFB alert: %d hits over %.2fs', $streak, $threshold );
        $body    = sprintf( "%d consecutive requests exceeded %.2fs TTFB.", $streak, $threshold );

        wp_mail( $recipient, $subject, $body );
        update_option( 'trm_last_alert_ts', $now );
    }
}
