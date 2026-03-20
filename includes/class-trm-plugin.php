<?php
/**
 * Core bootstrap for True RUM Monitor.
 *
 * @package TrueRUMMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once TRM_PLUGIN_DIR . 'includes/class-trm-settings.php';
require_once TRM_PLUGIN_DIR . 'includes/class-trm-db.php';
require_once TRM_PLUGIN_DIR . 'includes/class-trm-rest.php';
require_once TRM_PLUGIN_DIR . 'includes/class-trm-collector.php';
require_once TRM_PLUGIN_DIR . 'includes/class-trm-admin.php';
require_once TRM_PLUGIN_DIR . 'includes/class-trm-reports.php';

/**
 * Core bootstrap class for True RUM Monitor.
 */
class TRM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var TRM_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var TRM_Settings
	 */
	protected $settings;

	/**
	 * Collector instance.
	 *
	 * @var TRM_Collector
	 */
	protected $collector;

	/**
	 * REST handler.
	 *
	 * @var TRM_REST
	 */
	protected $rest;

	/**
	 * Admin UI handler.
	 *
	 * @var TRM_Admin
	 */
	protected $admin;

	/**
	 * Reports/alerts handler.
	 *
	 * @var TRM_Reports
	 */
	protected $reports;

	/**
	 * Get singleton.
	 *
	 * @return TRM_Plugin
	 */
	public static function instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->settings  = new TRM_Settings();
		$this->collector = new TRM_Collector( $this );
		$this->rest      = new TRM_REST( $this );
		$this->admin     = new TRM_Admin( $this );
		$this->reports   = new TRM_Reports( $this );

		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Init hooks.
	 */
	public function init(): void {
		$this->collector->hook();
		$this->rest->hook();
		$this->admin->hook();
		$this->reports->hook();

		add_action( 'admin_init', array( $this, 'register_privacy_content' ) );

		/**
		 * Fires after True RUM Monitor is fully loaded.
		 *
		 * @param TRM_Plugin $plugin Plugin instance.
		 */
		do_action( 'trm_loaded', $this );
	}

	/**
	 * Activation handler.
	 */
	public static function activate(): void {
		TRM_DB::create_table();

		$instance = self::instance();
		$instance->reports->register_cron();
	}

	/**
	 * Deactivation handler.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( TRM_Reports::CRON_HOOK );
	}

	/**
	 * Get settings handler.
	 *
	 * @return TRM_Settings
	 */
	public function settings(): TRM_Settings {
		return $this->settings;
	}

	/**
	 * Register privacy policy suggested content.
	 */
	public function register_privacy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__( 'True RUM Monitor', 'true-rum-monitor' ),
			__( 'This plugin collects anonymized performance metrics (page load times, device type, network type) from site visitors. No personally identifiable information (PII) is collected or stored.', 'true-rum-monitor' ),
			__( 'Session IDs are randomly generated per browser tab using sessionStorage and are not linked to user accounts. No cookies are set. No data is sent to external services — all collected data is stored locally in your WordPress database.', 'true-rum-monitor' ),
			__( 'Collected data is automatically purged based on configured retention settings.', 'true-rum-monitor' )
		);

		wp_add_privacy_policy_content( 'True RUM Monitor', wp_kses_post( $content ) );
	}

	/**
	 * Get reports handler.
	 *
	 * @return TRM_Reports
	 */
	public function reports(): TRM_Reports {
		return $this->reports;
	}

	/**
	 * Check if request should be sampled and recorded.
	 *
	 * @return bool
	 */
	public function should_track_request(): bool {
		$settings = $this->settings->all();

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( array_intersect( $settings['excluded_roles'], (array) $user->roles ) ) {
				return false;
			}
		}

		$raw_uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path      = (string) wp_parse_url( $raw_uri, PHP_URL_PATH );
		$blacklist = $settings['blacklist'];
		foreach ( $blacklist as $prefix ) {
			if ( $prefix && 0 === strpos( $path, $prefix ) ) {
				return false;
			}
		}

		$sample = $settings['sample_rate'];
		if ( $sample < 1 && wp_rand( 0, 1000 ) / 1000 > $sample ) {
			return false;
		}

		/**
		 * Filter whether the current request should be tracked.
		 *
		 * @param bool  $track    Whether to track the request.
		 * @param array $settings Current plugin settings.
		 */
		return apply_filters( 'trm_should_track_request', true, $settings );
	}

	/**
	 * Provide server-side metrics for JS collector.
	 *
	 * @return array
	 */
	public function get_server_context(): array {
		$server_time = $this->get_server_time();
		$memory_peak = memory_get_peak_usage();
		$country     = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : '';

		return array(
			'serverTime' => $server_time,
			'memoryPeak' => $memory_peak,
			'country'    => $country,
		);
	}

	/**
	 * Compute server render time using REQUEST_TIME_FLOAT baseline.
	 *
	 * @return float Seconds with micro precision.
	 */
	protected function get_server_time(): float {
		$now = microtime( true );

		// Use REQUEST_TIME_FLOAT if available (most reliable for total generic time).
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Numeric timestamp, cast to float.
			$start = (float) $_SERVER['REQUEST_TIME_FLOAT'];
		} elseif ( ! empty( $GLOBALS['timestart'] ) ) {
			// Fallback to WP global start time.
			$start = (float) $GLOBALS['timestart'];
		} else {
			// Last resort: assume 0 latency (should not happen in WP).
			$start = $now;
		}

		$elapsed = $now - $start;

		return max( 0, round( $elapsed, 4 ) );
	}
}
