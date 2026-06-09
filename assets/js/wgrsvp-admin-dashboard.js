/**
 * Guest list: copy public RSVP link; persist DataViews vs classic view preference.
 */
(function ( $ ) {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '.wgrsvp-copy-rsvp' );
		if ( ! t || ! t.dataset.url ) {
			return;
		}
		e.preventDefault();
		var u = t.dataset.url;
		var ok = t.dataset.copied;
		var l = t.dataset.label;
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( u ).then( function () {
				t.textContent = ok;
				setTimeout( function () {
					t.textContent = l;
				}, 1800 );
			} );
		}
	} );

	$( document ).on( 'click', '.wgrsvp-guest-list-view-btn', function () {
		if ( typeof wgrsvpGuestListView === 'undefined' ) {
			return;
		}
		var view = $( this ).data( 'wgrsvp-view' );
		if ( ! view || view === wgrsvpGuestListView.currentView ) {
			return;
		}
		$.post( wgrsvpGuestListView.ajaxUrl, {
			action: wgrsvpGuestListView.action,
			nonce: wgrsvpGuestListView.nonce,
			view: view
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.redirect_url ) {
					window.location.href = res.data.redirect_url;
					return;
				}
				window.alert( wgrsvpGuestListView.i18n.fail );
			} )
			.fail( function () {
				window.alert( wgrsvpGuestListView.i18n.fail );
			} );
	} );
})( jQuery );
