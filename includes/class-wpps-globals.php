<?php
/**
 * Global-styles export/import/hash.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Syncs GenerateBlocks Pro V2 Global Styles (CPT gblocks_styles) and the
 * GeneratePress global color palette (generate_settings[global_colors]),
 * including the receiver-side cache rebuilds (plan §6.6).
 */
class WPPS_Globals {

	const CPT = 'gblocks_styles';

	/**
	 * GB Pro enqueue class candidates. The installed GB Pro 2.4.0 names it
	 * GenerateBlocks_Pro_Enqueue_Styles (class-styles-enqueue.php:15); the
	 * second name is kept as a guard for other releases.
	 *
	 * @var string[]
	 */
	const GB_ENQUEUE_CLASSES = array( 'GenerateBlocks_Pro_Enqueue_Styles', 'GenerateBlocks_Pro_Styles_Enqueue' );

	/**
	 * Exporter (manifest builder, known hosts).
	 *
	 * @var WPPS_Exporter
	 */
	private $exporter;

	/**
	 * Importer (media map, kses-bracketed upserts).
	 *
	 * @var WPPS_Importer
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param WPPS_Exporter $exporter Exporter.
	 * @param WPPS_Importer $importer Importer.
	 */
	public function __construct( WPPS_Exporter $exporter, WPPS_Importer $importer ) {
		$this->exporter = $exporter;
		$this->importer = $importer;
	}

	/**
	 * All local gblocks_styles posts as bundle style entries.
	 *
	 * Identity = gb_style_selector when non-empty, else post_title (§6.6).
	 * gb_style_data is transferred complete: an incomplete data array makes
	 * the next editor save eat the compiled CSS.
	 *
	 * @return array[]
	 */
	public function export_styles() {
		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$styles = array();
		foreach ( $query->posts as $post ) {
			$selector = (string) get_post_meta( $post->ID, 'gb_style_selector', true );
			$data     = get_post_meta( $post->ID, 'gb_style_data', true );
			$styles[] = array(
				'identity'   => ( '' !== $selector ) ? $selector : $post->post_title,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'selector'   => $selector,
				'css'        => (string) get_post_meta( $post->ID, 'gb_style_css', true ),
				'data'       => is_array( $data ) ? $data : array(),
			);
		}

		return $styles;
	}

	/**
	 * Builds the globals bundle (plan §6.6): GET /globals response and
	 * POST /import-globals body. source is additive (rewrite host context).
	 *
	 * @return array
	 */
	public function export() {
		$styles = $this->export_styles();
		$colors = $this->global_colors();

		$strings = array();
		foreach ( $styles as $style ) {
			$strings[] = $style['css'];
			WPPS_Rewriter::collect_strings( $style['data'], $strings );
		}
		$manifest = $this->exporter->build_manifest( $strings );

		return array(
			'gb_styles'      => $styles,
			'gp_settings'    => array( 'global_colors' => $colors ),
			'media_manifest' => $manifest['manifest'],
			'hash'           => $this->hash(),
			'source'         => array(
				'home_url'    => home_url(),
				'uploads_url' => wp_upload_dir( null, false )['baseurl'],
			),
			'preflight'      => array(
				'errors'   => $manifest['errors'],
				'warnings' => $manifest['warnings'],
			),
		);
	}

	/**
	 * Globals hash for the status chip: sha256 over the normalized bundle,
	 * styles sorted by identity, env-host URLs normalized to relative. The
	 * media manifest is excluded (file equality is the media pipeline's
	 * concern), which also keeps the /status hash computation cheap.
	 *
	 * @return string
	 */
	public function hash() {
		$hosts     = $this->exporter->known_hosts();
		$normalize = function ( $value ) use ( $hosts ) {
			return WPPS_Rewriter::normalize( $value, $hosts );
		};

		$styles = array();
		foreach ( $this->export_styles() as $style ) {
			$styles[] = array(
				$style['identity'],
				$style['title'],
				$style['status'],
				$style['menu_order'],
				$style['selector'],
				$normalize( $style['css'] ),
				WPPS_Rewriter::apply_recursive( $style['data'], $normalize ),
			);
		}
		usort(
			$styles,
			function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);

		return hash( 'sha256', (string) wp_json_encode( array( $styles, $this->global_colors() ) ) );
	}

