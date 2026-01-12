<?php
/**
 * REST API endpoints for True RUM Monitor.
 *
 * @package TrueRUMMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRM_REST {

    /**
     * REST namespace.
     */
    const NS = 'true-rum/v1';

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
     * Hook routes.
     */
    public function hook() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route(
            self::NS,
            '/collect',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'collect' ),
                'permission_callback' => '__return_true',
                'args'                => array(),
            )
        );

        register_rest_route(
            self::NS,
            '/logs',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'logs' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
                'args'                => array(
                    'page'       => array( 'default' => 1 ),
                    'per_page'   => array( 'default' => 50 ),
                    'order'      => array( 'default' => 'desc' ),
                    'order_by'   => array( 'default' => 'event_time' ),
                    'session_id' => array(),
                ),
            )
        );

        register_rest_route(
            self::NS,
            '/stats',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'stats' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
                'args'                => array(),
            )
        );

        register_rest_route(
            self::NS,
            '/send-report',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'send_report' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
                'args'                => array(),
            )
        );
    }

    /**
     * Nonce verification for public collector.
     * 
     * Uses query param 'trm_token' or Header 'X-TRM-Nonce'.
     * Avoids '_wpnonce' to bypass Core's premature cookie auth checks.
     *
     * @return bool
     */
    public function verify_custom_nonce() {
        $nonce = null;

        if ( isset( $_SERVER['HTTP_X_TRM_NONCE'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TRM_NONCE'] ) );
        } elseif ( isset( $_GET['trm_token'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['trm_token'] ) );
        }

        if ( ! $nonce ) {
            return false;
        }

        return (bool) wp_verify_nonce( $nonce, 'trm_collect' );
    }

    /**
     * Collector ingestion endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function collect( WP_REST_Request $request ) {
        // Validation of custom header manual to avoid core rest_cookie_invalid_nonce interference
        if ( ! $this->verify_custom_nonce() ) {
             return new WP_Error( 'trm_forbidden', __( 'Invalid security token', 'true-rum-monitor' ), array( 'status' => 403 ) );
        }

        if ( ! $this->plugin->should_track_request() ) {
            return new WP_REST_Response( array( 'status' => 'skipped' ), 202 );
        }

        $body = $request->get_body();
        if ( empty( $body ) ) {
            return new WP_Error( 'trm_empty', __( 'Empty payload', 'true-rum-monitor' ), array( 'status' => 400 ) );
        }

        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'trm_json_error', __( 'Invalid JSON', 'true-rum-monitor' ), array( 'status' => 400 ) );
        }

        $settings = $this->plugin->settings()->all();

        $row = array(
            'event_time'  => isset( $payload['event_time'] ) ? sanitize_text_field( $payload['event_time'] ) : current_time( 'mysql', true ),
            'url'         => isset( $payload['url'] ) ? sanitize_text_field( $payload['url'] ) : '',
            'server_time' => isset( $payload['server_time'] ) ? floatval( $payload['server_time'] ) : 0,
            'ttfb'        => isset( $payload['ttfb'] ) ? floatval( $payload['ttfb'] ) : 0,
            'lcp'         => isset( $payload['lcp'] ) ? floatval( $payload['lcp'] ) : 0,
            'total_load'  => isset( $payload['total_load'] ) ? floatval( $payload['total_load'] ) : 0,
            'memory_peak' => isset( $payload['memory_peak'] ) ? absint( $payload['memory_peak'] ) : 0,
            'device'      => isset( $payload['device'] ) ? sanitize_text_field( $payload['device'] ) : '',
            'net'         => isset( $payload['net'] ) ? sanitize_text_field( $payload['net'] ) : '',
            'country'     => isset( $payload['country'] ) ? sanitize_text_field( $payload['country'] ) : '',
            'session_id'  => isset( $payload['session_id'] ) ? sanitize_text_field( $payload['session_id'] ) : '',
            'user_role'   => $this->get_user_role(),
            'meta'        => array(),
        );

        TRM_DB::insert( $row );
        TRM_DB::purge_older_than( $settings['retention_days'] );
        TRM_DB::enforce_limit( $settings['limit'] );

        return new WP_REST_Response( array( 'status' => 'ok' ), 201 );
    }

    /**
     * Admin logs endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function logs( WP_REST_Request $request ) {
        $filter_params = $this->get_filter_params( $request );
        $data = TRM_DB::query_logs( $filter_params );

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Admin stats endpoint.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function stats( WP_REST_Request $request ) {
        $filter_params = $this->get_filter_params( $request );
        $data = TRM_DB::get_stats( $filter_params );

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * Trigger email report manually.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function send_report( WP_REST_Request $request ) {
        $sent = $this->plugin->reports()->send_report();
        if ( $sent ) {
            return new WP_REST_Response( array( 'status' => 'sent' ), 200 );
        }
        return new WP_REST_Response( array( 'status' => 'failed', 'message' => 'Check mail settings or recipient' ), 500 );
    }

    /**
     * Helper to extract filters.
     * 
     * @param WP_REST_Request $request
     * @return array
     */
    protected function get_filter_params( $request ) {
        $filter_params = array();
        
        $params = [ 'page', 'per_page', 'order', 'order_by', 'session_id', 'url', 'device', 'net' ];
        foreach ($params as $param) {
            $val = $request->get_param($param);
            if ($val !== null) {
                $filter_params[$param] = $val;
            }
        }

        // For filters (rest params can be empty strings)
        if (isset($filter_params['url']) && $filter_params['url'] === '') unset($filter_params['url']);
        if (isset($filter_params['device']) && $filter_params['device'] === '') unset($filter_params['device']);
        if (isset($filter_params['net']) && $filter_params['net'] === '') unset($filter_params['net']);

        return $filter_params;
    }

    /**
     * Resolve current user role for logging.
     *
     * @return string
     */
    protected function get_user_role() {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user = wp_get_current_user();
        return isset( $user->roles[0] ) ? sanitize_text_field( $user->roles[0] ) : '';
    }
}
