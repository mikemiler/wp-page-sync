<?php
/**
 * REST routes for the site-to-site protocol.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wp-page-sync/v1 routes.
 *
 * Deliberate deviation from the generic "permission_callback with capability
 * check" rule: this namespace is server-to-server and authenticates peers via
 * HMAC request signatures (plan §2/§4.2); there is no WP user context. Every
 * authentication failure answers a uniform 401 without detail (no info leak).
 * Write routes (plan §5, "W") additionally require the peer's allow_incoming
 * flag and answer 403 without it.
 */
class WPPS_Rest {

	/**
	 * Peer store.
	 *
	 * @var WPPS_Peers
	 */
	private $peers;

	/**
	 * Exporter.
	 *
	 * @var WPPS_Exporter
	 */
	private $exporter;

	/**
	 * Importer.
	 *
	 * @var WPPS_Importer
	 */
	private $importer;

	/**
	 * Globals service.
	 *
	 * @var WPPS_Globals
	 */
	private $globals;

	/**
	 * Verifier.
	 *
	 * @var WPPS_Verifier
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @param WPPS_Peers    $peers    Peer store.
	 * @param WPPS_Exporter $exporter Exporter.
	 * @param WPPS_Importer $importer Importer.
	 * @param WPPS_Globals  $globals  Globals service.
	 * @param WPPS_Verifier $verifier Verifier.
	 */
	public function __construct( WPPS_Peers $peers, WPPS_Exporter $exporter, WPPS_Importer $importer, WPPS_Globals $globals, WPPS_Verifier $verifier ) {
		$this->peers    = $peers;
		$this->exporter = $exporter;
		$this->importer = $importer;
		$this->globals  = $globals;
		$this->verifier = $verifier;
	}

	/**
	 * Registers all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			WPPS_REST_NS,
			'/handshake',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_handshake' ),
				'permission_callback' => array( $this, 'permission_handshake' ),
				'args'                => array(
					'site_url'  => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_url_arg' ),
						'sanitize_callback' => 'esc_url_raw',
					),
					'site_name' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'rest_url'  => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_url_arg' ),
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/disconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_disconnect' ),
				'permission_callback' => array( $this, 'permission_peer' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/info',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_info' ),
				'permission_callback' => array( $this, 'permission_peer' ),
			)
		);

		$this->register_sync_routes();
		$this->register_media_routes();
	}

	/**
	 * Registers the Phase B post/globals/verify routes (plan §5).
	 *
	 * @return void
	 */
	private function register_sync_routes() {
		register_rest_route(
			WPPS_REST_NS,
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_posts' ),
				'permission_callback' => array( $this, 'permission_peer' ),
				'args'                => array(
					'post_type' => $this->post_type_arg(),
					'page'      => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'permission_peer' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/post',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => array( $this, 'permission_peer' ),
				'args'                => array(
					'post_type' => $this->post_type_arg(),
					'path'      => $this->path_arg(),
				),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/globals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_globals' ),
				'permission_callback' => array( $this, 'permission_peer' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/import-globals',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import_globals' ),
				'permission_callback' => array( $this, 'permission_peer_write' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/import-post',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import_post' ),
				'permission_callback' => array( $this, 'permission_peer_write' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_verify' ),
				'permission_callback' => array( $this, 'permission_peer' ),
				'args'                => array(
					'offset' => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'  => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Registers the media transfer routes (plan §5).
	 *
	 * @return void
	 */
	private function register_media_routes() {
		register_rest_route(
			WPPS_REST_NS,
			'/media-check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_media_check' ),
				'permission_callback' => array( $this, 'permission_peer' ),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/media-file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_media_file' ),
				'permission_callback' => array( $this, 'permission_peer' ),
				'args'                => array(
					'rel_path' => $this->rel_path_arg(),
				),
			)
		);

		// Single-request upload of senders before 1.3.0, kept for them.
		register_rest_route(
			WPPS_REST_NS,
			'/media-upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_media_upload' ),
				'permission_callback' => array( $this, 'permission_peer_write' ),
				'args'                => array(
					'rel_path' => $this->rel_path_arg(),
					'sha256'   => $this->sha256_arg(),
					'mime'     => $this->mime_arg(),
				),
			)
		);

		register_rest_route(
			WPPS_REST_NS,
			'/media-chunk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_media_chunk' ),
				'permission_callback' => array( $this, 'permission_peer_write' ),
				'args'                => array(
					'rel_path'  => $this->rel_path_arg(),
					'sha256'    => $this->sha256_arg(),
					'mime'      => $this->mime_arg(),
					'upload_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[0-9a-f]{32}\\z',
					),
					'offset'    => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'total'     => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * Shared sha256 route arg.
	 *
	 * @return array
	 */
	private function sha256_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_sha256_arg' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Shared mime route arg (informational; the type gate decides).
	 *
	 * @return array
	 */
	private function mime_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_mime_arg' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Shared post_type route arg (strict enum).
	 *
	 * @return array
	 */
	private function post_type_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'enum'              => WPPS_Exporter::SYNCABLE_POST_TYPES,
			'sanitize_callback' => 'sanitize_key',
		);
	}

