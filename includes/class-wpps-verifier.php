<?php
/**
 * Site verification scans.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans pages and posts (plan §7): foreign env URLs, missing upload files,
 * broken internal links, undefined global classes (§6.7), plus the
 * use_balanceTags import-correctness flag. Batched via offset/limit so
 * remote runs stay under max_execution_time.
 */
class WPPS_Verifier {

	/**
	 * Class namespaces WordPress core emits in content (has-medium-font-size,
	 * is-style-*, wp-block-*). Custom global classes may share these prefixes
	 * (e.g. .has-gradient-bg on this site), but deriving them as global-class
	 * prefixes (§6.7) would flag every core preset class as undefined.
	 *
	 * @var string[]
	 */
	const CORE_CLASS_PREFIXES = array( 'has-', 'is-', 'wp-' );

	/**
	 * Exporter (known hosts, meta export, path lookup).
	 *
	 * @var WPPS_Exporter
	 */
	private $exporter;

	/**
	 * Globals (defined-class set).
	 *
	 * @var WPPS_Globals
	 */
	private $globals;

	/**
	 * Constructor.
	 *
	 * @param WPPS_Exporter $exporter Exporter.
	 * @param WPPS_Globals  $globals  Globals service.
	 */
	public function __construct( WPPS_Exporter $exporter, WPPS_Globals $globals ) {
		$this->exporter = $exporter;
		$this->globals  = $globals;
	}

