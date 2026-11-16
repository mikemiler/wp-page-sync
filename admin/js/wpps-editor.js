/**
 * WP Page Sync editor notice.
 *
 * Warns while editing an existing post when a connected site holds a newer
 * copy, and offers a one-click pull followed by a reload. Loaded on post.php
 * only; it never touches the editor itself and never blocks it.
 *
 * @package WP_Page_Sync
 */

( function( $ ) {
	'use strict';

	/*
	 * English fallbacks for every localized string, same reasoning as in
	 * wpps-admin.js: a stripped or deferred wp_localize_script payload must
	 * degrade to readable English, never to empty buttons.
	 */
	var I18N_DEFAULTS = {
		titleNewer: 'A newer version exists on a connected site',
		titleDiverged: 'This post changed on both sides',
		intro: 'This post is out of sync with a connected site:',
		rowNewer: '%1$s has a newer version of this post. Pulling it replaces what you see in the editor with that version.',
		rowDiverged: 'This post changed here AND on %1$s since the last sync, so no side is simply newer. Pulling would discard the changes made here, which is a decision for the Sync tab, not for this notice.',
		pullReload: 'Pull now and reload',
		openSync: 'Open the Sync tab',
		dismiss: 'Continue editing',
		confirmPull: 'Pulling replaces this post with the version from the connected site and reloads the editor. Anything not saved here yet is lost. Continue?',
		working: 'Pulling…',
		error: 'The pull failed. Nothing was changed here.',
		pulledTitle: 'Pulled, with warnings',
		pulledIntro: 'The post was pulled, but the sync reported the following. Reload the editor to see the pulled version; saving the editor as it is would overwrite it.',
		reload: 'Reload now'
	};

	var STATUS_DEFAULTS = {
		peer_newer: 'Peer newer',
		diverged: 'Diverged'
	};

	var cfg = window.wppsEditor || {};
	var i18n = $.extend( {}, I18N_DEFAULTS, cfg.i18n || {} );
	var statuses = $.extend( {}, STATUS_DEFAULTS, cfg.statuses || {} );

	// Without the localized payload there is no nonce to sign the check with.
	// Stay silent rather than show a broken notice over someone's editor.
	if ( ! cfg.ajaxUrl || ! cfg.nonce || ! cfg.postId ) {
		return;
	}

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function responseMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return i18n.error;
	}

	// Fills the %1$s placeholders of a localized string.
	function sprintfArgs( template, args ) {
		return String( template ).replace( /%(\d+)\$s/g, function( placeholder, position ) {
			var value = args[ position - 1 ];
			return ( undefined === value ) ? placeholder : String( value );
		} );
	}

	// True while a pull request is in flight.
	var pulling = false;

	// True once a pull succeeded with warnings: the editor now shows stale
	// content, so the only way out is the reload button.
	var pulled = false;

	/**
	 * Locks or unlocks the modal for the duration of a pull.
	 *
	 * Locking is not cosmetic: a successful pull reloads the page, and that
	 * reload cannot be called off, so every way out has to stay shut until the
	 * request settles. Buttons get disabled, the Sync-tab link gets the core
	 * disabled look plus the click guard below, and closeModal() refuses to
	 * run, which also covers the backdrop click.
	 *
	 * @param {boolean} busy Whether a pull is in flight.
	 * @return {void}
	 */
	function setBusy( busy ) {
		var $overlay = $( '#wpps-editor-overlay' );

		pulling = busy;
		$overlay.find( 'button' ).prop( 'disabled', busy );
		$overlay.find( 'a' ).toggleClass( 'disabled', busy ).attr( 'aria-disabled', busy ? 'true' : 'false' );
	}

	function closeModal() {
		if ( pulling || pulled ) {
			return;
		}
		$( '#wpps-editor-overlay' ).remove();
	}

	function badge( status ) {
		return $( '<span>' )
			.addClass( 'wpps-badge wpps-badge-' + status )
			.text( statuses[ status ] || status );
	}

	/**
	 * Replaces the notice with the pull's warnings and a reload button.
	 *
	 * The reload waits for the user so the warnings can be read. The modal
	 * stays shut otherwise: dismissing it would leave the editor on the old
	 * content, and saving that would overwrite the version just pulled.
	 *
	 * @param {string[]} warnings Warning messages of the pull.
	 * @return {void}
	 */
	function showPullWarnings( warnings ) {
		var $modal = $( '#wpps-editor-overlay .wpps-modal' ).empty();
		var $list = $( '<ul>' );

		pulled = true;
		$.each( warnings, function( _, warning ) {
			$list.append( $( '<li>' ).text( warning ) );
		} );

		$modal.append( $( '<h2>' ).attr( 'id', 'wpps-editor-title' ).text( i18n.pulledTitle ) );
		$modal.append( $( '<p>' ).text( i18n.pulledIntro ) );
		$modal.append( $( '<div>' ).addClass( 'wpps-report-warnings' ).append( $list ) );
		$modal.append(
			$( '<p>' ).addClass( 'wpps-modal-actions' )
				.append( $( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-primary' ).attr( 'id', 'wpps-editor-reload' ).text( i18n.reload ) )
		);
	}

	/**
	 * Pulls one peer's copy and reloads the editor on success.
	 *
	 * The confirmation is unconditional. Asking only when the editor reports
	 * itself dirty would mean asking wp.data's isEditedPostDirty(), which is
	 * blind to classic meta-box changes (ACF and friends) and would therefore
	 * stay silent in exactly the case where the reload discards work nobody
	 * warned about. The browser's own unload guard is left alone; a second
	 * prompt is the cheaper price.
	 *
	 * A conflict (wpps_conflict) or any other error keeps the editor exactly
	 * as it is and only reports the message: nothing was written locally.
	 * A pull that succeeded with warnings shows them and reloads only when
	 * the user confirms (showPullWarnings()).
	 *
	 * @param {Object} data   Check payload (post_type, path).
	 * @param {string} peerId Peer to pull from.
	 * @return {void}
	 */
	function pullFromPeer( data, peerId ) {
		var $feedback = $( '#wpps-editor-feedback' );

		if ( ! window.confirm( i18n.confirmPull ) ) {
			return;
		}

		setBusy( true );
		$feedback.removeClass( 'wpps-feedback-error' ).addClass( 'wpps-feedback-ok' ).text( i18n.working );

		post( 'wpps_pull', {
			peer_id: peerId,
			post_type: data.post_type,
			path: data.path,
			force: 0
		} ).done( function( response ) {
			var warnings = ( response && response.success && response.data && response.data.warnings ) || [];

			if ( response && response.success && warnings.length ) {
				setBusy( false );
				showPullWarnings( warnings );
				return;
			}
			if ( response && response.success ) {
				// Unlock before the reload, not after: the reload fires
				// beforeunload, and the editor's unsaved-changes guard can turn
				// that into a "Leave site?" prompt. If the user cancels it, the
				// reload never happens and a still-locked modal would sit
				// unclosable over the editor. In the normal case the reload
				// wins the race and this is invisible.
				setBusy( false );
				window.location.reload();
				return;
			}
			$feedback.removeClass( 'wpps-feedback-ok' ).addClass( 'wpps-feedback-error' ).text( responseMessage( response ) );
			setBusy( false );
		} ).fail( function( xhr ) {
			var message = ( xhr && xhr.status ) ? i18n.error + ' (HTTP ' + xhr.status + ')' : i18n.error;

			$feedback.removeClass( 'wpps-feedback-ok' ).addClass( 'wpps-feedback-error' ).text( message );
			setBusy( false );
		} );
	}

	/**
	 * One row per reported peer.
	 *
	 * Per-row actions rather than one global button: pulling is a per-peer
	 * operation, and a mixed list (one peer newer, another diverged) can only
	 * offer the right action per peer this way.
	 *
	 * @param {Object} data Check payload.
	 * @param {Object} peer Peer entry (peer_id, name, status).
	 * @return {Object} The list item.
	 */
	function renderPeerRow( data, peer ) {
		var diverged = 'diverged' === peer.status;
		var $row = $( '<li>' ).addClass( 'wpps-editor-peer' );
		var $button;

		$row.append(
			$( '<span>' ).addClass( 'wpps-editor-peer-head' )
				.append( badge( peer.status ) )
				.append( $( '<strong>' ).text( peer.name ) )
		);
		$row.append(
			$( '<p>' ).text( sprintfArgs( diverged ? i18n.rowDiverged : i18n.rowNewer, [ peer.name ] ) )
		);

		if ( diverged ) {
			$row.append(
				$( '<a>' ).addClass( 'button' ).attr( 'href', cfg.syncUrl || '#' ).text( i18n.openSync )
			);
			return $row;
		}

		$button = $( '<button>' ).attr( 'type', 'button' ).addClass( 'button button-primary' ).text( i18n.pullReload );
		$button.on( 'click', function() {
			pullFromPeer( data, peer.peer_id );
		} );

		return $row.append( $button );
	}

	function renderModal( data ) {
		var peers = data.peers || [];
		var newer = $.grep( peers, function( peer ) {
			return 'peer_newer' === peer.status;
		} );
		var $list = $( '<ul>' ).addClass( 'wpps-editor-peers' );
		var $modal = $( '<div>' ).addClass( 'wpps-modal' ).attr( 'role', 'dialog' ).attr( 'aria-labelledby', 'wpps-editor-title' );

		$modal.append(
			$( '<h2>' ).attr( 'id', 'wpps-editor-title' ).text( newer.length ? i18n.titleNewer : i18n.titleDiverged )
		);
		$modal.append( $( '<p>' ).text( i18n.intro ) );

		$.each( peers, function( _, peer ) {
			$list.append( renderPeerRow( data, peer ) );
		} );
		$modal.append( $list );

		$modal.append(
			$( '<p>' ).addClass( 'wpps-modal-actions' )
				.append( $( '<button>' ).attr( 'type', 'button' ).addClass( 'button' ).attr( 'id', 'wpps-editor-dismiss' ).text( i18n.dismiss ) )
				.append( $( '<span>' ).attr( 'id', 'wpps-editor-feedback' ).addClass( 'wpps-feedback' ) )
		);

		$( 'body' ).append(
			$( '<div>' ).attr( 'id', 'wpps-editor-overlay' ).addClass( 'wpps-modal-overlay' ).append( $modal )
		);
	}

	$( document ).on( 'click', '#wpps-editor-dismiss', closeModal );
	$( document ).on( 'click', '#wpps-editor-reload', function() {
		window.location.reload();
	} );
	$( document ).on( 'click', '#wpps-editor-overlay', function( event ) {
		if ( event.target === this ) {
			closeModal();
		}
	} );
	// A link cannot be disabled through a property, so the lock has to cancel
	// the navigation itself.
	$( document ).on( 'click', '#wpps-editor-overlay a', function( event ) {
		if ( pulling ) {
			event.preventDefault();
		}
	} );

	/*
	 * Runs after the page has finished loading, and every failure path stays
	 * quiet: an unreachable, slow or erroring peer must leave the editor
	 * completely untouched.
	 */
	function runCheck() {
		post( 'wpps_editor_check', { post_id: cfg.postId } ).done( function( response ) {
			if ( response && response.success && response.data && ( response.data.peers || [] ).length ) {
				renderModal( response.data );
			}
		} );
	}

	if ( 'complete' === document.readyState ) {
		runCheck();
	} else {
		$( window ).on( 'load', runCheck );
	}
}( jQuery ) );
