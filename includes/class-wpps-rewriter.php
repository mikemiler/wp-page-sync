<?php
/**
 * URL extraction, rewriting and normalization.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ports the semantics of the old marketing/sync link-guard to PHP: URLs to
 * known environment hosts are recognized in plain (http://host/x),
 * JSON-escaped (http:\/\/host\/x) and protocol-relative (//host/x) forms,
 * port-tolerant and www-insensitive.
 *
 * Pass order on import (plan §6.3): media-map rewrite (longest first), then
 * env-host rewrite to relative (uploads URLs excepted so they surface as
 * warnings), then a scan pass for anything left. normalize() is the hashing
 * variant that rewrites ALL env URLs (uploads included) to relative.
 */
class WPPS_Rewriter {

	/**
	 * XML/SVG namespace hosts (xmlns="http://www.w3.org/...") are never links.
	 *
	 * @var string[]
	 */
	const NAMESPACE_HOSTS = array( 'w3.org' );

	/**
	 * Hostname of a URL, lowercased, without a leading "www.".
	 *
	 * @param string $url URL.
	 * @return string "" when the URL has no host.
	 */
	public static function bare_host( $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	/**
	 * Regex matching an absolute/protocol-relative URL to one host, in plain
	 * or JSON-escaped form. Captures the path portion (group 1).
	 *
	 * @param string $host Bare host.
	 * @return string PCRE pattern with ~ delimiters.
	 */
	public static function host_url_regex( $host ) {
		return '~(?:https?:)?(?:\\\\?/){2}(?:www\.)?'
			. preg_quote( $host, '~' )
			// Right boundary: don't match a host that prefixes a longer domain.
			. '(?![a-zA-Z0-9.-])'
			// Optional port, then the path: starts with a (possibly escaped)
			// slash, ends at quote/whitespace/tag/parenthesis delimiters.
			. '(?::\d+)?((?:\\\\?/)[^"\'\s<>)]*)?~i';
	}

	/**
	 * Extracts uploads references for any known env host from a string.
	 *
	 * @param string   $subject Content or meta string.
	 * @param string[] $hosts   Bare env hosts.
	 * @return array[] Entries {url, path, rel}: url = matched string as found,
	 *                 path = slash-normalized path, rel = decoded path
	 *                 relative to the uploads dir, query/fragment stripped.
	 */
	public static function extract_uploads_refs( $subject, array $hosts ) {
		$refs = array();

		foreach ( $hosts as $host ) {
			if ( ! preg_match_all( self::host_url_regex( $host ), (string) $subject, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$path = str_replace( '\/', '/', isset( $match[1] ) ? $match[1] : '' );
				if ( 0 !== strpos( $path, '/wp-content/uploads/' ) ) {
					continue;
				}
				$rel = substr( $path, strlen( '/wp-content/uploads/' ) );
				$rel = preg_split( '/[?#]/', $rel )[0];
				$rel = rawurldecode( $rel );
				if ( '' === $rel ) {
					continue;
				}
				$refs[] = array(
					'url'  => $match[0],
					'path' => $path,
					'rel'  => $rel,
				);
			}
		}

		return $refs;
	}

	/**
	 * Media-map pass: replaces mapped source URLs with target URLs.
	 *
	 * Longest source path first so size variants win over their original.
	 * Matches all three URL forms; a JSON-escaped occurrence is replaced with
	 * the JSON-escaped target URL.
	 *
	 * @param string $subject Content string.
	 * @param array  $map     source URL => target URL.
	 * @return string
	 */
	public static function rewrite_media_map( $subject, array $map ) {
		$subject = (string) $subject;
		$sources = array_keys( $map );
		usort(
			$sources,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $sources as $source ) {
			$host = self::bare_host( $source );
			$path = (string) wp_parse_url( $source, PHP_URL_PATH );
			if ( '' === $host || '' === $path ) {
				continue;
			}

			$path_regex = '';
			foreach ( str_split( $path ) as $char ) {
				$path_regex .= ( '/' === $char ) ? '(?:\\\\?/)' : preg_quote( $char, '~' );
			}

			// The trailing lookahead keeps "img.jpg" from matching inside "img.jpg.webp".
			$pattern = '~(?:https?:)?(?:\\\\?/){2}(?:www\.)?' . preg_quote( $host, '~' )
				. '(?![a-zA-Z0-9.-])(?::\d+)?' . $path_regex . '(?![A-Za-z0-9._\~%-])~i';

			$target = (string) $map[ $source ];
			$result = preg_replace_callback(
				$pattern,
				function ( $matched ) use ( $target ) {
					if ( false !== strpos( $matched[0], '\/' ) ) {
						return str_replace( '/', '\/', $target );
					}
					return $target;
				},
				$subject
			);
			if ( null !== $result ) {
				$subject = $result;
			}
		}

		return $subject;
	}

	/**
	 * Attachment-ID pass: source attachment IDs become target IDs.
	 *
	 * Rewrites "id"/"mediaId" in the attribute JSON of media block openers
	 * (core image, video, audio, file, cover, media-text; GenerateBlocks
	 * media and image) and wp-image-N classes. One preg_replace_callback over
	 * the whole string, so an ID that was just written is never mapped a
	 * second time (source 5 => 9 next to source 9 => 12).
	 *
	 * @param string          $subject Post content.
	 * @param array<int, int> $ids     Source attachment ID => target attachment ID.
	 * @return string
	 */
	public static function rewrite_attachment_ids( $subject, array $ids ) {
		if ( empty( $ids ) ) {
			return (string) $subject;
		}
		return self::map_attachment_ids(
			$subject,
			function ( $id ) use ( $ids ) {
				return isset( $ids[ (int) $id ] ) ? (string) $ids[ (int) $id ] : $id;
			}
		);
	}

	/**
	 * Hashing variant of the attachment-ID pass: every ID it covers becomes
	 * 0, so two copies whose only difference is their site-specific
	 * attachment IDs hash equal.
	 *
	 * @param string $subject Post content.
	 * @return string
	 */
	public static function neutralize_attachment_ids( $subject ) {
		return self::map_attachment_ids(
			$subject,
			function () {
				return '0';
			}
		);
	}

	/**
	 * Shared attachment-ID replacement over the allowlisted block openers
	 * and wp-image-N classes.
	 *
	 * @param string   $subject Post content.
	 * @param callable $map_id  Receives an ID string, returns its replacement.
	 * @return string
	 */
	private static function map_attachment_ids( $subject, callable $map_id ) {
		$subject = (string) $subject;
		$result  = preg_replace_callback(
			'~(<!--\s+wp:(?:(?:core/)?(?:image|video|audio|file|cover|media-text)|generateblocks/(?:media|image))\s+)(\{.*?\})(\s+/?-->)|\bwp-image-(\d+)\b~s',
			function ( $m ) use ( $map_id ) {
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					return 'wp-image-' . $map_id( $m[4] );
				}
				$attrs = preg_replace_callback(
					'~"(id|mediaId)":(\d+)~',
					function ( $attr ) use ( $map_id ) {
						return '"' . $attr[1] . '":' . $map_id( $attr[2] );
					},
					$m[2]
				);
				return $m[1] . $attrs . $m[3];
			},
			$subject
		);

		return ( null !== $result ) ? $result : $subject;
	}

	/**
	 * Env-host pass: absolute/protocol-relative env-host URLs become relative.
	 *
	 * Uploads URLs are deliberately left untouched (a foreign uploads URL
	 * means the media pipeline missed it; the scan pass reports it).
	 *
	 * @param string   $subject Content string.
	 * @param string[] $hosts   Bare env hosts.
	 * @return string
	 */
	public static function rewrite_env_hosts( $subject, array $hosts ) {
		return self::replace_env_hosts( (string) $subject, $hosts, true );
	}

	/**
	 * Hashing normalization: ALL env-host URLs (uploads included) → relative.
	 *
	 * @param string   $subject Content string.
	 * @param string[] $hosts   Bare env hosts.
	 * @return string
	 */
	public static function normalize( $subject, array $hosts ) {
		return self::replace_env_hosts( (string) $subject, $hosts, false );
	}

	/**
	 * Scan pass: remaining env-host URL occurrences (the alarm).
	 *
	 * @param string   $subject Content string.
	 * @param string[] $hosts   Bare env hosts.
	 * @return array[] Entries {host, path, snippet}.
	 */
	public static function scan_env_hosts( $subject, array $hosts ) {
		$subject    = (string) $subject;
		$violations = array();

		foreach ( $hosts as $host ) {
			if ( in_array( $host, self::NAMESPACE_HOSTS, true ) ) {
				continue;
			}
			if ( ! preg_match_all( self::host_url_regex( $host ), $subject, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$offset  = $match[0][1];
				$start   = max( 0, $offset - 60 );
				$snippet = substr( $subject, $start, min( strlen( $subject ), $offset + strlen( $match[0][0] ) + 60 ) - $start );

				$violations[] = array(
					'host'    => $host,
					'path'    => str_replace( '\/', '/', isset( $match[1][0] ) ? $match[1][0] : '' ),
					'snippet' => preg_replace( '/\s+/', ' ', $snippet ),
				);
			}
		}

		return $violations;
	}

	/**
	 * Applies a string callback recursively to a value (meta arrays).
	 *
	 * @param mixed    $value    String, array, or scalar.
	 * @param callable $callback Receives and returns a string.
	 * @return mixed Same shape with all strings mapped.
	 */
	public static function apply_recursive( $value, $callback ) {
		if ( is_string( $value ) ) {
			return call_user_func( $callback, $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::apply_recursive( $item, $callback );
			}
		}
		return $value;
	}

	/**
	 * Collects all strings inside a value (for scanning meta arrays).
	 *
	 * @param mixed $value Value.
	 * @param array $out   Collector, appended to by reference.
	 * @return void
	 */
	public static function collect_strings( $value, array &$out ) {
		if ( is_string( $value ) ) {
			$out[] = $value;
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::collect_strings( $item, $out );
			}
		}
	}

	/**
	 * Shared env-host replacement (ported link-guard pass 1).
	 *
	 * @param string   $subject        Content string.
	 * @param string[] $hosts          Bare env hosts.
	 * @param bool     $except_uploads Leave /wp-content/uploads/ URLs untouched.
	 * @return string
	 */
	private static function replace_env_hosts( $subject, array $hosts, $except_uploads ) {
		foreach ( $hosts as $host ) {
			$result = preg_replace_callback(
				self::host_url_regex( $host ),
				function ( $matched ) use ( $except_uploads ) {
					$path       = isset( $matched[1] ) ? $matched[1] : '';
					$normalized = str_replace( '\/', '/', $path );
					if ( $except_uploads && 0 === strpos( $normalized, '/wp-content/uploads/' ) ) {
						return $matched[0];
					}
					// Keep the path in its found (escaped/plain) form; bare host → "/".
					return '' !== $path ? $path : '/';
				},
				$subject
			);
			if ( null !== $result ) {
				$subject = $result;
			}
		}

		return $subject;
	}
}
