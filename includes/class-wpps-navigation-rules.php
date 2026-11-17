<?php
/**
 * Allowlist for the navigation unit (1.7.0 security review).
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * What may travel in the navigation unit, checked identically on the
 * sender (pre-flight), on the receiver (bundle posts) and against the
 * receiver's existing post of the same path, plus hard caps and the HTML
 * safety check for everything the import stores.
 *
 * Why so strict: GP Premium block elements are hooked with
 * add_action( $hook ) (gp-premium/elements/class-block.php) and hook
 * elements with "Execute PHP" eval their content (class-hooks.php). A peer
 * that may write must never be able to place content on an arbitrary hook
 * or replace an unrelated element, so only header, navigation and footer
 * output of the kind the unit consists of is accepted.
 */
class WPPS_Navigation_Rules {

	/**
	 * GP's "Execute PHP" switch (hook elements eval their content with it).
	 *
	 * @var string
	 */
	const PHP_HOOK_KEY = '_generate_hook_execute_php';

	/**
	 * Allowed _generate_block_type values (site-header/site-footer render on
	 * generate_header/generate_footer, class-block.php).
	 *
	 * @var string[]
	 */
	const BLOCK_TYPES = array( 'hook', 'site-header', 'site-footer' );

	/**
	 * GeneratePress header, navigation and footer ACTION hooks a "hook" block
	 * element may use: GP Premium's own hook list (class-elements-helper.php
	 * get_available_hooks(), groups header/navigation/footer plus the flexbox
	 * navigation hooks), each verified as a do_action() in GeneratePress or
	 * GP Premium, none a filter.
	 *
	 * @var string[]
	 */
	const NAV_HOOKS = array(
		'generate_before_header',
		'generate_after_header',
		'generate_before_header_content',
		'generate_after_header_content',
		'generate_before_logo',
		'generate_after_logo',
		'generate_header',
		'generate_inside_navigation',
		'generate_after_primary_menu',
		'generate_inside_secondary_navigation',
		'generate_inside_mobile_menu',
		'generate_inside_mobile_menu_bar',
		'generate_inside_mobile_header',
		'generate_inside_slideout_navigation',
		'generate_after_slideout_navigation',
		'generate_menu_bar_items',
		'generate_before_navigation',
		'generate_after_navigation',
		'generate_after_mobile_menu_button',
		'generate_inside_mobile_menu_control_wrapper',
		'generate_before_footer',
		'generate_after_footer',
		'generate_after_footer_widgets',
		'generate_before_footer_content',
		'generate_after_footer_content',
		'generate_footer',
	);

	/*
	 * Hard caps (chosen values, not derived from a measurement): far above a
	 * real site navigation, low enough to bound the work of one request.
	 */
	const MAX_MENUS            = 20;
	const MAX_ITEMS            = 300;
	const MAX_POSTS            = 20;
	const MAX_CONTENT_BYTES    = 524288;
	const MAX_META_KEYS        = 100;
	const MAX_META_VALUE_BYTES = 65536;
	const MAX_CONDITION_RULES  = 50;

	/**
	 * Tags never stored by the navigation import. SCRIPT is handled apart
	 * (receiver-approved external scripts only); STYLE is refused because
	 * GenerateBlocks content does not need it (none in LIVE's unit). The SVG
	 * animation tags can set href to javascript: through to/values/from,
	 * which the URL attribute check does not cover. get_tag() is uppercase,
	 * also inside <svg>, so the match is case-insensitive.
	 *
	 * @var string[]
	 */
	const BLOCKED_TAGS = array(
		'IFRAME',
		'OBJECT',
		'EMBED',
		'BASE',
		'META',
		'FRAME',
		'FRAMESET',
		'LINK',
		'STYLE',
		'ANIMATE',
		'SET',
		'ANIMATEMOTION',
		'ANIMATETRANSFORM',
		// Raw-text/escapable elements whose content a browser parses differently (security review R3).
		'NOSCRIPT',
		'XMP',
		'NOEMBED',
		'NOFRAMES',
		'PLAINTEXT',
		'TEXTAREA',
	);

	/**
	 * Attributes a receiver-approved external script may carry (data-* too).
	 *
	 * @var string[]
	 */
	const SCRIPT_ATTRIBUTES = array( 'src', 'async', 'defer', 'id', 'type', 'charset', 'crossorigin' );

