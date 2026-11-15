<?php
/**
 * WP Page Sync bootstrap file.
 *
 * @package WP_Page_Sync
 *
 * @wordpress-plugin
 * Plugin Name:       WP Page Sync
 * Plugin URI:        https://github.com/mikemiler/wp-page-sync
 * Description:       Syncs pages and posts, including their media files and GenerateBlocks global styles, between two WordPress installs over a signed connection.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WP Mike
 * Author URI:        https://github.com/mikemiler
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-page-sync
 * Update URI:        https://github.com/mikemiler/wp-page-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'WPPS_VERSION', '1.2.0' );
define( 'WPPS_PLUGIN_FILE', __FILE__ );
define( 'WPPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPPS_REST_NS', 'wp-page-sync/v1' );

/*
 * Design values from the approved plan (§3). WPPS_MAX_FILE_BYTES is an upper
 * bound only; the effective limit is the server's post_max_size.
 */
define( 'WPPS_MAX_FILE_BYTES', 64 * 1024 * 1024 );
define( 'WPPS_TS_WINDOW', 300 );
define( 'WPPS_NONCE_TTL', 600 );
define( 'WPPS_KEY_TTL', 3600 );
define( 'WPPS_TIMEOUT_API', 30 );
define( 'WPPS_TIMEOUT_MEDIA', 60 );

/*
 * The editor check is the only outbound call that is not user-initiated: it
 * runs on every post.php load, once per connected peer, before anyone asked
 * for anything. A hanging peer would otherwise occupy a PHP worker for the
 * full WPPS_TIMEOUT_API per open editor, and the notice could still appear
 * half a minute into the user's work. The 8 seconds are a chosen value, not
 * derived from a measurement.
 */
define( 'WPPS_TIMEOUT_EDITOR_CHECK', 8 );
define( 'WPPS_LOG_MAX', 50 );

/**
 * Persists a plugin option and guarantees autoload "no" on first write.
 *
 * Because add_option() is a no-op when the option exists, the
 * update_option() fallback keeps the stored autoload flag untouched.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return void
 */
function wpps_save_option( $name, $value ) {
	if ( ! add_option( $name, $value, '', 'no' ) ) {
		update_option( $name, $value );
	}
}

/**
 * Activation hook: pre-create the plugin options with autoload "no".
 *
 * @return void
 */
function wpps_activate() {
	add_option( 'wpps_peers', array(), '', 'no' );
	add_option( 'wpps_pending_keys', array(), '', 'no' );
	add_option( 'wpps_log', array(), '', 'no' );
}
register_activation_hook( __FILE__, 'wpps_activate' );

require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-log.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-signer.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-peers.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-client.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-rewriter.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-exporter.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-importer.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-globals.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-verifier.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-sync.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-rest.php';
require_once WPPS_PLUGIN_DIR . 'includes/class-wpps-plugin.php';
require_once WPPS_PLUGIN_DIR . 'admin/class-wpps-admin.php';

/**
 * GitHub URL of the plugin repository used for updates.
 *
 * Update this if the repository is renamed or moved. The slug at the end must
 * match the plugin folder name (wp-page-sync).
 */
define( 'WPPS_UPDATE_REPO', 'https://github.com/mikemiler/wp-page-sync/' );

/**
 * Wire up GitHub based plugin updates via the Plugin Update Checker library.
 *
 * The plugin is not distributed through wordpress.org, so the native
 * wordpress.org update channel never sees it. PUC hooks into the same
 * WordPress update flow, polls the GitHub Releases API and serves the ZIP
 * asset attached to the latest release. See readme.txt for the changelog
 * shown in the "View details" dialog.
 *
 * @return void
 */
function wpps_init_updates() {
	require_once WPPS_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		WPPS_UPDATE_REPO,
		WPPS_PLUGIN_FILE,
		'wp-page-sync'
	);

	// Public repo: no authentication required. Prefer the ZIP asset attached to
	// each release over the auto-generated source archive so the extracted
	// folder structure stays correct.
	$update_checker->getVcsApi()->enableReleaseAssets();

	/*
	 * PUC defaults to the branch "master"; this repository's default branch is
	 * "main". Setting it keeps the GitHub release and tag detection strategies
	 * working AND makes the branch fallback resolve instead of 404ing.
	 */
	$update_checker->setBranch( 'main' );
}
add_action( 'init', 'wpps_init_updates' );

WPPS_Plugin::instance();
