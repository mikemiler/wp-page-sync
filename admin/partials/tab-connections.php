<?php
/**
 * Connections tab markup.
 *
 * Expects $peers (array of peer records keyed by peer id) in scope.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="card wpps-card">
	<h2><?php esc_html_e( 'Create connection key', 'wp-page-sync' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %d: key lifetime in minutes. */
			esc_html__( 'Create a key on this site and paste it on the site you want to connect. The key is shown once, can be used once, and expires after %d minutes.', 'wp-page-sync' ),
			(int) ( WPPS_KEY_TTL / MINUTE_IN_SECONDS )
		);
		?>
	</p>
	<p>
		<button type="button" class="button button-primary" id="wpps-create-key"><?php esc_html_e( 'Create connection key', 'wp-page-sync' ); ?></button>
		<span id="wpps-create-feedback" class="wpps-feedback"></span>
	</p>
	<div id="wpps-key-result" class="wpps-hidden">
		<label class="screen-reader-text" for="wpps-key-blob"><?php esc_html_e( 'Connection key', 'wp-page-sync' ); ?></label>
		<input type="text" id="wpps-key-blob" class="large-text code" readonly>
		<p>
			<button type="button" class="button" id="wpps-copy-key"><?php esc_html_e( 'Copy key', 'wp-page-sync' ); ?></button>
			<span id="wpps-key-feedback" class="wpps-feedback"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Transfer the key over a secure channel: anyone holding it can claim the pairing.', 'wp-page-sync' ); ?></p>
	</div>
</div>

<div class="card wpps-card">
	<h2><?php esc_html_e( 'Connect to a site', 'wp-page-sync' ); ?></h2>
	<p><?php esc_html_e( 'Paste a connection key created on the other site.', 'wp-page-sync' ); ?></p>
	<p>
		<label class="screen-reader-text" for="wpps-connect-key"><?php esc_html_e( 'Connection key', 'wp-page-sync' ); ?></label>
		<input type="text" id="wpps-connect-key" class="large-text code" placeholder="<?php esc_attr_e( 'Paste connection key', 'wp-page-sync' ); ?>">
	</p>
	<p>
		<label for="wpps-connect-auth-user"><?php esc_html_e( 'Basic Auth username', 'wp-page-sync' ); ?></label><br>
		<input type="text" id="wpps-connect-auth-user" class="regular-text" autocomplete="off">
	</p>
	<p>
		<label for="wpps-connect-auth-pass"><?php esc_html_e( 'Basic Auth password', 'wp-page-sync' ); ?></label><br>
		<input type="password" id="wpps-connect-auth-pass" class="regular-text" autocomplete="new-password">
	</p>
	<p class="description"><?php esc_html_e( 'Only needed if the other site is protected by HTTP Basic Auth (browser password prompt). In that case, create the connection key on the protected site and connect here. To change the credentials later, edit the connection\'s Basic Auth under Connected sites.', 'wp-page-sync' ); ?></p>
	<p>
		<button type="button" class="button button-primary" id="wpps-connect"><?php esc_html_e( 'Connect', 'wp-page-sync' ); ?></button>
		<span id="wpps-connect-feedback" class="wpps-feedback"></span>
	</p>
</div>

<h2><?php esc_html_e( 'Connected sites', 'wp-page-sync' ); ?></h2>
<p class="description"><?php esc_html_e( '"Allow incoming" controls whether a peer may write to THIS site (receive pushes). It defaults to on only for local sites; on dev/production, enable it explicitly per peer.', 'wp-page-sync' ); ?></p>
<?php if ( empty( $peers ) ) : ?>
	<p><?php esc_html_e( 'No connected sites yet.', 'wp-page-sync' ); ?></p>
<?php else : ?>
	<table class="widefat striped wpps-peers-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'URL', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Key', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Last seen', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Allow incoming', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Basic Auth', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-page-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $peers as $peer ) : ?>
				<?php
				if ( empty( $peer['last_seen'] ) ) {
					$last_seen = __( 'Never', 'wp-page-sync' );
				} else {
					/* translators: %s: human-readable time difference. */
					$last_seen = sprintf( __( '%s ago', 'wp-page-sync' ), human_time_diff( (int) $peer['last_seen'] ) );
				}
				$auth_id = 'wpps-auth-' . $peer['peer_id'];
				?>
				<tr data-peer-id="<?php echo esc_attr( $peer['peer_id'] ); ?>">
					<td><?php echo esc_html( $peer['name'] ); ?></td>
					<td><a href="<?php echo esc_url( $peer['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $peer['url'] ); ?></a></td>
					<td><code><?php echo esc_html( substr( (string) $peer['peer_id'], 0, 4 ) ); ?>&hellip;</code></td>
					<td><?php echo esc_html( $last_seen ); ?></td>
					<td>
						<label>
							<input type="checkbox" class="wpps-toggle-incoming" <?php checked( ! empty( $peer['allow_incoming'] ) ); ?>>
							<span class="screen-reader-text"><?php esc_html_e( 'Allow incoming sync from this peer', 'wp-page-sync' ); ?></span>
						</label>
					</td>
					<td>
						<span class="wpps-basic-auth-state"><?php echo esc_html( WPPS_Admin::basic_auth_label( $peer ) ); ?></span>
						<div class="wpps-basic-auth-form wpps-hidden">
							<p>
								<label for="<?php echo esc_attr( $auth_id . '-user' ); ?>"><?php esc_html_e( 'Basic Auth username', 'wp-page-sync' ); ?></label><br>
								<input type="text" id="<?php echo esc_attr( $auth_id . '-user' ); ?>" class="regular-text wpps-auth-user" autocomplete="off">
							</p>
							<p>
								<label for="<?php echo esc_attr( $auth_id . '-pass' ); ?>"><?php esc_html_e( 'Basic Auth password', 'wp-page-sync' ); ?></label><br>
								<input type="password" id="<?php echo esc_attr( $auth_id . '-pass' ); ?>" class="regular-text wpps-auth-pass" autocomplete="new-password">
							</p>
							<p class="description"><?php esc_html_e( 'Both filled: set or replace the credentials. Both empty: remove them.', 'wp-page-sync' ); ?></p>
							<p>
								<button type="button" class="button button-primary wpps-auth-save"><?php esc_html_e( 'Save', 'wp-page-sync' ); ?></button>
								<span class="wpps-feedback wpps-auth-feedback"></span>
							</p>
						</div>
					</td>
					<td>
						<button type="button" class="button wpps-ping"><?php esc_html_e( 'Ping', 'wp-page-sync' ); ?></button>
						<button type="button" class="button wpps-basic-auth-toggle" aria-expanded="false"><?php esc_html_e( 'Basic Auth', 'wp-page-sync' ); ?></button>
						<button type="button" class="button wpps-disconnect"><?php esc_html_e( 'Disconnect', 'wp-page-sync' ); ?></button>
						<span class="wpps-feedback wpps-row-feedback"></span>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
