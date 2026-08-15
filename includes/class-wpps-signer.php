<?php
/**
 * HMAC request signing and verification.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the canonical request string (plan §4.2 plus the receiver binding
 * from the security review, byte-exact on both sides), signs it, and
 * verifies incoming REST requests.
 *
 * Canonical string, parts joined with "\n":
 *   METHOD      uppercase; server side: WP_REST_Request::get_method()
 *   PATH        the REST route; server side: WP_REST_Request::get_route()
 *   QUERY       all real query pairs excluding rest_route; key and value
 *               rawurlencode()d, pairs sorted bytewise by encoded key then
 *               encoded value, joined "k=v" with "&"; "" if none
 *   TIMESTAMP   the X-WPPS-Timestamp header value
 *   NONCE       the X-WPPS-Nonce header value
 *   RECEIVER    untrailingslashit(home_url()) of the RECEIVING site; the
 *               client uses the peer record's stored url. Binds a signature
 *               to one direction (kills reflection) without touching HTTP
 *               Host headers, so it stays reverse-proxy-safe.
 *   BODY_HASH   hex(sha256(raw request body)), sha256("") for empty body
 */
class WPPS_Signer {

	/**
	 * Builds the canonical string for signing.
	 *
	 * @param string $method      HTTP method.
	 * @param string $route       REST route incl. namespace, e.g. /wp-page-sync/v1/info.
	 * @param array  $query_pairs List of array( key, value ) pairs, raw (unencoded) values.
	 * @param string $timestamp   Unix timestamp header value.
	 * @param string $nonce       Nonce header value.
	 * @param string $receiver    Receiving site's home URL, untrailingslashed.
	 * @param string $body        Raw request body ("" for none).
	 * @return string
	 */
	public static function canonical_string( $method, $route, array $query_pairs, $timestamp, $nonce, $receiver, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( (string) $method ),
				(string) $route,
				self::encode_query_pairs( $query_pairs ),
				(string) $timestamp,
				(string) $nonce,
				untrailingslashit( (string) $receiver ),
				hash( 'sha256', (string) $body ),
			)
		);
	}

	/**
	 * Encodes and sorts query pairs into the canonical QUERY component.
	 *
	 * Also used by the client to build the actual request query string, so
	 * the signed bytes and the sent bytes always match.
	 *
	 * @param array $query_pairs List of array( key, value ) pairs, raw values.
	 * @return string "" when there are no pairs.
	 */
	public static function encode_query_pairs( array $query_pairs ) {
		$encoded = array();
		foreach ( $query_pairs as $pair ) {
			$encoded[] = array(
				rawurlencode( (string) $pair[0] ),
				rawurlencode( (string) $pair[1] ),
			);
		}

		usort(
			$encoded,
			function ( $a, $b ) {
				if ( $a[0] === $b[0] ) {
					return strcmp( $a[1], $b[1] );
				}
				return strcmp( $a[0], $b[0] );
			}
		);

		$parts = array();
		foreach ( $encoded as $pair ) {
			$parts[] = $pair[0] . '=' . $pair[1];
		}

		return implode( '&', $parts );
	}

	/**
	 * Signs a canonical string.
	 *
	 * @param string $secret    Shared peer secret (hex string).
	 * @param string $canonical Canonical string.
	 * @return string Lowercase hex HMAC-SHA256 signature.
	 */
	public static function sign( $secret, $canonical ) {
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Verifies an incoming REST request against a known secret.
	 *
	 * Order (plan §4.2): signature via hash_equals() first, then the
	 * timestamp window, then the replay nonce. The nonce is only stored
	 * AFTER the signature verified (no unauthenticated nonce burning).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $secret  Shared secret to verify against.
	 * @return bool
	 */
	public static function verify_request( WP_REST_Request $request, $secret ) {
		$timestamp = (string) $request->get_header( 'X-WPPS-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-WPPS-Nonce' );
		$signature = (string) $request->get_header( 'X-WPPS-Signature' );

		if ( '' === $timestamp || '' === $nonce || '' === $signature ) {
			return false;
		}

		// DoS guard (security review): never sha256 an oversized body.
		$body = $request->get_body();
		if ( strlen( $body ) > WPPS_MAX_FILE_BYTES ) {
			return false;
		}

		$canonical = self::canonical_string(
			$request->get_method(),
			$request->get_route(),
			self::query_pairs_from_request(),
			$timestamp,
			$nonce,
			home_url(),
			$body
		);

		if ( ! hash_equals( self::sign( $secret, $canonical ), $signature ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > WPPS_TS_WINDOW ) {
			return false;
		}

		$transient_key = 'wpps_nonce_' . sha1( $nonce );
		if ( false !== get_transient( $transient_key ) ) {
			return false;
		}
		set_transient( $transient_key, 1, WPPS_NONCE_TTL );

		return true;
	}

	/**
	 * Extracts the canonical query pairs from the current request.
	 *
	 * Parses the raw query string (not $_GET) so the bytes are independent
	 * of PHP array parsing and magic quotes; excludes rest_route so
	 * signatures are identical under pretty and plain permalinks.
	 *
	 * @return array List of array( key, value ) pairs with raw values.
	 */
	public static function query_pairs_from_request() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw query bytes are required for HMAC canonicalization; the value is never stored or output.
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';

		$pairs = array();
		if ( '' === $query_string ) {
			return $pairs;
		}

		foreach ( explode( '&', $query_string ) as $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}
			$parts = explode( '=', $chunk, 2 );
			$key   = rawurldecode( $parts[0] );
			$value = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
			if ( 'rest_route' === $key ) {
				continue;
			}
			$pairs[] = array( $key, $value );
		}

		return $pairs;
	}
}
