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
		filterEmpty: 'No posts match the current filter.',
		sortAsc: 'Sort ascending.',
		sortDesc: 'Sort descending.',
		filterCount: 'Showing %1$s of %2$s posts.',
		push: 'Push',
		pull: 'Pull',
		pushTitle: 'Push to peer',
		pullTitle: 'Pull from peer',
		confirm: 'Confirm',
		working: 'Working…',
		done: 'Done.',
		mediaNone: 'Media: none referenced.',
		mediaAllOnTarget: 'Media: %1$s referenced, all already on the target.',
		mediaAllHere: 'Media: %1$s referenced, all already here.',
		mediaToTransfer: 'Media: %1$s referenced, %2$s to transfer (%3$s).',
		forceLabel: 'Force: the copy on the target differs from this one and will be overwritten. Its current title, content and excerpt are saved there as a post revision first, unless that site has revisions switched off or its post type does not support them.',
		preflightErrors: 'Blocking problems (fix at the source first):',
		warnings: 'Warnings:',
		bulkTitle: 'Push selected posts',
		bulkIntro: 'Posts selected: %1$s. They are pushed to the peer parents first.',
		bulkConflicts: 'These posts differ on the peer and are skipped unless you tick the option below:',
		bulkForceLabel: 'Force: overwrite the posts listed above on the peer. The current title, content and excerpt of each are saved there as a post revision first, unless that site has revisions switched off or its post type does not support them.',
		bulkNone: 'Select at least one post.',
		bulkDone: 'Bulk push finished:',
		pushed: 'pushed',
		skipped: 'skipped',
		failed: 'failed',
		bulkSkipped: 'Skipped as conflicts (to overwrite them, select them again and tick the force option in the bulk dialog, or push each one on its own):',
		bulkFailed: 'Failed:',
		bulkWarnings: 'Pushed with warnings:',
		bulkRunning: 'A bulk push is still running. Wait for it to finish before syncing a single post.',
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

		post( 'wpps_connect', {
			key: key,
			basic_auth_user: $.trim( $( '#wpps-connect-auth-user' ).val() || '' ),
			// Byte-exact: a password may legitimately start or end with a space.
			basic_auth_pass: $( '#wpps-connect-auth-pass' ).val() || ''
		} ).done( function( response ) {
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

	// Show or hide a row's Basic Auth form.
	$( document ).on( 'click', '.wpps-basic-auth-toggle', function() {
		var $form = $( this ).closest( 'tr' ).find( '.wpps-basic-auth-form' );

		$form.toggleClass( 'wpps-hidden' );
		$( this ).attr( 'aria-expanded', $form.hasClass( 'wpps-hidden' ) ? 'false' : 'true' );
	} );

	// Save (both fields) or remove (both empty) a row's Basic Auth, then ping.
	$( document ).on( 'click', '.wpps-auth-save', function() {
		var $button = $( this );
		var $row = $button.closest( 'tr' );
		var $fb = $row.find( '.wpps-auth-feedback' );
		var $user = $row.find( '.wpps-auth-user' );
		var $pass = $row.find( '.wpps-auth-pass' );

		$button.prop( 'disabled', true );
		feedback( $fb, cfg.i18n.working, true );

		post( 'wpps_update_basic_auth', {
			peer_id: rowPeerId( this ),
			basic_auth_user: $.trim( $user.val() || '' ),
			// Byte-exact, as in the connect form.
			basic_auth_pass: $pass.val() || ''
		} ).done( function( response ) {
			feedback( $fb, responseMessage( response ), !! response.success );
			if ( response.success ) {
				$user.val( '' );
				$pass.val( '' );
				$row.find( '.wpps-basic-auth-state' ).text( response.data.label || '' );
				$row.find( '.wpps-ping' ).trigger( 'click' );
			}
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

	// True while a push/pull started from the modal is in flight, see
	// setModalBusy(). closeModal() reads it, which is what also blocks the
	// backdrop click.
	var modalBusy = false;

	// True while the force checkbox gates the confirm button (single-post
	// preview). The bulk dialog shows the same checkbox as a plain option:
	// unticked still runs, it only skips the conflicts.
	var forceGatesConfirm = false;

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

	// Fills the %1$s … %n$s placeholders of a localized string from an array.
	function sprintfArgs( template, args ) {
		return String( template ).replace( /%(\d+)\$s/g, function( placeholder, position ) {
			var value = args[ position - 1 ];
			return ( undefined === value ) ? placeholder : String( value );
		} );
	}

	/*
	 * Media summary for both preview modals. A bare "To transfer: 0 (0 B)" reads
	 * as "nothing will happen" although it only ever means the files are already
	 * in place while the post content still changes, so the zero case says that
	 * outright and drops the meaningless size.
	 */
	function mediaLine( media, direction ) {
		var files = media.files || 0;
		var transfer = media.transfer_files || 0;

		if ( 0 === files ) {
			return cfg.i18n.mediaNone;
		}
		if ( 0 === transfer ) {
			return sprintfArgs( 'pull' === direction ? cfg.i18n.mediaAllHere : cfg.i18n.mediaAllOnTarget, [ files ] );
		}
		return sprintfArgs( cfg.i18n.mediaToTransfer, [ files, transfer, formatBytes( media.transfer_bytes || 0 ) ] );
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

	/**
	 * Locks or unlocks the modal while a sync request is in flight.
	 *
	 * Locking is not cosmetic: the request writes to the other site, so every
	 * control that could start a second one or pull the modal away from under
	 * the running request has to stay shut until it settles.
	 *
	 * The overlay only blocks the mouse, and the dialog has no focus trap, so
	 * the controls outside it stay reachable by keyboard. They are covered from
	 * this same flag rather than from their own state: the bulk button through
	 * updateBulkButton(), the buttons that would open a fresh preview through an
	 * early return. The preview buttons deliberately are not disabled here,
	 * because their disabled state already encodes "unsyncable" and "not on the
	 * peer", which a blanket re-enable would throw away.
	 *
	 * Unlocking deliberately leaves #wpps-modal-confirm alone. Whether
	 * confirming is allowed again depends on the branch (a rejected request may
	 * be retried, a finished one must not run a second time from a now stale
	 * preview), so each caller decides that itself. Everything that only exists
	 * to let the user out is restored here, so no branch can leave the modal
	 * unclosable.
	 *
	 * @param {boolean} busy Whether a request is in flight.
	 * @return {void}
	 */
	function setModalBusy( busy ) {
		modalBusy = busy;
		$( '#wpps-modal-cancel, #wpps-modal-force' ).prop( 'disabled', busy );
		if ( busy ) {
			$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		}
		updateBulkButton();
	}

	/**
	 * Reflects a running bulk push in the modal.
	 *
	 * A bulk run writes to the same peer, so confirming a single push while it
	 * is going has to be blocked. The click handler on #wpps-modal-confirm is
	 * the actual guard; this only makes the block visible, so a preview opened
	 * during a bulk run does not offer a button that silently does nothing.
	 *
	 * Opening the preview itself stays allowed: it only reads, and looking at a
	 * post while a long bulk run finishes is useful.
	 *
	 * @return {void}
	 */
	function applyBulkLock() {
		if ( ! bulkBusy ) {
			return;
		}
		$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		feedback( $( '#wpps-modal-feedback' ), cfg.i18n.bulkRunning, false );
	}

	function openModal( title ) {
		modalConfirm = null;
		forceGatesConfirm = false;
		setModalBusy( false );
		$( '#wpps-modal-title' ).text( title || cfg.i18n.loading );
		$( '#wpps-modal-body' ).empty().append( $( '<p>' ).text( cfg.i18n.loading ) );
		$( '#wpps-modal-force-row' ).addClass( 'wpps-hidden' );
		$( '#wpps-modal-force-label' ).text( '' );
		$( '#wpps-modal-force' ).prop( 'checked', false );
		$( '#wpps-modal-confirm' ).prop( 'disabled', true );
		feedback( $( '#wpps-modal-feedback' ), '', true );
		$( '#wpps-modal-overlay' ).removeClass( 'wpps-hidden' );
	}

	function closeModal() {
		if ( modalBusy ) {
			return;
		}
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
		// Guarding the handler, not just the button's disabled state: that
		// state is written from several places (renderPreview(), the force
		// checkbox, every terminal branch of a run), so only the handler holds
		// regardless of which of them wrote it last.
		if ( bulkBusy ) {
			feedback( $( '#wpps-modal-feedback' ), cfg.i18n.bulkRunning, false );
			return;
		}
		if ( modalConfirm ) {
			modalConfirm();
		}
	} );
	// The checkbox only gates the confirm button in a single-post preview, and
	// only while there is still something to confirm: after a completed run
	// modalConfirm is cleared, and toggling force must not bring the button
	// back for a second run from a stale preview.
	$( document ).on( 'change', '#wpps-modal-force', function() {
		if ( modalConfirm && forceGatesConfirm ) {
			$( '#wpps-modal-confirm' ).prop( 'disabled', ! $( this ).is( ':checked' ) );
		}
	} );

	function renderStatusRow( item ) {
		// data-title mirrors the rendered title cell so the search matches what
		// the user actually sees, including the "(draft)" placeholder.
		var title = item.title || '(' + item.status + ')';
		var $tr = $( '<tr>' )
			.addClass( 'wpps-status-row' )
			.attr( 'data-path', item.path )
			.attr( 'data-post-type', item.post_type )
			.attr( 'data-sync-status', item.sync_status )
			.attr( 'data-title', title )
			.attr( 'data-modified', item.modified_gmt || '' );
		var unsyncable = 'no_slug' === item.sync_status;

		$tr.append( $( '<td>' ).addClass( 'check-column' ).append(
			$( '<input>' ).attr( 'type', 'checkbox' ).addClass( 'wpps-row-check' ).prop( 'disabled', unsyncable )
		) );
		$tr.append( $( '<td>' ).text( title ) );
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
			} else {
				$.each( response.data.items, function( _, item ) {
					$body.append( renderStatusRow( item ) );
				} );
				sortRows();
			}
			// Re-apply the active filter so a reload does not reset the view.
			rebuildStatusOptions();
			applyFilter();
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Status-list sorting (client-side)
	 *
	 * Display order only: the bulk push sorts its own queue parents first.
	 * Every "Load status" (also the one after a push or pull) re-applies the
	 * chosen sort; it lives for the page view, not across page loads.
	 * ------------------------------------------------------------------ */

	// Direction of the first click per column; newest first is the natural
	// start for dates.
	var SORT_FIRST_DIR = { title: 'asc', path: 'asc', modified: 'desc', status: 'asc' };
	var sortState = { key: 'modified', dir: 'desc' };

	// One collator for all comparisons: localeCompare with options would
	// rebuild the collation rules on every call.
	var collator = new window.Intl.Collator( undefined, { numeric: true, sensitivity: 'base' } );

	// Status => position in cfg.statuses (the single label and order source),
	// rebuilt once per sort by sortRows(); unknown statuses get statusCount.
	var statusOrder = {};
	var statusCount = 0;

	function compareText( a, b ) {
		return collator.compare( a || '', b || '' );
	}

	// Unknown statuses sort last.
	function statusRank( status ) {
		return Object.prototype.hasOwnProperty.call( statusOrder, status ) ? statusOrder[ status ] : statusCount;
	}

	// A zero date counts as no date.
	function modifiedValue( row ) {
		var value = row.getAttribute( 'data-modified' ) || '';
		return 0 === value.indexOf( '0000' ) ? '' : value;
	}

	function compareRows( a, b ) {
		var key = sortState.key;
		var result;

		if ( 'modified' === key ) {
			var am = modifiedValue( a );
			var bm = modifiedValue( b );
			if ( ! am !== ! bm ) {
				// Rows without a date stay last in both directions.
				return am ? -1 : 1;
			}
			result = am < bm ? -1 : ( am > bm ? 1 : 0 );
		} else if ( 'status' === key ) {
			result = statusRank( a.getAttribute( 'data-sync-status' ) ) - statusRank( b.getAttribute( 'data-sync-status' ) );
		} else {
			result = compareText( a.getAttribute( 'data-' + key ), b.getAttribute( 'data-' + key ) );
		}
		if ( 'desc' === sortState.dir ) {
			result = -result;
		}
		// Stable tie-break, independent of the direction.
		return result ||
			compareText( a.getAttribute( 'data-title' ), b.getAttribute( 'data-title' ) ) ||
			compareText( a.getAttribute( 'data-path' ), b.getAttribute( 'data-path' ) );
	}

	function sortRows() {
		var $body = $( '#wpps-posts-body' );
		var rows = $body.children( '.wpps-status-row' ).get();

		statusOrder = {};
		statusCount = 0;
		$.each( cfg.statuses, function( status ) {
			statusOrder[ status ] = statusCount++;
		} );
		rows.sort( compareRows );
		$body.append( rows );
		// The "no match" row stays last.
		$body.append( $( '#wpps-filter-empty' ) );
	}

	function updateSortHeaders() {
		$( '.wpps-posts-table th[data-wpps-sort]' ).each( function() {
			var $th = $( this );
			var key = $th.attr( 'data-wpps-sort' );
			var active = key === sortState.key;
			var next = SORT_FIRST_DIR[ key ];

			if ( active ) {
				next = 'asc' === sortState.dir ? 'desc' : 'asc';
			}
			// Same classes as WP_List_Table::print_column_headers().
			$th.removeClass( 'sortable sorted asc desc' )
				.addClass( active ? 'sorted ' + sortState.dir : 'sortable ' + ( 'asc' === next ? 'desc' : 'asc' ) );
			if ( active ) {
				$th.attr( 'aria-sort', 'asc' === sortState.dir ? 'ascending' : 'descending' );
			} else {
				$th.removeAttr( 'aria-sort' );
			}
			$th.find( '.wpps-sort-hint' ).text( 'asc' === next ? cfg.i18n.sortAsc : cfg.i18n.sortDesc );
		} );
	}

	$( document ).on( 'click', '.wpps-posts-table th[data-wpps-sort] a', function( event ) {
		var key = $( this ).closest( 'th' ).attr( 'data-wpps-sort' );

		event.preventDefault();
		if ( key === sortState.key ) {
			sortState.dir = 'asc' === sortState.dir ? 'desc' : 'asc';
		} else {
			sortState.key = key;
			sortState.dir = SORT_FIRST_DIR[ key ];
		}
		updateSortHeaders();
		sortRows();
	} );

	$( updateSortHeaders );

	$( document ).on( 'click', '#wpps-load-status', loadStatus );
	$( document ).on( 'change', '#wpps-peer-select, #wpps-post-type-select', function() {
		$( '#wpps-posts-body' ).empty();
		$( '#wpps-bulk-results' ).empty();
		updateGlobalsChip( null );
		rebuildStatusOptions();
		applyFilter();
	} );

	/* ------------------------------------------------------------------ *
	 * Status-list filter (client-side)
	 *
	 * The whole list is already in the DOM, so narrowing it costs no further
	 * request. A row the filter hides is unchecked, and every selection helper
	 * below counts visible rows only, so a bulk push can never act on a row the
	 * user cannot see.
	 * ------------------------------------------------------------------ */

	var filterTimer = null;

	/*
	 * The dropdown offers exactly the statuses the loaded rows carry. Which
	 * statuses a table can produce depends on the direction (missing_local,
	 * for one, never occurs in "Posts on this site"), so a fixed list would
	 * always contain options that match nothing. cfg.statuses stays the single
	 * label source and also fixes the order.
	 */
	function rebuildStatusOptions() {
		var $select = $( '#wpps-filter-status' );
		var previous = $select.val() || '';
		var present = {};

		$( '#wpps-posts-body .wpps-status-row' ).each( function() {
			var status = $( this ).attr( 'data-sync-status' );

			if ( status ) {
				present[ status ] = true;
			}
		} );

		$select.find( 'option' ).not( '[value=""]' ).remove();
		$.each( cfg.statuses, function( status, label ) {
			if ( present[ status ] ) {
				$select.append( $( '<option>' ).attr( 'value', status ).text( label ) );
			}
		} );

		$select.val( present[ previous ] ? previous : '' );
	}

	// Selectable checkboxes of the currently visible rows.
	function visibleChecks() {
		return $( '#wpps-posts-body tr:not(.wpps-hidden) .wpps-row-check:not(:disabled)' );
	}

	function checkedRows() {
		return visibleChecks().filter( ':checked' );
	}

	function rowMatches( $row, status, needle ) {
		if ( status && $row.attr( 'data-sync-status' ) !== status ) {
			return false;
		}
		if ( ! needle ) {
			return true;
		}
		return ( $row.attr( 'data-title' ) || '' ).toLowerCase().indexOf( needle ) > -1 ||
			( $row.attr( 'data-path' ) || '' ).toLowerCase().indexOf( needle ) > -1;
	}

	function renderFilterEmpty( show ) {
		var $row = $( '#wpps-filter-empty' );

		if ( ! show ) {
			$row.remove();
			return;
		}
		if ( ! $row.length ) {
			$( '#wpps-posts-body' ).append(
				$( '<tr>' ).attr( 'id', 'wpps-filter-empty' ).append(
					$( '<td>' ).attr( 'colspan', 6 ).text( cfg.i18n.filterEmpty )
				)
			);
		}
	}

	function applyFilter() {
		var status = $( '#wpps-filter-status' ).val() || '';
		var needle = $.trim( $( '#wpps-filter-search' ).val() || '' ).toLowerCase();
		var $rows = $( '#wpps-posts-body .wpps-status-row' );
		var visible = 0;

		$rows.each( function() {
			var $row = $( this );
			var match = rowMatches( $row, status, needle );

			$row.toggleClass( 'wpps-hidden', ! match );
			if ( match ) {
				visible++;
			} else {
				$row.find( '.wpps-row-check' ).prop( 'checked', false );
			}
		} );

		$( '#wpps-filter-count' ).text(
			$rows.length ? sprintfArgs( cfg.i18n.filterCount, [ visible, $rows.length ] ) : ''
		);
		renderFilterEmpty( $rows.length > 0 && 0 === visible );
		syncCheckAll();
		updateBulkButton();
	}

	$( document ).on( 'change', '#wpps-filter-status', applyFilter );
	$( document ).on( 'input', '#wpps-filter-search', function() {
		window.clearTimeout( filterTimer );
		filterTimer = window.setTimeout( applyFilter, 180 );
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
		$modalBody.append( $( '<p>' ).text( mediaLine( media, data.direction ) ) );
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
			forceGatesConfirm = true;
		} else {
			// Never inherit a force row (possibly ticked) from another dialog.
			$( '#wpps-modal-force-row' ).addClass( 'wpps-hidden' );
			$( '#wpps-modal-force' ).prop( 'checked', false );
			$( '#wpps-modal-confirm' ).prop( 'disabled', blocked );
		}
		applyBulkLock();
	}

	// peerId is the peer the preview was built for, never the live select:
	// the force decision belongs to that peer's status.
	function executeSync( direction, postType, path, peerId ) {
		var $fb = $( '#wpps-modal-feedback' );
		var force = $( '#wpps-modal-force' ).is( ':checked' ) ? 1 : 0;

		setModalBusy( true );
		feedback( $fb, cfg.i18n.working, true );

		post( 'push' === direction ? 'wpps_push' : 'wpps_pull', {
			peer_id: peerId,
			post_type: postType,
			path: path,
			force: force
		} ).done( function( response ) {
			setModalBusy( false );
			if ( response && response.success ) {
				var warnings = ( response.data && response.data.warnings ) || [];
				if ( warnings.length ) {
					// The run is over: the force row belongs to the spent preview,
					// so it must not sit next to the result still checked.
					$( '#wpps-modal-body' ).empty().append( listBlock( cfg.i18n.warnings, warnings, 'wpps-report-warnings' ) );
					$( '#wpps-modal-force-row' ).addClass( 'wpps-hidden' );
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
			setModalBusy( false );
			feedback( $fb, failMessage( xhr ), false );
			$( '#wpps-modal-confirm' ).prop( 'disabled', false );
		} );
	}

	function openSyncPreview( direction, postType, path ) {
		var peerId = currentPeer();

		// Keyboard-reachable while the overlay blocks the mouse: opening a second
		// preview would reset the busy flag and hand the user a confirm button
		// while the first run is still writing to the peer.
		if ( modalBusy ) {
			return;
		}

		openModal( ( 'push' === direction ? cfg.i18n.pushTitle : cfg.i18n.pullTitle ) + ' · ' + path );

		post( 'wpps_sync_preview', {
			peer_id: peerId,
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
				executeSync( direction, postType, path, peerId );
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

	// True while the bulk loop below is running, see updateBulkButton().
	var bulkBusy = false;

	// Single control point for the bulk button: it needs a selection, and it must
	// stay shut while any run is still writing to the peer, its own or one
	// started from the modal. setModalBusy() calls this, so both directions stay
	// in sync and no other place sets this button's disabled state.
	function updateBulkButton() {
		$( '#wpps-bulk-push' ).prop( 'disabled', modalBusy || bulkBusy || 0 === checkedRows().length );
		// A running sync writes to the peer it started with; the selects must
		// not drift away from that target while it runs.
		$( '#wpps-peer-select, #wpps-post-type-select' ).prop( 'disabled', modalBusy || bulkBusy );
	}

	// "Select all" reflects the visible selection: checked only while every
	// selectable visible row is checked.
	function syncCheckAll() {
		var $checks = visibleChecks();

		$( '#wpps-check-all' ).prop( 'checked', $checks.length > 0 && $checks.length === $checks.filter( ':checked' ).length );
	}

	$( document ).on( 'change', '.wpps-row-check', function() {
		syncCheckAll();
		updateBulkButton();
	} );
	$( document ).on( 'change', '#wpps-check-all', function() {
		visibleChecks().prop( 'checked', $( this ).is( ':checked' ) );
		updateBulkButton();
	} );

	$( document ).on( 'click', '#wpps-bulk-push', function() {
		var forcePush = cfg.forcePush || [];
		var peerId = currentPeer();
		var queue = [];

		// The button is disabled while either run writes to the peer; this
		// also holds for a click that arrives anyway. An open modal (a preview
		// still loading, by keyboard behind the overlay) must not be replaced:
		// its late answer would render over the bulk dialog.
		if ( modalBusy || bulkBusy || ! $( '#wpps-modal-overlay' ).hasClass( 'wpps-hidden' ) ) {
			return;
		}
		if ( ! peerId ) {
			feedback( $( '#wpps-bulk-feedback' ), cfg.i18n.noPeer, false );
			return;
		}

		// The status is read now, when the dialog lists the conflicts: only
		// these rows can be forced, so a post that turned into a conflict
		// after the list was loaded is still skipped, never overwritten.
		checkedRows().each( function() {
			var $tr = $( this ).closest( 'tr' );
			var status = $tr.attr( 'data-sync-status' );
			queue.push( {
				post_type: $tr.attr( 'data-post-type' ),
				path: $tr.attr( 'data-path' ),
				status: status,
				conflict: -1 !== $.inArray( status, forcePush )
			} );
		} );
		if ( ! queue.length ) {
			feedback( $( '#wpps-bulk-feedback' ), cfg.i18n.bulkNone, false );
			return;
		}

		queue.sort( function( a, b ) {
			var depth = a.path.split( '/' ).length - b.path.split( '/' ).length;
			return 0 !== depth ? depth : a.path.localeCompare( b.path );
		} );
		openBulkDialog( queue, peerId );
	} );

	/*
	 * Bulk confirmation in the shared modal: the count, the conflicts it would
	 * skip, and (only if there are any) the force option for exactly those.
	 * Confirm closes the modal and the run reports below the table as before,
	 * so the modal is free for single previews (still locked by bulkBusy).
	 * peerId is the peer whose table the conflicts were read from; every
	 * request of the run goes to it, whatever the select shows later.
	 */
	function openBulkDialog( queue, peerId ) {
		var conflicts = $.grep( queue, function( item ) {
			return item.conflict;
		} );
		var $modalBody;

		openModal( cfg.i18n.bulkTitle );
		$modalBody = $( '#wpps-modal-body' ).empty();
		$modalBody.append( $( '<p>' ).text( sprintfArgs( cfg.i18n.bulkIntro, [ queue.length ] ) ) );
		if ( conflicts.length ) {
			$modalBody.append( listBlock( cfg.i18n.bulkConflicts, $.map( conflicts, function( item ) {
				return item.path + ' (' + statusLabel( item.status ) + ')';
			} ), 'wpps-report-warnings' ) );
			$( '#wpps-modal-force-label' ).text( cfg.i18n.bulkForceLabel );
			$( '#wpps-modal-force-row' ).removeClass( 'wpps-hidden' );
		}
		$( '#wpps-modal-confirm' ).prop( 'disabled', false );

		modalConfirm = function() {
			var force = $( '#wpps-modal-force' ).is( ':checked' );

			closeModal();
			runBulkPush( queue, force, peerId );
		};
	}

	function runBulkPush( queue, force, peerId ) {
		var $fb = $( '#wpps-bulk-feedback' );
		var $results = $( '#wpps-bulk-results' ).empty();
		var pushed = 0;
		var skipped = [];
		var failed = [];
		var warned = [];

		// Show the run's target (the select may have changed behind the
		// dialog), so the closing reload lists the peer that was written to.
		$( '#wpps-peer-select' ).val( peerId );
		$( '#wpps-post-type-select' ).val( queue[ 0 ].post_type );
		bulkBusy = true;
		updateBulkButton();

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
			if ( warned.length ) {
				$results.append( listBlock( cfg.i18n.bulkWarnings, warned, 'wpps-report-warnings' ) );
			}
		}

		function next() {
			if ( ! queue.length ) {
				// Unlock first, exactly like setModalBusy( false ) leads every
				// terminal handler: report() renders peer-supplied data, and a
				// throw in there must not leave the bulk button dead until the
				// next page load.
				bulkBusy = false;
				updateBulkButton();
				report();
				loadStatus();
				return;
			}
			var item = queue.shift();
			feedback( $fb, cfg.i18n.working + ' ' + item.path, true );
			post( 'wpps_push', {
				peer_id: peerId,
				post_type: item.post_type,
				path: item.path,
				// Only rows listed as conflicts in the dialog are ever forced.
				force: force && item.conflict ? 1 : 0
			} )
				.done( function( response ) {
					if ( response && response.success ) {
						pushed++;
						// One line per warning, prefixed with its post, so a long
						// run still says which post each one belongs to.
						$.each( ( response.data && response.data.warnings ) || [], function( _, warning ) {
							warned.push( item.path + ': ' + warning );
						} );
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
	}

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
		$modalBody.append( $( '<p>' ).text( mediaLine( media, data.direction ) ) );
		if ( blocked ) {
			$modalBody.append( listBlock( cfg.i18n.preflightErrors, data.preflight.errors, 'wpps-report-errors' ) );
		}

		$( '#wpps-modal-confirm' ).prop( 'disabled', blocked );
		applyBulkLock();
	}

	$( document ).on( 'click', '.wpps-globals-preview', function() {
		var direction = $( this ).attr( 'data-direction' );
		var $fb = $( '#wpps-globals-feedback' );
		var peerId = currentPeer();

		// Same reason as in openSyncPreview(): reachable by keyboard while a run
		// from the modal is in flight, and opening a preview clears the lock.
		if ( modalBusy ) {
			return;
		}

		if ( ! peerId ) {
			feedback( $fb, cfg.i18n.noPeer, false );
			return;
		}
		openModal( ( 'push' === direction ? cfg.i18n.pushTitle : cfg.i18n.pullTitle ) + ' · Global styles' );

		post( 'wpps_globals_preview', { peer_id: peerId, direction: direction } ).done( function( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				$( '#wpps-modal-body' ).empty().append( $( '<p>' ).addClass( 'wpps-feedback-error' ).text( responseMessage( response ) ) );
				return;
			}
			renderGlobalsPreview( response.data );
			modalConfirm = function() {
				var $modalFb = $( '#wpps-modal-feedback' );
				setModalBusy( true );
				feedback( $modalFb, cfg.i18n.working, true );
				post( 'wpps_globals_sync', { peer_id: peerId, direction: direction } ).done( function( syncResponse ) {
					setModalBusy( false );
					if ( syncResponse && syncResponse.success ) {
						var warnings = ( syncResponse.data && syncResponse.data.warnings ) || [];
						if ( warnings.length ) {
							// Terminal branch, same treatment as executeSync().
							$( '#wpps-modal-body' ).empty().append( listBlock( cfg.i18n.warnings, warnings, 'wpps-report-warnings' ) );
							$( '#wpps-modal-force-row' ).addClass( 'wpps-hidden' );
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
					setModalBusy( false );
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
		$results.append( $( '<p>' ).text( sprintfArgs( cfg.i18n.scannedOf, [ scanned, total ] ) ) );
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
				feedback( $fb, cfg.i18n.verifyRunning + ' ' + sprintfArgs( cfg.i18n.scannedOf, [ scanned, total ] ), true );

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
				var $message = $( '<td>' ).text( entry.message );
				if ( entry.warnings && entry.warnings.length ) {
					$message.append( listBlock( '', entry.warnings, 'wpps-report-warnings' ) );
				}
				$tr.append( $( '<td>' ).text( entry.time ) );
				$tr.append( $( '<td>' ).append( $( '<code>' ).text( entry.type ) ) );
				$tr.append( $message );
				$body.append( $tr );
			} );
		} ).fail( function( xhr ) {
			feedback( $fb, failMessage( xhr ), false );
		} );
	} );
}( jQuery ) );
