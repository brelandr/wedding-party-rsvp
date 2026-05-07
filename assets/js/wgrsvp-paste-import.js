/**
 * Paste-import preview on Wedding Dashboard (admin).
 *
 * @package Wedding_Party_RSVP
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var btn = document.getElementById( 'wgrsvp-paste-preview-btn' );
		var blobEl = document.getElementById( 'wgrsvp_paste_blob' );
		var partyEl = document.getElementById( 'wgrsvp_paste_default_party' );
		var wrap = document.getElementById( 'wgrsvp-paste-preview-wrap' );
		var tbody = document.getElementById( 'wgrsvp-paste-preview-body' );
		var note = document.getElementById( 'wgrsvp-paste-preview-note' );

		if ( ! btn || ! blobEl || ! wrap || ! tbody || typeof wgrsvpPasteImport === 'undefined' ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var blob = blobEl.value || '';
			var party = partyEl ? partyEl.value : '';

			if ( ! blob.trim() ) {
				tbody.innerHTML = '';
				note.textContent = wgrsvpPasteImport.i18n.previewEmpty || '';
				wrap.style.display = 'block';
				return;
			}

			var fd = new FormData();
			fd.append( 'action', 'wgrsvp_preview_paste_import' );
			fd.append( 'nonce', wgrsvpPasteImport.nonce );
			fd.append( 'blob', blob );
			fd.append( 'default_party', party );

			fetch( wgrsvpPasteImport.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success || ! json.data ) {
						note.textContent = wgrsvpPasteImport.i18n.previewError || '';
						tbody.innerHTML = '';
						wrap.style.display = 'block';
						return;
					}
					var rows = json.data.rows || [];
					var total = json.data.total || 0;
					var max = json.data.max || 200;
					tbody.innerHTML = '';
					rows.forEach( function ( row ) {
						var tr = document.createElement( 'tr' );
						tr.innerHTML =
							'<td>' +
							escapeHtml( row.party_id || '' ) +
							'</td><td>' +
							escapeHtml( row.guest_name || '' ) +
							'</td><td>' +
							escapeHtml( row.email || '' ) +
							'</td><td>' +
							escapeHtml( row.phone || '' ) +
							'</td>';
						tbody.appendChild( tr );
					} );
					var noteTpl = wgrsvpPasteImport.i18n.previewNote || '';
					note.textContent =
						rows.length > 0
							? noteTpl
									.replace( '%1$d', String( rows.length ) )
									.replace( '%2$d', String( total ) )
									.replace( '%3$d', String( max ) )
							: ( wgrsvpPasteImport.i18n.previewEmpty || '' );
					wrap.style.display = 'block';
				} )
				.catch( function () {
					tbody.innerHTML = '';
					note.textContent = wgrsvpPasteImport.i18n.previewError || '';
					wrap.style.display = 'block';
				} );
		} );

		function escapeHtml( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s;
			return d.innerHTML;
		}
	} );
} )();
