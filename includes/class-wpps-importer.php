<?php
/**
 * Media import (dedup) and post upsert.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports media files (plan §6.2, path hardening §4.3) and upserts post
 * bundles (plan §6.5). Shared by push (peer side, via REST) and pull (local).
 */
class WPPS_Importer {

	/**
	 * The only file types the sync transfers, used for both the write gate
	 * on /media-upload and the read whitelist on /media-file (plan §4.3).
	 *
	 * @var array<string, string>
	 */
	const ALLOWED_MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'gif'      => 'image/gif',
		'webp'     => 'image/webp',
		'avif'     => 'image/avif',
		'svg'      => 'image/svg+xml',
		'pdf'      => 'application/pdf',
		'mp4|m4v'  => 'video/mp4',
		'webm'     => 'video/webm',
		'mov'      => 'video/quicktime',
		'ogv'      => 'video/ogg',
		'mp3|m4a'  => 'audio/mpeg',
		'ogg'      => 'audio/ogg',
		'wav'      => 'audio/wav',
		'vtt'      => 'text/vtt',
		'woff2'    => 'font/woff2',
		'ico'      => 'image/x-icon',
	);

	/**
	 * Folder below the uploads base dir that holds incoming files while they
	 * are being received (chunked push, streamed pull, legacy upload).
	 *
	 * @var string
	 */
	const TEMP_DIR = 'wpps-tmp';

	/**
	 * Age after which an abandoned temp file is deleted (design value, one
	 * hour: every received chunk and every streamed download refreshes the
	 * file's mtime, so only a transfer that stalled for an hour loses it).
	 *
	 * @var int
	 */
	const TEMP_MAX_AGE = HOUR_IN_SECONDS;

	/**
	 * Largest SVG the importer reads into memory for the sanitizer (design
	 * value: far above any web graphic, small enough that the sanitizer's
	 * DOM parse stays cheap). Larger SVGs are rejected.
	 *
	 * @var int
	 */
	const MAX_SVG_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Guard files in the temp folder: they stay when stale files are swept.
	 *
	 * @var string[]
	 */
	const TEMP_GUARDS = array( '.htaccess', 'index.html' );

	/**
	 * Exporter (path lookup, meta denylist, known hosts, content hash).
	 *
	 * @var WPPS_Exporter
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @param WPPS_Exporter $exporter Exporter.
	 */
	public function __construct( WPPS_Exporter $exporter ) {
		$this->exporter = $exporter;
	}

	/**
	 * Validates an uploads-relative path (plan §4.3, widened in 1.3.0).
	 *
	 * Containment rules, all required:
	 * - relative: no leading "/", no empty segment (so no "//"), max 5
	 *   segments; "/" is the only separator, a backslash is not allowed;
	 * - not inside the staging folder (first segment "wpps-tmp");
	 * - every segment passes valid_path_segment(): valid UTF-8, letters,
	 *   digits and combining marks of any script plus "._@-", never starting
	 *   with a dot (rules out ".", ".." and hidden files such as .htaccess),
	 *   no control characters, NUL, "%", ":" or whitespace;
	 * - the basename survives sanitize_file_name() unchanged apart from the
	 *   accent folding WordPress applies itself, so a name WordPress would
	 *   rewrite on upload (special characters, a double extension such as
	 *   "x.php.jpg") is still rejected.
	 * The write path additionally checks realpath() containment.
	 *
	 * @param string $rel Rel path.
	 * @return bool
	 */
	public static function validate_rel_path( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || '/' === $rel[0] ) {
			return false;
		}
		$segments = explode( '/', $rel );
		// The staging folder is never a sync target or source (any case, for case-insensitive file systems).
		if ( count( $segments ) > 5 || strtolower( $segments[0] ) === self::TEMP_DIR ) {
			return false;
		}
		foreach ( $segments as $segment ) {
			if ( ! self::valid_path_segment( $segment ) ) {
				return false;
			}
		}
		$basename = end( $segments );
		return sanitize_file_name( $basename ) === remove_accents( $basename );
	}

	/**
	 * Whether one path segment (a folder or file name) is allowed.
	 *
	 * The /u modifier makes preg_match() fail on invalid UTF-8, so overlong
	 * or broken byte sequences never pass.
	 *
	 * @param mixed $segment Segment.
	 * @return bool
	 */
	public static function valid_path_segment( $segment ) {
		return is_string( $segment )
			&& '' !== $segment
			&& strlen( $segment ) <= 255
			&& '.' !== $segment[0]
			&& 1 === preg_match( '/^[\p{L}\p{N}\p{M}._@-]+$/uD', $segment );
	}

	/**
	 * Whether a rel path's extension is on the transfer whitelist.
	 *
	 * @param string $rel Rel path.
	 * @return bool
	 */
	public static function allowed_extension( $rel ) {
		$ext = strtolower( (string) pathinfo( (string) $rel, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}
		foreach ( array_keys( self::ALLOWED_MIMES ) as $exts ) {
			if ( in_array( $ext, explode( '|', $exts ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Attachment ID whose _wp_attached_file equals a rel path.
	 *
	 * @param string $rel Rel path.
	 * @return int 0 when none exists.
	 */
	public static function attachment_id_by_rel( $rel ) {
		return self::attachment_id_by_meta( '_wp_attached_file', $rel );
	}

	/**
	 * Attachment that owns a file: the one registered under the file itself,
	 * else one whose main file is a WordPress variant of the same name in the
	 * same folder.
	 *
	 * WordPress registers a big image under "photo-scaled.jpg" (or
	 * "photo-rotated.jpg") and keeps the untouched "photo.jpg" only in its
	 * metadata (original_image); size files are "photo-1024x768.jpg", also
	 * for sizes no longer listed in the metadata. So a referenced file
	 * resolves through these candidates: the name itself with -scaled or
	 * -rotated, then the name without its -WxH suffix, plain, -scaled and
	 * -rotated.
	 *
	 * $require_proof is the import-side mode: a candidate only counts when
	 * its metadata names this file as its original_image, i.e. WordPress
	 * itself derived the candidate from exactly this file. That rules out
	 * adopting an unrelated attachment that merely has a similar name.
	 *
	 * @param string $rel           Uploads-relative path of the file.
	 * @param bool   $require_proof Only accept a candidate that lists this file as its original.
	 * @return int 0 when no attachment owns the file.
	 */
	public static function attachment_id_for_file( $rel, $require_proof = false ) {
		$exact = self::attachment_id_by_rel( $rel );
		if ( $exact ) {
			return $exact;
		}

		$basename = wp_basename( $rel );
		$dot      = strrpos( $basename, '.' );
		if ( false === $dot || 0 === $dot ) {
			return 0;
		}
		$dir       = substr( $rel, 0, strlen( $rel ) - strlen( $basename ) );
		$name      = substr( $basename, 0, $dot );
		$extension = substr( $basename, $dot );

		$stems    = $require_proof ? array( $name ) : array_unique( array( $name, (string) preg_replace( '/-\d+x\d+$/', '', $name ) ) );
		$suffixes = $require_proof ? array( '-scaled', '-rotated' ) : array( '', '-scaled', '-rotated' );
		foreach ( $stems as $stem ) {
			foreach ( $suffixes as $suffix ) {
				$candidate = $dir . $stem . $suffix . $extension;
				if ( $candidate === $rel ) {
					continue;
				}
				$id = self::attachment_id_by_rel( $candidate );
				if ( $id && ( ! $require_proof || self::is_original_of( $id, $basename ) ) ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Whether an attachment's metadata names a file as its original_image.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $basename      File name.
	 * @return bool
	 */
	private static function is_original_of( $attachment_id, $basename ) {
		$meta = wp_get_attachment_metadata( $attachment_id, true );
		return is_array( $meta ) && isset( $meta['original_image'] ) && $basename === $meta['original_image'];
	}

	/**
	 * Attachment ID by an exact meta value.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return int
	 */
	private static function attachment_id_by_meta( $key, $value ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _wp_attached_file has no dedicated index anywhere in core; the case/accent-insensitive matches are a handful of rows at most.
				'meta_query'     => array(
					array(
						'key'   => $key,
						'value' => $value,
					),
				),
			)
		);

		/*
		 * MySQL compares with the column's collation, which usually ignores
		 * case and accents ("Photo.jpg" = "photo.jpg", "cafe.jpg" =
		 * "café.jpg"). Only a byte-exact match counts, or an unrelated
		 * attachment would be adopted.
		 */
		foreach ( $query->posts as $id ) {
			if ( (string) get_post_meta( (int) $id, $key, true ) === (string) $value ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Imports one media file (plan §6.2 import rules, §4.3 hardening).
	 *
	 * Idempotent: the same rel_path + sha256 pair always converges on the
	 * same attachment. Different content never overwrites an existing file;
	 * it is stored under a deterministic -wpps{6 hex of sha} suffix.
	 *
	 * The received file is CONSUMED: it is deleted right away, after a copy
	 * under a fresh random name in the temp folder. Every check (size,
	 * sha256, type gate, SVG sanitizer) runs on that copy, so no other
	 * request that still holds a handle on the received file (a parallel
	 * chunk with the same upload id) can change the bytes between the
	 * checks and the move into place. The copy is moved into place on
	 * success and deleted on every other outcome.
	 *
	 * @param string $rel    Uploads-relative target path.
	 * @param string $sha256 Expected sha256 of the file.
	 * @param string $source Absolute path of the received temp file.
	 * @return array|WP_Error {attachment_id, rel_path, url, status, warnings[]}.
	 */
	public function import_media( $rel, $sha256, $source ) {
		$work = self::isolate( $rel, $source );
		if ( is_wp_error( $work ) ) {
			return $work;
		}
		try {
			return $this->import_media_file( $rel, $sha256, $work );
		} finally {
			self::discard( $work );
		}
	}

	/**
	 * Copies a received file to a fresh random-named temp file and deletes
	 * the original. A copy, not a rename: a rename keeps the inode, and with
	 * it any handle another request still has open on it.
	 *
	 * @param string $rel    Uploads-relative target path (for the size message).
	 * @param string $source Received file.
	 * @return string|WP_Error Path of the private copy.
	 */
	private static function isolate( $rel, $source ) {
		try {
			$size = is_file( $source ) ? (int) filesize( $source ) : 0;
			if ( $size > WPPS_MAX_FILE_BYTES ) {
				return new WP_Error( 'wpps_too_large', self::too_large_message( $rel, $size ), array( 'status' => 413 ) );
			}
			$copy = self::temp_file();
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
			if ( 0 === $size || ! copy( $source, $copy ) ) {
				self::discard( $copy );
				return new WP_Error( 'wpps_write_failed', __( 'Could not store the received file.', 'wp-page-sync' ), array( 'status' => 500 ) );
			}
			return $copy;
		} finally {
			self::discard( $source );
		}
	}

	/**
	 * Body of import_media(); the caller deletes $source afterwards.
	 *
	 * @param string $rel    Uploads-relative target path.
	 * @param string $sha256 Expected sha256 of the file.
	 * @param string $source Absolute path of the received temp file.
	 * @return array|WP_Error
	 */
	private function import_media_file( $rel, $sha256, $source ) {
		$request_error = $this->validate_media_request( $rel, $sha256, $source );
		if ( is_wp_error( $request_error ) ) {
			return $request_error;
		}

		$uploads  = wp_upload_dir( null, false );
		$warnings = array();

		if ( file_exists( $uploads['basedir'] . '/' . $rel ) && ! $this->is_same_import( $rel, $sha256, $uploads['basedir'] . '/' . $rel ) ) {
			// Never overwrite different content (plan §6.2).
			$suffixed = preg_replace( '/(\.[A-Za-z0-9]+)$/', '-wpps' . substr( $sha256, 0, 6 ) . '$1', $rel );
			/* translators: 1: original path, 2: renamed path. */
			$warnings[] = sprintf( __( 'A different file already exists at %1$s; stored as %2$s instead.', 'wp-page-sync' ), $rel, $suffixed );
			$rel        = $suffixed;
		}

		$abs = $uploads['basedir'] . '/' . $rel;
		// With proof: the target may have scaled the file it imported before.
		$existing = self::attachment_id_for_file( $rel, true );

		if ( file_exists( $abs ) ) {
			if ( ! $this->is_same_import( $rel, $sha256, $abs ) ) {
				return new WP_Error( 'wpps_conflicting_file', __( 'A conflicting file occupies the fallback path.', 'wp-page-sync' ), array( 'status' => 409 ) );
			}
			$status = $existing ? 'reused' : 'adopted';
		} else {
			$write_error = $this->write_validated_file( $abs, $rel, $source );
			if ( is_wp_error( $write_error ) ) {
				return $write_error;
			}
			$status = $existing ? 'repaired' : 'imported';
		}

		$attachment_id = $existing ? $existing : $this->create_attachment( $rel, $abs, $uploads );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		if ( 'reused' !== $status ) {
			$this->regenerate_metadata( $attachment_id, $rel, $abs );
		}
		update_post_meta( $attachment_id, '_wpps_sha256', $sha256 );

		return array(
			'attachment_id' => $attachment_id,
			'rel_path'      => $rel,
			'url'           => $uploads['baseurl'] . '/' . $rel,
			'status'        => $status,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Receives one chunk of a pushed file (1.3.0 chunked upload).
	 *
	 * Chunks arrive in order, each signed on its own. They are appended to
	 * {uploads}/wpps-tmp/{name}.part, where the name is derived server-side
	 * from the sending peer and its upload_id with an HMAC over this site's
	 * salt: a peer cannot pick or predict a file name, and two peers can
	 * never write into each other's upload. The last chunk completes the file,
	 * which then goes through import_media() (size + sha256 verification,
	 * type gate, dedupe). Any failure deletes the partial file, and the
	 * sender aborts the whole sync before the post is written.
	 *
	 * @param array  $chunk {peer_id, upload_id, rel_path, sha256, offset, total},
	 *                      already validated by the route args.
	 * @param string $bytes Raw chunk body.
	 * @return array|WP_Error {received} for a partial file, the import_media()
	 *                        result for the completing chunk.
	 */
	public function receive_chunk( array $chunk, $bytes ) {
		$offset = (int) $chunk['offset'];
		$total  = (int) $chunk['total'];
		$length = strlen( (string) $bytes );

		if ( ! preg_match( '/^[0-9a-f]{32}$/D', (string) $chunk['upload_id'] ) || $offset < 0 || $length < 1 || $length > WPPS_CHUNK_BYTES || $offset + $length > $total ) {
			return new WP_Error( 'wpps_invalid_chunk', __( 'Invalid upload chunk.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}
		if ( $total > WPPS_MAX_FILE_BYTES ) {
			return new WP_Error( 'wpps_too_large', self::too_large_message( (string) $chunk['rel_path'], $total ), array( 'status' => 413 ) );
		}

		$dir = self::temp_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$part = $dir . '/' . hash_hmac( 'sha256', $chunk['peer_id'] . '|' . $chunk['upload_id'], wp_salt() ) . '.part';

		clearstatcache( true, $part );
		if ( 0 !== $offset && ( ! is_file( $part ) || filesize( $part ) !== $offset ) ) {
			self::discard( $part );
			return new WP_Error( 'wpps_chunk_order', __( 'An upload chunk arrived out of order, so the transfer was aborted. Run the sync again.', 'wp-page-sync' ), array( 'status' => 409 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending to our own temp file inside uploads; WP_Filesystem cannot append.
		$written = file_put_contents( $part, $bytes, ( 0 === $offset ) ? LOCK_EX : FILE_APPEND | LOCK_EX );
		clearstatcache( true, $part );
		if ( $length !== $written ) {
			self::discard( $part );
			return new WP_Error( 'wpps_write_failed', __( 'Could not store the received upload chunk.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		if ( $offset + $length < $total ) {
			return array( 'received' => $offset + $length );
		}
		if ( filesize( $part ) !== $total ) {
			self::discard( $part );
			return new WP_Error( 'wpps_write_failed', __( 'The received file is incomplete.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}

		return $this->import_media( (string) $chunk['rel_path'], (string) $chunk['sha256'], $part );
	}

	/**
	 * Temp folder for incoming files, created on demand inside uploads.
	 *
	 * Each call also deletes every file older than TEMP_MAX_AGE except the
	 * guard files: a transfer whose sender died midway leaves its partial
	 * file behind, and the next transfer cleans it up. The files carry
	 * unguessable names (random or salted HMAC). An empty index.html and a
	 * .htaccess with "Require all denied" keep the folder from being listed
	 * or served by Apache; no PHP file is placed there, since security
	 * scanners report PHP files in uploads. Other web servers ignore both.
	 *
	 * @return string|WP_Error Real path of the folder, without trailing slash.
	 */
	public static function temp_dir() {
		$basedir = wp_upload_dir( null, false )['basedir'];
		$dir     = $basedir . '/' . self::TEMP_DIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wpps_mkdir_failed', __( 'Could not create the uploads directory.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}
		$real_dir  = realpath( $dir );
		$real_base = realpath( $basedir );
		if ( false === $real_dir || false === $real_base || 0 !== strpos( $real_dir . '/', $real_base . '/' ) ) {
			return new WP_Error( 'wpps_outside_uploads', __( 'Target path escapes the uploads directory.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		self::write_guard( $real_dir . '/index.html', '' );
		self::write_guard( $real_dir . '/.htaccess', "Require all denied\n" );

		foreach ( (array) scandir( $real_dir ) as $name ) {
			$stale = $real_dir . '/' . $name;
			if ( is_string( $name ) && ! in_array( $name, self::TEMP_GUARDS, true ) && is_file( $stale ) && filemtime( $stale ) < time() - self::TEMP_MAX_AGE ) {
				self::discard( $stale );
			}
		}

		return $real_dir;
	}

	/**
	 * Creates a guard file in the temp folder unless it exists.
	 *
	 * @param string $file     Absolute path.
	 * @param string $contents File contents.
	 * @return void
	 */
	private static function write_guard( $file, $contents ) {
		if ( ! file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard file in our own temp folder.
			file_put_contents( $file, $contents );
		}
	}

	/**
	 * A fresh, unique temp file path inside temp_dir().
	 *
	 * @return string|WP_Error
	 */
	public static function temp_file() {
		$dir = self::temp_dir();
		return is_wp_error( $dir ) ? $dir : $dir . '/' . bin2hex( random_bytes( 16 ) ) . '.part';
	}

	/**
	 * Deletes a temp file if it exists.
	 *
	 * @param string $file Absolute path.
	 * @return void
	 */
	public static function discard( $file ) {
		if ( is_string( $file ) && '' !== $file && is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
		}
	}

	/**
	 * Human-readable "file too large" message, shared by the pre-flight on
	 * both sides and the receiver.
	 *
	 * @param string $rel   Uploads-relative path.
	 * @param int    $bytes File size.
	 * @return string
	 */
	public static function too_large_message( $rel, $bytes ) {
		return sprintf(
			/* translators: 1: uploads-relative file path, 2: file size, 3: size limit. */
			__( 'File is too large to sync: %1$s (%2$s; the limit is %3$s).', 'wp-page-sync' ),
			$rel,
			size_format( $bytes, 1 ),
			size_format( WPPS_MAX_FILE_BYTES )
		);
	}

	/**
	 * Compares a media manifest against the local library (plan §5
	 * /media-check): identical / different / missing per file.
	 *
	 * @param array $items Entries with rel_path + sha256.
	 * @return array[] Entries {rel_path, status}.
	 */
	public function media_check( array $items ) {
		$basedir = wp_upload_dir( null, false )['basedir'];
		$results = array();

		foreach ( $items as $item ) {
			$rel = isset( $item['rel_path'] ) ? (string) $item['rel_path'] : '';
			$sha = isset( $item['sha256'] ) ? (string) $item['sha256'] : '';
			if ( ! self::validate_rel_path( $rel ) || ! preg_match( '/^[0-9a-f]{64}$/D', $sha ) ) {
				continue;
			}
			$abs = $basedir . '/' . $rel;
			if ( ! file_exists( $abs ) ) {
				$status = 'missing';
			} elseif ( $this->is_same_import( $rel, $sha, $abs ) ) {
				$status = 'identical';
			} else {
				// The deterministic dedup suffix may already hold this content.
				$suffixed = preg_replace( '/(\.[A-Za-z0-9]+)$/', '-wpps' . substr( $sha, 0, 6 ) . '$1', $rel );
				$status   = ( file_exists( $basedir . '/' . $suffixed ) && $this->is_same_import( $suffixed, $sha, $basedir . '/' . $suffixed ) )
					? 'identical'
					: 'different';
			}
			$results[] = array(
				'rel_path' => $rel,
				'status'   => $status,
			);
		}

		return $results;
	}

	/**
	 * Builds the source→target URL map from a media manifest (plan §6.2.2).
	 *
	 * Files must already be present locally (transfer runs first). Source
	 * size-variant URLs map onto the same-NAMED local size file when the
	 * regenerated metadata has one, else onto the full-size target URL.
	 *
	 * Entries that carry the source attachment_id (1.2.0+ sources) also feed
	 * the ids map, source attachment ID => target attachment ID.
	 *
	 * 1.3.0+ entries also carry "variants": other referenced file names of
	 * the same source attachment in the same folder (its untouched original
	 * behind a -scaled main file, size files missing from the metadata).
	 * They map onto the target's full-size URL. And they carry
	 * "attachment_fields" (title, alt, caption, description), which are
	 * applied to the mapped target attachment: the source is the truth.
	 *
	 * @param array $manifest Media manifest entries.
	 * @return array {map: array<string,string>, ids: array<int,int>, warnings: string[]}
	 */
	public function build_media_map( array $manifest ) {
		$uploads  = wp_upload_dir( null, false );
		$map      = array();
		$ids      = array();
		$warnings = array();

		foreach ( $manifest as $entry ) {
			$rel = isset( $entry['rel_path'] ) ? (string) $entry['rel_path'] : '';
			$sha = isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '';
			$src = isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '';
			if ( ! self::validate_rel_path( $rel ) || '' === $src ) {
				continue;
			}

			$attachment_id = $this->resolve_attachment( $rel, $sha );
			if ( ! $attachment_id ) {
				/* translators: %s: uploads-relative file path. */
				$warnings[] = sprintf( __( 'Media file was not transferred: %s', 'wp-page-sync' ), $rel );
				continue;
			}

			$source_id = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;
			if ( $source_id > 0 ) {
				$ids[ $source_id ] = $attachment_id;
			}

			$final_rel   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$target_url  = $uploads['baseurl'] . '/' . $final_rel;
			$map[ $src ] = $target_url;

			$src_dir    = substr( $src, 0, (int) strrpos( $src, '/' ) );
			$target_dir = substr( $target_url, 0, (int) strrpos( $target_url, '/' ) );
			$local_meta = wp_get_attachment_metadata( $attachment_id );
			$local_size = ( is_array( $local_meta ) && ! empty( $local_meta['sizes'] ) ) ? $local_meta['sizes'] : array();

			foreach ( ( isset( $entry['sizes'] ) && is_array( $entry['sizes'] ) ) ? $entry['sizes'] : array() as $name => $file ) {
				if ( ! is_string( $file ) || '' === $file ) {
					continue;
				}
				$map[ $src_dir . '/' . $file ] = isset( $local_size[ $name ]['file'] )
					? $target_dir . '/' . $local_size[ $name ]['file']
					: $target_url;
			}

			foreach ( ( isset( $entry['variants'] ) && is_array( $entry['variants'] ) ) ? $entry['variants'] : array() as $variant ) {
				if ( self::valid_path_segment( $variant ) && ! isset( $map[ $src_dir . '/' . $variant ] ) ) {
					$map[ $src_dir . '/' . $variant ] = $target_url;
				}
			}

			/*
			 * Texts only for an attachment proven to hold this exact file:
			 * with a valid sha256, resolve_attachment() matched by imported
			 * hash or verified file content. Without one it matched by path
			 * alone, which must not let a peer rename a foreign attachment.
			 */
			if ( preg_match( '/^[0-9a-f]{64}$/D', $sha ) ) {
				$this->apply_attachment_fields( $attachment_id, isset( $entry['attachment_fields'] ) ? $entry['attachment_fields'] : null );
			}
		}

		return array(
			'map'      => $map,
			'ids'      => $ids,
			'warnings' => $warnings,
		);
	}

	/**
	 * Applies the source attachment's title, alt text, caption and
	 * description to the mapped target attachment.
	 *
	 * A field missing from the entry (a source before 1.3.0, a file without
	 * an attachment row) leaves the target's value alone. Only changed values
	 * are written. Sanitizing follows core's media modal: title, caption and
	 * description go through wp_update_post(), so the content filters (kses
	 * for a user without unfiltered_html, e.g. the user-less push request)
	 * apply as in core; the alt text is stripped of all tags like core's
	 * wp_ajax_save_attachment() does.
	 *
	 * @param int        $attachment_id Target attachment ID.
	 * @param array|null $fields        {title, alt, caption, description}.
	 * @return void
	 */
	private function apply_attachment_fields( $attachment_id, $fields ) {
		if ( ! is_array( $fields ) ) {
			return;
		}

		$update = array();
		foreach ( array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		) as $key => $column ) {
			if ( ! isset( $fields[ $key ] ) || ! is_string( $fields[ $key ] ) ) {
				continue;
			}
			/*
			 * Compare what would be STORED: the same db-context filters
			 * wp_insert_post() runs (kses turns "Tom & Jerry" into
			 * "Tom &amp; Jerry"), on slashed data as there. Comparing the raw
			 * source value would rewrite an unchanged field on every sync.
			 */
			$stored_form = wp_unslash( sanitize_post_field( $column, wp_slash( $fields[ $key ] ), $attachment_id, 'db' ) );
			if ( get_post_field( $column, $attachment_id, 'raw' ) !== $stored_form ) {
				$update[ $column ] = $fields[ $key ];
			}
		}
		if ( $update ) {
			$update['ID'] = $attachment_id;
			wp_update_post( wp_slash( $update ) );
		}

		if ( isset( $fields['alt'] ) && is_string( $fields['alt'] ) ) {
			$alt = wp_strip_all_tags( $fields['alt'], true );
			if ( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}
	}

	/**
	 * Upserts a post bundle (plan §6.5). Media must already be present.
	 *
	 * @param array $bundle Export bundle (plan §5 shape).
	 * @return array|WP_Error {id, url, hash, warnings[]}.
	 */
	public function import_post( array $bundle ) {
		$post = $this->validate_bundle_post( $bundle );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$source_host = isset( $bundle['source']['home_url'] ) ? WPPS_Rewriter::bare_host( (string) $bundle['source']['home_url'] ) : '';
		$hosts       = $this->exporter->foreign_hosts( '' !== $source_host ? array( $source_host ) : array() );
		$manifest    = ( isset( $bundle['media_manifest'] ) && is_array( $bundle['media_manifest'] ) ) ? $bundle['media_manifest'] : array();
		$media       = $this->build_media_map( $manifest );
		$warnings    = $media['warnings'];

		$rewrite = function ( $value ) use ( $media, $hosts ) {
			$value = WPPS_Rewriter::rewrite_media_map( $value, $media['map'] );
			return WPPS_Rewriter::rewrite_env_hosts( $value, $hosts );
		};

		$content = WPPS_Rewriter::rewrite_attachment_ids( $rewrite( $post['content'] ), $media['ids'] );
		$excerpt = $rewrite( $post['excerpt'] );
		$meta    = WPPS_Rewriter::apply_recursive( $this->validate_bundle_meta( $bundle ), $rewrite );

		$post_id = $this->upsert_post( $post, $content, $excerpt, $warnings );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->apply_meta( $post_id, $meta, $post['template'] );
		$this->apply_terms( $post_id, $post['post_type'], isset( $bundle['terms'] ) ? $bundle['terms'] : null, $warnings );
		$this->apply_featured( $post_id, isset( $bundle['featured'] ) ? $bundle['featured'] : null, $warnings );
		$this->collect_scan_warnings( array_merge( array( $content, $excerpt ), $this->flatten( $meta ) ), $hosts, $warnings );

		$saved = get_post( $post_id );

		return array(
			'id'       => $post_id,
			'url'      => get_permalink( $post_id ),
			'hash'     => $saved ? $this->exporter->content_hash( $saved ) : '',
			'warnings' => $warnings,
		);
	}

	/**
	 * Validates and normalizes the bundle's post block.
	 *
	 * @param array $bundle Bundle.
	 * @return array|WP_Error Normalized post fields.
	 */
	private function validate_bundle_post( array $bundle ) {
		$post    = ( isset( $bundle['post'] ) && is_array( $bundle['post'] ) ) ? $bundle['post'] : array();
		$invalid = new WP_Error( 'wpps_invalid_bundle', __( 'Invalid post bundle.', 'wp-page-sync' ), array( 'status' => 400 ) );

		$post_type = isset( $post['post_type'] ) ? (string) $post['post_type'] : '';
		$path      = isset( $post['path'] ) ? (string) $post['path'] : '';
		$slug      = isset( $post['slug'] ) ? sanitize_title( (string) $post['slug'] ) : '';
		if ( ! in_array( $post_type, WPPS_Exporter::SYNCABLE_POST_TYPES, true ) || '' === $slug || ! self::validate_post_path( $path ) ) {
			return $invalid;
		}

		$status = isset( $post['status'] ) ? (string) $post['status'] : 'draft';
		$status = ( 'pending' === $status ) ? 'draft' : $status;
		if ( ! in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
			$status = 'draft';
		}

		return array(
			'post_type'   => $post_type,
			'path'        => $path,
			'slug'        => $slug,
			'title'       => isset( $post['title'] ) ? (string) $post['title'] : '',
			'status'      => $status,
			'content'     => isset( $post['content'] ) ? (string) $post['content'] : '',
			'excerpt'     => isset( $post['excerpt'] ) ? (string) $post['excerpt'] : '',
			'menu_order'  => isset( $post['menu_order'] ) ? (int) $post['menu_order'] : 0,
			'parent_path' => isset( $post['parent_path'] ) ? (string) $post['parent_path'] : '',
			'template'    => isset( $post['template'] ) ? sanitize_text_field( (string) $post['template'] ) : '',
			'date'        => self::valid_datetime( isset( $post['date'] ) ? $post['date'] : '' ),
			'date_gmt'    => self::valid_datetime( isset( $post['date_gmt'] ) ? $post['date_gmt'] : '' ),
			// Page settings (1.3.0+ sources); null = not sent, leave the target's value.
			'password'    => ( isset( $post['password'] ) && is_string( $post['password'] ) ) ? $post['password'] : null,
			'comments'    => self::open_or_closed( $post, 'comment_status' ),
			'pings'       => self::open_or_closed( $post, 'ping_status' ),
		);
	}

	/**
	 * A bundle's comment_status / ping_status: "open", "closed" or null when
	 * the source did not send it (or sent anything else).
	 *
	 * @param array  $post Bundle post block.
	 * @param string $key  Field name.
	 * @return string|null
	 */
	private static function open_or_closed( array $post, $key ) {
		return ( isset( $post[ $key ] ) && in_array( $post[ $key ], array( 'open', 'closed' ), true ) ) ? $post[ $key ] : null;
	}

	/**
	 * A MySQL datetime string ("Y-m-d H:i:s") or "" for anything else.
	 *
	 * The all-zero value (a draft's unset GMT date) counts as absent.
	 *
	 * @param mixed $value Raw bundle value.
	 * @return string
	 */
	private static function valid_datetime( $value ) {
		if ( ! is_string( $value ) || '0000-00-00 00:00:00' === $value ) {
			return '';
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ? $value : '';
	}

	/**
	 * Validates a hierarchical post path (slug segments, depth-capped).
	 *
	 * Slugs may contain percent-encoded UTF-8 (sanitize_title output).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function validate_post_path( $path ) {
		return is_string( $path )
			&& '' !== $path
			&& strlen( $path ) <= 500
			&& (bool) preg_match( '~^[A-Za-z0-9%_\-]+(?:/[A-Za-z0-9%_\-]+){0,9}$~D', $path );
	}

	/**
	 * Filters bundle meta down to safe keys (denylist re-applied on import).
	 *
	 * @param array $bundle Bundle.
	 * @return array
	 */
	private function validate_bundle_meta( array $bundle ) {
		$meta = array();
		foreach ( ( isset( $bundle['meta'] ) && is_array( $bundle['meta'] ) ) ? $bundle['meta'] : array() as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_.\-]{1,255}$/D', $key ) || WPPS_Exporter::is_denied_meta_key( $key ) ) {
				continue;
			}
			$meta[ $key ] = $value;
		}
		return $meta;
	}

	/**
	 * Inserts or updates the post row (kses bracket per plan §4.3).
	 *
	 * An update goes through wp_update_post(), which merges with the stored
	 * row: every field the bundle does not carry (author, comment and ping
	 * status from a pre-1.3.0 source, post_content_filtered, …) keeps the
	 * target's value instead of falling back to wp_insert_post() defaults.
	 * A new post keeps the defaults, including the author (the current
	 * user: none for a push, the pulling admin for a pull).
	 *
	 * @param array    $post     Normalized post fields.
	 * @param string   $content  Rewritten content.
	 * @param string   $excerpt  Rewritten excerpt.
	 * @param string[] $warnings Warning collector.
	 * @return int|WP_Error
	 */
	private function upsert_post( array $post, $content, $excerpt, array &$warnings ) {
		$parent_id = $this->parent_id( $post, $warnings );
		$existing  = $this->exporter->find_post( $post['post_type'], $post['path'] );

		$postarr = array_merge(
			array(
				'post_type'    => $post['post_type'],
				'post_name'    => $post['slug'],
				'post_title'   => $post['title'],
				'post_status'  => $post['status'],
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'menu_order'   => $post['menu_order'],
				'post_parent'  => $parent_id,
			),
			$this->post_dates( $post, $existing ),
			self::post_settings( $post )
		);

		/*
		 * The importer runs as user 0, where kses would strip inline <svg>
		 * from GenerateBlocks content. Same approach as WP's own importer;
		 * the writer is an HMAC-authenticated peer paired by an admin. The
		 * pre-update revision sits INSIDE the bracket on purpose: it writes
		 * the old content through wp_insert_post() as well, so with kses
		 * active the stored revision would lose the same inline SVG. The
		 * slug repair re-saves the post and therefore sits inside as well.
		 */
		kses_remove_filters();
		try {
			if ( $existing ) {
				$this->keep_previous_content( $existing );
				$postarr['ID'] = $existing->ID;
				// Keeps wp_update_post() from resetting a draft's date to "now".
				$postarr['edit_date'] = true;
				/*
				 * wp_update_post() merges the stored page_template back in, and
				 * wp_insert_post() rejects one the active theme does not list
				 * (invalid_page_template). The template is apply_meta()'s job.
				 */
				$postarr['page_template'] = '';
				$post_id                  = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$post_id = wp_insert_post( wp_slash( $postarr ), true );
			}
			if ( ! is_wp_error( $post_id ) ) {
				$this->claim_slug( (int) $post_id, $post['slug'], $parent_id, $warnings );
			}
		} finally {
			kses_init_filters();
		}

		return $post_id;
	}

	/**
	 * Local parent ID for the bundle's parent path (0 = top level).
	 *
	 * @param array    $post     Normalized post fields.
	 * @param string[] $warnings Warning collector.
	 * @return int
	 */
	private function parent_id( array $post, array &$warnings ) {
		if ( '' === $post['parent_path'] ) {
			return 0;
		}
		$parent = $this->exporter->find_post( $post['post_type'], $post['parent_path'] );
		if ( $parent ) {
			return $parent->ID;
		}
		/* translators: %s: parent path. */
		$warnings[] = sprintf( __( 'Parent "%s" does not exist here; imported at the top level.', 'wp-page-sync' ), $post['parent_path'] );
		return 0;
	}

	/**
	 * Page settings for wp_insert_post(): only the ones the bundle carries.
	 *
	 * @param array $post Normalized post fields.
	 * @return array Subset of post_password / comment_status / ping_status.
	 */
	private static function post_settings( array $post ) {
		$settings = array(
			'post_password'  => $post['password'],
			'comment_status' => $post['comments'],
			'ping_status'    => $post['pings'],
		);
		return array_filter(
			$settings,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Makes sure the saved post ends up under the requested slug.
	 *
	 * Core gives a page the slug "pricing-2" when an attachment with the
	 * same parent already holds "pricing", and media imported for this very
	 * page is created first, as an unattached attachment named after its
	 * file. The next sync would then miss the page at its path and create
	 * "pricing-3". When the blocker is an attachment, it is moved to a free
	 * "<slug>-media" slug (core keeps that unique) and the post is saved
	 * again under the requested slug. Any other blocker (a real post or
	 * page) is left alone and reported.
	 *
	 * @param int      $post_id   Saved post ID.
	 * @param string   $slug      Requested slug.
	 * @param int      $parent_id Post parent.
	 * @param string[] $warnings  Warning collector.
	 * @return void
	 */
	private function claim_slug( $post_id, $slug, $parent_id, array &$warnings ) {
		$stored = (string) get_post_field( 'post_name', $post_id );
		if ( $stored === $slug ) {
			return;
		}

		$blocker = $this->slug_blocker( $post_id, $slug, $parent_id );
		if ( $blocker && 'attachment' === get_post_type( $blocker ) ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'        => $blocker,
						'post_name' => $slug . '-media',
					)
				)
			);
			wp_update_post(
				wp_slash(
					array(
						'ID'            => $post_id,
						'post_name'     => $slug,
						'edit_date'     => true,
						'page_template' => '',
					)
				)
			);
			$stored = (string) get_post_field( 'post_name', $post_id );
		}

		if ( $stored === $slug ) {
			return;
		}
		if ( $blocker && 'attachment' !== get_post_type( $blocker ) ) {
			/* translators: 1: requested slug, 2: slug the post was saved under. */
			$warnings[] = sprintf( __( 'The address "%1$s" is already used by another post or page on this site, so this one was saved as "%2$s". Rename or remove the other one, then sync again.', 'wp-page-sync' ), $slug, $stored );
			return;
		}
		/* translators: 1: requested slug, 2: slug the post was saved under. */
		$warnings[] = sprintf( __( 'WordPress did not accept the address "%1$s" on this site (it reserves some addresses, for example for feeds or date archives), so this post was saved as "%2$s". Choose a different slug at the source, then sync again.', 'wp-page-sync' ), $slug, $stored );
	}

	/**
	 * The entry holding a slug in the same scope core's wp_unique_post_slug()
	 * checks: for a hierarchical type, the same type or an attachment with
	 * the same parent; otherwise the same type. Every status counts, as in
	 * core.
	 *
	 * @param int    $post_id   Post that wants the slug.
	 * @param string $slug      Slug.
	 * @param int    $parent_id Post parent.
	 * @return int 0 when none.
	 */
	private function slug_blocker( $post_id, $slug, $parent_id ) {
		$post_type    = (string) get_post_type( $post_id );
		$hierarchical = is_post_type_hierarchical( $post_type );

		$args = array(
			'name'           => $slug,
			'post_type'      => $hierarchical ? array( $post_type, 'attachment' ) : array( $post_type ),
			'post_status'    => array_values( get_post_stati() ),
			'post__not_in'   => array( $post_id ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( $hierarchical ) {
			$args['post_parent'] = $parent_id;
		}

		$query = new WP_Query( $args );
		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Date fields for wp_insert_post(): the source GMT date wins (local date
	 * derived in this site's timezone), else the source local date, else an
	 * update keeps the existing dates. Without them wp_insert_post() resets
	 * an updated post's date to "now". A future date on a published post
	 * turns it into a scheduled ("future") post, as in core.
	 *
	 * @param array        $post     Normalized post fields.
	 * @param WP_Post|null $existing Target post being updated, if any.
	 * @return array Subset of post_date / post_date_gmt.
	 */
	private function post_dates( array $post, $existing ) {
		if ( '' !== $post['date_gmt'] ) {
			return array(
				'post_date'     => get_date_from_gmt( $post['date_gmt'] ),
				'post_date_gmt' => $post['date_gmt'],
			);
		}
		if ( '' !== $post['date'] ) {
			/*
			 * An empty GMT date lets core derive it (or keep it unset for a
			 * draft); without it, an update would keep the target's old GMT
			 * date, e.g. of a published post a draft is pushed over.
			 */
			return array(
				'post_date'     => $post['date'],
				'post_date_gmt' => '',
			);
		}
		if ( $existing ) {
			return array(
				'post_date'     => $existing->post_date,
				'post_date_gmt' => $existing->post_date_gmt,
			);
		}
		return array();
	}

	/**
	 * Stores the target's CURRENT content as a post revision before an
	 * overwrite.
	 *
	 * wp_save_post_revision() re-reads the post from the database, so calling
	 * it before the update snapshots the OLD state. Core's own revision runs
	 * on wp_after_insert_post and stores the NEW state, which leaves a post
	 * that never had a revision (created programmatically, never opened in the
	 * editor) with no copy of what the sync replaced. This call is what makes
	 * the force-option promise true.
	 *
	 * Its reach is exactly _wp_post_revision_fields(): post_title, post_content
	 * and post_excerpt. Everything apply_meta() and apply_featured() write after
	 * the upsert (post meta, the page template, the featured image) is outside a
	 * revision and cannot be restored from one, which is why the force label and
	 * the readme say so.
	 *
	 * Core re-checks wp_revisions_enabled() and skips a revision identical to
	 * the latest one, so an unchanged re-import adds nothing.
	 *
	 * @param WP_Post $existing Target post as it currently stands.
	 * @return void
	 */
	private function keep_previous_content( WP_Post $existing ) {
		if ( wp_revisions_enabled( $existing ) ) {
			wp_save_post_revision( $existing->ID );
		}
	}

	/**
	 * Writes bundle meta and the template meta.
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $meta     Rewritten meta.
	 * @param string $template Template slug ("" = default).
	 * @return void
	 */
	private function apply_meta( $post_id, array $meta, $template ) {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		if ( '' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		} else {
			delete_post_meta( $post_id, '_wp_page_template' );
		}
	}

	/**
	 * Assigns the bundle's terms, matched by slug.
	 *
	 * Only syncable taxonomies present in the payload are touched: a bundle
	 * from a pre-1.2.0 source has no terms key and changes nothing. Missing
	 * terms are created at the top level; existing terms are never renamed.
	 * wp_set_object_terms() is used instead of tax_input, which is
	 * capability-gated and would be dropped for the importer's user 0.
	 *
	 * @param int        $post_id   Post ID.
	 * @param string     $post_type Post type.
	 * @param array|null $terms     Bundle terms: taxonomy => list of {slug, name}.
	 * @param string[]   $warnings  Warning collector.
	 * @return void
	 */
	private function apply_terms( $post_id, $post_type, $terms, array &$warnings ) {
		if ( ! is_array( $terms ) ) {
			return;
		}
		foreach ( array_intersect( WPPS_Exporter::SYNCABLE_TAXONOMIES, get_object_taxonomies( $post_type ) ) as $taxonomy ) {
			if ( ! isset( $terms[ $taxonomy ] ) || ! is_array( $terms[ $taxonomy ] ) ) {
				continue;
			}
			$term_ids = array();
			foreach ( $terms[ $taxonomy ] as $term ) {
				$term_id = is_array( $term ) ? $this->resolve_term( $term, $taxonomy, $warnings ) : 0;
				if ( $term_id ) {
					$term_ids[] = $term_id;
				}
			}
			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: taxonomy name, 2: error message. */
				$warnings[] = sprintf( __( 'Terms of %1$s could not be assigned: %2$s', 'wp-page-sync' ), $taxonomy, $result->get_error_message() );
			}
		}
	}

	/**
	 * Local term ID for a bundle term: existing by slug, else newly created.
	 *
	 * @param array    $term     Bundle term {slug, name}.
	 * @param string   $taxonomy Taxonomy.
	 * @param string[] $warnings Warning collector.
	 * @return int 0 when the term is invalid or could not be created.
	 */
	private function resolve_term( array $term, $taxonomy, array &$warnings ) {
		$raw_slug = isset( $term['slug'] ) ? $term['slug'] : '';
		$raw_name = isset( $term['name'] ) ? $term['name'] : '';
		if ( ! is_scalar( $raw_slug ) || ! is_scalar( $raw_name ) ) {
			/* translators: %s: taxonomy name. */
			$warnings[] = sprintf( __( 'A malformed term of %s was skipped.', 'wp-page-sync' ), $taxonomy );
			return 0;
		}
		$slug = sanitize_title( (string) $raw_slug );
		if ( '' === $slug ) {
			return 0;
		}
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$name    = sanitize_text_field( (string) $raw_name );
		$created = wp_insert_term( wp_slash( '' !== $name ? $name : $slug ), $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			/* translators: 1: term slug, 2: error message. */
			$warnings[] = sprintf( __( 'Term "%1$s" could not be created: %2$s', 'wp-page-sync' ), $slug, $created->get_error_message() );
			return 0;
		}
		return (int) $created['term_id'];
	}

	/**
	 * Maps the bundle's featured image onto a local attachment.
	 *
	 * @param int        $post_id  Post ID.
	 * @param array|null $featured Bundle featured descriptor.
	 * @param string[]   $warnings Warning collector.
	 * @return void
	 */
	private function apply_featured( $post_id, $featured, array &$warnings ) {
		if ( ! is_array( $featured ) || empty( $featured['rel_path'] ) ) {
			delete_post_meta( $post_id, '_thumbnail_id' );
			return;
		}

		$rel = (string) $featured['rel_path'];
		$sha = isset( $featured['sha256'] ) ? (string) $featured['sha256'] : '';
		if ( ! self::validate_rel_path( $rel ) ) {
			return;
		}

		$attachment_id = $this->resolve_attachment( $rel, $sha );
		if ( $attachment_id ) {
			update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		} else {
			/* translators: %s: uploads-relative file path. */
			$warnings[] = sprintf( __( 'Featured image could not be mapped: %s', 'wp-page-sync' ), $rel );
		}
	}

	/**
	 * Resolves a manifest file to a local attachment: by imported sha256
	 * first (covers the -wpps dedup suffix), then by rel path with a
	 * matching file on disk.
	 *
	 * @param string $rel Rel path.
	 * @param string $sha Expected sha256 ("" skips the content check).
	 * @return int 0 when unresolved.
	 */
	private function resolve_attachment( $rel, $sha ) {
		if ( preg_match( '/^[0-9a-f]{64}$/D', $sha ) ) {
			$by_sha = self::attachment_id_by_meta( '_wpps_sha256', $sha );
			if ( $by_sha ) {
				return $by_sha;
			}
		}

		$by_rel = self::attachment_id_for_file( $rel, true );
		if ( ! $by_rel ) {
			return 0;
		}
		if ( '' === $sha ) {
			return $by_rel;
		}
		$abs = wp_upload_dir( null, false )['basedir'] . '/' . $rel;
		return ( file_exists( $abs ) && hash_file( 'sha256', $abs ) === $sha ) ? $by_rel : 0;
	}

	/**
	 * Runs the scan pass over final strings and records leftovers (§6.3.3).
	 *
	 * @param string[] $strings  Rewritten strings.
	 * @param string[] $hosts    Env hosts.
	 * @param string[] $warnings Warning collector.
	 * @return void
	 */
	private function collect_scan_warnings( array $strings, array $hosts, array &$warnings ) {
		$seen = array();
		foreach ( $strings as $subject ) {
			foreach ( WPPS_Rewriter::scan_env_hosts( $subject, $hosts ) as $violation ) {
				$key = $violation['host'] . $violation['path'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				/* translators: 1: host name, 2: URL path. */
				$warnings[] = sprintf( __( 'Environment URL left in content: %1$s%2$s', 'wp-page-sync' ), $violation['host'], $violation['path'] );
			}
		}
	}

	/**
	 * Flattens a meta array to its strings.
	 *
	 * @param array $meta Meta values.
	 * @return string[]
	 */
	private function flatten( array $meta ) {
		$strings = array();
		WPPS_Rewriter::collect_strings( $meta, $strings );
		return $strings;
	}

	/**
	 * Request-level validation for import_media().
	 *
	 * @param string $rel    Rel path.
	 * @param string $sha256 Declared sha256.
	 * @param string $source Received temp file.
	 * @return true|WP_Error
	 */
	private function validate_media_request( $rel, $sha256, $source ) {
		if ( ! self::validate_rel_path( $rel ) ) {
			return new WP_Error( 'wpps_invalid_path', __( 'Invalid file path.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}
		if ( ! self::allowed_extension( $rel ) ) {
			return new WP_Error( 'wpps_type_not_allowed', __( 'File type is not allowed.', 'wp-page-sync' ), array( 'status' => 415 ) );
		}
		$size = is_file( $source ) ? (int) filesize( $source ) : 0;
		if ( $size > WPPS_MAX_FILE_BYTES ) {
			return new WP_Error( 'wpps_too_large', self::too_large_message( $rel, $size ), array( 'status' => 413 ) );
		}
		if ( 0 === $size || hash_file( 'sha256', $source ) !== $sha256 ) {
			return new WP_Error( 'wpps_hash_mismatch', __( 'File content does not match its checksum.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Whether the file at $abs holds exactly this import: either the bytes
	 * match the declared sha256, or the attachment at this rel path was
	 * imported FROM this source content (its _wpps_sha256 meta stores the
	 * declared source hash). The meta fallback keeps SVG imports idempotent:
	 * the mandatory sanitizer pass rewrites the bytes on disk, so the disk
	 * hash of a sanitized SVG never equals its source hash again.
	 *
	 * @param string $rel    Rel path.
	 * @param string $sha256 Declared source sha256.
	 * @param string $abs    Absolute path of the existing file.
	 * @return bool
	 */
	private function is_same_import( $rel, $sha256, $abs ) {
		if ( hash_file( 'sha256', $abs ) === $sha256 ) {
			return true;
		}
		$attachment_id = self::attachment_id_for_file( $rel, true );
		return $attachment_id && get_post_meta( $attachment_id, '_wpps_sha256', true ) === $sha256;
	}

	/**
	 * Moves the checked file into place (§4.3 gates). The file is the
	 * private copy from import_media(), already size- and sha256-verified in
	 * the temp folder. The type gate (own mime map, SVG sniff + mandatory
	 * sanitizer) also runs there, so only a fully checked file ever enters
	 * a visible uploads folder, with one rename after the containment check.
	 *
	 * @param string $abs    Absolute target path.
	 * @param string $rel    Rel path (for the filename-based type check).
	 * @param string $source Checked private temp file.
	 * @return true|WP_Error
	 */
	private function write_validated_file( $abs, $rel, $source ) {
		$checked = $this->check_file_type( $source, wp_basename( $rel ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		try {
			$basedir = wp_upload_dir( null, false )['basedir'];
			$dir     = dirname( $abs );
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'wpps_mkdir_failed', __( 'Could not create the uploads directory.', 'wp-page-sync' ), array( 'status' => 500 ) );
			}
			$real_dir  = realpath( $dir );
			$real_base = realpath( $basedir );
			if ( false === $real_dir || false === $real_base || 0 !== strpos( $real_dir . '/', $real_base . '/' ) ) {
				return new WP_Error( 'wpps_outside_uploads', __( 'Target path escapes the uploads directory.', 'wp-page-sync' ), array( 'status' => 400 ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- direct uploads write in a server-to-server import; WP_Filesystem adds credential prompts that cannot exist in REST context.
			if ( ! rename( $checked, $real_dir . '/' . wp_basename( $abs ) ) ) {
				return new WP_Error( 'wpps_write_failed', __( 'Could not move the file into place.', 'wp-page-sync' ), array( 'status' => 500 ) );
			}
		} finally {
			// A sanitized SVG is a separate file; after a successful rename this is a no-op.
			self::discard( $checked );
		}

		return true;
	}

	/**
	 * Type gate with our OWN mime map (plan §4.3): core's default map blocks
	 * svg/woff2 for user 0, so the map is passed explicitly AND registered
	 * as a scoped upload_mimes filter for the duration of the check.
	 *
	 * @param string $file     Path of the checked temp file.
	 * @param string $filename Real target file name.
	 * @return string|WP_Error Path of the file to move into place: $file, or
	 *                         for an SVG a new temp file with the sanitized
	 *                         markup.
	 */
	private function check_file_type( $file, $filename ) {
		$mimes  = self::ALLOWED_MIMES;
		$filter = function () use ( $mimes ) {
			return $mimes;
		};

		add_filter( 'upload_mimes', $filter, 999 );
		$check = wp_check_filetype_and_ext( $file, $filename, $mimes );
		remove_filter( 'upload_mimes', $filter, 999 );

		if ( 'svg' === strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			return $this->check_svg( $file );
		}
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! empty( $check['proper_filename'] ) ) {
			return new WP_Error(
				'wpps_type_mismatch',
				/* translators: %s: file name. */
				sprintf( __( 'WordPress on the receiving site rejected %s: its content does not match the file extension, or this server cannot identify the file type.', 'wp-page-sync' ), $filename ),
				array( 'status' => 415 )
			);
		}
		return $file;
	}

	/**
	 * SVG rules (plan §4.3, hardened per security review): finfo may sniff
	 * SVG as text/plain, so accept only files starting with <?xml or <svg
	 * (unconditionally, whatever the core check said). The sanitizer pass is
	 * MANDATORY: without safe-svg's bundled sanitizer class the import is
	 * rejected, and an unsanitized SVG is never written. The sanitized
	 * markup goes to a NEW temp file; the checked file is never rewritten
	 * in place.
	 *
	 * @param string $file Temp file path.
	 * @return string|WP_Error Path of the sanitized temp file.
	 */
	private function check_svg( $file ) {
		if ( (int) filesize( $file ) > self::MAX_SVG_BYTES ) {
			return new WP_Error(
				'wpps_svg_too_large',
				/* translators: %s: size limit, e.g. "5 MB". */
				sprintf( __( 'The SVG is larger than %s and was rejected.', 'wp-page-sync' ), size_format( self::MAX_SVG_BYTES ) ),
				array( 'status' => 413 )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own temp file.
		$contents = (string) file_get_contents( $file );
		$head     = ltrim( str_replace( "\xEF\xBB\xBF", '', substr( $contents, 0, 256 ) ) );

		if ( 0 !== strpos( $head, '<?xml' ) && 0 !== strpos( $head, '<svg' ) ) {
			return new WP_Error( 'wpps_type_mismatch', __( 'File is not a valid SVG.', 'wp-page-sync' ), array( 'status' => 415 ) );
		}

		if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
			return new WP_Error(
				'wpps_svg_sanitizer_missing',
				__( 'SVG import requires the safe-svg plugin (its bundled SVG sanitizer) on the receiving site.', 'wp-page-sync' ),
				array( 'status' => 415 )
			);
		}

		$sanitizer = new \enshrined\svgSanitize\Sanitizer();
		$clean     = $sanitizer->sanitize( $contents );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return new WP_Error( 'wpps_svg_rejected', __( 'The SVG did not survive sanitization and was rejected.', 'wp-page-sync' ), array( 'status' => 415 ) );
		}

		$sanitized = self::temp_file();
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the sanitized SVG to a new temp file of our own.
		if ( false === file_put_contents( $sanitized, $clean, LOCK_EX ) ) {
			self::discard( $sanitized );
			return new WP_Error( 'wpps_write_failed', __( 'Could not write the sanitized SVG.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		return $sanitized;
	}

	/**
	 * Creates the attachment row for a written file (plan §10 rules:
	 * post_mime_type + guid set explicitly).
	 *
	 * @param string $rel     Rel path.
	 * @param string $abs     Absolute path.
	 * @param array  $uploads wp_upload_dir() array.
	 * @return int|WP_Error
	 */
	private function create_attachment( $rel, $abs, array $uploads ) {
		$filetype = wp_check_filetype( basename( $rel ), self::ALLOWED_MIMES );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
				'post_title'     => sanitize_text_field( (string) preg_replace( '/\.[^.]+$/', '', wp_basename( $rel ) ) ),
				'post_status'    => 'inherit',
				'guid'           => $uploads['baseurl'] . '/' . $rel,
			),
			$abs,
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		return (int) $attachment_id;
	}

	/**
	 * Regenerates and persists attachment metadata (plan §10: only the
	 * image/PDF branches persist internally, so the return value must be
	 * written back explicitly).
	 *
	 * The attachment is first pointed at the file just written. That only
	 * changes something for an attachment found through its original_image
	 * (WordPress scaled the file on an earlier import): core then scales the
	 * re-written original again and moves the pointer back to "-scaled",
	 * and if this site no longer scales, file and metadata still agree.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $rel           Rel path of the written file.
	 * @param string $abs           Absolute file path.
	 * @return void
	 */
	private function regenerate_metadata( $attachment_id, $rel, $abs ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $rel ) {
			update_attached_file( $attachment_id, $abs );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $abs );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}
}
