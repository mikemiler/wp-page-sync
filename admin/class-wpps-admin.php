<?php
/**
 * Admin page and AJAX handlers.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "WP Page Sync" admin page (tab router) and handles the
 * Connections-tab AJAX actions with the hardened pattern:
 * check_ajax_referer + current_user_can('manage_options') + per-type
 * sanitization + wp_send_json_*.
 */
class WPPS_Admin {

	const PAGE_SLUG    = 'wp-page-sync';
	const NONCE_ACTION = 'wpps_admin';

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
	 * Sync orchestration.
	 *
	 * @var WPPS_Sync
	 */
	private $sync;

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
	 * @param WPPS_Client   $client   Signed HTTP client.
	 * @param WPPS_Sync     $sync     Sync orchestration.
	 * @param WPPS_Verifier $verifier Verifier.
	 */
	public function __construct( WPPS_Peers $peers, WPPS_Client $client, WPPS_Sync $sync, WPPS_Verifier $verifier ) {
		$this->peers    = $peers;
		$this->client   = $client;
		$this->sync     = $sync;
		$this->verifier = $verifier;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpps_create_key', array( $this, 'ajax_create_key' ) );
		add_action( 'wp_ajax_wpps_connect', array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_wpps_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_wpps_toggle_incoming', array( $this, 'ajax_toggle_incoming' ) );
		add_action( 'wp_ajax_wpps_ping', array( $this, 'ajax_ping' ) );
		add_action( 'wp_ajax_wpps_status_list', array( $this, 'ajax_status_list' ) );
		add_action( 'wp_ajax_wpps_peer_posts', array( $this, 'ajax_peer_posts' ) );
		add_action( 'wp_ajax_wpps_sync_preview', array( $this, 'ajax_sync_preview' ) );
		add_action( 'wp_ajax_wpps_push', array( $this, 'ajax_push' ) );
		add_action( 'wp_ajax_wpps_pull', array( $this, 'ajax_pull' ) );
		add_action( 'wp_ajax_wpps_globals_preview', array( $this, 'ajax_globals_preview' ) );
		add_action( 'wp_ajax_wpps_globals_sync', array( $this, 'ajax_globals_sync' ) );
		add_action( 'wp_ajax_wpps_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_wpps_get_log', array( $this, 'ajax_get_log' ) );
	}

	/**
	 * Adds the top-level "WP Page Sync" menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'WP Page Sync', 'wp-page-sync' ),
			__( 'WP Page Sync', 'wp-page-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-update'
		);
	}

	/**
	 * Enqueues admin assets on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpps-admin', WPPS_PLUGIN_URL . 'admin/css/wpps-admin.css', array(), WPPS_VERSION );
		wp_enqueue_script( 'wpps-admin', WPPS_PLUGIN_URL . 'admin/js/wpps-admin.js', array( 'jquery' ), WPPS_VERSION, true );
		wp_localize_script(
			'wpps-admin',
			'wppsAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'verifyBatch' => 10,
				'statuses'    => array(
					'in_sync'         => __( 'In sync', 'wp-page-sync' ),
					'in_sync_adopted' => __( 'In sync (adopted)', 'wp-page-sync' ),
					'local_newer'     => __( 'Local newer', 'wp-page-sync' ),
					'peer_newer'      => __( 'Peer newer', 'wp-page-sync' ),
					'diverged'        => __( 'Diverged', 'wp-page-sync' ),
					'never_synced'    => __( 'Differs (never synced)', 'wp-page-sync' ),
					'missing_on_peer' => __( 'Missing on peer', 'wp-page-sync' ),
					'missing_local'   => __( 'New from peer', 'wp-page-sync' ),
					'no_slug'         => __( 'No slug (unsyncable)', 'wp-page-sync' ),
				),
				'i18n'        => array(
					'copied'            => __( 'Key copied to clipboard.', 'wp-page-sync' ),
					'copyFailed'        => __( 'Copy failed. Select the key and copy it manually.', 'wp-page-sync' ),
					'connecting'        => __( 'Connecting…', 'wp-page-sync' ),
					'keyRequired'       => __( 'Please paste a connection key.', 'wp-page-sync' ),
					'pinging'           => __( 'Pinging…', 'wp-page-sync' ),
					'confirmDisconnect' => __( 'Disconnect this site? Sync operations with it will stop working.', 'wp-page-sync' ),
					'error'             => __( 'Request failed. Please try again.', 'wp-page-sync' ),
					'loading'           => __( 'Loading…', 'wp-page-sync' ),
					'noPeer'            => __( 'Connect a site first (Connections tab).', 'wp-page-sync' ),
					'noPosts'           => __( 'No posts found.', 'wp-page-sync' ),
					'push'              => __( 'Push', 'wp-page-sync' ),
					'pull'              => __( 'Pull', 'wp-page-sync' ),
					'pushTitle'         => __( 'Push to peer', 'wp-page-sync' ),
					'pullTitle'         => __( 'Pull from peer', 'wp-page-sync' ),
					'confirm'           => __( 'Confirm', 'wp-page-sync' ),
					'working'           => __( 'Working…', 'wp-page-sync' ),
					'done'              => __( 'Done.', 'wp-page-sync' ),
					'mediaFiles'        => __( 'Referenced media files:', 'wp-page-sync' ),
					'mediaTransfer'     => __( 'To transfer:', 'wp-page-sync' ),
					'forceLabel'        => __( 'Force: the copy on the target differs from this one and will be overwritten. Its current content stays available there as a post revision.', 'wp-page-sync' ),
					'preflightErrors'   => __( 'Blocking problems (fix at the source first):', 'wp-page-sync' ),
					'warnings'          => __( 'Warnings:', 'wp-page-sync' ),
					'bulkConfirm'       => __( 'Push the selected posts (parents first)? Conflicting posts are skipped.', 'wp-page-sync' ),
					'bulkNone'          => __( 'Select at least one post.', 'wp-page-sync' ),
					'bulkDone'          => __( 'Bulk push finished:', 'wp-page-sync' ),
					'pushed'            => __( 'pushed', 'wp-page-sync' ),
					'skipped'           => __( 'skipped', 'wp-page-sync' ),
					'failed'            => __( 'failed', 'wp-page-sync' ),
					'bulkSkipped'       => __( 'Skipped as conflicts (push each one on its own and confirm the force option):', 'wp-page-sync' ),
					'bulkFailed'        => __( 'Failed:', 'wp-page-sync' ),
					'globalsAdded'      => __( 'Added classes', 'wp-page-sync' ),
					'globalsChanged'    => __( 'Changed classes', 'wp-page-sync' ),
					'globalsOnlyTarget' => __( 'Only on target (kept, never deleted)', 'wp-page-sync' ),
					'colorsChanged'     => __( 'Color changes', 'wp-page-sync' ),
					'globalsInSync'     => __( 'Global styles are in sync.', 'wp-page-sync' ),
					'globalsDiffer'     => __( 'Global styles differ.', 'wp-page-sync' ),
					'globalsUnknown'    => __( 'Load the status to compare global styles.', 'wp-page-sync' ),
					'verifyRunning'     => __( 'Verifying…', 'wp-page-sync' ),
					'verifyClean'       => __( 'No problems found.', 'wp-page-sync' ),
					'verifyErrors'      => __( 'Errors', 'wp-page-sync' ),
					'verifyWarnings'    => __( 'Warnings', 'wp-page-sync' ),
					'colPost'           => __( 'Post', 'wp-page-sync' ),
					'colLocation'       => __( 'Location', 'wp-page-sync' ),
					'colDetail'         => __( 'Detail', 'wp-page-sync' ),
					'colProblem'        => __( 'Problem', 'wp-page-sync' ),
					/* translators: 1: number of scanned posts, 2: total number of posts. */
					'scannedOf'         => __( 'Scanned %1$s of %2$s posts.', 'wp-page-sync' ),
					'peerOnlyEmpty'     => __( 'No posts exist only on the peer.', 'wp-page-sync' ),
					'existsLocal'       => __( 'Also exists here', 'wp-page-sync' ),
					'peerOnly'          => __( 'Only on peer', 'wp-page-sync' ),
				),
			)
		);
	}

	/**
	 * Renders the admin page with the tab router.
	 *
	 * @return void
	 */
	public function render_page() {
		$tabs = array(
			'connections' => __( 'Connections', 'wp-page-sync' ),
			'sync'        => __( 'Sync', 'wp-page-sync' ),
			'verify'      => __( 'Verify', 'wp-page-sync' ),
			'log'         => __( 'Log', 'wp-page-sync' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view routing, no state change.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connections';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'connections';
		}

		echo '<div class="wrap wpps-wrap">';
		echo '<h1>' . esc_html__( 'WP Page Sync', 'wp-page-sync' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab_key => $label ) {
			$url   = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $tab_key,
				),
				admin_url( 'admin.php' )
			);
			$class = 'nav-tab' . ( $active === $tab_key ? ' nav-tab-active' : '' );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		$peers = $this->peers->get_peers();
		if ( 'sync' === $active ) {
			require WPPS_PLUGIN_DIR . 'admin/partials/tab-sync.php';
		} elseif ( 'verify' === $active ) {
			require WPPS_PLUGIN_DIR . 'admin/partials/tab-verify.php';
		} elseif ( 'log' === $active ) {
			$entries = array_reverse( WPPS_Log::get_entries() );
			require WPPS_PLUGIN_DIR . 'admin/partials/tab-log.php';
		} else {
			require WPPS_PLUGIN_DIR . 'admin/partials/tab-connections.php';
		}

		echo '</div>';
	}

	/**
	 * AJAX: create a single-use pairing key.
	 *
	 * @return void
	 */
	public function ajax_create_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$key = $this->peers->create_key();

		wp_send_json_success(
			array(
				'key_id' => $key['key_id'],
				'blob'   => $key['blob'],
			)
		);
	}

	/**
	 * AJAX: connect to a peer using a pasted pairing key.
	 *
	 * @return void
	 */
	public function ajax_connect() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$raw = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $raw ) {
			wp_send_json_error( array( 'message' => __( 'Please paste a connection key.', 'wp-page-sync' ) ) );
		}

		$data = WPPS_Peers::parse_key_blob( $raw );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		if ( untrailingslashit( $data['rest'] ) === untrailingslashit( rest_url() ) ) {
			wp_send_json_error( array( 'message' => __( 'This key belongs to this site. Paste it on the site you want to connect.', 'wp-page-sync' ) ) );
		}
		if ( $this->peers->get_peer( $data['key_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A connection with this key already exists.', 'wp-page-sync' ) ) );
		}

		$peer = array(
			'peer_id'        => $data['key_id'],
			'name'           => (string) wp_parse_url( $data['site'], PHP_URL_HOST ),
			'url'            => $data['site'],
			'rest_url'       => $data['rest'],
			'uploads_url'    => '',
			'secret'         => $data['secret'],
			'connected_at'   => time(),
			'allow_incoming' => WPPS_Peers::default_allow_incoming(),
			'last_seen'      => 0,
		);
		$this->peers->add_peer( $peer );

		$response = $this->client->handshake( $peer );
		if ( is_wp_error( $response ) ) {
			// Provisional-peer cleanup on failed handshake (plan §4.1).
			$this->peers->remove_peer( $data['key_id'] );
			/* translators: 1: peer site URL, 2: error message. */
			WPPS_Log::add( 'connect_failed', sprintf( __( 'Connecting to %1$s failed: %2$s', 'wp-page-sync' ), $data['site'], $response->get_error_message() ) );
			/* translators: %s: error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Handshake failed: %s', 'wp-page-sync' ), $response->get_error_message() ) ) );
		}

		$this->apply_peer_info( $data['key_id'], $response );
		/* translators: %s: peer site URL. */
		WPPS_Log::add( 'connected', sprintf( __( 'Connected to %s.', 'wp-page-sync' ), $data['site'] ) );

		wp_send_json_success( array( 'message' => __( 'Connected.', 'wp-page-sync' ) ) );
	}

	/**
	 * AJAX: disconnect a peer (best-effort remote notify, then local removal).
	 *
	 * @return void
	 */
	public function ajax_disconnect() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['peer_id'] ) ) : '';
		$peer    = $this->get_valid_peer( $peer_id );

		$notified = $this->client->disconnect( $peer );
		$this->peers->remove_peer( $peer_id );

		/* translators: %s: peer name. */
		$log_message = sprintf( __( 'Disconnected from %s.', 'wp-page-sync' ), $peer['name'] );
		if ( is_wp_error( $notified ) ) {
			$log_message .= ' ' . __( 'The peer could not be notified.', 'wp-page-sync' );
		}
		WPPS_Log::add( 'disconnected', $log_message );

		wp_send_json_success( array( 'message' => __( 'Disconnected.', 'wp-page-sync' ) ) );
	}

	/**
	 * AJAX: toggle whether the peer may write to this site.
	 *
	 * @return void
	 */
	public function ajax_toggle_incoming() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['peer_id'] ) ) : '';
		$allow   = isset( $_POST['allow'] ) ? intval( wp_unslash( $_POST['allow'] ) ) : -1;

		$this->get_valid_peer( $peer_id );
		if ( ! in_array( $allow, array( 0, 1 ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid value.', 'wp-page-sync' ) ) );
		}

		$this->peers->update_peer( $peer_id, array( 'allow_incoming' => (bool) $allow ) );

		$message = $allow
			? __( 'Incoming sync allowed.', 'wp-page-sync' )
			: __( 'Incoming sync blocked.', 'wp-page-sync' );
		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * AJAX: ping a peer via GET /info and refresh its record.
	 *
	 * @return void
	 */
	public function ajax_ping() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['peer_id'] ) ) : '';
		$peer    = $this->get_valid_peer( $peer_id );

		$info = $this->client->info( $peer );
		if ( is_wp_error( $info ) ) {
			/* translators: %s: error message. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Ping failed: %s', 'wp-page-sync' ), $info->get_error_message() ) ) );
		}

		$this->apply_peer_info( $peer_id, $info );

		$site_name = ! empty( $info['site_name'] ) ? (string) $info['site_name'] : $peer['name'];
		/* translators: 1: peer site name, 2: WordPress version, 3: plugin version. */
		$message = sprintf( __( 'Connected to %1$s (WordPress %2$s, plugin %3$s).', 'wp-page-sync' ), $site_name, (string) ( $info['wp_version'] ?? '?' ), (string) ( $info['plugin_version'] ?? '?' ) );

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * AJAX: status list for the Sync tab (posts + globals chip).
	 *
	 * @return void
	 */
	public function ajax_status_list() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result( $this->sync->status_list( $this->posted_peer(), $this->posted_post_type() ) );
	}

	/**
	 * AJAX: the peer's post list for the pull list.
	 *
	 * @return void
	 */
	public function ajax_peer_posts() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result( $this->sync->peer_posts( $this->posted_peer(), $this->posted_post_type() ) );
	}

	/**
	 * AJAX: preview for the push/pull modal.
	 *
	 * @return void
	 */
	public function ajax_sync_preview() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result(
			$this->sync->preview( $this->posted_peer(), $this->posted_post_type(), $this->posted_path(), $this->posted_direction() )
		);
	}

	/**
	 * AJAX: push one post to the peer.
	 *
	 * @return void
	 */
	public function ajax_push() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result(
			$this->sync->push( $this->posted_peer(), $this->posted_post_type(), $this->posted_path(), $this->posted_force() )
		);
	}

	/**
	 * AJAX: pull one post from the peer.
	 *
	 * @return void
	 */
	public function ajax_pull() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result(
			$this->sync->pull( $this->posted_peer(), $this->posted_post_type(), $this->posted_path(), $this->posted_force() )
		);
	}

	/**
	 * AJAX: globals diff preview.
	 *
	 * @return void
	 */
	public function ajax_globals_preview() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result( $this->sync->globals_preview( $this->posted_peer(), $this->posted_direction() ) );
	}

	/**
	 * AJAX: execute a globals push/pull.
	 *
	 * @return void
	 */
	public function ajax_globals_sync() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$this->send_result( $this->sync->globals_sync( $this->posted_peer(), $this->posted_direction() ) );
	}

	/**
	 * AJAX: one verify batch, locally or on a peer (target=self|peer_id).
	 *
	 * @return void
	 */
	public function ajax_verify() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'self';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$limit  = isset( $_POST['limit'] ) ? min( 50, max( 1, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 10;

		if ( 'self' === $target ) {
			wp_send_json_success( $this->verifier->run( $offset, $limit ) );
		}

		$peer = $this->get_valid_peer( $target );
		$this->send_result(
			$this->client->request(
				$peer,
				'POST',
				'/' . WPPS_REST_NS . '/verify',
				array(
					'offset' => (string) $offset,
					'limit'  => (string) $limit,
				)
			)
		);
	}

	/**
	 * AJAX: log entries, newest first.
	 *
	 * @return void
	 */
	public function ajax_get_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_admin();

		$entries = array();
		foreach ( array_reverse( WPPS_Log::get_entries() ) as $entry ) {
			$entries[] = array(
				'time'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ),
				'type'    => (string) $entry['type'],
				'message' => (string) $entry['message'],
			);
		}

		wp_send_json_success( array( 'entries' => $entries ) );
	}

	/**
	 * Sends a service result as a JSON response.
	 *
	 * The error code travels with the message so the caller can tell a
	 * skippable conflict (wpps_conflict) from a real failure; the bulk push
	 * reports the two differently.
	 *
	 * @param array|WP_Error $result Service return value.
	 * @return void
	 */
	private function send_result( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success( $result );
	}

	/**
	 * Validated peer record from $_POST['peer_id'].
	 *
	 * @return array
	 */
	private function posted_peer() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer runs in every calling handler.
		$peer_id = isset( $_POST['peer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['peer_id'] ) ) : '';
		return $this->get_valid_peer( $peer_id );
	}

	/**
	 * Validated post type from $_POST['post_type'].
	 *
	 * @return string
	 */
	private function posted_post_type() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer runs in every calling handler.
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! in_array( $post_type, WPPS_Exporter::SYNCABLE_POST_TYPES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'wp-page-sync' ) ) );
		}
		return $post_type;
	}

	/**
	 * Validated hierarchical path from $_POST['path'].
	 *
	 * @return string
	 */
	private function posted_path() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer runs in every calling handler.
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		if ( ! WPPS_Importer::validate_post_path( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid path.', 'wp-page-sync' ) ) );
		}
		return $path;
	}

	/**
	 * Validated direction from $_POST['direction'].
	 *
	 * @return string push|pull
	 */
	private function posted_direction() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer runs in every calling handler.
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		if ( ! in_array( $direction, array( 'push', 'pull' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid direction.', 'wp-page-sync' ) ) );
		}
		return $direction;
	}

	/**
	 * Force flag from $_POST['force'].
	 *
	 * @return bool
	 */
	private function posted_force() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer runs in every calling handler.
		return isset( $_POST['force'] ) && 1 === intval( wp_unslash( $_POST['force'] ) );
	}

	/**
	 * Capability guard for AJAX handlers (nonce is checked by the caller).
	 *
	 * @return void
	 */
	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-page-sync' ) ), 403 );
		}
	}

	/**
	 * Validates a posted peer id and returns the matching record, or ends the
	 * request with a JSON error.
	 *
	 * @param string $peer_id Sanitized posted peer id.
	 * @return array Peer record.
	 */
	private function get_valid_peer( $peer_id ) {
		if ( ! preg_match( '/^[0-9a-f]{8}$/', $peer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid peer.', 'wp-page-sync' ) ) );
		}
		$peer = $this->peers->get_peer( $peer_id );
		if ( ! $peer ) {
			wp_send_json_error( array( 'message' => __( 'Unknown peer.', 'wp-page-sync' ) ) );
		}
		return $peer;
	}

	/**
	 * Merges a handshake or /info response into the stored peer record.
	 *
	 * The peer's uploads_url is unknown at pairing time on the key-creating
	 * side (plan §4.1) and gets refreshed here on every successful ping.
	 *
	 * @param string $peer_id  Peer id.
	 * @param array  $response Handshake or /info response body.
	 * @return void
	 */
	private function apply_peer_info( $peer_id, array $response ) {
		$fields = array( 'last_seen' => time() );

		if ( ! empty( $response['site_name'] ) && is_string( $response['site_name'] ) ) {
			$fields['name'] = sanitize_text_field( $response['site_name'] );
		}
		if ( ! empty( $response['uploads_url'] ) && is_string( $response['uploads_url'] ) ) {
			$fields['uploads_url'] = esc_url_raw( $response['uploads_url'] );
		}
		if ( ! empty( $response['rest_url'] ) && is_string( $response['rest_url'] ) ) {
			$rest_url = esc_url_raw( $response['rest_url'] );
			$scheme   = (string) wp_parse_url( $rest_url, PHP_URL_SCHEME );
			$host     = (string) wp_parse_url( $rest_url, PHP_URL_HOST );
			if ( 'https' === $scheme || WPPS_Client::is_local_host( $host ) ) {
				$fields['rest_url'] = $rest_url;
			}
		}

		$this->peers->update_peer( $peer_id, $fields );
	}
}