	/**
	 * Core's block delimiter pattern (WP_Block_Parser::next_token()).
	 *
	 * @var string
	 */
	const BLOCK_DELIMITER = '<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->';

	/**
	 * A media manifest source_url: no quotes, angle brackets, whitespace,
	 * backslash or control characters (UTF-8 letters allowed).
	 *
	 * @var string
	 */
	const SOURCE_URL_PATTERN = '~^https?://[A-Za-z0-9._\~%!$&()*+,;=:@/?#\[\]\x80-\xFF-]+$~D';

	/**
	 * Attributes holding a URL (srcset: every candidate).
	 *
	 * @var string[]
	 */
	const URL_ATTRIBUTES = array( 'href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'data', 'srcset' );

	const CLASSIC_MENU = 'wp:generateblocks-pro/classic-menu';

	/**
	 * Rule view of a stored post.
	 *
	 * @param WP_Post $post Overlay or element.
	 * @return array {slug, path, parent_path, title, excerpt, password, content, meta}
	 */
	public static function stored_view( WP_Post $post ) {
		return array(
			'slug'        => (string) $post->post_name,
			'path'        => (string) get_page_uri( $post ),
			'parent_path' => $post->post_parent ? (string) get_page_uri( $post->post_parent ) : '',
			'title'       => (string) $post->post_title,
			'excerpt'     => (string) $post->post_excerpt,
			'password'    => (string) $post->post_password,
			'content'     => (string) $post->post_content,
			'meta'        => array_map(
				function ( $values ) {
					return maybe_unserialize( $values[0] );
				},
				(array) get_post_meta( $post->ID )
			),
		);
	}

	/**
	 * Rule view of a bundle post.
	 *
	 * @param array $post Post bundle.
	 * @return array {slug, path, parent_path, title, excerpt, password, content, meta}
	 */
	public static function bundle_view( array $post ) {
		return array(
			'slug'        => (string) ( $post['post']['slug'] ?? '' ),
			'path'        => (string) ( $post['post']['path'] ?? '' ),
			'parent_path' => (string) ( $post['post']['parent_path'] ?? '' ),
			'title'       => (string) ( $post['post']['title'] ?? '' ),
			'excerpt'     => (string) ( $post['post']['excerpt'] ?? '' ),
			'password'    => is_string( $post['post']['password'] ?? null ) ? $post['post']['password'] : '',
			'content'     => (string) ( $post['post']['content'] ?? '' ),
			'meta'        => ( isset( $post['meta'] ) && is_array( $post['meta'] ) ) ? $post['meta'] : array(),
		);
	}

	/**
	 * Why a post cannot take its role in the unit; empty = allowed.
	 *
	 * @param string        $role         overlay|element.
	 * @param array         $view         stored_view() or bundle_view(); the content is what will be stored.
	 * @param string[]|null $script_hosts Hosts external scripts may come from (receiver); null = scripts are
	 *                                    not judged here (sender pre-flight: the receiver decides).
	 * @return string[] Reasons.
	 */
	public static function post_errors( $role, array $view, $script_hosts ) {
		$meta    = array_change_key_case( $view['meta'], CASE_LOWER );
		$reasons = array_merge( self::common_errors( $view, $meta ), self::size_errors( $view ), self::html_errors( $view['content'], $script_hosts ) );
		return array_merge( $reasons, 'element' === $role ? self::element_errors( $view['content'], $meta ) : self::overlay_errors( $view['content'], $meta ) );
	}

	/**
	 * Rules for both roles.
	 *
	 * @param array $view Rule view.
	 * @param array $meta Meta with lowercased keys.
	 * @return string[]
	 */
	private static function common_errors( array $view, array $meta ) {
		$reasons = array();
		$keys    = array_map( 'strval', array_keys( $view['meta'] ) );
		if ( array_map( 'strtolower', $keys ) !== $keys ) {
			$reasons[] = __( 'its meta keys are not all lowercase', 'wp-page-sync' );
		}
		if ( array_key_exists( self::PHP_HOOK_KEY, $meta ) ) {
			$reasons[] = __( 'it carries the GP Premium setting "Execute PHP"', 'wp-page-sync' );
		}
		if ( '' !== $view['parent_path'] ) {
			$reasons[] = __( 'it has a parent post', 'wp-page-sync' );
		}
		$segments = explode( '/', $view['path'] );
		if ( end( $segments ) !== $view['slug'] ) {
			$reasons[] = __( 'its path does not end with its slug', 'wp-page-sync' );
		}
		if ( wp_strip_all_tags( $view['title'] ) !== $view['title'] || wp_strip_all_tags( $view['excerpt'] ) !== $view['excerpt'] ) {
			$reasons[] = __( 'its title or excerpt contains markup', 'wp-page-sync' );
		}
		if ( '' !== $view['password'] ) {
			$reasons[] = __( 'it is password protected', 'wp-page-sync' );
		}
		return $reasons;
	}

	/**
	 * Size caps of one unit post (content, meta key count, meta value size,
	 * display rules).
	 *
	 * @param array $view Rule view.
	 * @return string[]
	 */
	private static function size_errors( array $view ) {
		$too = strlen( $view['content'] ) > self::MAX_CONTENT_BYTES || count( $view['meta'] ) > self::MAX_META_KEYS;
		foreach ( $view['meta'] as $key => $value ) {
			$too = $too || strlen( (string) maybe_serialize( $value ) ) > self::MAX_META_VALUE_BYTES
				|| ( in_array( strtolower( (string) $key ), WPPS_Navigation::CONDITION_KEYS, true ) && is_array( $value ) && count( $value ) > self::MAX_CONDITION_RULES );
		}
		if ( ! $too ) {
			return array();
		}
		/* translators: 1: content KB, 2: meta keys, 3: meta value KB, 4: display rules. */
		return array( sprintf( __( 'it is larger than the sync allows (content %1$d KB, %2$d meta keys, %3$d KB per meta value, %4$d display rules)', 'wp-page-sync' ), self::MAX_CONTENT_BYTES / 1024, self::MAX_META_KEYS, self::MAX_META_VALUE_BYTES / 1024, self::MAX_CONDITION_RULES ) );
	}

	/**
	 * Why HTML must not be stored. Anything ambiguous is refused instead of
	 * interpreted: incomplete HTML, block comments the HTML tokenizer does not
	 * see as comments, blocked tags, scripts other than receiver-approved
	 * external ones, event handler attributes, URLs with a scheme WordPress
	 * does not allow. The content is also checked once more with all block
	 * delimiters removed (what a renderer of the parsed blocks sees).
	 *
	 * @param string        $html         HTML as it will be stored.
	 * @param string[]|null $script_hosts Allowed external script hosts; null = do not judge scripts.
	 * @return string[]
	 */
	public static function html_errors( $html, $script_hosts ) {
		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			return array( __( 'the navigation sync requires WordPress 6.5 or later', 'wp-page-sync' ) );
		}
		$html = (string) $html;
		if ( false !== stripos( $html, '<![CDATA[' ) ) {
			// CDATA is parsed differently inside and outside SVG/MathML: ambiguous, refused.
			return array( __( 'it contains a CDATA section', 'wp-page-sync' ) );
		}
		$reasons = self::token_errors( $html, $script_hosts );
		if ( ! $reasons && false !== strpos( $html, '<!--' ) ) {
			$plain   = self::without_delimiters( parse_blocks( $html ) );
			$reasons = ( $plain !== $html ) ? self::token_errors( $plain, $script_hosts ) : array();
		}
		return $reasons;
	}