	/**
	 * The GeneratePress global color palette as stored.
	 *
	 * @return array[] Entries {name, slug, color}.
	 */
	public function global_colors() {
		$settings = get_option( 'generate_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['global_colors'] ) || ! is_array( $settings['global_colors'] ) ) {
			return array();
		}

		$colors = array();
		foreach ( $settings['global_colors'] as $color ) {
			if ( ! is_array( $color ) ) {
				continue;
			}
			$colors[] = array(
				'name'  => isset( $color['name'] ) ? (string) $color['name'] : '',
				'slug'  => isset( $color['slug'] ) ? (string) $color['slug'] : '',
				'color' => isset( $color['color'] ) ? (string) $color['color'] : '',
			);
		}
		return $colors;
	}

	/**
	 * Imports a globals bundle (plan §6.6): upsert by identity, whole-key
	 * palette replace, explicit cache rebuilds. Never deletes target-only
	 * styles; they are reported as only_on_target.
	 *
	 * @param array $bundle Globals bundle.
	 * @return array {created[], updated[], only_on_target[], warnings[], hash}
	 */
	public function import( array $bundle ) {
		$styles = ( isset( $bundle['gb_styles'] ) && is_array( $bundle['gb_styles'] ) ) ? $bundle['gb_styles'] : array();

		$source_host = isset( $bundle['source']['home_url'] ) ? WPPS_Rewriter::bare_host( (string) $bundle['source']['home_url'] ) : '';
		$hosts       = $this->exporter->foreign_hosts( '' !== $source_host ? array( $source_host ) : array() );
		$manifest    = ( isset( $bundle['media_manifest'] ) && is_array( $bundle['media_manifest'] ) ) ? $bundle['media_manifest'] : array();
		$media       = $this->importer->build_media_map( $manifest );
		$warnings    = $media['warnings'];

		$rewrite = function ( $value ) use ( $media, $hosts ) {
			$value = WPPS_Rewriter::rewrite_media_map( $value, $media['map'] );
			return WPPS_Rewriter::rewrite_env_hosts( $value, $hosts );
		};

		$existing = array();
		foreach ( $this->export_styles() as $style ) {
			$existing[ $style['identity'] ] = $style;
		}

		$created  = array();
		$updated  = array();
		$imported = array();
		foreach ( $styles as $style ) {
			$identity = $this->upsert_style( $style, $existing, $rewrite, $warnings );
			if ( '' === $identity ) {
				continue;
			}
			$imported[ $identity ] = true;
			if ( isset( $existing[ $identity ] ) ) {
				$updated[] = $identity;
			} else {
				$created[] = $identity;
			}
		}

		$only_on_target = array_values( array_diff( array_keys( $existing ), array_keys( $imported ) ) );

		$this->import_colors( $bundle );
		$this->rebuild_gb_css( $warnings );

		return array(
			'created'        => $created,
			'updated'        => $updated,
			'only_on_target' => $only_on_target,
			'warnings'       => $warnings,
			'hash'           => $this->hash(),
		);
	}

	/**
	 * Upserts one style post with all three meta fields complete.
	 *
	 * @param array    $style    Bundle style entry.
	 * @param array    $existing Local styles indexed by identity.
	 * @param callable $rewrite  URL rewrite callback.
	 * @param string[] $warnings Warning collector.
	 * @return string Identity, or "" when the entry is invalid.
	 */
	private function upsert_style( $style, array $existing, $rewrite, array &$warnings ) {
		if ( ! is_array( $style ) ) {
			return '';
		}
		$identity = isset( $style['identity'] ) ? sanitize_text_field( (string) $style['identity'] ) : '';
		if ( '' === $identity ) {
			return '';
		}

		$status = isset( $style['status'] ) ? (string) $style['status'] : 'publish';
		if ( ! in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
			$status = 'publish';
		}

		$data    = ( isset( $style['data'] ) && is_array( $style['data'] ) ) ? $style['data'] : array();
		$postarr = array(
			'post_type'   => self::CPT,
			'post_title'  => isset( $style['title'] ) ? sanitize_text_field( (string) $style['title'] ) : $identity,
			'post_status' => $status,
			'menu_order'  => isset( $style['menu_order'] ) ? (int) $style['menu_order'] : 0,
		);

		$existing_id = 0;
		if ( isset( $existing[ $identity ] ) ) {
			$existing_id   = $this->style_post_id( $identity );
			$postarr['ID'] = $existing_id;
		}
		if ( isset( $existing[ $identity ] ) && ! $existing_id ) {
			/* translators: %s: style identity (selector or title). */
			$warnings[] = sprintf( __( 'Global style %s exists here but its post could not be located, so it was not updated.', 'wp-page-sync' ), $identity );
			return '';
		}

		kses_remove_filters();
		try {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		} finally {
			kses_init_filters();
		}

		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: style identity, 2: error message. */
			$warnings[] = sprintf( __( 'Global style %1$s failed to save: %2$s', 'wp-page-sync' ), $identity, $post_id->get_error_message() );
			return '';
		}

