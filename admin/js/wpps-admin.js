/**
 * WP Page Sync admin (Connections tab).
 *
 * @package WP_Page_Sync
 */

( function( $ ) {
	'use strict';

	/*
	 * English fallbacks for every localized string. The UI must never render
	 * silent-empty because wp_localize_script data was stripped or deferred
	 * by another plugin (this is exactly the noisy-install environment the
	 * plugin lives in); missing keys fall back to these literals.
	 */
	var I18N_DEFAULTS = {
		copied: 'Key copied to clipboard.',
		copyFailed: 'Copy failed. Select the key and copy it manually.',
		connecting: 'Connecting…',
		keyRequired: 'Please paste a connection key.',
		pinging: 'Pinging…',
		confirmDisconnect: 'Disconnect this site? Sync operations with it will stop working.',
		error: 'Request failed. Please try again.',
		loading: 'Loading…',
		noPeer: 'Connect a site first (Connections tab).',
		noPosts: 'No posts found.',
		push: 'Push',
		pull: 'Pull',
		pushTitle: 'Push to peer',
		pullTitle: 'Pull from peer',
		confirm: 'Confirm',
		working: 'Working…',
		done: 'Done.',
		mediaFiles: 'Referenced media files:',
		mediaTransfer: 'To transfer:',
		forceLabel: 'Force: the copy on the target differs from this one and will be overwritten. Its current content stays available there as a post revision.',
		preflightErrors: 'Blocking problems (fix at the source first):',
		warnings: 'Warnings:',
		bulkConfirm: 'Push the selected posts (parents first)? Conflicting posts are skipped.',
		bulkNone: 'Select at least one post.',
		bulkDone: 'Bulk push finished:',
		pushed: 'pushed',
		skipped: 'skipped',
		failed: 'failed',
		bulkSkipped: 'Skipped as conflicts (push each one on its own and confirm the force option):',
		bulkFailed: 'Failed:',
		globalsAdded: 'Added classes',
		globalsChanged: 'Changed classes',
		globalsOnlyTarget: 'Only on target (kept, never deleted)',
		colorsChanged: 'Color changes',
		globalsInSync: 'Global styles are in sync.',
		globalsDiffer: 'Global styles differ.',
		globalsUnknown: 'Load the status to compare global styles.',
		verifyRunning: 'Verifying…',
		verifyClean: 'No problems found.',
		verifyErrors: 'Errors',
		verifyWarnings: 'Warnings',
		colPost: 'Post',
		colLocation: 'Location',
		colDetail: 'Detail',
		colProblem: 'Problem',
		scannedOf: 'Scanned %1$s of %2$s posts.',
		peerOnlyEmpty: 'No posts exist only on the peer.',
		existsLocal: 'Also exists here',
		peerOnly: 'Only on peer',
		missingConfig: 'WP Page Sync: the script configuration (wppsAdmin) failed to load, so sync actions cannot run. Another plugin is probably deferring or stripping inline scripts in wp-admin. Reload the page; if it persists, exclude wp-admin from script optimization.'
	};

	var STATUS_DEFAULTS = {
		in_sync: 'In sync',
		in_sync_adopted: 'In sync (adopted)',
		local_newer: 'Local newer',
		peer_newer: 'Peer newer',
		diverged: 'Diverged',
		never_synced: 'Differs (never synced)',
		missing_on_peer: 'Missing on peer',
		missing_local: 'New from peer',
		no_slug: 'No slug (unsyncable)'
	};

	var raw = window.wppsAdmin || {};
	var cfg = $.extend( {}, raw, {
		i18n: $.extend( {}, I18N_DEFAULTS, raw.i18n || {} ),
		statuses: $.extend( {}, STATUS_DEFAULTS, raw.statuses || {} )
	} );

	// Missing localize data means no ajaxUrl/nonce: say so visibly instead
	// of a half-working UI.
	$( function() {
		if ( ! window.wppsAdmin && $( '.wpps-wrap' ).length ) {
			$( '.wpps-wrap' ).prepend(
				$( '<div>' ).addClass( 'notice notice-error' ).append( $( '<p>' ).text( cfg.i18n.missingConfig ) )
			);
		}
	} );

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function feedback( $el, message, ok ) {
		$el.text( message || '' )
			.toggleClass( 'wpps-feedback-ok', !! ok && !! message )
			.toggleClass( 'wpps-feedback-error', ! ok && !! message );
	}

	function responseMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return cfg.i18n.error;
	}

	// Transport-failure message; names the HTTP status when there is one.
	function failMessage( xhr ) {
		if ( xhr && xhr.status ) {
			return cfg.i18n.error + ' (HTTP ' + xhr.status + ')';
		}
		return cfg.i18n.error;
	}

	function rowPeerId( el ) {
		return $( el ).closest( 'tr' ).attr( 'data-peer-id' );
	}

	// Create a pairing key.
	$( document ).on( 'click', '#wpps-create-key', function() {
		var $button = $( this );
		var $fb = $( '#wpps-create-feedback' );

		$button.prop( 'disabled', true );
		feedback( $fb, '', true );

		post( 'wpps_create_key' ).done( function( response ) {
			if ( response && response.success && response.data ) {
				$( '#wpps-key-blob' ).val( response.data.blob );
				$( '#wpps-key-result' ).removeClass( 'wpps-hidden' );
				feedback( $( '#wpps-key-feedback' ), '', true );
			} else {
				feedback( $fb, responseMessage( response ), false );
			}
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} ).always( function() {
			$button.prop( 'disabled', false );
		} );
	} );

	// Select the whole key on focus.
	$( document ).on( 'focus', '#wpps-key-blob', function() {
		this.select();
	} );

	// Copy the key to the clipboard (with execCommand fallback for http).
	$( document ).on( 'click', '#wpps-copy-key', function() {
		var input = $( '#wpps-key-blob' ).get( 0 );
		var $fb = $( '#wpps-key-feedback' );

		function fallbackCopy() {
			input.select();
			try {
				document.execCommand( 'copy' );
				feedback( $fb, cfg.i18n.copied, true );
			} catch ( e ) {
				feedback( $fb, cfg.i18n.copyFailed, false );
			}
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( input.value ).then( function() {
				feedback( $fb, cfg.i18n.copied, true );
			}, fallbackCopy );
		} else {
			fallbackCopy();
		}
	} );

	// Connect with a pasted key.
	$( document ).on( 'click', '#wpps-connect', function() {
		var $button = $( this );
		var $fb = $( '#wpps-connect-feedback' );
		var key = $.trim( $( '#wpps-connect-key' ).val() || '' );

		if ( ! key ) {
			feedback( $fb, cfg.i18n.keyRequired, false );
			return;
		}

		$button.prop( 'disabled', true );
		feedback( $fb, cfg.i18n.connecting, true );

		post( 'wpps_connect', { key: key } ).done( function( response ) {
			if ( response.success ) {
				feedback( $fb, responseMessage( response ), true );
				window.setTimeout( function() {
					window.location.reload();
				}, 800 );
			} else {
				feedback( $fb, responseMessage( response ), false );
				$button.prop( 'disabled', false );
			}
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
			$button.prop( 'disabled', false );
		} );
	} );

	// Ping a peer.
	$( document ).on( 'click', '.wpps-ping', function() {
		var $button = $( this );
		var $fb = $button.closest( 'tr' ).find( '.wpps-row-feedback' );

		$button.prop( 'disabled', true );
		feedback( $fb, cfg.i18n.pinging, true );

		post( 'wpps_ping', { peer_id: rowPeerId( this ) } ).done( function( response ) {
			feedback( $fb, responseMessage( response ), !! response.success );
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} ).always( function() {
			$button.prop( 'disabled', false );
		} );
	} );

	// Disconnect a peer.
	$( document ).on( 'click', '.wpps-disconnect', function() {
		var $button = $( this );
		var $fb = $button.closest( 'tr' ).find( '.wpps-row-feedback' );

		if ( ! window.confirm( cfg.i18n.confirmDisconnect ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		post( 'wpps_disconnect', { peer_id: rowPeerId( this ) } ).done( function( response ) {
			if ( response.success ) {
				window.location.reload();
			} else {
				feedback( $fb, responseMessage( response ), false );
				$button.prop( 'disabled', false );
			}
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
			$button.prop( 'disabled', false );
		} );
	} );

	// Toggle allow-incoming.
	$( document ).on( 'change', '.wpps-toggle-incoming', function() {
		var $checkbox = $( this );
		var $fb = $checkbox.closest( 'tr' ).find( '.wpps-row-feedback' );
		var allow = $checkbox.is( ':checked' ) ? 1 : 0;

		post( 'wpps_toggle_incoming', { peer_id: rowPeerId( this ), allow: allow } ).done( function( response ) {
			if ( response.success ) {
				feedback( $fb, responseMessage( response ), true );
			} else {
				$checkbox.prop( 'checked', ! allow );
				feedback( $fb, responseMessage( response ), false );
			}
		} ).fail( function( xhr ) {
			$checkbox.prop( 'checked', ! allow );
			feedback( $fb, failMessage( xhr ), false );
		} );
	} );

	/* ------------------------------------------------------------------ *
	 * Sync tab
	 * ------------------------------------------------------------------ */

	var modalConfirm = null;

	function currentPeer() {
		return $( '#wpps-peer-select' ).val() || '';
	}

	function currentPostType() {
		return $( '#wpps-post-type-select' ).val() || 'page';
	}

	function statusLabel( status ) {
		return ( cfg.statuses && cfg.statuses[ status ] ) || status;
	}

	function badge( status ) {
		return $( '<span>' )
			.addClass( 'wpps-badge wpps-badge-' + status )
			.text( statusLabel( status ) );
	}

	function formatBytes( bytes ) {
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( bytes >= 1024 ) {
			return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
		}
		return bytes + ' B';
	}

	function sprintf2( template, a, b ) {
		return String( template ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function listBlock( heading, items, cssClass ) {
		var $wrap = $( '<div>' ).addClass( cssClass || '' );
		$wrap.append( $( '<strong>' ).text( heading ) );
		var $ul = $( '<ul>' );
		$.each( items, function( _, item ) {
			$ul.append( $( '<li>' ).text( item ) );
		} );
		return $wrap.append( $ul );
	}

	function openModal( title ) {
		modalConfirm = null;
		$( '#wpps-modal-title' ).text( title || cfg.i18n.loading );
		$( '#wpps-modal-body' ).empty().append( $( '<p>' ).text( cfg.i18n.loading ) );
		$( '#wpps-modal-force-row' ).addClass( 'wpps-hidden' );
		$( '#wpps-modal-force' ).prop( 'checked', false );
		$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		feedback( $( '#wpps-modal-feedback' ), '', true );
		$( '#wpps-modal-overlay' ).removeClass( 'wpps-hidden' );
	}

	function closeModal() {
		modalConfirm = null;
		$( '#wpps-modal-overlay' ).addClass( 'wpps-hidden' );
	}

	$( document ).on( 'click', '#wpps-modal-cancel', closeModal );
	$( document ).on( 'click', '#wpps-modal-overlay', function( event ) {
		if ( event.target === this ) {
			closeModal();
		}
	} );
	$( document ).on( 'click', '#wpps-modal-confirm', function() {
		if ( modalConfirm ) {
			modalConfirm();
		}
	} );
	$( document ).on( 'change', '#wpps-modal-force', function() {
		if ( ! $( '#wpps-modal-force-row' ).hasClass( 'wpps-hidden' ) ) {
			$( '#wpps-modal-confirm' ).prop( 'disabled', ! $( this ).is( ':checked' ) );
		}
	} );

	function renderStatusRow( item ) {
		var $tr = $( '<tr>' )
			.attr( 'data-path', item.path )
			.attr( 'data-post-type', item.post_type );
		var unsyncable = 'no_slug' === item.sync_status;

		$tr.append( $( '<td>' ).addClass( 'check-column' ).append(
			$( '<input>' ).attr( 'type', 'checkbox' ).addClass( 'wpps-row-check' ).prop( 'disabled', unsyncable )
		) );
		$tr.append( $( '<td>' ).text( item.title || '(' + item.status + ')' ) );
		$tr.append( $( '<td>' ).append( $( '<code>' ).text( item.path || '—' ) ) );
		$tr.append( $( '<td>' ).text( item.modified_gmt || '' ) );
		$tr.append( $( '<td>' ).append( badge( item.sync_status ) ) );

		var $actions = $( '<td>' );
		$actions.append(
			$( '<button>' ).attr( 'type', 'button' ).addClass( 'button wpps-row-push' )
				.prop( 'disabled', unsyncable ).text( cfg.i18n.push )
		);
		$actions.append( ' ' );
		$actions.append(
			$( '<button>' ).attr( 'type', 'button' ).addClass( 'button wpps-row-pull' )
				.prop( 'disabled', unsyncable || ! item.peer ).text( cfg.i18n.pull )
		);
		$tr.append( $actions );

		return $tr;
	}

	function updateGlobalsChip( globals ) {
		var $chip = $( '#wpps-globals-chip' );
		$chip.removeClass( 'wpps-badge-unknown wpps-badge-in_sync wpps-badge-diverged' );
		if ( ! globals || ! globals.peer_hash ) {
			$chip.addClass( 'wpps-badge-unknown' ).text( cfg.i18n.globalsUnknown );
		} else if ( globals.in_sync ) {
			$chip.addClass( 'wpps-badge-in_sync' ).text( cfg.i18n.globalsInSync );
		} else {
			$chip.addClass( 'wpps-badge-diverged' ).text( cfg.i18n.globalsDiffer );
		}
	}

	function loadStatus() {
		var $fb = $( '#wpps-sync-feedback' );
		var $body = $( '#wpps-posts-body' );

		if ( ! currentPeer() ) {
			feedback( $fb, cfg.i18n.noPeer, false );
			return;
		}
		feedback( $fb, cfg.i18n.loading, true );

		post( 'wpps_status_list', { peer_id: currentPeer(), post_type: currentPostType() } ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				feedback( $fb, responseMessage( response ), false );
				return;
			}
			response.data.items = response.data.items || [];
			feedback( $fb, '', true );
			$body.empty();
			updateGlobalsChip( response.data.globals );
			if ( ! response.data.items.length ) {
				$body.append( $( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 6 ).text( cfg.i18n.noPosts ) ) );
				return;
			}
			$.each( response.data.items, function( _, item ) {
				$body.append( renderStatusRow( item ) );
			} );
			updateBulkButton();
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} );
	}

	$( document ).on( 'click', '#wpps-load-status', loadStatus );
	$( document ).on( 'change', '#wpps-peer-select, #wpps-post-type-select', function() {
		$( '#wpps-posts-body' ).empty();
		$( '#wpps-bulk-results' ).empty();
		updateGlobalsChip( null );
	} );

	function renderPreview( data ) {
		var $modalBody = $( '#wpps-modal-body' ).empty();
		var media = data.media || {};
		var blocked = data.preflight && data.preflight.errors && data.preflight.errors.length > 0;

		$modalBody.append(
			$( '<p>' )
				.append( $( '<strong>' ).text( ( data.title || '' ) + ' ' ) )
				.append( $( '<code>' ).text( data.path || '' ) )
		);
		$modalBody.append( $( '<p>' ).append( badge( data.status ) ) );
		$modalBody.append(
			$( '<p>' ).text(
				cfg.i18n.mediaFiles + ' ' + ( media.files || 0 ) + ' · ' +
				cfg.i18n.mediaTransfer + ' ' + ( media.transfer_files || 0 ) +
				' (' + formatBytes( media.transfer_bytes || 0 ) + ')'
			)
		);
		if ( blocked ) {
			$modalBody.append( listBlock( cfg.i18n.preflightErrors, data.preflight.errors, 'wpps-report-errors' ) );
		}
		if ( data.preflight && data.preflight.warnings && data.preflight.warnings.length ) {
			$modalBody.append( listBlock( cfg.i18n.warnings, data.preflight.warnings, 'wpps-report-warnings' ) );
		}

		// A blocked preview can never be forced: keep the force row hidden so
		// the checkbox cannot re-enable the confirm button.
		if ( data.force_required && ! blocked ) {
			$( '#wpps-modal-force-label' ).text( cfg.i18n.forceLabel );
			$( '#wpps-modal-force-row' ).removeClass( 'wpps-hidden' );
			$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		} else {
			$( '#wpps-modal-confirm' ).prop( 'disabled', blocked );
		}
	}

	function executeSync( direction, postType, path ) {
		var $fb = $( '#wpps-modal-feedback' );
		var force = $( '#wpps-modal-force' ).is( ':checked' ) ? 1 : 0;

		$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		feedback( $fb, cfg.i18n.working, true );

		post( 'push' === direction ? 'wpps_push' : 'wpps_pull', {
			peer_id: currentPeer(),
			post_type: postType,
			path: path,
			force: force
		} ).done( function( response ) {
			if ( response && response.success ) {
				var warnings = ( response.data && response.data.warnings ) || [];
				if ( warnings.length ) {
					$( '#wpps-modal-body' ).empty().append( listBlock( cfg.i18n.warnings, warnings, 'wpps-report-warnings' ) );
					feedback( $fb, cfg.i18n.done, true );
					modalConfirm = null;
				} else {
					closeModal();
				}
				loadStatus();
			} else {
				feedback( $fb, responseMessage( response ), false );
				$( '#wpps-modal-confirm' ).prop( 'disabled', false );
			}
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
			$( '#wpps-modal-confirm' ).prop( 'disabled', false );
		} );
	}

	function openSyncPreview( direction, postType, path ) {
		openModal( ( 'push' === direction ? cfg.i18n.pushTitle : cfg.i18n.pullTitle ) + ' · ' + path );

		post( 'wpps_sync_preview', {
			peer_id: currentPeer(),
			post_type: postType,
			path: path,
			direction: direction
		} ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				$( '#wpps-modal-body' ).empty().append( $( '<p>' ).addClass( 'wpps-feedback-error' ).text( responseMessage( response ) ) );
				return;
			}
			renderPreview( response.data );
			modalConfirm = function() {
				executeSync( direction, postType, path );
			};
		} ).fail( function( xhr ) {
			$( '#wpps-modal-body' ).empty().append( $( '<p>' ).addClass( 'wpps-feedback-error' ).text( failMessage( xhr ) ) );
		} );
	}

	$( document ).on( 'click', '.wpps-row-push, .wpps-row-pull', function() {
		var $tr = $( this ).closest( 'tr' );
		openSyncPreview(
			$( this ).hasClass( 'wpps-row-push' ) ? 'push' : 'pull',
			$tr.attr( 'data-post-type' ),
			$tr.attr( 'data-path' )
		);
	} );

	/* Bulk push: parents first (path depth, then alphabetical). */

	function updateBulkButton() {
		$( '#wpps-bulk-push' ).prop( 'disabled', 0 === $( '.wpps-row-check:checked' ).length );
	}

	$( document ).on( 'change', '.wpps-row-check', updateBulkButton );
	$( document ).on( 'change', '#wpps-check-all', function() {
		$( '.wpps-row-check:not(:disabled)' ).prop( 'checked', $( this ).is( ':checked' ) );
		updateBulkButton();
	} );

	$( document ).on( 'click', '#wpps-bulk-push', function() {
		var $fb = $( '#wpps-bulk-feedback' );
		var $results = $( '#wpps-bulk-results' ).empty();
		var queue = [];

		$( '.wpps-row-check:checked' ).each( function() {
			var $tr = $( this ).closest( 'tr' );
			queue.push( { post_type: $tr.attr( 'data-post-type' ), path: $tr.attr( 'data-path' ) } );
		} );
		if ( ! queue.length ) {
			feedback( $fb, cfg.i18n.bulkNone, false );
			return;
		}
		if ( ! window.confirm( cfg.i18n.bulkConfirm ) ) {
			return;
		}

		queue.sort( function( a, b ) {
			var depth = a.path.split( '/' ).length - b.path.split( '/' ).length;
			return 0 !== depth ? depth : a.path.localeCompare( b.path );
		} );

		var pushed = 0;
		var skipped = [];
		var failed = [];
		var $button = $( this ).prop( 'disabled', true );

		// A row the peer rejected for a missing force confirmation is a skip,
		// not a failure: nothing was written and the user can still push it
		// individually. Everything else is a real failure.
		function report() {
			var summary = cfg.i18n.bulkDone + ' ' +
				pushed + ' ' + cfg.i18n.pushed + ', ' +
				skipped.length + ' ' + cfg.i18n.skipped + ', ' +
				failed.length + ' ' + cfg.i18n.failed;

			feedback( $fb, summary, 0 === skipped.length && 0 === failed.length );
			if ( skipped.length ) {
				$results.append( listBlock( cfg.i18n.bulkSkipped, skipped, 'wpps-report-warnings' ) );
			}
			if ( failed.length ) {
				$results.append( listBlock( cfg.i18n.bulkFailed, failed, 'wpps-report-errors' ) );
			}
		}

		function next() {
			if ( ! queue.length ) {
				report();
				$button.prop( 'disabled', false );
				loadStatus();
				return;
			}
			var item = queue.shift();
			feedback( $fb, cfg.i18n.working + ' ' + item.path, true );
			post( 'wpps_push', { peer_id: currentPeer(), post_type: item.post_type, path: item.path, force: 0 } )
				.done( function( response ) {
					if ( response && response.success ) {
						pushed++;
					} else if ( response && response.data && 'wpps_conflict' === response.data.code ) {
						skipped.push( item.path + ': ' + responseMessage( response ) );
					} else {
						failed.push( item.path + ': ' + responseMessage( response ) );
					}
				} )
				.fail( function( xhr ) {
					failed.push( item.path + ': ' + failMessage( xhr ) );
				} )
				.always( next );
		}
		next();
	} );

	/* Pull list: posts that exist only on the peer. */

	$( document ).on( 'click', '#wpps-load-peer-posts', function() {
		var $fb = $( '#wpps-peer-posts-feedback' );
		var $table = $( '.wpps-peer-posts-table' );
		var $body = $( '#wpps-peer-posts-body' );

		if ( ! currentPeer() ) {
			feedback( $fb, cfg.i18n.noPeer, false );
			return;
		}
		feedback( $fb, cfg.i18n.loading, true );

		post( 'wpps_peer_posts', { peer_id: currentPeer(), post_type: currentPostType() } ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				feedback( $fb, responseMessage( response ), false );
				return;
			}
			feedback( $fb, '', true );
			$body.empty();
			$table.removeClass( 'wpps-hidden' );

			var peerOnly = $.grep( response.data.items || [], function( item ) {
				return ! item.local_exists && item.path;
			} );
			if ( ! peerOnly.length ) {
				$body.append( $( '<tr>' ).append( $( '<td>' ).attr( 'colspan', 4 ).text( cfg.i18n.peerOnlyEmpty ) ) );
				return;
			}
			$.each( peerOnly, function( _, item ) {
				var $tr = $( '<tr>' ).attr( 'data-path', item.path ).attr( 'data-post-type', item.post_type );
				$tr.append( $( '<td>' ).text( item.title || '' ) );
				$tr.append( $( '<td>' ).append( $( '<code>' ).text( item.path ) ) );
				$tr.append( $( '<td>' ).text( item.status || '' ) );
				$tr.append( $( '<td>' ).append(
					$( '<button>' ).attr( 'type', 'button' ).addClass( 'button wpps-row-pull' ).text( cfg.i18n.pull )
				) );
				$body.append( $tr );
			} );
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} );
	} );

	/* Globals card. */

	function renderGlobalsPreview( data ) {
		var $modalBody = $( '#wpps-modal-body' ).empty();
		var added = data.added || [];
		var changed = data.changed || [];
		var onlyTarget = data.only_on_target || [];
		var colors = $.extend( { added: [], removed: [], changed: [] }, data.colors || {} );
		var media = data.media || {};
		var blocked = data.preflight && data.preflight.errors && data.preflight.errors.length > 0;

		if ( data.in_sync ) {
			$modalBody.append( $( '<p>' ).text( cfg.i18n.globalsInSync ) );
		}
		if ( added.length ) {
			$modalBody.append( listBlock( cfg.i18n.globalsAdded + ' (' + added.length + ')', added ) );
		}
		if ( changed.length ) {
			$modalBody.append( listBlock( cfg.i18n.globalsChanged + ' (' + changed.length + ')', changed ) );
		}
		if ( onlyTarget.length ) {
			$modalBody.append( listBlock( cfg.i18n.globalsOnlyTarget + ' (' + onlyTarget.length + ')', onlyTarget ) );
		}
		if ( colors.changed.length || colors.added.length || colors.removed.length ) {
			var colorLines = [];
			$.each( colors.changed, function( _, change ) {
				colorLines.push( change.slug + ': ' + change.from + ' → ' + change.to );
			} );
			$.each( colors.added, function( _, slug ) {
				colorLines.push( slug + ' (+)' );
			} );
			$.each( colors.removed, function( _, slug ) {
				colorLines.push( slug + ' (−)' );
			} );
			$modalBody.append( listBlock( cfg.i18n.colorsChanged, colorLines ) );
		}
		$modalBody.append(
			$( '<p>' ).text( cfg.i18n.mediaTransfer + ' ' + ( media.transfer_files || 0 ) + ' (' + formatBytes( media.transfer_bytes || 0 ) + ')' )
		);
		if ( blocked ) {
			$modalBody.append( listBlock( cfg.i18n.preflightErrors, data.preflight.errors, 'wpps-report-errors' ) );
		}

		$( '#wpps-modal-confirm' ).prop( 'disabled', blocked );
	}

	$( document ).on( 'click', '.wpps-globals-preview', function() {
		var direction = $( this ).attr( 'data-direction' );
		var $fb = $( '#wpps-globals-feedback' );

		if ( ! currentPeer() ) {
			feedback( $fb, cfg.i18n.noPeer, false );
			return;
		}
		openModal( ( 'push' === direction ? cfg.i18n.pushTitle : cfg.i18n.pullTitle ) + ' · Global styles' );

		post( 'wpps_globals_preview', { peer_id: currentPeer(), direction: direction } ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				$( '#wpps-modal-body' ).empty().append( $( '<p>' ).addClass( 'wpps-feedback-error' ).text( responseMessage( response ) ) );
				return;
			}
			renderGlobalsPreview( response.data );
			modalConfirm = function() {
				var $modalFb = $( '#wpps-modal-feedback' );
				$( '#wpps-modal-confirm' ).prop( 'disabled', true );
				feedback( $modalFb, cfg.i18n.working, true );
				post( 'wpps_globals_sync', { peer_id: currentPeer(), direction: direction } ).done( function( syncResponse ) {
					if ( syncResponse && syncResponse.success ) {
						var warnings = ( syncResponse.data && syncResponse.data.warnings ) || [];
						if ( warnings.length ) {
							$( '#wpps-modal-body' ).empty().append( listBlock( cfg.i18n.warnings, warnings, 'wpps-report-warnings' ) );
							feedback( $modalFb, cfg.i18n.done, true );
							modalConfirm = null;
						} else {
							closeModal();
						}
						loadStatus();
					} else {
						feedback( $modalFb, responseMessage( syncResponse ), false );
						$( '#wpps-modal-confirm' ).prop( 'disabled', false );
					}
				} ).fail( function( xhr ) {
					feedback( $modalFb, failMessage( xhr ), false );
					$( '#wpps-modal-confirm' ).prop( 'disabled', false );
				} );
			};
		} ).fail( function( xhr ) {
			$( '#wpps-modal-body' ).empty().append( $( '<p>' ).addClass( 'wpps-feedback-error' ).text( failMessage( xhr ) ) );
		} );
	} );

	/* ------------------------------------------------------------------ *
	 * Verify tab (batched loop)
	 * ------------------------------------------------------------------ */

	function renderVerifyGroup( $container, heading, entries, cssClass ) {
		if ( ! entries.length ) {
			return;
		}
		$container.append( $( '<h3>' ).text( heading + ' (' + entries.length + ')' ) );
		var $table = $( '<table>' ).addClass( 'widefat striped wpps-report-table ' + cssClass );
		var $head = $( '<tr>' );
		$.each( [ cfg.i18n.colPost, cfg.i18n.colLocation, cfg.i18n.colDetail, cfg.i18n.colProblem ], function( _, label ) {
			$head.append( $( '<th>' ).text( label ) );
		} );
		$table.append( $( '<thead>' ).append( $head ) );
		var $tbody = $( '<tbody>' );
		$.each( entries, function( _, entry ) {
			var $tr = $( '<tr>' );
			$tr.append( $( '<td>' ).append( $( '<code>' ).text( entry.path || ( '#' + entry.post_id ) ) ) );
			$tr.append( $( '<td>' ).text( entry.location || '' ) );
			$tr.append( $( '<td>' ).addClass( 'wpps-report-detail' ).text( entry.detail || '' ) );
			$tr.append( $( '<td>' ).text( entry.message || entry.type ) );
			$tbody.append( $tr );
		} );
		$container.append( $table.append( $tbody ) );
	}

	function renderVerifyReport( errors, warnings, scanned, total ) {
		var $results = $( '#wpps-verify-results' ).empty();
		$results.append( $( '<p>' ).text( sprintf2( cfg.i18n.scannedOf, scanned, total ) ) );
		if ( ! errors.length && ! warnings.length ) {
			$results.append( $( '<p>' ).addClass( 'wpps-feedback-ok' ).text( cfg.i18n.verifyClean ) );
			return;
		}
		renderVerifyGroup( $results, cfg.i18n.verifyErrors, errors, 'wpps-report-errors' );
		renderVerifyGroup( $results, cfg.i18n.verifyWarnings, warnings, 'wpps-report-warnings' );
	}

	$( document ).on( 'click', '.wpps-verify-run', function() {
		var target = $( this ).attr( 'data-target' );
		var $fb = $( '#wpps-verify-feedback' );
		var $buttons = $( '.wpps-verify-run' ).prop( 'disabled', true );
		var limit = cfg.verifyBatch || 10;
		var errors = [];
		var warnings = [];
		var scanned = 0;

		function batch( offset ) {
			post( 'wpps_verify', { target: target, offset: offset, limit: limit } ).done( function( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					feedback( $fb, responseMessage( response ), false );
					$buttons.prop( 'disabled', false );
					return;
				}
				var data = response.data;
				errors = errors.concat( data.errors || [] );
				warnings = warnings.concat( data.warnings || [] );
				scanned += data.scanned || 0;

				var total = data.total || 0;
				feedback( $fb, cfg.i18n.verifyRunning + ' ' + sprintf2( cfg.i18n.scannedOf, scanned, total ), true );

				if ( scanned < total && data.scanned > 0 ) {
					batch( offset + limit );
				} else {
					feedback( $fb, '', true );
					renderVerifyReport( errors, warnings, scanned, total );
					$buttons.prop( 'disabled', false );
				}
			} ).fail( function( xhr ) {
				feedback( $fb, failMessage( xhr ), false );
				$buttons.prop( 'disabled', false );
			} );
		}

		$( '#wpps-verify-results' ).empty();
		feedback( $fb, cfg.i18n.verifyRunning, true );
		batch( 0 );
	} );

	/* ------------------------------------------------------------------ *
	 * Log tab
	 * ------------------------------------------------------------------ */

	$( document ).on( 'click', '#wpps-log-refresh', function() {
		var $fb = $( '#wpps-log-feedback' );
		var $body = $( '#wpps-log-body' );

		feedback( $fb, cfg.i18n.loading, true );
		post( 'wpps_get_log' ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				feedback( $fb, responseMessage( response ), false );
				return;
			}
			feedback( $fb, '', true );
			$body.empty();
			$.each( response.data.entries || [], function( _, entry ) {
				var $tr = $( '<tr>' );
				$tr.append( $( '<td>' ).text( entry.time ) );
				$tr.append( $( '<td>' ).append( $( '<code>' ).text( entry.type ) ) );
				$tr.append( $( '<td>' ).text( entry.message ) );
				$body.append( $tr );
			} );
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} );
	} );
}( jQuery ) );
