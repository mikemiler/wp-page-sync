<?php
/**
 * Sync tab markup.
 *
 * Expects $peers (array of peer records keyed by peer id) in scope.
 *
 * @package WP_Page_Sync
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( empty( $peers ) ) : ?>
	<p><?php esc_html_e( 'Connect a site first (Connections tab).', 'wp-page-sync' ); ?></p>
<?php else : ?>
	<div class="wpps-toolbar">
		<label for="wpps-peer-select"><?php esc_html_e( 'Peer', 'wp-page-sync' ); ?></label>
		<select id="wpps-peer-select">
			<?php foreach ( $peers as $peer ) : ?>
				<option value="<?php echo esc_attr( $peer['peer_id'] ); ?>"><?php echo esc_html( $peer['name'] ? $peer['name'] : $peer['url'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="wpps-post-type-select"><?php esc_html_e( 'Post type', 'wp-page-sync' ); ?></label>
		<select id="wpps-post-type-select">
			<option value="page"><?php esc_html_e( 'Pages', 'wp-page-sync' ); ?></option>
			<option value="post"><?php esc_html_e( 'Posts', 'wp-page-sync' ); ?></option>
		</select>
		<button type="button" class="button" id="wpps-load-status"><?php esc_html_e( 'Load status', 'wp-page-sync' ); ?></button>
		<span id="wpps-sync-feedback" class="wpps-feedback"></span>
	</div>

	<div class="card wpps-card" id="wpps-globals-card">
		<h2><?php esc_html_e( 'Global styles', 'wp-page-sync' ); ?></h2>
		<p><?php esc_html_e( 'GenerateBlocks Pro global classes and the GeneratePress color palette. Target-only classes are never deleted.', 'wp-page-sync' ); ?></p>
		<p>
			<span id="wpps-globals-chip" class="wpps-badge wpps-badge-unknown"><?php esc_html_e( 'Unknown', 'wp-page-sync' ); ?></span>
			<button type="button" class="button wpps-globals-preview" data-direction="push"><?php esc_html_e( 'Push…', 'wp-page-sync' ); ?></button>
			<button type="button" class="button wpps-globals-preview" data-direction="pull"><?php esc_html_e( 'Pull…', 'wp-page-sync' ); ?></button>
			<span id="wpps-globals-feedback" class="wpps-feedback"></span>
		</p>
	</div>

	<h2><?php esc_html_e( 'Posts on this site', 'wp-page-sync' ); ?></h2>
	<div class="wpps-toolbar">
		<label for="wpps-filter-status"><?php esc_html_e( 'Status', 'wp-page-sync' ); ?></label>
		<?php /* The status options are filled from the loaded rows (wpps-admin.js), so the list never offers a status this table cannot produce. */ ?>
		<select id="wpps-filter-status">
			<option value=""><?php esc_html_e( 'All statuses', 'wp-page-sync' ); ?></option>
		</select>
		<label for="wpps-filter-search" class="screen-reader-text"><?php esc_html_e( 'Search posts', 'wp-page-sync' ); ?></label>
		<input type="search" id="wpps-filter-search" placeholder="<?php esc_attr_e( 'Search title or path', 'wp-page-sync' ); ?>">
		<span id="wpps-filter-count" class="wpps-filter-count"></span>
	</div>
	<div class="wpps-bulkbar">
		<button type="button" class="button" id="wpps-bulk-push" disabled><?php esc_html_e( 'Push selected (parents first)', 'wp-page-sync' ); ?></button>
		<span id="wpps-bulk-feedback" class="wpps-feedback"></span>
	</div>
	<div id="wpps-bulk-results"></div>
	<table class="widefat striped wpps-posts-table">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" id="wpps-check-all"></td>
				<th><?php esc_html_e( 'Title', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Path', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Modified (GMT)', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-page-sync' ); ?></th>
			</tr>
		</thead>
		<tbody id="wpps-posts-body">
			<tr><td colspan="6"><?php esc_html_e( 'Click "Load status" to compare this site with the peer.', 'wp-page-sync' ); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Posts only on the peer', 'wp-page-sync' ); ?></h2>
	<p>
		<button type="button" class="button" id="wpps-load-peer-posts"><?php esc_html_e( 'Load peer list', 'wp-page-sync' ); ?></button>
		<span id="wpps-peer-posts-feedback" class="wpps-feedback"></span>
	</p>
	<table class="widefat striped wpps-peer-posts-table wpps-hidden">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Path', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Peer status', 'wp-page-sync' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-page-sync' ); ?></th>
			</tr>
		</thead>
		<tbody id="wpps-peer-posts-body"></tbody>
	</table>

	<div id="wpps-modal-overlay" class="wpps-modal-overlay wpps-hidden">
		<div class="wpps-modal" role="dialog" aria-labelledby="wpps-modal-title">
			<h2 id="wpps-modal-title"></h2>
			<div id="wpps-modal-body"></div>
			<p id="wpps-modal-force-row" class="wpps-hidden">
				<label>
					<input type="checkbox" id="wpps-modal-force">
					<span id="wpps-modal-force-label"></span>
				</label>
			</p>
			<p class="wpps-modal-actions">
				<button type="button" class="button" id="wpps-modal-cancel"><?php esc_html_e( 'Cancel', 'wp-page-sync' ); ?></button>
				<button type="button" class="button button-primary" id="wpps-modal-confirm"><?php esc_html_e( 'Confirm', 'wp-page-sync' ); ?></button>
			</p>
			<?php /* Progress and result of the running action. Below the buttons, not beside them, so a long message reads as a block; it is a live region because it reports the outcome of an action the user cannot see otherwise. */ ?>
			<div id="wpps-modal-feedback" class="wpps-feedback wpps-modal-status" role="status" aria-live="polite"></div>
		</div>
	</div>
<?php endif; ?>
