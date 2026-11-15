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
		'svg'      => 'image/svg+xml',
		'pdf'      => 'application/pdf',
		'mp4'      => 'video/mp4',
		'webm'     => 'video/webm',
		'woff2'    => 'font/woff2',
		'ico'      => 'image/x-icon',
	);

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
	 * Validates an uploads-relative path (plan §4.3).
	 *
	 * No empty/./.. segments, max 5 segments, segment charset [A-Za-z0-9._-],
	 * basename must survive sanitize_file_name() unchanged (also rejects
	 * .htaccess-style names since sanitize strips leading dots).
	 *
	 * @param string $rel Rel path.
	 * @return bool
	 */
	public static function validate_rel_path( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || '/' === $rel[0] ) {
			return false;
		}
		$segments = explode( '/', $rel );
		if ( count( $segments ) > 5 ) {
			return false;
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || ! preg_match( '/^[A-Za-z0-9._-]+$/', $segment ) ) {
				return false;
			}
		}
		$basename = end( $segments );
		return sanitize_file_name( $basename ) === $basename;
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
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _wp_attached_file has no dedicated index anywhere in core; bounded to one row.
				'meta_query'     => array(
					array(
						'key'   => $key,
						'value' => $value,
					),
				),
			)
		);
		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Imports one media file (plan §6.2 import rules, §4.3 hardening).
	 *
	 * Idempotent: the same rel_path + sha256 pair always converges on the
	 * same attachment. Different content never overwrites an existing file;
	 * it is stored under a deterministic -wpps{6 hex of sha} suffix.
	 *
	 * @param string $rel    Uploads-relative target path.
	 * @param string $sha256 Expected sha256 of the binary.
	 * @param string $mime   Declared mime (informational; the type gate decides).
	 * @param string $binary Raw file bytes.
	 * @return array|WP_Error {attachment_id, rel_path, url, status, warnings[]}.
	 */
	public function import_media( $rel, $sha256, $mime, $binary ) {
		$request_error = $this->validate_media_request( $rel, $sha256, $binary );
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

		$abs      = $uploads['basedir'] . '/' . $rel;
		$existing = self::attachment_id_by_rel( $rel );

		if ( file_exists( $abs ) ) {
			if ( ! $this->is_same_import( $rel, $sha256, $abs ) ) {
				return new WP_Error( 'wpps_conflicting_file', __( 'A conflicting file occupies the fallback path.', 'wp-page-sync' ), array( 'status' => 409 ) );
			}
			$status = $existing ? 'reused' : 'adopted';
		} else {
			$write_error = $this->write_validated_file( $abs, $rel, $sha256, $binary );
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
			$this->regenerate_metadata( $attachment_id, $abs );
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
			if ( ! self::validate_rel_path( $rel ) || ! preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
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
	 * @param array $manifest Media manifest entries.
	 * @return array {map: array<string,string>, warnings: string[]}
	 */
	public function build_media_map( array $manifest ) {
		$uploads  = wp_upload_dir( null, false );
		$map      = array();
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
		}

		return array(
			'map'      => $map,
			'warnings' => $warnings,
		);
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

		$content = $rewrite( $post['content'] );
		$excerpt = $rewrite( $post['excerpt'] );
		$meta    = WPPS_Rewriter::apply_recursive( $this->validate_bundle_meta( $bundle ), $rewrite );

		$post_id = $this->upsert_post( $post, $content, $excerpt, $warnings );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->apply_meta( $post_id, $meta, $post['template'] );
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
		);
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
			&& (bool) preg_match( '~^[A-Za-z0-9%_\-]+(?:/[A-Za-z0-9%_\-]+){0,9}$~', $path );
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
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_.\-]{1,255}$/', $key ) || WPPS_Exporter::is_denied_meta_key( $key ) ) {
				continue;
			}
			$meta[ $key ] = $value;
		}
		return $meta;
	}

	/**
	 * Inserts or updates the post row (kses bracket per plan §4.3).
	 *
	 * @param array    $post     Normalized post fields.
	 * @param string   $content  Rewritten content.
	 * @param string   $excerpt  Rewritten excerpt.
	 * @param string[] $warnings Warning collector, by reference semantics via array return.
	 * @return int|WP_Error
	 */
	private function upsert_post( array $post, $content, $excerpt, array &$warnings ) {
		$parent_id = 0;
		if ( '' !== $post['parent_path'] ) {
			$parent = $this->exporter->find_post( $post['post_type'], $post['parent_path'] );
			if ( $parent ) {
				$parent_id = $parent->ID;
			} else {
				/* translators: %s: parent path. */
				$warnings[] = sprintf( __( 'Parent "%s" does not exist here; imported at the top level.', 'wp-page-sync' ), $post['parent_path'] );
			}
		}

		$postarr = array(
			'post_type'    => $post['post_type'],
			'post_name'    => $post['slug'],
			'post_title'   => $post['title'],
			'post_status'  => $post['status'],
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'menu_order'   => $post['menu_order'],
			'post_parent'  => $parent_id,
		);

		$existing = $this->exporter->find_post( $post['post_type'], $post['path'] );
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
		}

		/*
		 * The importer runs as user 0, where kses would strip inline <svg>
		 * from GenerateBlocks content. Same approach as WP's own importer;
		 * the writer is an HMAC-authenticated peer paired by an admin. The
		 * pre-update revision sits INSIDE the bracket on purpose: it writes
		 * the old content through wp_insert_post() as well, so with kses
		 * active the stored revision would lose the same inline SVG.
		 */
		kses_remove_filters();
		try {
			if ( $existing ) {
				$this->keep_previous_content( $existing );
			}
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		} finally {
			kses_init_filters();
		}

		return $post_id;
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
		if ( preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			$by_sha = self::attachment_id_by_meta( '_wpps_sha256', $sha );
			if ( $by_sha ) {
				return $by_sha;
			}
		}

		$by_rel = self::attachment_id_by_rel( $rel );
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
	 * @param string $binary Raw bytes.
	 * @return true|WP_Error
	 */
	private function validate_media_request( $rel, $sha256, $binary ) {
		if ( ! self::validate_rel_path( $rel ) ) {
			return new WP_Error( 'wpps_invalid_path', __( 'Invalid file path.', 'wp-page-sync' ), array( 'status' => 400 ) );
		}
		if ( ! self::allowed_extension( $rel ) ) {
			return new WP_Error( 'wpps_type_not_allowed', __( 'File type is not allowed.', 'wp-page-sync' ), array( 'status' => 415 ) );
		}
		if ( strlen( $binary ) > WPPS_MAX_FILE_BYTES ) {
			return new WP_Error( 'wpps_too_large', __( 'File exceeds the size limit.', 'wp-page-sync' ), array( 'status' => 413 ) );
		}
		if ( '' === $binary || hash( 'sha256', $binary ) !== $sha256 ) {
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
		$attachment_id = self::attachment_id_by_rel( $rel );
		return $attachment_id && get_post_meta( $attachment_id, '_wpps_sha256', true ) === $sha256;
	}

	/**
	 * Writes the binary through the §4.3 gates: containment, post-write
	 * sha256 verification, own mime map, SVG sniff + mandatory sanitizer,
	 * then an atomic same-dir rename.
	 *
	 * @param string $abs    Absolute target path.
	 * @param string $rel    Rel path (for the filename-based type check).
	 * @param string $sha256 Declared sha256 the written bytes must match.
	 * @param string $binary Raw bytes.
	 * @return true|WP_Error
	 */
	private function write_validated_file( $abs, $rel, $sha256, $binary ) {
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

		$tmp = $real_dir . '/.wpps-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct uploads write in a server-to-server import; WP_Filesystem adds credential prompts that cannot exist in REST context.
		$written = file_put_contents( $tmp, $binary, LOCK_EX );

		/*
		 * Plan §4.3: sha256 verified after write. Runs BEFORE the type check,
		 * because the SVG sanitizer deliberately rewrites the temp file.
		 */
		if ( false === $written || strlen( $binary ) !== $written || hash_file( 'sha256', $tmp ) !== $sha256 ) {
			if ( false !== $written ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
			}
			return new WP_Error( 'wpps_write_failed', __( 'Could not write the file (verification after write failed).', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		$type_error = $this->check_file_type( $tmp, basename( $rel ) );
		if ( is_wp_error( $type_error ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
			return $type_error;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory rename of our own temp file.
		if ( ! rename( $tmp, $abs ) ) {
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
			return new WP_Error( 'wpps_write_failed', __( 'Could not move the file into place.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Type gate with our OWN mime map (plan §4.3): core's default map blocks
	 * svg/woff2 for user 0, so the map is passed explicitly AND registered
	 * as a scoped upload_mimes filter for the duration of the check.
	 *
	 * @param string $file     Path of the written temp file.
	 * @param string $filename Real target file name.
	 * @return true|WP_Error
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
			return new WP_Error( 'wpps_type_mismatch', __( 'File content does not match its extension.', 'wp-page-sync' ), array( 'status' => 415 ) );
		}
		return true;
	}

	/**
	 * SVG rules (plan §4.3, hardened per security review): finfo may sniff
	 * SVG as text/plain, so accept only files starting with <?xml or <svg
	 * (unconditionally, whatever the core check said). The sanitizer pass is
	 * MANDATORY: without safe-svg's bundled sanitizer class the import is
	 * rejected, and an unsanitized SVG is never written.
	 *
	 * @param string $file Temp file path.
	 * @return true|WP_Error
	 */
	private function check_svg( $file ) {
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewriting our own temp file with the sanitized SVG.
		if ( false === file_put_contents( $file, $clean, LOCK_EX ) ) {
			return new WP_Error( 'wpps_write_failed', __( 'Could not write the sanitized SVG.', 'wp-page-sync' ), array( 'status' => 500 ) );
		}

		return true;
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
				'post_title'     => sanitize_text_field( pathinfo( $rel, PATHINFO_FILENAME ) ),
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
	 * @param int    $attachment_id Attachment ID.
	 * @param string $abs           Absolute file path.
	 * @return void
	 */
	private function regenerate_metadata( $attachment_id, $abs ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$metadata = wp_generate_attachment_metadata( $attachment_id, $abs );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}
}
