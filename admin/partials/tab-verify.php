<?php
/**
 * Verify tab markup.
 *
 * Expects $peers (array of peer records keyed by peer id) in scope.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="card wpps-card">
	<h2><?php esc_html_e( 'Verify', 'wp-page-sync' ); ?></h2>
	<p><?php esc_html_e( 'Scans pages and posts for foreign environment URLs, missing upload files, broken internal links and undefined global classes.', 'wp-page-sync' ); ?></p>
	<p>
		<button type="button" class="button button-primary wpps-verify-run" data-target="self"><?php esc_html_e( 'Verify this site', 'wp-page-sync' ); ?></button>
		<?php foreach ( $peers as $peer ) : ?>
			<button type="button" class="button wpps-verify-run" data-target="<?php echo esc_attr( $peer['peer_id'] ); ?>">
				<?php
				/* translators: %s: peer name. */
				echo esc_html( sprintf( __( 'Verify %s', 'wp-page-sync' ), $peer['name'] ? $peer['name'] : $peer['url'] ) );
				?>
			</button>
		<?php endforeach; ?>
		<span id="wpps-verify-feedback" class="wpps-feedback"></span>
	</p>
</div>

<div id="wpps-verify-results"></div>
