/**
 * Admin spike: read-only guest table fed by wgrsvp/v1/guest-rows (replace with core DataViews when bundled).
 */
( function () {
	'use strict';

	var cfg = window.wgrsvpDataviewsSpike;
	var root = document.getElementById( 'wgrsvp-dataviews-spike-root' );
	if ( ! cfg || ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	root.innerHTML = '<h2 class="title">' + escapeHtml( cfg.i18n.title ) + '</h2><p class="description">' + escapeHtml( cfg.i18n.note ) + '</p><p>' + escapeHtml( cfg.i18n.loading ) + '</p>';

	window.wp.apiFetch( { path: '/wgrsvp/v1/guest-rows?per_page=100&page=1' } )
		.then( function ( data ) {
			if ( ! data || ! Array.isArray( data.guests ) ) {
				throw new Error( 'bad' );
			}
			var cols = [ 'id', 'party_id', 'guest_name', 'email', 'rsvp_status', 'menu_choice', 'child_menu_choice', 'dietary_restrictions', 'table_number' ];
			var html = '<h2 class="title">' + escapeHtml( cfg.i18n.title ) + '</h2>';
			html += '<p class="description">' + escapeHtml( cfg.i18n.note ) + '</p>';
			html += '<p><strong>Total in database:</strong> ' + String( parseInt( data.total, 10 ) || 0 ) + ' · <strong>Showing:</strong> ' + String( data.guests.length ) + '</p>';
			html += '<div style="overflow:auto;"><table class="widefat striped"><thead><tr>';
			cols.forEach( function ( c ) {
				html += '<th>' + escapeHtml( c ) + '</th>';
			} );
			html += '</tr></thead><tbody>';
			data.guests.forEach( function ( row ) {
				html += '<tr>';
				cols.forEach( function ( c ) {
					var v = row && Object.prototype.hasOwnProperty.call( row, c ) ? String( row[ c ] ) : '';
					html += '<td>' + escapeHtml( v ) + '</td>';
				} );
				html += '</tr>';
			} );
			html += '</tbody></table></div>';
			root.innerHTML = html;
		} )
		.catch( function () {
			root.innerHTML = '<div class="notice notice-error"><p>' + escapeHtml( cfg.i18n.error ) + '</p></div>';
		} );

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}
} )();
