<?php
/**
 * Navigation sync unit: menus, mega-menu overlays and the GP block
 * elements that render them (1.7.0).
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports, hashes and imports the "Navigation" unit (plan §6.8).
 *
 * The unit is derived, not configured: GP block elements whose content
 * holds a GenerateBlocks Pro classic-menu block, the nav menus those blocks
 * show, and the overlays the menus' items open as mega menus. Pages are not
 * part of it; menu items and element rules reference them by path.
 *
 * Cross-site identity: menus by term slug, overlays and elements by
 * post_name, pages by path. Menu items have none: a synced menu is
 * replaced as a whole (positional upsert, leftovers deleted). Every bundle
 * value is site-neutral, so both sides hash the same structure.
 */
class WPPS_Navigation {

	const OVERLAY_TYPE = 'gblocks_overlay';
	const ELEMENT_TYPE = 'gp_elements';

	/**
	 * Menu item types the unit carries (taxonomy items are out of scope).
	 *
	 * @var string[]
	 */
	const ITEM_TYPES = array( 'post_type', 'custom', 'post_type_archive' );

	/**
	 * Element meta holding GP display rules ({rule, object} lists).
	 *
	 * @var string[]
	 */
	const CONDITION_KEYS = array( '_generate_element_display_conditions', '_generate_element_exclude_conditions' );

	/**
	 * A classic-menu block comment; group 2 is its attribute JSON.
	 *
	 * @var string
	 */
	const MENU_BLOCK_PATTERN = '~(<!--\s+wp:generateblocks-pro/classic-menu\s+)(\{.*?\})(\s+/?-->)~s';

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
	 * Constructor.
	 *
	 * @param WPPS_Exporter $exporter Exporter.
	 * @param WPPS_Importer $importer Importer.
	 */
	public function __construct( WPPS_Exporter $exporter, WPPS_Importer $importer ) {
		$this->exporter = $exporter;
		$this->importer = $importer;
	}

	/* ------------------------------------------------------------------ *
	 * Collection (shared by export, hash and the verifier)
	 * ------------------------------------------------------------------ */

	/**
	 * GP block elements that render a classic menu, ordered by slug.
	 *
	 * @return WP_Post[]
	 */
	public function nav_elements() {
		$posts = get_posts(
			array(
				'post_type'   => self::ELEMENT_TYPE,
				// Only published unit posts travel (security review L5).
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'name',
				'order'       => 'ASC',
				'meta_key'    => '_generate_element_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- small post type, one query per status load.
				'meta_value'  => 'block', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- see above.
			)
		);
		return array_values(
			array_filter(
				$posts,
				function ( $post ) {
					return false !== strpos( $post->post_content, 'wp:generateblocks-pro/classic-menu' );
				}
			)
		);
	}

	/**
	 * Applies a callback to the "menu" attribute of every classic-menu block,
	 * in one pass (so a mapped value is never mapped again).
	 *
	 * @param string   $content Block content.
	 * @param callable $map     Receives the stored value, returns the new one.
	 * @return string
	 */
	public static function map_menu_attrs( $content, callable $map ) {
		$result = preg_replace_callback(
			self::MENU_BLOCK_PATTERN,
			function ( $block ) use ( $map ) {
				$json = preg_replace_callback(
					'/"menu":"([^"\\\\]+)"/',
					function ( $attr ) use ( $map ) {
						return '"menu":"' . call_user_func( $map, $attr[1] ) . '"';
					},
					$block[2],
					1
				);
				return $block[1] . ( null === $json ? $block[2] : $json ) . $block[3];
			},
			(string) $content
		);
		return null === $result ? (string) $content : $result;
	}

	/**
	 * Menu references (term ID, slug or name) in a content's classic menus.
	 *
	 * @param string $content Block content.
	 * @return string[]
	 */
	public static function menu_refs( $content ) {
		$refs = array();
		self::map_menu_attrs(
			$content,
			function ( $ref ) use ( &$refs ) {
				$refs[] = $ref;
				return $ref;
			}
		);
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Resolves a classic-menu reference to its menu term.
	 *
	 * @param string $ref Term ID, slug or name.
	 * @return WP_Term|false
	 */
	public static function menu_term( $ref ) {
		return wp_get_nav_menu_object( ctype_digit( (string) $ref ) ? (int) $ref : (string) $ref );
	}

	/**
	 * Items of a menu in display order.
	 *
	 * @param int    $term_id Menu term ID.
	 * @param string $status  "publish" (export) or "any" (target pairing).
	 * @return WP_Post[]
	 */
	public static function menu_items( $term_id, $status = 'publish' ) {
		return get_posts(
			array(
				'post_type'   => 'nav_menu_item',
				'post_status' => $status,
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query -- one menu per call.
				'tax_query'   => array(
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_id',
						'terms'    => (int) $term_id,
					),
				),
			)
		);
	}

	/**
	 * Collects the unit from this site's database.
	 *
	 * @param string[] $errors Pre-flight error collector.
	 * @return array {menus: array[], overlays: WP_Post[], elements: WP_Post[]} keyed by slug.
	 */
	private function collect( array &$errors ) {
		$elements = array();
		$terms    = array();
		foreach ( $this->nav_elements() as $element ) {
			$elements[ $element->post_name ] = $element;
			foreach ( self::menu_refs( $element->post_content ) as $ref ) {
				$term = self::menu_term( $ref );
				if ( ! $term ) {
					/* translators: 1: element slug, 2: menu reference. */
					$errors[] = sprintf( __( 'Element "%1$s" shows menu "%2$s", which does not exist.', 'wp-page-sync' ), $element->post_name, $ref );
					continue;
				}
				$terms[ $term->slug ] = $term;
			}
		}
		ksort( $terms );

		$menus    = array();
		$overlays = array();
		foreach ( $terms as $term ) {
			$menu    = $this->export_menu( $term, $errors );
			$menus[] = $menu;
			foreach ( $menu['items'] as $item ) {
				$overlay = '' !== $item['mega_menu'] ? $this->exporter->find_post( self::OVERLAY_TYPE, $item['mega_menu'] ) : null;
				if ( $overlay && 'publish' === $overlay->post_status ) {
					$overlays[ $overlay->post_name ] = $overlay;
				} elseif ( $overlay ) {
					/* translators: %s: overlay slug. */
					$errors[] = sprintf( __( 'The mega menu overlay "%s" is not published.', 'wp-page-sync' ), $overlay->post_name );
				}
			}
		}
		ksort( $overlays );

		return array(
			'menus'    => $menus,
			'overlays' => $overlays,
			'elements' => $elements,
		);
	}

	/**
	 * One menu in bundle form, with its hash.
	 *
	 * @param WP_Term  $term   Menu term.
	 * @param string[] $errors Pre-flight error collector.
	 * @return array
	 */
	private function export_menu( WP_Term $term, array &$errors ) {
		$posts   = self::menu_items( $term->term_id );
		$targets = array();
		foreach ( $posts as $item ) {
			$targets[] = (int) get_post_meta( $item->ID, '_menu_item_object_id', true );
		}
		// One query for all link targets instead of one per item (/status runs this).
		_prime_post_caches( array_filter( $targets ), false, false );

		$items  = array();
		$labels = array();
		foreach ( $posts as $item ) {
			$items[]  = $this->export_item( $item, $errors );
			$labels[] = self::rendered_label( $item );
		}
		$menu         = array(
			'slug'           => $term->slug,
			'name'           => $term->name,
			'description'    => (string) $term->description,
			'source_term_id' => (int) $term->term_id,
			'items'          => $items,
		);
		$menu['hash'] = $this->menu_hash( $menu, $labels );
		return $menu;
	}

	/**
	 * The label a menu item renders: its stored title, or the target's title
	 * when that is empty (core blanks a title equal to the target's on save,
	 * nav-menu.php). Hashing this keeps a synced menu "in sync".
	 *
	 * @param WP_Post $item Menu item.
	 * @return string
	 */
	private static function rendered_label( WP_Post $item ) {
		if ( '' !== $item->post_title ) {
			return $item->post_title;
		}
		$type = get_post_meta( $item->ID, '_menu_item_type', true );
		if ( 'post_type' === $type ) {
			$target = get_post( (int) get_post_meta( $item->ID, '_menu_item_object_id', true ) );
			return $target ? (string) $target->post_title : '';
		}
		$object = 'post_type_archive' === $type ? get_post_type_object( (string) get_post_meta( $item->ID, '_menu_item_object', true ) ) : null;
		return $object ? (string) $object->labels->archives : '';
	}

