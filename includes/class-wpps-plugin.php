<?php
/**
 * Plugin wiring.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin services and registers the top-level hooks.
 */
final class WPPS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPPS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Peer store.
	 *
	 * @var WPPS_Peers
	 */
	private $peers;

	/**
	 * Outgoing signed HTTP client.
	 *
	 * @var WPPS_Client
	 */
	private $client;

	/**
	 * REST controller.
	 *
	 * @var WPPS_Rest
	 */
	private $rest;

	/**
	 * Admin page + AJAX controller (admin requests only).
	 *
	 * @var WPPS_Admin|null
	 */
	private $admin = null;

	/**
	 * Returns the shared plugin instance, booting it on first call.
	 *
	 * @return WPPS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Instantiates the services and registers hooks.
	 */
	private function __construct() {
		$this->peers  = new WPPS_Peers();
		$this->client = new WPPS_Client();

		$exporter = new WPPS_Exporter( $this->peers );
		$importer = new WPPS_Importer( $exporter );
		$globals  = new WPPS_Globals( $exporter, $importer );
		$verifier = new WPPS_Verifier( $exporter, $globals );
		$sync     = new WPPS_Sync( $this->peers, $this->client, $exporter, $importer, $globals );

		$this->rest = new WPPS_Rest( $this->peers, $exporter, $importer, $globals, $verifier );

		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );
		// Registered at load time: core may resolve the current user before rest_api_init.
		add_filter( 'application_password_is_api_request', array( 'WPPS_Rest', 'filter_app_password_api_request' ) );

		if ( is_admin() ) {
			$this->admin = new WPPS_Admin( $this->peers, $this->client, $sync, $verifier );
			$this->admin->register();
		}
	}
}
