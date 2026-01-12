<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRM_Admin {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
    }

    public function hook() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_menu() {
        add_menu_page(
            'True RUM Monitor',
            'True RUM',
            'manage_options',
            'true-rum-monitor',
            [ $this, 'render_live' ],
            'dashicons-performance',
            80
        );

        add_submenu_page(
            'true-rum-monitor',
            'Live Monitor',
            'Live Monitor',
            'manage_options',
            'true-rum-monitor',
            [ $this, 'render_live' ]
        );

        add_submenu_page(
            'true-rum-monitor',
            'Settings',
            'Settings',
            'manage_options',
            'true-rum-monitor-settings',
            [ $this, 'render_settings' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'true-rum-monitor' ) === false ) {
            return;
        }

        wp_enqueue_style( 'trm-admin-css', TRM_PLUGIN_URL . 'assets/admin/trm-admin.css', [], TRM_VERSION );
        wp_enqueue_script( 'trm-admin-js', TRM_PLUGIN_URL . 'assets/admin/trm-admin.js', [ 'jquery' ], TRM_VERSION, true );

        wp_localize_script( 'trm-admin-js', 'TRMAdminSettings', [
            'restUrl' => get_rest_url( null, 'true-rum/v1/logs' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'loading'   => __( 'Loading…', 'true-rum-monitor' ),
                'empty'     => __( 'No entries yet.', 'true-rum-monitor' ),
                'session'   => __( 'Filter by Session ID', 'true-rum-monitor' ),
                'timestamp' => __( 'Time', 'true-rum-monitor' ),
                'url'       => __( 'URL', 'true-rum-monitor' ),
                'ttfb'      => __( 'TTFB', 'true-rum-monitor' ),
                'lcp'       => __( 'LCP', 'true-rum-monitor' ),
                'load'      => __( 'Total Load', 'true-rum-monitor' ),
                'device'    => __( 'Device', 'true-rum-monitor' ),
                'net'       => __( 'Net', 'true-rum-monitor' ),
                'country'   => __( 'Country', 'true-rum-monitor' ),
            ]
        ]);
    }

    /**
     * Render live log page.
     */
    public function render_live() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Preload logs for instant render
        $preload = TRM_DB::query_logs( [ 'per_page' => 20, 'page' => 1 ] );
        ?>
        <div class="wrap trm-wrap">
            <div class="trm-header">
                <div>
                    <h1>True RUM Monitor</h1>
                    <div class="trm-header-info">
                        <p>Real User Monitoring (RUM) captures performance metrics from actual visitors.</p>
                    </div>
                </div>
                <div class="trm-actions">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=true-rum-monitor-settings' ) ); ?>" class="button button-primary">Settings</a>
                </div>
            </div>

            <div class="trm-info-cards">
                <div class="trm-card-metric">
                    <h3>TTFB (Time to First Byte)</h3>
                    <p>Time from request start until the first byte of response. High TTFB means slow server/PHP/database.</p>
                    <div class="trm-goal">Target: &lt; 0.8s</div>
                </div>
                <div class="trm-card-metric">
                    <h3>LCP (Largest Contentful Paint)</h3>
                    <p>Time until the main content is visible. Affected by TTFB, render-blocking JS/CSS, and image size.</p>
                    <div class="trm-goal">Target: &lt; 2.5s</div>
                </div>
                <div class="trm-card-metric">
                    <h3>Server Gen Time</h3>
                    <p>How long PHP took to generate the HTML. Pure backend execution time (excludes network latency).</p>
                    <div class="trm-goal">Target: &lt; 0.5s</div>
                </div>
            </div>
            
            <div id="trm-live-log" class="trm-live-log" data-preload="<?php echo esc_attr( json_encode( $preload ) ); ?>"></div>
        </div>
        <?php
    }

    /**
     * Render settings page.
     */
    public function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $message  = '';
        $settings = $this->plugin->settings()->all();

        if ( isset( $_POST['trm_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trm_settings_nonce'] ) ), 'trm_save_settings' ) ) {
            $data = wp_unslash( $_POST );
            if ( ! isset( $data['excluded_roles'] ) ) {
                $data['excluded_roles'] = [];
            }
            $settings = $this->plugin->settings()->update( $data );
            $message  = __( 'Settings saved.', 'true-rum-monitor' );
        }

        $wp_roles = wp_roles();
        $all_roles = $wp_roles->get_names();
        ?>
        <div class="wrap trm-wrap">
            <h1>True RUM Settings</h1>
            
            <?php if ( $message ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'trm_save_settings', 'trm_settings_nonce' ); ?>

                <div class="trm-settings-section">
                    <h2>Retention Policy</h2>
                    <div class="trm-field-row">
                        <label for="trm-limit">Max Records</label>
                        <div>
                            <input id="trm-limit" name="limit" type="number" min="100" value="<?php echo esc_attr( $settings['limit'] ); ?>" class="regular-text" />
                            <p class="trm-field-desc">Oldest records are removed when this limit is reached.</p>
                        </div>
                    </div>
                    <div class="trm-field-row">
                        <label for="trm-retention">Retention Limit (Days)</label>
                        <div>
                            <input id="trm-retention" name="retention_days" type="number" min="1" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="regular-text" />
                            <p class="trm-field-desc">Logs older than this will be purged automatically.</p>
                        </div>
                    </div>
                </div>

                <div class="trm-settings-section">
                    <h2>Tracking Rules</h2>
                    <div class="trm-field-row">
                        <label for="trm-sample">Sampling Rate</label>
                        <div>
                            <select id="trm-sample" name="sample_rate" class="regular-text">
                                <option value="1" <?php selected( $settings['sample_rate'], 1.0 ); ?>>100% (All traffic)</option>
                                <option value="0.5" <?php selected( $settings['sample_rate'], 0.5 ); ?>>50%</option>
                                <option value="0.1" <?php selected( $settings['sample_rate'], 0.1 ); ?>>10%</option>
                            </select>
                        </div>
                    </div>
                    <div class="trm-field-row">
                        <label>Excluded Roles</label>
                        <div class="trm-checkbox-list">
                            <?php foreach ( $all_roles as $role_key => $role_name ) : ?>
                                <label class="trm-checkbox-item">
                                    <input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array)$settings['excluded_roles'] ) ); ?> />
                                    <?php echo esc_html( $role_name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="trm-field-desc">Users with these roles will not be tracked.</p>
                    </div>
                    <div class="trm-field-row">
                        <label for="trm-blacklist">Blacklist URLs</label>
                        <div>
                            <textarea id="trm-blacklist" name="blacklist" rows="4" class="large-text code" placeholder="/wp-admin&#10;/checkout"><?php echo esc_textarea( implode( "\n", $settings['blacklist'] ) ); ?></textarea>
                            <p class="trm-field-desc">Enter URL prefixes to ignore (one per line).</p>
                        </div>
                    </div>
                </div>

                <div class="trm-settings-section">
                    <h2>Email Reports</h2>
                    <div class="trm-field-row">
                         <label for="trm-report">Schedule</label>
                         <div>
                            <select id="trm-report" name="report_schedule" class="regular-text">
                                <option value="daily" <?php selected( $settings['report_schedule'], 'daily' ); ?>>Daily</option>
                                <option value="weekly" <?php selected( $settings['report_schedule'], 'weekly' ); ?>>Weekly</option>
                            </select>
                         </div>
                    </div>
                    <div class="trm-field-row">
                        <label for="trm-recipient">Recipient Email</label>
                        <div>
                             <input id="trm-recipient" name="alert_recipient" type="email" value="<?php echo esc_attr( $settings['alert_recipient'] ); ?>" class="regular-text" style="vertical-align:middle;margin-right:10px;" />
                             <button type="button" id="trm-send-test-email" class="button button-secondary">Test Email</button>
                             <p class="trm-field-desc">Where to send performance summaries and alerts.</p>
                        </div>
                    </div>
                </div>

                <div class="trm-settings-section">
                    <h2>Critical Alerts</h2>
                    <div class="trm-field-row">
                        <label for="trm-ttfb">TTFB Threshold (seconds)</label>
                        <div>
                            <input id="trm-ttfb" name="alert_ttfb_threshold" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['alert_ttfb_threshold'] ); ?>" class="small-text" />
                            <p class="trm-field-desc">Trigger an alert if server response time exceeds this value.</p>
                        </div>
                    </div>
                    <div class="trm-field-row">
                        <label for="trm-consecutive">Trigger Logic</label>
                         <div>
                            <input id="trm-consecutive" name="alert_consecutive" type="number" min="1" value="<?php echo esc_attr( $settings['alert_consecutive'] ); ?>" class="small-text" />
                            <span class="description"> consecutive requests</span>
                            <p class="trm-field-desc">How many slow requests in a row trigger the alert.</p>
                        </div>
                    </div>
                    <div class="trm-field-row">
                        <label for="trm-cooldown">Cooldown (seconds)</label>
                        <div>
                             <input id="trm-cooldown" name="alert_min_interval" type="number" min="300" value="<?php echo esc_attr( $settings['alert_min_interval'] ); ?>" class="small-text" />
                             <p class="trm-field-desc">Minimum time between email alerts to avoid spamming.</p>
                        </div>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'true-rum-monitor' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}