	/**
	 * One tokenizer pass: per-tag rules, incomplete input, delimiter count.
	 *
	 * @param string        $html         HTML.
	 * @param string[]|null $script_hosts Allowed script hosts or null.
	 * @return string[]
	 */
	private static function token_errors( $html, $script_hosts ) {
		$reasons    = array();
		$delimiters = 0;
		$tags       = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_token() ) {
			$type = $tags->get_token_type();
			if ( '#comment' === $type ) {
				$delimiters += preg_match( '/^' . self::BLOCK_DELIMITER . '$/sD', '<!--' . $tags->get_modifiable_text() . '-->' );
			} elseif ( '#tag' === $type && ! $tags->is_tag_closer() ) {
				$reasons += self::tag_errors( $tags, $script_hosts );
			}
		}
		if ( $tags->paused_at_incomplete_token() ) {
			$reasons['incomplete'] = __( 'it contains incomplete HTML', 'wp-page-sync' );
		}
		if ( (int) preg_match_all( '/' . self::BLOCK_DELIMITER . '/s', $html ) !== $delimiters ) {
			$reasons['delimiters'] = __( 'its block comments do not match its HTML structure', 'wp-page-sync' );
		}
		return array_values( $reasons );
	}

	/**
	 * Rules for the tag the processor stands on, keyed for de-duplication.
	 *
	 * @param WP_HTML_Tag_Processor $tags         Processor at an opening tag.
	 * @param string[]|null         $script_hosts Allowed script hosts or null.
	 * @return array<string,string>
	 */
	private static function tag_errors( WP_HTML_Tag_Processor $tags, $script_hosts ) {
		$reasons = array();
		$name    = (string) $tags->get_tag();
		if ( in_array( $name, self::BLOCKED_TAGS, true ) ) {
			/* translators: %s: HTML tag name. */
			$reasons[ $name ] = sprintf( __( 'it contains the HTML tag <%s>', 'wp-page-sync' ), strtolower( $name ) );
		}
		if ( 'TITLE' === $name && false !== strpos( $tags->get_modifiable_text(), '<' ) ) {
			$reasons['title'] = __( 'it contains a <title> with markup inside', 'wp-page-sync' );
		}
		if ( 'SCRIPT' === $name && null !== $script_hosts && ! self::script_ok( $tags, $script_hosts ) ) {
			$reasons['script'] = __( 'it contains a script that is not an external https script from a host this site allows (WPPS_NAV_SCRIPT_HOSTS)', 'wp-page-sync' );
		}
		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $attribute ) {
			if ( 0 === strpos( $attribute, 'on' ) ) {
				$reasons['on'] = __( 'it contains an inline event handler attribute', 'wp-page-sync' );
			} elseif ( in_array( $attribute, self::URL_ATTRIBUTES, true ) && ! self::url_ok( $attribute, $tags->get_attribute( $attribute ) ) ) {
				$reasons['url'] = __( 'it contains a URL with a scheme WordPress does not allow (such as javascript:)', 'wp-page-sync' );
			}
		}
		return $reasons;
	}

	/**
	 * A script allowed on the receiver: only harmless attributes, not
	 * self-closing, no inline body, src exactly https://<listed host>/ plus
	 * plain URL characters (no port, no @, no backslash, no whitespace).
	 *
	 * @param WP_HTML_Tag_Processor $tags         Processor at the SCRIPT tag.
	 * @param string[]              $script_hosts Allowed hosts (lowercase).
	 * @return bool
	 */
	private static function script_ok( WP_HTML_Tag_Processor $tags, array $script_hosts ) {
		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $attribute ) {
			if ( ! in_array( $attribute, self::SCRIPT_ATTRIBUTES, true ) && 0 !== strpos( $attribute, 'data-' ) ) {
				return false;
			}
		}
		$src = $tags->get_attribute( 'src' );
		if ( ! $script_hosts || ! is_string( $src ) || $tags->has_self_closing_flag() || '' !== trim( $tags->get_modifiable_text() ) ) {
			return false;
		}
		$hosts = implode(
			'|',
			array_map(
				function ( $host ) {
					return preg_quote( $host, '~' );
				},
				$script_hosts
			)
		);
		return (bool) preg_match( '~^https://(?:' . $hosts . ')/[A-Za-z0-9._\~%!$&\'()*+,;=:/?#-]*$~D', $src );
	}

	/**
	 * Whether every URL of a URL attribute has an allowed scheme or none
	 * (srcset: every candidate).
	 *
	 * @param string      $attribute Attribute name.
	 * @param string|bool $value     Decoded value (true for a bare attribute).
	 * @return bool
	 */
	private static function url_ok( $attribute, $value ) {
		if ( ! is_string( $value ) ) {
			return true;
		}
		$urls = 'srcset' === $attribute ? array_map( 'trim', explode( ',', $value ) ) : array( $value );
		foreach ( $urls as $url ) {
			if ( ! self::scheme_ok( 'srcset' === $attribute ? (string) preg_replace( '/\s.*$/s', '', $url ) : $url ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Scheme test on the entity-decoded URL with ASCII whitespace and
	 * control characters removed: no ':' before the first '/', '?' or '#'
	 * means relative (ok); otherwise the scheme must be one WordPress allows,
	 * data: never. A value that is exactly one GenerateBlocks dynamic tag is
	 * ok; anything else containing {{ keeps the scheme rule.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function scheme_ok( $url ) {
		// Exactly one GenerateBlocks dynamic tag ({{featured_image key:url}}), resolved at render time by GB 2.4+.
		$dynamic_tags = defined( 'GENERATEBLOCKS_VERSION' ) && version_compare( GENERATEBLOCKS_VERSION, '2.4.0', '>=' );
		if ( $dynamic_tags && preg_match( '/^\{\{[a-z_]+(\s[^{}]*)?\}\}$/', trim( $url ) ) ) {
			return true;
		}
		$test = (string) preg_replace( '/[\x00-\x20\x7F]+/', '', html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( ! preg_match( '~^([^:/?#]*):~', $test, $match ) ) {
			return true;
		}
		$scheme = strtolower( $match[1] );
		return 'data' !== $scheme && in_array( $scheme, wp_allowed_protocols(), true );
	}

	/**
	 * Content without block delimiters: the concatenated innerContent of the
	 * parsed blocks, inner blocks in place.
	 *
	 * @param array[] $blocks parse_blocks() result.
	 * @return string
	 */
	private static function without_delimiters( array $blocks ) {
		$html = '';
		foreach ( $blocks as $block ) {
			$inner = 0;
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				$html .= is_string( $chunk ) ? $chunk : self::without_delimiters( array( $block['innerBlocks'][ $inner++ ] ?? array() ) );
			}
		}
		return $html;
	}

	/**
	 * Whether every media manifest source_url of a bundle (the navigation
	 * bundle's and each post bundle's) is a plain http(s) URL.
	 *
	 * @param array $bundle Navigation bundle.
	 * @return bool
	 */
	public static function source_urls_ok( array $bundle ) {
		$manifests = array( $bundle['media_manifest'] ?? array() );
		foreach ( ( isset( $bundle['posts'] ) && is_array( $bundle['posts'] ) ) ? $bundle['posts'] : array() as $post ) {
			$manifests[] = is_array( $post ) ? ( $post['media_manifest'] ?? array() ) : array();
		}
		foreach ( $manifests as $manifest ) {
			foreach ( is_array( $manifest ) ? $manifest : array( null ) as $entry ) {
				if ( ! is_array( $entry ) || ! is_string( $entry['source_url'] ?? null ) || ! preg_match( self::SOURCE_URL_PATTERN, $entry['source_url'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Hosts this site accepts external scripts from (wp-config.php constant
	 * WPPS_NAV_SCRIPT_HOSTS); never taken from a bundle.
	 *
	 * @return string[]
	 */
	public static function script_hosts() {
		if ( ! defined( 'WPPS_NAV_SCRIPT_HOSTS' ) || ! is_array( WPPS_NAV_SCRIPT_HOSTS ) ) {
			return array();
		}
		$hosts = array_map( 'strtolower', array_map( 'trim', array_filter( WPPS_NAV_SCRIPT_HOSTS, 'is_string' ) ) );
		// Plain host names with at least one dot; anything else is ignored.
		return array_values(
			array_filter(
				$hosts,
				function ( $host ) {
					return (bool) preg_match( '/^[a-z0-9.-]+$/D', $host ) && false !== strpos( $host, '.' );
				}
			)
		);
	}

	/**
	 * Element rules: a GP block element showing a classic menu, rendered as
	 * site header, site footer or on a GP header/navigation/footer action.
	 *
	 * @param string $content Content.
	 * @param array  $meta    Meta with lowercased keys.
	 * @return string[]
	 */
	private static function element_errors( $content, array $meta ) {
		$reasons = array();
		$type    = self::text( $meta, '_generate_block_type' );
		$hook    = self::text( $meta, '_generate_hook' );
		if ( 'block' !== self::text( $meta, '_generate_element_type' ) || false === strpos( $content, self::CLASSIC_MENU ) ) {
			$reasons[] = __( 'it is not a GP block element that shows a GenerateBlocks Pro classic menu', 'wp-page-sync' );
		}
		if ( ! in_array( $type, self::BLOCK_TYPES, true ) ) {
			/* translators: %s: GP block element type. */
			$reasons[] = sprintf( __( 'its element type "%s" is not a site header, site footer or hook', 'wp-page-sync' ), $type );
		} elseif ( 'hook' === $type && ! in_array( $hook, self::NAV_HOOKS, true ) ) {
			/* translators: %s: hook name. */
			$reasons[] = sprintf( __( 'it is placed on "%s", which is not a GeneratePress header, navigation or footer action', 'wp-page-sync' ), $hook );
		}
		if ( '' !== self::text( $meta, '_generate_custom_hook' ) ) {
			$reasons[] = __( 'it uses a custom hook', 'wp-page-sync' );
		}
		$priority = self::text( $meta, '_generate_hook_priority' );
		if ( '' !== $priority && ! ctype_digit( $priority ) ) {
			$reasons[] = __( 'its hook priority is not a number', 'wp-page-sync' );
		}
		if ( ! self::conditions_ok( $meta ) ) {
			$reasons[] = __( 'its display rules do not have the expected shape', 'wp-page-sync' );
		}
		return $reasons;
	}

	/**
	 * Overlay rules: a mega menu without a classic menu of its own and
	 * without a display condition.
	 *
	 * @param string $content Content.
	 * @param array  $meta    Meta with lowercased keys.
	 * @return string[]
	 */
	private static function overlay_errors( $content, array $meta ) {
		$reasons = array();
		if ( 'mega-menu' !== self::text( $meta, '_gb_overlay_type' ) ) {
			$reasons[] = __( 'it is not a mega menu overlay', 'wp-page-sync' );
		}
		if ( false !== strpos( $content, self::CLASSIC_MENU ) ) {
			$reasons[] = __( 'it contains a classic menu block itself', 'wp-page-sync' );
		}
		if ( '' !== self::text( $meta, '_gb_overlay_display_condition' ) ) {
			$reasons[] = __( 'it has a display condition, which the navigation sync does not carry', 'wp-page-sync' );
		}
		return $reasons;
	}

	/**
	 * A meta value as string; any non-scalar becomes a value no rule accepts.
	 *
	 * @param array  $meta Meta.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function text( array $meta, $key ) {
		if ( ! array_key_exists( $key, $meta ) || null === $meta[ $key ] ) {
			return '';
		}
		return is_scalar( $meta[ $key ] ) ? (string) $meta[ $key ] : "\0";
	}

	/**
	 * Shape of GP's rule meta: display/exclude rules are lists of
	 * {rule: string, object: scalar} (the bundle adds object_ref), user rules
	 * a list of strings.
	 *
	 * @param array $meta Meta with lowercased keys.
	 * @return bool
	 */
	private static function conditions_ok( array $meta ) {
		foreach ( WPPS_Navigation::CONDITION_KEYS as $key ) {
			$rules = $meta[ $key ] ?? '';
			if ( '' === $rules ) {
				continue;
			}
			if ( ! is_array( $rules ) ) {
				return false;
			}
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ! is_string( $rule['rule'] ?? null ) || ( isset( $rule['object'] ) && ! is_scalar( $rule['object'] ) )
					|| ( isset( $rule['object_ref'] ) && ! is_array( $rule['object_ref'] ) ) || array_diff( array_keys( $rule ), array( 'rule', 'object', 'object_ref' ) ) ) {
					return false;
				}
			}
		}
		$users = $meta['_generate_element_user_conditions'] ?? '';
		return '' === $users || ( is_array( $users ) && count( array_filter( $users, 'is_string' ) ) === count( $users ) );
	}

	/**
	 * Hard-cap violations of a bundle (menus, items per menu, posts).
	 *
	 * @param array $bundle Navigation bundle.
	 * @return string[]
	 */
	public static function cap_errors( array $bundle ) {
		$menus  = ( isset( $bundle['menus'] ) && is_array( $bundle['menus'] ) ) ? $bundle['menus'] : array();
		$posts  = ( isset( $bundle['posts'] ) && is_array( $bundle['posts'] ) ) ? $bundle['posts'] : array();
		$errors = array();
		$too    = count( $menus ) > self::MAX_MENUS || count( $posts ) > self::MAX_POSTS;
		foreach ( $menus as $menu ) {
			$too = $too || ( isset( $menu['items'] ) && is_array( $menu['items'] ) && count( $menu['items'] ) > self::MAX_ITEMS );
		}
		if ( $too ) {
			/* translators: 1: menus, 2: items per menu, 3: overlays and elements. */
			$errors[] = sprintf( __( 'The navigation is larger than the sync allows (at most %1$d menus, %2$d items per menu, %3$d overlays and elements).', 'wp-page-sync' ), self::MAX_MENUS, self::MAX_ITEMS, self::MAX_POSTS );
		}
		return $errors;
	}
}