	/**
	 * Shared hierarchical-path route arg.
	 *
	 * @return array
	 */
	private function path_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_path_arg' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Shared uploads-relative-path route arg.
	 *
	 * @return array
	 */
	private function rel_path_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_rel_path_arg' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Install-specific dummy secret: unknown key ids still cost one full
	 * HMAC verification, so response timing does not reveal whether a key
	 * id exists. Derived from this installation's auth salt (security
	 * review round 2): a predictable constant would let an unauthenticated
	 * caller produce a "matching" signature and reach the nonce-transient
	 * write inside verify_request; against a salt-derived secret no outside
	 * party can ever validate, so hash_equals fails first and nothing is
	 * stored.
	 *
	 * @return string 64-hex dummy secret.
	 */
	private static function dummy_secret() {
		static $secret = null;
		if ( null === $secret ) {
			$secret = hash_hmac( 'sha256', 'wpps_dummy', wp_salt( 'auth' ) );
		}
		return $secret;
	}

	/**
	 * Shared permission callback for connected peers: verifies the request
	 * signature against the stored peer secret.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permission_peer( $request ) {
		$incomplete = $this->incomplete_body_error( $request );
		if ( $incomplete ) {
			return $incomplete;
		}

		$key_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$peer   = '' === $key_id ? null : $this->peers->get_peer( $key_id );

		$known    = $peer && ! empty( $peer['secret'] );
		$verified = WPPS_Signer::verify_request( $request, $known ? (string) $peer['secret'] : self::dummy_secret() );

		if ( ! $known || ! $verified ) {
			return $this->unauthorized();
		}

		$this->peers->touch( $key_id );
		// Authenticated sync requests hash and move whole media files.
		wpps_raise_limits();

		return true;
	}

	/**
	 * Detects a request body the server dropped or cut short before
	 * WordPress saw it, so it is reported as a size problem and not as a
	 * failed signature ("Unauthorized"). PHP discards the whole body of a
	 * request above post_max_size, while the Content-Length header still
	 * states what the sender sent. It runs before authentication and reveals
	 * nothing about the authentication state, and the message names no
	 * configured value. Accepted deviation: by probing with growing bodies,
	 * an unauthenticated caller can still find the effective body-size limit,
	 * which a plain 401 would hide; the clear error for the paired admin is
	 * worth more than hiding a limit that is no secret.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_Error|null
	 */
	private function incomplete_body_error( WP_REST_Request $request ) {
		$declared = (int) $request->get_header( 'Content-Length' );
		$received = strlen( $request->get_body() );

		if ( $declared > WPPS_MAX_BODY_BYTES || $received > WPPS_MAX_BODY_BYTES ) {
			return new WP_Error( 'wpps_too_large', __( 'The request is too large for this site.', 'wp-page-sync' ), array( 'status' => 413 ) );
		}
		if ( $declared > 0 && $declared !== $received ) {
			return new WP_Error(
				'wpps_body_incomplete',
				sprintf(
					/* translators: %s: size of the request that was sent. */
					__( 'The file is too large for the receiving server: a %s request did not arrive completely. Its upload limit (PHP post_max_size or a proxy body-size limit) is smaller than that.', 'wp-page-sync' ),
					size_format( $declared, 1 )
				),
				array( 'status' => 413 )
			);
		}
		return null;
	}

	/**
	 * Permission callback for /handshake: verifies the signature against a
	 * pending (unclaimed) pairing key instead of a stored peer.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permission_handshake( $request ) {
		$key_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$secret = '' === $key_id ? null : $this->peers->get_pending_secret( $key_id );

		$verified = WPPS_Signer::verify_request( $request, empty( $secret ) ? self::dummy_secret() : $secret );

		if ( empty( $secret ) || ! $verified ) {
			return $this->unauthorized();
		}

		return true;
	}

	/**
	 * Permission callback for write routes (plan §5 "W"): peer signature
	 * plus the peer's allow_incoming flag (else 403).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public function permission_peer_write( $request ) {
		$verified = $this->permission_peer( $request );
		if ( true !== $verified ) {
			return $verified;
		}

		$peer = $this->peers->get_peer( (string) $request->get_header( 'X-WPPS-Key-Id' ) );
		if ( empty( $peer['allow_incoming'] ) ) {
			return new WP_Error(
				'wpps_forbidden',
				__( 'Incoming sync is disabled on this site for this peer.', 'wp-page-sync' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validates a URL argument: http(s) scheme and a non-empty host.
	 *
	 * @param mixed $value Raw argument value.
	 * @return bool
	 */
	public function validate_url_arg( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = wp_parse_url( $value, PHP_URL_HOST );
		return ! empty( $host ) && in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Validates a hierarchical post path argument.
	 *
	 * @param mixed $value Raw argument value.
	 * @return bool
	 */
	public function validate_path_arg( $value ) {
		return WPPS_Importer::validate_post_path( $value );
	}

	/**
	 * Validates an uploads-relative path argument (plan §4.3 rules).
	 *
	 * @param mixed $value Raw argument value.
	 * @return bool
	 */
	public function validate_rel_path_arg( $value ) {
		return WPPS_Importer::validate_rel_path( $value ) && WPPS_Importer::allowed_extension( $value );
	}

	/**
	 * Validates a sha256 hex argument.
	 *
	 * @param mixed $value Raw argument value.
	 * @return bool
	 */
	public function validate_sha256_arg( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[0-9a-f]{64}$/D', $value );
	}

	/**
	 * Validates a mime argument against the transfer whitelist values.
	 *
	 * @param mixed $value Raw argument value.
	 * @return bool
	 */
	public function validate_mime_arg( $value ) {
		return is_string( $value )
			&& ( in_array( $value, array_values( WPPS_Importer::ALLOWED_MIMES ), true ) || 'application/octet-stream' === $value );
	}

	/**
	 * POST /handshake: claims the pending pairing key for the calling site.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_handshake( WP_REST_Request $request ) {
		$site_url  = (string) $request->get_param( 'site_url' );
		$site_name = (string) $request->get_param( 'site_name' );
		$rest_url  = (string) $request->get_param( 'rest_url' );

		foreach ( array( $site_url, $rest_url ) as $url ) {
			$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( 'https' !== $scheme && ! WPPS_Client::is_local_host( $host ) ) {
				return new WP_Error(
					'wpps_https_required',
					__( 'Peer URLs must use HTTPS (plain HTTP is only allowed for local hosts).', 'wp-page-sync' ),
					array( 'status' => 400 )
				);
			}
		}

		$key_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$record = $this->peers->claim_key( $key_id, $site_url, $site_name, $rest_url );
		if ( ! $record ) {
			return $this->unauthorized();
		}

		/* translators: %s: peer site URL. */
		WPPS_Log::add( 'connected', sprintf( __( 'Paired with %s.', 'wp-page-sync' ), $site_url ) );

		return rest_ensure_response(
			array(
				'site_name'   => get_bloginfo( 'name' ),
				'uploads_url' => wp_upload_dir( null, false )['baseurl'],
				'rest_url'    => rest_url(),
			)
		);
	}

	/**
	 * POST /disconnect: the peer notifies that it removed the pairing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_disconnect( WP_REST_Request $request ) {
		$key_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$peer   = $this->peers->get_peer( $key_id );
		$name   = $peer && ! empty( $peer['name'] ) ? (string) $peer['name'] : $key_id;

		$this->peers->remove_peer( $key_id );
		/* translators: %s: peer name. */
		WPPS_Log::add( 'disconnected', sprintf( __( 'Peer %s disconnected.', 'wp-page-sync' ), $name ) );

		return rest_ensure_response( array( 'disconnected' => true ) );
	}

	/**
	 * GET /info: basic site info for pings and record refreshes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_info( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return rest_ensure_response(
			array(
				'site_name'      => get_bloginfo( 'name' ),
				'home_url'       => home_url(),
				'rest_url'       => rest_url(),
				'uploads_url'    => wp_upload_dir( null, false )['baseurl'],
				'wp_version'     => get_bloginfo( 'version' ),
				'plugin_version' => WPPS_VERSION,
			)
		);
	}

	/**
	 * GET /posts: paged list of syncable posts.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_posts( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$list     = $this->exporter->list_posts( (string) $request->get_param( 'post_type' ), $page, $per_page );

		return rest_ensure_response(
			array(
				'items'    => $list['items'],
				'total'    => $list['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * POST /status: batch status for {items:[{post_type, path}]} plus the
	 * globals hash and this peer's stored sync state (plan §5).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_status( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$items = ( is_array( $body ) && isset( $body['items'] ) && is_array( $body['items'] ) ) ? $body['items'] : array();
		if ( count( $items ) > 500 ) {
			return new WP_Error( 'wpps_too_many_items', __( 'Too many status items in one request.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}

		$peer_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$results = array();
		foreach ( $items as $item ) {
			$post_type = ( is_array( $item ) && isset( $item['post_type'] ) ) ? (string) $item['post_type'] : '';
			$path      = ( is_array( $item ) && isset( $item['path'] ) ) ? (string) $item['path'] : '';
			if ( ! in_array( $post_type, WPPS_Exporter::SYNCABLE_POST_TYPES, true ) || ! WPPS_Importer::validate_post_path( $path ) ) {
				continue;
			}
			$results[] = $this->status_item( $post_type, $path, $peer_id );
		}

		return rest_ensure_response(
			array(
				'items'        => $results,
				'globals_hash' => $this->globals->hash(),
			)
		);
	}

	/**
	 * Builds one /status item.
	 *
	 * @param string $post_type Post type.
	 * @param string $path      Path.
	 * @param string $peer_id   Authenticated peer id.
	 * @return array
	 */
	private function status_item( $post_type, $path, $peer_id ) {
		$post = $this->exporter->find_post( $post_type, $path );
		if ( ! $post ) {
			return array(
				'post_type' => $post_type,
				'path'      => $path,
				'exists'    => false,
			);
		}

		return array(
			'post_type'      => $post_type,
			'path'           => $path,
			'exists'         => true,
			'id'             => $post->ID,
			'modified_gmt'   => $post->post_modified_gmt,
			'hash'           => $this->exporter->content_hash( $post ),
			'state_for_peer' => WPPS_Sync::read_state( $post->ID, $peer_id ),
		);
	}

	/**
	 * GET /post: full export bundle for one post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_post( WP_REST_Request $request ) {
		$bundle = $this->exporter->export( (string) $request->get_param( 'post_type' ), (string) $request->get_param( 'path' ) );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		return rest_ensure_response( $bundle );
	}

	/**
	 * GET /globals: global-styles bundle (plan §6.6).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_globals( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		return rest_ensure_response( $this->globals->export() );
	}

	/**
	 * POST /import-globals (W): upsert the globals bundle + cache rebuilds.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_import_globals( WP_REST_Request $request ) {
		$bundle = $request->get_json_params();
		if ( ! is_array( $bundle ) || ! isset( $bundle['gb_styles'] ) || ! is_array( $bundle['gb_styles'] ) ) {
			return new WP_Error( 'wpps_invalid_bundle', __( 'Invalid globals bundle.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}

		$result = $this->globals->import( $bundle );
		/* translators: %s: peer name or id. */
		WPPS_Log::add( 'globals_import', sprintf( __( 'Global styles imported from peer %s.', 'wp-page-sync' ), $this->peer_label( $request ) ), $result['warnings'] );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /import-post (W): upsert a post bundle; media must already be
	 * present. Writes this side's sync state meta (plan §6.4).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_import_post( WP_REST_Request $request ) {
		$bundle = $request->get_json_params();
		if ( ! is_array( $bundle ) ) {
			return new WP_Error( 'wpps_invalid_bundle', __( 'Invalid post bundle.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}

		$result = $this->importer->import_post( $bundle );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$peer_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$source  = ( isset( $bundle['source'] ) && is_array( $bundle['source'] ) ) ? $bundle['source'] : array();
		WPPS_Sync::write_state(
			(int) $result['id'],
			$peer_id,
			isset( $source['post_id'] ) ? (int) $source['post_id'] : 0,
			(string) $result['hash'],
			isset( $source['hash'] ) ? (string) $source['hash'] : ''
		);

		/* translators: 1: post path, 2: peer name or id. */
		WPPS_Log::add( 'import', sprintf( __( 'Received %1$s from peer %2$s.', 'wp-page-sync' ), (string) $bundle['post']['path'], $this->peer_label( $request ) ), $result['warnings'] );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /media-check: identical / different / missing per manifest file.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_media_check( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$items = ( is_array( $body ) && isset( $body['items'] ) && is_array( $body['items'] ) ) ? $body['items'] : array();
		if ( count( $items ) > 500 ) {
			return new WP_Error( 'wpps_too_many_items', __( 'Too many media items in one request.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'items' => $this->importer->media_check( $items ) ) );
	}

	/**
	 * GET /media-file: one upload file as a raw binary response via the
	 * rest_pre_serve_request short-circuit (plan §5). The rel_path arg is
	 * §4.3-validated and extension-whitelisted by the route args.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_media_file( WP_REST_Request $request ) {
		$rel     = (string) $request->get_param( 'rel_path' );
		$basedir = wp_upload_dir( null, false )['basedir'];
		$abs     = realpath( $basedir . '/' . $rel );
		$base    = realpath( $basedir );

		if ( false === $abs || false === $base || 0 !== strpos( $abs, $base . '/' ) || ! is_file( $abs ) ) {
			return new WP_Error( 'wpps_not_found', __( 'File not found.', 'wp-page-sync' ), array( 'status' => 404 ) );
		}

		$filetype = wp_check_filetype( basename( $abs ), WPPS_Importer::ALLOWED_MIMES );
		$mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';
		$sha256   = hash_file( 'sha256', $abs );

		add_filter(
			'rest_pre_serve_request',
			function ( $served ) use ( $abs, $mime, $sha256 ) {
				if ( $served ) {
					return $served;
				}
				header( 'Content-Type: ' . $mime );
				header( 'Content-Length: ' . (string) filesize( $abs ) );
				header( 'X-WPPS-Sha256: ' . $sha256 );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated uploads file is the purpose of this route.
				readfile( $abs );
				return true;
			}
		);

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * POST /media-upload (W): one file as raw binary body (plan §5), with
	 * rel_path/sha256/mime as signed query args. Idempotent. Used by senders
	 * before 1.3.0 only; the body size is bounded by the signer's
	 * WPPS_MAX_BODY_BYTES guard.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_media_upload( WP_REST_Request $request ) {
		$temp = WPPS_Importer::temp_file();
		if ( is_wp_error( $temp ) ) {
			return $temp;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own temp file inside uploads; import_media() consumes it.
		if ( false === file_put_contents( $temp, $request->get_body(), LOCK_EX ) ) {
			WPPS_Importer::discard( $temp );
			return new WP_Error( 'wpps_write_failed', __( 'Could not store the received file.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			$this->importer->import_media( (string) $request->get_param( 'rel_path' ), (string) $request->get_param( 'sha256' ), $temp )
		);
	}

	/**
	 * POST /media-chunk (W, 1.3.0): one piece of a file as raw binary body,
	 * with rel_path/sha256/mime/upload_id/offset/total as signed query args.
	 * The completing piece answers with the import result.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_media_chunk( WP_REST_Request $request ) {
		$chunk = array( 'peer_id' => (string) $request->get_header( 'X-WPPS-Key-Id' ) );
		foreach ( array( 'upload_id', 'rel_path', 'sha256', 'offset', 'total' ) as $key ) {
			$chunk[ $key ] = $request->get_param( $key );
		}
		return rest_ensure_response( $this->importer->receive_chunk( $chunk, $request->get_body() ) );
	}

	/**
	 * POST /verify: run the verifier on this site (batched).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_verify( WP_REST_Request $request ) {
		return rest_ensure_response(
			$this->verifier->run( (int) $request->get_param( 'offset' ), (int) $request->get_param( 'limit' ) )
		);
	}

	/**
	 * Peer display label for log entries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	private function peer_label( WP_REST_Request $request ) {
		$key_id = (string) $request->get_header( 'X-WPPS-Key-Id' );
		$peer   = $this->peers->get_peer( $key_id );
		return ( $peer && ! empty( $peer['name'] ) ) ? (string) $peer['name'] : $key_id;
	}

	/**
	 * Uniform 401 for every authentication failure (plan §4.3, no info leak).
	 *
	 * @return WP_Error
	 */
	private function unauthorized() {
		return new WP_Error(
			'wpps_unauthorized',
			__( 'Unauthorized.', 'wp-page-sync' ),
			array( 'status' => 401 )
		);
	}
}
