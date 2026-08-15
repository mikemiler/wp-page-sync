<?php
/**
 * Log tab markup.
 *
 * Expects $entries (log entries, newest first) in scope.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<p>
	<button type="button" class="button" id="wpps-log-refresh"><?php esc_html_e( 'Refresh', 'wp-page-sync' ); ?></button>
	<span id="wpps-log-feedback" class="wpps-feedback"></span>
</p>
<table class="widefat striped wpps-log-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Time', 'wp-page-sync' ); ?></th>
			<th><?php esc_html_e( 'Type', 'wp-page-sync' ); ?></th>
			<th><?php esc_html_e( 'Message', 'wp-page-sync' ); ?></th>
		</tr>
	</thead>
	<tbody id="wpps-log-body">
		<?php if ( empty( $entries ) ) : ?>
			<tr><td colspan="3"><?php esc_html_e( 'No operations logged yet.', 'wp-page-sync' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
					<td><code><?php echo esc_html( $entry['type'] ); ?></code></td>
					<td><?php echo esc_html( $entry['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>
