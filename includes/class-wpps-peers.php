<?php
/**
 * Peer store and pairing-key handling.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages connected peers and single-use pairing keys.
 *
 * Peer records (option wpps_peers, autoload no), keyed by peer_id:
 * {peer_id, name, url, rest_url, uploads_url, secret, connected_at,
 * allow_incoming, last_seen}. Pending pairing keys (option
 * wpps_pending_keys, autoload no): {key_id: {secret, created}}, expired
 * entries are lazily purged on every read.
 */
class WPPS_Peers {

	const OPTION_PEERS   = 'wpps_peers';
	const OPTION_PENDING = 'wpps_pending_keys';

	/**
	 * Minimum seconds between last_seen option writes.
	 */
	const TOUCH_THROTTLE = 300;

	/**
	 * Creates a single-use pairing key and returns the shareable blob.
	 *
	 * @return array {key_id: string, blob: string} The blob is
	 *               base64(json{v, site, rest, key_id, secret}).
	 */
	public function create_key() {
		$pending = $this->get_pending_keys();
		$peers   = $this->get_peers();

		do {
			$key_id = bin2hex( random_bytes( 4 ) );
		} while ( isset( $pending[ $key_id ] ) || isset( $peers[ $key_id ] ) );

		$secret = bin2hex( random_bytes( 32 ) );

		$pending[ $key_id ] = array(
			'secret'  => $secret,
			'created' => time(),
		);
		wpps_save_option( self::OPTION_PENDING, $pending );

		$payload = array(
			'v'      => 1,
			'site'   => home_url(),
			'rest'   => rest_url(),
			'key_id' => $key_id,
			'secret' => $secret,
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for the pairing key blob, not obfuscation.
		$blob = base64_encode( wp_json_encode( $payload ) );

		return array(
			'key_id' => $key_id,
			'blob'   => $blob,
		);
	}

	/**
	 * Returns the secret of a pending (unclaimed, unexpired) pairing key.
	 *
	 * @param string $key_id Pairing key id (8 hex chars).
	 * @return string|null Secret, or null when unknown or expired.
	 */
	public function get_pending_secret( $key_id ) {
		$pending = $this->get_pending_keys();
		if ( isset( $pending[ $key_id ]['secret'] ) ) {
			return (string) $pending[ $key_id ]['secret'];
		}
		return null;
	}

	/**
	 * Claims a pending pairing key: moves it into the peer store bound to the
	 * caller's URLs. Read-validate-move within one request; a key can be
	 * claimed exactly once (last-wins option write accepted per plan §S7).
	 *
	 * @param string $key_id    Pairing key id.
	 * @param string $site_url  Peer home URL.
	 * @param string $site_name Peer site name.
	 * @param string $rest_url  Peer REST base URL.
	 * @return array|false The stored peer record, or false when the key is
	 *                     unknown, expired, or already claimed.
	 */
	public function claim_key( $key_id, $site_url, $site_name, $rest_url ) {
		$pending = $this->get_pending_keys();
		if ( ! isset( $pending[ $key_id ]['secret'] ) ) {
			return false;
		}

		$secret = (string) $pending[ $key_id ]['secret'];
		unset( $pending[ $key_id ] );
		wpps_save_option( self::OPTION_PENDING, $pending );

		$record = array(
			'peer_id'        => $key_id,
			'name'           => (string) $site_name,
			'url'            => (string) $site_url,
			'rest_url'       => (string) $rest_url,
			// Unknown at handshake time (plan §4.1 body has no uploads_url); refreshed from /info on ping.
			'uploads_url'    => '',
			'secret'         => $secret,
			'connected_at'   => time(),
			'allow_incoming' => self::default_allow_incoming(),
			'last_seen'      => time(),
		);

		$this->add_peer( $record );

		return $record;
	}

	/**
	 * Returns all peer records keyed by peer id.
	 *
	 * @return array[]
	 */
	public function get_peers() {
		$peers = get_option( self::OPTION_PEERS, array() );
		return is_array( $peers ) ? $peers : array();
	}

	/**
	 * Returns one peer record.
	 *
	 * @param string $peer_id Peer id.
	 * @return array|null
	 */
	public function get_peer( $peer_id ) {
		$peers = $this->get_peers();
		if ( isset( $peers[ $peer_id ] ) ) {
			return $peers[ $peer_id ];
		}
		return null;
	}

	/**
	 * Adds or replaces a peer record (also used for the provisional record
	 * during pairing, which is removed again on a failed handshake).
	 *
	 * @param array $record Peer record with a peer_id key.
	 * @return void
	 */
	public function add_peer( array $record ) {
		$peers                       = $this->get_peers();
		$peers[ $record['peer_id'] ] = $record;
		wpps_save_option( self::OPTION_PEERS, $peers );
	}

	/**
	 * Merges fields into an existing peer record.
	 *
	 * @param string $peer_id Peer id.
	 * @param array  $fields  Fields to merge.
	 * @return bool False when the peer does not exist.
	 */
	public function update_peer( $peer_id, array $fields ) {
		$peers = $this->get_peers();
		if ( ! isset( $peers[ $peer_id ] ) ) {
			return false;
		}
		$peers[ $peer_id ] = array_merge( $peers[ $peer_id ], $fields );
		wpps_save_option( self::OPTION_PEERS, $peers );
		return true;
	}

	/**
	 * Removes a peer record (disconnect / provisional-peer cleanup).
	 *
	 * @param string $peer_id Peer id.
	 * @return bool False when the peer did not exist.
	 */
	public function remove_peer( $peer_id ) {
		$peers = $this->get_peers();
		if ( ! isset( $peers[ $peer_id ] ) ) {
			return false;
		}
		unset( $peers[ $peer_id ] );
		wpps_save_option( self::OPTION_PEERS, $peers );
		return true;
	}

	/**
	 * Default for a new peer's allow_incoming flag (security review):
	 * incoming writes TO this site are enabled out of the box only on local
	 * hosts; production/dev must opt in via the Connections toggle.
	 *
	 * @return bool
	 */
	public static function default_allow_incoming() {
		$own_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return WPPS_Client::is_local_host( $own_host );
	}

	/**
	 * Updates a peer's last_seen timestamp, throttled to one option write
	 * per 300 s (security review: avoids a write per signed request).
	 *
	 * @param string $peer_id Peer id.
	 * @return void
	 */
	public function touch( $peer_id ) {
		$peer = $this->get_peer( $peer_id );
		if ( ! $peer ) {
			return;
		}
		if ( ( time() - (int) ( $peer['last_seen'] ?? 0 ) ) < self::TOUCH_THROTTLE ) {
			return;
		}
		$this->update_peer( $peer_id, array( 'last_seen' => time() ) );
	}

	/**
	 * Decodes and validates a pasted pairing-key blob.
	 *
	 * Enforces HTTPS on both peer URLs unless the host is local
	 * (*.test, *.local, localhost, 127.0.0.1) per plan §4.1.
	 *
	 * @param string $blob Pasted key blob.
	 * @return array|WP_Error {key_id, secret, site, rest} on success.
	 */
	public static function parse_key_blob( $blob ) {
		$invalid = new WP_Error( 'wpps_invalid_key', __( 'This is not a valid connection key.', 'wp-page-sync' ) );

		// Copy-paste tolerance: strip any whitespace before strict decoding.
		$blob = preg_replace( '/\s+/', '', (string) $blob );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding of the pairing key blob, not obfuscation.
		$json = base64_decode( $blob, true );
		if ( false === $json ) {
			return $invalid;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) ) {
			return $invalid;
		}
		if ( ! preg_match( '/^[0-9a-f]{8}$/', (string) ( $data['key_id'] ?? '' ) ) ) {
			return $invalid;
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $data['secret'] ?? '' ) ) ) {
			return $invalid;
		}

		foreach ( array( 'site', 'rest' ) as $url_key ) {
			$url    = (string) ( $data[ $url_key ] ?? '' );
			$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
			$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return $invalid;
			}
			if ( 'https' !== $scheme && ! WPPS_Client::is_local_host( $host ) ) {
				return new WP_Error( 'wpps_https_required', __( 'The peer site must use HTTPS (plain HTTP is only allowed for local hosts).', 'wp-page-sync' ) );
			}
		}

		return array(
			'key_id' => (string) $data['key_id'],
			'secret' => (string) $data['secret'],
			'site'   => esc_url_raw( (string) $data['site'] ),
			'rest'   => esc_url_raw( (string) $data['rest'] ),
		);
	}

	/**
	 * Reads the pending-keys option, lazily purging expired entries.
	 *
	 * @return array[]
	 */
	private function get_pending_keys() {
		$pending = get_option( self::OPTION_PENDING, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}

		$changed = false;
		foreach ( $pending as $key_id => $entry ) {
			$created = is_array( $entry ) ? (int) ( $entry['created'] ?? 0 ) : 0;
			if ( empty( $entry['secret'] ) || ( time() - $created ) > WPPS_KEY_TTL ) {
				unset( $pending[ $key_id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			wpps_save_option( self::OPTION_PENDING, $pending );
		}

		return $pending;
	}
}
