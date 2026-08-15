<?php
/**
 * Outgoing signed HTTP client.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends signed requests to a peer using its stored rest_url.
 *
 * Builds the identical canonical string as the server-side verifier
 * (plan §4.2) and enforces HTTPS for non-local peer hosts on every call.
 */
class WPPS_Client {

	/**
	 * Sends a signed request to a peer and decodes the JSON response.
	 *
	 * @param array             $peer    Peer record (needs peer_id, secret, rest_url).
	 * @param string            $method  HTTP method.
	 * @param string            $route   REST route incl. namespace, e.g. /wp-page-sync/v1/info.
	 * @param array             $query   Query args as key => value (raw values).
	 * @param array|string|null $body    Array = JSON payload; string = raw binary
	 *                                   body sent as application/octet-stream
	 *                                   (never a JSON content type, plan §10);
	 *                                   null = no body.
	 * @param int|null          $timeout Timeout in seconds; defaults to WPPS_TIMEOUT_API.
	 * @return array|WP_Error Decoded JSON response array on 2xx.
	 */
	public function request( array $peer, $method, $route, array $query = array(), $body = null, $timeout = null ) {
		$prepared = $this->prepare( $peer, $method, $route, $query, $body, $timeout );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		return $this->parse_response( $this->send( $prepared ) );
	}