		update_post_meta( $post_id, 'gb_style_selector', wp_slash( isset( $style['selector'] ) ? sanitize_text_field( (string) $style['selector'] ) : '' ) );
		update_post_meta( $post_id, 'gb_style_css', wp_slash( call_user_func( $rewrite, isset( $style['css'] ) ? (string) $style['css'] : '' ) ) );
		update_post_meta( $post_id, 'gb_style_data', wp_slash( WPPS_Rewriter::apply_recursive( $data, $rewrite ) ) );

		return $identity;
	}

	/**
	 * Post ID of a local style by identity.
	 *
	 * @param string $identity Style identity.
	 * @return int
	 */
	private function style_post_id( $identity ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- selector meta is the style identity; bounded to one row on a ~50-post CPT.
				'meta_query'     => array(
					array(
						'key'   => 'gb_style_selector',
						'value' => $identity,
					),
				),
			)
		);
		if ( $query->posts ) {
			return (int) $query->posts[0];
		}

		$fallback = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'title'          => $identity,
			)
		);
		return $fallback->posts ? (int) $fallback->posts[0] : 0;
	}

	/**
	 * Replaces the palette as a whole key and resets the GP dynamic CSS
	 * cache (the theme's own reset path, dashboard.php:338-339).
	 *
	 * Skipped entirely when the bundle carries no global_colors key, so a
	 * bundle from a site without GeneratePress never wipes the palette.
	 *
	 * @param array $bundle Globals bundle.
	 * @return void
	 */
	private function import_colors( array $bundle ) {
		if ( ! isset( $bundle['gp_settings']['global_colors'] ) || ! is_array( $bundle['gp_settings']['global_colors'] ) ) {
			return;
		}

		$colors = array();
		foreach ( $bundle['gp_settings']['global_colors'] as $color ) {
			if ( ! is_array( $color ) ) {
				continue;
			}
			$colors[] = array(
				'name'  => sanitize_text_field( isset( $color['name'] ) ? (string) $color['name'] : '' ),
				'slug'  => sanitize_key( isset( $color['slug'] ) ? (string) $color['slug'] : '' ),
				'color' => sanitize_text_field( isset( $color['color'] ) ? (string) $color['color'] : '' ),
			);
		}

		$settings = get_option( 'generate_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['global_colors'] = $colors;
		update_option( 'generate_settings', $settings );

		delete_option( 'generate_dynamic_css_output' );
		delete_option( 'generate_dynamic_css_cached_version' );
	}

	/**
	 * Explicit GB Pro CSS cache rebuild (plan §6.6): GB's own save hook
	 * early-returns for user 0 (current_user_can('edit_post'),
	 * class-styles-enqueue.php:271), so it never fires during REST imports.
	 *
	 * @param string[] $warnings Warning collector.
	 * @return void
	 */
	private function rebuild_gb_css( array &$warnings ) {
		foreach ( self::GB_ENQUEUE_CLASSES as $class_name ) {
			if ( ! class_exists( $class_name )
				|| ! method_exists( $class_name, 'get_instance' )
				|| ! method_exists( $class_name, 'build_css' ) ) {
				continue;
			}
			try {
				call_user_func( array( $class_name, 'get_instance' ) )->build_css();
			} catch ( \Throwable $throwable ) {
				/* translators: %s: error message. */
				$warnings[] = sprintf( __( 'GenerateBlocks CSS rebuild failed: %s', 'wp-page-sync' ), $throwable->getMessage() );
			}
			return;
		}

		$warnings[] = __( 'GenerateBlocks Pro is not active here; the global styles CSS cache was not rebuilt.', 'wp-page-sync' );
	}
}
