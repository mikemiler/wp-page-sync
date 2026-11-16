<?php
/**
 * Push/pull orchestration and sync state.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives push/pull of posts and globals against a peer (plan §6.5), computes
 * the conflict matrix (§6.4) and writes the per-peer state postmeta.
 */
class WPPS_Sync {

	/*
	 * Statuses whose sync would overwrite a differing copy on the target, so
	 * the caller must confirm the force option first. never_synced belongs
	 * here: both copies exist and their content differs while no sync state
	 * says they were ever compared, so writing over the target destroys
	 * content nobody has seen next to its counterpart. A post the target does
	 * not have (missing_on_peer / missing_local) or an identical one
	 * (in_sync_adopted) never lands in these lists.
	 */
	const FORCE_PUSH = array( 'peer_newer', 'diverged', 'never_synced' );
	const FORCE_PULL = array( 'local_newer', 'diverged', 'never_synced' );

	/**
	 * Peer store.
	 *
	 * @var WPPS_Peers
	 */
	private $peers;

	/**
	 * Signed HTTP client.
	 *
	 * @var WPPS_Client
	 */
	private $client;

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
	 * Constructor.
	 *
	 * @param WPPS_Peers    $peers    Peer store.
	 * @param WPPS_Client   $client   Signed HTTP client.
	 * @param WPPS_Exporter $exporter Exporter.
	 * @param WPPS_Importer $importer Importer.
	 * @param WPPS_Globals  $globals  Globals service.
	 */
	public function __construct( WPPS_Peers $peers, WPPS_Client $client, WPPS_Exporter $exporter, WPPS_Importer $importer, WPPS_Globals $globals ) {
		$this->peers    = $peers;
		$this->client   = $client;
		$this->exporter = $exporter;
		$this->importer = $importer;
		$this->globals  = $globals;
	}

	/**
	 * State meta key for a peer relationship (same key id on both sides).
	 *
	 * @param string $peer_id Peer id.
	 * @return string
	 */
	public static function state_key( $peer_id ) {
		return '_wpps_state_' . $peer_id;
	}

	/**
	 * Reads the stored sync state of a post for a peer.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $peer_id Peer id.
	 * @return array|null
	 */
	public static function read_state( $post_id, $peer_id ) {
		$state = get_post_meta( $post_id, self::state_key( $peer_id ), true );
		return ( is_array( $state ) && isset( $state['local_hash'], $state['peer_hash'] ) ) ? $state : null;
	}