	/**
	 * Runs one verification batch.
	 *
	 * @param int $offset Post offset (ID-ordered).
	 * @param int $limit  Batch size.
	 * @return array {errors[], warnings[], counts, scanned, total, offset}
	 */
	public function run( $offset = 0, $limit = 10 ) {
		$query = new WP_Query(
			array(
				'post_type'      => WPPS_Exporter::SYNCABLE_POST_TYPES,
				'post_status'    => WPPS_Exporter::SYNCABLE_STATUSES,
				'posts_per_page' => max( 1, (int) $limit ),
				'offset'         => max( 0, (int) $offset ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$context  = $this->build_context();
		$errors   = array();
		$warnings = array();

		foreach ( $query->posts as $post ) {
			$this->verify_post( $post, $context, $errors, $warnings );
		}

		if ( 0 === (int) $offset && 1 === (int) get_option( 'use_balanceTags' ) ) {
			$warnings[] = array(
				'type'    => 'balance_tags',
				'post_id' => 0,
				'path'    => '',
				'detail'  => 'use_balanceTags=1',
				'message' => __( 'The use_balanceTags option is enabled on this site. WordPress then rewrites the markup of every post it saves, which can corrupt imported block content. Turn it off before syncing.', 'wp-page-sync' ),
			);
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'counts'   => array(
				'errors'   => count( $errors ),
				'warnings' => count( $warnings ),
			),
			'scanned'  => count( $query->posts ),
			'total'    => (int) $query->found_posts,
			'offset'   => (int) $offset,
		);
	}

	/**
	 * Precomputes the per-run scan context.
	 *
	 * @return array
	 */
	private function build_context() {
		$own_host = WPPS_Rewriter::bare_host( home_url() );
		$hosts    = $this->exporter->known_hosts();

		$defined  = array();
		$prefixes = array();
		foreach ( $this->globals->export_styles() as $style ) {
			$class_name = ltrim( $style['identity'], '.' );
			if ( '' === $class_name ) {
				continue;
			}
			$defined[ $class_name ] = true;
			$dash                   = strpos( $class_name, '-' );
			if ( false !== $dash ) {
				$prefix = substr( $class_name, 0, $dash + 1 );
				if ( ! in_array( $prefix, self::CORE_CLASS_PREFIXES, true ) ) {
					$prefixes[ $prefix ] = true;
				}
			}
		}

		$class_regex = '';
		if ( $prefixes ) {
			$quoted      = array_map(
				function ( $prefix ) {
					return preg_quote( $prefix, '/' );
				},
				array_keys( $prefixes )
			);
			$class_regex = '/(?<![A-Za-z0-9_-])(?:' . implode( '|', $quoted ) . ')[A-Za-z0-9_-]+/';
		}

		return array(
			'own_host'      => $own_host,
			'all_hosts'     => $hosts,
			'foreign_hosts' => $this->exporter->foreign_hosts(),
			'basedir'       => wp_upload_dir( null, false )['basedir'],
			'defined'       => $defined,
			'class_regex'   => $class_regex,
		);
	}

	/**
	 * Runs all checks for one post.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $context  Scan context.
	 * @param array   $errors   Error collector.
	 * @param array   $warnings Warning collector.
	 * @return void
	 */
	private function verify_post( $post, array $context, array &$errors, array &$warnings ) {
		$path    = (string) get_page_uri( $post );
		$strings = array( 'content' => $post->post_content );
		if ( '' !== $post->post_excerpt ) {
			$strings['excerpt'] = $post->post_excerpt;
		}
		foreach ( $this->exporter->export_meta( $post->ID ) as $key => $value ) {
			$collected = array();
			WPPS_Rewriter::collect_strings( $value, $collected );
			if ( $collected ) {
				$strings[ 'meta:' . $key ] = implode( "\n", $collected );
			}
		}

		foreach ( $strings as $location => $subject ) {
			$this->check_foreign_urls( $post, $path, $location, $subject, $context, $errors );
			$this->check_missing_files( $post, $path, $location, $subject, $context, $errors );
		}

		$this->check_internal_links( $post, $path, $context, $warnings );
		$this->check_global_classes( $post, $path, $context, $errors );
	}

	/**
	 * Check 1: content pointing at a foreign environment host (plan §7.1).
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $path     Post path.
	 * @param string  $location Location label.
	 * @param string  $subject  String to scan.
	 * @param array   $context  Scan context.
	 * @param array   $errors   Error collector.
	 * @return void
	 */
	private function check_foreign_urls( $post, $path, $location, $subject, array $context, array &$errors ) {
		$seen = array();
		foreach ( WPPS_Rewriter::scan_env_hosts( $subject, $context['foreign_hosts'] ) as $violation ) {
			$detail = $violation['host'] . $violation['path'];
			if ( isset( $seen[ $detail ] ) ) {
				continue;
			}
			$seen[ $detail ] = true;
			$errors[]        = array(
				'type'     => 'foreign_url',
				'post_id'  => $post->ID,
				'path'     => $path,
				'location' => $location,
				'detail'   => $detail,
				'message'  => __( 'URL points at a foreign environment host.', 'wp-page-sync' ),
			);
		}
	}

	/**
	 * Check 2: referenced upload files missing on disk (plan §7.2).
	 * Size-suffixed URLs check the size file, falling back to the original.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $path     Post path.
	 * @param string  $location Location label.
	 * @param string  $subject  String to scan.
	 * @param array   $context  Scan context.
	 * @param array   $errors   Error collector.
	 * @return void
	 */
	private function check_missing_files( $post, $path, $location, $subject, array $context, array &$errors ) {
		$seen = array();
		foreach ( WPPS_Rewriter::extract_uploads_refs( $subject, $context['all_hosts'] ) as $ref ) {
			if ( isset( $seen[ $ref['rel'] ] ) ) {
				continue;
			}
			$seen[ $ref['rel'] ] = true;

			if ( file_exists( $context['basedir'] . '/' . $ref['rel'] ) ) {
				continue;
			}
			$original = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $ref['rel'] );
			if ( $original !== $ref['rel'] && file_exists( $context['basedir'] . '/' . $original ) ) {
				continue;
			}

			$errors[] = array(
				'type'     => 'missing_file',
				'post_id'  => $post->ID,
				'path'     => $path,
				'location' => $location,
				'detail'   => $ref['path'],
				'message'  => __( 'Referenced upload file does not exist on this site.', 'wp-page-sync' ),
			);
		}
	}

	/**
	 * Check 3: relative or own-host links that resolve to no post (§7.3).
	 * Warning-level: archive/taxonomy URLs are legitimate but unresolvable
	 * by path lookup.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $path     Post path.
	 * @param array   $context  Scan context.
	 * @param array   $warnings Warning collector.
	 * @return void
	 */
	private function check_internal_links( $post, $path, array $context, array &$warnings ) {
		if ( ! preg_match_all( '/href=(["\'])([^"\']*)\1/i', $post->post_content, $matches ) ) {
			return;
		}

		$seen = array();
		foreach ( $matches[2] as $href ) {
			$target = $this->internal_link_path( $href, $context['own_host'] );
			if ( null === $target || '' === $target || isset( $seen[ $target ] ) ) {
				continue;
			}
			$seen[ $target ] = true;

			if ( get_page_by_path( $target, OBJECT, WPPS_Exporter::SYNCABLE_POST_TYPES ) ) {
				continue;
			}

			$warnings[] = array(
				'type'     => 'broken_link',
				'post_id'  => $post->ID,
				'path'     => $path,
				'location' => 'content',
				'detail'   => $href,
				'message'  => __( 'Internal link resolves to no page or post.', 'wp-page-sync' ),
			);
		}
	}

	/**
	 * Extracts the lookup path of an internal href, or null for links the
	 * check does not cover (external, anchors, uploads, wp paths).
	 *
	 * @param string $href     Href value.
	 * @param string $own_host Own bare host.
	 * @return string|null
	 */
	private function internal_link_path( $href, $own_host ) {
		$href = html_entity_decode( (string) $href, ENT_QUOTES );
		if ( '' === $href || preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
			return null;
		}

		if ( preg_match( '~^(?:https?:)?//~i', $href ) ) {
			if ( WPPS_Rewriter::bare_host( preg_match( '~^//~', $href ) ? 'http:' . $href : $href ) !== $own_host ) {
				return null;
			}
			$href = (string) wp_parse_url( $href, PHP_URL_PATH );
		}
		if ( '' === $href || '/' !== $href[0] ) {
			return null;
		}

		$href = preg_split( '/[?#]/', $href )[0];
		if ( 0 === strpos( $href, '/wp-content/' ) || 0 === strpos( $href, '/wp-admin/' ) || 0 === strpos( $href, '/wp-json/' ) ) {
			return null;
		}

		return rawurldecode( trim( $href, '/' ) );
	}

	/**
	 * Check 4: class tokens matching a known global-class prefix but not
	 * defined in gblocks_styles (plan §6.7).
	 *
	 * Scans class CONTEXTS only (class attributes, className/globalClasses
	 * block JSON), not the whole content blob: a full-blob scan flags CSS
	 * values (no-repeat), file names and prose as undefined classes.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $path    Post path.
	 * @param array   $context Scan context.
	 * @param array   $errors  Error collector.
	 * @return void
	 */
	private function check_global_classes( $post, $path, array $context, array &$errors ) {
		if ( '' === $context['class_regex'] ) {
			return;
		}
		$corpus = $this->class_corpus( $post->post_content );
		if ( '' === $corpus || ! preg_match_all( $context['class_regex'], $corpus, $matches ) ) {
			return;
		}

		foreach ( array_unique( $matches[0] ) as $class_name ) {
			if ( isset( $context['defined'][ $class_name ] ) ) {
				continue;
			}
			$errors[] = array(
				'type'     => 'undefined_class',
				'post_id'  => $post->ID,
				'path'     => $path,
				'location' => 'content',
				'detail'   => $class_name,
				'message'  => __( 'Global class is used but not defined on this site.', 'wp-page-sync' ),
			);
		}
	}

	/**
	 * Collects the class-context strings of a content blob: HTML class
	 * attributes plus className/globalClasses values from block JSON.
	 * GenerateBlocks encodes a double dash as two backslash-u002d escape
	 * sequences inside block comments (gbp-button--primary), so that JSON
	 * escape is decoded before token matching.
	 *
	 * @param string $content Stored post content.
	 * @return string Space-joined class corpus.
	 */
	private function class_corpus( $content ) {
		$corpus = array();
		if ( preg_match_all( '/\bclass=(["\'])(.*?)\1/i', $content, $attr_matches ) ) {
			$corpus = $attr_matches[2];
		}
		if ( preg_match_all( '/"(?:className|globalClasses)":(?:"((?:[^"\\\\]|\\\\.)*)"|\[([^\]]*)\])/', $content, $json_matches, PREG_SET_ORDER ) ) {
			foreach ( $json_matches as $set ) {
				$corpus[] = ( isset( $set[2] ) && '' !== $set[2] ) ? $set[2] : $set[1];
			}
		}

		return str_replace( '\u002d', '-', implode( ' ', $corpus ) );
	}
}