	/**
	 * Signed GET returning the raw response body (the /media-file route
	 * answers with binary file content, not JSON).
	 *
	 * @param array    $peer    Peer record.
	 * @param string   $route   REST route incl. namespace.
	 * @param array    $query   Query args as key => value.
	 * @param int|null $timeout Timeout in seconds.
	 * @return array|WP_Error {body: string, sha256: string} on 200.
	 */
	public function download( array $peer, $route, array $query = array(), $timeout = null ) {
		$prepared = $this->prepare( $peer, 'GET', $route, $query, null, $timeout );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$response = $this->send( $prepared );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				return new WP_Error( 'wpps_http_' . $code, (string) $decoded['message'] );
			}
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wpps_http_' . $code, sprintf( __( 'Peer responded with HTTP %d.', 'wp-page-sync' ), $code ) );
		}

		return array(
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'sha256' => (string) wp_remote_retrieve_header( $response, 'x-wpps-sha256' ),
		);
	}

	/**
	 * Builds the signed URL + wp_remote_request args for a call.
	 *
	 * @param array             $peer    Peer record.
	 * @param string            $method  HTTP method.
	 * @param string            $route   REST route incl. namespace.
	 * @param array             $query   Query args as key => value.
	 * @param array|string|null $body    Body payload (see request()).
	 * @param int|null          $timeout Timeout in seconds.
	 * @return array|WP_Error {url: string, args: array}
	 */
	private function prepare( array $peer, $method, $route, array $query, $body, $timeout ) {
		$route = '/' . ltrim( (string) $route, '/' );

		$pairs = array();
		foreach ( $query as $key => $value ) {
			$pairs[] = array( (string) $key, (string) $value );
		}

		$query_string = WPPS_Signer::encode_query_pairs( $pairs );
		$url          = self::build_url( (string) $peer['rest_url'], $route, $query_string );

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'https' !== $scheme && ! self::is_local_host( $host ) ) {
			return new WP_Error( 'wpps_https_required', __( 'Refusing to call a non-local peer over plain HTTP.', 'wp-page-sync' ) );
		}

		if ( is_string( $body ) ) {
			$body_string  = $body;
			$content_type = 'application/octet-stream';
		} elseif ( null !== $body ) {
			$body_string  = wp_json_encode( $body );
			$content_type = 'application/json';
		} else {
			$body_string  = '';
			$content_type = '';
		}

		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		// RECEIVER binding: the peer record's url is the peer's own home_url.
		$canonical = WPPS_Signer::canonical_string( $method, $route, $pairs, $timestamp, $nonce, (string) $peer['url'], $body_string );

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => null === $timeout ? WPPS_TIMEOUT_API : (int) $timeout,
			// Redirects would re-send signed requests to attacker-chosen URLs.
			'redirection' => 0,
			'headers'     => array(
				'X-WPPS-Key-Id'    => (string) $peer['peer_id'],
				'X-WPPS-Timestamp' => $timestamp,
				'X-WPPS-Nonce'     => $nonce,
				'X-WPPS-Signature' => WPPS_Signer::sign( (string) $peer['secret'], $canonical ),
			),
		);

		if ( '' !== $body_string ) {
			$args['headers']['Content-Type'] = $content_type;
			$args['body']                    = $body_string;
		}

		return array(
			'url'  => $url,
			'args' => $args,
		);
	}

	/**
	 * Transport dispatch (security review, SSRF): non-local peers go through
	 * wp_safe_remote_request (blocks internal/reserved IPs); local hosts use
	 * wp_remote_request, because the safe variant rejects .test/127.0.0.1
	 * and would break local pairing.
	 *
	 * @param array $prepared {url, args} from prepare().
	 * @return array|WP_Error Raw transport response.
	 */
	private function send( array $prepared ) {
		$host = (string) wp_parse_url( $prepared['url'], PHP_URL_HOST );
		if ( self::is_local_host( $host ) ) {
			/*
			 * Local peers routinely run self-signed certificates; certificate
			 * verification adds nothing on the same machine, where plain HTTP
			 * is already allowed (plan §4.1). Non-local hosts keep the
			 * default sslverify=true below.
			 */
			$prepared['args']['sslverify'] = false;
			return wp_remote_request( $prepared['url'], $prepared['args'] );
		}
		return wp_safe_remote_request( $prepared['url'], $prepared['args'] );
	}

	/**
	 * Handshake call: claims the pairing key on the peer (plan §4.1).
	 *
	 * @param array $peer Provisional peer record.
	 * @return array|WP_Error Response {site_name, uploads_url, rest_url}.
	 */
	public function handshake( array $peer ) {
		$body = array(
			'site_url'  => home_url(),
			'site_name' => get_bloginfo( 'name' ),
			'rest_url'  => rest_url(),
		);
		return $this->request( $peer, 'POST', '/' . WPPS_REST_NS . '/handshake', array(), $body );
	}

	/**
	 * Best-effort disconnect notification to the peer.
	 *
	 * @param array $peer Peer record.
	 * @return array|WP_Error
	 */
	public function disconnect( array $peer ) {
		return $this->request( $peer, 'POST', '/' . WPPS_REST_NS . '/disconnect' );
	}

	/**
	 * Fetches the peer's /info (ping).
	 *
	 * @param array $peer Peer record.
	 * @return array|WP_Error Response {site_name, home_url, rest_url, uploads_url, wp_version, plugin_version}.
	 */
	public function info( array $peer ) {
		return $this->request( $peer, 'GET', '/' . WPPS_REST_NS . '/info' );
	}

	/**
	 * Whether a host counts as local (plain HTTP allowed, plan §4.1).
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = strtolower( (string) $host );
		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return true;
		}
		return (bool) preg_match( '/\.(test|local)$/', $host );
	}

	/**
	 * Builds the request URL from a stored peer rest_url and a route.
	 *
	 * Supports pretty permalinks (https://x/wp-json/) and plain permalinks
	 * (https://x/?rest_route=/) transparently.
	 *
	 * @param string $rest_url     Stored peer REST base URL.
	 * @param string $route        Route incl. namespace, with leading slash.
	 * @param string $query_string Pre-encoded canonical query string ("" for none).
	 * @return string
	 */
	public static function build_url( $rest_url, $route, $query_string ) {
		if ( false !== strpos( $rest_url, 'rest_route=' ) ) {
			$url = add_query_arg( 'rest_route', $route, $rest_url );
		} else {
			$url = trailingslashit( $rest_url ) . ltrim( $route, '/' );
		}

		if ( '' !== $query_string ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query_string;
		}

		return $url;
	}

	/**
	 * Turns a wp_remote_request() result into a decoded array or WP_Error.
	 *
	 * @param array|WP_Error $response Raw transport response.
	 * @return array|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			if ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
				$message = (string) $decoded['message'];
			} else {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Peer responded with HTTP %d.', 'wp-page-sync' ), $code );
			}
			return new WP_Error( 'wpps_http_' . $code, $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