	/**
	 * One menu item in bundle form (site-neutral: page by path, overlay by
	 * slug, parent by source item key, custom URL on the own host).
	 *
	 * @param WP_Post  $item   Menu item post.
	 * @param string[] $errors Pre-flight error collector.
	 * @return array
	 */
	private function export_item( WP_Post $item, array &$errors ) {
		$meta = function ( $key ) use ( $item ) {
			return (string) get_post_meta( $item->ID, $key, true );
		};
		$type = $meta( '_menu_item_type' );
		$ref  = ( 'post_type' === $type ) ? self::post_ref( (int) $meta( '_menu_item_object_id' ) ) : null;
		$text = '' !== $item->post_title ? $item->post_title : '#' . $item->ID;

		if ( '' !== $meta( '_gb_menu_condition' ) ) {
			/* translators: %s: menu item label. */
			$errors[] = sprintf( __( 'Menu item "%s" has a GenerateBlocks display condition; conditions are not part of the navigation sync. Remove it on the sending site first.', 'wp-page-sync' ), $text );
		}
		if ( preg_grep( '/^if_menu_/', array_keys( (array) get_post_meta( $item->ID ) ) ) ) {
			/* translators: %s: menu item label. */
			$errors[] = sprintf( __( 'Menu item "%s" uses If Menu visibility rules, which the navigation sync does not support. Remove them on the sending site first.', 'wp-page-sync' ), $text );
		}
		if ( self::item_text_errors( array( $item->post_title, $item->post_excerpt, $item->post_content ) ) ) {
			/* translators: %s: menu item label. */
			$errors[] = sprintf( __( 'Menu item "%s" has unsafe markup in its title, title attribute or description.', 'wp-page-sync' ), $text );
		}

		if ( ! in_array( $type, self::ITEM_TYPES, true ) ) {
			/* translators: 1: menu item label, 2: menu item type. */
			$errors[] = sprintf( __( 'Menu item "%1$s" is of the type "%2$s"; the navigation sync carries pages, posts, custom links and post type archives only.', 'wp-page-sync' ), $text, $type );
		} elseif ( 'post_type' === $type && null === $ref ) {
			/* translators: %s: menu item label. */
			$errors[] = sprintf( __( 'Menu item "%s" points at something that is not a page or post with a slug.', 'wp-page-sync' ), $text );
		}

		return array(
			'key'              => (int) $item->ID,
			'parent_key'       => (int) $meta( '_menu_item_menu_item_parent' ),
			'position'         => (int) $item->menu_order,
			'title'            => (string) $item->post_title,
			'attr_title'       => (string) $item->post_excerpt,
			'description'      => (string) $item->post_content,
			'type'             => $type,
			'object'           => $meta( '_menu_item_object' ),
			'target_ref'       => $ref,
			'url'              => 'custom' === $type ? (string) $this->exporter->rehost_foreign( $meta( '_menu_item_url' ) ) : '',
			'target'           => $meta( '_menu_item_target' ),
			'classes'          => array_values( array_filter( array_map( 'strval', (array) get_post_meta( $item->ID, '_menu_item_classes', true ) ) ) ),
			'xfn'              => $meta( '_menu_item_xfn' ),
			'mega_menu'        => $this->overlay_slug( (int) $meta( '_gb_mega_menu' ), $text, $errors ),
			'mega_menu_anchor' => $meta( '_gb_mega_menu_anchor' ),
		);
	}

	/**
	 * Whether menu item text (title, attr title, description) holds markup
	 * the HTML check refuses. Stored raw on both sides so hashes stay equal.
	 *
	 * @param array $texts Texts.
	 * @return bool
	 */
	private static function item_text_errors( array $texts ) {
		foreach ( $texts as $text ) {
			if ( WPPS_Navigation_Rules::html_errors( (string) $text, array() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A post as {post_type, path}, or null unless it is a page or post with
	 * a slug.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private static function post_ref( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, WPPS_Exporter::SYNCABLE_POST_TYPES, true ) || '' === $post->post_name ) {
			return null;
		}
		return array(
			'post_type' => $post->post_type,
			'path'      => (string) get_page_uri( $post ),
		);
	}

	/**
	 * Slug of a mega-menu overlay, "" for none.
	 *
	 * @param int      $overlay_id Overlay post ID from _gb_mega_menu.
	 * @param string   $label      Menu item label (for the error).
	 * @param string[] $errors     Pre-flight error collector.
	 * @return string
	 */
	private function overlay_slug( $overlay_id, $label, array &$errors ) {
		if ( ! $overlay_id ) {
			return '';
		}
		$overlay = get_post( $overlay_id );
		if ( ! $overlay || self::OVERLAY_TYPE !== $overlay->post_type || '' === $overlay->post_name ) {
			/* translators: %s: menu item label. */
			$errors[] = sprintf( __( 'Menu item "%s" opens a mega menu overlay that does not exist.', 'wp-page-sync' ), $label );
			return '';
		}
		return $overlay->post_name;
	}

	/**
	 * GP display rules with a post object as a site-neutral {post_type,
	 * path} reference; a rule with any other object (a term) cannot travel.
	 *
	 * @param array    $meta   Exported element meta.
	 * @param string   $label  Element slug (for the error).
	 * @param string[] $errors Pre-flight error collector.
	 * @return array
	 */
	private static function conditions_to_refs( array $meta, $label, array &$errors ) {
		foreach ( self::CONDITION_KEYS as $key ) {
			if ( empty( $meta[ $key ] ) || ! is_array( $meta[ $key ] ) ) {
				continue;
			}
			foreach ( $meta[ $key ] as $index => $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['object'] ) ) {
					continue;
				}
				$ref = ( 0 === strpos( (string) ( $rule['rule'] ?? '' ), 'post:' ) ) ? self::post_ref( (int) $rule['object'] ) : null;
				if ( null === $ref ) {
					/* translators: 1: element slug, 2: GP display rule. */
					$errors[] = sprintf( __( 'Element "%1$s" has a display rule "%2$s" for an object that is not a page or post; the navigation sync cannot carry it.', 'wp-page-sync' ), $label, (string) ( $rule['rule'] ?? '' ) );
					continue;
				}
				$meta[ $key ][ $index ]['object']     = '';
				$meta[ $key ][ $index ]['object_ref'] = $ref;
			}
		}
		return $meta;
	}

	/* ------------------------------------------------------------------ *
	 * Hash
	 * ------------------------------------------------------------------ */

	/**
	 * Navigation hash for the status chip: in_sync when both sides match.
	 *
	 * @return string
	 */
	public function hash() {
		$errors = array();
		return $this->combined_hash( $this->collect( $errors ) );
	}

