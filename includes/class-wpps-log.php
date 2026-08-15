<?php
/**
 * Ring-buffer operation log.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last WPPS_LOG_MAX operations in a non-autoloaded option.
 */
class WPPS_Log {

	const OPTION = 'wpps_log';

	/**
	 * Appends a log entry, trimming the buffer to WPPS_LOG_MAX entries.
	 *
	 * @param string $type    Short machine-readable entry type, e.g. "connected".
	 * @param string $message Human-readable message.
	 * @return void
	 */
	public static function add( $type, $message ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => time(),
			'type'    => (string) $type,
			'message' => (string) $message,
		);

		$count = count( $log );
		if ( $count > WPPS_LOG_MAX ) {
			$log = array_slice( $log, $count - WPPS_LOG_MAX );
		}

		wpps_save_option( self::OPTION, $log );
	}

	/**
	 * Returns all stored log entries, oldest first.
	 *
	 * @return array[]
	 */
	public static function get_entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