	/**
	 * Writes the sync state of a post for a peer (plan §6.4).
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $peer_id      Peer id.
	 * @param int    $peer_post_id Post ID on the peer.
	 * @param string $local_hash   Content hash of this side's copy.
	 * @param string $peer_hash    Content hash of the peer's copy.
	 * @return void
	 */
	public static function write_state( $post_id, $peer_id, $peer_post_id, $local_hash, $peer_hash ) {
		update_post_meta(
			$post_id,
			self::state_key( $peer_id ),
			array(
				'peer_post_id' => (int) $peer_post_id,
				'local_hash'   => (string) $local_hash,
				'peer_hash'    => (string) $peer_hash,
				'synced_gmt'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Conflict matrix (plan §6.4). One generalization: whenever the two
	 * current hashes are equal, the pair is reported in sync ("adopted")
	 * even with a stale state pair; a pull records state on the pulling
	 * side only, so this keeps the source side truthful.
	 *
	 * @param bool       $exists_on_peer Whether the peer has the post.
	 * @param string     $local_hash     Current local hash.
	 * @param string     $peer_hash      Current peer hash.
	 * @param array|null $state          Stored state pair.
	 * @return string Status key.
	 */
	public static function compute_status( $exists_on_peer, $local_hash, $peer_hash, $state ) {
		if ( ! $exists_on_peer ) {
			return 'missing_on_peer';
		}
		if ( null !== $state ) {
			$local_changed = $state['local_hash'] !== $local_hash;
			$peer_changed  = $state['peer_hash'] !== $peer_hash;
			if ( ! $local_changed && ! $peer_changed ) {
				return 'in_sync';
			}
			if ( $local_hash === $peer_hash ) {
				return 'in_sync_adopted';
			}
			if ( $local_changed && $peer_changed ) {
				return 'diverged';
			}
			return $local_changed ? 'local_newer' : 'peer_newer';
		}
		return ( $local_hash === $peer_hash ) ? 'in_sync_adopted' : 'never_synced';
	}

	/**
	 * Status list for the Sync tab: every local post with its conflict
	 * status against the peer, plus the globals hash pair (plan §8).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @return array|WP_Error
	 */
	public function status_list( array $peer, $post_type ) {
		$local = $this->exporter->list_posts( $post_type );

		$lookup = array();
		foreach ( $local['items'] as $item ) {
			if ( '' !== $item['path'] ) {
				$lookup[] = array(
					'post_type' => $post_type,
					'path'      => $item['path'],
				);
			}
		}

		/*
		 * array_chunk() of an empty list yields no chunks at all, which would
		 * skip the /status call and leave the globals hash unknown on a site
		 * without syncable posts. One empty chunk keeps the call (and with it
		 * the globals comparison) independent of the post list.
		 */
		$chunks = $lookup ? array_chunk( $lookup, 200 ) : array( array() );

		$peer_map     = array();
		$peer_globals = '';
		foreach ( $chunks as $chunk ) {
			$response = $this->client->request( $peer, 'POST', '/' . WPPS_REST_NS . '/status', array(), array( 'items' => $chunk ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			foreach ( ( isset( $response['items'] ) && is_array( $response['items'] ) ) ? $response['items'] : array() as $peer_item ) {
				if ( isset( $peer_item['path'] ) ) {
					$peer_map[ (string) $peer_item['path'] ] = $peer_item;
				}
			}
			if ( isset( $response['globals_hash'] ) ) {
				$peer_globals = (string) $response['globals_hash'];
			}
		}

		$items = array();
		foreach ( $local['items'] as $item ) {
			$items[] = $this->status_row( $item, $peer_map, (string) $peer['peer_id'] );
		}

		$local_globals = $this->globals->hash();

		return array(
			'items'   => $items,
			'globals' => array(
				'local_hash' => $local_globals,
				'peer_hash'  => $peer_globals,
				'in_sync'    => '' !== $peer_globals && $peer_globals === $local_globals,
			),
		);
	}

	/**
	 * Builds one status row.
	 *
	 * @param array  $item     Local list item.
	 * @param array  $peer_map Peer /status items by path.
	 * @param string $peer_id  Peer id.
	 * @return array
	 */
	private function status_row( array $item, array $peer_map, $peer_id ) {
		if ( '' === $item['path'] ) {
			$item['sync_status'] = 'no_slug';
			$item['peer']        = null;
			return $item;
		}

		$peer_item = isset( $peer_map[ $item['path'] ] ) ? $peer_map[ $item['path'] ] : array();
		$exists    = ! empty( $peer_item['exists'] );
		$peer_hash = isset( $peer_item['hash'] ) ? (string) $peer_item['hash'] : '';

		$item['sync_status'] = self::compute_status( $exists, $item['hash'], $peer_hash, self::read_state( $item['id'], $peer_id ) );
		$item['peer']        = $exists ? array(
			'id'           => isset( $peer_item['id'] ) ? (int) $peer_item['id'] : 0,
			'modified_gmt' => isset( $peer_item['modified_gmt'] ) ? (string) $peer_item['modified_gmt'] : '',
		) : null;

		return $item;
	}

	/**
	 * Conflict status of ONE local post against ONE peer.
	 *
	 * Same machinery the Sync tab uses (peer /status item + compute_status +
	 * the stored state pair), for the editor check, which cares about a single
	 * post only.
	 *
	 * This is the plugin's one call that nobody asked for: it fires on every
	 * editor load, once per peer, so it runs on the short
	 * WPPS_TIMEOUT_EDITOR_CHECK instead of the full API timeout the
	 * user-initiated calls use.
	 *
	 * @param array   $peer Peer record.
	 * @param WP_Post $post Local post.
	 * @return string|WP_Error Status key, or the peer request's error.
	 */
	public function post_sync_status( array $peer, WP_Post $post ) {
		$path = (string) get_page_uri( $post );
		if ( '' === $path ) {
			return new WP_Error( 'wpps_no_slug', __( 'The post has no slug and cannot be compared.', 'wp-page-sync' ) );
		}

		$peer_item = $this->peer_status_single( $peer, $post->post_type, $path, WPPS_TIMEOUT_EDITOR_CHECK );
		if ( is_wp_error( $peer_item ) ) {
			return $peer_item;
		}

		return self::compute_status(
			! empty( $peer_item['exists'] ),
			$this->exporter->content_hash( $post ),
			isset( $peer_item['hash'] ) ? (string) $peer_item['hash'] : '',
			self::read_state( $post->ID, (string) $peer['peer_id'] )
		);
	}

	/**
	 * The peer's post list (pull list), annotated with local existence.
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @return array|WP_Error {items: array[]}
	 */
	public function peer_posts( array $peer, $post_type ) {
		$items = array();
		$page  = 1;

		do {
			$response = $this->client->request(
				$peer,
				'GET',
				'/' . WPPS_REST_NS . '/posts',
				array(
					'post_type' => $post_type,
					'page'      => (string) $page,
					'per_page'  => '100',
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$batch = ( isset( $response['items'] ) && is_array( $response['items'] ) ) ? $response['items'] : array();
			foreach ( $batch as $item ) {
				$path                 = isset( $item['path'] ) ? (string) $item['path'] : '';
				$local                = ( '' !== $path ) ? $this->exporter->find_post( $post_type, $path ) : null;
				$item['local_exists'] = null !== $local;
				$items[]              = $item;
			}
			$total     = isset( $response['total'] ) ? (int) $response['total'] : 0;
			$collected = count( $items );
			++$page;
		} while ( $collected < $total && ! empty( $batch ) && $page <= 50 );

		return array( 'items' => $items );
	}

	/**
	 * Two-step sync, step 1: the preview for the modal (plan §6.5).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @param string $path      Post path.
	 * @param string $direction push|pull.
	 * @return array|WP_Error
	 */
	public function preview( array $peer, $post_type, $path, $direction ) {
		if ( 'push' === $direction ) {
			$bundle = $this->exporter->export( $post_type, $path );
			if ( is_wp_error( $bundle ) ) {
				return $bundle;
			}
			$peer_item = $this->peer_status_single( $peer, $post_type, $path );
			if ( is_wp_error( $peer_item ) ) {
				return $peer_item;
			}
			$status = self::compute_status(
				! empty( $peer_item['exists'] ),
				(string) $bundle['source']['hash'],
				isset( $peer_item['hash'] ) ? (string) $peer_item['hash'] : '',
				self::read_state( (int) $bundle['source']['post_id'], (string) $peer['peer_id'] )
			);
			$media  = $this->peer_media_diff( $peer, $bundle['media_manifest'] );
			if ( is_wp_error( $media ) ) {
				return $media;
			}
			$force = in_array( $status, self::FORCE_PUSH, true );
		} else {
			$bundle = $this->fetch_bundle( $peer, $post_type, $path );
			if ( is_wp_error( $bundle ) ) {
				return $bundle;
			}
			$status = $this->pull_status( $peer, $post_type, $path, $bundle );
			$media  = $this->local_media_diff( $bundle['media_manifest'] );
			$force  = in_array( $status, self::FORCE_PULL, true );
		}

		return array(
			'direction'      => $direction,
			'title'          => (string) $bundle['post']['title'],
			'path'           => $path,
			'status'         => $status,
			'force_required' => $force,
			'preflight'      => $bundle['preflight'],
			'media'          => $media,
		);
	}

	/**
	 * Pushes one post to the peer (plan §6.5).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @param string $path      Post path.
	 * @param bool   $force     Override the FORCE_PUSH conflict statuses.
	 * @return array|WP_Error
	 */
	public function push( array $peer, $post_type, $path, $force ) {
		$bundle = $this->exporter->export( $post_type, $path );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( ! empty( $bundle['preflight']['errors'] ) ) {
			return new WP_Error( 'wpps_preflight', implode( ' ', $bundle['preflight']['errors'] ) );
		}

		$peer_item = $this->peer_status_single( $peer, $post_type, $path );
		if ( is_wp_error( $peer_item ) ) {
			return $peer_item;
		}
		$status = self::compute_status(
			! empty( $peer_item['exists'] ),
			(string) $bundle['source']['hash'],
			isset( $peer_item['hash'] ) ? (string) $peer_item['hash'] : '',
			self::read_state( (int) $bundle['source']['post_id'], (string) $peer['peer_id'] )
		);
		if ( in_array( $status, self::FORCE_PUSH, true ) && ! $force ) {
			return new WP_Error( 'wpps_conflict', __( 'The copy on the peer differs from this one and would be overwritten. Confirm the force option to push anyway.', 'wp-page-sync' ) );
		}

		$transfer = $this->transfer_media_to_peer( $peer, $bundle['media_manifest'] );
		if ( is_wp_error( $transfer ) ) {
			return $this->log_failure( 'push', $path, $peer, $transfer );
		}

		$response = $this->client->request( $peer, 'POST', '/' . WPPS_REST_NS . '/import-post', array(), $bundle, WPPS_TIMEOUT_MEDIA );
		if ( is_wp_error( $response ) ) {
			return $this->log_failure( 'push', $path, $peer, $response );
		}

		self::write_state(
			(int) $bundle['source']['post_id'],
			(string) $peer['peer_id'],
			isset( $response['id'] ) ? (int) $response['id'] : 0,
			(string) $bundle['source']['hash'],
			isset( $response['hash'] ) ? (string) $response['hash'] : ''
		);

		$warnings = $this->merge_warnings( $transfer['warnings'], $response );
		/* translators: 1: post path, 2: peer name, 3: number of transferred files. */
		WPPS_Log::add( 'push', sprintf( __( 'Pushed %1$s to %2$s (%3$d media files transferred).', 'wp-page-sync' ), $path, $peer['name'], $transfer['files'] ), $warnings );

		return array(
			'message'  => __( 'Pushed.', 'wp-page-sync' ),
			'url'      => isset( $response['url'] ) ? (string) $response['url'] : '',
			'warnings' => $warnings,
		);
	}

	/**
	 * Pulls one post from the peer (mirrors push, plan §6.5).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @param string $path      Post path.
	 * @param bool   $force     Override the FORCE_PULL conflict statuses.
	 * @return array|WP_Error
	 */
	public function pull( array $peer, $post_type, $path, $force ) {
		$bundle = $this->fetch_bundle( $peer, $post_type, $path );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( ! empty( $bundle['preflight']['errors'] ) ) {
			return new WP_Error( 'wpps_preflight', implode( ' ', array_map( 'strval', $bundle['preflight']['errors'] ) ) );
		}

		$status = $this->pull_status( $peer, $post_type, $path, $bundle );
		if ( in_array( $status, self::FORCE_PULL, true ) && ! $force ) {
			return new WP_Error( 'wpps_conflict', __( 'The copy on this site differs from the peer copy and would be overwritten. Confirm the force option to pull anyway.', 'wp-page-sync' ) );
		}

		$fetched = $this->fetch_media_from_peer( $peer, $bundle['media_manifest'] );
		if ( is_wp_error( $fetched ) ) {
			return $this->log_failure( 'pull', $path, $peer, $fetched );
		}

		$result = $this->importer->import_post( $bundle );
		if ( is_wp_error( $result ) ) {
			return $this->log_failure( 'pull', $path, $peer, $result );
		}

		self::write_state(
			(int) $result['id'],
			(string) $peer['peer_id'],
			(int) $bundle['source']['post_id'],
			(string) $result['hash'],
			(string) $bundle['source']['hash']
		);

		$warnings = array_merge( $fetched['warnings'], $result['warnings'] );
		/* translators: 1: post path, 2: peer name, 3: number of transferred files. */
		WPPS_Log::add( 'pull', sprintf( __( 'Pulled %1$s from %2$s (%3$d media files transferred).', 'wp-page-sync' ), $path, $peer['name'], $fetched['files'] ), $warnings );

		return array(
			'message'  => __( 'Pulled.', 'wp-page-sync' ),
			'url'      => (string) $result['url'],
			'warnings' => $warnings,
		);
	}

	/**
	 * Globals diff preview (plan §6.6 UI).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $direction push|pull.
	 * @return array|WP_Error
	 */
	public function globals_preview( array $peer, $direction ) {
		$local       = $this->globals->export();
		$peer_bundle = $this->client->request( $peer, 'GET', '/' . WPPS_REST_NS . '/globals' );
		if ( is_wp_error( $peer_bundle ) ) {
			return $peer_bundle;
		}

		$from = ( 'push' === $direction ) ? $local : $peer_bundle;
		$to   = ( 'push' === $direction ) ? $peer_bundle : $local;

		$media = ( 'push' === $direction )
			? $this->peer_media_diff( $peer, $local['media_manifest'] )
			: $this->local_media_diff( ( isset( $peer_bundle['media_manifest'] ) && is_array( $peer_bundle['media_manifest'] ) ) ? $peer_bundle['media_manifest'] : array() );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$diff = $this->diff_globals( $from, $to );

		return array_merge(
			$diff,
			array(
				'direction' => $direction,
				'media'     => $media,
				'in_sync'   => isset( $peer_bundle['hash'] ) && (string) $peer_bundle['hash'] === (string) $local['hash'],
				'preflight' => ( 'push' === $direction ) ? $local['preflight'] : $this->pull_globals_preflight( $peer_bundle ),
			)
		);
	}

	/**
	 * Pre-flight of a globals pull: the peer's own report plus files above
	 * this site's size limit.
	 *
	 * @param array $peer_bundle Peer globals bundle.
	 * @return array {errors, warnings}
	 */
	private function pull_globals_preflight( array $peer_bundle ) {
		$preflight = ( isset( $peer_bundle['preflight'] ) && is_array( $peer_bundle['preflight'] ) ) ? $peer_bundle['preflight'] : array();
		$errors    = ( isset( $preflight['errors'] ) && is_array( $preflight['errors'] ) ) ? $preflight['errors'] : array();
		$manifest  = ( isset( $peer_bundle['media_manifest'] ) && is_array( $peer_bundle['media_manifest'] ) ) ? $peer_bundle['media_manifest'] : array();

		return array(
			'errors'   => array_merge( $errors, $this->oversize_errors( $manifest ) ),
			'warnings' => ( isset( $preflight['warnings'] ) && is_array( $preflight['warnings'] ) ) ? $preflight['warnings'] : array(),
		);
	}

	/**
	 * Executes a globals push or pull (plan §6.6).
	 *
	 * @param array  $peer      Peer record.
	 * @param string $direction push|pull.
	 * @return array|WP_Error
	 */
	public function globals_sync( array $peer, $direction ) {
		if ( 'push' === $direction ) {
			$bundle = $this->globals->export();
			if ( ! empty( $bundle['preflight']['errors'] ) ) {
				return new WP_Error( 'wpps_preflight', implode( ' ', $bundle['preflight']['errors'] ) );
			}
			$transfer = $this->transfer_media_to_peer( $peer, $bundle['media_manifest'] );
			if ( is_wp_error( $transfer ) ) {
				return $this->log_failure( 'globals_push', 'globals', $peer, $transfer );
			}
			$result = $this->client->request( $peer, 'POST', '/' . WPPS_REST_NS . '/import-globals', array(), $bundle, WPPS_TIMEOUT_MEDIA );
			if ( is_wp_error( $result ) ) {
				return $this->log_failure( 'globals_push', 'globals', $peer, $result );
			}
			$result['warnings'] = $this->merge_warnings( $transfer['warnings'], $result );
			/* translators: %s: peer name. */
			WPPS_Log::add( 'globals_push', sprintf( __( 'Pushed global styles to %s.', 'wp-page-sync' ), $peer['name'] ), $result['warnings'] );
			return $result;
		}

		$bundle = $this->fetch_globals_bundle( $peer );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		$fetched = $this->fetch_media_from_peer( $peer, $bundle['media_manifest'] );
		if ( is_wp_error( $fetched ) ) {
			return $this->log_failure( 'globals_pull', 'globals', $peer, $fetched );
		}
		$result             = $this->globals->import( $bundle );
		$result['warnings'] = array_merge( $fetched['warnings'], $result['warnings'] );
		/* translators: %s: peer name. */
		WPPS_Log::add( 'globals_pull', sprintf( __( 'Pulled global styles from %s.', 'wp-page-sync' ), $peer['name'] ), $result['warnings'] );

		return $result;
	}

	/**
	 * Fetches and minimally validates a peer post bundle.
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @param string $path      Post path.
	 * @return array|WP_Error
	 */
	private function fetch_bundle( array $peer, $post_type, $path ) {
		$bundle = $this->client->request(
			$peer,
			'GET',
			'/' . WPPS_REST_NS . '/post',
			array(
				'post_type' => $post_type,
				'path'      => $path,
			),
			null,
			WPPS_TIMEOUT_MEDIA
		);
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( ! isset( $bundle['post']['title'], $bundle['source']['hash'] ) ) {
			return new WP_Error( 'wpps_invalid_bundle', __( 'The peer returned an invalid bundle.', 'wp-page-sync' ) );
		}
		$bundle['media_manifest'] = ( isset( $bundle['media_manifest'] ) && is_array( $bundle['media_manifest'] ) ) ? $bundle['media_manifest'] : array();
		if ( ! isset( $bundle['preflight'] ) || ! is_array( $bundle['preflight'] ) ) {
			$bundle['preflight'] = array(
				'errors'   => array(),
				'warnings' => array(),
			);
		}
		$bundle['preflight']['errors'] = array_merge(
			isset( $bundle['preflight']['errors'] ) && is_array( $bundle['preflight']['errors'] ) ? $bundle['preflight']['errors'] : array(),
			$this->oversize_errors( $bundle['media_manifest'] )
		);
		return $bundle;
	}

	/**
	 * Pre-flight errors for manifest files above this site's size limit.
	 *
	 * The sending site checks its own limit already; this covers a pull
	 * from a peer before 1.3.0 (no check) or one with a larger limit.
	 *
	 * @param array $manifest Peer-supplied media manifest.
	 * @return string[]
	 */
	private function oversize_errors( array $manifest ) {
		$errors = array();
		foreach ( $manifest as $entry ) {
			$size = ( is_array( $entry ) && isset( $entry['size'] ) ) ? (int) $entry['size'] : 0;
			if ( $size > WPPS_MAX_FILE_BYTES ) {
				$errors[] = WPPS_Importer::too_large_message( isset( $entry['rel_path'] ) ? (string) $entry['rel_path'] : '', $size );
			}
		}
		return $errors;
	}

	/**
	 * Fetches and minimally validates a peer globals bundle.
	 *
	 * @param array $peer Peer record.
	 * @return array|WP_Error
	 */
	private function fetch_globals_bundle( array $peer ) {
		$bundle = $this->client->request( $peer, 'GET', '/' . WPPS_REST_NS . '/globals', array(), null, WPPS_TIMEOUT_MEDIA );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		if ( ! isset( $bundle['gb_styles'] ) || ! is_array( $bundle['gb_styles'] ) ) {
			return new WP_Error( 'wpps_invalid_bundle', __( 'The peer returned an invalid globals bundle.', 'wp-page-sync' ) );
		}
		$bundle['media_manifest'] = ( isset( $bundle['media_manifest'] ) && is_array( $bundle['media_manifest'] ) ) ? $bundle['media_manifest'] : array();
		$oversize                 = $this->oversize_errors( $bundle['media_manifest'] );
		if ( $oversize ) {
			return new WP_Error( 'wpps_preflight', implode( ' ', $oversize ) );
		}
		return $bundle;
	}

	/**
	 * Conflict status of a pull, from the local perspective.
	 *
	 * @param array  $peer      Peer record.
	 * @param string $post_type Post type.
	 * @param string $path      Post path.
	 * @param array  $bundle    Fetched bundle.
	 * @return string
	 */
	private function pull_status( array $peer, $post_type, $path, array $bundle ) {
		$local = $this->exporter->find_post( $post_type, $path );
		if ( ! $local ) {
			return 'missing_local';
		}
		return self::compute_status(
			true,
			$this->exporter->content_hash( $local ),
			(string) $bundle['source']['hash'],
			self::read_state( $local->ID, (string) $peer['peer_id'] )
		);
	}

	/**
	 * One-item /status call against the peer.
	 *
	 * @param array    $peer      Peer record.
	 * @param string   $post_type Post type.
	 * @param string   $path      Post path.
	 * @param int|null $timeout   Timeout in seconds; null keeps WPPS_TIMEOUT_API,
	 *                            which every user-initiated caller uses.
	 * @return array|WP_Error Peer status item (exists=false default).
	 */
	private function peer_status_single( array $peer, $post_type, $path, $timeout = null ) {
		$response = $this->client->request(
			$peer,
			'POST',
			'/' . WPPS_REST_NS . '/status',
			array(),
			array(
				'items' => array(
					array(
						'post_type' => $post_type,
						'path'      => $path,
					),
				),
			),
			$timeout
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return ( isset( $response['items'][0] ) && is_array( $response['items'][0] ) ) ? $response['items'][0] : array( 'exists' => false );
	}

	/**
	 * Media diff against the peer (push direction).
	 *
	 * @param array $peer     Peer record.
	 * @param array $manifest Media manifest.
	 * @return array|WP_Error {files, transfer_files, transfer_bytes, needed[]}
	 */
	private function peer_media_diff( array $peer, array $manifest ) {
		if ( empty( $manifest ) ) {
			return $this->media_diff_result( $manifest, array() );
		}
		$response = $this->client->request( $peer, 'POST', '/' . WPPS_REST_NS . '/media-check', array(), array( 'items' => $this->manifest_checklist( $manifest ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $this->media_diff_result( $manifest, ( isset( $response['items'] ) && is_array( $response['items'] ) ) ? $response['items'] : array() );
	}

	/**
	 * Media diff against the local library (pull direction).
	 *
	 * @param array $manifest Media manifest.
	 * @return array
	 */
	private function local_media_diff( array $manifest ) {
		return $this->media_diff_result( $manifest, $this->importer->media_check( $this->manifest_checklist( $manifest ) ) );
	}

	/**
	 * Reduces a manifest to the /media-check request items.
	 *
	 * @param array $manifest Media manifest.
	 * @return array[]
	 */
	private function manifest_checklist( array $manifest ) {
		$items = array();
		foreach ( $manifest as $entry ) {
			if ( isset( $entry['rel_path'], $entry['sha256'] ) ) {
				$items[] = array(
					'rel_path' => (string) $entry['rel_path'],
					'sha256'   => (string) $entry['sha256'],
				);
			}
		}
		return $items;
	}

	/**
	 * Indexes a media manifest by rel path for the transfer loops.
	 *
	 * The manifest of a pull is peer-supplied, so an entry may be missing the
	 * fields the transfer needs. Both are required up front: an incomplete
	 * entry fails here with its own error instead of reaching the transfer as
	 * an undefined key and surfacing as a checksum mismatch.
	 *
	 * @param array $manifest Media manifest.
	 * @return array|WP_Error rel_path => entry, or the first invalid entry.
	 */
	private function manifest_by_rel( array $manifest ) {
		$by_rel = array();
		foreach ( $manifest as $entry ) {
			$rel = ( is_array( $entry ) && isset( $entry['rel_path'] ) ) ? (string) $entry['rel_path'] : '';
			$sha = ( is_array( $entry ) && isset( $entry['sha256'] ) ) ? (string) $entry['sha256'] : '';
			if ( '' === $rel || ! preg_match( '/^[0-9a-f]{64}$/D', $sha ) ) {
				return new WP_Error(
					'wpps_invalid_manifest',
					/* translators: %s: uploads-relative file path, or a placeholder when the entry carries none. */
					sprintf( __( 'The media manifest is incomplete: the entry for %s has no usable file path or checksum.', 'wp-page-sync' ), ( '' !== $rel ) ? $rel : __( '(unnamed file)', 'wp-page-sync' ) )
				);
			}
			// A pull manifest is peer-supplied: nothing outside the transfer rules is ever downloaded.
			if ( ! WPPS_Importer::validate_rel_path( $rel ) || ! WPPS_Importer::allowed_extension( $rel ) ) {
				return new WP_Error(
					'wpps_invalid_manifest',
					/* translators: %s: uploads-relative file path. */
					sprintf( __( 'The media manifest names a file outside the transfer rules: %s', 'wp-page-sync' ), $rel )
				);
			}
			$by_rel[ $rel ] = $entry;
		}
		return $by_rel;
	}

	/**
	 * Combines a manifest with check results into transfer numbers.
	 *
	 * @param array $manifest Media manifest.
	 * @param array $checked  /media-check items.
	 * @return array
	 */
	private function media_diff_result( array $manifest, array $checked ) {
		$status_by_rel = array();
		foreach ( $checked as $item ) {
			if ( isset( $item['rel_path'], $item['status'] ) ) {
				$status_by_rel[ (string) $item['rel_path'] ] = (string) $item['status'];
			}
		}

		$needed = array();
		$bytes  = 0;
		foreach ( $manifest as $entry ) {
			$rel    = isset( $entry['rel_path'] ) ? (string) $entry['rel_path'] : '';
			$status = isset( $status_by_rel[ $rel ] ) ? $status_by_rel[ $rel ] : 'missing';
			if ( '' !== $rel && 'identical' !== $status ) {
				$needed[] = $rel;
				$bytes   += isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			}
		}

		return array(
			'files'          => count( $manifest ),
			'transfer_files' => count( $needed ),
			'transfer_bytes' => $bytes,
			'needed'         => $needed,
		);
	}

	/**
	 * Uploads the missing/different manifest files to the peer.
	 *
	 * @param array $peer     Peer record.
	 * @param array $manifest Media manifest.
	 * @return array|WP_Error {files, warnings[]}
	 */
	private function transfer_media_to_peer( array $peer, array $manifest ) {
		$by_rel = $this->manifest_by_rel( $manifest );
		if ( is_wp_error( $by_rel ) ) {
			return $by_rel;
		}
		$diff = $this->peer_media_diff( $peer, $manifest );
		if ( is_wp_error( $diff ) ) {
			return $diff;
		}

		$warnings = array();
		foreach ( $diff['needed'] as $rel ) {
			$response = $this->upload_file( $peer, $rel, (string) $by_rel[ $rel ]['sha256'] );
			if ( is_wp_error( $response ) ) {
				/* translators: 1: file path, 2: error message. */
				return new WP_Error( 'wpps_upload_failed', sprintf( __( 'Uploading %1$s failed: %2$s', 'wp-page-sync' ), $rel, $response->get_error_message() ) );
			}
			$warnings = $this->merge_warnings( $warnings, $response );
		}

		return array(
			'files'    => count( $diff['needed'] ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Sends one uploads file to the peer as signed WPPS_CHUNK_BYTES pieces
	 * (POST /media-chunk), reading it piece by piece so it never sits in
	 * memory as a whole. Each piece carries the path, its offset, the total
	 * size and the file's sha256; the peer verifies size and checksum after
	 * the last one.
	 *
	 * A 404 on the first piece means the peer has no /media-chunk route: it
	 * runs a version before 1.3.0.
	 *
	 * @param array  $peer   Peer record.
	 * @param string $rel    Uploads-relative path.
	 * @param string $sha256 sha256 from the manifest.
	 * @return array|WP_Error The peer's import result (after the last piece).
	 */
	private function upload_file( array $peer, $rel, $sha256 ) {
		$abs   = wp_upload_dir( null, false )['basedir'] . '/' . $rel;
		$total = is_file( $abs ) ? (int) filesize( $abs ) : 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a manifest-verified uploads file in pieces.
		$handle = ( $total > 0 ) ? fopen( $abs, 'rb' ) : false;
		if ( false === $handle ) {
			/* translators: %s: uploads-relative file path. */
			return new WP_Error( 'wpps_read_failed', sprintf( __( 'Could not read %s for transfer.', 'wp-page-sync' ), $rel ) );
		}

		$type  = wp_check_filetype( wp_basename( $rel ), WPPS_Importer::ALLOWED_MIMES );
		$query = array(
			'rel_path'  => $rel,
			'sha256'    => $sha256,
			'mime'      => $type['type'] ? $type['type'] : 'application/octet-stream',
			'upload_id' => bin2hex( random_bytes( 16 ) ),
			'total'     => (string) $total,
		);

		$offset   = 0;
		$response = array();
		try {
			while ( $offset < $total ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see fopen above.
				$bytes = fread( $handle, WPPS_CHUNK_BYTES );
				if ( false === $bytes || '' === $bytes ) {
					/* translators: %s: uploads-relative file path. */
					return new WP_Error( 'wpps_read_failed', sprintf( __( 'Could not read %s for transfer.', 'wp-page-sync' ), $rel ) );
				}
				$query['offset'] = (string) $offset;
				$response        = $this->client->request( $peer, 'POST', '/' . WPPS_REST_NS . '/media-chunk', $query, $bytes, WPPS_TIMEOUT_MEDIA );
				if ( is_wp_error( $response ) ) {
					return ( 0 === $offset && 'wpps_http_404' === $response->get_error_code() )
						? new WP_Error( 'wpps_peer_outdated', __( 'The connected site runs an older WP Page Sync without piecewise file upload. Update WP Page Sync on the connected site to 1.3.0 or later, then sync again.', 'wp-page-sync' ) )
						: $response;
				}
				$offset += strlen( $bytes );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fopen above.
		}

		return $response;
	}

	/**
	 * Downloads the missing/different manifest files from the peer and
	 * imports them locally.
	 *
	 * Each file streams into a temp file inside uploads (never into memory)
	 * and goes through import_media(), which verifies its size and sha256
	 * and consumes the temp file. The timeout grows with the file: a pull
	 * gets the same time per WPPS_CHUNK_BYTES that a chunked push gives each
	 * piece.
	 *
	 * @param array $peer     Peer record.
	 * @param array $manifest Media manifest.
	 * @return array|WP_Error {files, warnings[]}
	 */
	private function fetch_media_from_peer( array $peer, array $manifest ) {
		$by_rel = $this->manifest_by_rel( $manifest );
		if ( is_wp_error( $by_rel ) ) {
			return $by_rel;
		}
		$diff = $this->local_media_diff( $manifest );

		$warnings = array();
		foreach ( $diff['needed'] as $rel ) {
			$entry = $by_rel[ $rel ];
			$temp  = WPPS_Importer::temp_file();
			if ( is_wp_error( $temp ) ) {
				return $temp;
			}
			// Peer-supplied: clamped before it scales the timeout.
			$size     = isset( $entry['size'] ) ? min( max( 0, (int) $entry['size'] ), WPPS_MAX_FILE_BYTES ) : 0;
			$timeout  = max( 1, (int) ceil( $size / WPPS_CHUNK_BYTES ) ) * WPPS_TIMEOUT_MEDIA;
			$download = $this->client->download_to_file( $peer, '/' . WPPS_REST_NS . '/media-file', array( 'rel_path' => $rel ), $temp, $timeout );
			clearstatcache( true, $temp );
			if ( ! is_wp_error( $download ) && $size > 0 && (int) filesize( $temp ) !== $size ) {
				$download = new WP_Error( 'wpps_size_mismatch', __( 'The downloaded file does not have the size the peer announced.', 'wp-page-sync' ) );
			}
			if ( is_wp_error( $download ) ) {
				WPPS_Importer::discard( $temp );
				/* translators: 1: file path, 2: error message. */
				return new WP_Error( 'wpps_download_failed', sprintf( __( 'Downloading %1$s failed: %2$s', 'wp-page-sync' ), $rel, $download->get_error_message() ) );
			}
			$result = $this->importer->import_media( $rel, (string) $entry['sha256'], $temp );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: file path, 2: error message. */
				return new WP_Error( 'wpps_import_failed', sprintf( __( 'Importing %1$s failed: %2$s', 'wp-page-sync' ), $rel, $result->get_error_message() ) );
			}
			$warnings = array_merge( $warnings, $result['warnings'] );
		}

		return array(
			'files'    => count( $diff['needed'] ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Diffs two globals bundles (styles by identity, colors by slug).
	 *
	 * @param array $from Source bundle.
	 * @param array $to   Target bundle.
	 * @return array {added[], changed[], only_on_target[], colors}
	 */
	private function diff_globals( array $from, array $to ) {
		$hosts     = $this->exporter->known_hosts( $this->bundle_hosts( $from, $to ) );
		$normalize = function ( $style ) use ( $hosts ) {
			return wp_json_encode(
				array(
					isset( $style['title'] ) ? (string) $style['title'] : '',
					isset( $style['status'] ) ? (string) $style['status'] : '',
					isset( $style['menu_order'] ) ? (int) $style['menu_order'] : 0,
					isset( $style['selector'] ) ? (string) $style['selector'] : '',
					WPPS_Rewriter::normalize( isset( $style['css'] ) ? (string) $style['css'] : '', $hosts ),
					WPPS_Rewriter::apply_recursive(
						( isset( $style['data'] ) && is_array( $style['data'] ) ) ? $style['data'] : array(),
						function ( $value ) use ( $hosts ) {
							return WPPS_Rewriter::normalize( $value, $hosts );
						}
					),
				)
			);
		};

		$from_map = $this->styles_by_identity( $from );
		$to_map   = $this->styles_by_identity( $to );

		$added   = array_values( array_diff( array_keys( $from_map ), array_keys( $to_map ) ) );
		$changed = array();
		foreach ( array_intersect_key( $from_map, $to_map ) as $identity => $style ) {
			if ( $normalize( $style ) !== $normalize( $to_map[ $identity ] ) ) {
				$changed[] = $identity;
			}
		}

		return array(
			'added'          => $added,
			'changed'        => $changed,
			'only_on_target' => array_values( array_diff( array_keys( $to_map ), array_keys( $from_map ) ) ),
			'colors'         => $this->diff_colors( $from, $to ),
		);
	}

	/**
	 * Indexes a bundle's styles by identity.
	 *
	 * @param array $bundle Globals bundle.
	 * @return array
	 */
	private function styles_by_identity( array $bundle ) {
		$map = array();
		foreach ( ( isset( $bundle['gb_styles'] ) && is_array( $bundle['gb_styles'] ) ) ? $bundle['gb_styles'] : array() as $style ) {
			if ( is_array( $style ) && ! empty( $style['identity'] ) ) {
				$map[ (string) $style['identity'] ] = $style;
			}
		}
		return $map;
	}

	/**
	 * Color palette diff by slug.
	 *
	 * @param array $from Source bundle.
	 * @param array $to   Target bundle.
	 * @return array {added[], removed[], changed[]}
	 */
	private function diff_colors( array $from, array $to ) {
		$from_colors = $this->colors_by_slug( $from );
		$to_colors   = $this->colors_by_slug( $to );

		$changed = array();
		foreach ( array_intersect_key( $from_colors, $to_colors ) as $slug => $color ) {
			if ( $color !== $to_colors[ $slug ] ) {
				$changed[] = array(
					'slug' => $slug,
					'from' => $to_colors[ $slug ],
					'to'   => $color,
				);
			}
		}

		return array(
			'added'   => array_values( array_diff( array_keys( $from_colors ), array_keys( $to_colors ) ) ),
			'removed' => array_values( array_diff( array_keys( $to_colors ), array_keys( $from_colors ) ) ),
			'changed' => $changed,
		);
	}

	/**
	 * Indexes a bundle's palette by slug.
	 *
	 * @param array $bundle Globals bundle.
	 * @return array slug => color value.
	 */
	private function colors_by_slug( array $bundle ) {
		$map = array();
		foreach ( ( isset( $bundle['gp_settings']['global_colors'] ) && is_array( $bundle['gp_settings']['global_colors'] ) ) ? $bundle['gp_settings']['global_colors'] : array() as $color ) {
			if ( is_array( $color ) && ! empty( $color['slug'] ) ) {
				$map[ (string) $color['slug'] ] = isset( $color['color'] ) ? (string) $color['color'] : '';
			}
		}
		return $map;
	}

	/**
	 * Source hosts named in two bundles (for normalization).
	 *
	 * @param array $from Bundle.
	 * @param array $to   Bundle.
	 * @return string[]
	 */
	private function bundle_hosts( array $from, array $to ) {
		$hosts = array();
		foreach ( array( $from, $to ) as $bundle ) {
			if ( isset( $bundle['source']['home_url'] ) ) {
				$hosts[] = WPPS_Rewriter::bare_host( (string) $bundle['source']['home_url'] );
			}
		}
		return array_filter( $hosts );
	}

	/**
	 * Merges a response's warnings array into a collector.
	 *
	 * @param string[] $warnings Collector.
	 * @param array    $response Response possibly carrying warnings[].
	 * @return string[]
	 */
	private function merge_warnings( array $warnings, array $response ) {
		foreach ( ( isset( $response['warnings'] ) && is_array( $response['warnings'] ) ) ? $response['warnings'] : array() as $warning ) {
			if ( is_string( $warning ) ) {
				$warnings[] = $warning;
			}
		}
		return $warnings;
	}

	/**
	 * Logs a failed operation and passes the error through.
	 *
	 * @param string   $operation Operation type.
	 * @param string   $path      Post path or "globals".
	 * @param array    $peer      Peer record.
	 * @param WP_Error $error     Failure.
	 * @return WP_Error
	 */
	private function log_failure( $operation, $path, array $peer, WP_Error $error ) {
		/* translators: 1: operation, 2: post path, 3: peer name, 4: error message. */
		WPPS_Log::add( 'error', sprintf( __( '%1$s of %2$s with %3$s failed: %4$s', 'wp-page-sync' ), $operation, $path, $peer['name'], $error->get_error_message() ) );
		return $error;
	}
}
