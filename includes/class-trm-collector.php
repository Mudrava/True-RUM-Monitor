<?php
/**
 * Frontend collector enqueuer.
 *
 * @package TrueRUMMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRM_Collector {

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
     * Hook into WordPress.
     */
    public function hook() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wp_footer', array( $this, 'print_settings' ), 1 );
    }

    /**
     * Enqueue collector script on frontend.
     */
    public function enqueue() {
        if ( is_admin() ) {
            return;
        }

        if ( ! $this->plugin->should_track_request() ) {
            return;
        }

        $handle = 'trm-collector';

        wp_register_script(
            $handle,
            TRM_PLUGIN_URL . 'assets/js/trm-collector.js',
            array(),
            TRM_VERSION,
            true
        );

        wp_enqueue_script( $handle );
    }

    /**
     * Output settings in footer to capture full server time.
     */
    public function print_settings() {
        if ( is_admin() ) {
            return;
        }

        if ( ! $this->plugin->should_track_request() ) {
            return;
        }

        $context = $this->plugin->get_server_context();

        $localize = array(
            'restUrl'   => esc_url_raw( rest_url( 'true-rum/v1/collect' ) ),
            'nonce'     => wp_create_nonce( 'trm_collect' ),
            'timestamp' => current_time( 'mysql', true ),
            'server'    => array(
                'time'       => $context['serverTime'],
                'memoryPeak' => $context['memoryPeak'],
                'country'    => $context['country'],
            ),
            'sessionKey' => 'trm_session_id',
        );

        echo '<script type="text/javascript">';
        echo 'var TRMCollectorSettings = ' . wp_json_encode( $localize ) . ';';
        echo '</script>';
    }
}
