<?php
/**
 * Uninstall cleanup: removes all wpps_* options, the per-peer sync state and
 * media checksum postmeta, the uploads/wpps-tmp staging folder and, best
 * effort, the replay-protection nonce transients (they expire on their own
 * otherwise; with an external object cache they live outside the options
 * table and simply expire there).
 *
 * @package WP_Page_Sync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpps_peers' );
delete_option( 'wpps_pending_keys' );
delete_option( 'wpps_log' );

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time uninstall cleanup; there is no core API for LIKE-deleting transients or postmeta.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wpps_nonce_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wpps_nonce_' ) . '%'
	)
);

// Per-peer sync state (_wpps_state_<peer id>) on posts and the imported-file
// checksum (_wpps_sha256) on attachments.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key = %s",
		$wpdb->esc_like( '_wpps_state_' ) . '%',
		'_wpps_sha256'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery

// Staging folder for files in transit: only the files the plugin creates
// there (*.part plus the two guard files), and the folder only once empty.
$wpps_temp_dir = wp_upload_dir( null, false )['basedir'] . '/wpps-tmp';
if ( is_dir( $wpps_temp_dir ) ) {
	$wpps_temp_files = array_merge( (array) glob( $wpps_temp_dir . '/*.part' ), array( $wpps_temp_dir . '/.htaccess', $wpps_temp_dir . '/index.html' ) );
	foreach ( $wpps_temp_files as $wpps_temp_file ) {
		if ( is_string( $wpps_temp_file ) && is_file( $wpps_temp_file ) ) {
			wp_delete_file( $wpps_temp_file );
		}
	}
	if ( array( '.', '..' ) === array_values( (array) scandir( $wpps_temp_dir ) ) ) {
		rmdir( $wpps_temp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own staging folder once it is empty.
	}
}