	/**
	 * Hash over the per-object hashes of a collected unit.
	 *
	 * @param array $collected collect() result.
	 * @return string
	 */
	private function combined_hash( array $collected ) {
		$slugs = self::slug_map( $collected['menus'] );
		$parts = array();
		foreach ( $collected['menus'] as $menu ) {
			$parts[] = 'menu:' . $menu['slug'] . '=' . $menu['hash'];
		}
		foreach ( array(
			'overlay' => $collected['overlays'],
			'element' => $collected['elements'],
		) as $role => $posts ) {
			foreach ( $posts as $slug => $post ) {
				$parts[] = $role . ':' . $slug . '=' . $this->post_hash( $post, $slugs );
			}
		}
		sort( $parts );
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Source term ID => menu slug of a bundle's or collection's menus.
	 *
	 * @param array[] $menus Menus in bundle form.
	 * @return array<int,string>
	 */
	private static function slug_map( array $menus ) {
		$map = array();
		foreach ( $menus as $menu ) {
			$map[ (int) $menu['source_term_id'] ] = (string) $menu['slug'];
		}
		return $map;
	}

	/**
	 * Site-neutral hash of a menu: parents as item index, hosts relative,
	 * the description trimmed (core stores " " for an empty one).
	 *
	 * @param array    $menu   Menu in bundle form.
	 * @param string[] $labels Rendered label per item (rendered_label()).
	 * @return string
	 */
	private function menu_hash( array $menu, array $labels ) {
		$hosts = $this->exporter->known_hosts();
		$index = array_flip( array_column( $menu['items'], 'key' ) );
		$items = array();
		foreach ( $menu['items'] as $position => $item ) {
			$items[] = array(
				isset( $index[ $item['parent_key'] ] ) ? $index[ $item['parent_key'] ] : -1,
				$item['type'],
				$item['object'],
				$item['target_ref'],
				WPPS_Rewriter::normalize( $item['url'], $hosts ),
				$labels[ $position ] ?? $item['title'],
				$item['attr_title'],
				trim( $item['description'] ),
				$item['target'],
				$item['classes'],
				$item['xfn'],
				$item['mega_menu'],
				$item['mega_menu_anchor'],
			);
		}
		return hash( 'sha256', (string) wp_json_encode( array( $menu['slug'], $menu['name'], $menu['description'], $items ) ) );
	}

	/**
	 * Site-neutral hash of an overlay or element: menu IDs as slugs,
	 * attachment IDs neutralized, env hosts relative, rules by path.
	 *
	 * @param WP_Post $post  Overlay or element.
	 * @param array   $slugs Term ID => menu slug.
	 * @return string
	 */
	private function post_hash( WP_Post $post, array $slugs ) {
		$hosts   = $this->exporter->known_hosts();
		$content = self::map_menu_attrs(
			$post->post_content,
			function ( $ref ) use ( $slugs ) {
				return isset( $slugs[ (int) $ref ] ) && ctype_digit( $ref ) ? 'menu:' . $slugs[ (int) $ref ] : $ref;
			}
		);
		$ignore  = array();
		$meta    = self::conditions_to_refs( $this->exporter->export_meta( $post->ID ), '', $ignore );
		$meta    = WPPS_Rewriter::apply_recursive(
			$meta,
			function ( $value ) use ( $hosts ) {
				return WPPS_Rewriter::normalize( $value, $hosts );
			}
		);
		ksort( $meta );

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					$post->post_type,
					$post->post_name,
					$post->post_title,
					'pending' === $post->post_status ? 'draft' : $post->post_status,
					WPPS_Rewriter::normalize( WPPS_Rewriter::neutralize_attachment_ids( $content ), $hosts ),
					WPPS_Rewriter::normalize( $post->post_excerpt, $hosts ),
					$meta,
				)
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * The navigation bundle (GET /navigation, POST /import-navigation body).
	 *
	 * @return array
	 */
	public function export() {
		$errors    = array();
		$warnings  = array();
		$collected = $this->collect( $errors );
		$slugs     = self::slug_map( $collected['menus'] );
		$posts     = array();
		$manifest  = array();

		foreach ( array(
			'overlay' => $collected['overlays'],
			'element' => $collected['elements'],
		) as $role => $list ) {
			foreach ( $list as $post ) {
				$bundle = $this->post_bundle( $post, $role, $errors );
				if ( null === $bundle ) {
					continue;
				}
				$bundle['nav_hash'] = $this->post_hash( $post, $slugs );
				$warnings           = array_merge( $warnings, $bundle['preflight']['warnings'] );
				foreach ( $bundle['media_manifest'] as $entry ) {
					$manifest[ $entry['rel_path'] ] = $entry;
				}
				$posts[] = $bundle;
			}
		}

		$bundle = array(
			'menus'          => $collected['menus'],
			'posts'          => $posts,
			'media_manifest' => array_values( $manifest ),
			'env'            => self::env(),
			'hash'           => $this->combined_hash( $collected ),
			'source'         => array(
				'home_url'    => home_url(),
				'uploads_url' => wp_upload_dir( null, false )['baseurl'],
			),
		);

		$bundle['preflight'] = array(
			'errors'   => array_values( array_unique( array_merge( $errors, WPPS_Navigation_Rules::cap_errors( $bundle ) ) ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
		return $bundle;
	}

	/**
	 * Post bundle of an overlay or element (the regular exporter bundle,
	 * plus nav_role and display rules by path).
	 *
	 * @param WP_Post  $post   Overlay or element.
	 * @param string   $role   overlay|element.
	 * @param string[] $errors Pre-flight error collector.
	 * @return array|null
	 */
	private function post_bundle( WP_Post $post, $role, array &$errors ) {
		$bundle = $this->exporter->export( $post->post_type, (string) get_page_uri( $post ) );
		if ( is_wp_error( $bundle ) ) {
			/* translators: 1: overlay or element slug, 2: error message. */
			$errors[] = sprintf( __( '"%1$s" cannot be exported: %2$s', 'wp-page-sync' ), $post->post_name, $bundle->get_error_message() );
			return null;
		}
		$bundle['nav_role'] = $role;
		$bundle['meta']     = self::conditions_to_refs( $bundle['meta'], $post->post_name, $errors );
		// Scripts are judged by the receiver (its WPPS_NAV_SCRIPT_HOSTS), not here.
		foreach ( WPPS_Navigation_Rules::post_errors( $role, WPPS_Navigation_Rules::stored_view( $post ), null ) as $reason ) {
			/* translators: 1: overlay or element slug, 2: reason. */
			$errors[] = sprintf( __( '"%1$s" cannot be synced: %2$s.', 'wp-page-sync' ), $post->post_name, $reason );
		}
		$errors = array_merge( $errors, $bundle['preflight']['errors'] );
		return $bundle;
	}

	/**
	 * Plugin state the receiver compares (preview warnings).
	 *
	 * @return array
	 */
	public static function env() {
		return array(
			'gb_pro_version'   => defined( 'GENERATEBLOCKS_PRO_VERSION' ) ? (string) GENERATEBLOCKS_PRO_VERSION : '',
			'gb_version'       => defined( 'GENERATEBLOCKS_VERSION' ) ? (string) GENERATEBLOCKS_VERSION : '',
			'overlays_enabled' => function_exists( 'generateblocks_pro_overlays_enabled' ) && generateblocks_pro_overlays_enabled(),
			'elements_enabled' => post_type_exists( self::ELEMENT_TYPE ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Validation (receiver side; any error means zero writes)
	 * ------------------------------------------------------------------ */

	/**
	 * Post bundles of a navigation bundle with a valid role.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return array[]
	 */
	private static function bundle_posts( array $bundle ) {
		$posts = ( isset( $bundle['posts'] ) && is_array( $bundle['posts'] ) ) ? $bundle['posts'] : array();
		return array_values(
			array_filter(
				$posts,
				function ( $post ) {
					return is_array( $post ) && isset( $post['nav_role'], $post['post'] ) && in_array( $post['nav_role'], array( 'overlay', 'element' ), true );
				}
			)
		);
	}

	/**
	 * Menus of a navigation bundle.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return array[]
	 */
	private static function bundle_menus( array $bundle ) {
		$menus = ( isset( $bundle['menus'] ) && is_array( $bundle['menus'] ) ) ? $bundle['menus'] : array();
		return array_values(
			array_filter(
				$menus,
				function ( $menu ) {
					return is_array( $menu ) && isset( $menu['slug'], $menu['name'], $menu['items'] ) && is_array( $menu['items'] );
				}
			)
		);
	}

	/**
	 * Every reason this site cannot take a navigation bundle.
	 *
	 * @param array      $bundle Navigation bundle.
	 * @param array|null $here   This site's collect() result (import passes it; null = collect here).
	 * @return string[] Error messages; empty = importable.
	 */
	public function validate( array $bundle, $here = null ) {
		$errors = WPPS_Navigation_Rules::cap_errors( $bundle );
		if ( $errors ) {
			return $errors;
		}
		if ( ! WPPS_Navigation_Rules::source_urls_ok( $bundle ) ) {
			$errors[] = __( 'The navigation bundle contains a media file URL with characters a URL must not have.', 'wp-page-sync' );
		}
		$ignore   = array();
		$here     = is_array( $here ) ? $here : $this->collect( $ignore );
		$overlays = $this->validate_posts( $bundle, $errors );
		$menus    = self::bundle_menus( $bundle );
		self::validate_menu_identity( $menus, $errors );
		$this->validate_scope( $bundle, $menus, array_column( $here['menus'], 'slug' ), $errors );
		$known = array();
		foreach ( $menus as $menu ) {
			$known = array_merge( $known, array( (string) ( $menu['source_term_id'] ?? '' ), (string) $menu['slug'], (string) $menu['name'] ) );
			self::validate_tree( $menu, $errors );
			$this->validate_menu( $menu, $overlays, $errors );
		}
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			if ( 'element' !== $post['nav_role'] ) {
				continue;
			}
			foreach ( array_diff( self::menu_refs( (string) ( $post['post']['content'] ?? '' ) ), $known ) as $ref ) {
				/* translators: 1: element slug, 2: menu ID, slug or name. */
				$errors[] = sprintf( __( 'Element "%1$s" shows the menu "%2$s", which is not part of the bundle.', 'wp-page-sync' ), (string) ( $post['post']['slug'] ?? '' ), $ref );
			}
			$this->validate_conditions( $post, $errors );
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Hosts the import rewrites to relative paths for a bundle (the same set
	 * import_post() uses), so checks see what will be stored.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return string[]
	 */
	private function import_hosts( array $bundle ) {
		$source_host = WPPS_Rewriter::bare_host( (string) ( $bundle['source']['home_url'] ?? '' ) );
		return $this->exporter->foreign_hosts( '' !== $source_host ? array( $source_host ) : array() );
	}

	/**
	 * Rule view of a bundle post with the content as the import will store
	 * it (env hosts rewritten; the media map only swaps upload URLs).
	 *
	 * @param array $bundle Navigation bundle.
	 * @param array $post   Post bundle.
	 * @return array
	 */
	private function stored_bundle_view( array $bundle, array $post ) {
		$view            = WPPS_Navigation_Rules::bundle_view( $post );
		$view['content'] = WPPS_Rewriter::rewrite_env_hosts( $view['content'], $this->import_hosts( $bundle ) );
		return $view;
	}

	/**
	 * Checks the overlay and element bundles; returns the overlay slugs.
	 *
	 * @param array    $bundle Navigation bundle.
	 * @param string[] $errors Error collector.
	 * @return array<string,true>
	 */
	private function validate_posts( array $bundle, array &$errors ) {
		$overlays = array();
		$types    = array(
			'overlay' => self::OVERLAY_TYPE,
			'element' => self::ELEMENT_TYPE,
		);
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			$type = $types[ $post['nav_role'] ];
			$slug = (string) ( $post['post']['slug'] ?? '' );
			if ( (string) ( $post['post']['post_type'] ?? '' ) !== $type || '' === $slug || ! WPPS_Importer::validate_post_path( (string) ( $post['post']['path'] ?? '' ) ) ) {
				$errors[] = __( 'The navigation bundle contains an invalid overlay or element.', 'wp-page-sync' );
				continue;
			}
			if ( ! post_type_exists( $type ) ) {
				/* translators: %s: post type. */
				$errors[] = sprintf( __( 'This site cannot store "%s" posts: activate GenerateBlocks Pro overlays and GP Premium elements first.', 'wp-page-sync' ), $type );
			}
			foreach ( WPPS_Navigation_Rules::post_errors( $post['nav_role'], $this->stored_bundle_view( $bundle, $post ), WPPS_Navigation_Rules::script_hosts() ) as $reason ) {
				/* translators: 1: overlay or element slug, 2: reason. */
				$errors[] = sprintf( __( 'Refused "%1$s": %2$s.', 'wp-page-sync' ), $slug, $reason );
			}
			$existing = $this->exporter->find_post( $type, (string) $post['post']['path'] );
			foreach ( $existing ? WPPS_Navigation_Rules::post_errors( $post['nav_role'], WPPS_Navigation_Rules::stored_view( $existing ), WPPS_Navigation_Rules::script_hosts() ) : array() as $reason ) {
				/* translators: 1: overlay or element slug, 2: reason. */
				$errors[] = sprintf( __( 'The existing "%1$s" on this site cannot be replaced by the navigation sync: %2$s.', 'wp-page-sync' ), $slug, $reason );
			}
			if ( 'overlay' === $post['nav_role'] ) {
				$overlays[ $slug ] = true;
			}
		}
		return $overlays;
	}

	/**
	 * Menu identity across the bundle: a name, unique names and slugs,
	 * unique positive source term IDs.
	 *
	 * @param array[]  $menus  Bundle menus.
	 * @param string[] $errors Error collector.
	 * @return void
	 */
	private static function validate_menu_identity( array $menus, array &$errors ) {
		$slugs = array();
		$names = array();
		$ids   = array();
		foreach ( $menus as $menu ) {
			$slug = (string) $menu['slug'];
			$name = (string) $menu['name'];
			$id   = (int) ( $menu['source_term_id'] ?? 0 );
			if ( '' === trim( $name ) ) {
				$errors[] = __( 'A menu in the navigation bundle has no name.', 'wp-page-sync' );
			}
			if ( isset( $names[ $name ] ) ) {
				/* translators: %s: menu name. */
				$errors[] = sprintf( __( 'The navigation bundle contains two menus named "%s".', 'wp-page-sync' ), $name );
			}
			$names[ $name ] = true;
			if ( isset( $slugs[ $slug ] ) ) {
				/* translators: %s: menu slug. */
				$errors[] = sprintf( __( 'The navigation bundle contains the menu "%s" twice.', 'wp-page-sync' ), $slug );
			}
			if ( $id <= 0 || isset( $ids[ $id ] ) ) {
				$errors[] = __( 'The navigation bundle contains a menu with a missing or duplicate ID.', 'wp-page-sync' );
			}
			$slugs[ $slug ] = true;
			$ids[ $id ]     = true;
		}
	}

	/**
	 * Item structure of one menu: unique positive keys, parents inside the
	 * menu, no item its own ancestor (self-parent or loop).
	 *
	 * @param array    $menu   Menu in bundle form.
	 * @param string[] $errors Error collector.
	 * @return void
	 */
	private static function validate_tree( array $menu, array &$errors ) {
		$name    = (string) $menu['name'];
		$parents = array();
		foreach ( $menu['items'] as $item ) {
			$key = is_array( $item ) ? (int) ( $item['key'] ?? 0 ) : 0;
			if ( $key <= 0 || isset( $parents[ $key ] ) ) {
				/* translators: %s: menu name. */
				$errors[] = sprintf( __( 'Menu "%s" contains an item with a missing or duplicate key.', 'wp-page-sync' ), $name );
				return;
			}
			$parents[ $key ] = (int) ( $item['parent_key'] ?? 0 );
		}
		foreach ( $parents as $key => $parent ) {
			if ( 0 !== $parent && ! isset( $parents[ $parent ] ) ) {
				/* translators: %s: menu name. */
				$errors[] = sprintf( __( 'Menu "%s" contains an item whose parent is not in the menu.', 'wp-page-sync' ), $name );
				return;
			}
		}
		// Linear: every node walked once; nodes proven loop-free are remembered.
		$safe = array( 0 => true );
		foreach ( array_keys( $parents ) as $start ) {
			$path = array();
			$node = $start;
			while ( ! isset( $safe[ $node ] ) ) {
				if ( isset( $path[ $node ] ) ) {
					/* translators: %s: menu name. */
					$errors[] = sprintf( __( 'The item parents of menu "%s" form a loop.', 'wp-page-sync' ), $name );
					return;
				}
				$path[ $node ] = true;
				$node          = $parents[ $node ];
			}
			$safe += $path;
		}
	}

	/**
	 * Menu scope: every bundle menu must be shown by a bundle element, and an
	 * existing menu of the same slug may only be replaced when it belongs to
	 * this site's own navigation unit.
	 *
	 * @param array    $bundle Navigation bundle.
	 * @param array[]  $menus  Bundle menus.
	 * @param string[] $unit   Menu slugs of this site's own unit.
	 * @param string[] $errors Error collector.
	 * @return void
	 */
	private function validate_scope( array $bundle, array $menus, array $unit, array &$errors ) {
		$shown = array();
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			if ( 'element' === $post['nav_role'] ) {
				$shown = array_merge( $shown, self::menu_refs( (string) ( $post['post']['content'] ?? '' ) ) );
			}
		}
		foreach ( $menus as $menu ) {
			$slug = (string) $menu['slug'];
			if ( ! array_intersect( array( (string) ( $menu['source_term_id'] ?? '' ), $slug, (string) $menu['name'] ), $shown ) ) {
				/* translators: %s: menu slug. */
				$errors[] = sprintf( __( 'The navigation bundle contains the menu "%s", which none of its elements shows.', 'wp-page-sync' ), $slug );
			}
			if ( get_term_by( 'slug', $slug, 'nav_menu' ) && ! in_array( $slug, $unit, true ) ) {
				/* translators: %s: menu slug. */
				$errors[] = sprintf( __( 'The menu "%s" exists on this site but is not part of its navigation, so the sync does not replace it. Rename or delete it first.', 'wp-page-sync' ), $slug );
			}
		}
	}

	/**
	 * Checks one menu: name clash with another menu, item types, page
	 * targets (must exist published here), mega-menu overlays (must be in
	 * the bundle).
	 *
	 * @param array    $menu     Menu in bundle form.
	 * @param array    $overlays Overlay slugs in the bundle.
	 * @param string[] $errors   Error collector.
	 * @return void
	 */
	private function validate_menu( array $menu, array $overlays, array &$errors ) {
		$slug  = (string) $menu['slug'];
		$named = get_term_by( 'name', (string) $menu['name'], 'nav_menu' );
		if ( '' === $slug || sanitize_title( $slug ) !== $slug ) {
			$errors[] = __( 'The navigation bundle contains a menu with an invalid slug.', 'wp-page-sync' );
		} elseif ( $named && $named->slug !== $slug ) {
			/* translators: 1: menu name, 2: menu slug here. */
			$errors[] = sprintf( __( 'A menu named "%1$s" already exists here with the slug "%2$s". Rename one of them first.', 'wp-page-sync' ), (string) $menu['name'], $named->slug );
		}
		foreach ( $menu['items'] as $item ) {
			$label = is_array( $item ) ? (string) ( $item['title'] ?? '' ) : '';
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! in_array( (string) ( $item['type'] ?? '' ), self::ITEM_TYPES, true ) ) {
				/* translators: %s: menu name. */
				$errors[] = sprintf( __( 'Menu "%s" contains an item type the navigation sync does not carry.', 'wp-page-sync' ), (string) $menu['name'] );
				continue;
			}
			if ( 'post_type' === $item['type'] && ! $this->resolve_ref( $item['target_ref'] ?? null, true ) ) {
				/* translators: 1: menu item label, 2: page path. */
				$errors[] = sprintf( __( 'Menu item "%1$s" links to "%2$s", which does not exist here or is not published.', 'wp-page-sync' ), $label, (string) ( $item['target_ref']['path'] ?? '' ) );
			}
			if ( '' !== (string) ( $item['mega_menu'] ?? '' ) && ! isset( $overlays[ $item['mega_menu'] ] ) ) {
				/* translators: 1: menu item label, 2: overlay slug. */
				$errors[] = sprintf( __( 'Menu item "%1$s" opens the mega menu "%2$s", which is not part of the bundle.', 'wp-page-sync' ), $label, (string) $item['mega_menu'] );
			}
			if ( ! empty( $item['extra_meta'] ) ) {
				/* translators: %s: menu item label. */
				$errors[] = sprintf( __( 'Menu item "%s" carries extra meta, which the navigation sync does not accept.', 'wp-page-sync' ), $label );
			}
			if ( self::item_text_errors( array( $item['title'] ?? '', $item['attr_title'] ?? '', $item['description'] ?? '' ) ) ) {
				/* translators: %s: menu item label. */
				$errors[] = sprintf( __( 'Menu item "%s" has unsafe markup in its title, title attribute or description.', 'wp-page-sync' ), $label );
			}
		}
	}

	/**
	 * Checks that every display rule of an element resolves here.
	 *
	 * @param array    $post   Element bundle.
	 * @param string[] $errors Error collector.
	 * @return void
	 */
	private function validate_conditions( array $post, array &$errors ) {
		foreach ( self::CONDITION_KEYS as $key ) {
			$rules = ( isset( $post['meta'][ $key ] ) && is_array( $post['meta'][ $key ] ) ) ? $post['meta'][ $key ] : array();
			foreach ( $rules as $rule ) {
				if ( is_array( $rule ) && isset( $rule['object_ref'] ) && ! $this->resolve_ref( $rule['object_ref'], false ) ) {
					/* translators: 1: element slug, 2: page path. */
					$errors[] = sprintf( __( 'Element "%1$s" has a display rule for "%2$s", which does not exist here.', 'wp-page-sync' ), (string) ( $post['post']['slug'] ?? '' ), (string) ( $rule['object_ref']['path'] ?? '' ) );
				}
			}
		}
	}

	/**
	 * Local post ID of a {post_type, path} reference, 0 when missing.
	 *
	 * @param mixed $ref       Reference from a bundle.
	 * @param bool  $published Require the publish status.
	 * @return int
	 */
	private function resolve_ref( $ref, $published ) {
		if ( ! is_array( $ref ) || ! in_array( (string) ( $ref['post_type'] ?? '' ), WPPS_Exporter::SYNCABLE_POST_TYPES, true ) || ! WPPS_Importer::validate_post_path( (string) ( $ref['path'] ?? '' ) ) ) {
			return 0;
		}
		$post = $this->exporter->find_post( (string) $ref['post_type'], (string) $ref['path'] );
		if ( ! $post || ( $published && 'publish' !== $post->post_status ) ) {
			return 0;
		}
		return (int) $post->ID;
	}

	/* ------------------------------------------------------------------ *
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Imports a navigation bundle (plan §6.8 write order). Media must be
	 * present already. Everything is validated first: a validation failure
	 * writes nothing.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return array|WP_Error
	 */
	public function import( array $bundle ) {
		$ignore = array();
		$here   = $this->collect( $ignore );
		$errors = $this->validate( $bundle, $here );
		if ( $errors ) {
			return new WP_Error( 'wpps_preflight', implode( ' ', $errors ), array( 'status' => 400 ) );
		}

		$before = $this->combined_hash( $here );
		$result = array(
			'created'        => array(),
			'updated'        => array(),
			'items_written'  => 0,
			'items_deleted'  => 0,
			'only_on_target' => $this->only_on_target( $bundle, $here ),
			'warnings'       => array(),
			'unchanged'      => true,
			'hash'           => $before,
		);
		if ( (string) ( $bundle['hash'] ?? '' ) === $before ) {
			// The sender's hash says nothing changes: no writes, no purge, no log.
			return $result;
		}

		$written = $this->write_bundle( $bundle, $result );
		if ( is_wp_error( $written ) ) {
			// The write phase was entered: parts may be written (callers log it).
			$data                 = $written->get_error_data();
			$data                 = is_array( $data ) ? $data : array();
			$data['wpps_written'] = true;
			$written->add_data( $data );
			return $written;
		}
		// Logged by the caller whenever the write phase ran, changed or not.
		$result['written'] = true;

		// Caches (and LiteSpeed) are purged only when the navigation really changed.
		$result['hash']      = $this->hash();
		$result['unchanged'] = $result['hash'] === $before;
		if ( ! $result['unchanged'] ) {
			self::clear_caches();
		}
		return $result;
	}

	/**
	 * The write phase of an import (plan §6.8 order): menus, overlays,
	 * elements, then the items of every menu.
	 *
	 * @param array $bundle Validated navigation bundle.
	 * @param array $result Import result (lists and counters).
	 * @return true|WP_Error
	 */
	private function write_bundle( array $bundle, array &$result ) {
		$menu_ids = $this->apply_menus( self::bundle_menus( $bundle ), $result );
		$overlays = is_wp_error( $menu_ids ) ? $menu_ids : $this->import_posts( $bundle, 'overlay', $menu_ids, $result );
		$elements = is_wp_error( $overlays ) ? $overlays : $this->import_posts( $bundle, 'element', $menu_ids, $result );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$context = array(
			'overlays' => $overlays,
			'hosts'    => $this->import_hosts( $bundle ),
		);
		foreach ( self::bundle_menus( $bundle ) as $menu ) {
			$written = $this->write_items( $menu_ids[ (int) ( $menu['source_term_id'] ?? 0 ) ], $menu['items'], $context );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$result['items_written'] += $written['written'];
			$result['items_deleted'] += $written['deleted'];
		}
		return true;
	}

	/**
	 * Labels of this site's menus, overlays and elements that the bundle
	 * does not carry (kept, never deleted).
	 *
	 * @param array $bundle Navigation bundle.
	 * @param array $here   This site's collect() result.
	 * @return string[]
	 */
	private function only_on_target( array $bundle, array $here ) {
		$sent = array();
		foreach ( self::bundle_menus( $bundle ) as $menu ) {
			$sent[ 'menu:' . $menu['slug'] ] = true;
		}
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			$sent[ $post['nav_role'] . ':' . (string) ( $post['post']['slug'] ?? '' ) ] = true;
		}
		$only = array();
		foreach ( $here['menus'] as $menu ) {
			if ( ! isset( $sent[ 'menu:' . $menu['slug'] ] ) ) {
				$only[] = 'menu:' . $menu['slug'];
			}
		}
		foreach ( array(
			'overlay' => $here['overlays'],
			'element' => $here['elements'],
		) as $role => $posts ) {
			foreach ( array_keys( $posts ) as $slug ) {
				if ( ! isset( $sent[ $role . ':' . $slug ] ) ) {
					$only[] = $role . ':' . $slug;
				}
			}
		}
		return $only;
	}

	/**
	 * Creates missing menus (then fixes the slug core derived from the name)
	 * and updates name and description of existing ones.
	 *
	 * @param array[] $menus  Bundle menus.
	 * @param array   $result Import result (created/updated lists).
	 * @return array<int,int>|WP_Error Source term ID => target term ID.
	 */
	private function apply_menus( array $menus, array &$result ) {
		$ids = array();
		foreach ( $menus as $menu ) {
			$term = get_term_by( 'slug', (string) $menu['slug'], 'nav_menu' );
			$args = array(
				'name'        => (string) $menu['name'],
				'slug'        => (string) $menu['slug'],
				'description' => (string) ( $menu['description'] ?? '' ),
			);
			if ( $term ) {
				$term_id             = (int) $term->term_id;
				$result['updated'][] = 'menu:' . $menu['slug'];
			} else {
				$term_id = wp_create_nav_menu( wp_slash( (string) $menu['name'] ) );
				if ( is_wp_error( $term_id ) ) {
					return $term_id;
				}
				$result['created'][] = 'menu:' . $menu['slug'];
			}
			$updated = wp_update_term( (int) $term_id, 'nav_menu', wp_slash( $args ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$ids[ (int) ( $menu['source_term_id'] ?? 0 ) ] = (int) $term_id;
		}
		return $ids;
	}

	/**
	 * Imports the overlays or the elements through the regular post import
	 * (rewrite, media map, kses bracket, WP revision), with menu IDs and
	 * display rules mapped first, then drops meta the sender does not have.
	 *
	 * @param array  $bundle   Navigation bundle.
	 * @param string $role     overlay|element.
	 * @param array  $menu_ids Source term ID => target term ID.
	 * @param array  $result   Import result.
	 * @return array<string,int>|WP_Error Slug => post ID.
	 */
	private function import_posts( array $bundle, $role, array $menu_ids, array &$result ) {
		$ids = array();
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			if ( $role !== $post['nav_role'] ) {
				continue;
			}
			$type     = (string) $post['post']['post_type'];
			$existing = $this->exporter->find_post( $type, (string) $post['post']['path'] );
			if ( 'element' === $role ) {
				$post = $this->map_element( $post, $menu_ids );
			}
			// Defense in depth: validate() refuses it already, it is never written anyway.
			unset( $post['meta'][ WPPS_Navigation_Rules::PHP_HOOK_KEY ] );
			// Rewrite context = the navigation bundle's (what validate() checked against).
			$post['source'] = array(
				'home_url'    => (string) ( $bundle['source']['home_url'] ?? '' ),
				'uploads_url' => (string) ( $bundle['source']['uploads_url'] ?? '' ),
			);
			$saved          = $this->importer->import_post( $post, array( $type ), array( __CLASS__, 'final_guard' ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			self::prune_meta( (int) $saved['id'], ( isset( $post['meta'] ) && is_array( $post['meta'] ) ) ? $post['meta'] : array() );
			$ids[ (string) $post['post']['slug'] ]         = (int) $saved['id'];
			$result[ $existing ? 'updated' : 'created' ][] = $role . ':' . $post['post']['slug'];
			$result['warnings']                            = array_merge( $result['warnings'], $saved['warnings'] );
		}
		return $ids;
	}

	/**
	 * Authoritative check on the final strings import_post() is about to
	 * write (after media map, host rewrite and attachment ID mapping).
	 *
	 * @param string $content Final content.
	 * @param string $title   Title.
	 * @param string $excerpt Final excerpt.
	 * @return string[] Errors; empty = write.
	 */
	public static function final_guard( $content, $title, $excerpt ) {
		$errors = WPPS_Navigation_Rules::html_errors( $content, WPPS_Navigation_Rules::script_hosts() );
		if ( wp_strip_all_tags( $title ) !== $title || wp_strip_all_tags( $excerpt ) !== $excerpt ) {
			$errors[] = __( 'its title or excerpt contains markup', 'wp-page-sync' );
		}
		return $errors;
	}

	/**
	 * An element bundle with this site's menu IDs and display-rule objects.
	 *
	 * @param array $post     Element bundle.
	 * @param array $menu_ids Source term ID => target term ID.
	 * @return array
	 */
	private function map_element( array $post, array $menu_ids ) {
		$post['post']['content'] = self::map_menu_attrs(
			(string) $post['post']['content'],
			function ( $ref ) use ( $menu_ids ) {
				return ( ctype_digit( $ref ) && isset( $menu_ids[ (int) $ref ] ) ) ? (string) $menu_ids[ (int) $ref ] : $ref;
			}
		);
		foreach ( self::CONDITION_KEYS as $key ) {
			if ( empty( $post['meta'][ $key ] ) || ! is_array( $post['meta'][ $key ] ) ) {
				continue;
			}
			foreach ( $post['meta'][ $key ] as $index => $rule ) {
				if ( is_array( $rule ) && isset( $rule['object_ref'] ) ) {
					$post['meta'][ $key ][ $index ]['object'] = (string) $this->resolve_ref( $rule['object_ref'], false );
					unset( $post['meta'][ $key ][ $index ]['object_ref'] );
				}
			}
		}
		return $post;
	}

	/**
	 * Deletes meta the sender does not have, so both copies converge (the
	 * post import only adds and updates keys). Denied keys stay.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Bundle meta.
	 * @return void
	 */
	private static function prune_meta( $post_id, array $meta ) {
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) && ! WPPS_Exporter::is_denied_meta_key( (string) $key ) ) {
				delete_post_meta( $post_id, (string) $key );
			}
		}
	}

	/**
	 * Replaces a menu's items with the bundle's: existing items are reused
	 * by position (stable IDs on a re-import), surplus ones are created,
	 * parents set from the bundle keys, leftovers deleted last.
	 *
	 * @param int   $term_id Target menu term ID.
	 * @param array $items   Bundle items in order.
	 * @param array $context {overlays: slug => ID, hosts: string[]}.
	 * @return array|WP_Error {written, deleted}
	 */
	private function write_items( $term_id, array $items, array $context ) {
		$existing = wp_list_pluck( self::menu_items( $term_id, 'any' ), 'ID' );
		$ids      = array();

		// Titles and descriptions may carry markup that kses strips for user 0.
		kses_remove_filters();
		try {
			foreach ( array_values( $items ) as $index => $item ) {
				$id = wp_update_nav_menu_item( $term_id, (int) ( $existing[ $index ] ?? 0 ), wp_slash( $this->item_args( $item, $index + 1, $context ) ) );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$ids[ (int) $item['key'] ] = (int) $id;
				self::replace_item_meta( (int) $id, $item, $context['overlays'] );
			}
			foreach ( $items as $item ) {
				$parent = (int) ( $item['parent_key'] ?? 0 );
				update_post_meta( $ids[ (int) $item['key'] ], '_menu_item_menu_item_parent', (string) ( $ids[ $parent ] ?? 0 ) );
			}
		} finally {
			kses_init_filters();
		}

		$leftovers = array_slice( $existing, count( $items ) );
		foreach ( $leftovers as $leftover ) {
			wp_delete_post( (int) $leftover, true );
		}
		return array(
			'written' => count( $items ),
			'deleted' => count( $leftovers ),
		);
	}

	/**
	 * wp_update_nav_menu_item() arguments for one bundle item.
	 *
	 * @param array $item     Bundle item.
	 * @param int   $position 1-based position.
	 * @param array $context  {overlays, hosts}.
	 * @return array
	 */
	private function item_args( array $item, $position, array $context ) {
		$type = (string) $item['type'];
		return array(
			'menu-item-type'        => $type,
			'menu-item-object'      => (string) ( $item['object'] ?? '' ),
			'menu-item-object-id'   => 'post_type' === $type ? $this->resolve_ref( $item['target_ref'], true ) : 0,
			'menu-item-parent-id'   => 0,
			'menu-item-position'    => (int) $position,
			'menu-item-title'       => (string) ( $item['title'] ?? '' ),
			'menu-item-url'         => 'custom' === $type ? WPPS_Rewriter::rewrite_env_hosts( (string) ( $item['url'] ?? '' ), $context['hosts'] ) : '',
			'menu-item-description' => (string) ( $item['description'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $item['attr_title'] ?? '' ),
			'menu-item-target'      => (string) ( $item['target'] ?? '' ),
			'menu-item-classes'     => implode( ' ', array_map( 'strval', (array) ( $item['classes'] ?? array() ) ) ),
			'menu-item-xfn'         => (string) ( $item['xfn'] ?? '' ),
			'menu-item-status'      => 'publish',
		);
	}

	/**
	 * Sets an item's non-core meta to exactly the bundle's: the mega-menu
	 * pair (overlay mapped by slug) and every extra row (multi-row safe).
	 *
	 * @param int   $item_id  Menu item ID.
	 * @param array $item     Bundle item.
	 * @param array $overlays Overlay slug => target post ID.
	 * @return void
	 */
	private static function replace_item_meta( $item_id, array $item, array $overlays ) {
		foreach ( array_keys( (array) get_post_meta( $item_id ) ) as $key ) {
			if ( 0 !== strpos( (string) $key, '_menu_item_' ) ) {
				delete_post_meta( $item_id, (string) $key );
			}
		}
		$mega = (string) ( $item['mega_menu'] ?? '' );
		if ( '' !== $mega && isset( $overlays[ $mega ] ) ) {
			add_post_meta( $item_id, '_gb_mega_menu', $overlays[ $mega ] );
		}
		if ( '' !== (string) ( $item['mega_menu_anchor'] ?? '' ) ) {
			add_post_meta( $item_id, '_gb_mega_menu_anchor', wp_slash( (string) $item['mega_menu_anchor'] ) );
		}
	}

	/**
	 * Clears the caches that hold menus and overlays after an import.
	 *
	 * @return void
	 */
	private static function clear_caches() {
		if ( function_exists( 'generateblocks_pro_clear_overlay_cache' ) ) {
			generateblocks_pro_clear_overlay_cache();
		}
		if ( defined( 'GENERATEBLOCKS_VERSION' ) ) {
			// GenerateBlocks Pro resets this list on every overlay save.
			update_option( 'generateblocks_dynamic_css_posts', array() );
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge API.
		}
	}

	/* ------------------------------------------------------------------ *
	 * Preview helpers (diff, receiver references, environment)
	 * ------------------------------------------------------------------ */

	/**
	 * What a sync from one bundle onto another would change, as display
	 * groups {heading, lines[]}, plus what only the target has.
	 *
	 * @param array $from Sending bundle.
	 * @param array $to   Receiving bundle.
	 * @return array {groups: array[], only_on_target: string[]}
	 */
	public static function diff( array $from, array $to ) {
		return array(
			'groups'         => array_merge( self::diff_menus( self::bundle_menus( $from ), self::bundle_menus( $to ) ), self::diff_posts( $from, $to ) ),
			'only_on_target' => self::only_in( $to, $from ),
		);
	}

	/**
	 * Menu groups: new menus with their items, changed menus with the items
	 * added (+) and removed (−).
	 *
	 * @param array[] $from Sending menus.
	 * @param array[] $to   Receiving menus.
	 * @return array[]
	 */
	private static function diff_menus( array $from, array $to ) {
		$target = array_column( $to, null, 'slug' );
		$groups = array();
		foreach ( $from as $menu ) {
			$labels = array_map( array( __CLASS__, 'item_label' ), $menu['items'] );
			if ( ! isset( $target[ $menu['slug'] ] ) ) {
				$groups[] = array(
					/* translators: 1: menu name, 2: number of items. */
					'heading' => sprintf( __( 'Menu "%1$s": new, %2$d items', 'wp-page-sync' ), $menu['name'], count( $labels ) ),
					'lines'   => $labels,
				);
				continue;
			}
			if ( (string) ( $menu['hash'] ?? '' ) === (string) ( $target[ $menu['slug'] ]['hash'] ?? '' ) ) {
				continue;
			}
			$lines    = self::label_changes( $labels, array_map( array( __CLASS__, 'item_label' ), $target[ $menu['slug'] ]['items'] ) );
			$groups[] = array(
				/* translators: %s: menu name. */
				'heading' => sprintf( __( 'Menu "%s": changed', 'wp-page-sync' ), $menu['name'] ),
				'lines'   => $lines ? $lines : array( __( 'Item order or item settings changed.', 'wp-page-sync' ) ),
			);
		}
		return $groups;
	}

	/**
	 * "+ label" for labels only in $sent, "− label" for labels only in $kept
	 * (multiset, so a duplicated item counts).
	 *
	 * @param string[] $sent Sending labels.
	 * @param string[] $kept Receiving labels.
	 * @return string[]
	 */
	private static function label_changes( array $sent, array $kept ) {
		$lines = array();
		$count = array_count_values( $kept );
		foreach ( $sent as $label ) {
			if ( ! empty( $count[ $label ] ) ) {
				--$count[ $label ];
				continue;
			}
			$lines[] = '+ ' . $label;
		}
		foreach ( $count as $label => $left ) {
			for ( $i = 0; $i < $left; $i++ ) {
				$lines[] = '− ' . $label;
			}
		}
		return $lines;
	}

	/**
	 * Display label of a bundle item: text → destination, with mega menu.
	 *
	 * @param mixed $item Bundle item (peer-supplied, may be malformed).
	 * @return string
	 */
	private static function item_label( $item ) {
		$item = is_array( $item ) ? $item : array();
		$type = (string) ( $item['type'] ?? '' );
		$path = isset( $item['target_ref']['path'] ) ? '/' . $item['target_ref']['path'] . '/' : '';
		if ( 'custom' === $type ) {
			$path = (string) ( $item['url'] ?? '' );
		} elseif ( 'post_type_archive' === $type ) {
			$path = 'archive:' . (string) ( $item['object'] ?? '' );
		}
		$text  = '' !== (string) ( $item['title'] ?? '' ) ? (string) $item['title'] : $path;
		$label = ( empty( $item['parent_key'] ) ? '' : '– ' ) . $text . ' → ' . $path;
		$mega  = (string) ( $item['mega_menu'] ?? '' );
		/* translators: %s: overlay slug. */
		return '' !== $mega ? $label . ' ' . sprintf( __( '(mega menu "%s")', 'wp-page-sync' ), $mega ) : $label;
	}

	/**
	 * Overlay and element groups: new or changed, overlays with link changes.
	 *
	 * @param array $from Sending bundle.
	 * @param array $to   Receiving bundle.
	 * @return array[]
	 */
	private static function diff_posts( array $from, array $to ) {
		$target = array();
		foreach ( self::bundle_posts( $to ) as $post ) {
			$target[ $post['nav_role'] . ':' . (string) ( $post['post']['slug'] ?? '' ) ] = $post;
		}
		$lines = array(
			'overlay' => array(),
			'element' => array(),
		);
		foreach ( self::bundle_posts( $from ) as $post ) {
			$slug = (string) ( $post['post']['slug'] ?? '' );
			$key  = $post['nav_role'] . ':' . $slug;
			if ( ! isset( $target[ $key ] ) ) {
				/* translators: %s: overlay or element slug. */
				$lines[ $post['nav_role'] ][] = sprintf( __( '%s: new', 'wp-page-sync' ), $slug );
			} elseif ( (string) ( $post['nav_hash'] ?? '' ) !== (string) ( $target[ $key ]['nav_hash'] ?? '' ) ) {
				/* translators: %s: overlay or element slug. */
				$lines[ $post['nav_role'] ][] = sprintf( __( '%s: changed', 'wp-page-sync' ), $slug );
				$lines[ $post['nav_role'] ]   = array_merge( $lines[ $post['nav_role'] ], self::label_changes( self::links( $post, $from ), self::links( $target[ $key ], $to ) ) );
			}
		}
		$groups = array();
		if ( $lines['overlay'] ) {
			$groups[] = array(
				'heading' => __( 'Mega menu overlays', 'wp-page-sync' ),
				'lines'   => $lines['overlay'],
			);
		}
		if ( $lines['element'] ) {
			$groups[] = array(
				'heading' => __( 'Header and footer elements', 'wp-page-sync' ),
				'lines'   => $lines['element'],
			);
		}
		return $groups;
	}

	/**
	 * Link targets in a post bundle's content, own-host links relative.
	 *
	 * @param array $post   Post bundle.
	 * @param array $bundle Navigation bundle it belongs to (source host).
	 * @return string[]
	 */
	private static function links( array $post, array $bundle ) {
		$host    = WPPS_Rewriter::bare_host( (string) ( $bundle['source']['home_url'] ?? '' ) );
		$content = WPPS_Rewriter::normalize( (string) ( $post['post']['content'] ?? '' ), '' !== $host ? array( $host ) : array() );
		if ( ! preg_match_all( '/href=(["\'])([^"\']*)\1/i', $content, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[2] ) );
	}

	/**
	 * Labels of menus, overlays and elements only $bundle has.
	 *
	 * @param array $bundle Bundle to list from.
	 * @param array $other  Bundle to compare with.
	 * @return string[]
	 */
	private static function only_in( array $bundle, array $other ) {
		$keys = function ( array $source ) {
			$list = array();
			foreach ( self::bundle_menus( $source ) as $menu ) {
				$list[] = 'menu:' . $menu['slug'];
			}
			foreach ( self::bundle_posts( $source ) as $post ) {
				$list[] = $post['nav_role'] . ':' . $post['post']['slug'];
			}
			return $list;
		};
		return array_values( array_diff( $keys( $bundle ), $keys( $other ) ) );
	}

	/**
	 * Internal link paths in the bundle's overlay content (a missing target
	 * on the receiver is a warning).
	 *
	 * @param array $bundle Sending bundle.
	 * @return string[]
	 */
	public static function link_paths( array $bundle ) {
		$host  = WPPS_Rewriter::bare_host( (string) ( $bundle['source']['home_url'] ?? '' ) );
		$paths = array();
		foreach ( self::bundle_posts( $bundle ) as $post ) {
			foreach ( 'overlay' === $post['nav_role'] ? self::links( $post, $bundle ) : array() as $href ) {
				$path = WPPS_Verifier::internal_link_path( $href, $host );
				if ( null !== $path && '' !== $path && WPPS_Importer::validate_post_path( $path ) ) {
					$paths[ $path ] = true;
				}
			}
		}
		return array_keys( $paths );
	}

	/**
	 * What an import of the bundle would report on this site, without
	 * writing anything: the validation errors and the warnings (plugin
	 * versions, If Menu, mega menu links to missing pages). Serves the pull
	 * preview locally and the push preview via /import-navigation?dry_run=1.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return array {errors: string[], warnings: string[]}
	 */
	public function dry_run( array $bundle ) {
		$warnings = self::env_warnings( $bundle, array( 'env' => self::env() ) );
		foreach ( self::link_paths( $bundle ) as $path ) {
			if ( ! self::link_exists( $path ) ) {
				/* translators: %s: link path. */
				$warnings[] = sprintf( __( 'A mega menu links to "/%s/", which is not a published page or post on the receiving site.', 'wp-page-sync' ), $path );
			}
		}
		return array(
			'errors'   => $this->validate( $bundle ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether a link path is a published page or post on this site.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function link_exists( $path ) {
		$post = get_page_by_path( $path, OBJECT, WPPS_Exporter::SYNCABLE_POST_TYPES );
		return $post && 'publish' === $post->post_status;
	}

	/**
	 * Preview warnings from the two sites' plugin state.
	 *
	 * @param array $from Sending bundle.
	 * @param array $to   Receiving bundle.
	 * @return string[]
	 */
	public static function env_warnings( array $from, array $to ) {
		$warnings = array();
		$sent     = (string) ( $from['env']['gb_pro_version'] ?? '' );
		$here     = (string) ( $to['env']['gb_pro_version'] ?? '' );
		if ( $sent !== $here ) {
			/* translators: 1: version on the sending site, 2: version on the receiving site. */
			$warnings[] = sprintf( __( 'GenerateBlocks Pro differs: %1$s on the sending site, %2$s on the receiving site. Menus and overlays may render differently.', 'wp-page-sync' ), '' !== $sent ? $sent : '-', '' !== $here ? $here : '-' );
		}
		return $warnings;
	}

	/* ------------------------------------------------------------------ *
	 * Verifier
	 * ------------------------------------------------------------------ */

	/**
	 * Overlays and elements of the unit (scanned like pages by the verifier).
	 *
	 * @return WP_Post[]
	 */
	public function nav_posts() {
		$errors    = array();
		$collected = $this->collect( $errors );
		return array_merge( array_values( $collected['overlays'] ), array_values( $collected['elements'] ) );
	}

	/**
	 * Navigation errors for the Verify tab: a classic menu showing a menu
	 * that does not exist, menu items linking to a missing or unpublished
	 * page, mega menus opening a missing, unpublished or non-mega-menu
	 * overlay.
	 *
	 * @return array[] Verifier entries.
	 */
	public function problems() {
		$problems = array();
		foreach ( $this->nav_elements() as $element ) {
			foreach ( self::menu_refs( $element->post_content ) as $ref ) {
				$term = self::menu_term( $ref );
				if ( ! $term ) {
					$problems[ 'menu:' . $ref ] = self::problem( $element, 'menu ' . $ref, __( 'A classic menu block shows a menu that does not exist.', 'wp-page-sync' ) );
					continue;
				}
				foreach ( self::menu_items( $term->term_id ) as $item ) {
					$problems = array_merge( $problems, self::item_problems( $item, $term ) );
				}
			}
		}
		return array_values( $problems );
	}

	/**
	 * Problems of one menu item, keyed for de-duplication.
	 *
	 * @param WP_Post $item Menu item.
	 * @param WP_Term $term Its menu.
	 * @return array[]
	 */
	private static function item_problems( WP_Post $item, WP_Term $term ) {
		$problems = array();
		$label    = $term->name . ': ' . ( '' !== $item->post_title ? $item->post_title : '#' . $item->ID );
		if ( 'post_type' === get_post_meta( $item->ID, '_menu_item_type', true ) ) {
			$target = get_post( (int) get_post_meta( $item->ID, '_menu_item_object_id', true ) );
			if ( ! $target || 'publish' !== $target->post_status ) {
				$problems[ 'target:' . $item->ID ] = self::problem( $item, $label, __( 'Menu item links to a page or post that does not exist or is not published.', 'wp-page-sync' ) );
			}
		}
		$overlay_id = (int) get_post_meta( $item->ID, '_gb_mega_menu', true );
		if ( $overlay_id ) {
			$overlay = get_post( $overlay_id );
			if ( ! $overlay || self::OVERLAY_TYPE !== $overlay->post_type || 'publish' !== $overlay->post_status || 'mega-menu' !== get_post_meta( $overlay_id, '_gb_overlay_type', true ) ) {
				$problems[ 'mega:' . $item->ID ] = self::problem( $item, $label, __( 'Menu item opens a mega menu overlay that does not exist, is not published or is not of the type "mega menu".', 'wp-page-sync' ) );
			}
		}
		return $problems;
	}

	/**
	 * One verifier error entry.
	 *
	 * @param WP_Post $post    Post the problem sits on.
	 * @param string  $detail  Detail column.
	 * @param string  $message Problem column.
	 * @return array
	 */
	private static function problem( WP_Post $post, $detail, $message ) {
		return array(
			'type'     => 'navigation',
			'post_id'  => $post->ID,
			'path'     => '' !== $post->post_name ? (string) get_page_uri( $post ) : '#' . $post->ID,
			'location' => 'navigation',
			'detail'   => $detail,
			'message'  => $message,
		);
	}
}
