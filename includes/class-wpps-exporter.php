<?php
/**
 * Post bundle builder and media manifest.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds export bundles (plan §5), the media manifest with sha256 + sizes
 * (plan §6.2), post lists for /posts, and the content hash (plan §6.4).
 */
class WPPS_Exporter {

	const SYNCABLE_POST_TYPES = array( 'page', 'post' );
	const SYNCABLE_STATUSES   = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Taxonomies whose term assignments travel with a post (by slug).
	 *
	 * @var string[]
	 */
	const SYNCABLE_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Meta keys never exported (plan §5). _thumbnail_id → featured,
	 * _wp_page_template → post.template; the two GenerateBlocks keys are
	 * post-ID-bound on the source site.
	 *
	 * @var string[]
	 */
	const META_DENY_EXACT = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_thumbnail_id',
		'_wp_page_template',
		'_generateblocks_dynamic_css_version',
		'_generateblocks_reusable_blocks',
	);

	/**
	 * Denied meta key prefixes (_oembed_ rows are host-bound response caches).
	 *
	 * @var string[]
	 */
	const META_DENY_PREFIX = array( '_wp_attachment_', '_wpps_', '_oembed_' );

	/**
	 * Peer store (known-host derivation).
	 *
	 * @var WPPS_Peers
	 */
	private $peers;

	/**
	 * Constructor.
	 *
	 * @param WPPS_Peers $peers Peer store.
	 */
	public function __construct( WPPS_Peers $peers ) {
		$this->peers = $peers;
	}

	/**
	 * All known environment hosts: own host, every peer's host, localhost.
	 *
	 * @param string[] $extra Additional bare hosts (e.g. a bundle's source host).
	 * @return string[] Unique bare hosts.
	 */
	public function known_hosts( array $extra = array() ) {
		$hosts = array( WPPS_Rewriter::bare_host( home_url() ), 'localhost', '127.0.0.1' );
		foreach ( $this->peers->get_peers() as $peer ) {
			$hosts[] = WPPS_Rewriter::bare_host( (string) $peer['url'] );
		}
		foreach ( $extra as $host ) {
			$hosts[] = preg_replace( '/^www\./i', '', strtolower( (string) $host ) );
		}
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	/**
	 * Known env hosts EXCLUDING this site's own host.
	 *
	 * The receiver-side rewrite/scan passes use this set (ported link-guard
	 * semantics: the target host is excluded, so URLs already pointing at
	 * this site are the correct final state, not a violation).
	 *
	 * @param string[] $extra Additional bare hosts.
	 * @return string[]
	 */
	public function foreign_hosts( array $extra = array() ) {
		$own = WPPS_Rewriter::bare_host( home_url() );
		return array_values( array_diff( $this->known_hosts( $extra ), array( $own ) ) );
	}

	/**
	 * Finds a post by hierarchical path, validating type and skipping trash.
	 *
	 * Array post type on purpose: a string post type makes get_page_by_path()
	 * silently include attachments (plan §6.1).
	 *
	 * @param string $post_type Post type.
	 * @param string $path      Hierarchical path.
	 * @return WP_Post|null
	 */
	public function find_post( $post_type, $path ) {
		if ( '' === $path ) {
			return null;
		}
		$post = get_page_by_path( $path, OBJECT, array( $post_type ) );
		if ( ! $post || $post->post_type !== $post_type || 'trash' === $post->post_status ) {
			return null;
		}
		return $post;
	}

	/**
	 * Content hash (plan §6.4): sha256 over title|path|status|normalized
	 * content|normalized excerpt. pending counts as draft so hashes stay
	 * stable across the pending→draft import rule. Attachment IDs in media
	 * blocks are neutralized: the import remaps them to the receiving site's
	 * own IDs, so they differ between two otherwise identical copies.
	 *
	 * @param string $title   Post title.
	 * @param string $path    Hierarchical path.
	 * @param string $status  Post status.
	 * @param string $content Raw content.
	 * @param string $excerpt Raw excerpt.
	 * @return string
	 */
	public function hash_fields( $title, $path, $status, $content, $excerpt ) {
		$hosts  = $this->known_hosts();
		$status = ( 'pending' === $status ) ? 'draft' : $status;

		return hash(
			'sha256',
			implode(
				"\n",
				array(
					(string) $title,
					(string) $path,
					(string) $status,
					WPPS_Rewriter::normalize( WPPS_Rewriter::neutralize_attachment_ids( $content ), $hosts ),
					WPPS_Rewriter::normalize( (string) $excerpt, $hosts ),
				)
			)
		);
	}

	/**
	 * Content hash of a post object.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function content_hash( WP_Post $post ) {
		return $this->hash_fields(
			$post->post_title,
			(string) get_page_uri( $post ),
			$post->post_status,
			$post->post_content,
			$post->post_excerpt
		);
	}

	/**
	 * Lists syncable posts for the /posts route and the Sync tab.
	 *
	 * @param string $post_type Post type.
	 * @param int    $page      1-based page; 0 = all posts in one list.
	 * @param int    $per_page  Page size (ignored when $page is 0).
	 * @return array {items: array[], total: int}
	 */
	public function list_posts( $post_type, $page = 0, $per_page = 100 ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => self::SYNCABLE_STATUSES,
				'posts_per_page' => ( $page > 0 ) ? $per_page : -1,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$path    = ( '' !== $post->post_name ) ? (string) get_page_uri( $post ) : '';
			$items[] = array(
				'id'           => $post->ID,
				'post_type'    => $post->post_type,
				'path'         => $path,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'modified_gmt' => $post->post_modified_gmt,
				'hash'         => $this->content_hash( $post ),
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Exported (denylist-filtered, unserialized) meta of a post.
	 *
	 * Multi-value meta keys export their first value only (documented
	 * prototype limitation; the synced plugins store single values).
	 *
	 * @param int $post_id Post ID.
	 * @return array key => value.
	 */
	public function export_meta( $post_id ) {
		$meta = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( self::is_denied_meta_key( $key ) || empty( $values ) ) {
				continue;
			}
			$meta[ $key ] = maybe_unserialize( $values[0] );
		}
		return $meta;
	}

	/**
	 * Whether a meta key is excluded from sync.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_denied_meta_key( $key ) {
		if ( in_array( $key, self::META_DENY_EXACT, true ) ) {
			return true;
		}
		foreach ( self::META_DENY_PREFIX as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the full export bundle for one post (plan §5).
	 *
	 * The bundle carries two additive keys beyond the documented shape:
	 * source.post_id + source.hash (state bookkeeping) and preflight
	 * {errors, warnings} (preview surface); /import-post ignores preflight.
	 *
	 * @param string $post_type Post type.
	 * @param string $path      Hierarchical path.
	 * @return array|WP_Error Bundle, or not-found error.
	 */
	public function export( $post_type, $path ) {
		$post = $this->find_post( $post_type, $path );
		if ( ! $post ) {
			return new WP_Error( 'wpps_not_found', __( 'Post not found for this path.', 'wp-page-sync' ), array( 'status' => 404 ) );
		}

		$warnings = array();
		$status   = $post->post_status;
		if ( 'pending' === $status ) {
			$status     = 'draft';
			$warnings[] = __( 'Status "pending" is synced as "draft" (a pending import would lose its slug).', 'wp-page-sync' );
		}

		$meta         = $this->export_meta( $post->ID );
		$meta_strings = array();
		WPPS_Rewriter::collect_strings( $meta, $meta_strings );

		$featured = $this->export_featured( $post->ID );
		$manifest = $this->build_manifest(
			array_merge( array( $post->post_content, $post->post_excerpt ), $meta_strings ),
			$featured ? array( $featured['rel_path'] ) : array()
		);

		$parent_path = $post->post_parent ? (string) get_page_uri( $post->post_parent ) : '';

		return array(
			'post'           => array(
				'post_type'    => $post->post_type,
				'path'         => $path,
				'slug'         => $post->post_name,
				'title'        => $post->post_title,
				'status'       => $status,
				'content'      => $post->post_content,
				'excerpt'      => $post->post_excerpt,
				'menu_order'   => (int) $post->menu_order,
				'parent_path'  => $parent_path,
				'template'     => (string) get_post_meta( $post->ID, '_wp_page_template', true ),
				'modified_gmt' => $post->post_modified_gmt,
				'date'         => $post->post_date,
				'date_gmt'     => $post->post_date_gmt,
			),
			'meta'           => $meta,
			'terms'          => $this->export_terms( $post ),
			'featured'       => $featured,
			'media_manifest' => $manifest['manifest'],
			'source'         => array(
				'home_url'    => home_url(),
				'uploads_url' => wp_upload_dir( null, false )['baseurl'],
				'post_id'     => $post->ID,
				'hash'        => $this->content_hash( $post ),
			),
			'preflight'      => array(
				'errors'   => $manifest['errors'],
				'warnings' => array_merge( $warnings, $manifest['warnings'] ),
			),
		);
	}

	/**
	 * Term assignments of a post, per syncable taxonomy of its post type.
	 *
	 * Every syncable taxonomy of the post type is present, an empty list
	 * included, so the receiver can tell "no terms" from "not sent".
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, array[]> taxonomy => list of {slug, name}.
	 */
	private function export_terms( WP_Post $post ) {
		$terms = array();
		foreach ( array_intersect( self::SYNCABLE_TAXONOMIES, get_object_taxonomies( $post->post_type ) ) as $taxonomy ) {
			$assigned = get_the_terms( $post, $taxonomy );
			$list     = array();
			foreach ( is_array( $assigned ) ? $assigned : array() as $term ) {
				$list[] = array(
					'slug' => $term->slug,
					'name' => $term->name,
				);
			}
			$terms[ $taxonomy ] = $list;
		}
		return $terms;
	}

	/**
	 * Featured-image descriptor for the bundle.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null {rel_path, sha256} or null.
	 */
	private function export_featured( $post_id ) {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb_id ) {
			return null;
		}
		$rel = (string) get_post_meta( $thumb_id, '_wp_attached_file', true );
		$abs = wp_upload_dir( null, false )['basedir'] . '/' . $rel;
		if ( '' === $rel || ! file_exists( $abs ) ) {
			return null;
		}
		return array(
			'rel_path' => $rel,
			'sha256'   => hash_file( 'sha256', $abs ),
		);
	}

	/**
	 * Builds the media manifest from content/meta strings (plan §6.2).
	 *
	 * Every uploads reference (images in all forms AND linked files like
	 * PDFs) resolves to a local original file; referenced-but-missing files
	 * become pre-flight errors instead of silently breaking on the target.
	 *
	 * Files whose rel path fails the §4.3 transfer rules (umlauts, foreign
	 * extensions) are excluded with a preflight WARNING instead of entering
	 * the manifest: they would hard-abort the whole push at /media-upload.
	 * The receiver-side scan pass still warns about their URLs.
	 *
	 * @param string[] $strings    Strings to sweep.
	 * @param string[] $extra_rels Additional rel paths (featured image).
	 * @return array {manifest: array[], errors: string[], warnings: string[]}
	 */
	public function build_manifest( array $strings, array $extra_rels = array() ) {
		$uploads = wp_upload_dir( null, false );
		$hosts   = $this->known_hosts();

		$rels     = array();
		$errors   = array();
		$warnings = array();
		foreach ( $strings as $subject ) {
			foreach ( WPPS_Rewriter::extract_uploads_refs( $subject, $hosts ) as $ref ) {
				$resolved = $this->resolve_original( $ref['rel'], $uploads['basedir'] );
				if ( null === $resolved ) {
					/* translators: %s: referenced file URL. */
					$errors[ $ref['rel'] ] = sprintf( __( 'Referenced upload file is missing on this site: %s', 'wp-page-sync' ), $ref['path'] );
					continue;
				}
				if ( ! $this->transferable_rel( $resolved, $warnings ) ) {
					continue;
				}
				$rels[ $resolved ] = true;
			}
		}
		foreach ( $extra_rels as $rel ) {
			if ( $this->transferable_rel( $rel, $warnings ) ) {
				$rels[ $rel ] = true;
			}
		}

		$manifest = array();
		foreach ( array_keys( $rels ) as $rel ) {
			$manifest[] = $this->manifest_entry( $rel, $uploads );
		}

		return array(
			'manifest' => $manifest,
			'errors'   => array_values( $errors ),
			'warnings' => array_values( $warnings ),
		);
	}

	/**
	 * Whether a rel path may enter the manifest; records a warning if not.
	 *
	 * @param string $rel      Rel path of an existing file.
	 * @param array  $warnings Warning collector, keyed by rel path.
	 * @return bool
	 */
	private function transferable_rel( $rel, array &$warnings ) {
		if ( WPPS_Importer::validate_rel_path( $rel ) && WPPS_Importer::allowed_extension( $rel ) ) {
			return true;
		}
		/* translators: %s: uploads-relative file path. */
		$warnings[ $rel ] = sprintf( __( 'File is not transferable (path characters or file type outside the sync whitelist) and stays untouched: %s', 'wp-page-sync' ), $rel );
		return false;
	}

	/**
	 * Resolves a referenced rel path to the original file's rel path.
	 *
	 * Size suffixes (-300x200) are stripped to find the original; when only
	 * the suffixed file exists on disk it is treated as its own original.
	 *
	 * @param string $rel     Referenced rel path.
	 * @param string $basedir Uploads base dir.
	 * @return string|null Rel path of an existing file, or null.
	 */
	private function resolve_original( $rel, $basedir ) {
		$original = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $rel );
		if ( file_exists( $basedir . '/' . $original ) ) {
			return $original;
		}
		if ( $original !== $rel && file_exists( $basedir . '/' . $rel ) ) {
			return $rel;
		}
		return null;
	}

	/**
	 * Builds one manifest entry for an existing uploads file.
	 *
	 * The sizes map is keyed by WordPress size NAME (thumbnail, medium, …)
	 * so the receiver can map source size URLs onto its own regenerated
	 * size files even when the size dimensions differ between sites.
	 *
	 * @param string $rel     Rel path of the original file.
	 * @param array  $uploads wp_upload_dir() array.
	 * @return array Manifest entry.
	 */
	private function manifest_entry( $rel, array $uploads ) {
		$abs    = $uploads['basedir'] . '/' . $rel;
		$att_id = WPPS_Importer::attachment_id_by_rel( $rel );

		$mime = '';
		$size = array();
		if ( $att_id ) {
			$mime = (string) get_post_mime_type( $att_id );
			$meta = wp_get_attachment_metadata( $att_id );
			foreach ( ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) ? $meta['sizes'] : array() as $name => $info ) {
				if ( ! empty( $info['file'] ) ) {
					$size[ $name ] = (string) $info['file'];
				}
			}
		}
		if ( '' === $mime ) {
			$filetype = wp_check_filetype( basename( $rel ) );
			$mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';
		}

		return array(
			'source_url'    => $uploads['baseurl'] . '/' . $rel,
			'rel_path'      => $rel,
			'sha256'        => hash_file( 'sha256', $abs ),
			'mime'          => $mime,
			'size'          => (int) filesize( $abs ),
			'sizes'         => $size,
			'attachment_id' => $att_id,
		);
	}
}
